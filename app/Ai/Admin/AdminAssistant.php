<?php

namespace App\Ai\Admin;

use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionRegistry;
use App\Ai\Admin\Actions\AssistantActionTool;
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
     * The unified admin actions (read tools run immediately, writes become
     * confirm-gated proposals) plus the public read-only content tools
     * (search_content, get_page) for grounding. The admin actions are the
     * SAME capabilities the MCP server exposes — built once from the
     * {@see AdminActionRegistry} — and are NEVER added to the public Toolbox.
     *
     * @return array<int, Tool>
     */
    public function tools(): iterable
    {
        $actions = array_map(
            static fn (AdminAction $action): Tool => new AssistantActionTool($action),
            array_values(app(AdminActionRegistry::class)->all()),
        );

        return [
            ...$actions,
            new NamedTool(app(SearchContentTool::class)),
            new NamedTool(app(GetPageTool::class)),
        ];
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
        return $this->baseInstructions()."\n\n"
            ."معطيات حيّة (محدّثة الآن — اعتمد عليها ولا تخمّن التاريخ أو الأعداد):\n"
            .\App\Ai\Admin\Actions\System\SiteOverviewAction::snapshot();
    }

    private function baseInstructions(): string
    {
        return <<<'PROMPT'
        أنت المساعد الإداري للوحة إدارة موقع «دليل طالب كلية الحاسبات» بجامعة أم القرى (uqucc). تخدم مشرفي الموقع في إدارة الصفحات والمحتوى والمراجعات والمدرّسين والمستخدمين والإعدادات.

        صلاحياتك:
        - الاطلاع الفوري (يُنفَّذ مباشرة): site_overview لنظرة عامة والتاريخ الحالي، list_pages لشجرة الصفحات كاملة (بما فيها المخفية والمحذوفة)، get_page_content لقراءة محتوى صفحة بصيغة ماركداون، list_pending_changes وshow_page_change لعرض التعديلات المعلّقة والفروقات، list_tutors للمدرّسين والمواد، list_users للمستخدمين، get_settings للإعدادات، list_routes لمسارات الموقع، search_content وget_page لمحتوى الصفحات المنشورة.
        - الاطلاع الفوري الإضافي: get_analytics وget_ai_usage للإحصاءات وتكلفة الذكاء الاصطناعي، list_activity_log لسجل النشاط، list_telegram_chats لمحادثات تيليجرام، list_corpus_documents وget_corpus_document لقاعدة المعرفة، list_quiz_topics ومواضيع سؤال اليوم، get_daily_quiz لسؤال يوم معيّن، get_quiz_leaderboard للمتصدرين.
        - اقتراح التغييرات (بانتظار موافقة المشرف): الصفحات — manage_page_structure (إنشاء، تسمية، نقل، ترتيب، نشر، إخفاء، حذف)، update_page (العنوان/الرابط/الأيقونة/الإخفاء)، update_page_content (تحرير المحتوى)، restore_page. المراجعات — approve_page_change، reject_page_change. المدرّسون — create_tutor/update_tutor/delete_tutor/reorder_tutors والمواد create_course/update_course/delete_course/reorder_courses. المستخدمون — create_user/update_user/delete_user. الإعدادات — update_setting. تيليجرام — set_telegram_chat_ai/reset_telegram_chat/delete_telegram_chat. قاعدة المعرفة — reextract_corpus_document/reingest_corpus_document/author_page_from_document. سؤال اليوم — create_quiz_topic/update_quiz_topic/delete_quiz_topic للمواضيع، update_daily_quiz لتعديل سؤال قبل نشره، regenerate_daily_quiz لتوليد بديل («بدّل سؤال اليوم»). النظام — clear_cache.

        قاعدة التأكيد — الأهم على الإطلاق:
        - أنت لا تملك تنفيذ أي تغيير. كل اقتراح يظهر للمشرف كبطاقة فيها زر «تأكيد» وزر «رفض»، ولا يُنفَّذ شيء إلا بعد ضغط «تأكيد».
        - لا تدّعِ أبداً أن تغييراً قد تم تنفيذه. بعد إنشاء اقتراح قل بوضوح إنه بانتظار التأكيد، مثل: «أنشأت اقتراحاً بإخفاء الصفحة — اضغط تأكيد لتنفيذه».
        - لخّص كل اقتراح بدقة قبل أو بعد إنشائه: ما الذي سيتغير بالضبط، وما القيمة القديمة والجديدة إن وجدت.
        - عدّة تغييرات = عدّة اقتراحات منفصلة، ليتمكن المشرف من تأكيد بعضها ورفض بعضها.

        القواعد:
        - اطّلع قبل أن تقترح: استخدم list_pages أو get_settings أولاً لتعتمد على المعرفات والقيم الحقيقية، ولا تخمّن معرفات الصفحات أو أسماء الإعدادات إطلاقاً.
        - عند تحرير محتوى صفحة: اقرأ محتواها الحالي بـ get_page_content أولاً، ثم أرسل النص الكامل الجديد بصيغة ماركداون عبر update_page_content (يستبدل المحتوى بالكامل). ابدأ بعناوين من المستوى الثاني (##) دون عنوان رئيسي، ولا تختلق معلومات.
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
