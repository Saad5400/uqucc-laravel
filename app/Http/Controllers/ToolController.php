<?php

namespace App\Http\Controllers;

use App\Http\Requests\TruthTableRequest;
use App\Models\Page;
use App\Services\Logic\FormulaError;
use App\Services\Logic\TruthTableGenerator;
use App\Support\Seo;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class ToolController extends Controller
{
    /**
     * Build SEO metadata for a tool page, preferring the backing page record.
     *
     * @return array<string, mixed>
     */
    private function toolSeo(?Page $page, string $fallbackTitle, string $fallbackDescription): array
    {
        if ($page) {
            return Seo::forPage($page);
        }

        return Seo::forDefault($fallbackTitle, $fallbackDescription);
    }

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
            'seo' => $this->toolSeo($page, 'حاسبة المعدل', 'احسب معدلك التراكمي والفصلي بسهولة عبر حاسبة المعدل لطلاب كلية الحاسبات بجامعة أم القرى.'),
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
            'seo' => $this->toolSeo($page, 'حاسبة الحرمان', 'احسب نسبة غيابك ونقاط الحرمان المتبقية لكل مقرر دراسي عبر حاسبة الحرمان لطلاب كلية الحاسبات.'),
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
            'seo' => $this->toolSeo($page, 'حاسبة التحويل', 'احسب معدلك بعد التحويل بين التخصصات أو الجامعات عبر حاسبة التحويل لطلاب كلية الحاسبات.'),
        ]);
    }

    /**
     * Display the truth table generator tool.
     */
    public function truthTable(): Response
    {
        $page = Page::where('slug', '/adwat/jdwal-alsawab')
            ->where('hidden', false)
            ->first();

        return Inertia::render('tools/TruthTablePage', [
            'page' => $page ? [
                'html_content' => $page->html_content,
                'title' => $page->title,
            ] : null,
            'hasContent' => $page && ! empty($page->html_content),
            'seo' => $this->toolSeo($page, 'جدول الصواب', 'أنشئ جدول الصواب لأي صيغة منطقية بأي صيغة كتابة (∧ ∨ ¬ → ↔ أو and/or/not) عبر مولد جدول الصواب لطلاب كلية الحاسبات.'),
        ]);
    }

    /**
     * Generate a truth table for the submitted formula (JSON endpoint used
     * by the truth table tool page).
     */
    public function generateTruthTable(TruthTableRequest $request, TruthTableGenerator $generator): JsonResponse
    {
        try {
            return response()->json($generator->generate($request->validated('formula'))->toArray());
        } catch (FormulaError $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }
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
            'seo' => $this->toolSeo($page, 'حاسبة المكافأة القادمة', 'اعرف موعد ومقدار مكافأتك الجامعية القادمة عبر حاسبة المكافأة لطلاب كلية الحاسبات.'),
        ]);
    }
}
