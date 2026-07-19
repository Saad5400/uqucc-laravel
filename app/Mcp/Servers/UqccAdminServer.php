<?php

namespace App\Mcp\Servers;

use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionRegistry;
use App\Mcp\Tools\AdminActionTool;
use App\Mcp\Tools\Moderation\ApprovePageChangeTool;
use App\Mcp\Tools\Moderation\CreateTutorTool;
use App\Mcp\Tools\Moderation\CreateUserTool;
use App\Mcp\Tools\Moderation\DeleteTutorTool;
use App\Mcp\Tools\Moderation\DeleteUserTool;
use App\Mcp\Tools\Moderation\ListPendingChangesTool;
use App\Mcp\Tools\Moderation\ListTutorsTool;
use App\Mcp\Tools\Moderation\ListUsersTool;
use App\Mcp\Tools\Moderation\RejectPageChangeTool;
use App\Mcp\Tools\Moderation\UpdateTutorTool;
use App\Mcp\Tools\Moderation\UpdateUserTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * Authenticated moderation MCP server for the UQU College of Computing
 * student guide (uqucc). Unlike the public {@see UqccServer}, every tool
 * here performs a privileged write (or reads non-public admin state), so the
 * route is protected by OAuth2 (`auth:api`, Passport) in routes/ai.php.
 *
 * Each tool re-checks the signed-in moderator's ability with
 * `$request->user()->can(...)` — the same abilities the `/manage` panel
 * enforces (`review-changes`, `manage-private-tutors`, `manage-users`,
 * `edit-content`) — so an authenticated editor still cannot reach an
 * admin-only surface.
 */
class UqccAdminServer extends Server
{
    protected string $name = 'UQU CC Moderation';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Authenticated moderation tools for the College of Computing student guide (uqucc) at Umm Al-Qura University. Every tool acts as the signed-in moderator and enforces the same permissions as the admin panel: review and approve/reject pending page edits, manage private tutors, manage panel users, and edit/trash guide pages. Actions are permanent and change live site content — confirm intent before calling a write tool. Content is Arabic.

        أدوات إشراف موثّقة لدليل طلاب كلية الحاسبات بجامعة أم القرى. كل أداة تعمل نيابةً عن المشرف المسجَّل وتطبّق صلاحيات لوحة الإدارة نفسها: مراجعة التعديلات المعلّقة واعتمادها أو رفضها، وإدارة المدرّسين الخصوصيين، وإدارة مستخدمي اللوحة، وتعديل صفحات الدليل أو حذفها. الإجراءات دائمة وتغيّر محتوى الموقع الفعلي، لذا تأكّد من النية قبل استدعاء أي أداة كتابة.
        MARKDOWN;

    /**
     * Serve the whole toolset in one tools/list page.
     */
    public int $defaultPaginationLength = 50;

    /**
     * The moderation tools not yet migrated to the unified action registry
     * (reviews, tutors, users). The unified page/settings actions are added as
     * INSTANCES in the constructor. As each of these domains moves onto an
     * {@see AdminAction}, its legacy tool is removed from here.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $moderationTools = [
        ListPendingChangesTool::class,
        ApprovePageChangeTool::class,
        RejectPageChangeTool::class,
        ListTutorsTool::class,
        CreateTutorTool::class,
        UpdateTutorTool::class,
        DeleteTutorTool::class,
        ListUsersTool::class,
        CreateUserTool::class,
        UpdateUserTool::class,
        DeleteUserTool::class,
    ];

    /**
     * Register the unified admin actions (as configured adapter instances) plus
     * the remaining legacy moderation tools. laravel/mcp resolves the server
     * through the container with `transport` as a named parameter, so the
     * registry is autowired here.
     */
    public function __construct(Transport $transport, AdminActionRegistry $registry)
    {
        parent::__construct($transport);

        $actionTools = array_map(
            static fn (AdminAction $action): AdminActionTool => new AdminActionTool($action),
            array_values($registry->all()),
        );

        $this->tools = [...$actionTools, ...$this->moderationTools];
    }
}
