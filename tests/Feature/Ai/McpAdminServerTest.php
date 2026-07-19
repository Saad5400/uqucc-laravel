<?php

use App\Models\Page;
use App\Models\PageChangeRequest;
use App\Models\PrivateTutor\PrivateTutor;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Passport\Passport;

use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * A tools/call JSON-RPC envelope for the authenticated admin MCP server.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function adminRpc(string $tool, array $arguments = []): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ];
}

function makeUser(string $role, bool $requiresReview = false): User
{
    $user = User::factory()->create(['requires_review' => $requiresReview]);
    $user->assignRole($role);

    return $user;
}

it('rejects unauthenticated access to the admin server', function () {
    postJson('/mcp/admin', adminRpc('list_pending_changes'))->assertUnauthorized();
});

it('lists the unified admin actions and remaining moderation tools for an authorized moderator', function () {
    Passport::actingAs(makeUser('admin'));

    $response = postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ]);

    $response->assertOk();

    $names = collect($response->json('result.tools'))->pluck('name')->all();

    expect($names)->toBe([
        // Unified admin actions (shared with the in-app assistant)
        'list_pages',
        'get_page_content',
        'manage_page_structure',
        'update_page',
        'update_page_content',
        'restore_page',
        'get_settings',
        'update_setting',
        // Moderation tools not yet migrated to the action registry
        'list_pending_changes',
        'approve_page_change',
        'reject_page_change',
        'list_tutors',
        'create_tutor',
        'update_tutor',
        'delete_tutor',
        'list_users',
        'create_user',
        'update_user',
        'delete_user',
    ]);
});

it('lets a reviewer approve a pending change and publishes it to the live page', function () {
    $editor = makeUser('editor');
    $page = Page::factory()->create(['title' => 'العنوان القديم']);
    $change = PageChangeRequest::factory()->create([
        'page_id' => $page->id,
        'status' => PageChangeRequest::STATUS_PENDING,
        'payload' => ['title' => 'العنوان الجديد'],
    ]);

    Passport::actingAs($editor);

    postJson('/mcp/admin', adminRpc('approve_page_change', ['change_request_id' => $change->id]))
        ->assertOk();

    expect($page->fresh()->title)->toBe('العنوان الجديد')
        ->and($change->fresh()->status)->toBe(PageChangeRequest::STATUS_APPROVED)
        ->and($change->fresh()->reviewed_by)->toBe($editor->id);
});

it('denies approval to an editor whose own changes require review', function () {
    $reviewGated = makeUser('editor', requiresReview: true);
    $page = Page::factory()->create(['title' => 'كما هو']);
    $change = PageChangeRequest::factory()->create([
        'page_id' => $page->id,
        'status' => PageChangeRequest::STATUS_PENDING,
        'payload' => ['title' => 'محاولة'],
    ]);

    Passport::actingAs($reviewGated);

    $response = postJson('/mcp/admin', adminRpc('approve_page_change', ['change_request_id' => $change->id]));

    expect($response->json('result.isError'))->toBeTrue()
        ->and($page->fresh()->title)->toBe('كما هو')
        ->and($change->fresh()->status)->toBe(PageChangeRequest::STATUS_PENDING);
});

it('funnels a review-mode editor page edit into the review queue instead of the live page', function () {
    $reviewGated = makeUser('editor', requiresReview: true);
    $page = Page::factory()->create(['title' => 'الأصلي']);

    Passport::actingAs($reviewGated);

    postJson('/mcp/admin', adminRpc('update_page', ['page_id' => $page->id, 'title' => 'المقترح']))
        ->assertOk();

    expect($page->fresh()->title)->toBe('الأصلي');

    $pending = PageChangeRequest::query()
        ->where('page_id', $page->id)
        ->where('author_id', $reviewGated->id)
        ->where('status', PageChangeRequest::STATUS_PENDING)
        ->first();

    expect($pending)->not->toBeNull()
        ->and($pending->payload['title'])->toBe('المقترح');
});

it('updates the live page directly for an editor who is not review-gated', function () {
    $editor = makeUser('editor');
    $page = Page::factory()->create(['title' => 'قبل', 'hidden' => false]);

    Passport::actingAs($editor);

    postJson('/mcp/admin', adminRpc('update_page', ['page_id' => $page->id, 'title' => 'بعد', 'hidden' => true]))
        ->assertOk();

    expect($page->fresh()->title)->toBe('بعد')
        ->and($page->fresh()->hidden)->toBeTrue()
        ->and(PageChangeRequest::query()->count())->toBe(0);
});

