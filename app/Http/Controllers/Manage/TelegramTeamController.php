<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\StoreTelegramTeamAliasRequest;
use App\Http\Requests\Manage\StoreTelegramTeamRequest;
use App\Http\Requests\Manage\UpdateTelegramTeamRequest;
use App\Models\TelegramChatSetting;
use App\Models\TelegramTeam;
use App\Models\TelegramTeamAlias;
use App\Models\TelegramTeamCategory;
use App\Models\TelegramTeamMember;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The panel side of the bot's group teams. Categories and teams are fully
 * editable here; memberships are remove-only — adding a member always goes
 * through the Telegram consent flow («انضم» + an admin's «أضف» reply).
 */
class TelegramTeamController extends Controller
{
    /**
     * The chats that have teams, with their counts.
     */
    public function index(): Response
    {
        $teams = TelegramTeam::query()->withCount('members')->get();

        $titles = TelegramChatSetting::query()
            ->whereIn('chat_id', $teams->pluck('chat_id')->unique())
            ->pluck('title', 'chat_id');

        return Inertia::render('manage/telegram-teams/Index', [
            'chats' => $teams
                ->groupBy('chat_id')
                ->map(fn ($chatTeams, int|string $chatId): array => [
                    'chat_id' => (string) $chatId,
                    'title' => $titles[$chatId] ?? null,
                    'teams_count' => $chatTeams->count(),
                    'members_count' => $chatTeams->sum('members_count'),
                ])
                ->values(),
        ]);
    }

    /**
     * One chat's categories, teams and members.
     */
    public function show(string $chatId): Response
    {
        $chatId = (int) $chatId;

        $teams = TelegramTeam::query()
            ->where('chat_id', $chatId)
            ->with([
                'members' => fn ($query) => $query->orderBy('id'),
                'aliases' => fn ($query) => $query->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();

        abort_if($teams->isEmpty() && ! TelegramTeamCategory::query()->where('chat_id', $chatId)->exists(), 404);

        return Inertia::render('manage/telegram-teams/Show', [
            'chat' => [
                'chat_id' => (string) $chatId,
                'title' => TelegramChatSetting::forChat($chatId)?->title,
            ],
            'categories' => TelegramTeamCategory::query()
                ->where('chat_id', $chatId)
                ->orderBy('name')
                ->get()
                ->map(fn (TelegramTeamCategory $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                ]),
            'teams' => $teams->map(fn (TelegramTeam $team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'category_id' => $team->category_id,
                'aliases' => $team->aliases->map(fn (TelegramTeamAlias $alias): array => [
                    'id' => $alias->id,
                    'name' => $alias->name,
                ]),
                'members' => $team->members->map(fn (TelegramTeamMember $member): array => [
                    'id' => $member->id,
                    'telegram_user_id' => (string) $member->telegram_user_id,
                    'name' => $member->displayName(),
                    'username' => $member->username,
                    'consented_at' => $member->consented_at?->toISOString(),
                    'added_by_telegram_id' => (string) $member->added_by_telegram_id,
                ]),
            ]),
        ]);
    }

    public function store(StoreTelegramTeamRequest $request, string $chatId): RedirectResponse
    {
        TelegramTeam::query()->create([
            'chat_id' => (int) $chatId,
            'name' => $request->string('name')->trim()->value(),
            'category_id' => $request->input('category_id'),
        ]);

        return back()->with('success', 'تم إنشاء الفريق.');
    }

    /**
     * Rename a team or move it between categories.
     */
    public function update(UpdateTelegramTeamRequest $request, TelegramTeam $team): RedirectResponse
    {
        $team->update($request->safe()->only(['name', 'category_id']));

        return back();
    }

    public function destroy(TelegramTeam $team): RedirectResponse
    {
        $team->delete();

        return back()->with('success', 'تم حذف الفريق وعضوياته.');
    }

    /**
     * Remove one membership. There is deliberately no panel-side "add
     * member" — consent lives in Telegram only.
     */
    public function destroyMember(TelegramTeamMember $member): RedirectResponse
    {
        $member->delete();

        return back()->with('success', 'تمت إزالة العضو من الفريق.');
    }

    /**
     * Add an extra name the team answers to in Telegram.
     */
    public function storeAlias(StoreTelegramTeamAliasRequest $request, TelegramTeam $team): RedirectResponse
    {
        $team->aliases()->create([
            'chat_id' => $team->chat_id,
            'name' => $request->string('name')->trim()->value(),
        ]);

        return back()->with('success', 'تمت إضافة الاختصار.');
    }

    public function destroyAlias(TelegramTeamAlias $alias): RedirectResponse
    {
        $alias->delete();

        return back()->with('success', 'تم حذف الاختصار.');
    }
}
