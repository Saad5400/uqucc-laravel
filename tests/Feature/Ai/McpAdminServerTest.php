<?php

use App\Models\Page;
use App\Models\PageChangeRequest;
use App\Models\PrivateTutor\PrivateTutor;
use App\Models\PrivateTutor\PrivateTutorCourse;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Passport\Passport;

use function Pest\Laravel\postJson;

/** The text content of a successful tools/call response. */
function adminResultText(\Illuminate\Testing\TestResponse $response): string
{
    return (string) collect($response->json('result.content'))->pluck('text')->implode("\n");
}

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

it('lists all unified admin actions for an authorized moderator', function () {
    Passport::actingAs(makeUser('admin'));

    $response = postJson('/mcp/admin', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ]);

    $response->assertOk();

    $names = collect($response->json('result.tools'))->pluck('name')->all();

    expect($names)->toBe([
        'list_pages',
        'get_page_content',
        'manage_page_structure',
        'update_page',
        'update_page_content',
        'restore_page',
        'list_pending_changes',
        'show_page_change',
        'approve_page_change',
        'reject_page_change',
        'list_tutors',
        'create_tutor',
        'update_tutor',
        'delete_tutor',
        'reorder_tutors',
        'create_course',
        'update_course',
        'delete_course',
        'reorder_courses',
        'list_users',
        'create_user',
        'update_user',
        'delete_user',
        'get_settings',
        'update_setting',
        'list_telegram_chats',
        'set_telegram_chat_ai',
        'reset_telegram_chat',
        'delete_telegram_chat',
        'list_quiz_topics',
        'create_quiz_topic',
        'update_quiz_topic',
        'delete_quiz_topic',
        'get_daily_quiz',
        'update_daily_quiz',
        'regenerate_daily_quiz',
        'get_quiz_leaderboard',
        'list_corpus_documents',
        'get_corpus_document',
        'reextract_corpus_document',
        'reingest_corpus_document',
        'author_page_from_document',
        'get_analytics',
        'get_ai_usage',
        'list_activity_log',
        'site_overview',
        'list_routes',
        'clear_cache',
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

it('shows a field-by-field diff of a pending change so a reviewer is not approving blind', function () {
    $page = Page::factory()->create(['title' => 'العنوان الحالي']);
    $change = PageChangeRequest::factory()->create([
        'page_id' => $page->id,
        'status' => PageChangeRequest::STATUS_PENDING,
        'payload' => ['title' => 'العنوان المقترح'],
    ]);

    Passport::actingAs(makeUser('editor'));

    $response = postJson('/mcp/admin', adminRpc('show_page_change', ['change_request_id' => $change->id]))->assertOk();
    $text = adminResultText($response);

    expect($text)->toContain('العنوان الحالي')
        ->and($text)->toContain('العنوان المقترح');
});

it('lets a moderator create, list, and reorder tutor courses (taxonomy the MCP server previously could not manage)', function () {
    Passport::actingAs(makeUser('admin'));

    postJson('/mcp/admin', adminRpc('create_course', ['name' => 'خوارزميات']))->assertOk();
    postJson('/mcp/admin', adminRpc('create_course', ['name' => 'قواعد بيانات']))->assertOk();

    $algorithms = PrivateTutorCourse::query()->where('name', 'خوارزميات')->firstOrFail();
    $databases = PrivateTutorCourse::query()->where('name', 'قواعد بيانات')->firstOrFail();

    $list = adminResultText(postJson('/mcp/admin', adminRpc('list_tutors'))->assertOk());
    expect($list)->toContain('خوارزميات')->and($list)->toContain('قواعد بيانات');

    postJson('/mcp/admin', adminRpc('reorder_courses', ['ids' => [$databases->id, $algorithms->id]]))->assertOk();

    expect($databases->fresh()->order)->toBe(1)
        ->and($algorithms->fresh()->order)->toBe(2);

    postJson('/mcp/admin', adminRpc('update_course', ['course_id' => $algorithms->id, 'name' => 'تصميم خوارزميات']))->assertOk();
    expect($algorithms->fresh()->name)->toBe('تصميم خوارزميات');
});

it('reorders tutors with sequential orders through Eloquent', function () {
    $first = PrivateTutor::create(['name' => 'أ. أول', 'order' => 1]);
    $second = PrivateTutor::create(['name' => 'أ. ثانٍ', 'order' => 2]);

    Passport::actingAs(makeUser('admin'));

    postJson('/mcp/admin', adminRpc('reorder_tutors', ['ids' => [$second->id, $first->id]]))->assertOk();

    expect($second->fresh()->order)->toBe(1)
        ->and($first->fresh()->order)->toBe(2);
});

it('exposes the current date and site inventory through site_overview', function () {
    Passport::actingAs(makeUser('editor'));

    $text = adminResultText(postJson('/mcp/admin', adminRpc('site_overview'))->assertOk());

    expect($text)->toContain(now()->format('Y-m-d'))
        ->and($text)->toContain('نظرة عامة على الموقع');
});

it('lists the application routes through list_routes', function () {
    Passport::actingAs(makeUser('editor'));

    $text = adminResultText(postJson('/mcp/admin', adminRpc('list_routes', ['filter' => 'manage.assistant']))->assertOk());

    expect($text)->toContain('manage.assistant.index');
});

it('toggles a Telegram chat AI setting by chat_id', function () {
    $chat = App\Models\TelegramChatSetting::query()->create([
        'chat_id' => 123456789,
        'title' => 'مجموعة',
        'type' => 'group',
        'ai_enabled' => false,
    ]);

    Passport::actingAs(makeUser('admin'));

    postJson('/mcp/admin', adminRpc('set_telegram_chat_ai', ['chat_id' => '123456789', 'enabled' => true]))->assertOk();

    expect($chat->fresh()->ai_enabled)->toBeTrue();
});

it('summarizes analytics as readable text', function () {
    Passport::actingAs(makeUser('admin'));

    $text = adminResultText(postJson('/mcp/admin', adminRpc('get_analytics'))->assertOk());

    expect($text)->toContain('إحصاءات الموقع');
});

it('gates the activity log behind view-activity-logs', function () {
    Passport::actingAs(makeUser('editor'));

    postJson('/mcp/admin', adminRpc('list_activity_log'))->assertOk();
});

it('clears the application cache', function () {
    Passport::actingAs(makeUser('admin'));

    postJson('/mcp/admin', adminRpc('clear_cache'))->assertOk();
});
