<?php

namespace Database\Factories\Ai;

use App\Models\Ai\ChatAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChatAttachment>
 */
class ChatAttachmentFactory extends Factory
{
    protected $model = ChatAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->unique()->slug(2).'.pdf';

        return [
            'session_id' => Str::random(40),
            'conversation_id' => null,
            'original_filename' => $filename,
            'disk' => ChatAttachment::DISK,
            'path' => ChatAttachment::DIRECTORY.'/'.$filename,
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(10_000, 2_000_000),
            'status' => ChatAttachment::STATUS_PENDING,
            'extracted_markdown' => null,
            'error' => null,
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
            'path' => ChatAttachment::DIRECTORY.'/'.$filename,
            'mime' => 'image/png',
        ]);
    }

    /**
     * An attachment whose text has been extracted successfully.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ChatAttachment::STATUS_READY,
            'extracted_markdown' => "## السجل الأكاديمي\n\n".fake()->paragraph(),
        ]);
    }

    /**
     * An attachment whose extraction failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ChatAttachment::STATUS_FAILED,
            'error' => 'تعذر استخراج النص من الملف.',
        ]);
    }
}
