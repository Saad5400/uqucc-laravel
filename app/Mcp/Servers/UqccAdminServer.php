<?php

namespace App\Mcp\Servers;

use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionRegistry;
use App\Mcp\Tools\AdminActionTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * Authenticated moderation MCP server for the UQU College of Computing student
 * guide (uqucc). Every tool is a unified {@see AdminAction} — the SAME
 * capabilities the in-app admin assistant exposes, built once from the
 * {@see AdminActionRegistry} and wrapped here in an {@see AdminActionTool} for
 * external AI clients. The route is protected by OAuth2 (`auth:api`, Passport)
 * in routes/ai.php; each tool re-checks the signed-in moderator's ability
 * (`review-changes`, `manage-private-tutors`, `manage-users`, `edit-content`,
 * …) exactly as the `/manage` panel does.
 */
class UqccAdminServer extends Server
{
    protected string $name = 'UQU CC Moderation';

    protected string $version = '2.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Authenticated admin tools for the College of Computing student guide (uqucc) at Umm Al-Qura University. Every tool acts as the signed-in moderator and enforces the same permissions as the admin panel: manage guide pages (structure, settings, and text content), review and approve/reject pending page edits, manage private tutors and their courses, and manage panel users. Read tools (list_pages, get_page_content, show_page_change, list_tutors, list_users, get_settings, site_overview, list_routes) are safe to call freely; write tools change live site content, so confirm intent first. Content is Arabic.

        أدوات إدارة موثّقة لدليل طلاب كلية الحاسبات بجامعة أم القرى. كل أداة تعمل نيابةً عن المشرف المسجَّل وتطبّق صلاحيات لوحة الإدارة نفسها: إدارة صفحات الدليل (البنية والإعدادات والمحتوى النصي)، ومراجعة التعديلات المعلّقة واعتمادها أو رفضها، وإدارة المدرّسين الخصوصيين وموادّهم، وإدارة مستخدمي اللوحة. أدوات القراءة آمنة، أما أدوات الكتابة فتغيّر محتوى الموقع الفعلي، لذا تأكّد من النية قبل استدعائها.
        MARKDOWN;

    /**
     * Serve the whole toolset in one tools/list page.
     */
    public int $defaultPaginationLength = 50;

    /**
     * Build the toolset from the unified action registry as configured adapter
     * instances. laravel/mcp resolves the server through the container with
     * `transport` as a named parameter, so the registry is autowired here.
     */
    public function __construct(Transport $transport, AdminActionRegistry $registry)
    {
        parent::__construct($transport);

        $this->tools = array_map(
            static fn (AdminAction $action): AdminActionTool => new AdminActionTool($action),
            array_values($registry->all()),
        );
    }
}
