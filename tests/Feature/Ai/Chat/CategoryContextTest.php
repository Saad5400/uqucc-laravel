<?php

use App\Ai\Chat\CategoryContext;
use App\Ai\Chat\QuestionCategory;
use App\Ai\Chat\QuestionClassifier;
use App\Ai\Corpus\CorpusSourceType;
use App\Models\Corpus\CorpusDocument;
use App\Models\Corpus\CorpusItem;
use App\Models\Page;
use App\Settings\AiSettings;

beforeEach(function () {
    config()->set('app.url', 'https://uqucc.sb.sa');
    config()->set('ai.embeddings.driver', 'fake');
    config()->set('ai.embeddings.dimensions', 64);

    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->search_enabled = true;
    $settings->save();
});

function classifier(): QuestionClassifier
{
    return app(QuestionClassifier::class);
}

function categoryContext(): CategoryContext
{
    return app(CategoryContext::class);
}

/**
 * A retrievable document: ready file plus a ready, enabled corpus item.
 */
function readableDocument(string $title, ?string $referenceUrl = null): CorpusDocument
{
    $document = CorpusDocument::factory()->ready()->create([
        'title' => $title,
        'reference_url' => $referenceUrl,
    ]);

    CorpusItem::factory()->create([
        'source_type' => CorpusSourceType::Document,
        'source_id' => $document->id,
        'title' => $title,
        'slug' => null,
    ]);

    return $document;
}

it('classifies majors questions in every phrasing that produced the bug', function (string $question) {
    expect(classifier()->classify($question))->toBe(QuestionCategory::Majors);
})->with([
    'وش أفضل تخصص بكلية الحاسبات',
    'وش اسهل تخصص في تخصصات الحاسب؟',
    'ايهما أفضل الأمن السيبراني أو هندسة البرمجيات',
    'كم معدل القبول في التخصصات؟',
    'which major has the most jobs?',
]);

it('leaves questions that are not about majors uncategorized', function (string $question) {
    expect(classifier()->classify($question))->toBeNull();
})->with([
    'كم مكافأة الامتياز؟',
    'متى يبدأ الحذف والإضافة',
    'اشرح لي الـ recursion',
    'the majority of my grades are B',
]);

it('folds arabic spelling variants the way the corpus index does', function () {
    expect(classifier()->classify('وش أحسن تخصّص؟'))->toBe(QuestionCategory::Majors);
});

it('passes an uncategorized message through byte-identically', function () {
    readableDocument('لائحة الدراسة والاختبارات');

    $message = 'كم مكافأة الامتياز؟';

    expect(categoryContext()->wrap($message, $message))->toBe($message);
});

it('hands the model the document index so it cannot invent a major it has no source for', function () {
    readableDocument('تعريف عن تخصص علم البيانات', 'https://uqucc-majors.sb.sa/ds');

    $wrapped = categoryContext()->wrap('وش أفضل تخصص؟', 'وش أفضل تخصص؟');

    expect($wrapped)
        ->toContain('تعريف عن تخصص علم البيانات')
        ->toContain('https://uqucc-majors.sb.sa/ds')
        ->toContain('get_document')
        ->toContain('رسالة المستخدم:')
        ->toEndWith('وش أفضل تخصص؟');
});

it('lists a document by the id get_document expects', function () {
    $document = readableDocument('تعريف عن تخصص الذكاء الاصطناعي');

    expect(categoryContext()->wrap('وش أفضل تخصص؟', 'وش أفضل تخصص؟'))
        ->toContain('المستند '.$document->id.': تعريف عن تخصص الذكاء الاصطناعي');
});

it('never advertises a document the admin switched off', function () {
    $document = CorpusDocument::factory()->ready()->create(['title' => 'مسودة قديمة عن التخصصات']);

    CorpusItem::factory()->disabled()->create([
        'source_type' => CorpusSourceType::Document,
        'source_id' => $document->id,
        'slug' => null,
    ]);

    expect(categoryContext()->wrap('وش أفضل تخصص؟', 'وش أفضل تخصص؟'))
        ->not->toContain('مسودة قديمة عن التخصصات');
});

it('injects the category guidance alongside the evidence', function () {
    readableDocument('تعريف عن تخصص هندسة البرمجيات');

    expect(categoryContext()->wrap('وش أفضل تخصص؟', 'وش أفضل تخصص؟'))
        ->toContain('مساهمات طلابية غير رسمية')
        ->toContain('أعِد القرار للطالب');
});

it('wraps the prompt an inner wrapper already built, not the raw question', function () {
    readableDocument('تعريف عن تخصص علوم الحاسب');

    $wrapped = categoryContext()->wrap("[مرفقات المستخدم]\nنص الملف\n\nوش أفضل تخصص؟", 'وش أفضل تخصص؟');

    expect($wrapped)->toContain("[مرفقات المستخدم]\nنص الملف");
});

it('unwraps back to exactly what the inner wrapper produced', function () {
    readableDocument('تعريف عن تخصص علوم الحاسب');

    $inner = 'وش أفضل تخصص؟';

    expect(categoryContext()->unwrap(categoryContext()->wrap($inner, $inner)))->toBe($inner);
});

it('leaves content it did not wrap alone when unwrapping', function () {
    expect(categoryContext()->unwrap('رسالة المستخدم:'."\n".'شيء آخر'))
        ->toBe('رسالة المستخدم:'."\n".'شيء آخر');
});

it('runs the search the model kept skipping and hands over the hits', function () {
    Page::factory()->create([
        'title' => 'دليل التخصصات',
        'slug' => '/altkhssusat',
        'html_content' => [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'يدرس طالب تخصص علم البيانات مقررات الإحصاء والتنقيب في البيانات']],
            ]],
        ],
    ]);

    expect(categoryContext()->wrap('وش أفضل تخصص؟', 'وش أفضل تخصص؟'))
        ->toContain('مقتطفات من محتوى الدليل')
        ->toContain('(slug: /altkhssusat)')
        ->toContain('التنقيب في البيانات');
});

it('still injects the index when retrieval finds nothing', function () {
    readableDocument('تعريف عن تخصص تفاعل الانسان مع الحاسب');

    expect(categoryContext()->wrap('وش أفضل تخصص؟', 'وش أفضل تخصص؟'))
        ->toContain('فهرس المستندات المتاحة للقراءة');
});
