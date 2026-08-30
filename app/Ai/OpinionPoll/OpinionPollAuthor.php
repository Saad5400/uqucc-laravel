<?php

namespace App\Ai\OpinionPoll;

use App\Models\OpinionPoll;
use App\Settings\AiSettings;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Exceptions\AiException;
use RuntimeException;
use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Safety\Exceptions\AiUnavailableException;

/**
 * Generates the day's opinion poll: one authoring-tier agent call that turns a
 * rotating {@see OpinionPollTheme} into a `ready` {@see OpinionPoll} an admin
 * may still edit before it goes out.
 *
 * The hard part is not writing the question — it is not writing a quiz. The
 * daily question already asks the group to be right; this ritual exists
 * because ten thousand members will not risk being wrong, so a poll with a
 * defensible answer silently undoes the whole feature. That single rule is
 * what most of the prompt below defends, and it is the one thing the tool
 * cannot check mechanically.
 *
 * Gated like every paid feature: the AI master switch, the OpenRouter key, and
 * the daily spend budget; each call's cost is metered by ai-kit under the
 * `opinion_poll` feature.
 */
class OpinionPollAuthor
{
    /** Usage feature label for poll generations (ai-kit usage module). */
    public const FEATURE = 'opinion_poll';

    /** Telegram's own cap on a poll question. */
    public const MAX_QUESTION_CHARS = 300;

    /**
     * Deliberately below Telegram's 100-character option cap: a poll is
     * answered in the two seconds it takes to read the options, and an option
     * that needs a second line is a question the reader scrolls past. Hand-
     * written polls keep the full Telegram allowance — this is a quality rule
     * for the model, not a protocol limit.
     */
    public const MAX_OPTION_CHARS = 60;

    /**
     * Three to five real choices. Two is usually a false binary, and past five
     * the votes spread so thin that the result says nothing.
     */
    public const MIN_OPTIONS = 3;

    public const MAX_OPTIONS = 5;

    /** How many structured-generation attempts before giving up for the day. */
    private const MAX_ATTEMPTS = 2;

    /**
     * How many past polls — whatever their theme — the prompt lists as "do not
     * repeat". Long enough to cover more than a full rotation of the eight
     * themes, so the model sees the previous turn of the theme it is writing
     * for now.
     */
    private const RECENT_POLLS = 20;

    private const INSTRUCTIONS = <<<'PROMPT'
        أنت مؤلف «استطلاع الرأي» اليومي في مجموعة تيليجرام لطلاب كلية الحاسبات بجامعة أم القرى.
        المجموعة فيها أكثر من عشرة آلاف عضو، أغلبهم يقرؤون ولا يشاركون.

        لماذا يوجد هذا الاستطلاع أصلاً — اقرأ هذا أولاً وافهمه، فكل ما بعده يخدمه:
        في المجموعة «سؤال اليوم»، وهو اختبار له إجابة صحيحة. من يجيب عليه يخاطر بأن يخطئ أمام عشرة آلاف، وهذه المخاطرة هي سبب صمت الأغلبية. الاستطلاع هو الباب الذي لا يخاطر فيه أحد بشيء: تصويت مجهول عن صاحبه نفسه — عن أداته وعادته وتجربته — لا يمكن أن يكون فيه مخطئ. فإن ألّفت سؤالاً له إجابة صحيحة، تكون قد أعدت بناء سؤال اليوم مرة أخرى وأفسدت الفكرة كلها.

        الاختبار الحاسم قبل أي إرسال:
        اسأل نفسك: «هل يمكن لأحد أن يعلّق تحت الاستطلاع قائلاً: من صوّت للخيار الثاني فهو مخطئ؟» إن كان الجواب نعم — ولو احتمالاً — فالسؤال مرفوض، بدّله كلياً.
        - ممنوع: كل سؤال جوابه حقيقة أو حساب أو تعريف («ما ناتج…؟»، «أي لغة أسرع؟»، «ما اختصار…؟»، «أي خوارزمية أفضل لـ…؟»).
        - ممنوع: كل سؤال فيه خيار «صحيح» ضمناً («كم مرة يجب أن تراجع كودك؟» — فيه إجابة مثالية يعرفها الجميع، ومن يصوّت بغيرها يعترف بتقصيره). السؤال الذي يجعل المصوّت يبدو مقصّراً هو سؤال محرج لا سؤال رأي.
        - المطلوب: سؤال عن الواقع الشخصي («ما المحرر الذي تكتب به فعلاً؟»، «متى تذاكر أفضل؟»، «متى تسلّم الواجب عادةً؟»). كل خيار فيه إجابة يقولها عضو حقيقي عن نفسه بلا خجل.

