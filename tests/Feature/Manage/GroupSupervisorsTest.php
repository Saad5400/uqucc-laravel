<?php

use App\Models\StudentGroup\Cohort;
use App\Models\StudentGroup\GroupSupervisor;
use App\Models\StudentGroup\SupervisorSection;
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
    $this->group = StudentGroupFactory::new()->forCohort($this->cohort)->create();
});

describe('authorization', function () {
    it('blocks guests from every supervisor mutation', function () {
        $supervisor = GroupSupervisorFactory::new()->forGroup($this->group)->create();

        $this->post("/manage/groups/{$this->group->id}/supervisors", [])->assertRedirect(route('manage.login'));
        $this->put("/manage/supervisors/{$supervisor->id}", [])->assertRedirect(route('manage.login'));
        $this->delete("/manage/supervisors/{$supervisor->id}")->assertRedirect(route('manage.login'));
    });

    it('blocks editors from every supervisor mutation', function () {
        $supervisor = GroupSupervisorFactory::new()->forGroup($this->group)->create();

        $this->actingAs($this->editor);

        $this->post("/manage/groups/{$this->group->id}/supervisors", ['name' => 'أحمد'])->assertForbidden();
        $this->put("/manage/supervisors/{$supervisor->id}", ['name' => 'أحمد'])->assertForbidden();
        $this->delete("/manage/supervisors/{$supervisor->id}")->assertForbidden();
        $this->post("/manage/groups/{$this->group->id}/supervisors/reorder", ['ids' => [$supervisor->id]])->assertForbidden();
    });
});

