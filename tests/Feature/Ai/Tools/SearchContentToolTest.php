<?php

use App\Ai\Tools\SearchContentTool;
use App\Models\Page;
use App\Settings\AiSettings;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    config()->set('ai.embeddings.driver', 'fake');
    config()->set('ai.embeddings.dimensions', 64);

    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->search_enabled = true;
    $settings->save();
});

function seedToolSearchablePage(string $title, string $body): Page
{
    return Page::factory()->create([
        'title' => $title,
        'html_content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $body]]],
            ],
        ],
    ]);
}

it('returns matching content with title, slug and snippet', function () {
    $plan = seedToolSearchablePage('الخطة الدراسية', 'تحتوي الخطة على مقررات البرمجة وهياكل البيانات');
    seedToolSearchablePage('القبول والتسجيل', 'شروط القبول تتطلب اجتياز اختبار القدرات العامة');

    $reply = (string) app(SearchContentTool::class)->handle(new Request(['query' => 'مقررات البرمجة']));

    expect($reply)->toContain('الخطة الدراسية')
        ->toContain($plan->slug)
        ->toContain('البرمجة')
        ->toContain('آخر تحديث: '.$plan->fresh()->updated_at->toDateString());
});

it('respects the limit argument', function () {
    foreach (range(1, 5) as $i) {
        seedToolSearchablePage("صفحة رقم {$i}", "شرح البرمجة والتطوير في الصفحة رقم {$i}");
    }

    $reply = (string) app(SearchContentTool::class)->handle(new Request(['query' => 'البرمجة', 'limit' => 2]));

    expect(preg_match_all('/^\d+\. /mu', $reply))->toBe(2);
});

it('reports when nothing matches', function () {
    seedToolSearchablePage('صفحة', 'محتوى عادي تماما');

    $reply = (string) app(SearchContentTool::class)->handle(new Request(['query' => 'xyzzy quixotic']));

    expect($reply)->toContain('لا توجد نتائج');
});

it('rejects a too-short query', function () {
    $reply = (string) app(SearchContentTool::class)->handle(new Request(['query' => 'ب']));

    expect($reply)->toContain('حرفين على الأقل');
});

it('returns a disabled message when the search toggle is off', function () {
    seedToolSearchablePage('الخطة الدراسية', 'تحتوي الخطة على مقررات البرمجة');

    $settings = app(AiSettings::class);
    $settings->search_enabled = false;
    $settings->save();

    $reply = (string) app(SearchContentTool::class)->handle(new Request(['query' => 'البرمجة']));

    expect($reply)->toContain('غير متاح')
        ->not->toContain('الخطة الدراسية');
});

it('returns a disabled message when the master ai kill switch is off', function () {
    seedToolSearchablePage('الخطة الدراسية', 'تحتوي الخطة على مقررات البرمجة');

    $settings = app(AiSettings::class);
    $settings->ai_enabled = false;
    $settings->save();

    $reply = (string) app(SearchContentTool::class)->handle(new Request(['query' => 'البرمجة']));

    expect($reply)->toContain('غير متاح')
        ->not->toContain('الخطة الدراسية');
});
