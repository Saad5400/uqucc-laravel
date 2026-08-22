<?php

use App\Models\StudentGroup\Branch;
use App\Models\StudentGroup\Cohort;
use App\Models\StudentGroup\GroupSupervisor;
use App\Models\StudentGroup\Major;
use App\Models\StudentGroup\StudentGroup;
use App\Models\User;
use Database\Factories\StudentGroup\CohortFactory;
use Database\Factories\StudentGroup\GroupSupervisorFactory;
use Database\Factories\StudentGroup\StudentGroupFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');

    $this->cohort = CohortFactory::new()->create();
});

describe('authorization', function () {
    it('blocks guests from every group mutation', function () {
        $group = StudentGroupFactory::new()->forCohort($this->cohort)->create();

        $this->post("/manage/cohorts/{$this->cohort->id}/groups", [])->assertRedirect(route('manage.login'));
        $this->put("/manage/groups/{$group->id}", [])->assertRedirect(route('manage.login'));
        $this->delete("/manage/cohorts/{$this->cohort->id}/groups/{$group->id}")->assertRedirect(route('manage.login'));
    });

    it('blocks editors from every group mutation', function () {
        $group = StudentGroupFactory::new()->forCohort($this->cohort)->create();

        $this->actingAs($this->editor);

        $this->post("/manage/cohorts/{$this->cohort->id}/groups", ['major' => 'cybersecurity', 'branch' => 'main'])->assertForbidden();
        $this->put("/manage/groups/{$group->id}", ['is_active' => false])->assertForbidden();
        $this->delete("/manage/cohorts/{$this->cohort->id}/groups/{$group->id}")->assertForbidden();
        $this->post("/manage/cohorts/{$this->cohort->id}/groups/reorder", ['ids' => [$group->id]])->assertForbidden();
    });
});

