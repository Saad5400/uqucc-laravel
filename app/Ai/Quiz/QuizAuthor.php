<?php

namespace App\Ai\Quiz;

use App\Models\DailyQuiz;
use App\Models\QuizTopic;
use App\Settings\AiSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Context;
use RuntimeException;
use Saad\AiKit\Safety\BudgetGuard;

/**
 * Generates the daily multiple-choice question: one authoring-tier agent call
 * that turns an admin-curated {@see QuizTopic} into a `ready` {@see DailyQuiz}
 * row admins may still edit before it is posted.
 *
 * The model submits its question through {@see SubmitQuizQuestionTool}, whose
 * validator enforces Telegram's quiz-poll limits (question 300 chars, options
 * 100, explanation 200), the optional `body` (700 chars), and the quality
 * rules. A rejected question is corrected inside the same conversation — the
 * model is told exactly what failed — so there is no blind stateless retry.
 * Gated like every paid feature: the AI master switch, the OpenRouter key,
 * and the daily spend budget; each call's cost is metered by ai-kit under the
 * `quiz` feature.
 */
class QuizAuthor
{
    /** Usage feature label for quiz generations (ai-kit usage module). */
    public const FEATURE = 'quiz';

    /** Telegram sendPoll hard limits the generated content must fit. */
    public const MAX_QUESTION_CHARS = 300;

    public const MAX_OPTION_CHARS = 100;

    /**
     * The "longest option is the answer" tell: reject (and retry) when the
     * correct option runs more than this many characters longer than the
     * average of the three distractors, forcing options of similar length.
     */
    public const MAX_CORRECT_OPTION_LENGTH_LEAD = 10;

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

        القاعدة الأولى — «علّم ثم اسأل»:
        كل سؤال ناجح عندنا جزءان: مقدمة قصيرة في حقل body تشرح المفهوم بلغة ودودة ومثال ملموس، ثم سؤال قصير يطبّق ما شرحته المقدمة للتو. من لم يكن يعرف المفهوم يخرج وقد تعلّمه، ومن كان يعرفه يستمتع بتطبيقه — والنتيجة أن يجيب معظم الناس إجابةً صحيحة ويبتسموا، لا أن يسقط أغلبهم. هذا هو الشكل المطلوب في الغالبية الساحقة من الأيام.

        مثالان مكتملان من أفضل ما نُشر — احتذِ بهما في الروح والشكل:

        ‏■ المثال الأول (علّم ثم احسب):
        body: «في ترميز ASCII (المعيار الذي طُوّر في ستينيات القرن الماضي)، الأحرف الإنجليزية الكبيرة لها قيم رقمية متسلسلة: A=65, B=66, C=67… وهكذا بالترتيب.»
        question: «إذا كان الحرف 'F' في ترميز ASCII قيمته 70، فما قيمة الحرف 'I'؟»
        options: «71» / «72» / «73» / «74» — والصحيح «73».
        explanation: «بما أن F=70، نعد ثلاثة أحرف: G=71، H=72، I=73. جميع الأحرف الكبيرة في ASCII متسلسلة.»
        hint: «تذكّر تسلسل الأحرف: F ثم G ثم H ثم I.»
        obvious_hint: «F هي الحرف السادس وI هي التاسع. أضف 3 إلى 70.»
        لماذا نجح: المقدمة أعطت القاعدة ومثالاً عليها، والسؤال خطوة عدّ واحدة، والخيارات أربعة أرقام متجاورة متطابقة الطول، والتلميح الثاني سمّى العملية دون كتابة الناتج.