describe('create', function () {
    it('adds a Telegram supervisor to the group', function () {
        $this->actingAs($this->admin)
            ->post("/manage/groups/{$this->group->id}/supervisors", [
                'name' => 'أثير',
                'telegram_username' => 'giv22',
                'section' => 'women',
                'is_available' => true,
            ])
            ->assertSessionHasNoErrors();

        $supervisor = GroupSupervisor::query()->where('name', 'أثير')->first();

        expect($supervisor)->not->toBeNull()
            ->and($supervisor->student_group_id)->toBe($this->group->id)
            ->and($supervisor->telegram_username)->toBe('giv22')
            ->and($supervisor->whatsapp_number)->toBeNull()
            ->and($supervisor->section)->toBe(SupervisorSection::Women)
            ->and($supervisor->telegramUrl())->toBe('https://t.me/giv22');
    });

    it('adds a WhatsApp-only supervisor, the way half the college lists publish', function () {
        $this->actingAs($this->admin)
            ->post("/manage/groups/{$this->group->id}/supervisors", [
                'name' => 'أنس المحمادي',
                'whatsapp_number' => '0507487697',
                'section' => 'men',
            ])
            ->assertSessionHasNoErrors();

        $supervisor = GroupSupervisor::query()->where('name', 'أنس المحمادي')->first();

        expect($supervisor->telegram_username)->toBeNull()
            ->and($supervisor->whatsapp_number)->toBe('966507487697')
            ->and($supervisor->whatsappUrl())->toBe('https://wa.me/966507487697');
    });

    it('adds a supervisor reachable both ways', function () {
        $this->actingAs($this->admin)
            ->post("/manage/groups/{$this->group->id}/supervisors", [
                'name' => 'رواف',
                'telegram_username' => '@Rori_chan_0',
                'whatsapp_number' => '0581138300',
                'section' => 'women',
            ])
            ->assertSessionHasNoErrors();

        expect(GroupSupervisor::query()->where('name', 'رواف')->first()->contacts())->toHaveCount(2);
    });

    it('accepts a pasted profile link and stores the bare handle', function (string $input) {
        $this->actingAs($this->admin)
            ->post("/manage/groups/{$this->group->id}/supervisors", [
                'name' => 'يوسف',
                'telegram_username' => $input,
                'section' => 'men',
            ])
            ->assertSessionHasNoErrors();

        expect(GroupSupervisor::query()->where('name', 'يوسف')->first()->telegram_username)->toBe('ysf_arr');
    })->with([
        'bare' => 'ysf_arr',
        'at-prefixed' => '@ysf_arr',
        'https url' => 'https://t.me/ysf_arr',
        'url with query' => 'https://t.me/ysf_arr?start=1',
    ]);

    it('refuses a supervisor nobody can reach', function () {
        $this->actingAs($this->admin)
            ->post("/manage/groups/{$this->group->id}/supervisors", [
                'name' => 'بلا تواصل',
                'telegram_username' => null,
                'whatsapp_number' => null,
                'section' => 'men',
            ])
            ->assertSessionHasErrors(['telegram_username' => 'أضف معرّف تيليجرام أو رقم واتساب على الأقل.']);
    });

    it('rejects a malformed handle or number', function () {
        $this->actingAs($this->admin)
            ->post("/manage/groups/{$this->group->id}/supervisors", [
                'name' => 'أحمد',
                'telegram_username' => 'ab',
                'whatsapp_number' => '123',
                'section' => 'men',
            ])
            ->assertSessionHasErrors(['telegram_username', 'whatsapp_number']);
    });

    it('rejects the same handle twice inside one group', function () {
        GroupSupervisorFactory::new()->forGroup($this->group)->create(['telegram_username' => 'ysf_arr']);

        $this->actingAs($this->admin)
            ->post("/manage/groups/{$this->group->id}/supervisors", [
                'name' => 'مكرر',
                'telegram_username' => 'https://t.me/ysf_arr',
                'section' => 'men',
            ])
            ->assertSessionHasErrors(['telegram_username' => 'هذا المعرّف مضاف مسبقاً في هذا القروب.']);
    });

    it('rejects the same number twice inside one group, however it was written', function () {
        GroupSupervisorFactory::new()->forGroup($this->group)->whatsappOnly('0507487697')->create();

        $this->actingAs($this->admin)
            ->post("/manage/groups/{$this->group->id}/supervisors", [
                'name' => 'مكرر',
                'whatsapp_number' => '+966 50 748 7697',
                'section' => 'men',
            ])
            ->assertSessionHasErrors(['whatsapp_number' => 'هذا الرقم مضاف مسبقاً في هذا القروب.']);
    });

    it('allows the same person to supervise two different groups', function () {
        GroupSupervisorFactory::new()->forGroup($this->group)->create(['telegram_username' => 'ysf_arr']);
        $other = StudentGroupFactory::new()->forCohort($this->cohort)->general()->create();

        $this->actingAs($this->admin)
            ->post("/manage/groups/{$other->id}/supervisors", [
                'name' => 'يوسف',
                'telegram_username' => 'ysf_arr',
                'section' => 'men',
            ])
            ->assertSessionHasNoErrors();

        expect(GroupSupervisor::query()->where('telegram_username', 'ysf_arr')->count())->toBe(2);
    });

    it('rejects an unknown section', function () {
        $this->actingAs($this->admin)
            ->post("/manage/groups/{$this->group->id}/supervisors", [
                'name' => 'أحمد',
                'telegram_username' => 'ahmad_x',
                'section' => 'staff',
            ])
            ->assertSessionHasErrors(['section' => 'الشطر المحدد غير صالح.']);
    });

    it('accepts the mixed «للشطرين» roster the general lists use', function () {
        $this->actingAs($this->admin)
            ->post("/manage/groups/{$this->group->id}/supervisors", [
                'name' => 'الكاتو',
                'telegram_username' => 'ElCatoCS',
                'section' => 'both',
            ])
            ->assertSessionHasNoErrors();

        expect(GroupSupervisor::query()->where('name', 'الكاتو')->first()->section)->toBe(SupervisorSection::Both);
    });
});