describe('create', function () {
    it('adds a specialized group to the intake', function () {
        $this->actingAs($this->admin)
            ->post("/manage/cohorts/{$this->cohort->id}/groups", [
                'major' => 'data_science',
                'branch' => 'jamoum',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $group = StudentGroup::query()->with('cohorts')->first();

        expect($group)->not->toBeNull()
            ->and($group->cohorts->pluck('id')->all())->toBe([$this->cohort->id])
            ->and($group->major)->toBe(Major::DataScience)
            ->and($group->branch)->toBe(Branch::Jamoum)
            ->and($group->isGeneral())->toBeFalse()
            ->and($group->displayName())->toBe('علم البيانات');
    });

    it('treats an empty major as the general group', function () {
        $this->actingAs($this->admin)
            ->post("/manage/cohorts/{$this->cohort->id}/groups", ['major' => null, 'branch' => null])
            ->assertSessionHasNoErrors();

        $group = StudentGroup::query()->first();

        expect($group->major)->toBeNull()
            ->and($group->branch)->toBeNull()
            ->and($group->isGeneral())->toBeTrue()
            ->and($group->displayName())->toBe('القروب العام');
    });

    it('rejects a second group for the same major and branch', function () {
        StudentGroupFactory::new()->forCohort($this->cohort)
            ->major(Major::Cybersecurity)->branch(Branch::Main)->create();

        $this->actingAs($this->admin)
            ->post("/manage/cohorts/{$this->cohort->id}/groups", ['major' => 'cybersecurity', 'branch' => 'main'])
            ->assertSessionHasErrors(['major' => 'هذا التخصص مضاف مسبقاً لهذا الفرع في إحدى الدفعات المحددة.']);
    });

    it('rejects a second general group in the same intake', function () {
        StudentGroupFactory::new()->forCohort($this->cohort)->general()->create();

        $this->actingAs($this->admin)
            ->post("/manage/cohorts/{$this->cohort->id}/groups", ['major' => null, 'branch' => null])
            ->assertSessionHasErrors('major');
    });

    it('allows the same major and branch under a different intake', function () {
        StudentGroupFactory::new()->forCohort($this->cohort)
            ->major(Major::Cybersecurity)->branch(Branch::Main)->create();

        $other = CohortFactory::new()->create();

        $this->actingAs($this->admin)
            ->post("/manage/cohorts/{$other->id}/groups", ['major' => 'cybersecurity', 'branch' => 'main'])
            ->assertSessionHasNoErrors();

        expect(StudentGroup::query()->count())->toBe(2);
    });

    it('allows the same major at a different branch', function () {
        StudentGroupFactory::new()->forCohort($this->cohort)
            ->major(Major::ComputerScience)->branch(Branch::Main)->create();

        $this->actingAs($this->admin)
            ->post("/manage/cohorts/{$this->cohort->id}/groups", ['major' => 'computer_science', 'branch' => 'layth'])
            ->assertSessionHasNoErrors();

        expect(StudentGroup::query()->count())->toBe(2);
    });

    it('rejects an unknown major or branch', function () {
        $this->actingAs($this->admin)
            ->post("/manage/cohorts/{$this->cohort->id}/groups", ['major' => 'astrology', 'branch' => 'mars'])
            ->assertSessionHasErrors([
                'major' => 'التخصص المحدد غير صالح.',
                'branch' => 'الفرع المحدد غير صالح.',
            ]);
    });
});

describe('update', function () {
    it('accepts a visibility-only payload without touching the pair', function () {
        $group = StudentGroupFactory::new()->forCohort($this->cohort)
            ->major(Major::SoftwareEngineering)->branch(Branch::Qunfudah)->create();

        $this->actingAs($this->admin)
            ->put("/manage/groups/{$group->id}", ['is_active' => false])
            ->assertSessionHasNoErrors();

        expect($group->fresh())
            ->is_active->toBeFalse()
            ->major->toBe(Major::SoftwareEngineering)
            ->branch->toBe(Branch::Qunfudah);
    });

    it('moves a group to another branch', function () {
        $group = StudentGroupFactory::new()->forCohort($this->cohort)->create();

        $this->actingAs($this->admin)
            ->put("/manage/groups/{$group->id}", ['branch' => 'adham'])
            ->assertSessionHasNoErrors();

        expect($group->fresh()->branch)->toBe(Branch::Adham);
    });

    it('keeps its own pair without tripping the duplicate check', function () {
        $group = StudentGroupFactory::new()->forCohort($this->cohort)
            ->major(Major::DataScience)->branch(Branch::Main)->create();

        $this->actingAs($this->admin)
            ->put("/manage/groups/{$group->id}", ['major' => 'data_science', 'branch' => 'main'])
            ->assertSessionHasNoErrors();
    });

    it('rejects moving onto a pair the intake already has', function () {
        StudentGroupFactory::new()->forCohort($this->cohort)
            ->major(Major::DataScience)->branch(Branch::Main)->create();
        $group = StudentGroupFactory::new()->forCohort($this->cohort)
            ->major(Major::DataScience)->branch(Branch::Layth)->create();

        $this->actingAs($this->admin)
            ->put("/manage/groups/{$group->id}", ['branch' => 'main'])
            ->assertSessionHasErrors('major');
    });
});

describe('delete', function () {
    it('deletes a group along with its supervisors when only this intake holds it', function () {
        $group = StudentGroupFactory::new()->forCohort($this->cohort)->create();
        GroupSupervisorFactory::new()->forGroup($group)->count(3)->create();

        $this->actingAs($this->admin)
            ->delete("/manage/cohorts/{$this->cohort->id}/groups/{$group->id}")
            ->assertSessionHasNoErrors();

        expect(StudentGroup::query()->count())->toBe(0)
            ->and(GroupSupervisor::query()->count())->toBe(0);
    });

    it('only detaches a group another intake still shares', function () {
        $other = CohortFactory::new()->create();
        $group = StudentGroupFactory::new()->forCohort($this->cohort, $other)->create();
        GroupSupervisorFactory::new()->forGroup($group)->count(2)->create();

        $this->actingAs($this->admin)
            ->delete("/manage/cohorts/{$this->cohort->id}/groups/{$group->id}")
            ->assertSessionHasNoErrors();

        expect(StudentGroup::query()->count())->toBe(1)
            ->and(GroupSupervisor::query()->count())->toBe(2)
            ->and($group->fresh()->cohorts->pluck('id')->all())->toBe([$other->id])
            ->and($this->cohort->groups()->count())->toBe(0);
    });
});

describe('sharing across intakes', function () {
    it('creates a group serving several intakes at once', function () {
        $other = CohortFactory::new()->create();

        $this->actingAs($this->admin)
            ->post("/manage/cohorts/{$this->cohort->id}/groups", [
                'major' => 'data_science',
                'branch' => 'main',
                'cohort_ids' => [$other->id],
            ])
            ->assertSessionHasNoErrors();

        $group = StudentGroup::query()->with('cohorts')->first();

        expect($group->cohorts->pluck('id')->sort()->values()->all())
            ->toBe(collect([$this->cohort->id, $other->id])->sort()->values()->all());
    });

    it('shows one group under both intakes rather than duplicating it', function () {
        $other = CohortFactory::new()->create();
        StudentGroupFactory::new()->forCohort($this->cohort, $other)->major(Major::DataScience)->create();

        expect(StudentGroup::query()->count())->toBe(1)
            ->and($this->cohort->groups()->count())->toBe(1)
            ->and($other->groups()->count())->toBe(1);
    });

    it('rejects a pair that clashes in any of the intakes being synced', function () {
        $other = CohortFactory::new()->create();
        StudentGroupFactory::new()->forCohort($other)->major(Major::DataScience)->branch(Branch::Main)->create();

        $this->actingAs($this->admin)
            ->post("/manage/cohorts/{$this->cohort->id}/groups", [
                'major' => 'data_science',
                'branch' => 'main',
                'cohort_ids' => [$other->id],
            ])
            ->assertSessionHasErrors('major');
    });

    it('refuses to leave a group with no intake at all', function () {
        $group = StudentGroupFactory::new()->forCohort($this->cohort)->create();

        $this->actingAs($this->admin)
            ->put("/manage/groups/{$group->id}", ['cohort_ids' => []])
            ->assertSessionHasErrors(['cohort_ids' => 'يجب أن يكون القروب ضمن دفعة واحدة على الأقل.']);
    });
});

describe('reorder', function () {
    it('persists a new order within the intake', function () {
        $first = StudentGroupFactory::new()->forCohort($this->cohort)->major(Major::ComputerScience)->create();
        $second = StudentGroupFactory::new()->forCohort($this->cohort)->major(Major::DataScience)->create();

        $this->actingAs($this->admin)
            ->post("/manage/cohorts/{$this->cohort->id}/groups/reorder", ['ids' => [$second->id, $first->id]])
            ->assertSessionHasNoErrors();

        expect($second->fresh()->order)->toBe(1)
            ->and($first->fresh()->order)->toBe(2);
    });

    it('rejects ids belonging to another intake', function () {
        $mine = StudentGroupFactory::new()->forCohort($this->cohort)->create();
        $theirs = StudentGroupFactory::new()->create();

        $this->actingAs($this->admin)
            ->post("/manage/cohorts/{$this->cohort->id}/groups/reorder", ['ids' => [$mine->id, $theirs->id]])
            ->assertSessionHasErrors(['ids' => 'قائمة الترتيب تحتوي على قروب من دفعة أخرى.']);
    });
});

describe('cache invalidation', function () {
    it('flushes the public payload on every group write', function () {
        $group = StudentGroupFactory::new()->forCohort($this->cohort)->major(Major::Cybersecurity)->create();

        foreach ([
            fn () => $this->post("/manage/cohorts/{$this->cohort->id}/groups", ['major' => 'data_science', 'branch' => 'main']),
            fn () => $this->put("/manage/groups/{$group->id}", ['is_active' => false]),
            fn () => $this->post("/manage/cohorts/{$this->cohort->id}/groups/reorder", ['ids' => [$group->id]]),
            fn () => $this->delete("/manage/cohorts/{$this->cohort->id}/groups/{$group->id}"),
        ] as $write) {
            Cache::forever(Cohort::CACHE_KEY, ['stale']);

            $this->actingAs($this->admin);
            $write();

            expect(Cache::get(Cohort::CACHE_KEY))->toBeNull();
        }
    });
});
