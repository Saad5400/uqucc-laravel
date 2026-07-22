<?php

namespace App\Ai\Quiz;

use App\Ai\Spend\SpendLedger;
use App\Models\DailyQuiz;
use App\Models\QuizTopic;
use App\Settings\AiSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Generates the daily multiple-choice question: one structured authoring-tier
 * call that turns an admin-curated {@see QuizTopic} into a `ready`
 * {@see DailyQuiz} row admins may still edit before it is posted.
 *
 * The output must survive Telegram's quiz-poll limits verbatim (question 300
 * chars, options 100, explanation 200), so those limits are part of the
 * contract here: a response that breaks them is rejected and retried once.
 * Gated like every paid feature: the AI master switch, the OpenRouter key,
 * and the daily spend budget; each call's cost lands on the ledger under the
 * `quiz` feature.
 */
class QuizAuthor
{
    /** Spend-ledger feature key for quiz generations. */
    public const FEATURE = 'quiz';

    /** Telegram sendPoll hard limits the generated content must fit. */
    public const MAX_QUESTION_CHARS = 300;

    public const MAX_OPTION_CHARS = 100;

    public const MAX_EXPLANATION_CHARS = 200;

    /** How many structured-generation attempts before giving up for the day. */
    private const MAX_ATTEMPTS = 2;

    /** How many recent questions the prompt lists as "do not repeat". */
    private const RECENT_QUESTIONS = 15;

    private const INSTRUCTIONS = <<<'PROMPT'
        أنت مؤلف «سؤال اليوم» في مجموعة تيليجرام لطلاب كلية الحاسبات بجامعة أم القرى.
        الجمهور مختلط: سبعة تخصصات وأربع سنوات دراسية، والهدف طقس يومي ممتع — لا اختبار رسمي.
        مهمتك تأليف سؤال اختيار من متعدد واحد فقط عن الموضوع المعطى.

        أسلوب السؤال — الأهم:
        - اجعله سؤالاً يُشغّل التفكير لا الذاكرة: توقّع ناتج كود قصير جداً، اكتشف الخطأ، لغز ثنائي أو منطقي صغير، تطبيق مفهوم على موقف عملي يومي، أو «أيها المختلف عن البقية».
        - ممنوع تماماً أسئلة الحفظ الجاف: «أي قانون/عالم/سنة/اختصار يعني...» والتعاريف المنسوخة — هذا النوع ممل ولن يتفاعل معه أحد.
        - المستوى: يستطيع طالب السنة الأولى أو الثانية الوصول للإجابة بالتفكير أثناء قراءة السؤال، ويستمتع به طالب السنة الرابعة. لا تشترط مادة متقدمة.
        - إذا استخدمت كوداً فاجعله من سطر إلى أربعة أسطر كحد أقصى، بأسلوب مفهوم لدارسي جافا وبايثون معاً ما أمكن.
        - سؤال واحد واضح له إجابة صحيحة واحدة لا لبس فيها، وثلاثة بدائل خاطئة معقولة يقع فيها المتسرع (ليست هزلية ولا واضحة الخطأ).

        اللغة:
        - اكتب بالعربية الفصحى المبسطة كما تُشرح المواد في القاعات: المصطلح بالعربية وبجانبه المصطلح الإنجليزي بين قوسين، مثل «المكدس (Stack)» و«الاستدعاء الذاتي (Recursion)».
        - أبقِ أسماء الدوال والأوامر والأكواد بالإنجليزية كما هي.
        - لا تعتمد على معلومات قد تتغير مع الزمن (إصدارات حديثة، أسعار، أشخاص).

        الحدود الصارمة:
        - السؤال 300 حرف كحد أقصى، كل خيار 100 حرف كحد أقصى، الشرح 200 حرف كحد أقصى.
        - الشرح جملة أو جملتان تشرحان لماذا الإجابة صحيحة — يظهر للطالب بعد إجابته.
        - أعد الناتج بصيغة JSON فقط بهذا الشكل بالضبط، بدون أي نص آخر وبدون أسوار أكواد:
          {"question": "...", "options": ["...", "...", "...", "..."], "correct_option": 0, "explanation": "..."}
        - correct_option هو ترتيب الإجابة الصحيحة في المصفوفة (من 0 إلى 3)، ونوّع موضعها.
        PROMPT;

    public function __construct(
        private readonly AiSettings $settings,
        private readonly SpendLedger $ledger,
    ) {}

    /**
     * Why generation is unavailable, for disabled-with-reason UX — null while
     * it can run.
     */
    public function disabledReason(): ?string
    {
        if (! $this->settings->ai_enabled) {
            return 'الذكاء الاصطناعي معطل بالكامل. فعّل «تفعيل الذكاء الاصطناعي» من صفحة الإعدادات أولاً.';
        }

        if ((string) config('ai.providers.openrouter.key', '') === '') {
            return 'مفتاح OpenRouter غير مضبوط — لا يمكن توليد سؤال اليوم.';
        }

        return null;
    }

