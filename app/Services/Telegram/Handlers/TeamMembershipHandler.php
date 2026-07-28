<?php

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramTeamMember;
use Telegram\Bot\Objects\Message;

/**
 * The consent-based membership lifecycle: a member posts «انضم ف1، ف2», a
 * group admin replies «أضف» to that exact message within 24 hours, and the
 * membership is created. «انسحب» is an unconditional self opt-out, and «أزل»
 * (as a reply to any of the member's messages) is admin removal. Consent is
 * verified from the replied-to message itself — sender, shape and Telegram
 * timestamp — so nothing needs to be stored or expired server-side.
 */
class TeamMembershipHandler extends BaseTeamHandler
{
    public function handle(Message $message): void
    {
        $text = $message->getText();
        $content = is_string($text) ? trim($text) : '';

        if (preg_match(self::JOIN_PATTERN, $content, $matches) === 1) {
            $this->requestJoin($message, trim($matches[1]));

            return;
        }

        if (preg_match('/^[اأ]ضف(?:\s+(.+))?$/u', $content, $matches) === 1) {
            $this->approve($message, isset($matches[1]) ? trim($matches[1]) : null);

            return;
        }

        if (preg_match('/^انسحب(?:\s+(.+))?$/u', $content, $matches) === 1) {
            $this->leave($message, isset($matches[1]) ? trim($matches[1]) : null);

            return;
        }

        if (preg_match('/^[اأ]زل\s+(.+)$/u', $content, $matches) === 1) {
            $this->removeByAdmin($message, trim($matches[1]));
        }
    }

    /**
     * «انضم ف1، ف2» — the consent message. The bot only acknowledges and
     * explains the approval step; nothing is written until an admin replies.
     */
    protected function requestJoin(Message $message, string $rawList): void
    {
        if (! $this->isGroupChat($message)) {
            $this->reply($message, self::GROUP_ONLY_MESSAGE);

            return;
        }

        $this->trackCommand($message, 'team_join_request');

        $chatId = (int) $message->getChat()->getId();
        $names = $this->parseNameList($rawList);

        if ($names === []) {
            $this->reply($message, 'حدد الفريق: انضم اسم الفريق');

            return;
        }

        [$teams, $missing] = $this->resolveTeams($chatId, $names);

        if ($missing !== []) {
            $this->replyHtml($message, 'الفرق التالية غير موجودة: '.$this->joinNames($missing)."\nاعرض الفرق المتاحة بالأمر: الفرق");

            return;
        }

        $userId = (int) $message->getFrom()->getId();

        $alreadyIn = TelegramTeamMember::query()
            ->whereIn('team_id', $teams->pluck('id'))
            ->where('telegram_user_id', $userId)
            ->with('team')
            ->get()
            ->map(fn (TelegramTeamMember $member): string => $member->team->name);

        if ($alreadyIn->count() === $teams->count()) {
            $this->replyHtml($message, 'أنت بالفعل عضو في: '.$this->joinNames($alreadyIn));

            return;
        }

        $name = $this->personName($message->getFrom());

        $this->replyToSender(
            $message,
            "📝 تم استلام طلبك يا {$this->escapeHtml($name)}!\n"
            .'للإتمام: يرد أحد مشرفي المجموعة على رسالتك بكلمة «أضف» خلال '.self::CONSENT_MAX_AGE_HOURS." ساعة.\n"
            .'الفرق المطلوبة: '.$this->joinNames($teams->pluck('name')),
            'HTML',
        );
    }

