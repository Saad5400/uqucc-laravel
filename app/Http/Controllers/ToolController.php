<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Inertia\Inertia;
use Inertia\Response;

class ToolController extends Controller
{
    /**
     * Display the GPA calculator tool.
     */
    public function gpaCalculator(): Response
    {
        $page = Page::where('slug', '/adwat/hasbh-almadl')
            ->where('hidden', false)
            ->first();

        return Inertia::render('tools/GpaCalculatorPage', [
            'page' => $page ? [
                'html_content' => $page->html_content,
                'title' => $page->title,
            ] : null,
            'hasContent' => $page && ! empty($page->html_content),
        ]);
    }

    /**
     * Display the deprivation calculator tool.
     */
    public function deprivationCalculator(): Response
    {
        $page = Page::where('slug', '/adwat/hasbh-alhrman')
            ->where('hidden', false)
            ->first();

        return Inertia::render('tools/DeprivationCalculatorPage', [
            'page' => $page ? [
                'html_content' => $page->html_content,
                'title' => $page->title,
            ] : null,
            'hasContent' => $page && ! empty($page->html_content),
        ]);
    }

    /**
     * Display the transfer calculator tool.
     */
    public function transferCalculator(): Response
    {
        $page = Page::where('slug', '/adwat/hasbh-altahwel')
            ->where('hidden', false)
            ->first();

        return Inertia::render('tools/TransferCalculatorPage', [
            'page' => $page ? [
                'html_content' => $page->html_content,
                'title' => $page->title,
            ] : null,
            'hasContent' => $page && ! empty($page->html_content),
        ]);
    }

    /**
     * Display the next reward tool.
     */
    public function nextReward(): Response
    {
        $page = Page::where('slug', '/adwat/almkafa')
            ->where('hidden', false)
            ->first();

        return Inertia::render('tools/NextRewardPage', [
            'page' => $page ? [
                'html_content' => $page->html_content,
                'title' => $page->title,
            ] : null,
            'hasContent' => $page && ! empty($page->html_content),
        ]);
    }
}
