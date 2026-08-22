<?php

use App\Models\StudentGroup\Branch;
use App\Models\StudentGroup\Cohort;
use App\Models\StudentGroup\Major;
use App\Models\StudentGroup\StudentGroup;
use App\Models\StudentGroup\SupervisorSection;
use Illuminate\Database\Migrations\Migration;

/**
 * One-time import of the supervisor lists the college has been publishing by
 * hand — the دفعة ٤٨ announcement, the joint دفعة ٤٦ و٤٧ announcement, and the
 * standalone دفعة ٤٧ page this feature replaces.
 *
 * This is an import, not a seeder: the rows are real content that belongs in
 * production exactly once, after which they are edited from /manage like any
 * other content. Re-running is safe — a cohort that already exists is skipped
 * whole, so a later `migrate` never resurrects a supervisor an admin removed.
 *
 * Writes go through the models on purpose: they normalize handles and phone
 * numbers, assign ordering, and flush the public cache.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tests build the schema from scratch and assert against exact counts;
        // seeding real content into every test database would break all of it.
        if (app()->runningUnitTests()) {
            return;
        }

        foreach ($this->cohorts() as $definition) {
            $this->importCohort($definition);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately a no-op: by the time anyone rolls back, these rows have been
     * edited by hand, and deleting a live supervisor list is far worse than
     * leaving it in place.
     */
    public function down(): void
    {
        //
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function importCohort(array $definition): void
    {
        if (Cohort::query()->where('name', $definition['name'])->exists()) {
            return;
        }

        $cohort = Cohort::create([
            'name' => $definition['name'],
            'description' => $definition['description'],
            'note' => $definition['note'],
            'requirements' => $definition['requirements'],
            'is_active' => true,
            'is_featured' => $definition['featured'],
        ]);

        foreach ($definition['groups'] as $group) {
            $this->importGroup($cohort, $group);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function importGroup(Cohort $cohort, array $definition): void
    {
        $group = StudentGroup::create([
            'student_cohort_id' => $cohort->id,
            'major' => $definition['major'],
            'branch' => $definition['branch'],
            'is_active' => true,
        ]);

        foreach ($definition['supervisors'] as [$section, $name, $contacts]) {
            $group->supervisors()->create([
                ...$this->contactColumns((array) $contacts),
                'name' => $name,
                'section' => $section,
                'is_available' => ! in_array('unavailable', (array) $contacts, true),
            ]);
        }
    }

    /**
     * Split the way the announcements write contacts — a Telegram handle, a
     * profile URL, a Saudi mobile number, or one of each — into the two
     * columns. Anything that is only digits and phone punctuation is a number;
     * everything else is a Telegram handle.
     *
     * @param  array<int, string>  $contacts
     * @return array{telegram_username: ?string, whatsapp_number: ?string}
     */
    private function contactColumns(array $contacts): array
    {
        $columns = ['telegram_username' => null, 'whatsapp_number' => null];

        foreach ($contacts as $contact) {
            if ($contact === 'unavailable') {
                continue;
            }

            if (preg_match('/^[\d\s+\-()]+$/', $contact) === 1) {
                $columns['whatsapp_number'] = $contact;

                continue;
            }

            $columns['telegram_username'] = $contact;
        }

        return $columns;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cohorts(): array
    {
        return [$this->cohort48(), $this->cohort4647(), $this->cohort47()];
    }

    /**
     * @return array<string, mixed>
     */
    private function cohort48(): array
    {
        $men = SupervisorSection::Men;
        $women = SupervisorSection::Women;

        return [
            'name' => 'دفعة ٤٨',
            'featured' => true,
            'description' => 'مبارك لكم قبولكم في كلية الحاسبات بجامعة أم القرى. تواصل مع أحد مشرفي تخصصك للانضمام إلى قروب دفعتك.',
            'note' => 'أرقام الجوال مخصصة للتواصل عبر الواتساب فقط.',
            'requirements' => [
                'صورة القبول النهائي من البوابة الأكاديمية، حديثة وواضحة وغير مقصوصة',
                'اسم الطالب ظاهر في الصورة',
                'الفرع ظاهر في الصورة',
                'اسم التخصص ظاهر في الصورة',
            ],
            'groups' => [
                [
                    'major' => null,
                    'branch' => null,
                    'supervisors' => array_map(
                        fn (array $entry) => [SupervisorSection::Both, $entry[0], [$entry[1]]],
                        [
                            ['أنس', 'itsanas121'],
                            ['احمد', 'AHMAD_halabi'],
                            ['iiu_p3', 'iiu_p3'],
                            ['Rosee4511', 'Rosee4511'],
                            ['Abtlli', 'Abtlli'],
                            ['بسمة', 'ImBasmah'],
                            ['حنين', 'Han2006N'],
                            ['ريتاج', 'lluviia1'],
                            ['رغد', 'Ryrah_05'],
                            ['atimenf', 'atimenf'],
                            ['الكاتو', 'ElCatoCS'],
                        ],
                    ),
                ],
                [
                    'major' => Major::ComputerScience,
                    'branch' => Branch::Main,
                    'supervisors' => [
                        [$women, 'نوره الوذيناني', ['0550778675']],
                        [$women, 'تالا', ['0545467248']],
                        [$women, 'دانه الحازمي', ['0555732116']],
                        [$women, 'الين الحربي', ['0541505886']],
                        [$men, 'مشعل حكمي', ['0555698092']],
                        [$men, 'علي الزهراني', ['0552967048']],
                        [$men, 'مازن الغامدي', ['0507093031']],
                        [$men, 'سعد زاهر', ['0559733471']],
                        [$men, 'أنس المحمادي', ['0507487697']],
                    ],
                ],
                [
                    'major' => Major::ComputerEngineering,
                    'branch' => Branch::Main,
                    'supervisors' => [
                        [$women, 'ريماس فرج', ['0502453253']],
                        [$women, 'اميرة باسم', ['0567577567']],
                        [$women, 'لمار الدعدي', ['0564356097']],
                        [$women, 'آمال مليباري', ['mele_a4']],
                        [$women, 'ليان المعتاز', ['Layan2_5_0']],
                        [$men, 'محمود القنطار', ['PPPDL']],
                        [$men, 'ناصر', ['0504425647']],
                        [$men, 'آدم', ['0568419622']],
                        [$men, 'نواف', ['bcyuiv']],
                    ],
                ],
                [
                    'major' => Major::SoftwareEngineering,
                    'branch' => Branch::Main,
                    'supervisors' => [
                        [$women, 'فاطمه القرشي', ['0581378432']],
                        [$women, 'عاليه العتيبي', ['0558130545']],
                        [$women, 'فرح مياجان', ['0550431040']],
                        [$women, 'طيبه ازهري', ['0535283343']],
                        [$women, 'ليان فيصل', ['0566365764']],
                        [$women, 'رندا الغامدي', ['0555015085']],
                        [$women, 'اسماء مليباري', ['0530133115']],
                        [$men, 'زياد إمام', ['0508457413']],
                        [$men, 'سلطان الفيفي', ['0503990106']],
                        [$men, 'عبدالعزيز المطرفي', ['0534435949']],
                        [$men, 'شادي رزق', ['0503197195']],
                    ],
                ],
                [
                    'major' => Major::Cybersecurity,
                    'branch' => Branch::Main,
                    'supervisors' => [
                        [$women, 'جنى قضماني', ['0505535274']],
                        [$women, 'شذا عبدالله العتيبي', ['0533401825']],
                        [$women, 'لين تركي الجعيد', ['0530218734']],
                        [$women, 'آمنه محمد سوداقر', ['0551593881']],
                        [$women, 'ساره احمد مسملي', ['0502375458']],
                        [$men, 'حمود المطرفي', ['0556355700']],
                        [$men, 'باسم مجرشي', ['0598270271']],
                        [$men, 'عبدالله العتيبي', ['0507056671']],
                        [$men, 'مهند الشريف', ['0557712482']],
                    ],
                ],
                [
                    'major' => Major::ArtificialIntelligence,
                    'branch' => Branch::Main,
                    'supervisors' => [
                        [$women, 'منار تركي', ['me11_8r']],
                        [$women, 'خلود المسعودي', ['0558913196']],
                        [$women, 'هادن الشّعار', ['0500096280']],
                        [$women, 'ميرال باوارث', ['me76roo']],
                        [$women, 'ليان الثبيتي', ['mnbvc_99']],
                        [$women, 'مريم اللقماني', ['0544051059']],
                        [$men, 'خالد الدمح', ['kllh99']],
                        [$men, 'ناصر النميري', ['0595583572']],
                        [$men, 'صهيب بخش', ['0599023675']],
                    ],
                ],
                [
                    'major' => Major::DataScience,
                    'branch' => Branch::Main,
                    'supervisors' => [
                        [$women, 'الاء الشهري', ['0531003707']],
                        [$women, 'عطاء الهوساوي', ['0572178236']],
                        [$women, 'حلا محسن', ['0544665418']],
                        [$women, 'ريتاج قدح', ['lluviia1']],
                        [$women, 'لميس الغامدي', ['0554143168']],
                        [$women, 'سارة الحارثي', ['0536936795']],
                        [$men, 'أحمد حلبي', ['0504777626']],
                        [$men, 'عبدالعزيز السلمي', ['0564813867']],
                        [$men, 'طارق العتيبي', ['0502884346']],
                        [$men, 'يوسف عمار', ['0509610800']],
                    ],
                ],
                [
                    'major' => Major::HumanComputerInteraction,
                    'branch' => Branch::Main,
                    'supervisors' => [
                        [$women, 'شذى سبعي', ['0595903305']],
                        [$women, 'عزوف العسيري', ['0534317502']],
                        [$women, 'جوري الشريف', ['0545272443']],
                        [$women, 'رحمة الشريف', ['0504676518']],
                        [$men, 'خالد النافع', ['0561374797']],
                        [$men, 'أيمن ملا', ['0547472269']],
                        [$men, 'رائف خالد', ['0557303473']],
                        [$men, 'حسان شهاوي', ['0502815989']],
                    ],
                ],
                [
                    'major' => Major::ComputerScience,
                    'branch' => Branch::Qunfudah,
                    'supervisors' => [
                        [$women, 'أثير الكناني', ['0539702502']],
                        [$women, 'شذى المنتشري', ['0552811529']],
                        [$women, 'كوثر المعيدي', ['0556148354']],
                        [$men, 'عبدالرحمن القرني', ['0531428870']],
                    ],
                ],
                [
                    'major' => Major::ComputerEngineering,
                    'branch' => Branch::Qunfudah,
                    'supervisors' => [
                        [$men, 'أحمد', ['0598889490']],
                    ],
                ],
                [
                    'major' => Major::ComputerScience,
                    'branch' => Branch::Jamoum,
                    'supervisors' => [
                        [$women, 'روابي', ['ltivxx']],
                        [$women, 'جودي', ['J511oodi']],
                        [$women, 'ريماس', ['rlexv']],
                        [$women, 'أبرار', ['b_eijwp']],
                        [$men, 'مازن فقيهي', ['0531225513']],
                        [$men, 'محمد نور', ['0566864077']],
                        [$men, 'نواف', ['DZ_qY']],
                        [$men, 'أنس ميمش', ['NeerdAnas']],
                    ],
                ],
                [
                    'major' => Major::ComputerScience,
                    'branch' => Branch::Adham,
                    'supervisors' => [
                        [$women, 'رغد', ['Ryrah_05']],
                        [$women, 'ظي', ['X44dhai']],
                        [$women, 'جود', ['jllasoo']],
                    ],
                ],
                [
                    'major' => Major::ComputerScience,
                    'branch' => Branch::Layth,
                    'supervisors' => [
                        [$women, 'رغد', ['Ryrah_05']],
                        [$women, 'بسمة', ['ImBasmah']],
                        [$women, 'أسيل', ['httpsAseel']],
                        [$women, 'نادية', ['Yooopiy']],
                        [$men, 'محمد', ['l8lrx']],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cohort4647(): array
    {
        $men = SupervisorSection::Men;
        $women = SupervisorSection::Women;

        return [
            'name' => 'دفعة ٤٦ و٤٧',
            'featured' => false,
            'description' => 'قروبات تخصصات كلية الحاسبات لدفعتَي ٤٦ و٤٧. ادخل على قروب تخصصك الخاص بشطرك وفرعك لمتابعة أحدث المستجدات.',
            'note' => 'التزم بوسيلة التواصل المحددة لكل مشرف. وإن لم يصلك رد لفترة طويلة، تواصل مع مشرف آخر.',
            'requirements' => [
                'صورة من إشعار القبول',
                'اسم الطالب',
                'رقم الجوال',
                'اسم التخصص',
                'رقم الدفعة (٤٦ أو ٤٧)',
            ],
            'groups' => [
                [
                    'major' => Major::ComputerScience,
                    'branch' => Branch::Main,
                    'supervisors' => [
                        [$men, 'أنس المحمادي', ['0507487697']],
                        [$men, 'بدر الياسي', ['0555032750']],
                        [$men, 'أبوبكر الشريف', ['0507552313']],
                        [$women, 'أريام فاضل', ['0555282273']],
                        [$women, 'ساره المطرفي', ['0502319043']],
                        [$women, 'جمانه الهذلي', ['0597017561']],
                        [$women, 'مشاعل شاكر', ['0553034473']],
                    ],
                ],
                [
                    'major' => Major::ComputerEngineering,
                    'branch' => Branch::Main,
                    'supervisors' => [
                        [$men, 'محمد', ['CNE_MOH']],
                        [$men, 'ناصر', ['0504425647']],
                        [$women, 'ريماس فرج', ['0502453253']],
                        [$women, 'ماريا العلوي', ['0554361289']],
                        [$women, 'ريماس عجيمي', ['0570301977']],
                        [$women, 'جنى العباسي', ['0531505666']],
                        [$women, 'اميرة حسنين', ['0567577567']],
                    ],
                ],
                [
                    'major' => Major::SoftwareEngineering,
                    'branch' => Branch::Main,
                    'supervisors' => [
                        [$men, 'شادي رزق', ['0503197195']],
                        [$men, 'الكاتو', ['0501321169']],
                        [$men, 'يزن عبدالله', ['0553501426']],
                        [$men, 'عبدالرحمن العساف', ['0555086910']],
                        [$women, 'رندا', ['0555015085']],
                        [$women, 'رغد', ['0559323241']],
                        [$women, 'سماهر', ['0508051851']],
                        [$women, 'لينا', ['0570297830']],
                    ],
                ],
                [
                    'major' => Major::Cybersecurity,
                    'branch' => Branch::Main,
                    'supervisors' => [
                        [$men, 'سلمان قاري', ['0582840478']],
                        [$men, 'انس طيب', ['0559746714']],
                        [$men, 'اوس مالكي', ['0544750058']],
                        [$women, 'رنيم بكور', ['0539790523']],
                        [$women, 'جوري الحربي', ['0566460724']],
                        [$women, 'رزان المالكي', ['0555651401']],
                    ],
                ],
                [
                    'major' => Major::ArtificialIntelligence,
                    'branch' => Branch::Main,
                    'supervisors' => [
                        [$men, 'خالد الدمح', ['0541611300']],
                        [$men, 'حسن اخرس', ['0556022074']],
                        [$men, 'سهيل الخطابي', ['0508416329']],
                        [$men, 'نواف باربود', ['0530398424']],
                        [$women, 'بسمة محمد', ['0503861243']],
                        [$women, 'دينا يوسف', ['Dina_1x']],
                        [$women, 'مودة', ['0560366479']],
                        [$women, 'سديم', ['0564228616']],
                        [$women, 'رواف', ['0581138300', 'Rori_chan_0']],
                    ],
                ],
                [
                    'major' => Major::DataScience,
                    'branch' => Branch::Main,
                    'supervisors' => [
                        [$men, 'يوسف عمار', ['0509610800']],
                        [$men, 'عبد الرحمن المعيقلي', ['0505377040']],
                        [$men, 'أحمد حلبي', ['0504777626']],
                        [$women, 'جوري الكناني', ['0530563997']],
                        [$women, 'سارا نايف', ['0550353751']],
                        [$women, 'مروج دخيل الله', ['0501818757']],
                        [$women, 'نوره المسعودي', ['0535093398']],
                    ],
                ],
                [
                    'major' => Major::HumanComputerInteraction,
                    'branch' => Branch::Main,
                    'supervisors' => [
                        [$men, 'خالد النافع', ['0561374797']],
                        [$men, 'محمود فراش', ['0569151634']],
                        [$men, 'عبد الرحمن الشريف', ['0544544705']],
                        [$men, 'زياد', ['0553507014']],
                        [$women, 'شذى سبعي', ['0562835744']],
                        [$women, 'اسماء الكبكبي', ['0538019130']],
                        [$women, 'اسراء صُبغة', ['0541633179']],
                        [$women, 'جمانة الشريف', ['0565501647']],
                    ],
                ],
                [
                    'major' => Major::ComputerScience,
                    'branch' => Branch::Jamoum,
                    'supervisors' => [
                        [$men, 'مازن', ['0531225513']],
                        [$women, 'شهد الحازمي', ['0565563848']],
                        [$women, 'شذى', ['reru_i']],
                        [$women, 'ربى', ['0538664143']],
                        [$women, 'راي', ['ltivxx']],
                        [$women, 'ريماس الاحمدي', ['0559695700']],
                        [$women, 'ندى', ['NiiiiDA']],
                    ],
                ],
                [
                    'major' => Major::ComputerScience,
                    'branch' => Branch::Qunfudah,
                    'supervisors' => [
                        [$men, 'أحمد', ['0598889490']],
                        [$men, 'أنس', ['0507487697']],
                        [$women, 'شوق', ['0557984189']],
                        [$women, 'أثير', ['0539702502']],
                        [$women, 'جود', ['0550980824']],
                    ],
                ],
                [
                    'major' => Major::ComputerEngineering,
                    'branch' => Branch::Qunfudah,
                    'supervisors' => [
                        [$men, 'أحمد', ['0598889490']],
                        [$men, 'عبدالرحمن', ['0554588617']],
                        [$men, 'حسن', ['0538331543']],
                    ],
                ],
                [
                    'major' => Major::ComputerScience,
                    'branch' => Branch::Layth,
                    'supervisors' => [
                        [$men, 'خالد الجمل', ['k_0aj']],
                        [$women, 'بسمة', ['ImBasmah']],
                        [$women, 'اريام', ['liiar11']],
                        [$women, 'عهود', ['x_laq1i']],
                        [$women, 'حنين', ['Han2006N']],
                    ],
                ],
                [
                    'major' => Major::ComputerScience,
                    'branch' => Branch::Adham,
                    'supervisors' => [
                        [$women, 'رغد', ['Ryrah_05']],
                        [$women, 'ظي', ['X44dhai']],
                        [$women, 'أثير', ['0539702502']],
                    ],
                ],
            ],
        ];
    }

    /**
     * The list the standalone دفعة ٤٧ page carried. The names it kept commented
     * out come across as unavailable rather than missing — that flag is exactly
     * what the comments were standing in for.
     *
     * @return array<string, mixed>
     */
    private function cohort47(): array
    {
        $men = SupervisorSection::Men;
        $women = SupervisorSection::Women;

        return [
            'name' => 'دفعة ٤٧',
            'featured' => false,
            'description' => 'قروب تيليجرام لدفعة ٤٧ في كلية الحاسبات. القروب مختلف عن الواتساب لكنه لنفس الغرض: التعاون والإفادة في الدراسة، ومستمر إلى التخرج بمشيئة الله.',
            'note' => null,
            'requirements' => [
                'صورة من البوابة الأكاديمية',
                'الاسم الأول ظاهر في الصورة',
                'اسم التخصص ظاهر في الصورة',
                'رقم الدفعة ظاهر في الصورة',
            ],
            'groups' => [
                [
                    'major' => null,
                    'branch' => null,
                    'supervisors' => [
                        [$men, 'يوسف', ['ysf_arr']],
                        [$men, 'حمود', ['CID_18']],
                        [$men, 'احمد', ['AHMAD_halabi']],
                        [$men, 'أنس', ['itsanas121']],
                        [$men, 'الكاتو', ['ElCatoCS']],
                        [$men, 'ناصر', ['nasser1l']],
                        [$men, 'شادي', ['irzq2', 'unavailable']],
                        [$women, 'أثير', ['giv22']],
                        [$women, 'بَنان', ['e_bananarab']],
                        [$women, 'فاطمة', ['ff0ffy']],
                        [$women, 'فجر', ['apollocirii']],
                        [$women, 'رغد', ['Ryrah_05', 'unavailable']],
                        [$women, 'عهود', ['x_laq1i', 'unavailable']],
                        [$women, 'حنين', ['Han2006N', 'unavailable']],
                        [$women, 'دُرر', ['vnixu', 'unavailable']],
                        [$women, 'بيسال', ['Bessal2', 'unavailable']],
                    ],
                ],
            ],
        ];
    }
};