    /**
     * «أضف» as a reply to a consent message. «أضف ف1» approves a subset of
     * what the member asked for — never anything they didn't.
     */
    protected function approve(Message $message, ?string $subsetRaw): void
    {
        if (! $this->isGroupChat($message)) {
            return;
        }

        $consent = $message->getReplyToMessage();

        if (! $consent instanceof Message) {
            return;
        }

        $consentText = $consent->getText();
        $consentContent = is_string($consentText) ? trim($consentText) : '';
        $isConsent = $consent->getFrom() !== null
            && ! $consent->getFrom()->getIsBot()
            && preg_match(self::JOIN_PATTERN, $consentContent, $consentMatches) === 1;

        if (! $isConsent) {
            // Only the bare «أضف» earns a hint; «أضف …» with arguments may be
            // another handler's command used as a reply.
            if ($subsetRaw === null) {
                $this->reply($message, 'للموافقة، رد بكلمة «أضف» على رسالة «انضم …» المرسلة من العضو نفسه.');
            }

            return;
        }

        $this->trackCommand($message, 'team_approve');

        if (! $this->isGroupAdmin($message)) {
            $this->reply($message, self::ADMIN_ONLY_MESSAGE);

            return;
        }

        $consentDate = (int) $consent->getDate();

        if ($consentDate < now()->subHours(self::CONSENT_MAX_AGE_HOURS)->getTimestamp()) {
            $this->reply($message, '⌛ انتهت صلاحية رسالة الموافقة (أقدم من '.self::CONSENT_MAX_AGE_HOURS." ساعة).\nاطلب من العضو إرسال «انضم …» من جديد.");

            return;
        }

        $consentNames = $this->parseNameList(trim($consentMatches[1]));
        $approvedNames = $consentNames;

        if ($subsetRaw !== null) {
            $requested = $this->parseNameList($subsetRaw);
            $outsideConsent = array_values(array_diff($requested, $consentNames));

            if ($outsideConsent !== []) {
                $this->replyHtml($message, 'هذه الفرق ليست في رسالة الموافقة: '.$this->joinNames($outsideConsent)."\nلا يمكن إضافة العضو إلا لما طلبه بنفسه.");

                return;
            }

            $approvedNames = $requested;
        }

        $chatId = (int) $message->getChat()->getId();

        [$teams, $missing] = $this->resolveTeams($chatId, $approvedNames);

        if ($teams->isEmpty()) {
            $this->replyHtml($message, 'الفرق المطلوبة لم تعد موجودة: '.$this->joinNames($missing));

            return;
        }

        $member = $consent->getFrom();
        $memberId = (int) $member->getId();
        $added = [];
        $blocked = [];

        foreach ($teams as $team) {
            $optedOutAt = $this->consentBlockedSince($chatId, $memberId, (int) $team->id);

            if ($optedOutAt !== null && $consentDate <= $optedOutAt) {
                $blocked[] = $team->name;

                continue;
            }

            TelegramTeamMember::query()->updateOrCreate(
                [
                    'team_id' => $team->id,
                    'telegram_user_id' => $memberId,
                ],
                [
                    'first_name' => $member->getFirstName(),
                    'username' => $member->getUsername(),
                    'consent_message_id' => (int) $consent->getMessageId(),
                    'consented_at' => now()->setTimestamp($consentDate),
                    'added_by_telegram_id' => (int) $message->getFrom()->getId(),
                ],
            );

            $added[] = $team->name;
        }

        $lines = [];

        if ($added !== []) {
            $lines[] = '✅ تمت إضافة '.$this->mentionUser($memberId, $member->getUsername(), $this->personName($member)).' إلى: '.$this->joinNames($added);
        }

        if ($blocked !== []) {
            $lines[] = '⚠️ تجاهلت: '.$this->joinNames($blocked).' — انسحب العضو بعد هذا الطلب، ويلزمه إرسال «انضم» جديد.';
        }

        if ($missing !== []) {
            $lines[] = '⚠️ لم تعد موجودة: '.$this->joinNames($missing);
        }

        $this->replyHtml($message, implode("\n", $lines));
    }

