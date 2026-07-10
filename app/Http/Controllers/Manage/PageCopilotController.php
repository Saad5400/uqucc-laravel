<?php

namespace App\Http\Controllers\Manage;

use App\Ai\Copilot\CopilotDisabledException;
use App\Ai\Copilot\PageCopilot;
use App\Ai\Copilot\TipTapContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\DraftPageSectionRequest;
use App\Http\Requests\Manage\ImprovePageTextRequest;
use App\Models\Page;
use App\Settings\AiSettings;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * The page-workspace copilot endpoints. Each runs one {@see PageCopilot}
 * generation and RETURNS the result as JSON — never saves — so the admin
 * reviews the suggestion in the editor and confirms by saving the page.
 *
 * The UI hides the copilot entirely while the admin_copilot feature (or the
 * master AI switch) is off in {@see AiSettings}; the endpoints enforce the
 * same flag first (403), before any other outcome, exactly like the hidden
 * admin actions they replace. {@see PageCopilot} re-checks it as well.
 */
class PageCopilotController extends Controller
{
    public function __construct(
        private readonly PageCopilot $copilot,
        private readonly AiSettings $settings,
    ) {}

    /**
     * «تحسين النص» — rewrite the editor's current content for clarity, with
     * an optional steering instruction. Returns the improved TipTap document.
     */
    public function improveText(ImprovePageTextRequest $request, Page $page): JsonResponse
    {
        if ($denied = $this->deniedResponse()) {
            return $denied;
        }

        $markdown = TipTapContent::toMarkdown($request->validated('content'));

        if (trim($markdown) === '') {
            return response()->json(['message' => 'لا يوجد محتوى لتحسينه — اكتب محتوى الصفحة أولاً ثم أعد المحاولة.'], 422);
        }

        return $this->respond(function () use ($markdown, $request): array {
            $improved = $this->copilot->improveText($markdown, trim((string) $request->validated('instruction', '')));

            return ['content' => TipTapContent::toDocument($improved)];
        });
    }

    /**
     * «مسودة قسم» — draft a new markdown section about a topic, grounded in
     * the page title + current content. Returns the TipTap document with the
     * section appended after the current content.
     */
    public function draftSection(DraftPageSectionRequest $request, Page $page): JsonResponse
    {
        if ($denied = $this->deniedResponse()) {
            return $denied;
        }

        $currentContent = $request->validated('content');

        return $this->respond(function () use ($currentContent, $request, $page): array {
            $context = trim('# '.trim($page->title)."\n\n".TipTapContent::toMarkdown($currentContent));

            $section = $this->copilot->draftSection(trim((string) $request->validated('topic')), $context);

            return ['content' => TipTapContent::append($currentContent, $section)];
        });
    }

    /**
     * «توليد وصف SEO» — generate a meta title + description from the page's
     * saved content. Returns the suggested title and the description wrapped
     * as the HTML the quick-response message field stores (that column is
     * the first source {@see \App\Support\Seo} uses for the meta description).
     */
    public function generateSeoMeta(Page $page): JsonResponse
    {
        if ($denied = $this->deniedResponse()) {
            return $denied;
        }

        $content = TipTapContent::toMarkdown($page->html_content);

        if (trim($content) === '') {
            return response()->json(['message' => 'لا يوجد محتوى لتوليد الوصف منه — اكتب محتوى الصفحة واحفظه أولاً ثم أعد المحاولة.'], 422);
        }

        return $this->respond(function () use ($page, $content): array {
            $meta = $this->copilot->generateSeoMeta(trim($page->title), $content);

            return [
                'title' => $meta['title'],
                'message' => '<p>'.e($meta['description']).'</p>',
            ];
        });
    }

    /**
     * The 403 every endpoint returns while the admin copilot feature is off,
     * or null when it is enabled.
     */
    private function deniedResponse(): ?JsonResponse
    {
        if ($this->settings->isFeatureEnabled('admin_copilot')) {
            return null;
        }

        return response()->json(['message' => (new CopilotDisabledException)->getMessage()], 403);
    }

    /**
     * Run one generation and map failures the way the admin used to see
     * them: a disabled copilot is a 403 with the operator-facing message,
     * anything else is reported and returned as a 422 the UI toasts verbatim.
     *
     * @param  callable(): array<string, mixed>  $generate
     */
    private function respond(callable $generate): JsonResponse
    {
        try {
            return response()->json($generate());
        } catch (CopilotDisabledException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