        ‏■ المثال الثاني (علّم ثم صنّف):
        body: «في هندسة البرمجيات، تبدأ عملية تطوير أي نظام بتحديد احتياجاته (المواصفات أو المتطلبات)، مثل: "يجب أن يحتوي البرنامج على ذكاء اصطناعي لمساعدة المستخدم". وتنقسم هذه المتطلبات إلى تصنيفين أساسيين: المتطلبات الوظيفية تحدد ما يجب أن يفعله النظام، والمتطلبات غير الوظيفية تحدد خصائص النظام وجودته.»
        question: «أي من الخيارات التالية يُعد مثالاً على متطلب غير وظيفي (Non-functional Requirement)؟»
        options: «يجب أن يسمح النظام للمستخدم بتسجيل الدخول» / «يجب أن يحسب النظام الراتب الشهري» / «يجب أن يستجيب النظام في أقل من ثانيتين» / «يجب أن يخزن النظام بيانات العميل».
        explanation: «المتطلبات غير الوظيفية تصف خصائص الجودة مثل الأداء والأمان، بينما الوظيفية تصف ما يفعله النظام.»
        hint: «المتطلبات غير الوظيفية تهتم بكيفية أداء النظام، لا بما يفعله.»
        obvious_hint: «فكر في سرعة الاستجابة كمثال.»
        لماذا نجح: المقدمة عرّفت التصنيفين تعريفاً مجرداً ولم تذكر أي خيار ولم تصنّف أياً منه، فبقي على الطالب أن يطبّق التعريف بنفسه. صيغة «أيّها مثال على X؟» مرحّب بها تماماً بهذا الشرط وحده: أن تكون القاعدة مشروحة في body قبلها، وأن تكون الخيارات حالات ملموسة لا أسماء التصنيفات نفسها.

        مستوى الصعوبة:
        - بعد قراءة المقدمة يجب أن يصل طالب السنة الأولى إلى الإجابة بخطوة تفكير واحدة. مثال معياري آخر: «اشتراك إنترنت 100 ميغابت/ثانية ⇐ كم ميغابايت في الثانية؟ (نقسم على 8 = 12.5)».
        - أنواع صالحة: احسب قيمة أو ناتج كود من سطر أو سطرين، أو طبّق التصنيف/القاعدة التي شرحتها للتو على حالة ملموسة، أو تتبّع خطوة واحدة من سلوك أمر أو دالة، أو «أيها المختلف عن البقية».

        ممنوع:
        - الحفظ الجاف بلا تعليم: سؤال جوابه معلومة تُستحضر من الذاكرة ولا تُشتق مما هو أمام القارئ — «من اخترع؟ في أي سنة؟ ماذا يعني هذا الاختصار؟ لماذا سُمّيت اللغة بهذا الاسم؟». من لا يعرفها يخمّن ومن يعرفها لم يتعلّم شيئاً. العلاج واحد: إمّا أن تشرح القاعدة في body ثم تسأل تطبيقاً عليها، وإمّا أن تبدّل السؤال. حتى في موضوع الثقافة التقنية: اجعل الطُرفة التاريخية مقدمةً تُمهّد لسؤال يُحسب أو يُستنتج، لا سؤالاً عن الطُرفة نفسها.
        - الألغاز الخادعة وحالات الحافة الدقيقة التي تُسقط حتى المحترف: تفاصيل منطقة التحضير في Git، سلوك TTL/ICMP الدقيق، أي سلوك يعتمد على تفاصيل تنفيذ أو إصدار.
        - النظريات والمصطلحات التخصصية المتقدمة بلا تمهيد: قوانين التصميم (فيتس، نمط F)، «العبء المعرفي»، وأي مصطلح لا يعرفه إلا طالب سنوات متقدمة — إلا إن شرحته المقدمة بسطر بسيط وصار السؤال تطبيقاً عليه.
        - القاعدة الحاسمة: إن كانت الإجابة تحتاج معرفة ليست في body ولا يملكها طالب السنة الأولى، فالسؤال صعب — إمّا أن تُمهّد له في body وإمّا أن تبسّطه. إن شككت أنه صعب، فهو صعب.