it('refuses a structural page change for a review-mode editor', function () {
    $reviewGated = makeUser('editor', requiresReview: true);
    $page = Page::factory()->create();

    Passport::actingAs($reviewGated);

    $response = postJson('/mcp/admin', adminRpc('manage_page_structure', ['action' => 'delete', 'page_id' => $page->id]));

    expect($response->json('result.isError'))->toBeTrue()
        ->and($page->fresh()->trashed())->toBeFalse();
});

it('lets a non-review editor trash and restore a page through structural actions', function () {
    $editor = makeUser('editor');
    $parent = Page::factory()->create();
    $child = Page::factory()->create(['parent_id' => $parent->id]);

    Passport::actingAs($editor);

    postJson('/mcp/admin', adminRpc('manage_page_structure', ['action' => 'delete', 'page_id' => $parent->id]))
        ->assertOk();

    expect($parent->fresh()->trashed())->toBeTrue()
        ->and($child->fresh()->trashed())->toBeTrue();

    postJson('/mcp/admin', adminRpc('restore_page', ['page_id' => $parent->id]))
        ->assertOk();

    expect($parent->fresh()->trashed())->toBeFalse();
});

it('edits a page\'s text content through update_page_content, preserving custom blocks', function () {
    $editor = makeUser('editor');
    $page = Page::factory()->create([
        'html_content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'قديم']]],
                ['type' => 'alert', 'attrs' => ['variant' => 'info'], 'content' => [['type' => 'text', 'text' => 'تنبيه']]],
            ],
        ],
    ]);

    Passport::actingAs($editor);

    postJson('/mcp/admin', adminRpc('update_page_content', ['page_id' => $page->id, 'content' => '## قسم جديد'."\n\n".'نص محدّث.']))
        ->assertOk();

    $content = $page->fresh()->html_content;
    $types = collect($content['content'])->pluck('type')->all();

    expect($types)->toContain('alert')
        ->and(json_encode($content, JSON_UNESCAPED_UNICODE))->toContain('قسم جديد')
        ->and(json_encode($content, JSON_UNESCAPED_UNICODE))->not->toContain('قديم');
});

it('lets an admin create a tutor but denies an editor', function () {
    Passport::actingAs(makeUser('admin'));

    postJson('/mcp/admin', adminRpc('create_tutor', ['name' => 'أ. محمد']))->assertOk();

    expect(PrivateTutor::query()->where('name', 'أ. محمد')->exists())->toBeTrue();

    Passport::actingAs(makeUser('editor'));

    $response = postJson('/mcp/admin', adminRpc('create_tutor', ['name' => 'أ. خالد']));

    expect($response->json('result.isError'))->toBeTrue()
        ->and(PrivateTutor::query()->where('name', 'أ. خالد')->exists())->toBeFalse();
});

it('denies user management to a non-admin', function () {
    Passport::actingAs(makeUser('editor'));

    $response = postJson('/mcp/admin', adminRpc('list_users'));

    expect($response->json('result.isError'))->toBeTrue();
});

it('lets an admin create a user with the email pre-verified', function () {
    Passport::actingAs(makeUser('admin'));

    postJson('/mcp/admin', adminRpc('create_user', [
        'name' => 'مشرف جديد',
        'email' => 'new-mod@uqu.test',
        'password' => 'super-secret-pw',
        'roles' => ['editor'],
    ]))->assertOk();

    $created = User::query()->where('email', 'new-mod@uqu.test')->first();

    expect($created)->not->toBeNull()
        ->and($created->email_verified_at)->not->toBeNull()
        ->and($created->hasRole('editor'))->toBeTrue();
});

it('blocks an admin from deleting their own account', function () {
    $admin = makeUser('admin');

    Passport::actingAs($admin);

    $response = postJson('/mcp/admin', adminRpc('delete_user', ['user_id' => $admin->id]));

    expect($response->json('result.isError'))->toBeTrue()
        ->and(User::query()->whereKey($admin->id)->exists())->toBeTrue();
});
