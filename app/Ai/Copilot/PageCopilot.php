<?php

namespace App\Ai\Copilot;

use App\Settings\AiSettings;
use RuntimeException;

/**
 * The admin copilot for CMS pages: three helpers, each a SINGLE tool-less
 * text generation through {@see PageCopilotAgent}, in and out of markdown
 * (the manage-panel copilot endpoints convert to/from the editor's TipTap
 * JSON via {@see TipTapContent}).
 *
 * Gated on the operator-editable admin_copilot feature flag (which honours
 * the master AI kill switch) — a disabled copilot throws
 * {@see CopilotDisabledException} rather than silently calling out. The
 * model comes from AiSettings->chat_model with a config fallback, mirroring
 * {@see \App\Ai\Corpus\DocumentVisionExtractor}.
 */
class PageCopilot
{
    private const IMPROVE_INSTRUCTIONS = <<<'PROMPT'
        أنت محرر محتوى عربي محترف لموقع «دليل طالب كلية الحاسبات» بجامعة أم القرى.
        مهمتك تحسين نص صفحة معطى بصيغة ماركداون: صياغة أوضح وأسلس، تصحيح الأخطاء الإملائية والنحوية، وتحسين البنية (عناوين وقوائم) دون تغيير المعنى.

        القواعد:
        - حافظ على لغة النص الأصلية (العربية تبقى عربية والإنجليزية تبقى إنجليزية) وعلى المصطلحات التقنية والأرقام والروابط كما هي.
        - لا تضف معلومات جديدة ولا تحذف تفاصيل موجودة.
        - حافظ على بنية العناوين (##، ###) وحسّنها عند الحاجة فقط.
        - أعد النص كاملاً بصيغة ماركداون فقط — بدون مقدمات أو تعليقات أو أسوار أكواد حول الناتج.
        PROMPT;

    private const DRAFT_SECTION_INSTRUCTIONS = <<<'PROMPT'
        أنت كاتب محتوى عربي محترف لموقع «دليل طالب كلية الحاسبات» بجامعة أم القرى — دليل إرشادي لطلاب الكلية.
        مهمتك كتابة قسم واحد جديد لصفحة موجودة، انطلاقاً من موضوع يحدده المحرر وسياق الصفحة الحالي.

        القواعد:
        - اكتب بالعربية الفصحى المبسطة وبنبرة الدليل الإرشادية الموجودة في السياق، وأبقِ المصطلحات التقنية الإنجليزية كما تُكتب عادة.
        - ابدأ القسم بعنوان ماركداون من المستوى الثاني (##) يلخص الموضوع.
        - اجعل القسم موجزاً ومنظماً: فقرات قصيرة وقوائم نقطية عند الحاجة.
        - لا تكرر محتوى موجوداً في سياق الصفحة، ولا تختلق أرقاماً أو أنظمة أو روابط غير واردة فيه.
        - أعد القسم بصيغة ماركداون فقط — بدون مقدمات أو تعليقات أو أسوار أكواد حول الناتج.
        PROMPT;

    private const SEO_META_INSTRUCTIONS = <<<'PROMPT'
        أنت خبير SEO عربي لموقع «دليل طالب كلية الحاسبات» بجامعة أم القرى.
        مهمتك توليد عنوان ووصف تعريفي (meta) لصفحة انطلاقاً من عنوانها ومحتواها.

        القواعد:
        - العنوان: بالعربية، موجز وجذاب، لا يتجاوز 60 حرفاً، بدون اسم الموقع.
        - الوصف: بالعربية، جملة أو جملتان تلخصان قيمة الصفحة للطالب، بين 100 و155 حرفاً، بدون حشو ولا تكرار للعنوان حرفياً.
        - أعد الناتج بصيغة JSON فقط بهذا الشكل بالضبط: {"title": "...", "description": "..."}
        - بدون أي نص آخر وبدون أسوار أكواد حول الناتج.
        PROMPT;

    public function __construct(private readonly AiSettings $settings) {}

    /**
     * Rewrite page markdown for clarity/correctness, optionally steered by an
     * editor instruction. Returns the improved markdown.
     */
    public function improveText(string $markdown, string $instruction = ''): string
    {
        $this->ensureEnabled();

        $prompt = 'حسّن نص الصفحة التالي وأعده كاملاً بصيغة ماركداون:'."\n\n".trim($markdown);

        if (trim($instruction) !== '') {
            $prompt .= "\n\n".'تعليمات إضافية من المحرر: '.trim($instruction);
        }

        return $this->generate(self::IMPROVE_INSTRUCTIONS, $prompt);
    }

    /**
     * Draft a brand-new markdown section (## heading + body) about a topic,
     * grounded in the page's current content as context.
     */
    public function draftSection(string $topic, string $context = ''): string
    {
        $this->ensureEnabled();

        $prompt = 'اكتب قسماً جديداً عن الموضوع التالي: '.trim($topic);

        if (trim($context) !== '') {
            $prompt .= "\n\n".'سياق الصفحة الحالي:'."\n\n".trim($context);
        }

        return $this->generate(self::DRAFT_SECTION_INSTRUCTIONS, $prompt);
    }

    /**
     * Generate an SEO meta title + description for a page.
     *
     * @return array{title: string, description: string}
     */
    public function generateSeoMeta(string $title, string $content): array
    {
        $this->ensureEnabled();

        $prompt = 'ولّد عنوان ووصف SEO للصفحة التالية.'."\n\n"
            .'عنوان الصفحة: '.trim($title)."\n\n"
            .'محتوى الصفحة:'."\n\n".trim($content);

        return $this->decodeSeoMeta($this->generate(self::SEO_META_INSTRUCTIONS, $prompt));
    }

    /**
     * Guard every helper: feature flag first (domain exception the UI can
     * show verbatim), then the provider key so a call never silently fails
     * mid-request.
     */
    private function ensureEnabled(): void
    {
        if (! $this->settings->isFeatureEnabled('admin_copilot')) {
            throw new CopilotDisabledException;
        }

        if ((string) config('ai.providers.openrouter.key', '') === '') {
            throw new RuntimeException('مفتاح OpenRouter غير مضبوط — لا يمكن استخدام مساعد الكتابة.');
        }
    }

    /**
     * The single text-generation call all helpers share.
     */
    private function generate(string $instructions, string $prompt): string
    {
        $response = (new PageCopilotAgent($instructions))->prompt(
            $prompt,
            provider: (string) config('ai.default', 'openrouter'),
            model: $this->model(),
            timeout: (int) config('ai.chat.timeout', 60),
        );

        return trim((string) $response->text);
    }

    /**
     * Parse the model's JSON reply for generateSeoMeta, tolerating a stray
     * markdown code fence but nothing else.
     *
     * @return array{title: string, description: string}
     */
    private function decodeSeoMeta(string $raw): array
    {
        $json = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw));

        $decoded = json_decode($json, true);

        $title = is_array($decoded) ? trim((string) ($decoded['title'] ?? '')) : '';
        $description = is_array($decoded) ? trim((string) ($decoded['description'] ?? '')) : '';

        if ($title === '' || $description === '') {
            throw new RuntimeException('أعاد النموذج ناتجاً غير صالح لوصف SEO — حاول مرة أخرى.');
        }

        return ['title' => $title, 'description' => $description];
    }

    /**
     * The operator-configured chat model, falling back to config.
     */
    private function model(): string
    {
        $model = trim($this->settings->chat_model);

        return $model !== '' ? $model : (string) config('ai.chat.model', 'google/gemini-3.5-flash');
    }
}
