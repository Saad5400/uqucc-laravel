<?php

namespace App\Ai\Admin\Actions\Quiz;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\QuizTopic;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Saad\AiKit\Approvals\Classified\Field;
use Saad\AiKit\Approvals\Classified\FieldWidget;

/**
 * Add a daily-quiz topic, mirroring
 * {@see \App\Http\Controllers\Manage\QuizTopicController::store()}. New topics
 * start active. Mark is_spotlight for a major-specific theme that should only
 * appear on the weekly spotlight day.
 */
class CreateQuizTopicAction extends AdminAction
{
    public function name(): string
    {
        return 'create_quiz_topic';
    }

    public function category(): string
    {
        return 'quiz';
    }

    public function description(): string
    {
        return 'Create a new daily-quiz topic the questions can be generated from. '
            .'Provide name, an optional prompt_hint to steer generation, and is_spotlight '
            .'(true for a major-specific theme shown only on the weekly spotlight day). New topics start active.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('The topic name, written in Arabic like the rest of the site content, e.g. "programming basics".')
                ->required(),
            'prompt_hint' => $schema->string()
                ->description('Optional guidance for the author model about this topic.'),
            'is_spotlight' => $schema->boolean()
                ->description('True for a major-specific theme shown only on the weekly spotlight day. Defaults to false.'),
        ];
    }

    /**
     * Arabic labels for the approval card, with each field's widget restated
     * alongside its label. Declaring a spec REPLACES the kit's value-based
     * inference for that argument, so the widget an unlabelled field would
     * have been given has to be named here — an id declared without
     * `Field::readonly` would come back as an editable text box.
     *
     * @return array<string, mixed>
     */
    public function fieldWidgets(): array
    {
        return [
            'name' => Field::make('name', FieldWidget::Text, label: 'اسم الموضوع'),
            'prompt_hint' => Field::make('prompt_hint', FieldWidget::Textarea, label: 'تلميح التوليد'),
            'is_spotlight' => Field::make('is_spotlight', FieldWidget::Boolean, label: 'موضوع الأسبوع'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            throw new AdminActionException('اسم الموضوع مطلوب.');
        }

        if (mb_strlen($name) > 255) {
            throw new AdminActionException('اسم الموضوع طويل جداً (الحد 255 حرفاً).');
        }

        $hint = array_key_exists('prompt_hint', $input) && $input['prompt_hint'] !== null
            ? trim((string) $input['prompt_hint'])
            : null;

        if ($hint !== null && mb_strlen($hint) > 2000) {
            throw new AdminActionException('توجيهات الموضوع طويلة جداً (الحد 2000 حرف).');
        }

        return [
            'name' => $name,
            'prompt_hint' => $hint === '' ? null : $hint,
            'is_spotlight' => (bool) ($input['is_spotlight'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'إضافة موضوع لسؤال اليوم: «'.$normalized['name'].'»'
            .($normalized['is_spotlight'] ? ' (يوم تخصص)' : '').'.';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $topic = QuizTopic::create([
            'name' => $normalized['name'],
            'prompt_hint' => $normalized['prompt_hint'],
            'is_spotlight' => $normalized['is_spotlight'],
            'is_active' => true,
        ]);

        return ActionResult::text('تمت إضافة موضوع سؤال اليوم «'.$topic->name.'» (id='.$topic->id.').');
    }
}