        الشكل:
        - سؤال واحد قصير بالعربية الفصحى المبسطة، جملة واحدة، ينتهي بعلامة استفهام. خاطب العضو بصيغة المفرد («تكتب»، «تذاكر») لا بصيغة الجمع.
        - من ثلاثة إلى خمسة خيارات. كل خيار كلمة إلى أربع كلمات — انظر إلى هذه الأمثلة الجيدة: «VS Code» / «بعد الفجر» / «أقل من ٤ ساعات» / «في آخر دقيقة». الخيار الطويل لا يُقرأ.
        - غطِّ المساحة الواقعية للإجابات: لا تترك ثلث المجموعة بلا خيار يمثّلهم. وإن بقيت حالات متفرقة فاجعل الخيار الأخير جامعاً مثل «شيء آخر» — خياراً واحداً جامعاً على الأكثر، ولا تجعله خياراً مملوءاً بالتفاصيل.
        - اجعل كل خيار محتملاً فعلاً: لا خيار هزلي ولا خيار يعرف الجميع أن أحداً لن يختاره. إن لم تتخيل عضواً يصوّت لخيار، فاحذفه.
        - في أسئلة الأرقام استعمل نطاقات لا أرقام دقيقة («٤ إلى ٦ ساعات» لا «٥ ساعات»)، ورتّب النطاقات تصاعدياً أو تنازلياً بانتظام.
        - الخيارات نص عادي بلا ترقيم وبلا رموز تنسيق — تيليجرام يرقّمها بنفسه. وأبقِ أسماء الأدوات واللغات بالإنجليزية كما تُكتب.

        ممنوع منعاً باتاً (المجموعة عامة وكبيرة، ورسالة واحدة تكفي لجرح فئة كاملة):
        - الدين والسياسة والقبيلة والمنطقة والجنس والدخل والمظهر.
        - تقييم شخص بعينه أو دكتور أو مقرر أو قسم أو جامعة، أو أي سؤال يُفهم منه ترتيب الناس أو تفضيل فئة على فئة.
        - كل ما يكشف عن المصوّت ما لا يحب أن يُعرف عنه: معدله، رسوبه، حالته المادية، صحته النفسية.
        - وإن خطر لك سؤال «طريف» فيه غمز من فئة — تخصص أو سنة دراسية أو مستخدمي أداة — فاحذفه. الطرافة عندنا في الجدل التقني الخفيف فقط، حيث طرفا الجدل كلاهما مقبول ولا أحد فيه أقل من الآخر.

        قبل الإرسال راجع ثلاثة أشياء بهذا الترتيب: أن لا إجابة صحيحة، وأن لا فئة تُجرح، وأن الخيارات قصيرة وتغطي الناس. ثم أرسل.

        الإرسال:
        - أرسل استطلاعك باستدعاء الأداة submit_opinion_poll فقط — لا تكتب الناتج نصاً عادياً ولا JSON. حقولها: question (نص عادي)، options (مصفوفة نصية).
        - إن أعادت الأداة قائمة مشاكل فعالجها كلها ثم استدعِها مرة أخرى، وكرّر حتى تُقبل. وبمجرد قبولها توقّف ولا تُجرِ تعديلاً إضافياً.
        PROMPT;

    public function __construct(
        private readonly AiSettings $settings,
        private readonly BudgetGuard $budget,
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
            return 'مفتاح OpenRouter غير مضبوط — لا يمكن توليد الاستطلاع.';
        }

        if ($this->budget->exceeded()) {
            return __('ai-kit::safety.budget_exceeded');
        }

