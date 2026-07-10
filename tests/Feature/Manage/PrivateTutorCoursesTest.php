<?php

use App\Models\PrivateTutor\PrivateTutorCourse;
use App\Models\User;
use Database\Factories\PrivateTutor\PrivateTutorCourseFactory;
use Database\Factories\PrivateTutor\PrivateTutorFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

describe('authorization', function () {
    it('blocks editors from every course mutation', function () {
        $course = PrivateTutorCourseFactory::new()->create();

        $this->actingAs($this->editor);

        $this->post('/manage/courses', ['name' => 'مقرر'])->assertForbidden();
        $this->put("/manage/courses/{$course->id}", ['name' => 'جديد'])->assertForbidden();
        $this->delete("/manage/courses/{$course->id}")->assertForbidden();
        $this->post('/manage/courses/reorder', ['ids' => [$course->id]])->assertForbidden();
    });

    it('redirects guests attempting to create a course', function () {
        $this->post('/manage/courses', ['name' => 'مقرر'])->assertRedirect(route('manage.login'));
    });
});

describe('create', function () {
    it('creates a course and redirects back (inline create from the tutor dialog)', function () {
        $response = $this->actingAs($this->admin)
            ->from('/manage/tutors')
            ->post('/manage/courses', ['name' => 'هياكل البيانات']);

        $response->assertRedirect('/manage/tutors');
        $response->assertSessionHasNoErrors();

        expect(PrivateTutorCourse::query()->where('name', 'هياكل البيانات')->exists())->toBeTrue();
    });

    it('assigns the next order on creation', function () {
        PrivateTutorCourseFactory::new()->create();

        $this->actingAs($this->admin)->post('/manage/courses', ['name' => 'مقرر ثانٍ']);

        expect(PrivateTutorCourse::query()->where('name', 'مقرر ثانٍ')->value('order'))->toBe(2);
    });

    it('rejects invalid payloads with Arabic messages', function (array $payload, string $message) {
        $response = $this->actingAs($this->admin)->post('/manage/courses', $payload);

        $response->assertSessionHasErrors(['name' => $message]);
    })->with([
        'missing name' => [[], 'حقل اسم المقرر مطلوب.'],
        'name too long' => [['name' => str_repeat('ا', 256)], 'يجب ألا يتجاوز اسم المقرر ٢٥٥ حرفاً.'],
    ]);
});

describe('update', function () {
    it('renames the course', function () {
        $course = PrivateTutorCourseFactory::new()->create();

        $this->actingAs($this->admin)
            ->put("/manage/courses/{$course->id}", ['name' => 'اسم محدث'])
            ->assertSessionHasNoErrors();

        expect($course->fresh()->name)->toBe('اسم محدث');
    });

    it('rejects an empty name', function () {
        $course = PrivateTutorCourseFactory::new()->create();

        $this->actingAs($this->admin)
            ->put("/manage/courses/{$course->id}", ['name' => ''])
            ->assertSessionHasErrors(['name' => 'حقل اسم المقرر مطلوب.']);
    });
});

describe('delete', function () {
    it('deletes the course and detaches it from tutors without deleting them', function () {
        $course = PrivateTutorCourseFactory::new()->create();
        $tutor = PrivateTutorFactory::new()->create();
        $tutor->courses()->attach($course);

        $this->actingAs($this->admin)->delete("/manage/courses/{$course->id}");

        expect(PrivateTutorCourse::query()->find($course->id))->toBeNull()
            ->and($tutor->fresh())->not->toBeNull()
            ->and($tutor->fresh()->courses)->toBeEmpty();
    });

    it('returns 404 for a missing course', function () {
        $this->actingAs($this->admin)->delete('/manage/courses/999')->assertNotFound();
    });
});

describe('reorder', function () {
    it('persists the new order', function () {
        [$a, $b, $c] = PrivateTutorCourseFactory::new()->count(3)->create();

        $this->actingAs($this->admin)
            ->post('/manage/courses/reorder', ['ids' => [$b->id, $c->id, $a->id]])
            ->assertSessionHasNoErrors();

        expect(PrivateTutorCourse::query()->orderBy('order')->pluck('id')->all())
            ->toBe([$b->id, $c->id, $a->id]);
    });

    it('rejects ids from the wrong table', function () {
        $tutor = PrivateTutorFactory::new()->create();

        $this->actingAs($this->admin)
            ->post('/manage/courses/reorder', ['ids' => [$tutor->id]])
            ->assertSessionHasErrors('ids.0');
    });
});

describe('cache invalidation', function () {
    it('flushes the private tutors cache when a course is created', function () {
        Cache::put('private_tutors_data', 'cached');

        $this->actingAs($this->admin)->post('/manage/courses', ['name' => 'مقرر']);

        expect(Cache::has('private_tutors_data'))->toBeFalse();
    });

    it('flushes the private tutors cache when a course is deleted', function () {
        $course = PrivateTutorCourseFactory::new()->create();

        Cache::put('private_tutors_data', 'cached');

        $this->actingAs($this->admin)->delete("/manage/courses/{$course->id}");

        expect(Cache::has('private_tutors_data'))->toBeFalse();
    });
});
