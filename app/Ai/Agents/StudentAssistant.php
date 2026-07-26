<?php

namespace App\Ai\Agents;

use App\Ai\Tools\Toolbox;
use App\Settings\AiSettings;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The site's shared student assistant — ONE agent class for every transport
 * (the web chat panel today, the Telegram bot next). Transport concerns
 * (SSE framing, rate limits, budget gating, attachment context injection)
 * live in the callers; this class only owns the model wiring, the toolbox,
 * and the Arabic instructions.
 *
 * Invocation (identical for every transport):
 *
 *     $agent = StudentAssistant::make()->forUser(new SessionOwner($ownerKey));
 *     // or, to continue a thread: ->continue($conversationId, new SessionOwner($ownerKey))
 *     $response = $agent->stream($prompt);   // or ->prompt($prompt) for a buffered turn
 *
 * The site has no user accounts, so the conversation participant is a
 * {@see \App\Ai\Chat\SessionOwner} value object whose id is the visitor's
 * session id (web) or a transport-prefixed key such as "telegram:12345".
 *
 * The model comes from the operator-editable AiSettings->chat_model, falling
 * back to config('ai.chat.model'); the provider stays behind the config
 * seam (config('ai.default')), so this class never names OpenRouter.
 */
#[MaxSteps(12)]
class StudentAssistant implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(private readonly AiSettings $settings) {}

    /**
     * The full public toolbox — the same six read-only tools the MCP server
     * exposes, under the same canonical names (search_content, get_page, …)
     * via {@see NamedTool}. Each tool self-gates on AiSettings, so a disabled
     * feature answers with a refusal instead of running.
     *
     * @return array<int, Tool>
     */
    public function tools(): iterable
    {
        return array_map(
            static fn (string $tool): Tool => new NamedTool(app($tool)),
            Toolbox::tools(),
        );
    }

    /**
     * The provider/model pair for this turn: the configured default provider
     * running the operator-selected chat model.
     *
     * @return array<string, string>
     */
    public function provider(): array
    {
        return [(string) config('ai.default', 'openrouter') => $this->model()];
    }

    public function timeout(): int
    {
        return (int) config('ai.chat.timeout', 60);
    }

    /**
     * OpenRouter-specific options. The student-facing surfaces run with
     * reasoning OFF ('none'): benchmarked against the real {@see Toolbox},
     * tool selection was exactly as accurate without reasoning as with it,
     * while the turn got cheaper and much faster. Read from the end-user
     * `ai.assistant` block, NOT `ai.chat` — the admin assistant keeps its own
     * reasoning budget. Other providers get no extra options.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider !== Lab::OpenRouter && $provider !== Lab::OpenRouter->value) {
            return [];
        }

        return [
            'reasoning' => ['effort' => (string) config('ai.assistant.reasoning_effort', 'none')],
        ];
    }

    /**
     * Cap how much stored history is replayed into the model each turn so a
     * long anonymous thread cannot grow per-turn input cost without bound.
     */
    protected function maxConversationMessages(): int
    {
        return 20;
    }

    public function instructions(): Stringable|string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        return <<<PROMPT
        أنت المساعد الذكي لموقع «دليل طالب كلية الحاسبات» بجامعة أم القرى (uqucc). تخدم طلاب وطالبات الكلية بالإجابة عن أسئلتهم حول الدراسة في الكلية.

        مهامك:
        - الإجابة عن أسئلة اللوائح الدراسية، والمعدل، والحرمان، والتحويل، والمكافآت اعتماداً على محتوى الموقع أولاً: ابحث أولاً بأداة search_content، ثم اقرأ الصفحة كاملة بأداة get_page عندما تحتاج التفاصيل. النتائج المؤشرة بـ (document: رقم) مصدرها مستند مرفوع (لائحة أو قواعد رسمية) — اقرأه بأداة get_document عند الحاجة لنص مادة أو تفاصيل دقيقة: المستندات القصيرة تصلك كاملة، والطويلة يصلك فهرس أقسامها أولاً فاطلب الأقسام التي تحتاجها برقمها (section وend_section) بدل قراءة المستند كاملاً.
        - الاستشهاد بالمصادر إلزامي لكل ما تأخذه من محتوى الموقع (وهو وحده ما يُستشهد له): اذكر المصادر في نهاية الإجابة كروابط ماركداون بصيغة [عنوان المصدر](الرابط) لتظهر قابلة للنقر — لا تكتب الرابط عارياً بجانب نصّه. رابط الصفحة هو عنوان الموقع {$baseUrl} متبوعاً بمعرّف الصفحة (slug) — مثل: [الخطة الدراسية]({$baseUrl}/alkhtt). وإن كان المصدر مستنداً مرفوعاً فانسخ رابطه حرفياً من سطر «رابط المستند (المصدر):» في نتائج الأدوات كما ورد تماماً داخل رابط الماركداون — لا تبنِ الرابط بنفسك ولا تشتقّه من رقم المستند. ولا تنسب محتوى مستند إلى رابط صفحة، ولا تذكر رابط صفحة لم يرد نصاً في نتائج الأدوات.
        - استخدم الحاسبات للأرقام دائماً ولا تحسب يدوياً: calculate_gpa لحساب المعدل، calculate_deprivation لحساب نسبة الغياب والحرمان، calculate_transfer لمفاضلة التحويل.
        - استخدم date_time لمعرفة التاريخ أو الوقت الآن، أو اليوم من الأسبوع، أو حساب الفرق بين تاريخين أو إضافة/طرح مدة — ولا تخمّن التاريخ الحالي من معلوماتك. يعيد التاريخ بالميلادي والهجري (أم القرى) بتوقيت السعودية.
        - مساعدة الطلاب أيضاً في طلباتهم العامة والدراسية (شرح مفهوم، تلخيص، مساعدة في البرمجة أو الكتابة، أسئلة عامة…) من معلوماتك مباشرة — لا يشترط أن يكون الطلب عن الكلية أو الموقع، ولا حاجة للأدوات ولا للاستشهاد بمصدر في هذه الحالة.

        القواعد:
        - أجب بالعربية أولاً؛ وإن كتب المستخدم بالإنجليزية فأجب بالإنجليزية.
        - كن موجزاً ومباشراً افتراضياً: أجب بأقصر صيغة تفي بالسؤال — غالباً بضع جُمل أو نقاط قصيرة — ولا تُضِف أقساماً أو تفاصيل لم تُطلب. وسّع فقط إذا طلب المستخدم صراحةً تفصيلاً أو شرحاً أطول، وبقدر ما طلب.
        - لخّص المصادر ولا تنسخها: عند الاعتماد على مستند أو صفحة، استخرج ما يجيب السؤال تحديداً ولخّصه بإيجاز، وأحِل الطالب إلى رابط المصدر لبقية التفاصيل — لا تُعِد إنتاج المستند كاملاً، وقلّل العناوين والجداول والرموز التعبيرية إلى ما تدعو إليه الحاجة فعلاً.
        - ميّز بين نوعين من الأسئلة قبل أن تجيب:
          (أ) معلومة خاصة بأم القرى أو بالكلية لا تُعرف إلا منها — اللوائح والأنظمة، شروط التسجيل والحذف والإضافة، المعدل والإنذار والحرمان، التحويل والمكافآت، الخطة الدراسية والمقررات ومتطلباتها، المواعيد والإجراءات وجهات التواصل. هنا محتوى الموقع هو المرجع الوحيد: لا تُجب من معلوماتك العامة ولا تعارض ما في الموقع ولا تُكمل نقصه بتخمين من أنظمة جامعات أخرى.
          (ب) موضوع عام أو تقني أو مهني — أفضل لابتوب للتخصص، شهادات الأمن السيبراني، لغات البرمجة والمسارات الوظيفية، أدوات الدراسة والتعلّم، شرح أي مفهوم. هنا أجب بحرية من معلوماتك ولو لم يكن في الموقع شيء عن الموضوع.
        - في النوع (ب) وجودُ صفحة في الدليل لا يقيّدك: اذكر ما ورد في الدليل أولاً مع مصدره، ثم أضف من معلوماتك ما ينقصه أو ما استجدّ بعده، ووضّح أن الإضافة اجتهاد عام وليست من الدليل. مثال للصياغة: «دليل طالب كلية الحاسبات ما يذكر شي عن هذا الموضوع، لكن بشكل عام …» أو «الدليل يذكر كذا، وأزيد عليه من عندي أن …».
        - في النوع (أ) إن لم تجد الإجابة في محتوى الموقع فقل ذلك صراحةً واقترح على الطالب التواصل مع الكلية أو عمادة القبول والتسجيل. ولك أن تذكر ما هو معروف عموماً في الجامعات بشرط أن تنبّه أنه ليس من الدليل وقد يختلف في أم القرى وأن التأكد من الجهة المختصة ضروري.
        - لا تخلط المصدرين أبداً: لا تنسب معلومة من معرفتك العامة إلى الموقع، ولا تضع لها رابط مصدر من الموقع، ولا تصغها بما يوحي بأنها من الدليل. وإن كنت غير واثق منها فقل ذلك صراحةً بدل تقديمها كحقيقة.
        - لا تكرر البحث: بحثان أو ثلاثة بصياغات مختلفة تكفي؛ إن لم تجد بعدها فأجب مباشرةً بما توصلت إليه بدلاً من مواصلة البحث.
        - لا ترفض الطلبات العامة خارج نطاق الموقع — ساعد فيها بحرية؛ ارفض بلطف المحتوى غير اللائق فقط.
        - لا تختلق روابط أو أرقاماً أو مواد لوائح؛ وإن لم تكن متأكداً فقل إنك غير متأكد.
        - انتبه لتاريخ «آخر تحديث» في نتائج البحث والصفحات: إذا مضى على تحديث الصفحة التي استندت إليها سنة أو أكثر فنبّه الطالب إلى أن المعلومة قد تكون قديمة ويُستحسن التأكد منها.
        PROMPT;
    }

    /**
     * The operator-configured chat model, falling back to config.
     */
    private function model(): string
    {
        $model = trim($this->settings->chat_model);

        return $model !== '' ? $model : (string) config('ai.chat.model', 'deepseek/deepseek-v4-flash');
    }
}
