<?php

use App\Models\PageChangeRequest;
use App\Models\User;
use Database\Factories\PageFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    // An editor whose edits go straight through (the default).
    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');

    // An editor whose edits are gated behind review.
    $this->reviewEditor = User::factory()->create(['requires_review' => true]);
    $this->reviewEditor->assignRole('editor');
});

describe('review-mode capture on page update', function () {
    it('captures a review-mode editor edit as a pending change instead of applying it', function () {
        $page = PageFactory::new()->create(['title' => 'الأصل', 'html_content' => 'قديم']);

        $this->actingAs($this->reviewEditor)
            ->put("/manage/pages/{$page->id}", ['html_content' => 'جديد'])
            ->assertRedirect();

        expect($page->fresh()->getRawOriginal('html_content'))->toBe('قديم');

        $request = PageChangeRequest::sole();
        expect($request->status)->toBe(PageChangeRequest::STATUS_PENDING)
            ->and($request->author_id)->toBe($this->reviewEditor->id)
            ->and($request->payload['html_content'])->toBe('جديد');
    });

    it('applies edits immediately for editors who are not review-gated', function () {
        $page = PageFactory::new()->create(['html_content' => 'قديم']);

        $this->actingAs($this->editor)
            ->put("/manage/pages/{$page->id}", ['html_content' => 'جديد'])
            ->assertRedirect();

        expect($page->fresh()->getRawOriginal('html_content'))->toBe('جديد');
        expect(PageChangeRequest::count())->toBe(0);
    });

    it('never gates an admin even if the flag is set on their account', function () {
        $this->admin->update(['requires_review' => true]);
        $page = PageFactory::new()->create(['html_content' => 'قديم']);

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", ['html_content' => 'جديد']);

        expect($page->fresh()->getRawOriginal('html_content'))->toBe('جديد');
        expect(PageChangeRequest::count())->toBe(0);
    });

    it('merges successive per-tab saves into one pending change for the same editor and page', function () {
        $page = PageFactory::new()->create();

        $this->actingAs($this->reviewEditor)->put("/manage/pages/{$page->id}", ['html_content' => 'محتوى']);
        $this->actingAs($this->reviewEditor)->put("/manage/pages/{$page->id}", ['icon' => 'star']);

        $request = PageChangeRequest::sole();
        expect($request->payload)->toMatchArray(['html_content' => 'محتوى', 'icon' => 'star']);
    });

    it('surfaces the review context to the page workspace', function () {
        $page = PageFactory::new()->create();

        $this->actingAs($this->reviewEditor)
            ->get("/manage/pages/{$page->id}/edit")
            ->assertInertia(fn (Assert $inertia) => $inertia->where('review.mode', true)->where('review.has_pending', false));

        PageChangeRequest::factory()->create(['page_id' => $page->id, 'author_id' => $this->reviewEditor->id]);

        $this->actingAs($this->reviewEditor)
            ->get("/manage/pages/{$page->id}/edit")
            ->assertInertia(fn (Assert $inertia) => $inertia->where('review.has_pending', true));
    });
});

describe('review queue authorization', function () {
    it('redirects guests to the login page', function () {
        $this->get('/manage/reviews')->assertRedirect(route('manage.login'));
    });

    it('lets admins and unrestricted editors see the queue', function () {
        $this->actingAs($this->admin)->get('/manage/reviews')->assertOk();
        $this->actingAs($this->editor)->get('/manage/reviews')->assertOk();
    });

    it('forbids review-mode editors from the queue', function () {
        $this->actingAs($this->reviewEditor)->get('/manage/reviews')->assertForbidden();
    });

    it('lists only pending change requests', function () {
        $page = PageFactory::new()->create();
        PageChangeRequest::factory()->create(['page_id' => $page->id, 'author_id' => $this->reviewEditor->id]);
        PageChangeRequest::factory()->approved()->create(['page_id' => $page->id, 'author_id' => $this->reviewEditor->id]);

        $this->actingAs($this->admin)
            ->get('/manage/reviews')
            ->assertInertia(fn (Assert $inertia) => $inertia->has('pending', 1));
    });
});

describe('approve', function () {
    it('applies the pending payload to the page and marks it approved', function () {
        $page = PageFactory::new()->create(['title' => 'قديم', 'html_content' => 'قديم']);
        $request = PageChangeRequest::factory()->create([
            'page_id' => $page->id,
            'author_id' => $this->reviewEditor->id,
            'payload' => ['title' => 'جديد', 'html_content' => 'محتوى جديد'],
        ]);

        $this->actingAs($this->admin)
            ->post("/manage/reviews/{$request->id}/approve")
            ->assertRedirect(route('manage.reviews.index'));

        $page->refresh();
        expect($page->title)->toBe('جديد')
            ->and($page->getRawOriginal('html_content'))->toBe('محتوى جديد');

        $request->refresh();
        expect($request->status)->toBe(PageChangeRequest::STATUS_APPROVED)
            ->and($request->reviewed_by)->toBe($this->admin->id)
            ->and($request->reviewed_at)->not->toBeNull();
    });

    it('rejects approving an already-decided request', function () {
        $page = PageFactory::new()->create();
        $request = PageChangeRequest::factory()->rejected()->create(['page_id' => $page->id]);

        $this->actingAs($this->admin)
            ->post("/manage/reviews/{$request->id}/approve")
            ->assertSessionHas('error');

        expect($request->fresh()->status)->toBe(PageChangeRequest::STATUS_REJECTED);
    });

    it('forbids a review-mode editor from approving', function () {
        $page = PageFactory::new()->create();
        $request = PageChangeRequest::factory()->create(['page_id' => $page->id]);

        $this->actingAs($this->reviewEditor)
            ->post("/manage/reviews/{$request->id}/approve")
            ->assertForbidden();

        expect($request->fresh()->status)->toBe(PageChangeRequest::STATUS_PENDING);
    });
});

describe('reject', function () {
    it('discards the request and leaves the page untouched', function () {
        $page = PageFactory::new()->create(['title' => 'الأصل']);
        $request = PageChangeRequest::factory()->create([
            'page_id' => $page->id,
            'author_id' => $this->reviewEditor->id,
            'payload' => ['title' => 'لن يُطبَّق'],
        ]);

        $this->actingAs($this->admin)
            ->post("/manage/reviews/{$request->id}/reject")
            ->assertRedirect(route('manage.reviews.index'));

        expect($page->fresh()->title)->toBe('الأصل');
        expect($request->fresh()->status)->toBe(PageChangeRequest::STATUS_REJECTED);
    });
});

describe('requires_review toggle in users CRUD', function () {
    it('lets an admin set requires_review on a user', function () {
        $target = User::factory()->create(['requires_review' => false]);

        $this->actingAs($this->admin)
            ->put("/manage/users/{$target->id}", [
                'name' => $target->name,
                'email' => $target->email,
                'requires_review' => true,
            ])
            ->assertRedirect();

        expect($target->fresh()->requires_review)->toBeTrue();
    });

    it('ignores requires_review from a user manager without assign-roles', function () {
        $manager = User::factory()->create();
        $manager->assignRole('editor');
        $manager->givePermissionTo('manage-users');

        $target = User::factory()->create(['requires_review' => false]);

        $this->actingAs($manager)->put("/manage/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'requires_review' => true,
        ]);

        expect($target->fresh()->requires_review)->toBeFalse();
    });
});
