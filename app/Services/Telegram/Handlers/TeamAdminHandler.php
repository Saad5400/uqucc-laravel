<?php

namespace App\Services\Telegram\Handlers;

use App\Helpers\ArabicNormalizer;
use App\Models\TelegramTeam;
use App\Models\TelegramTeamAlias;
use App\Models\TelegramTeamCategory;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Message;

/**
 * Group-admin commands that shape a chat's teams: «فريق جديد», «حذف فريق»,
 * «تصنيف جديد», «حذف تصنيف» and «صنف … ضمن …». Every command is group-only
 * and checked live against getChatMember. Team deletion is destructive (it
 * drops memberships) so it goes through an inline confirm button; category
 * deletion just ungroups its teams and runs immediately.
 */
class TeamAdminHandler extends BaseTeamHandler
{
    /** Callback-data prefix for this handler's inline buttons. */
    public const CALLBACK_PREFIX = 'tgteam:';

    public function handle(Message $message): void
    {
        $text = $message->getText();
        $content = is_string($text) ? trim($text) : '';

        $route = $this->route($content);

        if ($route === null) {
            return;
        }

        if (! $this->isGroupChat($message)) {
            $this->reply($message, self::GROUP_ONLY_MESSAGE);

            return;
        }

        $this->trackCommand($message, $route['command']);

        if (! $this->isGroupAdmin($message)) {
            $this->reply($message, self::ADMIN_ONLY_MESSAGE);

            return;
        }

        match ($route['command']) {
            'team_create' => $this->createTeam($message, $route['args'][0], $route['args'][1] ?? null),
            'team_delete' => $this->confirmDeleteTeam($message, $route['args'][0]),
            'team_category_create' => $this->createCategory($message, $route['args'][0]),
            'team_category_delete' => $this->deleteCategory($message, $route['args'][0]),
            'team_categorize' => $this->categorizeTeam($message, $route['args'][0], $route['args'][1]),
            'team_alias_add' => $this->addAliases($message, $route['args'][0], $route['args'][1]),
            'team_alias_remove' => $this->removeAliases($message, $route['args'][0]),
        };
    }

    /**
     * Inline button presses for team deletion. Routed here by
     * {@see \App\Jobs\ProcessTelegramUpdate} for callback data starting with
     * {@see CALLBACK_PREFIX}. The presser's admin status is re-checked live —
     * anyone in the group can press a button.
     */
    public function handleCallback(CallbackQuery $callback): void
    {
        $data = (string) $callback->getData();
        $callbackMessage = $callback->getMessage();

        if (! str_starts_with($data, self::CALLBACK_PREFIX) || $callbackMessage === null) {
            return;
        }

        $chatId = (int) $callbackMessage->getChat()->getId();

        if (! $this->presserIsGroupAdmin($chatId, (int) $callback->getFrom()->getId())) {
            $this->answerCallback($callback, self::ADMIN_ONLY_MESSAGE, true);

            return;
        }

        $action = substr($data, strlen(self::CALLBACK_PREFIX));

        if ($action === 'cancel') {
            $this->editCallbackMessage($callback, 'أُلغي الحذف.');
            $this->answerCallback($callback, 'أُلغي');

            return;
        }

        if (str_starts_with($action, 'del:')) {
            $team = TelegramTeam::query()
                ->where('chat_id', $chatId)
                ->find((int) substr($action, 4));

            if ($team === null) {
                $this->editCallbackMessage($callback, 'هذا الفريق لم يعد موجودًا.');
                $this->answerCallback($callback, 'غير موجود');

                return;
            }

            $membersCount = $team->members()->count();
            $name = $team->name;
            $team->delete();

            $this->editCallbackMessage($callback, "✅ تم حذف فريق «{$name}» وعضوياته ({$membersCount}).");
            $this->answerCallback($callback, 'تم الحذف');
        }
    }

    /**
     * @return array{command: string, args: array<int, string>}|null
     */
    protected function route(string $content): ?array
    {
        if (preg_match('/^تصنيف جديد\s+(.+)$/u', $content, $matches)) {
            return ['command' => 'team_category_create', 'args' => [trim($matches[1])]];
        }

        if (preg_match('/^حذف تصنيف\s+(.+)$/u', $content, $matches)) {
            return ['command' => 'team_category_delete', 'args' => [trim($matches[1])]];
        }

        if (preg_match('/^فريق جديد\s+(.+?)(?:\s+ضمن\s+(.+))?$/u', $content, $matches)) {
            $args = [trim($matches[1])];

            if (isset($matches[2]) && trim($matches[2]) !== '') {
                $args[] = trim($matches[2]);
            }

            return ['command' => 'team_create', 'args' => $args];
        }

        if (preg_match('/^حذف فريق\s+(.+)$/u', $content, $matches)) {
            return ['command' => 'team_delete', 'args' => [trim($matches[1])]];
        }

        if (preg_match('/^صنّ?ف\s+(.+?)\s+ضمن\s+(.+)$/u', $content, $matches)) {
            return ['command' => 'team_categorize', 'args' => [trim($matches[1]), trim($matches[2])]];
        }

        if (preg_match('/^حذف اختصار\s+(.+)$/u', $content, $matches)) {
            return ['command' => 'team_alias_remove', 'args' => [trim($matches[1])]];
        }

        if (preg_match('/^اختصار\s+(.+?)\s*[:：=]\s*(.+)$/u', $content, $matches)) {
            return ['command' => 'team_alias_add', 'args' => [trim($matches[1]), trim($matches[2])]];
        }

        return null;
    }

