<?php

use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');

    // Creating the users above logs "created" activities; drop them so tests count from zero.
    Activity::query()->delete();
});

describe('authorization', function () {
    it('redirects guests to the login page', function () {
        $this->get('/manage/activity')->assertRedirect(route('manage.login'));
    });

    it('allows editors, who hold view-activity-logs', function () {
        $this->actingAs($this->editor)->get('/manage/activity')->assertOk();
    });

    it('allows admins', function () {
        $this->actingAs($this->admin)->get('/manage/activity')->assertOk();
    });

    it('blocks panel users whose role lacks view-activity-logs', function () {
        Role::findByName('editor')->revokePermissionTo('view-activity-logs');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->editor)->get('/manage/activity')->assertForbidden();
    });
});

describe('pagination', function () {
    it('paginates 25 activities per page, latest first', function () {
        foreach (range(1, 30) as $index) {
            activity('test')->event('created')->log("نشاط {$index}");
        }

        $this->actingAs($this->admin)->get('/manage/activity')->assertInertia(fn (Assert $page) => $page
            ->component('manage/activity/Index')
            ->count('activities.data', 25)
            ->where('activities.current_page', 1)
            ->where('activities.last_page', 2)
            ->where('activities.total', 30)
            ->where('activities.data.0.description', 'نشاط 30'));

        $this->actingAs($this->admin)->get('/manage/activity?page=2')->assertInertia(fn (Assert $page) => $page
            ->count('activities.data', 5)
            ->where('activities.current_page', 2));
    });
});

describe('filters', function () {
    it('narrows by log name', function () {
        activity('custom')->log('سجل مخصص');
        activity()->log('سجل افتراضي');

        $this->actingAs($this->admin)->get('/manage/activity?log_name=custom')->assertInertia(fn (Assert $page) => $page
            ->count('activities.data', 1)
            ->where('activities.data.0.log_name', 'custom')
            ->where('filters.log_name', 'custom'));
    });

    it('narrows by event', function () {
        $this->actingAs($this->admin);

        $page = Page::factory()->create();
        $page->update(['title' => 'عنوان جديد']);

        $this->get('/manage/activity?event=updated')->assertInertia(fn (Assert $inertia) => $inertia
            ->count('activities.data', 1)
            ->where('activities.data.0.event', 'updated'));
    });

    it('narrows by subject type basename', function () {
        $this->actingAs($this->admin);

        Page::factory()->create();
        User::factory()->create();

        $this->get('/manage/activity?subject_type=Page')->assertInertia(fn (Assert $inertia) => $inertia
            ->count('activities.data', 1)
            ->where('activities.data.0.subject_type', 'Page'));

        $this->get('/manage/activity?subject_type=User')->assertInertia(fn (Assert $inertia) => $inertia
            ->count('activities.data', 1)
            ->where('activities.data.0.subject_type', 'User'));
    });

    it('shares distinct filter options', function () {
        $this->actingAs($this->admin);

        Page::factory()->create()->update(['title' => 'محدث']);
        activity('custom')->log('سجل مخصص');

        $this->get('/manage/activity')->assertInertia(fn (Assert $inertia) => $inertia
            ->where('filterOptions.logNames', fn ($names) => collect($names)->contains('custom'))
            ->where('filterOptions.events', fn ($events) => collect($events)->contains('created') && collect($events)->contains('updated'))
            ->where('filterOptions.subjectTypes', fn ($types) => collect($types)->contains('Page')));
    });
});

describe('rows', function () {
    it('maps an updated page with its old/new changes payload', function () {
        $this->actingAs($this->admin);

        $page = Page::factory()->create(['title' => 'عنوان قديم']);
        $page->update(['title' => 'عنوان جديد']);

        $this->get('/manage/activity?event=updated')->assertInertia(fn (Assert $inertia) => $inertia
            ->where('activities.data.0.event', 'updated')
            ->where('activities.data.0.subject_type', 'Page')
            ->where('activities.data.0.subject_id', $page->id)
            ->where('activities.data.0.subject_title', 'عنوان جديد')
            ->where('activities.data.0.causer_name', $this->admin->name)
            ->where('activities.data.0.changes.old.title', 'عنوان قديم')
            ->where('activities.data.0.changes.new.title', 'عنوان جديد')
            ->has('activities.data.0.created_at')
            ->has('activities.data.0.created_at_human'));
    });

    it('keeps a null changes payload for activities without properties', function () {
        activity('test')->log('بدون خصائص');

        $this->actingAs($this->admin)->get('/manage/activity')->assertInertia(fn (Assert $inertia) => $inertia
            ->where('activities.data.0.changes', null));
    });

    it('resolves subject titles for soft-deleted pages', function () {
        $this->actingAs($this->admin);

        $page = Page::factory()->create(['title' => 'صفحة محذوفة']);
        $page->delete();

        $this->get('/manage/activity?event=deleted')->assertInertia(fn (Assert $inertia) => $inertia
            ->where('activities.data.0.event', 'deleted')
            ->where('activities.data.0.subject_title', 'صفحة محذوفة'));
    });
});
