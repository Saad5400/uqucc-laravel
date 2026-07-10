<?php

use App\Models\PrivateTutor\PrivateTutor;
use App\Models\User;
use Database\Factories\PrivateTutor\PrivateTutorCourseFactory;
use Database\Factories\PrivateTutor\PrivateTutorFactory;
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

describe('authorization', function () {
    it('redirects guests to the login page', function () {
        $this->get('/manage/tutors')->assertRedirect(route('manage.login'));
    });

    it('blocks editors from the tutors workspace', function () {
        $this->actingAs($this->editor)->get('/manage/tutors')->assertForbidden();
    });

    it('blocks editors from every tutor mutation', function () {
        $tutor = PrivateTutorFactory::new()->create();

        $this->actingAs($this->editor);

        $this->post('/manage/tutors', ['name' => 'خصوصي'])->assertForbidden();
        $this->put("/manage/tutors/{$tutor->id}", ['name' => 'جديد'])->assertForbidden();
        $this->delete("/manage/tutors/{$tutor->id}")->assertForbidden();
        $this->post('/manage/tutors/reorder', ['ids' => [$tutor->id]])->assertForbidden();
    });

    it('allows admins to open the tutors workspace', function () {
        $this->actingAs($this->admin)->get('/manage/tutors')->assertOk();
    });
});

describe('index', function () {
    it('shares ordered tutors with their courses and the full course list', function () {
        $course = PrivateTutorCourseFactory::new()->create(['name' => 'برمجة ١']);
        $first = PrivateTutorFactory::new()->create(['name' => 'الأول']);
        $second = PrivateTutorFactory::new()->withoutUrl()->create(['name' => 'الثاني']);
        $second->courses()->attach($course);

        $response = $this->actingAs($this->admin)->get('/manage/tutors');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('manage/tutors/Index')
            ->count('tutors', 2)
            ->where('tutors.0.id', $first->id)
            ->where('tutors.0.url', $first->url)
            ->where('tutors.0.courses', [])
            ->where('tutors.1.id', $second->id)
            ->where('tutors.1.url', null)
            ->where('tutors.1.courses.0.name', 'برمجة ١')
            ->count('courses', 1)
            ->where('courses.0.name', 'برمجة ١')
            ->where('courses.0.tutors_count', 1)
        );
    });

    it('orders tutors by their order column', function () {
        $first = PrivateTutorFactory::new()->create();
        $second = PrivateTutorFactory::new()->create();

        $second->update(['order' => 0]);

        $response = $this->actingAs($this->admin)->get('/manage/tutors');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('tutors.0.id', $second->id)
            ->where('tutors.1.id', $first->id)
        );
    });
});

describe('create', function () {
    it('creates a tutor and assigns it the next order', function () {
        PrivateTutorFactory::new()->create();

        $response = $this->actingAs($this->admin)
            ->from('/manage/tutors')
            ->post('/manage/tutors', ['name' => 'خصوصي جديد', 'url' => 'https://example.com']);

        $response->assertRedirect('/manage/tutors');
        $response->assertSessionHasNoErrors();

        $tutor = PrivateTutor::query()->where('name', 'خصوصي جديد')->first();
        expect($tutor)->not->toBeNull()
            ->and($tutor->url)->toBe('https://example.com')
            ->and($tutor->order)->toBe(2);
    });

    it('creates a tutor with attached courses', function () {
        [$first, $second] = PrivateTutorCourseFactory::new()->count(2)->create();

        $this->actingAs($this->admin)
            ->post('/manage/tutors', ['name' => 'خصوصي بمقررات', 'course_ids' => [$first->id, $second->id]])
            ->assertSessionHasNoErrors();

        $tutor = PrivateTutor::query()->where('name', 'خصوصي بمقررات')->first();
        expect($tutor)->not->toBeNull()
            ->and($tutor->courses->pluck('id')->sort()->values()->all())
            ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
    });

    it('creates a tutor without courses when an empty list is sent', function () {
        $this->actingAs($this->admin)
            ->post('/manage/tutors', ['name' => 'بلا مقررات', 'course_ids' => []])
            ->assertSessionHasNoErrors();

        expect(PrivateTutor::query()->where('name', 'بلا مقررات')->first()->courses)->toBeEmpty();
    });

    it('rejects course ids that do not exist on create', function () {
        $response = $this->actingAs($this->admin)
            ->post('/manage/tutors', ['name' => 'خصوصي', 'course_ids' => [999]]);

        $response->assertSessionHasErrors(['course_ids.0' => 'أحد المقررات المحددة غير موجود.']);

        expect(PrivateTutor::query()->where('name', 'خصوصي')->exists())->toBeFalse();
    });

    it('creates a tutor without a url', function () {
        $this->actingAs($this->admin)
            ->post('/manage/tutors', ['name' => 'بدون رابط', 'url' => null])
            ->assertSessionHasNoErrors();

        expect(PrivateTutor::query()->where('name', 'بدون رابط')->exists())->toBeTrue();
    });

    it('rejects invalid payloads with Arabic messages', function (array $payload, string $field, string $message) {
        $response = $this->actingAs($this->admin)->post('/manage/tutors', $payload);

        $response->assertSessionHasErrors([$field => $message]);
    })->with([
        'missing name' => [['url' => 'https://example.com'], 'name', 'حقل الاسم مطلوب.'],
        'invalid url' => [['name' => 'خصوصي', 'url' => 'not-a-url'], 'url', 'يجب إدخال رابط صالح يبدأ بـ https:// أو http://.'],
        'name too long' => [['name' => str_repeat('ا', 256)], 'name', 'يجب ألا يتجاوز الاسم ٢٥٥ حرفاً.'],
    ]);
});

