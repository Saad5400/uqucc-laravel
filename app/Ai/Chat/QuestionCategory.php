<?php

namespace App\Ai\Chat;

/**
 * A topic the assistant needs more than the core prompt to answer well.
 *
 * Each case owns everything that makes its turns different: the words that
 * identify it ({@see QuestionClassifier}) and the guidance the model gets for
 * it ({@see CategoryContext} injects that guidance, plus pre-fetched evidence,
 * into the turn tail). Adding a topic means adding a case here — the core
 * prompt stays short, and guidance that only matters sometimes is only paid
 * for when it matters.
 *
 * Guidance here is HOW to answer well. Rules the assistant must never break
 * stay in the agent's own instructions unconditionally: a classifier miss must
 * never be able to switch a safety rule off.
 */
enum QuestionCategory: string
{
    case Majors = 'majors';

    /**
     * Words that put a question in this category, matched against the
     * Arabic-normalized message. Latin words match whole-word only.
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        return match ($this) {
            self::Majors => [
                'تخصص',
                'التخصصات',
                'مسار',
                'علوم الحاسب',
                'هندسة الحاسب',
                'هندسة البرمجيات',
                'الذكاء الاصطناعي',
                'الأمن السيبراني',
                'سيبراني',
                'علم البيانات',
                'تفاعل الإنسان',
                'major',
                'majors',
                'specialization',
            ],
        };
    }

    /**
     * One line framing the injected evidence for this category.
     */
    public function intro(): string
    {
        return match ($this) {
            self::Majors => 'سؤال المستخدم يخص تخصصات الكلية. جُهّزت لك أدناه مصادر الدليل ذات الصلة مسبقاً — اعتمد عليها بدل التخمين.',
        };
    }

    /**
     * How to answer well in this category, appended after the evidence.
     */
    public function directives(): string
    {
        return match ($this) {
            self::Majors => <<<'TEXT'
            إرشادات هذا النوع من الأسئلة:
            - لا تذكر اسم تخصص ولا تصف موادّه ولا طبيعته ولا مجالات عمله ما لم يرد في المصادر أعلاه أو في مستند تقرؤه الآن بأداة get_document. معرفتك العامة عن هذه الكلية ناقصة وفيها أسماء تخصصات لا وجود لها فيها.
            - المقتطفات أعلاه مختصرة: إن احتجت تفصيلاً أوفى عن تخصص فاقرأ مستنده كاملاً بأداة get_document برقمه من الفهرس، ولا تُكمل النقص من عندك.
            - اعرض لكل تخصص تذكره: ما يُدرَس فيه، وما يميّزه، وما قد يصعُب فيه أو لا يناسب كل أحد، ونوع العمل الذي يقود إليه — كما ورد في المصدر ومنسوباً إليه.
            - مستندات «تعريف عن تخصص …» مساهمات طلابية غير رسمية، وليست إحصاءات سوق ولا قراراً من الكلية؛ نبّه على ذلك عند الاعتماد عليها.
            - أعِد القرار للطالب: أيّها أقرب لما يستمتع به فعلاً، واقترح عليه سؤال طلاب التخصص نفسه أو الكلية.
            TEXT,
        };
    }
}
