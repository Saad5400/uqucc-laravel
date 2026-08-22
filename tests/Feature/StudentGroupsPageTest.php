<?php

use App\Models\StudentGroup\Branch;
use App\Models\StudentGroup\Major;
use Database\Factories\PageFactory;
use Database\Factories\StudentGroup\CohortFactory;
use Database\Factories\StudentGroup\GroupSupervisorFactory;
use Database\Factories\StudentGroup\StudentGroupFactory;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
});

it('renders with no cohorts at all', function () {
    $response = $this->get('/qroubat');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('StudentGroupsPage')
        ->where('cohorts', [])
        ->where('hasContent', false)
        ->where('seo.title', 'قروبات الدفعات')
    );
});

it('asks crawlers to stay out, since the page is unlisted', function () {
    $this->get('/qroubat')->assertInertia(fn (Assert $page) => $page->where('seo.noindex', true));
});

it('is reachable by URL but linked from nowhere', function () {
    // No Page record means no navigation entry, no sitemap entry and no search index.
    $this->get('/qroubat')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('page', null)
        ->where('navigation', [])
    );
});

it('puts the featured intake first, whatever its order column says', function () {
    CohortFactory::new()->create(['name' => 'دفعة ٤٧']);
    CohortFactory::new()->featured()->create(['name' => 'دفعة ٤٨']);

    $this->get('/qroubat')->assertInertia(fn (Assert $page) => $page
        ->count('cohorts', 2)
        ->where('cohorts.0.name', 'دفعة ٤٨')
        ->where('cohorts.0.is_featured', true)
        ->where('cohorts.1.name', 'دفعة ٤٧')
    );
});

it('ships the intake prose every one of its groups shares', function () {
    CohortFactory::new()->create([
        'name' => 'دفعة ٤٨',
        'description' => 'قروبات الدفعة',
        'note' => 'أرقام الجوال للواتساب فقط.',
        'requirements' => ['صورة القبول', '', 'رقم الدفعة'],
    ]);

    $this->get('/qroubat')->assertInertia(fn (Assert $page) => $page
        ->where('cohorts.0.description', 'قروبات الدفعة')
        ->where('cohorts.0.note', 'أرقام الجوال للواتساب فقط.')
        ->where('cohorts.0.requirements', ['صورة القبول', 'رقم الدفعة'])
    );
});

it('names a group by its programme and marks the general one', function () {
    $cohort = CohortFactory::new()->create();
    $general = StudentGroupFactory::new()->forCohort($cohort)->general()->create();
    $specialized = StudentGroupFactory::new()->forCohort($cohort)
        ->major(Major::Cybersecurity)->branch(Branch::Jamoum)->create();

    GroupSupervisorFactory::new()->forGroup($general)->bothSections()->create();
    GroupSupervisorFactory::new()->forGroup($specialized)->create();

    $this->get('/qroubat')->assertInertia(fn (Assert $page) => $page
        ->count('cohorts.0.groups', 2)
        ->where('cohorts.0.groups.0.name', 'القروب العام')
        ->where('cohorts.0.groups.0.is_general', true)
        ->where('cohorts.0.groups.0.branch_label', 'كل الفروع')
        ->where('cohorts.0.groups.0.sections.0.key', 'both')
        ->where('cohorts.0.groups.0.sections.0.label', 'للشطرين')
        ->where('cohorts.0.groups.1.name', 'الأمن السيبراني')
        ->where('cohorts.0.groups.1.is_general', false)
        ->where('cohorts.0.groups.1.major', 'cybersecurity')
        ->where('cohorts.0.groups.1.branch', 'jamoum')
        ->where('cohorts.0.groups.1.branch_label', 'فرع الجموم')
    );
});

it('buckets supervisors by section with every way to reach them', function () {
    $cohort = CohortFactory::new()->create();
    $group = StudentGroupFactory::new()->forCohort($cohort)->create();

    GroupSupervisorFactory::new()->forGroup($group)->create(['name' => 'يوسف', 'telegram_username' => 'ysf_arr']);
    GroupSupervisorFactory::new()->forGroup($group)->women()->whatsappOnly('0507487697')->create(['name' => 'نوره']);

    $this->get('/qroubat')->assertInertia(fn (Assert $page) => $page
        ->count('cohorts.0.groups.0.sections', 2)
        ->where('cohorts.0.groups.0.sections.0.label', 'شطر الطلاب')
        ->where('cohorts.0.groups.0.sections.0.supervisors.0.name', 'يوسف')
        ->where('cohorts.0.groups.0.sections.0.supervisors.0.contacts.0.kind', 'telegram')
        ->where('cohorts.0.groups.0.sections.0.supervisors.0.contacts.0.url', 'https://t.me/ysf_arr')
        ->where('cohorts.0.groups.0.sections.1.label', 'شطر الطالبات')
        ->where('cohorts.0.groups.0.sections.1.supervisors.0.contacts.0.kind', 'whatsapp')
        ->where('cohorts.0.groups.0.sections.1.supervisors.0.contacts.0.handle', '0507487697')
        ->where('cohorts.0.groups.0.sections.1.supervisors.0.contacts.0.url', 'https://wa.me/966507487697')
    );
});

