<?php

use App\Models\Page;
use App\Models\User;
use Database\Factories\PageFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Inertia;
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
        $this->get('/manage/pages')->assertRedirect(route('manage.login'));
    });

    it('allows editors to manage pages (no extra permission, parity with Filament)', function () {
        $page = PageFactory::new()->create();

        $this->actingAs($this->editor);

        $this->get('/manage/pages')->assertOk();
        $this->get("/manage/pages/{$page->id}/edit")->assertOk();
        $this->put("/manage/pages/{$page->id}", ['title' => 'عنوان جديد'])->assertRedirect();
    });

    it('allows admins to open the pages tree', function () {
        $this->actingAs($this->admin)->get('/manage/pages')->assertOk();
    });
});

describe('index', function () {
    it('shares the full nested tree ordered by order within each parent', function () {
        $root = PageFactory::new()->create(['title' => 'الجذر']);
        $secondChild = PageFactory::new()->childOf($root)->create(['title' => 'الثاني']);
        $firstChild = PageFactory::new()->childOf($root)->create(['title' => 'الأول']);
        $grandchild = PageFactory::new()->childOf($firstChild)->create(['title' => 'الحفيد']);

        $firstChild->update(['order' => 1]);
        $secondChild->update(['order' => 2]);

        $response = $this->actingAs($this->admin)->get('/manage/pages');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('manage/pages/Index')
            ->count('pages', 1)
            ->where('pages.0.id', $root->id)
            ->where('pages.0.children_count', 2)
            ->where('pages.0.children.0.id', $firstChild->id)
            ->where('pages.0.children.1.id', $secondChild->id)
            ->where('pages.0.children.0.children.0.id', $grandchild->id)
        );
    });

    it('flags pages without real content', function () {
        $withContent = PageFactory::new()->create();
        $empty = PageFactory::new()->create(['html_content' => '']);
        $emptyDoc = PageFactory::new()->create(['html_content' => ['type' => 'doc', 'content' => []]]);

        $response = $this->actingAs($this->admin)->get('/manage/pages');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('pages.0.id', $withContent->id)
            ->where('pages.0.has_content', true)
            ->where('pages.1.id', $empty->id)
            ->where('pages.1.has_content', false)
            ->where('pages.2.id', $emptyDoc->id)
            ->where('pages.2.has_content', false)
        );
    });

    it('shares visibility flags on tree nodes', function () {
        PageFactory::new()->hidden()->hiddenFromBot()->create(['smart_search' => true]);

        $response = $this->actingAs($this->admin)->get('/manage/pages');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('pages.0.hidden', true)
            ->where('pages.0.hidden_from_bot', true)
            ->where('pages.0.smart_search', true)
        );
    });

    it('defers the trashed pages prop and serves it on a partial reload', function () {
        $parent = PageFactory::new()->create(['title' => 'الأب']);
        $trashed = PageFactory::new()->childOf($parent)->create(['title' => 'محذوفة']);
        PageFactory::new()->childOf($trashed)->create()->delete();
        $trashed->delete();

        $this->actingAs($this->admin)->get('/manage/pages')
            ->assertInertia(fn (Assert $page) => $page->missing('trashedPages'));

        $response = $this->actingAs($this->admin)->get('/manage/pages', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'manage/pages/Index',
            'X-Inertia-Partial-Data' => 'trashedPages',
        ]);

        $response->assertOk();
        $response->assertJsonPath('props.trashedPages.0.id', $trashed->id);
        $response->assertJsonPath('props.trashedPages.0.parent_title', 'الأب');
        $response->assertJsonPath('props.trashedPages.0.children_count', 1);
    });
});