        الشكل:
        - سؤال واحد واضح له إجابة صحيحة واحدة لا لبس فيها، وثلاثة بدائل خاطئة معقولة يمكن أن يقع فيها طالب متعجّل (ليست هزلية ولا واضحة الخطأ ولا حشواً لا يختاره أحد)، لكنها ليست فخاخاً دقيقة تُصمَّم لإسقاط المنتبه. اختبار سريع: لو أخطأ طالب، أي خيار سيختار ولماذا؟ إن لم تجد سبباً معقولاً لخيار فاستبدله.
        - اجعل الخيارات الأربعة متقاربة الطول وبالأسلوب النحوي نفسه؛ لا تجعل الإجابة الصحيحة أطول أو أكثر تفصيلاً من البدائل — فطولها الزائد تلميح يكشفها. (سترفض الأداة السؤال إذا تجاوز طول الإجابة الصحيحة متوسط طول البدائل بأكثر من عشرة أحرف، فتعيد صياغة الخيارات.)
        - أوجِز الخيارات: انظر إلى المثالين أعلاه — خيارات الأول حرفان («71» و«72»…)، وأطول خيار في الثاني ثمانية وثلاثون حرفاً. فاستهدف نحو أربعين حرفاً ولا تتجاوز الخمسين إلا لضرورة حقيقية، واحذف من كل خيار كل كلمة لا تغيّر معناه. التصويت يُقرأ على الجوال في ثوانٍ، والخيارات الطويلة تحوّل السؤال إلى قطعة استيعاب مرهقة.
        - ولا تجعل الإجابة الصحيحة أطول خيارات القائمة. بعد كتابة الخيارات عُدّ أحرف كل واحد: إن كانت الصحيحة هي الأطول فاختصرها أو أضف تفصيلاً مكافئاً إلى البدائل حتى تتساوى الأطوال تقريباً.
        - إن كانت المقدمة تشرح تصنيفات، فاجعل الخيارات الأربعة حالات ملموسة يحكم عليها الطالب — لا أسماء التصنيفات التي عرّفتها للتو، وإلا صار الجواب نسخاً من المقدمة.

