<?php

use App\Models\StudentGroup\Cohort;
use App\Models\StudentGroup\GroupSupervisor;
use App\Models\StudentGroup\StudentGroup;
use App\Models\User;
use Database\Factories\StudentGroup\CohortFactory;
use Database\Factories\StudentGroup\GroupSupervisorFactory;
use Database\Factories\StudentGroup\StudentGroupFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

it('starts empty — the content import never runs against a test database', function () {
    expect(Cohort::query()->count())->toBe(0)
        ->and(StudentGroup::query()->count())->toBe(0)
        ->and(GroupSupervisor::query()->count())->toBe(0);
});

describe('authorization', function () {
    it('redirects guests to the login page', function () {
        $this->get('/manage/cohorts')->assertRedirect(route('manage.login'));
    });

    it('lets editors open the cohorts workspace — the area is not behind its own permission', function () {
        $this->actingAs($this->editor)->get('/manage/cohorts')->assertOk();
    });

    it('lets editors manage cohorts — the area is not behind its own permission', function () {
        $cohort = CohortFactory::new()->create();

        $this->actingAs($this->editor);

        $this->get("/manage/cohorts/{$cohort->id}")->assertOk();
        $this->post('/manage/cohorts', ['name' => 'دفعة ٤٩'])->assertSessionHasNoErrors();
        $this->put("/manage/cohorts/{$cohort->id}", ['name' => 'جديد'])->assertSessionHasNoErrors();
        $this->post('/manage/cohorts/reorder', ['ids' => [$cohort->id]])->assertSessionHasNoErrors();
        $this->delete("/manage/cohorts/{$cohort->id}")->assertSessionHasNoErrors();

        expect(Cohort::query()->where('name', 'دفعة ٤٩')->exists())->toBeTrue();
    });

    it('allows admins to open the cohorts workspace', function () {
        $this->actingAs($this->admin)->get('/manage/cohorts')->assertOk();
    });
});

describe('index', function () {
    it('counts groups and supervisors across the whole intake', function () {
        $cohort = CohortFactory::new()->create(['name' => 'دفعة ٤٨']);
        $general = StudentGroupFactory::new()->forCohort($cohort)->general()->create();
        $specialized = StudentGroupFactory::new()->forCohort($cohort)->create();

        GroupSupervisorFactory::new()->forGroup($general)->count(2)->create();
        GroupSupervisorFactory::new()->forGroup($specialized)->create();
        GroupSupervisorFactory::new()->forGroup($specialized)->unavailable()->create();

        $this->actingAs($this->admin)->get('/manage/cohorts')->assertInertia(fn (Assert $page) => $page
            ->component('manage/groups/Index')
            ->count('cohorts', 1)
            ->where('cohorts.0.name', 'دفعة ٤٨')
            ->where('cohorts.0.groups_count', 2)
            ->where('cohorts.0.supervisors_count', 4)
            ->where('cohorts.0.available_supervisors_count', 3)
        );
    });

    it('orders cohorts by their order column', function () {
        $first = CohortFactory::new()->create();
        $second = CohortFactory::new()->create();

        $second->update(['order' => 0]);

        $this->actingAs($this->admin)->get('/manage/cohorts')->assertInertia(fn (Assert $page) => $page
            ->where('cohorts.0.id', $second->id)
            ->where('cohorts.1.id', $first->id)
        );
    });
});

describe('show', function () {
    it('ships the intake, its groups with supervisors, and the taxonomy the pickers need', function () {
        $cohort = CohortFactory::new()->create(['requirements' => ['صورة من البوابة']]);
        $group = StudentGroupFactory::new()->forCohort($cohort)->create();

        GroupSupervisorFactory::new()->forGroup($group)->create(['name' => 'أحمد', 'telegram_username' => 'ahmad_x']);

        $this->actingAs($this->admin)->get("/manage/cohorts/{$cohort->id}")->assertInertia(fn (Assert $page) => $page
            ->component('manage/groups/Show')
            ->where('cohort.name', $cohort->name)
            ->where('cohort.requirements', ['صورة من البوابة'])
            ->count('groups', 1)
            ->where('groups.0.name', 'علوم الحاسب الآلي')
            ->where('groups.0.branch_label', 'الفرع الرئيسي — مكة المكرمة')
            ->where('groups.0.is_general', false)
            ->count('groups.0.supervisors', 1)
            ->where('groups.0.supervisors.0.name', 'أحمد')
            ->where('groups.0.supervisors.0.contacts.0.url', 'https://t.me/ahmad_x')
            ->count('taxonomy.majors', 7)
            ->count('taxonomy.branches', 5)
            ->count('taxonomy.sections', 3)
        );
    });

    it('names a group with no major as the general one', function () {
        $cohort = CohortFactory::new()->create();
        StudentGroupFactory::new()->forCohort($cohort)->general()->create();

        $this->actingAs($this->admin)->get("/manage/cohorts/{$cohort->id}")->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.name', 'القروب العام')
            ->where('groups.0.is_general', true)
            ->where('groups.0.branch_label', 'كل الفروع')
        );
    });

    it('includes unavailable supervisors so they can be switched back on', function () {
        $cohort = CohortFactory::new()->create();
        $group = StudentGroupFactory::new()->forCohort($cohort)->create();
        GroupSupervisorFactory::new()->forGroup($group)->unavailable()->create(['name' => 'موقوف']);

        $this->actingAs($this->admin)->get("/manage/cohorts/{$cohort->id}")->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.supervisors.0.name', 'موقوف')
            ->where('groups.0.supervisors.0.is_available', false)
        );
    });
});

