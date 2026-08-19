<?php

namespace App\Ai\Quiz;

use App\Models\DailyQuiz;
use App\Models\QuizTopic;
use App\Settings\AiSettings;
use App\Support\QuizContentHtml;
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
 * validator sanitizes the authored HTML and enforces the length caps (question
 * text 1200 chars, options 100, explanation 200) and the quality rules. The
 * question, its scenario/code and its options are rendered to an image, so the
 * Telegram poll itself is generic — a "choose 1–4" prompt whose votes map back
 * by index. A rejected question is corrected inside the same conversation — the
 * model is told exactly what failed — so there is no blind stateless retry.
 * Gated like every paid feature: the AI master switch, the OpenRouter key,
 * and the daily spend budget; each call's cost is metered by ai-kit under the
 * `quiz` feature.
 */
class QuizAuthor
{
    /** Usage feature label for quiz generations (ai-kit usage module). */
    public const FEATURE = 'quiz';

    /**
     * The authored question is one HTML fragment rendered to an image, not a
     * Telegram poll field, so the cap is on its readable text (tags excluded)
     * and generous enough for a teaching preamble, a short code block and the
     * question itself.
     */
    public const MAX_QUESTION_CHARS = 1200;

    public const MAX_OPTION_CHARS = 100;

    /**
     * The "longest option is the answer" tell: reject (and retry) when the
     * correct option runs more than this many characters longer than the
     * average of the three distractors, forcing options of similar length.
     */
    public const MAX_CORRECT_OPTION_LENGTH_LEAD = 10;

    public const MAX_EXPLANATION_CHARS = 200;

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

        كيف يُعرض سؤالك:
        السؤال بأكمله — المقدمة والكود والسؤال والخيارات الأربعة — يُرسم في صورة أنيقة تُنشر في المجموعة، ثم يظهر تحتها تصويت عام خياراته أرقام فقط (1، 2، 3، 4) يختار منه الطالب. لذلك لا يقرأ الطالب نصاً عربياً مختلطاً باتجاهات؛ كل شيء داخل الصورة بتنسيق سليم. وهذا يعني أمرين: (أ) اكتب حقل question بصيغة HTML بسيطة كما هو موضّح أدناه، و(ب) اكتب في الخيارات النص الكامل للإجابة (لا الأرقام) لأنه يظهر في الصورة.

        القاعدة الأولى — «علّم ثم اسأل»:
        كل سؤال ناجح عندنا جزءان: مقدمة قصيرة تشرح المفهوم بلغة ودودة ومثال ملموس، ثم سؤال قصير يطبّق ما شرحته المقدمة للتو. من لم يكن يعرف المفهوم يخرج وقد تعلّمه، ومن كان يعرفه يستمتع بتطبيقه — والنتيجة أن يجيب معظم الناس إجابةً صحيحة ويبتسموا، لا أن يسقط أغلبهم. هذا هو الشكل المطلوب في الغالبية الساحقة من الأيام. اجعل المقدمة والسؤال معاً في حقل question، المقدمة أولاً ثم السؤال في نهايته.

        ثلاثة أمثلة مكتملة من أفضل ما نُشر — احتذِ بها في الروح والشكل:

        المثال الأول (علّم ثم احسب):
        question:
        <p dir="rtl">في ترميز ASCII، الأحرف الإنجليزية الكبيرة لها قيم رقمية متسلسلة: A=65، B=66، C=67… وهكذا بالترتيب.</p>
        <p dir="rtl">إذا كان الحرف <span dir="ltr">F</span> قيمته 70، فما قيمة الحرف <span dir="ltr">I</span>؟</p>
        options: «71» / «72» / «73» / «74» — والصحيح «73».
        explanation: «بما أن F=70، نعد ثلاثة أحرف: G=71، H=72، I=73. جميع الأحرف الكبيرة في ASCII متسلسلة.»
        hint: «تذكّر تسلسل الأحرف: F ثم G ثم H ثم I.»
        obvious_hint: «F هي الحرف السادس وI هي التاسع. أضف 3 إلى 70.»
        لماذا نجح: المقدمة أعطت القاعدة ومثالاً عليها، والسؤال خطوة عدّ واحدة، والخيارات أربعة أرقام متجاورة متطابقة الطول، والتلميح الثاني سمّى العملية دون كتابة الناتج.

