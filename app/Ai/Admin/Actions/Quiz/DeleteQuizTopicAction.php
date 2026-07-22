<?php

namespace App\Ai\Admin\Actions\Quiz;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\QuizTopic;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Delete a daily-quiz topic, mirroring
 * {@see \App\Http\Controllers\Manage\QuizTopicController::destroy()}. Identify
 * the topic by its id (from list_quiz_topics). Prefer setting is_active=false
 * with update_quiz_topic when the topic might be wanted again.
 */
class DeleteQuizTopicAction extends AdminAction
{
    public function name(): string
    {
        return 'delete_quiz_topic';
    }

    public function category(): string
    {
        return 'quiz';
    }

    public function description(): string
    {
        return 'Delete a daily-quiz topic (حذف موضوع سؤال اليوم). '
            .'Provide topic_id (from list_quiz_topics). This is permanent — to only stop it being picked, '
            .'use update_quiz_topic with is_active=false instead.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic_id' => $schema->integer()
                ->description('The id of the topic to delete, from list_quiz_topics.')
                ->required(),
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

        return [
            'topic_id' => $topic->id,
            'topic_name' => $topic->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'حذف موضوع سؤال اليوم «'.$normalized['topic_name'].'» نهائياً.';
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

        $name = $topic->name;
        $topic->delete();

        return ActionResult::text('تم حذف موضوع سؤال اليوم «'.$name.'».');
    }
}
