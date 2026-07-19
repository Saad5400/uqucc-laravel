<?php

namespace App\Mcp\Servers;

use App\Ai\Tools\Toolbox;
use App\Mcp\Tools\ReadOnlyToolAdapter;
use Laravel\Mcp\Server;

/**
 * Public, read-only MCP server for the UQU College of Computing student
 * guide (uqucc). Serves the same toolset the in-app assistant uses, shared
 * through {@see Toolbox} and wrapped per-tool in {@see ReadOnlyToolAdapter}.
 *
 * Registered unauthenticated in routes/ai.php (every tool is read-only and
 * public content only) behind the `mcp` rate limiter. Its privileged sibling
 * {@see UqccAdminServer} exposes the moderation tools on its own path behind
 * OAuth (`auth:api`).
 */
class UqccServer extends Server
{
    protected string $name = 'UQU CC Student Guide';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        This MCP server exposes the student guide of the College of Computing at Umm Al-Qura University (uqucc) — a site by students, for students. All tools are read-only and public: search the guide's Arabic content, fetch full pages as markdown, run the official student calculators (GPA on the UQU 4.0 scale, absence/deprivation limits, internal-transfer composite score), and look up private tutors by course.

        هذا الخادم يتيح لعملاء الذكاء الاصطناعي الوصول لدليل طلاب كلية الحاسبات بجامعة أم القرى: البحث في محتوى الدليل، وقراءة الصفحات كاملة، وحاسبات المعدل والحرمان والتحويل، والبحث عن المدرسين الخصوصيين. المحتوى باللغة العربية، لذا يفضل استخدام استعلامات عربية. جميع الأدوات للقراءة فقط.
        MARKDOWN;

    /**
     * Serve the whole toolset in one tools/list page.
     */
    public int $defaultPaginationLength = 50;

    protected function boot(): void
    {
        $this->tools = array_map(
            fn (string $toolClass): ReadOnlyToolAdapter => new ReadOnlyToolAdapter($toolClass),
            Toolbox::tools(),
        );
    }
}