    /**
     * Generate the quiz for the given day and store it as `ready`. Throws
     * with an operator-facing Arabic message on any refusal.
     */
    public function generateForDate(CarbonInterface $date): DailyQuiz
    {
        if (($reason = $this->disabledReason()) !== null) {
            throw new RuntimeException($reason);
        }

        if (! $this->ledger->hasBudgetRemaining()) {
            throw new RuntimeException($this->ledger->budgetExhaustedMessage());
        }

        if (DailyQuiz::forDate($date) !== null) {
            throw new RuntimeException('يوجد سؤال لهذا اليوم بالفعل.');
        }

        $topic = QuizTopic::pickForDate($date);

        if ($topic === null) {
            throw new RuntimeException('لا توجد مواضيع مفعّلة — أضف مواضيع من صفحة سؤال اليوم أولاً.');
        }

        $decoded = $this->generateQuestion($topic);

        $quiz = DailyQuiz::create([
            'quiz_topic_id' => $topic->id,
            'quiz_date' => $date,
            'question' => $decoded['question'],
            'options' => $decoded['options'],
            'correct_option' => $decoded['correct_option'],
            'explanation' => $decoded['explanation'],
            'status' => DailyQuiz::STATUS_READY,
        ]);

        $topic->update(['last_used_at' => now()]);

        return $quiz;
    }

    /**
     * Run the structured generation, retrying once on an invalid response.
     *
     * @return array{question: string, options: array<int, string>, correct_option: int, explanation: string|null}
     */
    private function generateQuestion(QuizTopic $topic): array
    {
        $prompt = $this->buildPrompt($topic);
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $this->decodeQuestion($this->generate($prompt));
            } catch (RuntimeException $exception) {
                $lastError = $exception;
            }
        }

        throw new RuntimeException('تعذّر توليد سؤال صالح: '.$lastError?->getMessage());
    }

    private function buildPrompt(QuizTopic $topic): string
    {
        $prompt = 'موضوع اليوم: '.trim($topic->name);

        if (filled($topic->prompt_hint)) {
            $prompt .= "\n".'توجيهات المشرفين عن الموضوع: '.trim((string) $topic->prompt_hint);
        }

        if ($topic->is_spotlight) {
            $prompt .= "\n".'هذا موضوع «يوم التخصص» الأسبوعي: خذ فكرة من هذا التخصص لكن قدّمها بطريقة يفهمها ويستمتع بها غير المتخصص وطالب السنة الأولى — عرّف الجمهور بجمال هذا المجال بدل التعمق في مقرراته.';
        }

        $recent = DailyQuiz::query()
            ->latest('quiz_date')
            ->limit(self::RECENT_QUESTIONS)
            ->pluck('question')
            ->filter()
            ->values();

        if ($recent->isNotEmpty()) {
            $prompt .= "\n\n".'أسئلة الأيام الماضية — لا تكرر أياً منها ولا فكرتها:'."\n"
                .$recent->map(fn (string $question): string => '- '.$question)->implode("\n");
        }

        return $prompt;
    }

    /**
     * One authoring-tier generation with its exact provider cost recorded on
     * the spend ledger under the `quiz` feature.
     */
    private function generate(string $prompt): string
    {
        $this->ledger->clearContextCosts();

        try {
            $response = (new QuizAuthoringAgent(self::INSTRUCTIONS))->prompt($prompt);
        } finally {
            $this->recordSpend($response ?? null);
        }

        return trim((string) $response->text);
    }

    private function recordSpend(?\Laravel\Ai\Responses\AgentResponse $response): void
    {
        try {
            $this->ledger->record(
                self::FEATURE,
                (string) config('ai.authoring.model', 'deepseek/deepseek-v4-pro'),
                $response?->usage,
                $this->ledger->captureContextCosts(),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Parse and validate the question JSON against Telegram's poll limits,
     * tolerating a stray markdown code fence but nothing else.
     *
     * @return array{question: string, options: array<int, string>, correct_option: int, explanation: string|null}
     */
    private function decodeQuestion(string $raw): array
    {
        $json = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw));

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('أعاد النموذج ناتجاً ليس JSON صالحاً.');
        }

        $question = trim((string) ($decoded['question'] ?? ''));
        $options = $decoded['options'] ?? null;
        $correct = $decoded['correct_option'] ?? null;
        $explanation = trim((string) ($decoded['explanation'] ?? ''));

        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION_CHARS) {
            throw new RuntimeException('السؤال فارغ أو أطول من حد تيليجرام (300 حرف).');
        }

        if (! is_array($options) || count($options) !== 4) {
            throw new RuntimeException('الخيارات يجب أن تكون أربعة بالضبط.');
        }

        $options = array_values(array_map(fn (mixed $option): string => trim((string) $option), $options));

        foreach ($options as $option) {
            if ($option === '' || mb_strlen($option) > self::MAX_OPTION_CHARS) {
                throw new RuntimeException('أحد الخيارات فارغ أو أطول من حد تيليجرام (100 حرف).');
            }
        }

        if (count(array_unique($options)) !== 4) {
            throw new RuntimeException('الخيارات متكررة.');
        }

        if (! is_numeric($correct) || (int) $correct < 0 || (int) $correct > 3) {
            throw new RuntimeException('ترتيب الإجابة الصحيحة يجب أن يكون بين 0 و3.');
        }

        return [
            'question' => $question,
            'options' => $options,
            'correct_option' => (int) $correct,
            'explanation' => $explanation === '' ? null : Str::limit($explanation, self::MAX_EXPLANATION_CHARS, ''),
        ];
    }
}