describe('update', function () {
    it('updates the tutor attributes', function () {
        $tutor = PrivateTutorFactory::new()->create();

        $this->actingAs($this->admin)
            ->put("/manage/tutors/{$tutor->id}", ['name' => 'اسم محدث', 'url' => 'https://updated.example.com'])
            ->assertSessionHasNoErrors();

        expect($tutor->fresh())
            ->name->toBe('اسم محدث')
            ->url->toBe('https://updated.example.com');
    });

    it('syncs the attached courses', function () {
        $tutor = PrivateTutorFactory::new()->create();
        [$kept, $detached, $added] = PrivateTutorCourseFactory::new()->count(3)->create();
        $tutor->courses()->attach([$kept->id, $detached->id]);

        $this->actingAs($this->admin)
            ->put("/manage/tutors/{$tutor->id}", [
                'name' => $tutor->name,
                'url' => $tutor->url,
                'course_ids' => [$kept->id, $added->id],
            ])
            ->assertSessionHasNoErrors();

        expect($tutor->fresh()->courses->pluck('id')->sort()->values()->all())
            ->toBe(collect([$kept->id, $added->id])->sort()->values()->all());
    });

    it('detaches all courses when an empty list is sent', function () {
        $tutor = PrivateTutorFactory::new()->create();
        $tutor->courses()->attach(PrivateTutorCourseFactory::new()->create());

        $this->actingAs($this->admin)
            ->put("/manage/tutors/{$tutor->id}", ['name' => $tutor->name, 'url' => $tutor->url, 'course_ids' => []])
            ->assertSessionHasNoErrors();

        expect($tutor->fresh()->courses)->toBeEmpty();
    });

    it('leaves courses untouched when course_ids is not sent', function () {
        $tutor = PrivateTutorFactory::new()->create();
        $course = PrivateTutorCourseFactory::new()->create();
        $tutor->courses()->attach($course);

        $this->actingAs($this->admin)
            ->put("/manage/tutors/{$tutor->id}", ['name' => 'تعديل الاسم فقط', 'url' => null])
            ->assertSessionHasNoErrors();

        expect($tutor->fresh()->courses->pluck('id')->all())->toBe([$course->id]);
    });

    it('rejects course ids that do not exist', function () {
        $tutor = PrivateTutorFactory::new()->create();

        $response = $this->actingAs($this->admin)
            ->put("/manage/tutors/{$tutor->id}", ['name' => $tutor->name, 'course_ids' => [999]]);

        $response->assertSessionHasErrors(['course_ids.0' => 'أحد المقررات المحددة غير موجود.']);
    });
});

describe('delete', function () {
    it('deletes the tutor and detaches its courses without deleting them', function () {
        $tutor = PrivateTutorFactory::new()->create();
        $course = PrivateTutorCourseFactory::new()->create();
        $tutor->courses()->attach($course);

        $this->actingAs($this->admin)->delete("/manage/tutors/{$tutor->id}");

        expect(PrivateTutor::query()->find($tutor->id))->toBeNull()
            ->and($course->fresh())->not->toBeNull()
            ->and($course->fresh()->tutors)->toBeEmpty();
    });

    it('returns 404 for a missing tutor', function () {
        $this->actingAs($this->admin)->delete('/manage/tutors/999')->assertNotFound();
    });
});

describe('reorder', function () {
    it('persists the new order', function () {
        [$a, $b, $c] = PrivateTutorFactory::new()->count(3)->create();

        $this->actingAs($this->admin)
            ->post('/manage/tutors/reorder', ['ids' => [$c->id, $a->id, $b->id]])
            ->assertSessionHasNoErrors();

        expect(PrivateTutor::query()->orderBy('order')->pluck('id')->all())
            ->toBe([$c->id, $a->id, $b->id]);
    });

    it('rejects invalid reorder payloads', function (array $payload, string $field) {
        PrivateTutorFactory::new()->create();

        $this->actingAs($this->admin)
            ->post('/manage/tutors/reorder', $payload)
            ->assertSessionHasErrors($field);
    })->with([
        'missing ids' => [[], 'ids'],
        'ids not an array' => [['ids' => 'nope'], 'ids'],
        'unknown id' => [['ids' => [999]], 'ids.0'],
        'duplicate id' => [['ids' => [1, 1]], 'ids.0'],
    ]);
});

describe('cache invalidation', function () {
    it('flushes the private tutors cache when a tutor is created', function () {
        Cache::put('private_tutors_data', 'cached');

        $this->actingAs($this->admin)->post('/manage/tutors', ['name' => 'خصوصي']);

        expect(Cache::has('private_tutors_data'))->toBeFalse();
    });

    it('flushes the private tutors cache when tutors are reordered', function () {
        [$a, $b] = PrivateTutorFactory::new()->count(2)->create();

        Cache::put('private_tutors_data', 'cached');

        $this->actingAs($this->admin)->post('/manage/tutors/reorder', ['ids' => [$b->id, $a->id]]);

        expect(Cache::has('private_tutors_data'))->toBeFalse();
    });

    it('flushes the private tutors cache when a tutor is deleted', function () {
        $tutor = PrivateTutorFactory::new()->create();

        Cache::put('private_tutors_data', 'cached');

        $this->actingAs($this->admin)->delete("/manage/tutors/{$tutor->id}");

        expect(Cache::has('private_tutors_data'))->toBeFalse();
    });
});
