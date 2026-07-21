<?php

namespace Tests\Fakes;

use Telegram\Bot\Api;
use Telegram\Bot\Objects\BaseObject;
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

        return new \Telegram\Bot\Objects\Poll(['id' => (string) ($params['message_id'] ?? 0), 'is_closed' => true]);
    }

    public function editMessageText(array $params): Message
    {
        $this->editedMessages[] = $params;

        return new Message(['message_id' => $params['message_id'] ?? 0, 'chat' => ['id' => $params['chat_id'] ?? 0]]);
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
