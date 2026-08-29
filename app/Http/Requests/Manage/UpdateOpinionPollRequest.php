<?php

namespace App\Http\Requests\Manage;

use App\Models\OpinionPoll;
use Illuminate\Validation\Validator;

class UpdateOpinionPollRequest extends StoreOpinionPollRequest
{
    /**
     * A poll the group is already voting in cannot change under its voters —
     * reject the whole edit up front so the admin gets the reason rather than
     * a save that was never going to happen.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $poll = $this->poll();

                if ($poll !== null && ! $poll->isReady()) {
                    $validator->errors()->add('poll', 'لا يمكن تعديل استطلاع بعد نشره.');
                }
            },
        ];
    }

    protected function editedPollKey(): ?int
    {
        return $this->poll()?->getKey();
    }

    private function poll(): ?OpinionPoll
    {
        $poll = $this->route('poll');

        return $poll instanceof OpinionPoll ? $poll : null;
    }
}
