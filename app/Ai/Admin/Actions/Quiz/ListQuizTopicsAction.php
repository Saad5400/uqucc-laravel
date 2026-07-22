<?php

namespace App\Ai\Admin\Actions\Quiz;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Models\QuizTopic;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The admin-curated themes the daily quiz generates from, each with its id,
 * name, optional prompt hint, whether it is a weekly major-spotlight topic,
 * whether it is active, and when it was last used. Mirrors the topic list on
 * {@see \App\Http\Controllers\Manage\QuizController::index()}. Use the returned
 * ids for update_quiz_topic and delete_quiz_topic. Read-only.
 */
class ListQuizTopicsAction extends AdminAction
{
    public function name(): string
    {
        return 'list_quiz_topics';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'quiz';
    }

    public function description(): string
    {
        return 'List the daily-quiz topics the questions are generated from — id, name, prompt hint, '
            .'whether it is a weekly spotlight topic, whether it is active, and when it was last used '
            .'(عرض مواضيع سؤال اليوم التي تُولّد منها الأسئلة). '
            .'Use the returned ids for update_quiz_topic and delete_quiz_topic. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $topics = QuizTopic::query()
            ->orderByDesc('is_active')
            ->orderBy('is_spotlight')
            ->orderBy('name')
            ->get();

        if ($topics->isEmpty()) {
            return ActionResult::text('لا توجد مواضيع لسؤال اليوم بعد — أضف موضوعاً بـ create_quiz_topic.');
        }

        $lines = $topics->map(fn (QuizTopic $topic): string => sprintf(
            '- id=%d | %s | %s | %s | آخر استخدام: %s%s',
            $topic->id,
            $topic->name,
            $topic->is_active ? 'مفعّل' : 'معطّل',
            $topic->is_spotlight ? 'يوم تخصص' : 'عام',
            $topic->last_used_at?->toDateString() ?? 'لم يُستخدم',
            filled($topic->prompt_hint) ? ' | توجيه: '.$topic->prompt_hint : '',
        ));

        return ActionResult::text(
            "مواضيع سؤال اليوم (id | الاسم | الحالة | النوع | آخر استخدام):\n".$lines->implode("\n"),
        );
    }
}
