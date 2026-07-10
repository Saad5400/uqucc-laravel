<?php

namespace Database\Factories\Corpus;

use App\Models\Corpus\CorpusDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CorpusDocument>
 */
class CorpusDocumentFactory extends Factory
{
    protected $model = CorpusDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->unique()->slug(2).'.pdf';

        return [
            'title' => fake()->sentence(3),
            'original_filename' => $filename,
            'disk' => CorpusDocument::DISK,
            'path' => CorpusDocument::DIRECTORY.'/'.$filename,
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(10_000, 2_000_000),
            'status' => CorpusDocument::STATUS_PENDING,
            'extracted_markdown' => null,
            'error' => null,
            'uploaded_by' => null,
        ];
    }

    /**
     * An image upload instead of a PDF.
     */
    public function image(): static
    {
        $filename = fake()->unique()->slug(2).'.png';

        return $this->state(fn (array $attributes): array => [
            'original_filename' => $filename,
            'path' => CorpusDocument::DIRECTORY.'/'.$filename,
            'mime' => 'image/png',
        ]);
    }

    /**
     * A document whose text has been extracted successfully.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CorpusDocument::STATUS_READY,
            'extracted_markdown' => "## لائحة الدراسة\n\n".fake()->paragraph(),
        ]);
    }

    /**
     * A document whose extraction failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CorpusDocument::STATUS_FAILED,
            'error' => 'تعذر استخراج النص من الملف.',
        ]);
    }
}