describe('store', function () {
    it('creates a page with a slug generated exactly like Filament prefilled it', function (string $title) {
        $response = $this->actingAs($this->editor)->post('/manage/pages', ['title' => $title]);

        $page = Page::query()->where('title', $title)->firstOrFail();

        expect($page->slug)->toBe('/'.Str::slug($title));
        expect($page->getRawOriginal('html_content'))->toBe('');
        $response->assertRedirect("/manage/pages/{$page->id}/edit");
        $response->assertSessionHas('success');
    })->with([
        'latin title' => 'Hello World',
        'arabic title' => 'مرحبا بالعالم',
    ]);

    it('resolves slug collisions with a numeric suffix, counting trashed pages', function () {
        PageFactory::new()->create(['slug' => '/hello-world'])->delete();

        $this->actingAs($this->admin)->post('/manage/pages', ['title' => 'Hello World']);

        expect(Page::query()->where('title', 'Hello World')->firstOrFail()->slug)->toBe('/hello-world-1');
    });

    it('creates the page under the given parent with the next sibling order', function () {
        $parent = PageFactory::new()->create();
        $sibling = PageFactory::new()->childOf($parent)->create();

        $this->actingAs($this->admin)->post('/manage/pages', ['title' => 'صفحة فرعية', 'parent_id' => $parent->id]);

        $page = Page::query()->where('title', 'صفحة فرعية')->firstOrFail();

        expect($page->parent_id)->toBe($parent->id);
        expect($page->order)->toBe($sibling->order + 1);
    });

    it('rejects a missing title and a trashed parent', function () {
        $trashedParent = PageFactory::new()->create();
        $trashedParent->delete();

        $this->actingAs($this->admin)->post('/manage/pages', ['title' => ''])
            ->assertSessionHasErrors('title');

        $this->actingAs($this->admin)->post('/manage/pages', ['title' => 'صفحة', 'parent_id' => $trashedParent->id])
            ->assertSessionHasErrors('parent_id');
    });
});

describe('edit', function () {
    it('shares the full workspace payload', function () {
        $root = PageFactory::new()->create(['title' => 'الجذر']);
        $page = PageFactory::new()->childOf($root)->create([
            'title' => 'الصفحة',
            'quick_response_buttons' => [['text' => 'زر', 'url' => 'https://example.com', 'size' => 'half']],
            'quick_response_attachments' => ['quick-responses/file.pdf'],
        ]);
        $child = PageFactory::new()->childOf($page)->create(['title' => 'الابن']);
        $grandchild = PageFactory::new()->childOf($child)->create();

        $author = User::factory()->create(['name' => 'مؤلف']);
        $page->users()->attach($author, ['order' => 1]);

        $response = $this->actingAs($this->admin)->get("/manage/pages/{$page->id}/edit");

        $response->assertInertia(fn (Assert $assert) => $assert
            ->component('manage/pages/Edit')
            ->where('page.id', $page->id)
            ->where('page.title', 'الصفحة')
            ->where('page.parent_id', $root->id)
            ->where('page.quick_response_buttons.0.size', 'half')
            ->where('page.quick_response_attachments.0', 'quick-responses/file.pdf')
            ->where('parentChain.0.id', $root->id)
            ->count('children', 1)
            ->where('children.0.id', $child->id)
            ->where('children.0.children_count', 1)
            ->where('authors.0.id', $author->id)
            ->where('descendantIds', [$child->id, $grandchild->id])
            ->where('attachments.0.name', 'file.pdf')
            ->has('users')
        );
    });

    it('shares parent options as a flat depth-first list with tree levels', function () {
        $root = PageFactory::new()->create(['title' => 'الجذر']);
        $child = PageFactory::new()->childOf($root)->create(['title' => 'الابن']);
        $grandchild = PageFactory::new()->childOf($child)->create(['title' => 'الحفيد']);

        $response = $this->actingAs($this->admin)->get("/manage/pages/{$grandchild->id}/edit");

        $response->assertInertia(fn (Assert $assert) => $assert
            ->where('parentOptions.0.id', $root->id)
            ->where('parentOptions.0.level', 0)
            ->where('parentOptions.1.id', $child->id)
            ->where('parentOptions.1.level', 1)
            ->where('parentOptions.2.id', $grandchild->id)
            ->where('parentOptions.2.level', 2)
        );
    });

    it('shares the HTML quick response message untouched', function () {
        $page = PageFactory::new()->create(['quick_response_message' => '<p>مرحبا <strong>بكم</strong></p>']);

        $this->actingAs($this->admin)->get("/manage/pages/{$page->id}/edit")
            ->assertInertia(fn (Assert $assert) => $assert
                ->where('page.quick_response_message', '<p>مرحبا <strong>بكم</strong></p>')
            );
    });

    it('resolves trashed pages through the route binding', function () {
        $page = PageFactory::new()->create();
        $page->delete();

        $response = $this->actingAs($this->admin)->get("/manage/pages/{$page->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn (Assert $assert) => $assert
            ->where('page.id', $page->id)
            ->whereNot('page.deleted_at', null)
        );
    });
});