describe('create', function () {
    it('creates a cohort and assigns it the next order', function () {
        CohortFactory::new()->create();

        $response = $this->actingAs($this->admin)
            ->from('/manage/cohorts')
            ->post('/manage/cohorts', [
                'name' => 'دفعة ٤٩',
                'description' => 'قروبات الدفعة الجديدة',
                'note' => 'أرقام الجوال للواتساب فقط.',
                'requirements' => ['صورة القبول', 'رقم الدفعة'],
                'is_active' => true,
                'is_featured' => true,
            ]);

        $response->assertRedirect('/manage/cohorts');
        $response->assertSessionHasNoErrors();

        $cohort = Cohort::query()->where('name', 'دفعة ٤٩')->first();
        expect($cohort)->not->toBeNull()
            ->and($cohort->note)->toBe('أرقام الجوال للواتساب فقط.')
            ->and($cohort->requirements)->toBe(['صورة القبول', 'رقم الدفعة'])
            ->and($cohort->is_featured)->toBeTrue()
            ->and($cohort->order)->toBe(2);
    });

    it('stores an all-blank checklist as no checklist at all', function () {
        $this->actingAs($this->admin)
            ->post('/manage/cohorts', ['name' => 'بلا شروط', 'requirements' => ['   ']])
            ->assertSessionHasNoErrors();

        expect(Cohort::query()->where('name', 'بلا شروط')->first()->requirements)->toBeNull();
    });

    it('rejects a cohort without a name', function () {
        $this->actingAs($this->admin)
            ->post('/manage/cohorts', ['name' => ''])
            ->assertSessionHasErrors(['name' => 'حقل اسم الدفعة مطلوب.']);
    });

    it('rejects more than ten requirements', function () {
        $this->actingAs($this->admin)
            ->post('/manage/cohorts', ['name' => 'دفعة', 'requirements' => array_fill(0, 11, 'شرط')])
            ->assertSessionHasErrors(['requirements' => 'يجب ألا تتجاوز الشروط ١٠ عناصر.']);
    });
});

describe('update', function () {
    it('accepts a visibility-only payload without touching the rest', function () {
        $cohort = CohortFactory::new()->create(['name' => 'دفعة ٤٨']);

        $this->actingAs($this->admin)
            ->put("/manage/cohorts/{$cohort->id}", ['is_active' => false])
            ->assertSessionHasNoErrors();

        expect($cohort->fresh())
            ->is_active->toBeFalse()
            ->name->toBe('دفعة ٤٨')
            ->requirements->toBe($cohort->requirements);
    });

    it('rejects blanking the name', function () {
        $cohort = CohortFactory::new()->create();

        $this->actingAs($this->admin)
            ->put("/manage/cohorts/{$cohort->id}", ['name' => ''])
            ->assertSessionHasErrors(['name' => 'حقل اسم الدفعة مطلوب.']);
    });
});

describe('delete', function () {
    it('keeps a group another cohort still shares, and drops the rest', function () {
        $cohort = CohortFactory::new()->create();
        $other = CohortFactory::new()->create();

        $shared = StudentGroupFactory::new()->forCohort($cohort, $other)->create();
        $ownOnly = StudentGroupFactory::new()->forCohort($cohort)->general()->create();
        GroupSupervisorFactory::new()->forGroup($shared)->create();
        GroupSupervisorFactory::new()->forGroup($ownOnly)->create();

        $this->actingAs($this->admin)->delete("/manage/cohorts/{$cohort->id}")->assertRedirect('/manage/cohorts');

        expect(StudentGroup::query()->pluck('id')->all())->toBe([$shared->id])
            ->and(GroupSupervisor::query()->count())->toBe(1)
            ->and($other->groups()->count())->toBe(1);
    });

    it('deletes a cohort along with its groups and their supervisors', function () {
        $cohort = CohortFactory::new()->create();
        $group = StudentGroupFactory::new()->forCohort($cohort)->create();
        GroupSupervisorFactory::new()->forGroup($group)->count(2)->create();

        $this->actingAs($this->admin)
            ->delete("/manage/cohorts/{$cohort->id}")
            ->assertRedirect('/manage/cohorts');

        expect(Cohort::query()->count())->toBe(0)
            ->and(StudentGroup::query()->count())->toBe(0)
            ->and(GroupSupervisor::query()->count())->toBe(0);
    });
});

describe('reorder', function () {
    it('persists a new order', function () {
        $first = CohortFactory::new()->create();
        $second = CohortFactory::new()->create();

        $this->actingAs($this->admin)
            ->post('/manage/cohorts/reorder', ['ids' => [$second->id, $first->id]])
            ->assertSessionHasNoErrors();

        expect($second->fresh()->order)->toBe(1)
            ->and($first->fresh()->order)->toBe(2);
    });

    it('rejects an unknown id', function () {
        $this->actingAs($this->admin)
            ->post('/manage/cohorts/reorder', ['ids' => [999]])
            ->assertSessionHasErrors(['ids.0' => 'إحدى الدفعات في قائمة الترتيب غير موجودة.']);
    });
});

describe('cache invalidation', function () {
    it('flushes the public payload on every cohort write', function () {
        $cohort = CohortFactory::new()->create();

        foreach ([
            fn () => $this->post('/manage/cohorts', ['name' => 'دفعة أخرى']),
            fn () => $this->put("/manage/cohorts/{$cohort->id}", ['name' => 'اسم محدّث']),
            fn () => $this->post('/manage/cohorts/reorder', ['ids' => [$cohort->id]]),
            fn () => $this->delete("/manage/cohorts/{$cohort->id}"),
        ] as $write) {
            Cache::forever(Cohort::CACHE_KEY, ['stale']);

            $this->actingAs($this->admin);
            $write();

            expect(Cache::get(Cohort::CACHE_KEY))->toBeNull();
        }
    });
});
