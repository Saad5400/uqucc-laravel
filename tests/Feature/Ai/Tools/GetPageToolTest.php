<?php

use App\Ai\Tools\GetPageTool;
use App\Models\Page;
use App\Settings\AiSettings;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->save();
});

it('returns the full page as markdown by slug', function () {
    $page = Page::factory()->create([
        'slug' => '/adwat/dalil-altahwil',
        'title' => 'دليل التحويل',
        'hidden' => false,
        'html_content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'الشروط']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'يشترط اجتياز مقررات السنة الأولى المشتركة.']]],
            ],
        ],
    ]);

    $reply = (string) app(GetPageTool::class)->handle(new Request(['slug' => $page->slug]));

    expect($reply)->toContain('# دليل التحويل')
        ->toContain('## الشروط')
        ->toContain('يشترط اجتياز مقررات السنة الأولى المشتركة.')
        ->toContain('slug: /adwat/dalil-altahwil')
        ->toContain('آخر تحديث: '.$page->fresh()->updated_at->toDateString());
});

it('accepts the slug without a leading slash', function () {
    Page::factory()->create([
        'slug' => '/adwat/dalil-altahwil',
        'title' => 'دليل التحويل',
        'hidden' => false,
    ]);

    $reply = (string) app(GetPageTool::class)->handle(new Request(['slug' => 'adwat/dalil-altahwil']));

    expect($reply)->toContain('# دليل التحويل');
});

it('never exposes hidden pages', function () {
    Page::factory()->create([
        'slug' => '/srri',
        'title' => 'صفحة مخفية',
        'hidden' => true,
    ]);

    $reply = (string) app(GetPageTool::class)->handle(new Request(['slug' => '/srri']));

    expect($reply)->toContain('لم يتم العثور')
        ->not->toContain('صفحة مخفية');
});

it('reports an unknown slug', function () {
    $reply = (string) app(GetPageTool::class)->handle(new Request(['slug' => '/gheir-mawjood']));

    expect($reply)->toContain('لم يتم العثور');
});

it('asks for a slug when none is given', function () {
    $reply = (string) app(GetPageTool::class)->handle(new Request([]));

    expect($reply)->toContain('يرجى تحديد');
});

it('returns a disabled message when the master ai kill switch is off', function () {
    Page::factory()->create(['slug' => '/mawjooda', 'title' => 'موجودة', 'hidden' => false]);

    $settings = app(AiSettings::class);
    $settings->ai_enabled = false;
    $settings->save();

    $reply = (string) app(GetPageTool::class)->handle(new Request(['slug' => '/mawjooda']));

    expect($reply)->toContain('معطلة')
        ->not->toContain('موجودة');
});
