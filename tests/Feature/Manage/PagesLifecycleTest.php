<?php

use App\Models\User;
use Database\Factories\PageFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

describe('destroy', function () {
    it('soft deletes the page together with its whole subtree', function () {
        $page = PageFactory::new()->create();
        $child = PageFactory::new()->childOf($page)->create();
        $grandchild = PageFactory::new()->childOf($child)->create();
        $sibling = PageFactory::new()->create();

        $response = $this->actingAs($this->admin)->delete("/manage/pages/{$page->id}");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSoftDeleted('pages', ['id' => $page->id]);
        $this->assertSoftDeleted('pages', ['id' => $child->id]);
        $this->assertSoftDeleted('pages', ['id' => $grandchild->id]);
        expect($sibling->fresh()->trashed())->toBeFalse();
    });

    it('flushes the app caches through the model events', function () {
        $page = PageFactory::new()->create();

        Cache::put(config('app-cache.keys.navigation_tree'), ['stale']);

        $this->actingAs($this->admin)->delete("/manage/pages/{$page->id}");

        expect(Cache::has(config('app-cache.keys.navigation_tree')))->toBeFalse();
    });
});

describe('restore', function () {
    it('restores the page and its trashed descendants', function () {
        $page = PageFactory::new()->create();
        $child = PageFactory::new()->childOf($page)->create();

        $this->actingAs($this->admin)->delete("/manage/pages/{$page->id}");

        $response = $this->actingAs($this->admin)->post("/manage/pages/{$page->id}/restore");

        $response->assertRedirect();
        $response->assertSessionHas('success');

        expect($page->fresh()->trashed())->toBeFalse();
        expect($child->fresh()->trashed())->toBeFalse();
    });

    it('blocks restoring a page whose parent is still trashed', function () {
        $parent = PageFactory::new()->create();
        $child = PageFactory::new()->childOf($parent)->create();

        $this->actingAs($this->admin)->delete("/manage/pages/{$parent->id}");

        $response = $this->actingAs($this->admin)->post("/manage/pages/{$child->id}/restore");

        $response->assertRedirect();
        $response->assertSessionHas('error');
        expect($child->fresh()->trashed())->toBeTrue();
    });
});

describe('force delete', function () {
    it('permanently deletes a trashed page', function () {
        $page = PageFactory::new()->create();
        $page->delete();

        $response = $this->actingAs($this->admin)->delete("/manage/pages/{$page->id}/force");

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
    });

    it('refuses to force delete a page that is not trashed', function () {
        $page = PageFactory::new()->create();

        $response = $this->actingAs($this->admin)->delete("/manage/pages/{$page->id}/force");

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('pages', ['id' => $page->id]);
    });

    it('blocks force deleting while non-deleted children still exist', function () {
        $page = PageFactory::new()->create();
        $child = PageFactory::new()->childOf($page)->create();

        $page->delete();
        $child->restore();

        $response = $this->actingAs($this->admin)->delete("/manage/pages/{$page->id}/force");

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('pages', ['id' => $page->id]);
    });

    it('cascades trashed children away at the database level', function () {
        $page = PageFactory::new()->create();
        $child = PageFactory::new()->childOf($page)->create();

        $this->actingAs($this->admin)->delete("/manage/pages/{$page->id}");
        $this->actingAs($this->admin)->delete("/manage/pages/{$page->id}/force")->assertSessionHas('success');

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('pages', ['id' => $child->id]);
    });
});

describe('reorder', function () {
    it('reorders siblings within one parent', function () {
        $parent = PageFactory::new()->create();
        $first = PageFactory::new()->childOf($parent)->create();
        $second = PageFactory::new()->childOf($parent)->create();
        $third = PageFactory::new()->childOf($parent)->create();

        $response = $this->actingAs($this->admin)->post('/manage/pages/reorder', [
            'parent_id' => $parent->id,
            'ids' => [$third->id, $first->id, $second->id],
        ]);

        $response->assertRedirect();
        expect($third->fresh()->order)->toBe(1);
        expect($first->fresh()->order)->toBe(2);
        expect($second->fresh()->order)->toBe(3);
    });

    it('reorders root pages with a null parent', function () {
        $first = PageFactory::new()->create();
        $second = PageFactory::new()->create();

        $this->actingAs($this->admin)->post('/manage/pages/reorder', [
            'parent_id' => null,
            'ids' => [$second->id, $first->id],
        ])->assertSessionDoesntHaveErrors();

        expect($second->fresh()->order)->toBe(1);
        expect($first->fresh()->order)->toBe(2);
    });

    it('rejects a list containing a page from another parent', function () {
        $parent = PageFactory::new()->create();
        $child = PageFactory::new()->childOf($parent)->create();
        $stranger = PageFactory::new()->create();

        $this->actingAs($this->admin)->post('/manage/pages/reorder', [
            'parent_id' => $parent->id,
            'ids' => [$child->id, $stranger->id],
        ])->assertSessionHasErrors('ids');

        expect($child->fresh()->order)->toBe(1);
    });

    it('flushes the app caches because each page saves through Eloquent', function () {
        $parent = PageFactory::new()->create();
        $first = PageFactory::new()->childOf($parent)->create();
        $second = PageFactory::new()->childOf($parent)->create();

        Cache::put(config('app-cache.keys.navigation_tree'), ['stale']);
        Cache::put(config('app-cache.keys.search_data'), ['stale']);

        $this->actingAs($this->admin)->post('/manage/pages/reorder', [
            'parent_id' => $parent->id,
            'ids' => [$second->id, $first->id],
        ]);

        expect(Cache::has(config('app-cache.keys.navigation_tree')))->toBeFalse();
        expect(Cache::has(config('app-cache.keys.search_data')))->toBeFalse();
    });
});