        المثال الثاني (علّم ثم صنّف):
        question:
        <p dir="rtl">في هندسة البرمجيات، تبدأ عملية تطوير أي نظام بتحديد متطلباته. وتنقسم المتطلبات إلى تصنيفين: المتطلبات الوظيفية تحدد ما يجب أن يفعله النظام، والمتطلبات غير الوظيفية تحدد خصائص النظام وجودته.</p>
        <p dir="rtl">أيّ من الخيارات التالية يُعد مثالاً على متطلب غير وظيفي (Non-functional Requirement)؟</p>
        options: «يسمح للمستخدم بتسجيل الدخول» / «يحسب الراتب الشهري» / «يستجيب في أقل من ثانيتين» / «يخزّن بيانات العميل».
        explanation: «المتطلبات غير الوظيفية تصف خصائص الجودة مثل الأداء والأمان، بينما الوظيفية تصف ما يفعله النظام.»
        hint: «افحص كل خيار واسأل: هل يصف ما يفعله النظام أم كيف يؤديه؟»
        obvious_hint: «فكر في سرعة الاستجابة كمثال.»
        لماذا نجح: المقدمة عرّفت التصنيفين تعريفاً مجرداً ولم تذكر أي خيار ولم تصنّف أياً منه، فبقي على الطالب أن يطبّق التعريف بنفسه. صيغة «أيّها مثال على X؟» مرحّب بها تماماً بهذا الشرط وحده: أن تكون القاعدة مشروحة في المقدمة قبلها، وأن تكون الخيارات حالات ملموسة لا أسماء التصنيفات نفسها.

        المثال الثالث (فيه كود):
        question:
        <p dir="rtl">في بايثون، المعامل <span dir="ltr">**</span> يرفع العدد إلى أُس. الكود التالي يرفع 2 إلى الأُس 3:</p>
        <pre dir="ltr"><code>print(2 ** 3)</code></pre>
        <p dir="rtl">ماذا يُطبع؟</p>
        options: «6» / «8» / «9» / «16» — والصحيح «8».
        لاحظ: الكود في سطر مستقل داخل <pre dir="ltr"> منفصلاً عن الجملة العربية، لا مدسوساً داخلها.

        كتابة حقل question بصيغة HTML:
        - اكتب كل فقرة عربية داخل <p dir="rtl"> … </p>، والسؤال نفسه فقرة مستقلة في النهاية.
        - أي كود أو سطر لاتيني من أكثر من كلمة ضعه في سطر مستقل داخل <pre dir="ltr"><code> … </code></pre> — لا تدسّه داخل جملة عربية. اجعل الكود من سطر إلى أربعة أسطر كحد أقصى، ومفهوماً لدارسي جافا وبايثون معاً ما أمكن.
        - القاعدة الذهبية للاتجاه: لا تخلط اتجاهين في سطر واحد. العربي في سطره باتجاه rtl، واللاتيني/الكود في سطره باتجاه ltr. الرمز أو الكلمة اللاتينية القصيرة (حرف، معامل، اسم دالة) داخل جملة عربية لُفّها بـ <span dir="ltr"> … </span> حتى لا ينقلب ترتيبها.
        - الوسوم المسموحة فقط: p، br، pre، code، strong، b، em، i، span، ul، ol، li، h3، h4. والسمة الوحيدة المسموحة هي dir بقيمة rtl أو ltr أو auto. لا تستعمل style ولا class ولا أي وسم آخر.
        - والكود وحده لا يكفي: اشرح قبله أو بعده القاعدة التي يقيسها السؤال (ما الذي يفعله هذا الأمر أو تلك الدالة) بجملة واحدة.

        مستوى الصعوبة:
        - بعد قراءة المقدمة يجب أن يصل طالب السنة الأولى إلى الإجابة بخطوة تفكير واحدة. مثال معياري آخر: «اشتراك إنترنت 100 ميغابت/ثانية ⇐ كم ميغابايت في الثانية؟ (نقسم على 8 = 12.5)».
        - أنواع صالحة: احسب قيمة أو ناتج كود من سطر أو سطرين، أو طبّق التصنيف/القاعدة التي شرحتها للتو على حالة ملموسة، أو تتبّع خطوة واحدة من سلوك أمر أو دالة، أو «أيها المختلف عن البقية».

