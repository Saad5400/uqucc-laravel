<?php

use App\Ai\Tools\CalculateDeprivationTool;
use App\Mcp\Servers\UqccServer;
use App\Mcp\Tools\ReadOnlyToolAdapter;
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

function mcpJsonRpc(array $overrides = []): array
{
    return array_merge([
        'jsonrpc' => '2.0',
        'id' => 1,
    ], $overrides);
}

it('lists all nine tools over the http transport', function () {
    $response = $this->postJson('/mcp', mcpJsonRpc(['method' => 'tools/list']));

    $response->assertOk();

    $names = collect($response->json('result.tools'))->pluck('name')->all();

    expect($names)->toBe([
        'search_content',
        'get_page',
        'get_document',
        'calculate_gpa',
        'calculate_deprivation',
        'calculate_transfer',
        'truth_table',
        'date_time',
        'list_stale_pages',
    ]);

    expect($response->json('result.tools.0.description'))->toContain('uqucc')
        ->and($response->json('result.tools.3.inputSchema.properties.courses.type'))->toBe('array')
        ->and($response->json('result.tools.0.annotations.readOnlyHint'))->toBeTrue();
});

it('answers initialize with the bilingual server description', function () {
    $response = $this->postJson('/mcp', mcpJsonRpc([
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0'],
        ],
    ]));

    $response->assertOk();

    expect($response->json('result.serverInfo.name'))->toBe('UQU CC Student Guide')
        ->and($response->json('result.instructions'))->toContain('Umm Al-Qura')
        ->and($response->json('result.instructions'))->toContain('كلية الحاسبات');
});

it('executes a tool call over the http transport', function () {
    $response = $this->postJson('/mcp', mcpJsonRpc([
        'method' => 'tools/call',
        'params' => [
            'name' => 'calculate_gpa',
            'arguments' => [
                'courses' => [
                    ['credits' => '3', 'grade' => 'A+'],
                    ['credits' => '3', 'grade' => 'B'],
                ],
            ],
        ],
    ]));

    $response->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.content.0.text'))->toContain('3.5');
});

it('generates a truth table over the http transport', function () {
    $response = $this->postJson('/mcp', mcpJsonRpc([
        'method' => 'tools/call',
        'params' => [
            'name' => 'truth_table',
            'arguments' => ['formula' => 'p /\ q -> ~r'],
        ],
    ]));

    $response->assertOk();

    expect($response->json('result.isError'))->toBeFalse()
        ->and($response->json('result.content.0.text'))->toContain('p ∧ q → ¬r')
        ->and($response->json('result.content.0.text'))->toContain('ممكنة');
});

it('serves search results end to end through mcp', function () {
    Page::factory()->create([
        'title' => 'الخطة الدراسية',
        'html_content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'تحتوي الخطة على مقررات البرمجة']]],
            ],
        ],
    ]);

    $response = $this->postJson('/mcp', mcpJsonRpc([
        'method' => 'tools/call',
        'params' => ['name' => 'search_content', 'arguments' => ['query' => 'مقررات البرمجة']],
    ]));

    expect($response->json('result.content.0.text'))->toContain('الخطة الدراسية');
});

it('returns the disabled message through mcp when the master ai kill switch is off', function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = false;
    $settings->save();

    $response = $this->postJson('/mcp', mcpJsonRpc([
        'method' => 'tools/call',
        'params' => [
            'name' => 'calculate_transfer',
            'arguments' => ['weighted_score' => 80, 'cumulative_gpa' => 4],
        ],
    ]));

    $response->assertOk();

    expect($response->json('result.content.0.text'))->toContain('معطلة');
});

it('supports the package test helper against a registered tool', function () {
    UqccServer::tool(new ReadOnlyToolAdapter(CalculateDeprivationTool::class), [
        'lectures_per_week' => 2,
        'unexcused_hours' => 4,
        'excused_hours' => 4,
    ])
        ->assertOk()
        ->assertSee('غير محروم')
        ->assertSee('23.53');
});

it('rejects a GET request with 405 instead of the page catch-all', function () {
    $this->get('/mcp')->assertStatus(405);
});

it('rate limits the endpoint after 30 requests per minute', function () {
    foreach (range(1, 30) as $i) {
        $this->postJson('/mcp', mcpJsonRpc(['method' => 'ping', 'id' => $i]))->assertOk();
    }

    $this->postJson('/mcp', mcpJsonRpc(['method' => 'ping', 'id' => 31]))->assertTooManyRequests();
});