describe('update', function () {
    it('applies a partial title-only update without touching other fields', function () {
        $page = PageFactory::new()->create(['slug' => '/original']);

        $response = $this->actingAs($this->editor)->put("/manage/pages/{$page->id}", ['title' => 'عنوان جديد']);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $page->refresh();
        expect($page->title)->toBe('عنوان جديد');
        expect($page->slug)->toBe('/original');
    });

    it('updates a trashed page through the route binding', function () {
        $page = PageFactory::new()->create();
        $page->delete();

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", ['title' => 'بعد الحذف'])->assertRedirect();

        expect($page->fresh()->title)->toBe('بعد الحذف');
    });

    it('validates the slug format and uniqueness while ignoring the page itself', function () {
        PageFactory::new()->create(['slug' => '/taken']);
        $page = PageFactory::new()->create(['slug' => '/mine']);

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", ['slug' => 'no-leading-slash'])
            ->assertSessionHasErrors('slug');

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", ['slug' => '/taken'])
            ->assertSessionHasErrors('slug');

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", ['slug' => '/mine'])
            ->assertSessionDoesntHaveErrors();

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", ['slug' => '/new/nested-slug'])
            ->assertSessionDoesntHaveErrors();

        expect($page->fresh()->slug)->toBe('/new/nested-slug');
    });

    it('rejects moving a page under itself or one of its descendants', function () {
        $page = PageFactory::new()->create();
        $child = PageFactory::new()->childOf($page)->create();
        $grandchild = PageFactory::new()->childOf($child)->create();

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", ['parent_id' => $page->id])
            ->assertSessionHasErrors(['parent_id' => 'لا يمكن نقل الصفحة تحت نفسها أو تحت إحدى صفحاتها الفرعية.']);

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", ['parent_id' => $grandchild->id])
            ->assertSessionHasErrors('parent_id');

        expect($page->fresh()->parent_id)->toBeNull();
    });

    it('moves a page under a valid new parent', function () {
        $page = PageFactory::new()->create();
        $newParent = PageFactory::new()->create();

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", ['parent_id' => $newParent->id])
            ->assertSessionDoesntHaveErrors();

        expect($page->fresh()->parent_id)->toBe($newParent->id);
    });

    it('passes a TipTap array through the model accessor untouched', function () {
        $page = PageFactory::new()->create();
        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'محتوى']]]]];

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", ['html_content' => $document])
            ->assertSessionDoesntHaveErrors();

        $page->refresh();
        expect($page->getRawOriginal('html_content'))->toBe(json_encode($document));
        expect($page->html_content)->toBe($document);
    });

    it('round-trips a stored-format document with custom blocks through HTTP', function () {
        $page = PageFactory::new()->create();
        $document = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'attrs' => ['textAlign' => 'center'],
                    'content' => [['type' => 'text', 'text' => 'مقدمة', 'marks' => [['type' => 'bold']]]],
                ],
                [
                    'type' => 'customBlock',
                    'attrs' => ['id' => 'alert', 'config' => ['icon' => 'solar:info-circle-linear', 'content' => 'تنبيه مهم']],
                ],
                [
                    'type' => 'customBlock',
                    'attrs' => ['id' => 'collapsible', 'config' => ['question' => 'سؤال', 'answer' => 'جواب']],
                ],
            ],
        ];

        $this->actingAs($this->editor)->put("/manage/pages/{$page->id}", ['html_content' => $document])
            ->assertSessionDoesntHaveErrors();

        $page->refresh();
        expect($page->getRawOriginal('html_content'))->toBe(json_encode($document));
        expect($page->html_content)->toBe($document);

        $this->actingAs($this->editor)->get("/manage/pages/{$page->id}/edit")
            ->assertInertia(fn (Assert $assert) => $assert->where('page.html_content', $document));
    });

    it('persists the quick response message as an HTML string byte-identical (frozen bot contract)', function () {
        $page = PageFactory::new()->create();
        $html = '<p><strong>رد </strong><a href="https://example.com">سريع</a></p><pre><code>echo 1;</code></pre>';

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", ['quick_response_message' => $html])
            ->assertSessionDoesntHaveErrors();

        expect($page->refresh()->quick_response_message)->toBe($html);

        $this->actingAs($this->admin)->get("/manage/pages/{$page->id}/edit")
            ->assertInertia(fn (Assert $assert) => $assert->where('page.quick_response_message', $html));
    });

    it('rejects a non-string quick response message (the column is HTML, never JSON)', function () {
        $page = PageFactory::new()->create();

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", [
            'quick_response_message' => ['type' => 'doc', 'content' => []],
        ])->assertSessionHasErrors('quick_response_message');
    });

    it('stores null html_content as an empty string (column is not nullable)', function () {
        $page = PageFactory::new()->create();

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", ['html_content' => null])
            ->assertSessionDoesntHaveErrors();

        expect($page->fresh()->getRawOriginal('html_content'))->toBe('');
    });

    it('updates the quick response fields', function () {
        $page = PageFactory::new()->create();

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", [
            'quick_response_send_link' => false,
            'quick_response_auto_extract_message' => true,
            'quick_response_message' => 'رسالة الرد',
            'quick_response_buttons' => [
                ['text' => 'زر أول', 'url' => 'https://example.com/a', 'size' => 'half'],
                ['text' => 'زر ثانٍ', 'url' => 'https://example.com/b', 'size' => 'full'],
            ],
            'quick_response_attachments' => ['quick-responses/file.pdf'],
        ])->assertSessionDoesntHaveErrors();

        $page->refresh();
        expect($page->quick_response_send_link)->toBeFalse();
        expect($page->quick_response_auto_extract_message)->toBeTrue();
        expect($page->quick_response_message)->toBe('رسالة الرد');
        expect($page->quick_response_buttons)->toHaveCount(2);
        expect($page->quick_response_buttons[0]['size'])->toBe('half');
        expect($page->quick_response_attachments)->toBe(['quick-responses/file.pdf']);
    });

    it('rejects malformed quick response buttons', function (array $button, string $errorKey) {
        $page = PageFactory::new()->create();

        $this->actingAs($this->admin)->put("/manage/pages/{$page->id}", ['quick_response_buttons' => [$button]])
            ->assertSessionHasErrors($errorKey);
    })->with([
        'missing text' => [['url' => 'https://example.com', 'size' => 'full'], 'quick_response_buttons.0.text'],
        'invalid url' => [['text' => 'زر', 'url' => 'not-a-url', 'size' => 'full'], 'quick_response_buttons.0.url'],
        'invalid size' => [['text' => 'زر', 'url' => 'https://example.com', 'size' => 'huge'], 'quick_response_buttons.0.size'],
    ]);
});
