<?php

namespace App\Services\Telegram\Handlers;

use App\Jobs\DeleteTelegramMessages;
use App\Models\TelegramTeam;
use App\Models\TelegramTeamCategory;
use App\Models\TelegramTeamMember;
use Illuminate\Database\Eloquent\Collection;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Message;

/**
 * «انضم» — joining teams by tapping instead of typing.
 *
 * The old way in was one message listing every team by name («انضم علوم
 * الحاسب، العابدية، 1448، طالب») followed by a wait for a group admin to reply
 * «أضف». Between the exact spelling and the wait, most people never finished,
 * which is why the teams stayed empty. This handler replies with the chat's
 * own categories as buttons; a press opens that category's teams, and a press
 * on a team joins it — or leaves it, since the same button toggles. The
 * keyboard shows a ✅ next to the teams the presser is already in, so the
 * picker doubles as «فرقي».
 *
 * The press *is* the consent: Telegram attributes a button press to a real
 * account the way it cannot attribute a forwarded or quoted message, so
 * membership is written immediately and no approval step stands between a
 * member and their own team. Admins keep «أزل» for anyone who joins a team
 * they have no business in, and the older «انضم …» + «أضف» flow
 * ({@see TeamMembershipHandler}) still works for adding someone else.
 *
 * The keyboard belongs to the person who asked for it — its callback data
 * carries their id, and a press from anyone else is answered with an
 * invitation to open their own. Otherwise one member's ✅ marks would be shown
 * to everyone and the next presser would toggle their own membership against
 * a list describing someone else's.
 */
class TeamJoinPickerHandler extends BaseTeamHandler
{
    /** Callback-data prefix for this handler's inline buttons. */
    public const CALLBACK_PREFIX = 'tgjoin:';

    /** The category menu itself, as a view id no category can take. */
    private const MENU = -1;

    /** The view holding the teams that belong to no category. */
    private const UNCATEGORIZED = 0;

    /** Buttons one view will show before falling back to the typed command. */
    private const MAX_BUTTONS = 40;

    /** How long the picker and the message that opened it stay in the group. */
    private const PICKER_TTL_SECONDS = 300;