        المقدمة أو الكود (حقل body):
        - body رسالة منسّقة تُنشر فوق التصويت مباشرة. الأصل أن تكتب فيها مقدمة تعليمية: عرّف المفهوم في سطر أو سطرين بلغة بسيطة ودودة كما يشرح مُعيد على السبورة، وأعطِ مثالاً ملموساً واحداً، ثم اجعل السؤال تطبيقاً عليها. لا تضع نص السؤال داخل body.
        - الكود/السيناريو: ضع أي كود داخل سياج ماركداون بلغته، هكذا: ‏```py … ``` أو ‏```java … ``` — ليظهر بخط ثابت واتجاه سليم. اجعله من سطر إلى أربعة أسطر كحد أقصى، ومفهوماً لدارسي جافا وبايثون معاً ما أمكن. والكود وحده لا يكفي: اشرح قبله أو بعده القاعدة التي يقيسها السؤال (ما الذي يفعله هذا الأمر أو تلك الدالة) بسطر واحد.
        - متى تُترك body فارغة: نادراً جداً — فقط إن كان المفهوم من البديهيات المطلقة التي يعرفها كل طالب بلا استثناء (أولوية العمليات الحسابية مثلاً). في ما عدا ذلك المقدمة مطلوبة، ووجودها هو ما يجعل السؤال عادلاً لجمهور لم يدرس كله المادة نفسها. وإن وجدت نفسك تشرح القاعدة في التلميح الأول، فمكانها الصحيح المقدمة.
        - حاسم — المقدمة تُمهّد ولا تكشف: اشرح القاعدة أو التصنيف تعريفاً عاماً مجرداً، ولا تذكر أي خيار من الخيارات الأربعة بنصه ولا بمعناه، ولا تصنّف أياً منها، ولا تضرب مثالاً يطابق أحدها. الحد الفاصل: بعد قراءة المقدمة يبقى على الطالب أن يطبّق التعريف على الخيارات بنفسه، لا أن ينسخ الجواب منها. (المثال الثاني أعلاه هو الحد بالضبط: عرّف التصنيفين ولم يذكر «زمن الاستجابة» ولا «تسجيل الدخول».) مثال ممنوع وقع فعلاً: مقدمة تقول «الشجرة والرسم غير خطيين» ثم السؤال «أيّها ليس خطياً؟» والشجرة خيار — نسخ مباشر.
        - ولا تجعل مثال المقدمة نسخة من السؤال بأرقام مختلفة، ولا تعدّد فيه كل القيم التي يحتاجها الحل. المقدمة تعطي القاعدة، والسؤال يطلب خطوة لم يؤدِّها المثال. مثال على الخطأ: مقدمة تشرح صلاحيات chmod وتقول «6 = قراءة وكتابة، و5 = قراءة وتنفيذ، و0 = لا شيء»، ثم السؤال يطلب تركيب رقم من هذه القيم نفسها — لم يبقَ إلا الترتيب. الصواب أن تعطي القاعدة (4 قراءة، 2 كتابة، 1 تنفيذ) وتترك الجمع للطالب.
        - قاعدة مستوى المثال — وهي أدقّ ما في الباب: مثال المقدمة يجب أن يكون في مستوى أعلى من الخيارات، لا في مستواها. انظر كيف فعلها المثال الثاني أعلاه: ضرب مثالاً واحداً على «ما هو المتطلب أصلاً»، ثم عرّف التصنيفين تعريفاً مجرداً بلا أي مثال عليهما. فلو أنه مثّل للمتطلب غير الوظيفي بـ«سرعة الاستجابة» لكان قد كتب الجواب بيده. فإذا كانت خياراتك حالات ملموسة (سيناريوهات، أنشطة، أوامر)، فامنع نفسك من ضرب أي مثال ملموس على التصنيف الذي يسأل عنه السؤال — عرّفه بالكلام المجرد فقط. أمثلة على الخطأ وقعت فعلاً: مقدمة تعرّف حالة «الانتظار» وتمثّل لها بـ«انتظار إدخال المستخدم»، والخيار الصحيح «عملية حاسبة تنتظر أن يدخل المستخدم رقماً». ومقدمة تقول إن المنهجيات الرشيقة «تنتج جزءاً صغيراً عاملاً وتجمع تغذية راجعة من العميل»، والخيار الصحيح «تسليم نسخة أولية بعد شهر وجمع آراء المستخدمين».
        - واختبر مقدمتك قبل الإرسال: اقرأ الخيار الصحيح ثم ابحث في المقدمة عن جملة تكاد تطابقه. إن وجدتها فاحذفها أو ارفع مستواها.
        - عند وجود body اجعل حقل question جملة استفهامية قصيرة مستقلة تُقرأ وحدها (مثل «ماذا يُطبع؟» أو «أيّها مثال على متطلب غير وظيفي؟») — لأن التصويت لا يعرض نص body.
        - حاسم — body وحده يقبل التنسيق. أمّا question وoptions وexplanation وhint وobvious_hint فتُرسل نصاً عادياً بلا تنسيق، فلا تكتب فيها علامة ` ولا رمزاً لاتينياً على طرفه شرطة أو نقطة (مثل `-rwxr-xr--` أو `--global` أو `.env`) — اتجاه هذه الرموز ينقلب داخل السطر العربي فيقرأها الطالب مبدَّلة، وسؤالٌ يطلب قراءة رمزٍ مقلوب لا جواب له. ضع الرمز في body داخل سياج ``` ثم أشِر إليه بالكلام: «في السلسلة أعلاه». (المصطلح الإنجليزي بين قوسين مثل «المكدس (Stack)» سليم، وكذلك الشرطة داخل الكلمة مثل Big-O.)