    /**
     * «اختصار علوم الحاسب: cs، سي اس» — extra names the team answers to
     * everywhere: انضم, منشن فريق, أعضاء and the rest.
     */
    protected function addAliases(Message $message, string $teamName, string $rawAliases): void
    {
        $chatId = (int) $message->getChat()->getId();
        $team = TelegramTeam::findByName($chatId, $teamName);

        if ($team === null) {
            $this->reply($message, "لا يوجد فريق باسم «{$teamName}». اعرض الفرق بالأمر: الفرق");

            return;
        }

        $names = $this->parseNameList($rawAliases);

        if ($names === []) {
            $this->reply($message, "حدد الاختصار: اختصار {$team->name}: cs");

            return;
        }

        $added = [];
        $taken = [];
        $tooLong = [];

        foreach ($names as $name) {
            if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
                $tooLong[] = $name;

                continue;
            }

            if (TelegramTeam::nameIsTaken($chatId, $name, (int) $team->id)) {
                $taken[] = $name;

                continue;
            }

            $alias = TelegramTeamAlias::query()->create([
                'team_id' => $team->id,
                'chat_id' => $chatId,
                'name' => $name,
            ]);

            $added[] = $alias->name;
        }

        $lines = [];

        if ($added !== []) {
            $lines[] = '✅ أصبح فريق «'.$this->escapeHtml($team->name).'» يستجيب أيضًا لـ: '.$this->joinNames($added);
        }

        if ($taken !== []) {
            $lines[] = '⚠️ مستخدمة بالفعل في هذه المجموعة: '.$this->joinNames($taken);
        }

        if ($tooLong !== []) {
            $lines[] = '⚠️ طويلة (الحد '.self::MAX_NAME_LENGTH.' حرفًا): '.$this->joinNames($tooLong);
        }