        ممنوع:
        - الحفظ الجاف بلا تعليم: سؤال جوابه معلومة تُستحضر من الذاكرة ولا تُشتق مما هو أمام القارئ — «من اخترع؟ في أي سنة؟ ماذا يعني هذا الاختصار؟ لماذا سُمّيت اللغة بهذا الاسم؟». من لا يعرفها يخمّن ومن يعرفها لم يتعلّم شيئاً. العلاج واحد: إمّا أن تشرح القاعدة في المقدمة ثم تسأل تطبيقاً عليها، وإمّا أن تبدّل السؤال. حتى في موضوع الثقافة التقنية: اجعل الطُرفة التاريخية مقدمةً تُمهّد لسؤال يُحسب أو يُستنتج، لا سؤالاً عن الطُرفة نفسها.
        - الألغاز الخادعة وحالات الحافة الدقيقة التي تُسقط حتى المحترف: تفاصيل منطقة التحضير في Git، سلوك TTL/ICMP الدقيق، أي سلوك يعتمد على تفاصيل تنفيذ أو إصدار.
        - النظريات والمصطلحات التخصصية المتقدمة بلا تمهيد: قوانين التصميم (فيتس، نمط F)، «العبء المعرفي»، وأي مصطلح لا يعرفه إلا طالب سنوات متقدمة — إلا إن شرحته المقدمة بسطر بسيط وصار السؤال تطبيقاً عليه.
        - القاعدة الحاسمة: إن كانت الإجابة تحتاج معرفة ليست في المقدمة ولا يملكها طالب السنة الأولى، فالسؤال صعب — إمّا أن تُمهّد له في المقدمة وإمّا أن تبسّطه. إن شككت أنه صعب، فهو صعب.

        الشكل:
        - سؤال واحد واضح له إجابة صحيحة واحدة لا لبس فيها، وثلاثة بدائل خاطئة معقولة يمكن أن يقع فيها طالب متعجّل (ليست هزلية ولا واضحة الخطأ ولا حشواً لا يختاره أحد)، لكنها ليست فخاخاً دقيقة تُصمَّم لإسقاط المنتبه. اختبار سريع: لو أخطأ طالب، أي خيار سيختار ولماذا؟ إن لم تجد سبباً معقولاً لخيار فاستبدله.
        - اجعل الخيارات الأربعة متقاربة الطول وبالأسلوب النحوي نفسه؛ لا تجعل الإجابة الصحيحة أطول أو أكثر تفصيلاً من البدائل — فطولها الزائد تلميح يكشفها. (سترفض الأداة السؤال إذا تجاوز طول الإجابة الصحيحة متوسط طول البدائل بأكثر من عشرة أحرف، فتعيد صياغة الخيارات.)
        - أوجِز الخيارات: انظر إلى الأمثلة أعلاه — خيارات الأول حرفان («71» و«72»…)، وأطول خيار في الثاني نحو ثلاثين حرفاً. فاستهدف نحو أربعين حرفاً ولا تتجاوز الخمسين إلا لضرورة حقيقية، واحذف من كل خيار كل كلمة لا تغيّر معناه.
        - الخيارات تُكتب نصاً عادياً (لا HTML). ولا بأس أن يحتوي الخيار رمزاً أو كوداً لاتينياً فالصورة ترسمه باتجاه سليم.
        - ولا تجعل الإجابة الصحيحة أطول خيارات القائمة. بعد كتابة الخيارات عُدّ أحرف كل واحد: إن كانت الصحيحة هي الأطول فاختصرها أو أضف تفصيلاً مكافئاً إلى البدائل حتى تتساوى الأطوال تقريباً.
        - إن كانت المقدمة تشرح تصنيفات، فاجعل الخيارات الأربعة حالات ملموسة يحكم عليها الطالب — لا أسماء التصنيفات التي عرّفتها للتو، وإلا صار الجواب نسخاً من المقدمة.

