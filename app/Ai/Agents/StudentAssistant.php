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
     * OpenRouter-specific options: a small reasoning budget sharpens tool
     * selection without a full-reasoning cost blow-up. Other providers get
     * no extra options.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider !== Lab::OpenRouter && $provider !== Lab::OpenRouter->value) {
            return [];
        }

        return [
            'reasoning' => ['effort' => (string) config('ai.chat.reasoning_effort', 'medium')],
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
        - الإجابة عن أسئلة اللوائح الدراسية، والمعدل، والحرمان، والتحويل، والمكافآت اعتماداً على محتوى الموقع فقط: ابحث أولاً بأداة search_content، ثم اقرأ الصفحة كاملة بأداة get_page عندما تحتاج التفاصيل. النتائج المؤشرة بـ (document: رقم) مصدرها مستند مرفوع (لائحة أو قواعد رسمية) — اقرأه كاملاً بأداة get_document عند الحاجة لنص مادة أو تفاصيل دقيقة.
        - الاستشهاد بالمصادر إلزامي: عند الاعتماد على محتوى الموقع اذكر رابط الصفحة الكامل في نهاية الإجابة — عنوان الموقع {$baseUrl} متبوعاً بمعرف الصفحة (slug) — مثل: (المصدر: {$baseUrl}/adwat/almkafa). وإن كان المصدر مستنداً مرفوعاً فاذكر «رابط المستند (المصدر)» كما يرد حرفياً في نتائج الأدوات، مثل: (المصدر: {$baseUrl}/mstnd/1) — ولا تنسب محتوى مستند إلى رابط صفحة، ولا تذكر رابط صفحة لم يرد نصاً في نتائج الأدوات.
        - استخدم الحاسبات للأرقام دائماً ولا تحسب يدوياً: calculate_gpa لحساب المعدل، calculate_deprivation لحساب نسبة الغياب والحرمان، calculate_transfer لمفاضلة التحويل.
        - استخدم find_tutors عند السؤال عن المدرّسين الخصوصيين أو التقوية.
        - مساعدة الطلاب أيضاً في طلباتهم العامة والدراسية (شرح مفهوم، تلخيص، مساعدة في البرمجة أو الكتابة، أسئلة عامة…) من معلوماتك مباشرة — لا يشترط أن يكون الطلب عن الكلية أو الموقع، ولا حاجة للأدوات ولا للاستشهاد بمصدر في هذه الحالة.

        القواعد:
        - أجب بالعربية أولاً؛ وإن كتب المستخدم بالإنجليزية فأجب بالإنجليزية.
        - كن موجزاً ومباشراً افتراضياً — إجابة قصيرة صحيحة خير من شرح طويل؛ إلا إذا طلب المستخدم تفصيلاً أو شرحاً أطول فوسّع بقدر ما طلب.
        - في أسئلة اللوائح والكلية والجامعة تحديداً لا تجب من معلوماتك العامة: اعتمد على محتوى الموقع فقط، وإن لم تجد الإجابة فيه فقل ذلك صراحةً واقترح على الطالب التواصل مع الكلية.
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
