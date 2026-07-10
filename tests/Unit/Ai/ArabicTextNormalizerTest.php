<?php

use App\Ai\Corpus\ArabicTextNormalizer;

it('normalizes alef variants so hamza spelling differences match', function () {
    $normalizer = new ArabicTextNormalizer;

    expect($normalizer->normalize('أحكام'))->toBe($normalizer->normalize('احكام'))
        ->and($normalizer->normalize('إسلام'))->toBe($normalizer->normalize('اسلام'))
        ->and($normalizer->normalize('آداب'))->toBe($normalizer->normalize('اداب'));
});

it('strips tashkeel (harakat, shadda, tanween)', function () {
    $normalizer = new ArabicTextNormalizer;

    expect($normalizer->normalize('مُحَمَّد'))->toBe('محمد')
        ->and($normalizer->normalize('مُقَرَّرات'))->toBe($normalizer->normalize('مقررات'))
        ->and($normalizer->normalize('كتابٌ'))->toBe($normalizer->normalize('كتاب'));
});

it('strips tatweel', function () {
    $normalizer = new ArabicTextNormalizer;

    expect($normalizer->normalize('مـــرحـبا'))->toBe('مرحبا');
});

it('unifies taa marbuta and hamza carriers', function () {
    $normalizer = new ArabicTextNormalizer;

    expect($normalizer->normalize('الجامعة'))->toBe($normalizer->normalize('الجامعه'))
        ->and($normalizer->normalize('سؤال'))->toBe($normalizer->normalize('سءال'));
});

it('lowercases latin text and collapses whitespace', function () {
    $normalizer = new ArabicTextNormalizer;

    expect($normalizer->normalize("  Hello   WORLD \n new\tline "))->toBe('hello world new line');
});

it('tokenizes into unique tokens and drops single-character noise', function () {
    $normalizer = new ArabicTextNormalizer;

    $tokens = $normalizer->tokenize('البرمجة و البرمجة ممتعة!');

    expect($tokens)->toBe([
        $normalizer->normalize('البرمجة'),
        $normalizer->normalize('ممتعة'),
    ]);
});

it('applies the identical folding at index and query time', function () {
    $normalizer = new ArabicTextNormalizer;

    $indexed = $normalizer->normalize('مُقَرَّرات البَرمجة الأساسية');
    $queried = $normalizer->normalize('مقررات البرمجه الاساسيه');

    expect($indexed)->toBe($queried);
});