        return null;
    }

    /**
     * Generate the poll for the given day and store it as `ready`. Throws with
     * an operator-facing Arabic message on any refusal.
     *
     * Pass an explicit `$theme` to force an angle; otherwise the rotation
     * picks the one that has waited longest ({@see OpinionPoll::nextTheme()}).
     * When a `ready` poll already exists for the day, `$replace` regenerates
     * it — the old one is dropped only after the new poll is authored, so a
     * generation failure leaves the existing poll untouched.
     */
    public function generateForDate(CarbonInterface $date, ?OpinionPollTheme $theme = null, bool $replace = false): OpinionPoll
    {
        if (($reason = $this->disabledReason()) !== null) {
            throw new RuntimeException($reason);
        }

        $existing = OpinionPoll::forDate($date);

        if ($existing !== null) {
            if (! $replace) {
                throw new RuntimeException('يوجد استطلاع لهذا اليوم بالفعل.');
            }

            if (! $existing->isReady()) {
                throw new RuntimeException('لا يمكن إعادة توليد استطلاع بعد نشره.');
            }
        }

        $theme ??= OpinionPoll::nextTheme();

        $authored = $this->authorPoll($theme);

        $existing?->delete();

        return OpinionPoll::create([
            'poll_date' => $date,
            'question' => $authored['question'],
            'options' => $authored['options'],
            'theme' => $theme,
            'status' => OpinionPoll::STATUS_READY,
        ]);
    }

    /**
     * Author one poll, retrying the whole agent run when the model finishes
     * without ever submitting a valid poll through the tool (in-conversation
     * correction handles ordinary validation failures), or when the call never
     * reached a verdict at all.
     *
     * The catch is wider than RuntimeException for the same reason the quiz's
     * is: the authoring tier regularly grazes its HTTP timeout, and an
     * upstream timeout arrives as a ConnectionException — letting it escape
     * would cost the day its poll over one flaky call.
     *
     * @return array{question: string, options: array<int, string>}
     */
    private function authorPoll(OpinionPollTheme $theme): array
    {
        $prompt = $this->buildPrompt($theme);
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $this->generate($prompt);
            } catch (AiUnavailableException $exception) {
                // The turn was refused before it cost anything — the budget is
                // spent or the kit is off. Another attempt refuses identically.
                throw $exception;
            } catch (AiException|ConnectionException|RuntimeException $exception) {
                $lastError = $exception;
            }
        }

        throw new RuntimeException('تعذّر توليد استطلاع صالح: '.$lastError?->getMessage());
    }

    private function buildPrompt(OpinionPollTheme $theme): string
    {
        $prompt = 'زاوية اليوم: '.$theme->label()."\n".$theme->guidance();

        $recent = $this->pastQuestions();

        if ($recent->isNotEmpty()) {
            $prompt .= "\n\n".'استطلاعات سابقة — لا تكرر أياً منها ولا فكرتها ولو بصياغة أخرى:'."\n"
                .$recent->map(fn (string $question): string => '- '.$question)->implode("\n");
        }

        return $prompt;
    }

    /**
     * The last polls as plain text, newest first. Days admins have already
     * queued ahead count as past here: a question is just as repeated when it
     * is already scheduled.
     *
     * @return Collection<int, string>
     */
    private function pastQuestions(): Collection
    {
        return OpinionPoll::query()
            ->latest('poll_date')
            ->limit(self::RECENT_POLLS)
            ->pluck('question')
            ->filter()
            ->values();
    }

    /**
     * One authoring-tier generation with its exact provider cost recorded on
     * ai-kit's usage module under the `opinion_poll` feature.
     *
     * The poll is validated behind {@see SubmitOpinionPollTool}, so a rejected
     * candidate is corrected within this same agentic call; we read the
     * accepted payload back off the tool once the run finishes.
     *
     * @return array{question: string, options: array<int, string>}
     */
    private function generate(string $prompt): array
    {
        Context::add(config('ai-kit.usage.feature_context_key'), self::FEATURE);

        $tool = new SubmitOpinionPollTool;

        (new OpinionPollAuthoringAgent(self::INSTRUCTIONS, [$tool]))->prompt($prompt);

        $accepted = $tool->accepted();

        if ($accepted === null) {
            throw new RuntimeException('لم يعتمد النموذج استطلاعاً صالحاً عبر الأداة.');
        }

        return $accepted;
    }
}