        $this->replyHtml($message, implode("\n", $lines));
    }

    /**
     * «حذف اختصار cs» — drops the alias only; the team and its members stay.
     */
    protected function removeAliases(Message $message, string $rawAliases): void
    {
        $chatId = (int) $message->getChat()->getId();
        $names = $this->parseNameList($rawAliases);

        $removed = [];
        $missing = [];

        foreach ($names as $name) {
            $alias = TelegramTeamAlias::query()
                ->where('chat_id', $chatId)
                ->where('normalized_name', ArabicNormalizer::normalize($name))
                ->first();

            if ($alias === null) {
                $missing[] = $name;

                continue;
            }

            $removed[] = $alias->name;
            $alias->delete();
        }

        $lines = [];

        if ($removed !== []) {
            $lines[] = '✅ تم حذف الاختصار: '.$this->joinNames($removed);
        }

        if ($missing !== []) {
            $lines[] = '⚠️ لا يوجد اختصار بهذا الاسم: '.$this->joinNames($missing);
        }

        $this->replyHtml($message, implode("\n", $lines));
    }

    protected function createTeam(Message $message, string $name, ?string $categoryName): void
    {
        $chatId = (int) $message->getChat()->getId();

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $this->reply($message, 'اسم الفريق طويل — الحد الأقصى '.self::MAX_NAME_LENGTH.' حرفًا.');

            return;
        }

        if (TelegramTeam::nameIsTaken($chatId, $name)) {
            $this->reply($message, "الاسم «{$name}» مستخدم بالفعل في هذه المجموعة (كفريق أو كاختصار).");

            return;
        }

        if (TelegramTeam::query()->where('chat_id', $chatId)->count() >= self::MAX_TEAMS_PER_CHAT) {
            $this->reply($message, 'وصلت المجموعة للحد الأقصى من الفرق ('.self::MAX_TEAMS_PER_CHAT.'). احذف فريقًا قديمًا أولًا.');

            return;
        }

        $category = null;

        if ($categoryName !== null) {
            $category = TelegramTeamCategory::findByName($chatId, $categoryName);

            if ($category === null) {
                $this->reply($message, "تصنيف «{$categoryName}» غير موجود. أنشئه أولًا بالأمر: تصنيف جديد {$categoryName}");

                return;
            }
        }

        TelegramTeam::query()->create([
            'chat_id' => $chatId,
            'category_id' => $category?->id,
            'name' => $name,
            'created_by_telegram_id' => (int) $message->getFrom()->getId(),
        ]);

        $suffix = $category !== null ? " ضمن تصنيف «{$category->name}»" : '';

        $this->reply($message, "✅ تم إنشاء فريق «{$name}»{$suffix}.\nينضم له الأعضاء بإرسال «انضم» ثم الضغط على اسم الفريق.");
    }

    protected function confirmDeleteTeam(Message $message, string $name): void
    {
        $chatId = (int) $message->getChat()->getId();
        $team = TelegramTeam::findByName($chatId, $name);

        if ($team === null) {
            $this->reply($message, "لا يوجد فريق باسم «{$name}». اعرض الفرق بالأمر: الفرق");

            return;
        }

        $membersCount = $team->members()->count();

        $keyboard = Keyboard::make()
            ->inline()
            ->row([
                Keyboard::inlineButton([
                    'text' => 'تأكيد الحذف 🗑️',
                    'callback_data' => self::CALLBACK_PREFIX.'del:'.$team->id,
                ]),
                Keyboard::inlineButton([
                    'text' => 'إلغاء',
                    'callback_data' => self::CALLBACK_PREFIX.'cancel',
                ]),
            ]);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "حذف فريق «{$team->name}» سيزيل عضوياته ({$membersCount}) نهائيًا. متأكد؟",
            'reply_to_message_id' => $message->getMessageId(),
            'reply_markup' => $keyboard,
        ]);
    }

    protected function createCategory(Message $message, string $name): void
    {
        $chatId = (int) $message->getChat()->getId();

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $this->reply($message, 'اسم التصنيف طويل — الحد الأقصى '.self::MAX_NAME_LENGTH.' حرفًا.');

            return;
        }

        if (TelegramTeamCategory::findByName($chatId, $name) !== null) {
            $this->reply($message, "تصنيف «{$name}» موجود بالفعل.");

            return;
        }

        if (TelegramTeamCategory::query()->where('chat_id', $chatId)->count() >= self::MAX_CATEGORIES_PER_CHAT) {
            $this->reply($message, 'وصلت المجموعة للحد الأقصى من التصنيفات ('.self::MAX_CATEGORIES_PER_CHAT.').');

            return;
        }

        TelegramTeamCategory::query()->create([
            'chat_id' => $chatId,
            'name' => $name,
        ]);

        $this->reply($message, "✅ تم إنشاء تصنيف الفرق «{$name}».\nأنشئ فريقًا ضمنه: فريق جديد اسم الفريق ضمن {$name}");
    }

    protected function deleteCategory(Message $message, string $name): void
    {
        $chatId = (int) $message->getChat()->getId();
        $category = TelegramTeamCategory::findByName($chatId, $name);

        if ($category === null) {
            $this->reply($message, "لا يوجد تصنيف باسم «{$name}».");

            return;
        }

        $teamsCount = $category->teams()->count();
        $category->delete();

        $suffix = $teamsCount > 0 ? " أصبحت فرقه ({$teamsCount}) بدون تصنيف." : '';

        $this->reply($message, "✅ تم حذف تصنيف «{$name}».{$suffix}");
    }

    protected function categorizeTeam(Message $message, string $teamName, string $categoryName): void
    {
        $chatId = (int) $message->getChat()->getId();
        $team = TelegramTeam::findByName($chatId, $teamName);

        if ($team === null) {
            $this->reply($message, "لا يوجد فريق باسم «{$teamName}». اعرض الفرق بالأمر: الفرق");

            return;
        }

        $category = TelegramTeamCategory::findByName($chatId, $categoryName);

        if ($category === null) {
            $this->reply($message, "تصنيف «{$categoryName}» غير موجود. أنشئه أولًا بالأمر: تصنيف جديد {$categoryName}");

            return;
        }

        $team->update(['category_id' => $category->id]);

        $this->reply($message, "✅ أصبح فريق «{$team->name}» ضمن تصنيف «{$category->name}».");
    }

    protected function presserIsGroupAdmin(int $chatId, int $userId): bool
    {
        try {
            $member = $this->telegram->getChatMember([
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);

            return in_array($member->status, ['creator', 'administrator'], true);
        } catch (\Exception) {
            return false;
        }
    }

    protected function answerCallback(CallbackQuery $callback, string $text, bool $alert = false): void
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

    protected function editCallbackMessage(CallbackQuery $callback, string $text): void
    {
        $callbackMessage = $callback->getMessage();

        if ($callbackMessage === null) {
            return;
        }

        $this->telegram->editMessageText([
            'chat_id' => $callbackMessage->getChat()->getId(),
            'message_id' => $callbackMessage->getMessageId(),
            'text' => $text,
        ]);
    }
}