        المقدمة تُمهّد ولا تكشف:
        - اشرح القاعدة أو التصنيف تعريفاً عاماً مجرداً، ولا تذكر أي خيار من الخيارات الأربعة بنصه ولا بمعناه، ولا تصنّف أياً منها، ولا تضرب مثالاً يطابق أحدها. الحد الفاصل: بعد قراءة المقدمة يبقى على الطالب أن يطبّق التعريف على الخيارات بنفسه، لا أن ينسخ الجواب منها. (المثال الثاني أعلاه هو الحد بالضبط: عرّف التصنيفين ولم يذكر «زمن الاستجابة» ولا «تسجيل الدخول».) مثال ممنوع وقع فعلاً: مقدمة تقول «الشجرة والرسم غير خطيين» ثم السؤال «أيّها ليس خطياً؟» والشجرة خيار — نسخ مباشر.
        - ولا تجعل مثال المقدمة نسخة من السؤال بأرقام مختلفة، ولا تعدّد فيه كل القيم التي يحتاجها الحل. المقدمة تعطي القاعدة، والسؤال يطلب خطوة لم يؤدِّها المثال. مثال على الخطأ: مقدمة تشرح صلاحيات chmod وتقول «6 = قراءة وكتابة، و5 = قراءة وتنفيذ، و0 = لا شيء»، ثم السؤال يطلب تركيب رقم من هذه القيم نفسها — لم يبقَ إلا الترتيب. الصواب أن تعطي القاعدة (4 قراءة، 2 كتابة، 1 تنفيذ) وتترك الجمع للطالب.
        - قاعدة مستوى المثال — وهي أدقّ ما في الباب: مثال المقدمة يجب أن يكون في مستوى أعلى من الخيارات، لا في مستواها. انظر كيف فعلها المثال الثاني أعلاه: عرّف التصنيفين تعريفاً مجرداً بلا أي مثال ملموس عليهما. فلو أنه مثّل للمتطلب غير الوظيفي بـ«سرعة الاستجابة» لكان قد كتب الجواب بيده. فإذا كانت خياراتك حالات ملموسة (سيناريوهات، أنشطة، أوامر)، فامنع نفسك من ضرب أي مثال ملموس على التصنيف الذي يسأل عنه السؤال — عرّفه بالكلام المجرد فقط.
        - واختبر مقدمتك قبل الإرسال: اقرأ الخيار الصحيح ثم ابحث في المقدمة عن جملة تكاد تطابقه. إن وجدتها فاحذفها أو ارفع مستواها.

        اللغة والفائدة:
        - اكتب بالعربية الفصحى المبسطة كما تُشرح المواد في القاعات: المصطلح بالعربية وبجانبه المصطلح الإنجليزي بين قوسين، مثل «المكدس (Stack)» و«الاستدعاء الذاتي (Recursion)».
        - أبقِ أسماء الدوال والأوامر والأكواد بالإنجليزية كما هي.
        - لا تعتمد على معلومات قد تتغير مع الزمن (إصدارات حديثة، أسعار، أشخاص).
        - اسأل نفسك قبل الإرسال: ما الجملة الواحدة التي سيخرج بها الطالب ويستطيع أن يقولها لزميله؟ إن لم تجدها فالسؤال بلا فائدة — بدّله. واجعل الشرح (explanation) يذكر القاعدة العامة لا مجرد الإجابة، ليتعلّم منه حتى من أخطأ.

