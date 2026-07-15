<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Support\Seo;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /almosaed (name: assistant) — the student assistant chat page. The
 * page always renders, even while the assistant feature is toggled off:
 * the chat endpoints answer 503 with an Arabic message and the client shows
 * the disabled state (same lazy discovery the AI search palette uses), so
 * the cached page response never bakes in a stale feature flag.
 */
class AssistantPageController extends Controller
{
    public function __invoke(): Response
    {
        $page = Page::where('slug', '/almosaed')
            ->where('hidden', false)
            ->first();

        return Inertia::render('AssistantPage', [
            'page' => $page ? [
                'html_content' => $page->html_content,
                'title' => $page->title,
            ] : null,
            'hasContent' => $page && ! empty($page->html_content),
            'disclaimer' => (string) config('ai.assistant.disclaimer'),
            'seo' => $page
                ? Seo::forPage($page)
                : Seo::forDefault('المساعد الذكي', 'اسأل المساعد الذكي عن كل ما يخص كلية الحاسبات بجامعة أم القرى — اللوائح، التخصصات، الحرمان، والمعدل — بإجابات موثقة من الدليل.'),
        ]);
    }
}
