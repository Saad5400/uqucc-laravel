<?php

namespace Tests\Fakes;

use Telegram\Bot\Api;
use Telegram\Bot\Objects\BaseObject;
use Telegram\Bot\Objects\Chat;
use Telegram\Bot\Objects\ChatMember;
use Telegram\Bot\Objects\File;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\User;

/**
 * An in-memory Telegram Api double for handler tests: records every outgoing
 * call instead of hitting the Bot API, and answers getChatMember/getFile from
 * canned data the test sets up.
 */
class FakeTelegramApi extends Api
{
    /** @var array<int, array<string, mixed>> */
    public array $sentMessages = [];

    /** @var array<int, array<string, mixed>> */
    public array $editedMessages = [];

    /** @var array<int, array<string, mixed>> */
    public array $sentPhotos = [];

    /** @var array<int, array<string, mixed>> */
    public array $sentPolls = [];

    /** @var array<int, array<string, mixed>> */
    public array $stoppedPolls = [];

    /** @var array<int, array<string, mixed>> */
    public array $pinnedMessages = [];

    /** @var array<int, array<string, mixed>> */
    public array $unpinnedMessages = [];

    /** @var array<int, array<string, mixed>> */
    public array $answeredCallbacks = [];

    /** @var array<int, array<string, mixed>> */
    public array $reactions = [];

    /** @var array<int, array<string, mixed>> */
    public array $editedReplyMarkups = [];

    /**
     * Message ids the API should answer for as if they no longer exist.
     *
     * @var array<int, int>
     */
    public array $missingMessageIds = [];

    /** Error setMessageReaction should always fail with, when set. */
    public ?string $reactionError = null;

    /**
     * Final per-option voter counts stopPoll() should report, keyed by the
     * poll message id. A message id not listed here stops with no options at
     * all, the way a poll nobody could vote in would.
     *
     * @var array<int, array<int, int>>
     */
    public array $pollResults = [];

    /**
     * Whether members may post, per chat id — the «can_send_messages» chat
     * permission getChat() reports. A chat not listed here reads as open.
     *
     * @var array<int|string, bool>
     */
    public array $membersCanPost = [];

    /** Chat-member status per telegram user id (default 'member'). */
    /** @var array<int|string, string> */
    public array $chatMemberStatuses = [];

    /** Bytes downloadFile() writes to the requested path. */
    public string $downloadContents = '';

    public string $botUsername = 'UquccTestBot';

    private int $nextMessageId = 1000;

    private int $nextPollId = 5000;

    public function __construct()
    {
        parent::__construct('123456:fake-token');
    }

    public function sendMessage(array $params): Message
    {
        $this->sentMessages[] = $params;

        return new Message(['message_id' => ++$this->nextMessageId, 'chat' => ['id' => $params['chat_id'] ?? 0]]);
    }

    public function sendPhoto(array $params): Message
    {
        $this->sentPhotos[] = $params;

        return new Message(['message_id' => ++$this->nextMessageId, 'chat' => ['id' => $params['chat_id'] ?? 0]]);
    }

    public function sendPoll(array $params): Message
    {
        $this->sentPolls[] = $params;

        return new Message([
            'message_id' => ++$this->nextMessageId,
            'chat' => ['id' => $params['chat_id'] ?? 0],
            'poll' => ['id' => (string) ++$this->nextPollId, 'question' => $params['question'] ?? ''],
        ]);
    }

    public function stopPoll(array $params): \Telegram\Bot\Objects\Poll
    {
        $this->stoppedPolls[] = $params;

        $this->failWhenMissing($params, 'Bad Request: message to stop not found');

        $counts = $this->pollResults[(int) ($params['message_id'] ?? 0)] ?? [];

        return new \Telegram\Bot\Objects\Poll([
            'id' => (string) ($params['message_id'] ?? 0),
            'is_closed' => true,
            'options' => array_map(
                static fn (int $index, int $votes): array => ['text' => (string) ($index + 1), 'voter_count' => $votes],
                array_keys($counts),
                array_values($counts),
            ),
        ]);
    }

    public function pinChatMessage(array $params): bool
    {
        $this->pinnedMessages[] = $params;

        return true;
    }

    public function unpinChatMessage(array $params): bool
    {
        $this->unpinnedMessages[] = $params;

        return true;
    }

    public function editMessageText(array $params): Message
    {
        $this->editedMessages[] = $params;

        return new Message(['message_id' => $params['message_id'] ?? 0, 'chat' => ['id' => $params['chat_id'] ?? 0]]);
    }

    public function answerCallbackQuery(array $params): bool
    {
        $this->answeredCallbacks[] = $params;

        return true;
    }

    public function setMessageReaction(array $params): bool
    {
        $this->reactions[] = $params;

        if ($this->reactionError !== null) {
            throw new \RuntimeException($this->reactionError);
        }

        $this->failWhenMissing($params, 'Bad Request: message to react not found');

        return true;
    }

    public function editMessageReplyMarkup(array $params): Message
    {
        $this->editedReplyMarkups[] = $params;

        $this->failWhenMissing($params, 'Bad Request: message to edit not found');

        throw new \RuntimeException("Bad Request: message can't be edited");
    }

    /**
     * Mimic Telegram's error for a message id the test declared missing.
     *
     * @param  array<string, mixed>  $params
     */
    private function failWhenMissing(array $params, string $error): void
    {
        if (in_array((int) ($params['message_id'] ?? 0), $this->missingMessageIds, true)) {
            throw new \RuntimeException($error);
        }
    }

    public function getChat(array $params): Chat
    {
        return new Chat([
            'id' => (int) $params['chat_id'],
            'type' => 'supergroup',
            'permissions' => ['can_send_messages' => $this->membersCanPost[$params['chat_id']] ?? true],
        ]);
    }

    public function getChatMember(array $params): ChatMember
    {
        return new ChatMember(['status' => $this->chatMemberStatuses[$params['user_id']] ?? 'member']);
    }

    public function getMe(): User
    {
        return new User(['id' => 42, 'is_bot' => true, 'first_name' => 'Uqucc', 'username' => $this->botUsername]);
    }

    public function getFile(array $params): File
    {
        return new File(['file_id' => $params['file_id'], 'file_path' => 'documents/'.$params['file_id']]);
    }

    public function downloadFile(File|BaseObject|string $file, string $filename): string
    {
        file_put_contents($filename, $this->downloadContents);

        return $filename;
    }

    /**
     * Every text sent or edited, in call order — handy for content asserts.
     *
     * @return array<int, string>
     */
    public function allTexts(): array
    {
        return array_values(array_map(
            static fn (array $params): string => (string) ($params['text'] ?? ''),
            array_merge($this->sentMessages, $this->editedMessages),
        ));
    }
}
