<?php

namespace App\Mcp\Tools\Moderation;

use App\Models\PrivateTutor\PrivateTutor;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * Delete a private tutor. Attached courses are detached, not deleted. Mirrors
 * {@see \App\Http\Controllers\Manage\PrivateTutorController::destroy()}.
 */
#[Description('Delete a private tutor (حذف مدرّس خصوصي). Requires tutor_id (from list_tutors). The tutor\'s courses are kept — only the tutor and its course links are removed. This cannot be undone.')]
class DeleteTutorTool extends ModerationTool
{
    protected string $name = 'delete_tutor';

    protected function requiredAbility(): string
    {
        return 'manage-private-tutors';
    }

    protected function perform(Request $request, User $user): Response
    {
        $tutor = PrivateTutor::query()->find((int) $request->get('tutor_id'));

        if ($tutor === null) {
            return Response::error('لم يُعثر على المدرّس المطلوب. No tutor found for that id.');
        }

        $name = $tutor->name;
        $tutor->delete();

        return Response::text('تم حذف المدرّس «'.$name.'». Tutor deleted.');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tutor_id' => $schema->integer()
                ->description('The id of the tutor to delete, from list_tutors.')
                ->required(),
        ];
    }
}
