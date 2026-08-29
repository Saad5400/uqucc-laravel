<?php

namespace App\Support;

/**
 * Ready-made opinion polls the panel offers as a starting point.
 *
 * The queue is written by hand, and an empty queue is the way this feature
 * dies in its first week — so the editor opens with a shelf of questions an
 * admin can send in two clicks. They are deliberately low-stakes and about the
 * members themselves (their tools, their habits, their term), because a
 * question anyone can answer from their own life is the one a lurker answers
 * first.
 */
class OpinionPollSuggestions
{
    /**
     * @return array<int, array{question: string, options: array<int, string>}>
     */
    public static function all(): array
    {
        return [
            [
                'question' => 'ما المحرر الذي تكتب به أكثر؟',
                'options' => ['VS Code', 'IntelliJ / PyCharm', 'Vim / Neovim', 'شيء آخر'],
            ],
            [
                'question' => 'أصعب مادة مرّت عليك حتى الآن؟',
                'options' => ['تراكيب البيانات', 'الرياضيات المتقطعة', 'أنظمة التشغيل', 'الشبكات'],
            ],
            [
                'question' => 'متى تذاكر أفضل؟',
                'options' => ['بعد الفجر', 'وسط النهار', 'بعد العشاء', 'بعد منتصف الليل'],
            ],
            [
                'question' => 'كم تنام في أيام الاختبارات؟',
                'options' => ['أقل من ٤ ساعات', '٤ إلى ٦ ساعات', '٦ إلى ٨ ساعات', 'أكثر من ٨ ساعات'],
            ],
            [
                'question' => 'أول لغة برمجة تعلمتها؟',
                'options' => ['Python', 'Java', 'C / C++', 'شيء آخر'],
            ],
            [
                'question' => 'المشروع أم الاختبار النهائي؟',
                'options' => ['المشروع أرحم', 'الاختبار أرحم', 'كلاهما مرعب'],
            ],
            [
                'question' => 'ما المجال الذي تنوي التخصص فيه؟',
                'options' => ['تطوير الويب', 'الذكاء الاصطناعي', 'الأمن السيبراني', 'الشبكات والأنظمة'],
            ],
            [
                'question' => 'نظام التشغيل الذي تبرمج عليه؟',
                'options' => ['Windows', 'macOS', 'Linux', 'أكثر من واحد'],
            ],
            [
                'question' => 'مصدرك الأول عند التعثر في كود؟',
                'options' => ['البحث في الإنترنت', 'مساعد ذكي', 'زميل أو مجموعة', 'التوثيق الرسمي'],
            ],
            [
                'question' => 'متى تسلّم الواجب عادةً؟',
                'options' => ['قبل الموعد بأيام', 'قبله بيوم', 'في آخر ساعات', 'في آخر دقيقة'],
            ],
            [
                'question' => 'كم مشروعاً جانبياً أنجزت هذا الترم؟',
                'options' => ['ولا واحد', 'واحد', 'اثنان أو ثلاثة', 'أكثر من ثلاثة'],
            ],
            [
                'question' => 'تذاكر أفضل وحدك أم مع مجموعة؟',
                'options' => ['وحدي', 'مع زميل واحد', 'مع مجموعة', 'حسب المادة'],
            ],
        ];
    }
}
