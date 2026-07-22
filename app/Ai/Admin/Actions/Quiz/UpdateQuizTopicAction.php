<?php

namespace App\Ai\Admin\Actions\Quiz;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\QuizTopic;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Edit a daily-quiz topic, mirroring
 * {@see \App\Http\Controllers\Manage\QuizTopicController::update()}. Identify
 * the topic by its id (from list_quiz_topics); only the fields you pass change.
 * Toggling is_active off keeps a topic without it being picked; is_spotlight
 * restricts it to the weekly spotlight day.
 */
class UpdateQuizTopicAction extends AdminAction
{
    public function name(): string
    {
        return 'update_quiz_topic';
    }

    public function category(): string
    {
        return 'quiz';
    }

    public function description(): string
    {
        return 'Update a daily-quiz topic (تعديل موضوع سؤال اليوم). '
            .'Provide topic_id (from list_quiz_topics) and any of name, prompt_hint, is_spotlight, is_active — '
            .'only the fields you pass change. Set is_active=false to keep a topic without it being picked.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic_id' => $schema->integer()
                ->description('The id of the topic to edit, from list_quiz_topics.')
                ->required(),
            'name' => $schema->string()->description('New topic name.'),
            'prompt_hint' => $schema->string()->description('New generation hint. Pass an empty string to clear it.'),
            'is_spotlight' => $schema->boolean()->description('Whether this is a weekly spotlight (major-specific) topic.'),
            'is_active' => $schema->boolean()->description('Whether the topic is active (eligible to be picked).'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $topic = QuizTopic::query()->find($input['topic_id'] ?? null);

        if ($topic === null) {
            throw new AdminActionException('لا يوجد موضوع بهذا المعرّف. استخدم list_quiz_topics للتأكد.');
        }

        $changes = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);

            if ($name === '') {
                throw new AdminActionException('اسم الموضوع لا يمكن أن يكون فارغاً.');
            }

            if (mb_strlen($name) > 255) {
                throw new AdminActionException('اسم الموضوع طويل جداً (الحد 255 حرفاً).');
            }

            $changes['name'] = $name;
        }

        if (array_key_exists('prompt_hint', $input)) {
            $hint = $input['prompt_hint'] === null ? '' : trim((string) $input['prompt_hint']);

            if (mb_strlen($hint) > 2000) {
                throw new AdminActionException('توجيهات الموضوع طويلة جداً (الحد 2000 حرف).');
            }

            $changes['prompt_hint'] = $hint === '' ? null : $hint;
        }

        if (array_key_exists('is_spotlight', $input)) {
            $changes['is_spotlight'] = (bool) $input['is_spotlight'];
        }

        if (array_key_exists('is_active', $input)) {
            $changes['is_active'] = (bool) $input['is_active'];
        }

        if ($changes === []) {
            throw new AdminActionException('لم تُحدَّد أي حقول للتعديل.');
        }

        return [
            'topic_id' => $topic->id,
            'topic_name' => $topic->name,
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        $labels = [
            'name' => 'الاسم',
            'prompt_hint' => 'التوجيه',
            'is_spotlight' => 'يوم التخصص',
            'is_active' => 'التفعيل',
        ];

        $fields = collect($normalized['changes'])
            ->keys()
            ->map(fn (string $key): string => $labels[$key] ?? $key)
            ->implode('، ');

        return 'تعديل موضوع سؤال اليوم «'.$normalized['topic_name'].'» ('.$fields.').';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $topic = QuizTopic::query()->find($normalized['topic_id']);

        if ($topic === null) {
            throw new AdminActionException('الموضوع المستهدف لم يعد موجوداً.');
        }

        $topic->update($normalized['changes']);

        return ActionResult::text('تم تعديل موضوع سؤال اليوم «'.$topic->name.'».');
    }
}
