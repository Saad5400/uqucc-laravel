<?php

use App\Models\Page;
use App\Settings\AiSettings;

beforeEach(function () {
    config()->set('ai.embeddings.driver', 'fake');
    config()->set('ai.embeddings.dimensions', 64);

    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->search_enabled = true;
    $settings->save();
});

function seedSearchablePage(string $title, string $body): Page
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

it('returns matching results for a valid arabic query', function () {
    $plan = seedSearchablePage('الخطة الدراسية', 'تحتوي الخطة على مقررات البرمجة وهياكل البيانات');
    seedSearchablePage('القبول والتسجيل', 'شروط القبول تتطلب اجتياز اختبار القدرات العامة');

    $response = $this->getJson(route('search', ['q' => 'مقررات البرمجة']));

    $response->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('results.0.title', 'الخطة الدراسية')
        ->assertJsonPath('results.0.slug', $plan->slug)
        ->assertJsonStructure([
            'enabled',
            'results' => [
                ['title', 'slug', 'heading', 'snippet', 'score'],
            ],
        ]);

    expect($response->json('results.0.snippet'))->toContain('البرمجة')
        ->and($response->json('results.0.score'))->toBeGreaterThan(0.0);
});

it('returns an empty result set when nothing matches', function () {
    seedSearchablePage('صفحة', 'محتوى عادي تماما');

    $this->getJson(route('search', ['q' => 'xyzzy quixotic']))
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('results', []);
});

it('respects the limit parameter', function () {
    foreach (range(1, 5) as $i) {
        seedSearchablePage("صفحة رقم {$i}", "شرح البرمجة والتطوير في الصفحة رقم {$i}");
    }

    $response = $this->getJson(route('search', ['q' => 'البرمجة', 'limit' => 2]));

    $response->assertOk()
        ->assertJsonCount(2, 'results');
});

it('rejects invalid input', function (array $query, string $field) {
    $this->getJson(route('search', $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing q' => [[], 'q'],
    'q too short' => [['q' => 'ب'], 'q'],
    'q too long' => [['q' => str_repeat('ب', 101)], 'q'],
    'limit not an integer' => [['q' => 'البرمجة', 'limit' => 'كثير'], 'limit'],
    'limit of zero' => [['q' => 'البرمجة', 'limit' => 0], 'limit'],
    'limit above the cap' => [['q' => 'البرمجة', 'limit' => 21], 'limit'],
]);

it('returns a feature-disabled response when the search toggle is off', function () {
    seedSearchablePage('الخطة الدراسية', 'تحتوي الخطة على مقررات البرمجة');

    $settings = app(AiSettings::class);
    $settings->search_enabled = false;
    $settings->save();

    $this->getJson(route('search', ['q' => 'البرمجة']))
        ->assertServiceUnavailable()
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('results', []);
});

it('returns a feature-disabled response when the master ai kill switch is off', function () {
    seedSearchablePage('الخطة الدراسية', 'تحتوي الخطة على مقررات البرمجة');

    $settings = app(AiSettings::class);
    $settings->ai_enabled = false;
    $settings->save();

    $this->getJson(route('search', ['q' => 'البرمجة']))
        ->assertServiceUnavailable()
        ->assertJsonPath('enabled', false);
});

it('rate limits the endpoint after 20 requests per minute', function () {
    foreach (range(1, 20) as $i) {
        $this->getJson(route('search', ['q' => 'البرمجة']))->assertOk();
    }

    $this->getJson(route('search', ['q' => 'البرمجة']))->assertTooManyRequests();
});
