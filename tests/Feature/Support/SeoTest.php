<?php

use App\Models\Page;
use App\Support\Seo;

it('strips html tags from quick_response_message when building the description', function () {
    $page = Page::factory()->create([
        'slug' => '/tahwel',
        'quick_response_message' => '<b>حاسبة</b> التحويل<br><a href="https://x.test">اضغط هنا</a>',
        'html_content' => '',
    ]);

    $description = Seo::descriptionFor($page->fresh());

    expect($description)
        ->not->toContain('<')
        ->not->toContain('>')
        ->toContain('حاسبة التحويل')
        ->toContain('اضغط هنا');
});

it('falls back to the default description when quick_response_message is visually empty html', function () {
    $page = Page::factory()->create([
        'slug' => '/empty-message',
        'quick_response_message' => '<p></p>',
        'html_content' => '',
    ]);

    $description = Seo::descriptionFor($page->fresh());

    expect($description)->toBe(Seo::DEFAULT_DESCRIPTION);
});

it('decodes html entities left over after stripping tags', function () {
    $page = Page::factory()->create([
        'slug' => '/entities',
        'quick_response_message' => '<p>الرياضيات&nbsp;&amp;&nbsp;الإحصاء</p>',
        'html_content' => '',
    ]);

    $description = Seo::descriptionFor($page->fresh());

    expect($description)
        ->toBe('الرياضيات & الإحصاء')
        ->not->toContain('&amp;')
        ->not->toContain('&nbsp;');
});

it('uses html_content when quick_response_message is empty', function () {
    $page = Page::factory()->create([
        'slug' => '/from-content',
        'quick_response_message' => null,
        'html_content' => '<p>محتوى الصفحة الحقيقي</p>',
    ]);

    $description = Seo::descriptionFor($page->fresh());

    expect($description)->toBe('محتوى الصفحة الحقيقي');
});