        التلميحان (hint و obvious_hint) — أكثر ما يُخطئ فيه المؤلفون، فاقرأ هذا بتمعّن:
        القارئ قد قرأ المقدمة صباحاً. فإعادة صياغة المقدمة في تلميح ليست تلميحاً، بل حشو. لكل تلميح وظيفة مختلفة تماماً. وهذان التلميحان يُرسلان نصاً عادياً في تيليجرام (لا صورة)، فاكتبهما جملاً عربية دون كود أو رموز لاتينية على أطرافها شرطات.
        - التلميح الأول (hint) = «أين تنظر»: يشير إلى الجزء الذي يحسم الجواب في المعطيات — خانة بعينها، أو كلمة في السؤال، أو سؤال فرعي يبدأ به الطالب. لا يذكر القاعدة (فهي في المقدمة)، ولا يصف الإجابة الصحيحة ولا يستبعد أي خيار.
          مثال جيد: «الرقم الثاني في أمر chmod 640 هو المخصص للمجموعة.» — أشار إلى موضع النظر ولم يحسب شيئاً.
          مثال جيد آخر: «لاحظ كيف يتغير السعر كلما زادت المساحة 50م².»
        - التلميح الثاني (obvious_hint) = «ماذا تفعل»: يسمّي الخطوة أو العملية المطلوبة صراحةً ويترك للقارئ تنفيذها فقط. لا يكتب الناتج، ولا نص أي خيار، ولا كلمته المميزة، ولا رقمه.
          مثال جيد: «العلاقة قسمة على 8.» ومثال جيد آخر: «ستّ سنوات = ثلاث دورات تضاعف. اضرب في 2 ثلاث مرات.»
          وفي أسئلة «أيّها مثال على X؟» لا توجد عملية حسابية، فسمِّ الخاصية التي يُختبر بها كل خيار — كما في «فكر في سرعة الاستجابة كمثال» — دون أن تصف الخيار الصحيح نفسه.
        - أمثلة سيئة وقعت فعلاً فتجنّبها: مقدمة تعرّف «التعلم الموجّه بأنه تعلّم من بيانات معنونة»، ثم hint يقول «التعلم الموجّه يحتاج أمثلة معروفة النتائج» وobvious_hint يقول «ابحث عن الحالة التي تُستخدم فيها بيانات معنونة» — تكرار للمقدمة مرتين، ولا فرق بين التلميحين. وكذلك obvious_hint يكتب الناتج: «23 = 6 × 3 + 5 والباقي 5» — أفسد السؤال.
        - اختبار ميكانيكي حاسم للتلميح الأول: هل يصلح أن يكون جملةً منقولة من مقدمتك؟ إن صلح فقد فشل، أعد كتابته. التلميح الأول يشير إلى المعطيات أو إلى الخيارات، ولا يعرّف المفهوم أبداً.
        - وفي أسئلة «أيّها مثال على X؟» تحديداً — وهي أكثر ما يقع فيه الخلل — لا تكتب في التلميح الأول تعريف X مرة أخرى. اجعله دعوةً لفحص الخيارات بمعيار يُذكر ولا يُطبَّق، مثل: «افحص كل خيار واسأل: هل يصف ما يفعله النظام أم كيف يؤديه؟».
        - اختبار أخير: هل التلميح الثاني أوضح فعلاً من الأول بدرجة ملموسة؟ إن كانا في القوة نفسها فقد فشلا معاً.

        الإرسال والحدود:
        - أرسل سؤالك باستدعاء الأداة submit_quiz_question فقط — لا تكتب الناتج نصاً عادياً ولا JSON. حقولها: question (HTML)، options (مصفوفة من أربعة خيارات نصية)، correct_option (رقم من 0 إلى 3)، explanation، hint، obvious_hint.
        - إن أعادت الأداة قائمة مشاكل فعالجها كلها ثم استدعِها مرة أخرى، وكرّر حتى تُقبل. وبمجرد قبولها توقّف ولا تُجرِ تعديلاً إضافياً.
        - الحدود القصوى (على النص المقروء لا على وسوم HTML): نص السؤال 1200 حرف، كل خيار 100 حرف، الشرح 200 حرف، وكل تلميح 120 حرف.
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
     * @return array{question: string, options: array<int, string>, correct_option: int, explanation: string|null, hint: string|null, obvious_hint: string|null}
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
     * @param  array{question: string, options: array<int, string>, correct_option: int, explanation: string|null, hint: string|null, obvious_hint: string|null}  $question
     * @return array{question: string, options: array<int, string>, correct_option: int, explanation: string|null, hint: string|null, obvious_hint: string|null}
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
            ->map(fn (string $question): string => QuizContentHtml::toPlainText($question))
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
     * @return array{question: string, options: array<int, string>, correct_option: int, explanation: string|null, hint: string|null, obvious_hint: string|null}
     */
    private function generate(string $prompt): array
    {
        Context::add(config('ai-kit.usage.feature_context_key'), self::FEATURE);

        $tool = new SubmitQuizQuestionTool;

        (new QuizAuthoringAgent(self::INSTRUCTIONS, [$tool]))->prompt($prompt);

        $accepted = $tool->accepted();

        if ($accepted === null) {
            throw new RuntimeException('لم يعتمد النموذج سؤالاً صالحاً عبر الأداة.');
        }

        return $accepted;
    }
}