        اللغة والفائدة:
        - اكتب بالعربية الفصحى المبسطة كما تُشرح المواد في القاعات: المصطلح بالعربية وبجانبه المصطلح الإنجليزي بين قوسين، مثل «المكدس (Stack)» و«الاستدعاء الذاتي (Recursion)».
        - أبقِ أسماء الدوال والأوامر والأكواد بالإنجليزية كما هي.
        - لا تعتمد على معلومات قد تتغير مع الزمن (إصدارات حديثة، أسعار، أشخاص).
        - اسأل نفسك قبل الإرسال: ما الجملة الواحدة التي سيخرج بها الطالب ويستطيع أن يقولها لزميله؟ إن لم تجدها فالسؤال بلا فائدة — بدّله. واجعل الشرح (explanation) يذكر القاعدة العامة لا مجرد الإجابة، ليتعلّم منه حتى من أخطأ.

        التلميحان (hint و obvious_hint) — أكثر ما يُخطئ فيه المؤلفون، فاقرأ هذا بتمعّن:
        القارئ قد قرأ المقدمة صباحاً. فإعادة صياغة المقدمة في تلميح ليست تلميحاً، بل حشو. لكل تلميح وظيفة مختلفة تماماً:
        - التلميح الأول (hint) = «أين تنظر»: يشير إلى الجزء الذي يحسم الجواب في المعطيات — خانة بعينها، أو كلمة في السؤال، أو سؤال فرعي يبدأ به الطالب. لا يذكر القاعدة (فهي في المقدمة)، ولا يصف الإجابة الصحيحة ولا يستبعد أي خيار.
          مثال جيد: «الرقم الثاني في `chmod 640` هو المخصص للمجموعة.» — أشار إلى موضع النظر ولم يحسب شيئاً.
          مثال جيد آخر: «لاحظ كيف يتغير السعر كلما زادت المساحة 50م².»
        - التلميح الثاني (obvious_hint) = «ماذا تفعل»: يسمّي الخطوة أو العملية المطلوبة صراحةً ويترك للقارئ تنفيذها فقط. لا يكتب الناتج، ولا نص أي خيار، ولا كلمته المميزة، ولا رقمه.
          مثال جيد: «العلاقة قسمة على 8.» ومثال جيد آخر: «ستّ سنوات = ثلاث دورات تضاعف. اضرب في 2 ثلاث مرات.»
          وفي أسئلة «أيّها مثال على X؟» لا توجد عملية حسابية، فسمِّ الخاصية التي يُختبر بها كل خيار — كما في «فكر في سرعة الاستجابة كمثال» — دون أن تصف الخيار الصحيح نفسه.
        - أمثلة سيئة وقعت فعلاً فتجنّبها: مقدمة تعرّف «التعلم الموجّه بأنه تعلّم من بيانات معنونة»، ثم hint يقول «التعلم الموجّه يحتاج أمثلة معروفة النتائج» وobvious_hint يقول «ابحث عن الحالة التي تُستخدم فيها بيانات معنونة» — تكرار للمقدمة مرتين، ولا فرق بين التلميحين. وكذلك obvious_hint يكتب الناتج: «23 = 6 × 3 + 5 والباقي 5» — أفسد السؤال.
        - اختبار ميكانيكي حاسم للتلميح الأول: هل يصلح أن يكون جملةً منقولة من مقدمتك؟ إن صلح فقد فشل، أعد كتابته. التلميح الأول يشير إلى المعطيات أو إلى الخيارات، ولا يعرّف المفهوم أبداً.
        - وفي أسئلة «أيّها مثال على X؟» تحديداً — وهي أكثر ما يقع فيه الخلل — لا تكتب في التلميح الأول تعريف X مرة أخرى. اجعله دعوةً لفحص الخيارات بمعيار يُذكر ولا يُطبَّق، مثل: «افحص كل خيار واسأل: هل يصف ما يفعله النظام أم كيف يؤديه؟».
        - اختبار أخير: هل التلميح الثاني أوضح فعلاً من الأول بدرجة ملموسة؟ إن كانا في القوة نفسها فقد فشلا معاً.

