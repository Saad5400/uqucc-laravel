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
 * chars, options 100, explanation 200) plus the optional `body` (700 chars —
 * the code/scenario posted as its own formatted message above the poll), so
 * those limits are part of the contract here: a response that breaks them is
 * rejected and retried once.
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

    /**
     * The optional scenario/code block posted as its own formatted message
     * above the poll. It is a normal Telegram message (not a poll field), so
     * the cap is generous — enough for a short code snippet plus framing,
     * well under Telegram's 4096-char message limit.
     */
    public const MAX_BODY_CHARS = 700;

    /**
     * A short teaser used in the "answer today's question" reminders — the
     * same cap for both the subtle mid-window hint and the blunter one that
     * goes out with the last call.
     */
    public const MAX_HINT_CHARS = 120;

    /** How many structured-generation attempts before giving up for the day. */
    private const MAX_ATTEMPTS = 2;

    /** How many recent questions the prompt lists as "do not repeat". */
    private const RECENT_QUESTIONS = 15;

    private const INSTRUCTIONS = <<<'PROMPT'
        أنت مؤلف «سؤال اليوم» في مجموعة تيليجرام لطلاب كلية الحاسبات بجامعة أم القرى.
        الجمهور مختلط: سبعة تخصصات وأربع سنوات دراسية، والهدف طقس يومي ممتع — لا اختبار رسمي.
        مهمتك تأليف سؤال اختيار من متعدد واحد فقط عن الموضوع المعطى.

        مستوى الصعوبة — الأهم على الإطلاق:
        - الهدف «طقس يومي خفيف وممتع» لا اختبار: اجعله سؤالاً سهلاً يحله أغلب المشاركين بخطوة تفكير واحدة أثناء قراءته. النجاح أن يجيب معظم الناس إجابةً صحيحة ويبتسموا، لا أن يسقط أغلبهم.
        - المستوى المستهدف بالضبط، احتذِ به: «اشتراك إنترنت 100 ميغابت/ثانية ⇐ كم ميغابايت في الثانية؟ (نقسم على 8 = 12.5)» — خطوة واحدة، معلومة يعرفها طالب السنة الأولى، تجيب عليها الأغلبية.
        - نوع السؤال: تطبيق مفهوم مشهور على موقف يومي، أو حساب ذهني بسيط (تحويل وحدات مثلاً)، أو توقّع ناتج كود من سطر أو سطرين واضح جداً، أو «أيها المختلف عن البقية». تفكير خفيف لا حفظ.

        ممنوع تماماً (كل ما يلي أصعب من هدفنا بكثير):
        - الحفظ الجاف: «أي قانون/عالم/سنة/اختصار يعني...» والتعاريف المنسوخة — ممل ولن يتفاعل معه أحد.
        - أسئلة التصنيف والتعريف: «أيّها مثال على X؟»، «أيّها ليس Y؟»، «أيّ التالي يُعد Z؟» — مملة ومبنية على استرجاع تصنيف محفوظ، ولا تنقذها مقدمة. تجنّبها تماماً واطرح بدلاً منها سؤالاً يتطلب تفكيراً فعلياً: توقّع ناتج كود، احسب قيمة، اكتشف خطأً، أو طبّق المفهوم على موقف ملموس جديد.
        - الألغاز الخادعة وحالات الحافة الدقيقة التي تُسقط حتى المحترف: تفاصيل منطقة التحضير في Git، سلوك TTL/ICMP الدقيق، أي سلوك يعتمد على تفاصيل تنفيذ أو إصدار.
        - النظريات والمصطلحات التخصصية المتقدمة: قوانين التصميم (فيتس، نمط F)، «العبء المعرفي»، وأي مصطلح لا يعرفه إلا طالب سنوات متقدمة أو متخصص.
        - القاعدة الحاسمة: إن احتاجت الإجابة إلى معرفة متقدمة أو نادرة أو حيلة ذكية، فالسؤال صعب — بسّطه. أما المفهوم التمهيدي الذي يمكن تعريفه بسطر بسيط فاطرحه بشرط أن تُمهّد له في body (انظر قسم الكود أو المقدمة أدناه). إن شككت أنه صعب، فهو صعب.

        الشكل:
        - سؤال واحد واضح له إجابة صحيحة واحدة لا لبس فيها، وثلاثة بدائل خاطئة معقولة (ليست هزلية ولا واضحة الخطأ)، لكنها ليست فخاخاً دقيقة تُصمَّم لإسقاط المنتبه.

        الكود أو المقدمة (حقل body):
        - body رسالة منسّقة تُنشر فوق التصويت مباشرة، ولها استخدامان: (أ) كود أو سيناريو يعتمد عليه السؤال، أو (ب) مقدمة تعليمية قصيرة تُمهّد لمفهوم قد لا يعرفه الجميع. لا تضع أياً منهما داخل نص السؤال.
        - الكود/السيناريو: ضع أي كود داخل سياج ماركداون بلغته، هكذا: ‏```py … ``` أو ‏```java … ``` — ليظهر بخط ثابت واتجاه سليم. اجعله من سطر إلى أربعة أسطر كحد أقصى، ومفهوماً لدارسي جافا وبايثون معاً ما أمكن.
        - المقدمة التعليمية: إذا اعتمد سؤال تطبيقي على مفهوم قد لا يعرفه بعض الطلاب، عرّف الفكرة عموماً في body بسطر أو سطرين بلغة بسيطة وودّية، ثم اطرح سؤالاً يطبّقها على موقف ملموس جديد.
        - حاسم — ممنوع كشف الإجابة في المقدمة: المقدمة تشرح المفهوم عموماً فقط، ولا تذكر أياً من الخيارات بالاسم، ولا تُصنّف أياً منها، ولا تُلمّح لأي خيار هو الصحيح. إن أمكن للقارئ نسخ الإجابة من المقدمة فقد أفسدتها. مثال ممنوع وقع فعلاً: مقدمة تقول «الشجرة والرسم غير خطيين» ثم السؤال «أيّها ليس خطياً؟» والشجرة خيار — هذا كشف صريح وسؤال تصنيف ممل معاً. المقدمة تُمهّد لتطبيق يفكّر فيه الطالب، لا لاسترجاع ما كُتب فيها.
        - عند وجود body اجعل حقل question جملة استفهامية قصيرة مستقلة تُقرأ وحدها (مثل «ماذا يُطبع؟» أو «أيّها مثال على متطلب غير وظيفي؟») — لأن التصويت لا يعرض نص body.
        - إن لم يحتَج السؤال إلى كود ولا مقدمة، اترك body سلسلة فارغة "" واكتب السؤال كاملاً في question.

        اللغة:
        - اكتب بالعربية الفصحى المبسطة كما تُشرح المواد في القاعات: المصطلح بالعربية وبجانبه المصطلح الإنجليزي بين قوسين، مثل «المكدس (Stack)» و«الاستدعاء الذاتي (Recursion)».
        - أبقِ أسماء الدوال والأوامر والأكواد بالإنجليزية كما هي.
        - لا تعتمد على معلومات قد تتغير مع الزمن (إصدارات حديثة، أسعار، أشخاص).

        التلميحان (hint و obvious_hint):
        - التلميح الأول (hint): جملة قصيرة واحدة تُوجّه التفكير نحو الإجابة دون كشفها إطلاقاً — تُرسل في منتصف يوم السؤال، فاجعلها مشوّقة لا فاضحة.
          مثال جيد: «فكّر في وحدات القياس والفرق بينها» — لا تذكر الإجابة الصحيحة ولا رقم الخيار.
        - التلميح الثاني (obvious_hint): تلميح أوضح بكثير يُرسل قبل إغلاق السؤال مباشرة — يقرّب القارئ من الإجابة خطوة واحدة فقط: يسمّي العملية أو القاعدة المطلوبة صراحةً، دون كتابة الإجابة حرفياً ولا رقم الخيار.
          مثال جيد لسؤال «100 ميغابت/ثانية كم ميغابايت؟»: «العلاقة قسمة على 8».
        - اجعل التلميحين مختلفين فعلاً: الأول يوجّه، والثاني يكاد يكشف.

        الحدود الصارمة:
        - السؤال 300 حرف كحد أقصى، حقل body 700 حرف كحد أقصى، كل خيار 100 حرف كحد أقصى، الشرح 200 حرف كحد أقصى، وكل تلميح 120 حرف كحد أقصى.
        - الشرح جملة أو جملتان تشرحان لماذا الإجابة صحيحة — يظهر للطالب بعد إجابته.
        - أعد الناتج بصيغة JSON فقط بهذا الشكل بالضبط، بدون أي نص آخر وبدون أسوار أكواد:
          {"question": "...", "body": "...", "options": ["...", "...", "...", "..."], "correct_option": 0, "explanation": "...", "hint": "...", "obvious_hint": "..."}
        - body اختياري: اتركه "" عند عدم الحاجة لكود أو مقدمة.
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
     *
     * Pass an explicit `$topic` to force that theme; otherwise the topic is
     * picked automatically (least-recently-used, spotlight-aware). When a
     * `ready` question already exists for the day, `$replace` regenerates it —
     * the old one is dropped only after the new question is authored, so a
     * generation failure leaves the existing question untouched.
     */
    public function generateForDate(CarbonInterface $date, ?QuizTopic $topic = null, bool $replace = false): DailyQuiz
    {
        if (($reason = $this->disabledReason()) !== null) {
            throw new RuntimeException($reason);
        }

        if (! $this->ledger->hasBudgetRemaining()) {
            throw new RuntimeException($this->ledger->budgetExhaustedMessage());
        }

        $existing = DailyQuiz::forDate($date);

        if ($existing !== null) {
            if (! $replace) {
                throw new RuntimeException('يوجد سؤال لهذا اليوم بالفعل.');
            }

            if (! $existing->isReady()) {
                throw new RuntimeException('لا يمكن إعادة توليد سؤال بعد نشره.');
            }
        }

        $topic ??= QuizTopic::pickForDate($date);

        if ($topic === null) {
            throw new RuntimeException('لا توجد مواضيع مفعّلة — أضف مواضيع من صفحة سؤال اليوم أولاً.');
        }

        $decoded = $this->generateQuestion($topic);

        $existing?->delete();

        $quiz = DailyQuiz::create([
            'quiz_topic_id' => $topic->id,
            'quiz_date' => $date,
            'question' => $decoded['question'],
            'body' => $decoded['body'],
            'options' => $decoded['options'],
            'correct_option' => $decoded['correct_option'],
            'explanation' => $decoded['explanation'],
            'hint' => $decoded['hint'],
            'obvious_hint' => $decoded['obvious_hint'],
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
     * @return array{question: string, body: string|null, options: array<int, string>, correct_option: int, explanation: string|null, hint: string|null, obvious_hint: string|null}
     */
    private function decodeQuestion(string $raw): array
    {
        $json = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw));

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('أعاد النموذج ناتجاً ليس JSON صالحاً.');
        }

        $question = trim((string) ($decoded['question'] ?? ''));
        $body = trim((string) ($decoded['body'] ?? ''));
        $options = $decoded['options'] ?? null;
        $correct = $decoded['correct_option'] ?? null;
        $explanation = trim((string) ($decoded['explanation'] ?? ''));
        $hint = trim((string) ($decoded['hint'] ?? ''));
        $obviousHint = trim((string) ($decoded['obvious_hint'] ?? ''));

        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION_CHARS) {
            throw new RuntimeException('السؤال فارغ أو أطول من حد تيليجرام (300 حرف).');
        }

        if (mb_strlen($body) > self::MAX_BODY_CHARS) {
            throw new RuntimeException('محتوى السؤال (body) أطول من الحد ('.self::MAX_BODY_CHARS.' حرف).');
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
            'body' => $body === '' ? null : $body,
            'options' => $options,
            'correct_option' => (int) $correct,
            'explanation' => $explanation === '' ? null : Str::limit($explanation, self::MAX_EXPLANATION_CHARS, ''),
            'hint' => $hint === '' ? null : Str::limit($hint, self::MAX_HINT_CHARS, ''),
            'obvious_hint' => $obviousHint === '' ? null : Str::limit($obviousHint, self::MAX_HINT_CHARS, ''),
        ];
    }
}
