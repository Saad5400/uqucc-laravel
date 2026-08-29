<?php

namespace App\Ai\OpinionPoll;

/**
 * The angles an opinion poll can ask from — the opinion poll's answer to the
 * quiz's {@see \App\Models\QuizTopic}, except these are a fixed vocabulary in
 * code rather than an admin-curated table: an opinion poll needs no syllabus
 * coverage, only variety, and a closed set is what lets the author rotate
 * honestly without another CRUD screen to keep filled.
 *
 * Every theme asks about the member's own life — their tools, their habits,
 * their term — because a question anyone can answer from experience is the
 * one a lurker answers first.
 */
enum OpinionPollTheme: string
{
    case Tools = 'tools';

    case StudyHabits = 'study_habits';

    case TermLife = 'term_life';

    case TechTaste = 'tech_taste';

    case CareerPath = 'career_path';

    case CampusLife = 'campus_life';

    case LightDebate = 'light_debate';

    case SelfCheck = 'self_check';

    public function label(): string
    {
        return match ($this) {
            self::Tools => 'الأدوات',
            self::StudyHabits => 'عادات الدراسة',
            self::TermLife => 'الترم والاختبارات',
            self::TechTaste => 'الذائقة التقنية',
            self::CareerPath => 'المسار والتخصص',
            self::CampusLife => 'حياة الجامعة',
            self::LightDebate => 'جدل خفيف',
            self::SelfCheck => 'اعرف نفسك',
        };
    }

    /** The angle handed to the model, with the kind of question it should produce. */
    public function guidance(): string
    {
        return match ($this) {
            self::Tools => 'الأدوات التي يستعملها الطالب فعلاً: المحرر، الطرفية، نظام التشغيل، أداة الملاحظات، طريقة حفظ الملفات. '
                .'اسأل عن الاستعمال لا عن الأفضلية المطلقة.',
            self::StudyHabits => 'كيف يذاكر الطالب: وقت المذاكرة المفضل، وحده أم مع مجموعة، من الكتاب أم من الفيديو، '
                .'متى يبدأ قبل الاختبار، أين يذاكر.',
            self::TermLife => 'إيقاع الترم: ضغط الأسبوع الحالي، عدد المواد، متى يسلّم الواجبات، المشروع أم الاختبار، '
                .'أصعب ما في هذا الترم بالنسبة له.',
            self::TechTaste => 'الذوق التقني الشخصي: أول لغة تعلمها، اللغة التي يستمتع بكتابتها، الواجهة أم الخلفية، '
                .'الوضع الليلي أم النهاري، الخط والثيم.',
            self::CareerPath => 'إلى أين يتجه: المجال الذي ينوي التخصص فيه، وظيفة أم دراسات عليا أم مشروع خاص، '
                .'التدريب الصيفي، أول مهارة يريد إتقانها.',
            self::CampusLife => 'حياة الحرم من زاوية محايدة تماماً: المواصلات، القهوة، وقت الوصول، مكان الجلوس بين المحاضرات، '
                .'الحضور المبكر أم على الجرس. لا تقترب من تقييم شخص أو مقرر أو جهة.',
            self::LightDebate => 'جدل تقني خفيف يضحك ولا يجرح: المسافات أم Tab، الفاصلة المنقوطة، أسماء المتغيرات، '
                .'التعليقات في الكود، اسم الفرع في Git. طرفا الجدل كلاهما مقبول.',
            self::SelfCheck => 'مرآة صغيرة يرى فيها الطالب نفسه بين أقرانه: كم مشروعاً جانبياً، كم ساعة نوم، '
                .'كم مرة يفتح الهاتف وهو يذاكر، كم لغة يعرف. الأرقام في نطاقات لا أرقام دقيقة.',
        };
    }

    /**
     * @return array<int, self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