        الإرسال والحدود:
        - أرسل سؤالك باستدعاء الأداة submit_quiz_question فقط — لا تكتب الناتج نصاً عادياً ولا JSON. حقولها: question، body، options (مصفوفة من أربعة خيارات)، correct_option (رقم من 0 إلى 3)، explanation، hint، obvious_hint.
        - إن أعادت الأداة قائمة مشاكل فعالجها كلها ثم استدعِها مرة أخرى، وكرّر حتى تُقبل. وبمجرد قبولها توقّف ولا تُجرِ تعديلاً إضافياً.
        - الحدود القصوى: السؤال 300 حرف، body 700 حرف، كل خيار 100 حرف، الشرح 200 حرف، وكل تلميح 120 حرف. body اختياري: أرسله "" عند عدم الحاجة لكود أو مقدمة.
        - الشرح جملة أو جملتان تشرحان لماذا الإجابة صحيحة — يظهر للطالب بعد إجابته.
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

        if ($this->budget->exceeded()) {
            throw new RuntimeException(__('ai-kit::safety.budget_exceeded'));
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

        $decoded = $this->shuffleOptions($this->generateQuestion($topic));

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
     * Author one question, retrying the whole agent run only if the model
     * finishes without ever submitting a valid question through the tool
     * (in-conversation correction handles ordinary validation failures).
     *
     * @return array{question: string, body: string|null, options: array<int, string>, correct_option: int, explanation: string|null, hint: string|null, obvious_hint: string|null}
     */
    private function generateQuestion(QuizTopic $topic): array
    {
        $prompt = $this->buildPrompt($topic);
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $this->generate($prompt);
            } catch (RuntimeException $exception) {
                $lastError = $exception;
            }
        }

        throw new RuntimeException('تعذّر توليد سؤال صالح: '.$lastError?->getMessage());
    }

    /**
     * Randomize the option order so the stored position of the correct answer
     * is independent of the model. The model is never told where to place it,
     * so any residual ordering bias in its output is dissolved here; the
     * correct answer is tracked by value and its index remapped afterward.
     *
     * @param  array{question: string, body: string|null, options: array<int, string>, correct_option: int, explanation: string|null, hint: string|null, obvious_hint: string|null}  $question
     * @return array{question: string, body: string|null, options: array<int, string>, correct_option: int, explanation: string|null, hint: string|null, obvious_hint: string|null}
     */
    private function shuffleOptions(array $question): array
    {
        $correctValue = $question['options'][$question['correct_option']];

        $options = $question['options'];
        shuffle($options);

        $question['options'] = $options;
        $question['correct_option'] = (int) array_search($correctValue, $options, true);

        return $question;
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
     * ai-kit's usage module under the `quiz` feature.
     *
     * The question is validated behind {@see SubmitQuizQuestionTool}, so a
     * rejected candidate is corrected within this same agentic call; we read
     * the accepted payload back off the tool once the run finishes.
     *
     * @return array{question: string, body: string|null, options: array<int, string>, correct_option: int, explanation: string|null, hint: string|null, obvious_hint: string|null}
     */
    private function generate(string $prompt): array
    {
        Context::add(config('ai-kit.usage.feature_context_key'), self::FEATURE);

        $tool = new SubmitQuizQuestionTool;
        $response = null;

        $response = (new QuizAuthoringAgent(self::INSTRUCTIONS, [$tool]))->prompt($prompt);

        $accepted = $tool->accepted();

        if ($accepted === null) {
            throw new RuntimeException('لم يعتمد النموذج سؤالاً صالحاً عبر الأداة.');
        }

        return $accepted;
    }
}
