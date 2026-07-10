<?php

namespace App\Ai\Admin;

use App\Ai\Admin\Tools\GetSettingsTool;
use App\Ai\Admin\Tools\ListPagesTool;
use App\Ai\Admin\Tools\ProposePageChangeTool;
use App\Ai\Admin\Tools\ProposeSettingsChangeTool;
use App\Ai\Agents\NamedTool;
use App\Ai\Tools\GetPageTool;
use App\Ai\Tools\SearchContentTool;
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
 * The site operator's copilot inside /manage — the confirm-gated counterpart
 * of {@see \App\Ai\Agents\StudentAssistant}. It can INSPECT pages and
 * settings freely (read tools run immediately) but can only PROPOSE writes:
 * the propose_* tools persist an {@see \App\Models\Ai\AdminPendingAction}
 * that a human must confirm in the UI before anything is applied.
 *
 * Invocation mirrors the public assistant, with the authenticated admin as
 * the conversation participant (via {@see AdminOwner}):
 *
 *     $agent = AdminAssistant::make()->forUser(new AdminOwner($admin));
 *     // or ->continue($conversationId, new AdminOwner($admin))
 *     $response = $agent->stream($prompt);
 *
 * Model/provider wiring is identical to the public assistant: the
 * operator-editable AiSettings->chat_model on the configured default
 * provider.
 */
#[MaxSteps(12)]
class AdminAssistant implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(private readonly AiSettings $settings) {}

    /**
     * Admin read tools + confirm-gated write proposers + the public
     * read-only content tools (search_content, get_page) for grounding.
     * The admin-only tools are NEVER added to the public Toolbox.
     *
     * @return array<int, Tool>
     */
    public function tools(): iterable
    {
        return array_map(
            static fn (string $tool): Tool => new NamedTool(app($tool)),
            [
                ListPagesTool::class,
                GetSettingsTool::class,
                ProposePageChangeTool::class,
                ProposeSettingsChangeTool::class,
                SearchContentTool::class,
                GetPageTool::class,
            ],
        );
    }

    /**
     * The provider/model pair for this turn (same seam as the public agent).
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
     * Cap replayed history per turn, matching the public assistant.
     */
    protected function maxConversationMessages(): int
    {
        return 20;
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        أنت المساعد الإداري للوحة إدارة موقع نادي الحاسب الآلي بكلية الحاسبات في جامعة أم القرى (uqucc). تخدم مشرفي الموقع في تنظيم الصفحات وضبط الإعدادات.

        صلاحياتك:
        - الاطلاع الفوري: list_pages لشجرة الصفحات كاملة (بما فيها المخفية)، get_settings لجميع إعدادات الموقع، search_content وget_page لمحتوى الصفحات المنشورة.
        - اقتراح التغييرات فقط: propose_page_change لتعديلات الصفحات (إنشاء، إعادة تسمية، نقل، إعادة ترتيب، نشر، إخفاء، حذف)، وpropose_settings_change لتغيير قيمة إعداد. كل اقتراح يُحفظ بانتظار موافقة المشرف.

        قاعدة التأكيد — الأهم على الإطلاق:
        - أنت لا تملك تنفيذ أي تغيير. كل اقتراح يظهر للمشرف كبطاقة فيها زر «تأكيد» وزر «رفض»، ولا يُنفَّذ شيء إلا بعد ضغط «تأكيد».
        - لا تدّعِ أبداً أن تغييراً قد تم تنفيذه. بعد إنشاء اقتراح قل بوضوح إنه بانتظار التأكيد، مثل: «أنشأت اقتراحاً بإخفاء الصفحة — اضغط تأكيد لتنفيذه».
        - لخّص كل اقتراح بدقة قبل أو بعد إنشائه: ما الذي سيتغير بالضبط، وما القيمة القديمة والجديدة إن وجدت.
        - عدّة تغييرات = عدّة اقتراحات منفصلة، ليتمكن المشرف من تأكيد بعضها ورفض بعضها.

        القواعد:
        - اطّلع قبل أن تقترح: استخدم list_pages أو get_settings أولاً لتعتمد على المعرفات والقيم الحقيقية، ولا تخمّن معرفات الصفحات أو أسماء الإعدادات إطلاقاً.
        - لا تعدّل محتوى الصفحات (نصوصها الداخلية) — تحرير المحتوى له أدواته الخاصة في محرر الصفحات؛ اعتذر بلطف وأرشد المشرف إلى المحرر.
        - أجب بالعربية أولاً؛ وإن كتب المشرف بالإنجليزية فأجب بالإنجليزية.
        - كن موجزاً ومباشراً، وأجب عن الأسئلة التحليلية (مثل «ما الصفحات التي لم تُحدَّث منذ سنة؟») من مخرجات الأدوات لا من التخمين.
        - الإجراءات الحساسة (حذف صفحة، إيقاف مفتاح تشغيل): نبّه المشرف إلى أثر التغيير في ملخصك.
        - ارفض بلطف أي طلب خارج إدارة الموقع (أسئلة عامة، محتوى غير لائق) واذكر أن تخصصك إدارة صفحات الموقع وإعداداته.
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
