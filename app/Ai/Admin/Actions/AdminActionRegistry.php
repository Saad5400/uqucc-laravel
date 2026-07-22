<?php

namespace App\Ai\Admin\Actions;

use App\Ai\Admin\Actions\Analytics\GetAiUsageAction;
use App\Ai\Admin\Actions\Analytics\GetAnalyticsAction;
use App\Ai\Admin\Actions\Analytics\ListActivityLogAction;
use App\Ai\Admin\Actions\Corpus\AuthorPageFromDocumentAction;
use App\Ai\Admin\Actions\Corpus\GetCorpusDocumentAction;
use App\Ai\Admin\Actions\Corpus\ListCorpusDocumentsAction;
use App\Ai\Admin\Actions\Corpus\ReextractCorpusDocumentAction;
use App\Ai\Admin\Actions\Corpus\ReingestCorpusDocumentAction;
use App\Ai\Admin\Actions\Pages\GetPageContentAction;
use App\Ai\Admin\Actions\Pages\ListPagesAction;
use App\Ai\Admin\Actions\Pages\ManagePageStructureAction;
use App\Ai\Admin\Actions\Pages\RestorePageAction;
use App\Ai\Admin\Actions\Pages\UpdatePageAction;
use App\Ai\Admin\Actions\Pages\UpdatePageContentAction;
use App\Ai\Admin\Actions\Quiz\CreateQuizTopicAction;
use App\Ai\Admin\Actions\Quiz\DeleteQuizTopicAction;
use App\Ai\Admin\Actions\Quiz\GetDailyQuizAction;
use App\Ai\Admin\Actions\Quiz\GetQuizLeaderboardAction;
use App\Ai\Admin\Actions\Quiz\ListQuizTopicsAction;
use App\Ai\Admin\Actions\Quiz\RegenerateDailyQuizAction;
use App\Ai\Admin\Actions\Quiz\UpdateDailyQuizAction;
use App\Ai\Admin\Actions\Quiz\UpdateQuizTopicAction;
use App\Ai\Admin\Actions\Reviews\ApprovePageChangeAction;
use App\Ai\Admin\Actions\Reviews\ListPendingChangesAction;
use App\Ai\Admin\Actions\Reviews\RejectPageChangeAction;
use App\Ai\Admin\Actions\Reviews\ShowPageChangeAction;
use App\Ai\Admin\Actions\Settings\GetSettingsAction;
use App\Ai\Admin\Actions\Settings\UpdateSettingAction;
use App\Ai\Admin\Actions\System\ClearCacheAction;
use App\Ai\Admin\Actions\System\ListRoutesAction;
use App\Ai\Admin\Actions\System\SiteOverviewAction;
use App\Ai\Admin\Actions\Telegram\DeleteTelegramChatAction;
use App\Ai\Admin\Actions\Telegram\ListTelegramChatsAction;
use App\Ai\Admin\Actions\Telegram\ResetTelegramChatAction;
use App\Ai\Admin\Actions\Telegram\SetTelegramChatAiAction;
use App\Ai\Admin\Actions\Tutors\CreateCourseAction;
use App\Ai\Admin\Actions\Tutors\CreateTutorAction;
use App\Ai\Admin\Actions\Tutors\DeleteCourseAction;
use App\Ai\Admin\Actions\Tutors\DeleteTutorAction;
use App\Ai\Admin\Actions\Tutors\ListTutorsAction;
use App\Ai\Admin\Actions\Tutors\ReorderCoursesAction;
use App\Ai\Admin\Actions\Tutors\ReorderTutorsAction;
use App\Ai\Admin\Actions\Tutors\UpdateCourseAction;
use App\Ai\Admin\Actions\Tutors\UpdateTutorAction;
use App\Ai\Admin\Actions\Users\CreateUserAction;
use App\Ai\Admin\Actions\Users\DeleteUserAction;
use App\Ai\Admin\Actions\Users\ListUsersAction;
use App\Ai\Admin\Actions\Users\UpdateUserAction;

/**
 * The single ordered list of unified admin capabilities. Both AI surfaces
 * build their toolset from here — the in-app assistant wraps each action in an
 * {@see AssistantActionTool}, the MCP server in an
 * {@see \App\Mcp\Tools\AdminActionTool} — so a capability is defined once and
 * appears on both surfaces with identical permissions and validation.
 */
class AdminActionRegistry
{
    /**
     * @var list<class-string<AdminAction>>
     */
    private const ACTIONS = [
        // Pages
        ListPagesAction::class,
        GetPageContentAction::class,
        ManagePageStructureAction::class,
        UpdatePageAction::class,
        UpdatePageContentAction::class,
        RestorePageAction::class,
        // Reviews (pending page-change queue)
        ListPendingChangesAction::class,
        ShowPageChangeAction::class,
        ApprovePageChangeAction::class,
        RejectPageChangeAction::class,
        // Tutors + course taxonomy
        ListTutorsAction::class,
        CreateTutorAction::class,
        UpdateTutorAction::class,
        DeleteTutorAction::class,
        ReorderTutorsAction::class,
        CreateCourseAction::class,
        UpdateCourseAction::class,
        DeleteCourseAction::class,
        ReorderCoursesAction::class,
        // Users
        ListUsersAction::class,
        CreateUserAction::class,
        UpdateUserAction::class,
        DeleteUserAction::class,
        // Settings
        GetSettingsAction::class,
        UpdateSettingAction::class,
        // Telegram (per-chat)
        ListTelegramChatsAction::class,
        SetTelegramChatAiAction::class,
        ResetTelegramChatAction::class,
        DeleteTelegramChatAction::class,
        // Daily quiz (topics + questions + leaderboard)
        ListQuizTopicsAction::class,
        CreateQuizTopicAction::class,
        UpdateQuizTopicAction::class,
        DeleteQuizTopicAction::class,
        GetDailyQuizAction::class,
        UpdateDailyQuizAction::class,
        RegenerateDailyQuizAction::class,
        GetQuizLeaderboardAction::class,
        // Corpus / knowledge base
        ListCorpusDocumentsAction::class,
        GetCorpusDocumentAction::class,
        ReextractCorpusDocumentAction::class,
        ReingestCorpusDocumentAction::class,
        AuthorPageFromDocumentAction::class,
        // Analytics / audit
        GetAnalyticsAction::class,
        GetAiUsageAction::class,
        ListActivityLogAction::class,
        // System / context
        SiteOverviewAction::class,
        ListRoutesAction::class,
        ClearCacheAction::class,
    ];

    /** @var array<string, AdminAction>|null */
    private ?array $resolved = null;

    /**
     * Every action, resolved from the container and keyed by tool name.
     *
     * @return array<string, AdminAction>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = [];

        foreach (self::ACTIONS as $class) {
            $action = app($class);
            $resolved[$action->name()] = $action;
        }

        return $this->resolved = $resolved;
    }

    public function get(string $name): ?AdminAction
    {
        return $this->all()[$name] ?? null;
    }
}
