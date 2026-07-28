<?php

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramTeam;
use App\Models\TelegramTeamMember;
use Telegram\Bot\Objects\Message;

/**
 * Read-only team commands: «الفرق» (the chat's teams grouped by their
 * categories), «أعضاء فريق» (a plain, non-pinging member list) and «فرقي»
 * (the sender's own memberships).
 */
class TeamInfoHandler extends BaseTeamHandler
{
    /** Cap on names shown by «أعضاء» before summarizing the rest. */
    private const MEMBER_LIST_LIMIT = 100;

    public function handle(Message $message): void
    {
        $text = $message->getText();
        $content = is_string($text) ? trim($text) : '';

        if ($content === 'الفرق') {
            $this->listTeams($message);

            return;
        }

        if (preg_match('/^[اأ]عضاء\s+(.+)$/u', $content, $matches) === 1) {
            $this->listMembers($message, trim($matches[1]));

            return;
        }

        if ($content === 'فرقي') {
            $this->listMyTeams($message);
        }
    }

    protected function listTeams(Message $message): void
    {
        if (! $this->isGroupChat($message)) {
            $this->reply($message, self::GROUP_ONLY_MESSAGE);

            return;
        }

        $this->trackCommand($message, 'teams_list');

        $teams = TelegramTeam::query()
            ->where('chat_id', (int) $message->getChat()->getId())
            ->with(['category', 'aliases'])
            ->withCount('members')
            ->orderBy('name')
            ->get();

        if ($teams->isEmpty()) {
            $this->reply($message, "لا توجد فرق في هذه المجموعة بعد.\nينشئها مشرفو المجموعة بالأمر: فريق جديد اسم الفريق");

            return;
        }

        $grouped = $teams->groupBy(fn (TelegramTeam $team): string => $team->category?->name ?? '');

        $lines = $grouped
            ->sortKeys()
            ->sortBy(fn ($group, string $categoryName): int => $categoryName === '' ? 1 : 0)
            ->map(function ($group, string $categoryName): string {
                $label = $categoryName === '' ? 'بدون تصنيف' : $categoryName;
                $teamList = $group
                    ->map(function (TelegramTeam $team): string {
                        $aliases = $team->aliases->isNotEmpty()
                            ? ' ['.$team->aliases->map(fn ($alias): string => $this->escapeHtml($alias->name))->implode('، ').']'
                            : '';

                        return $this->escapeHtml($team->name)."{$aliases} ({$team->members_count})";
                    })
                    ->implode(' • ');

                return '<b>'.$this->escapeHtml($label).'</b> — '.$teamList;
            });

        $this->replyHtml(
            $message,
            "📚 فرق هذه المجموعة:\n".$lines->implode("\n")."\n\nللانضمام: انضم اسم الفريق",
        );
    }

    protected function listMembers(Message $message, string $teamName): void
    {
        if (! $this->isGroupChat($message)) {
            $this->reply($message, self::GROUP_ONLY_MESSAGE);

            return;
        }

        $this->trackCommand($message, 'team_members');

        $team = TelegramTeam::findByName((int) $message->getChat()->getId(), $teamName);

        if ($team === null) {
            $this->reply($message, "لا يوجد فريق باسم «{$teamName}». اعرض الفرق بالأمر: الفرق");

            return;
        }

        $members = $team->members()->orderBy('id')->get();

        if ($members->isEmpty()) {
            $this->reply($message, "فريق «{$team->name}» بلا أعضاء بعد.\nالانضمام: انضم {$team->name}");

            return;
        }

        $shown = $members
            ->take(self::MEMBER_LIST_LIMIT)
            ->map(fn (TelegramTeamMember $member): string => $this->escapeHtml($this->memberPlainName($member)));

        $rest = $members->count() - $shown->count();
        $suffix = $rest > 0 ? "\nو{$rest} آخرين." : '';

        $this->replyHtml(
            $message,
            '👥 أعضاء «'.$this->escapeHtml($team->name)."» ({$members->count()}):\n".$shown->implode('، ').$suffix,
        );
    }

    protected function listMyTeams(Message $message): void
    {
        if (! $this->isGroupChat($message)) {
            $this->reply($message, self::GROUP_ONLY_MESSAGE);

            return;
        }

        $this->trackCommand($message, 'my_teams');

        $memberships = TelegramTeamMember::query()
            ->where('telegram_user_id', (int) $message->getFrom()->getId())
            ->whereHas('team', fn ($query) => $query->where('chat_id', (int) $message->getChat()->getId()))
            ->with('team')
            ->get();

        if ($memberships->isEmpty()) {
            $this->reply($message, "لست في أي فريق بعد.\nاعرض الفرق بالأمر «الفرق»، ثم اطلب الانضمام: انضم اسم الفريق");

            return;
        }

        $names = $memberships->map(fn (TelegramTeamMember $member): string => $member->team->name)->sort();

        $this->replyHtml($message, '👤 فرقك: '.$this->joinNames($names));
    }

    /**
     * The stored snapshot name without the @ that would ping the member.
     */
    private function memberPlainName(TelegramTeamMember $member): string
    {
        $name = trim((string) $member->first_name);

        if ($name !== '') {
            return $name;
        }

        return $member->username !== null && $member->username !== '' ? $member->username : 'عضو';
    }
}