    public function handle(Message $message): void
    {
        if (! $this->matches($message, '/^(?:\/join(?:@\w+)?|[اأ]نضم|[اأ]نضمام|دخول\s+(?:ال)?مجموع(?:ة|ات))$/u')) {
            return;
        }

        if (! $this->isGroupChat($message)) {
            $this->reply($message, self::GROUP_ONLY_MESSAGE);

            return;
        }

        $this->trackCommand($message, 'team_join_picker');

        $chatId = (int) $message->getChat()->getId();

        if (! TelegramTeam::query()->where('chat_id', $chatId)->exists()) {
            $this->reply($message, "لا توجد فرق في هذه المجموعة بعد.\nينشئها مشرفو المجموعة بالأمر: فريق جديد اسم الفريق");

            return;
        }

        $userId = (int) $message->getFrom()->getId();
        $view = $this->openingView($chatId);

        $picker = $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $this->pickerText($message->getFrom()->getFirstName(), $chatId, $userId, $view),
            'parse_mode' => 'HTML',
            'reply_to_message_id' => $message->getMessageId(),
            'reply_markup' => $this->keyboard($chatId, $userId, $view),
        ]);

        DeleteTelegramMessages::dispatch($chatId, [
            $message->getMessageId(),
            $picker->getMessageId(),
        ])->delay(now()->addSeconds(self::PICKER_TTL_SECONDS));
    }

    /**
     * Button presses on a picker. Routed here by
     * {@see \App\Jobs\ProcessTelegramUpdate} for callback data starting with
     * {@see CALLBACK_PREFIX}.
     */
    public function handleCallback(CallbackQuery $callback): void
    {
        $data = (string) $callback->getData();
        $pickerMessage = $callback->getMessage();

        if (! str_starts_with($data, self::CALLBACK_PREFIX) || $pickerMessage === null) {
            return;
        }

        $parts = explode(':', substr($data, strlen(self::CALLBACK_PREFIX)), 2);

        if (count($parts) !== 2) {
            return;
        }

        [$ownerId, $action] = [(int) $parts[0], $parts[1]];
        $presserId = (int) $callback->getFrom()->getId();

        if ($presserId !== $ownerId) {
            $this->answerCallback($callback, 'هذه القائمة لعضو آخر — أرسل «انضم» لتفتح قائمتك أنت.', true);

            return;
        }

        $chatId = (int) $pickerMessage->getChat()->getId();

        if ($action === 'done') {
            $this->closePicker($callback, $chatId, $ownerId);

            return;
        }

        $toast = '';

        if (str_starts_with($action, 't:')) {
            $result = $this->toggle($chatId, $ownerId, (int) substr($action, 2), $callback);

            if ($result === null) {
                return;
            }

            // Stay in the category the pressed team lives in: a toggle should
            // leave the list exactly where the member is still choosing.
            [$toast, $action] = [$result['toast'], 'c:'.$result['view']];
        }

        $view = str_starts_with($action, 'c:')
            ? (int) substr($action, 2)
            : ($this->viewOf($action) ?? $this->openingView($chatId));

        $this->redraw($callback, $chatId, $ownerId, $view);
        $this->answerCallback($callback, $toast);
    }

    /**
     * Join the team, or leave it when the presser is already a member — one
     * button, both directions. Returns the toast to show and the view the
     * team lives in, or null when the team is gone and the picker was rebuilt
     * instead.
     *
     * @return array{toast: string, view: int}|null
     */
    private function toggle(int $chatId, int $userId, int $teamId, CallbackQuery $callback): ?array
    {
        $team = TelegramTeam::query()->where('chat_id', $chatId)->find($teamId);

        if ($team === null) {
            $this->redraw($callback, $chatId, $userId, $this->openingView($chatId));
            $this->answerCallback($callback, 'هذا الفريق لم يعد موجودًا.');

            return null;
        }

        $membership = TelegramTeamMember::query()
            ->where('team_id', $team->id)
            ->where('telegram_user_id', $userId)
            ->first();

        if ($membership !== null) {
            // Same block the typed opt-out sets: an «انضم» message sent before
            // this moment must not let an admin put them back with «أضف».
            $this->blockStaleConsents($chatId, $userId, (int) $team->id);
            $membership->delete();

            return ['toast' => "👋 خرجت من «{$team->name}»", 'view' => $this->viewOfTeam($team)];
        }

        $from = $callback->getFrom();

        TelegramTeamMember::query()->create([
            'team_id' => $team->id,
            'telegram_user_id' => $userId,
            'first_name' => $from->getFirstName(),
            'username' => $from->getUsername(),
            // A self-service join has no «انضم» message behind it: the press
            // itself is the consent, so the picker carries the record.
            'consent_message_id' => (int) $callback->getMessage()->getMessageId(),
            'consented_at' => now(),
            'added_by_telegram_id' => $userId,
        ]);

        return ['toast' => "✅ انضممت إلى «{$team->name}»", 'view' => $this->viewOfTeam($team)];
    }

    /**
     * Replace the picker with a plain summary and drop its keyboard, so a
     * finished picker cannot be pressed again by a scroller.
     */
    private function closePicker(CallbackQuery $callback, int $chatId, int $userId): void
    {
        $teams = $this->teamNamesOf($chatId, $userId);

        $this->telegram->editMessageText([
            'chat_id' => $chatId,
            'message_id' => $callback->getMessage()->getMessageId(),
            'text' => $teams === []
                ? 'لم تنضم إلى أي فريق. أرسل «انضم» متى ما أردت.'
                : '👥 فرقك: '.$this->joinNames($teams),
            'parse_mode' => 'HTML',
        ]);

        $this->answerCallback($callback, 'تم');
    }

    /**
     * Redraw the picker in place. Telegram rejects an edit that would not
     * change anything — pressing the open category again, say — and that
     * rejection must not swallow the toast, so the failure is ignored here
     * rather than left to bubble up.
     */
    private function redraw(CallbackQuery $callback, int $chatId, int $userId, int $view): void
    {
        try {
            $this->telegram->editMessageText([
                'chat_id' => $chatId,
                'message_id' => $callback->getMessage()->getMessageId(),
                'text' => $this->pickerText($callback->getFrom()->getFirstName(), $chatId, $userId, $view),
                'parse_mode' => 'HTML',
                'reply_markup' => $this->keyboard($chatId, $userId, $view),
            ]);
        } catch (\Exception) {
            // Nothing to redraw, or the picker is gone; the press still counted.
        }
    }

    /**
     * The view a fresh picker opens on: the category menu when the chat sorts
     * its teams into more than one bucket, otherwise that single bucket's
     * teams — a menu with one entry is a tap that teaches nothing.
     */
    private function openingView(int $chatId): int
    {
        $buckets = $this->buckets($chatId);

        return count($buckets) === 1 ? (int) array_key_first($buckets) : self::MENU;
    }

    /**
     * The chat's non-empty categories as «id => name», plus the
     * uncategorized bucket when any team is loose.
     *
     * @return array<int, string>
     */
    private function buckets(int $chatId): array
    {
        $categories = TelegramTeamCategory::query()
            ->where('chat_id', $chatId)
            ->withCount('teams')
            ->orderBy('name')
            ->get()
            ->filter(fn (TelegramTeamCategory $category): bool => $category->teams_count > 0);

        $buckets = [];

        foreach ($categories as $category) {
            $buckets[(int) $category->id] = $category->name;
        }

        $looseTeams = TelegramTeam::query()
            ->where('chat_id', $chatId)
            ->whereNull('category_id')
            ->count();

        if ($looseTeams > 0) {
            $buckets[self::UNCATEGORIZED] = $buckets === [] ? 'الفرق' : 'بدون تصنيف';
        }

        return $buckets;
    }

    /** The view a team is listed in: its category, or the loose bucket. */
    private function viewOfTeam(TelegramTeam $team): int
    {
        return $team->category_id === null ? self::UNCATEGORIZED : (int) $team->category_id;
    }

    /** The view an action name refers to, when it is not a category. */
    private function viewOf(string $action): ?int
    {
        return $action === 'menu' ? self::MENU : null;
    }

    /**
     * @return Collection<int, TelegramTeam>
     */
    private function teamsIn(int $chatId, int $view): Collection
    {
        return TelegramTeam::query()
            ->where('chat_id', $chatId)
            ->when(
                $view === self::UNCATEGORIZED,
                fn ($query) => $query->whereNull('category_id'),
                fn ($query) => $query->where('category_id', $view),
            )
            ->orderBy('name')
            ->get();
    }

    private function pickerText(?string $firstName, int $chatId, int $userId, int $view): string
    {
        $name = trim((string) $firstName);
        $greeting = $name === '' ? '👥 <b>اختر فرقك</b>' : '👥 <b>اختر فرقك يا '.$this->escapeHtml($name).'</b>';

        $lines = [$greeting];

        $lines[] = $view === self::MENU
            ? 'اختر التصنيف أولًا:'
            : 'اضغط على الفريق للانضمام — واضغط عليه مرة أخرى للخروج منه.';

        $teams = $this->teamNamesOf($chatId, $userId);

        $lines[] = $teams === []
            ? 'فرقك الآن: لا شيء بعد.'
            : 'فرقك الآن: '.$this->joinNames($teams);

        if ($view !== self::MENU && $this->teamsIn($chatId, $view)->count() > self::MAX_BUTTONS) {
            $lines[] = '<i>تُعرض أول '.self::MAX_BUTTONS.' فريقًا — لبقيتها استخدم: انضم اسم الفريق</i>';
        }

        return implode("\n", $lines);
    }

    /**
     * The names of the teams the member is in, in this chat.
     *
     * @return array<int, string>
     */
    private function teamNamesOf(int $chatId, int $userId): array
    {
        return TelegramTeamMember::query()
            ->where('telegram_user_id', $userId)
            ->whereHas('team', fn ($query) => $query->where('chat_id', $chatId))
            ->with('team')
            ->get()
            ->map(fn (TelegramTeamMember $member): string => $member->team->name)
            ->sort()
            ->values()
            ->all();
    }

    private function keyboard(int $chatId, int $userId, int $view): Keyboard
    {
        $keyboard = Keyboard::make()->inline();

        foreach ($this->buttonRows($chatId, $userId, $view) as $row) {
            $keyboard->row($row);
        }

        return $keyboard;
    }

    /**
     * @return array<int, array<int, \Telegram\Bot\Keyboard\Button>>
     */
    private function buttonRows(int $chatId, int $userId, int $view): array
    {
        $buttons = $view === self::MENU
            ? $this->categoryButtons($chatId, $userId)
            : $this->teamButtons($chatId, $userId, $view);

        $rows = array_chunk($buttons, 2);

        $footer = [];

        if ($view !== self::MENU && count($this->buckets($chatId)) > 1) {
            $footer[] = $this->button('⬅️ التصنيفات', 'menu', $userId);
        }

        $footer[] = $this->button('✔️ تم', 'done', $userId);

        $rows[] = $footer;

        return $rows;
    }

    /**
     * @return array<int, \Telegram\Bot\Keyboard\Button>
     */
    private function categoryButtons(int $chatId, int $userId): array
    {
        $buttons = [];

        foreach ($this->buckets($chatId) as $id => $name) {
            $count = $this->teamsIn($chatId, $id)->count();
            $buttons[] = $this->button("{$name} ({$count})", 'c:'.$id, $userId);
        }

        return $buttons;
    }

    /**
     * @return array<int, \Telegram\Bot\Keyboard\Button>
     */
    private function teamButtons(int $chatId, int $userId, int $view): array
    {
        $memberOf = TelegramTeamMember::query()
            ->where('telegram_user_id', $userId)
            ->whereHas('team', fn ($query) => $query->where('chat_id', $chatId))
            ->pluck('team_id')
            ->map(fn ($teamId): int => (int) $teamId)
            ->all();

        $buttons = [];

        foreach ($this->teamsIn($chatId, $view)->take(self::MAX_BUTTONS) as $team) {
            $mark = in_array((int) $team->id, $memberOf, true) ? '✅ ' : '';
            $buttons[] = $this->button($mark.$team->name, 't:'.$team->id, $userId);
        }

        return $buttons;
    }

    private function button(string $text, string $action, int $ownerId): \Telegram\Bot\Keyboard\Button
    {
        return Keyboard::inlineButton([
            'text' => $text,
            'callback_data' => self::CALLBACK_PREFIX.$ownerId.':'.$action,
        ]);
    }

    private function answerCallback(CallbackQuery $callback, string $text, bool $alert = false): void
    {
        try {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $callback->getId(),
                'text' => $text,
                'show_alert' => $alert,
            ]);
        } catch (\Exception) {
            // The press still worked; the toast is best-effort.
        }
    }
}