it('omits a section nobody is available in', function () {
    $cohort = CohortFactory::new()->create();
    $group = StudentGroupFactory::new()->forCohort($cohort)->create();
    GroupSupervisorFactory::new()->forGroup($group)->create();

    $this->get('/qroubat')->assertInertia(fn (Assert $page) => $page
        ->count('cohorts.0.groups.0.sections', 1)
        ->where('cohorts.0.groups.0.sections.0.key', 'men')
    );
});

it('hides inactive cohorts and inactive groups', function () {
    CohortFactory::new()->inactive()->create(['name' => 'دفعة متخرجة']);
    $cohort = CohortFactory::new()->create(['name' => 'دفعة ٤٨']);
    StudentGroupFactory::new()->forCohort($cohort)->major(Major::DataScience)->create();
    StudentGroupFactory::new()->forCohort($cohort)->major(Major::Cybersecurity)->inactive()->create();

    $this->get('/qroubat')->assertInertia(fn (Assert $page) => $page
        ->count('cohorts', 1)
        ->where('cohorts.0.name', 'دفعة ٤٨')
        ->count('cohorts.0.groups', 1)
        ->where('cohorts.0.groups.0.name', 'علم البيانات')
    );
});

it('leaves unavailable supervisors out of the rotation', function () {
    $cohort = CohortFactory::new()->create();
    $group = StudentGroupFactory::new()->forCohort($cohort)->create();
    GroupSupervisorFactory::new()->forGroup($group)->create(['name' => 'متاح']);
    GroupSupervisorFactory::new()->forGroup($group)->unavailable()->create(['name' => 'موقوف']);

    $this->get('/qroubat')->assertInertia(fn (Assert $page) => $page
        ->where('cohorts.0.groups.0.supervisors_count', 1)
        ->count('cohorts.0.groups.0.sections.0.supervisors', 1)
        ->where('cohorts.0.groups.0.sections.0.supervisors.0.name', 'متاح')
    );
});

it('keeps a group with nobody available, so the page still says it exists', function () {
    $cohort = CohortFactory::new()->create();
    $group = StudentGroupFactory::new()->forCohort($cohort)->create();
    GroupSupervisorFactory::new()->forGroup($group)->unavailable()->create();

    $this->get('/qroubat')->assertInertia(fn (Assert $page) => $page
        ->count('cohorts.0.groups', 1)
        ->where('cohorts.0.groups.0.sections', [])
    );
});

it('orders groups and supervisors by their order columns', function () {
    $cohort = CohortFactory::new()->create();
    $first = StudentGroupFactory::new()->forCohort($cohort)->major(Major::ComputerScience)->create();
    $second = StudentGroupFactory::new()->forCohort($cohort)->major(Major::DataScience)->create();
    $second->update(['order' => 0]);

    $early = GroupSupervisorFactory::new()->forGroup($first)->create(['name' => 'مبكر']);
    $late = GroupSupervisorFactory::new()->forGroup($first)->create(['name' => 'متأخر']);
    $late->update(['order' => 0]);

    $this->get('/qroubat')->assertInertia(fn (Assert $page) => $page
        ->where('cohorts.0.groups.0.name', 'علم البيانات')
        ->where('cohorts.0.groups.1.name', 'علوم الحاسب الآلي')
        ->where('cohorts.0.groups.1.sections.0.supervisors.0.name', 'متأخر')
        ->where('cohorts.0.groups.1.sections.0.supervisors.1.name', 'مبكر')
    );
});

it('renders the intro content and SEO of the backing page record', function () {
    PageFactory::new()->create([
        'slug' => '/qroubat',
        'title' => 'قروبات الدفعات',
        'html_content' => '<p>كيف تنضم لقروب دفعتك.</p>',
    ]);

    $this->get('/qroubat')->assertInertia(fn (Assert $page) => $page
        ->where('hasContent', true)
        ->where('page.title', 'قروبات الدفعات')
        ->where('seo.title', 'قروبات الدفعات')
        ->where('seo.noindex', true)
    );
});
