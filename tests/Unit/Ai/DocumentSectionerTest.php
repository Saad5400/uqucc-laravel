<?php

use App\Ai\Corpus\DocumentSection;
use App\Ai\Corpus\DocumentSectioner;

it('splits markdown at headings, keeping each heading line inside its section', function () {
    $markdown = "مقدمة قبل أول عنوان.\n\n# الباب الأول\nنص الباب الأول.\n\n## المادة الأولى\nنص المادة الأولى.";

    $sections = (new DocumentSectioner)->sections($markdown);

    expect($sections)->toHaveCount(3)
        ->and($sections[0]->number)->toBe(1)
        ->and($sections[0]->heading)->toBeNull()
        ->and($sections[0]->content)->toBe('مقدمة قبل أول عنوان.')
        ->and($sections[1]->heading)->toBe('الباب الأول')
        ->and($sections[1]->level)->toBe(1)
        ->and($sections[1]->content)->toBe("# الباب الأول\nنص الباب الأول.")
        ->and($sections[2]->heading)->toBe('المادة الأولى')
        ->and($sections[2]->level)->toBe(2)
        ->and($sections[2]->content)->toBe("## المادة الأولى\nنص المادة الأولى.");
});

it('is deterministic and numbers sections 1-based in document order', function () {
    $markdown = "# أ\nواحد\n# ب\nاثنان";

    $first = (new DocumentSectioner)->sections($markdown);
    $second = (new DocumentSectioner)->sections($markdown);

    expect($first)->toEqual($second)
        ->and(array_map(fn (DocumentSection $s): int => $s->number, $first))->toBe([1, 2]);
});

it('splits an oversized section into continuation parts that preserve every word', function () {
    $body = implode("\n", array_map(
        fn (int $i): string => trim("سطر رقم {$i} ".str_repeat('كلمة ', 20)),
        range(1, 200),
    ));
    $markdown = "# قسم ضخم\n".$body;

    $sections = (new DocumentSectioner(maxChars: 2000))->sections($markdown);

    expect(count($sections))->toBeGreaterThan(1)
        ->and($sections[0]->continuation)->toBeFalse()
        ->and($sections[1]->continuation)->toBeTrue()
        ->and($sections[1]->heading)->toBe('قسم ضخم');

    foreach ($sections as $section) {
        expect(mb_strlen($section->content))->toBeLessThanOrEqual(2000);
    }

    $reassembled = implode("\n", array_map(fn (DocumentSection $s): string => $s->content, $sections));

    expect($reassembled)->toBe($markdown);
});

it('splits a single line longer than the cap at word boundaries', function () {
    $markdown = str_repeat('كلمة_طويلة ', 500);

    $sections = (new DocumentSectioner(maxChars: 1000))->sections($markdown);

    expect(count($sections))->toBeGreaterThan(1);

    foreach ($sections as $section) {
        expect($section->content)->not->toContain("\n")
            ->and(mb_strlen($section->content))->toBeLessThanOrEqual(1000);
    }
});

it('drops blank blocks so every section has content', function () {
    $markdown = "\n\n# عنوان بلا نص\n\n\n# عنوان بنص\nالمحتوى هنا.";

    $sections = (new DocumentSectioner)->sections($markdown);

    expect(array_map(fn (DocumentSection $s): ?string => $s->heading, $sections))
        ->toBe(['عنوان بلا نص', 'عنوان بنص']);
});