    /**
     * «انسحب ف1» / «انسحب من الكل» — self opt-out, no approval needed, and it
     * kills any still-fresh consent messages for the removed teams.
     */
    protected function leave(Message $message, ?string $rawList): void
    {
        if (! $this->isGroupChat($message)) {
            $this->reply($message, self::GROUP_ONLY_MESSAGE);

            return;
        }

        $this->trackCommand($message, 'team_leave');

        if ($rawList === null || $rawList === '') {
            $this->reply($message, 'حدد الفريق: «انسحب اسم الفريق» — أو: «انسحب من الكل».');

            return;
        }

        $chatId = (int) $message->getChat()->getId();
        $userId = (int) $message->getFrom()->getId();

        $memberships = TelegramTeamMember::query()
            ->where('telegram_user_id', $userId)
            ->whereHas('team', fn ($query) => $query->where('chat_id', $chatId))
            ->with('team')
            ->get();

        if ($memberships->isEmpty()) {
            $this->reply($message, 'لست عضوًا في أي فريق في هذه المجموعة.');

            return;
        }

        if ($rawList === 'من الكل') {
            $names = $memberships->map(fn (TelegramTeamMember $member): string => $member->team->name);

            foreach ($memberships as $membership) {
                $this->blockStaleConsents($chatId, $userId, (int) $membership->team_id);
                $membership->delete();
            }

            $this->replyHtml($message, '✅ تم انسحابك من كل الفرق: '.$this->joinNames($names));

            return;
        }

        $names = $this->parseNameList($rawList);

        [$teams, $missing] = $this->resolveTeams($chatId, $names);

        $removed = [];
        $notMember = [];

        foreach ($teams as $team) {
            $membership = $memberships->firstWhere('team_id', $team->id);

            if ($membership === null) {
                $notMember[] = $team->name;

                continue;
            }

            $this->blockStaleConsents($chatId, $userId, (int) $team->id);
            $membership->delete();
            $removed[] = $team->name;
        }

        $lines = [];

        if ($removed !== []) {
            $lines[] = '✅ تم انسحابك من: '.$this->joinNames($removed);

            $remaining = $memberships
                ->reject(fn (TelegramTeamMember $member): bool => in_array($member->team->name, $removed, true))
                ->map(fn (TelegramTeamMember $member): string => $member->team->name);

            if ($remaining->isNotEmpty()) {
                $lines[] = '(ما زلت في: '.$this->joinNames($remaining).')';
            }
        }

        if ($notMember !== []) {
            $lines[] = 'لست عضوًا في: '.$this->joinNames($notMember);
        }

        if ($missing !== []) {
            $lines[] = 'غير موجودة: '.$this->joinNames($missing);
        }

        $this->replyHtml($message, implode("\n", $lines));
    }

    /**
     * «أزل ف1» / «أزل من الكل» as a reply to any of the member's messages —
     * admin removal, which also invalidates that member's older consents.
     */
    protected function removeByAdmin(Message $message, string $rawList): void
    {
        if (! $this->isGroupChat($message)) {
            $this->reply($message, self::GROUP_ONLY_MESSAGE);

            return;
        }

        $this->trackCommand($message, 'team_remove');

        $target = $message->getReplyToMessage()?->getFrom();

        if ($target === null) {
            $this->reply($message, 'استخدم هذا الأمر بالرد على رسالة من العضو المراد إزالته.');

            return;
        }

        if (! $this->isGroupAdmin($message)) {
            $this->reply($message, self::ADMIN_ONLY_MESSAGE);

            return;
        }

        if ($target->getIsBot()) {
            $this->reply($message, 'هذه رسالة بوت — رد على رسالة العضو نفسه.');

            return;
        }

        $chatId = (int) $message->getChat()->getId();
        $targetId = (int) $target->getId();

        $memberships = TelegramTeamMember::query()
            ->where('telegram_user_id', $targetId)
            ->whereHas('team', fn ($query) => $query->where('chat_id', $chatId))
            ->with('team')
            ->get();

        if ($memberships->isEmpty()) {
            $this->reply($message, 'هذا العضو ليس في أي فريق.');

            return;
        }

        $targetName = $this->personName($target);

        if ($rawList === 'من الكل') {
            $names = $memberships->map(fn (TelegramTeamMember $member): string => $member->team->name);

            foreach ($memberships as $membership) {
                $this->blockStaleConsents($chatId, $targetId, (int) $membership->team_id);
                $membership->delete();
            }

            $this->replyHtml($message, "✅ تمت إزالة {$this->escapeHtml($targetName)} من كل الفرق: ".$this->joinNames($names));

            return;
        }

        $names = $this->parseNameList($rawList);

        [$teams, $missing] = $this->resolveTeams($chatId, $names);

        $removed = [];
        $notMember = [];

        foreach ($teams as $team) {
            $membership = $memberships->firstWhere('team_id', $team->id);

            if ($membership === null) {
                $notMember[] = $team->name;

                continue;
            }

            $this->blockStaleConsents($chatId, $targetId, (int) $team->id);
            $membership->delete();
            $removed[] = $team->name;
        }

        $lines = [];

        if ($removed !== []) {
            $lines[] = "✅ تمت إزالة {$this->escapeHtml($targetName)} من: ".$this->joinNames($removed);
        }

        if ($notMember !== []) {
            $lines[] = 'ليس عضوًا في: '.$this->joinNames($notMember);
        }

        if ($missing !== []) {
            $lines[] = 'غير موجودة: '.$this->joinNames($missing);
        }

        $this->replyHtml($message, implode("\n", $lines));
    }
}