describe('update', function () {
    it('applies an availability-only payload without restating the row', function () {
        $supervisor = GroupSupervisorFactory::new()->forGroup($this->group)->create([
            'name' => 'أحمد',
            'telegram_username' => 'ahmad_x',
        ]);

        $this->actingAs($this->admin)
            ->put("/manage/supervisors/{$supervisor->id}", ['is_available' => false])
            ->assertSessionHasNoErrors();

        expect($supervisor->fresh())
            ->is_available->toBeFalse()
            ->name->toBe('أحمد')
            ->telegram_username->toBe('ahmad_x');
    });

    it('keeps its own contacts without tripping the duplicate check', function () {
        $supervisor = GroupSupervisorFactory::new()->forGroup($this->group)->withWhatsapp('0507487697')->create([
            'telegram_username' => 'ahmad_x',
        ]);

        $this->actingAs($this->admin)
            ->put("/manage/supervisors/{$supervisor->id}", [
                'name' => 'أحمد الحلبي',
                'telegram_username' => '@ahmad_x',
                'whatsapp_number' => '0507487697',
                'section' => 'men',
            ])
            ->assertSessionHasNoErrors();

        expect($supervisor->fresh()->name)->toBe('أحمد الحلبي');
    });

    it('refuses to strip the last contact off a supervisor', function () {
        $supervisor = GroupSupervisorFactory::new()->forGroup($this->group)->create(['telegram_username' => 'ahmad_x']);

        $this->actingAs($this->admin)
            ->put("/manage/supervisors/{$supervisor->id}", ['telegram_username' => null])
            ->assertSessionHasErrors(['telegram_username' => 'أضف معرّف تيليجرام أو رقم واتساب على الأقل.']);
    });

    it('allows dropping one contact while the other remains', function () {
        $supervisor = GroupSupervisorFactory::new()->forGroup($this->group)->withWhatsapp('0507487697')->create([
            'telegram_username' => 'ahmad_x',
        ]);

        $this->actingAs($this->admin)
            ->put("/manage/supervisors/{$supervisor->id}", ['telegram_username' => null])
            ->assertSessionHasNoErrors();

        expect($supervisor->fresh())
            ->telegram_username->toBeNull()
            ->whatsapp_number->toBe('966507487697');
    });

    it('moves a supervisor to the other section', function () {
        $supervisor = GroupSupervisorFactory::new()->forGroup($this->group)->create();

        $this->actingAs($this->admin)
            ->put("/manage/supervisors/{$supervisor->id}", ['section' => 'women'])
            ->assertSessionHasNoErrors();

        expect($supervisor->fresh()->section)->toBe(SupervisorSection::Women);
    });

    it('rejects blanking the name', function () {
        $supervisor = GroupSupervisorFactory::new()->forGroup($this->group)->create();

        $this->actingAs($this->admin)
            ->put("/manage/supervisors/{$supervisor->id}", ['name' => ''])
            ->assertSessionHasErrors(['name' => 'حقل اسم المشرف مطلوب.']);
    });
});

describe('delete', function () {
    it('removes a supervisor', function () {
        $supervisor = GroupSupervisorFactory::new()->forGroup($this->group)->create();

        $this->actingAs($this->admin)->delete("/manage/supervisors/{$supervisor->id}")->assertSessionHasNoErrors();

        expect(GroupSupervisor::query()->count())->toBe(0);
    });
});

describe('reorder', function () {
    it('persists a new order inside one section', function () {
        $first = GroupSupervisorFactory::new()->forGroup($this->group)->create();
        $second = GroupSupervisorFactory::new()->forGroup($this->group)->create();

        $this->actingAs($this->admin)
            ->post("/manage/groups/{$this->group->id}/supervisors/reorder", ['ids' => [$second->id, $first->id]])
            ->assertSessionHasNoErrors();

        expect($second->fresh()->order)->toBe(1)
            ->and($first->fresh()->order)->toBe(2);
    });

    it('rejects ids belonging to another group', function () {
        $mine = GroupSupervisorFactory::new()->forGroup($this->group)->create();
        $theirs = GroupSupervisorFactory::new()->create();

        $this->actingAs($this->admin)
            ->post("/manage/groups/{$this->group->id}/supervisors/reorder", ['ids' => [$mine->id, $theirs->id]])
            ->assertSessionHasErrors(['ids' => 'قائمة الترتيب تحتوي على مشرف من قروب آخر.']);
    });

    it('rejects mixing two sections in one list', function () {
        $man = GroupSupervisorFactory::new()->forGroup($this->group)->create();
        $woman = GroupSupervisorFactory::new()->forGroup($this->group)->women()->create();

        $this->actingAs($this->admin)
            ->post("/manage/groups/{$this->group->id}/supervisors/reorder", ['ids' => [$man->id, $woman->id]])
            ->assertSessionHasErrors(['ids' => 'لا يمكن ترتيب مشرفين من شطرين مختلفين معاً.']);
    });
});

describe('cache invalidation', function () {
    it('flushes the public payload on every supervisor write', function () {
        $supervisor = GroupSupervisorFactory::new()->forGroup($this->group)->create();

        foreach ([
            fn () => $this->post("/manage/groups/{$this->group->id}/supervisors", [
                'name' => 'جديد',
                'telegram_username' => 'brand_new',
                'section' => 'men',
            ]),
            fn () => $this->put("/manage/supervisors/{$supervisor->id}", ['is_available' => false]),
            fn () => $this->post("/manage/groups/{$this->group->id}/supervisors/reorder", ['ids' => [$supervisor->id]]),
            fn () => $this->delete("/manage/supervisors/{$supervisor->id}"),
        ] as $write) {
            Cache::forever(Cohort::CACHE_KEY, ['stale']);

            $this->actingAs($this->admin);
            $write();

            expect(Cache::get(Cohort::CACHE_KEY))->toBeNull();
        }
    });
});
