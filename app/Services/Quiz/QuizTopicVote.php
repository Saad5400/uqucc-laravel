<?php

namespace App\Services\Quiz;

use App\Models\DailyQuiz;
use App\Models\QuizTopic;
use App\Models\QuizTopicPoll;
use App\Settings\QuizSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Throwable;

/**
 * Lets the group pick tomorrow's topic — an illusion of choice with no way to
 * cheat the rotation. The ballot only ever lists topics the running cycle
 * still owes ({@see QuizTopic::cycleCandidates()}), so a vote reorders the
 * cycle and never decides whether a topic is covered at all.
 *
 * Two moments, a day apart: {@see self::open()} posts the ballot right under
 * the day's question, and {@see self::resolve()} stops the polls when the next
 * question is generated and returns what the group asked for.
 *
 * The vote is deliberately skipped — silently, with the ordinary
 * least-recently-used pick taking over — whenever offering it would be a lie:
 *  - fewer than {@see self::BALLOT_SIZE} topics are left in the cycle, since
 *    filling the last slots would mean offering a topic that is already
 *    covered while topics that are not go unasked;
 *  - the day already has a question, so its topic is settled;
 *  - a ballot for that day already went out.
 *
 * The Api client is built lazily so the service can be container-resolved in
 * environments without a bot token; tests pass a FakeTelegramApi instead.
 */
class QuizTopicVote
{
    /** How many topics a ballot lists — and the minimum the cycle must owe. */
    public const BALLOT_SIZE = 4;

    public const QUESTION = '🗳 موضوع سؤال الغد بين أيديكم — صوّتوا!';

    /** Telegram's hard cap on one poll option. */
    private const MAX_OPTION_CHARS = 100;

    private ?Api $telegram;

    public function __construct(
        private readonly QuizSettings $settings,
        ?Api $telegram = null,
    ) {
        $this->telegram = $telegram;
    }

    /**
     * Put tomorrow's topic to the group, in every configured chat. Returns the
     * recorded ballot, or null when the vote was skipped or no chat took it —
     * either way the day still gets a topic, just without asking.
     */
    public function open(CarbonInterface $date): ?QuizTopicPoll
    {
        if (! $this->settings->isConfigured()) {
            return null;
        }

        if (QuizTopicPoll::forDate($date) !== null || DailyQuiz::forDate($date) !== null) {
            return null;
        }

        $ballot = $this->ballotFor($date);

        if ($ballot === null) {
            return null;
        }

        $posts = $this->send($ballot);

        if ($posts === []) {
            return null;
        }

        return QuizTopicPoll::create([
            'quiz_date' => $date,
            'topic_ids' => $ballot->modelKeys(),
            'posts' => $posts,
        ]);
    }

    /**
     * Close the day's ballot and report what won, or null when there was no
     * ballot, nobody voted, or the winning topic is gone. Idempotent: a poll
     * that was already tallied answers from its stored result, so regenerating
     * a question never re-reads votes that are no longer being cast.
     */
    public function resolve(CarbonInterface $date): ?QuizTopic
    {
        $poll = QuizTopicPoll::forDate($date);

        if ($poll === null) {
            return null;
        }

        if ($poll->isClosed()) {
            return $poll->topic?->is_active === true ? $poll->topic : null;
        }

        $winner = $this->tally($poll);

        $poll->update([
            'quiz_topic_id' => $winner?->id,
            'closed_at' => now(),
        ]);

        return $winner;
    }

    /**
     * The four topics to offer, in least-recently-used order so a tie is
     * broken exactly the way the automatic pick would have — or null when the
     * cycle no longer owes enough topics to fill a ballot honestly.
     *
     * @return Collection<int, QuizTopic>|null
     */
    private function ballotFor(CarbonInterface $date): ?Collection
    {
        $candidates = QuizTopic::cycleCandidates($date)
            ->unique(fn (QuizTopic $topic): string => $this->optionText($topic))
            ->values();

        if ($candidates->count() < self::BALLOT_SIZE) {
            return null;
        }

        $chosen = $candidates->random(self::BALLOT_SIZE)->modelKeys();

        return $candidates
            ->filter(fn (QuizTopic $topic): bool => in_array($topic->id, $chosen, true))
            ->values();
    }

    /**
     * Send the ballot to every configured chat, returning one delivery receipt
     * per chat that took it. A chat that refuses is logged and skipped — the
     * vote still counts wherever it landed.
     *
     * @param  Collection<int, QuizTopic>  $ballot
     * @return array<int, array{chat_id: int, message_id: int, message_thread_id: int|null, telegram_poll_id: string|null}>
     */
    private function send(Collection $ballot): array
    {
        $params = [
            'question' => self::QUESTION,
            'options' => $ballot->map(fn (QuizTopic $topic): string => $this->optionText($topic))->all(),
            'is_anonymous' => true,
        ];

        $posts = [];

        foreach ($this->settings->targets() as $target) {
            try {
                $message = $this->telegram()->sendPoll($target->apply($params));

                $posts[] = [
                    'chat_id' => $message->getChat()->getId(),
                    'message_id' => $message->getMessageId(),
                    'message_thread_id' => $target->threadId,
                    'telegram_poll_id' => $message->getPoll()?->getId(),
                ];
            } catch (Throwable $exception) {
                Log::warning('Failed to post the topic vote to chat', [
                    'chat_id' => $target->chatId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $posts;
    }

    /**
     * Stop the ballot everywhere and add the votes up across chats — one vote
     * is one vote wherever it was cast, the same way the leaderboard treats
     * every group as one room. Null when nothing was cast or the winning
     * option's topic has since been deleted or deactivated.
     */
    private function tally(QuizTopicPoll $poll): ?QuizTopic
    {
        $totals = array_fill(0, count($poll->topic_ids), 0);

        foreach ($poll->posts as $post) {
            foreach ($this->stop($post) as $index => $votes) {
                if (array_key_exists($index, $totals)) {
                    $totals[$index] += $votes;
                }
            }
        }

        if (max($totals) === 0) {
            return null;
        }

        // array_search takes the first maximum, and the ballot is ordered
        // least-recently-used first — so a tie goes to the topic that has
        // waited longest, exactly as the automatic pick would have chosen.
        $winner = $poll->ballot()->get((int) array_search(max($totals), $totals, true));

        return $winner?->is_active === true ? $winner : null;
    }

    /**
     * Close one chat's copy of the ballot and read its final counts, indexed by
     * option. A poll Telegram already closed — or a message someone deleted —
     * just makes the call throw; that chat's votes are lost rather than the
     * whole tally.
     *
     * @param  array<string, mixed>  $post
     * @return array<int, int>
     */
    private function stop(array $post): array
    {
        try {
            $params = [
                'chat_id' => $post['chat_id'],
                'message_id' => $post['message_id'],
            ];

            $result = $this->telegram()->stopPoll($params);

            return collect($result->getOptions() ?? [])
                ->map(fn ($option): int => (int) $option->getVoterCount())
                ->values()
                ->all();
        } catch (Throwable $exception) {
            Log::warning('Failed to stop the topic vote poll', [
                'chat_id' => $post['chat_id'] ?? null,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function optionText(QuizTopic $topic): string
    {
        return mb_substr(trim($topic->name), 0, self::MAX_OPTION_CHARS);
    }

    private function telegram(): Api
    {
        return $this->telegram ??= new Api((string) config('services.telegram.token'), false);
    }
}
