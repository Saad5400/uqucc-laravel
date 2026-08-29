<?php

namespace App\Services\OpinionPoll;

use App\Helpers\ArabicPlural;
use App\Models\OpinionPoll;
use App\Models\OpinionPollPost;
use App\Settings\OpinionPollSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Telegram\Bot\Api;
use Throwable;

/**
 * Everything the bot sends to the groups for «استطلاع الرأي»: one anonymous
 * poll per configured group, and the result recap that closes it a day later.
 *
 * Anonymous is the whole design. The daily question is a quiz — a vote there
 * is attributable, and being wrong in front of ten thousand members is a real
 * cost that keeps most of them reading instead of answering. Here nobody can
 * be wrong and nobody can be seen, so the only thing a vote costs is the tap;
 * that is what makes this the entry step into the group's daily ritual rather
 * than a second quiz. The flip side is that nothing here can be scored: an
 * anonymous vote carries no user, so there are no players, points or streaks.
 *
 * The Api client is built lazily so the service can be container-resolved in
 * environments without a bot token; tests pass a FakeTelegramApi instead.
 */
class OpinionPollPoster
{
    private ?Api $telegram;

    public function __construct(
        private readonly OpinionPollSettings $settings,
        ?Api $telegram = null,
    ) {
        $this->telegram = $telegram;
    }

    /**
     * Post the poll to every configured group. Any poll still open is closed
     * first — one live poll at a time, so the results recap always answers the
     * question the group is currently looking at. A group that fails (bot
     * kicked, no rights) is logged and skipped; the poll counts as posted
     * while at least one group got it.
     *
     * `$force` re-posts a poll that already went out — the escape hatch for a
     * message deleted from the group by mistake. The poll keeps its identity
     * and the votes already cast in the other groups stay counted; its own
     * earlier messages are stopped quietly, without a recap, because the
     * round is not over.
     */
    public function post(OpinionPoll $poll, bool $force = false): OpinionPoll
    {
        if (! $this->settings->isConfigured()) {
            throw new RuntimeException('استطلاع الرأي غير مهيأ — فعّله وحدد المجموعات من صفحة استطلاع الرأي.');
        }

        if (! $poll->isReady() && ! ($force && $poll->isPosted())) {
            throw new RuntimeException('هذا الاستطلاع ليس بانتظار النشر.');
        }

        $this->closeOpenPolls($poll);
        $this->retireOwnPosts($poll);

        $params = [
            'question' => $poll->question,
            'options' => array_values($poll->options),
            'is_anonymous' => true,
        ];

        $delivered = 0;

        foreach ($this->settings->targets() as $target) {
            try {
                $message = $this->telegram()->sendPoll($target->apply($params));

                // One post row per poll per group (a unique index enforces
                // it), so a re-post moves the row onto the new message
                // instead of adding a second one that would fail to save.
                OpinionPollPost::updateOrCreate([
                    'opinion_poll_id' => $poll->id,
                    'chat_id' => $message->getChat()->getId(),
                ], [
                    'message_id' => $message->getMessageId(),
                    'message_thread_id' => $target->threadId,
                    'telegram_poll_id' => $message->getPoll()?->getId(),
                    'votes' => null,
                    'posted_at' => now(),
                    'closed_at' => null,
                ]);

                $delivered++;
            } catch (Throwable $exception) {
                Log::error('Failed to post opinion poll to chat', [
                    'opinion_poll_id' => $poll->id,
                    'chat_id' => $target->chatId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($delivered === 0) {
            throw new RuntimeException('تعذّر نشر الاستطلاع في أي من المجموعات المحددة.');
        }

        $poll->update([
            'status' => OpinionPoll::STATUS_POSTED,
            'posted_at' => now(),
            'closes_at' => now()->addHours($this->openHours()),
        ]);

        return $poll->refresh();
    }

    /**
     * Close every live poll whose voting window has run out, and return how
     * many were closed. This — not the next posting — is what ends a round:
     * the queue is written by hand and may well have a gap tomorrow, and a
     * poll left open over a gap would collect votes nobody ever sees a result
     * for.
     */
    public function closeElapsed(): int
    {
        $elapsed = OpinionPoll::query()
            ->where('status', OpinionPoll::STATUS_POSTED)
            ->whereNotNull('closes_at')
            ->where('closes_at', '<=', now())
            ->get();

        $elapsed->each(fn (OpinionPoll $poll) => $this->close($poll));

        return $elapsed->count();
    }

    /**
     * Stop a live poll everywhere, add its votes up across groups and hand the
     * result back to the group. Idempotent in effect: a poll with no open
     * messages left simply records what it already had.
     */
    public function close(OpinionPoll $poll, bool $recap = true): OpinionPoll
    {
        $justClosed = $poll->posts()->open()->get();

        foreach ($justClosed as $post) {
            $post->update([
                'votes' => $this->stop($post),
                'closed_at' => now(),
            ]);
        }

        $poll->update([
            'results' => $this->tally($poll),
            'status' => OpinionPoll::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        if ($recap) {
            $this->sendRecap($poll->refresh(), $justClosed);
        }

        return $poll->refresh();
    }

    /**
     * Close every live poll other than the one being posted, with its recap —
     * the ordinary end of a round when the next poll goes out early.
     */
    private function closeOpenPolls(?OpinionPoll $except = null): void
    {
        OpinionPoll::query()
            ->where('status', OpinionPoll::STATUS_POSTED)
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except->getKey()))
            ->get()
            ->each(fn (OpinionPoll $poll) => $this->close($poll));
    }

    /**
     * Stop this poll's own messages from an earlier posting round, without a
     * recap — a re-post is not the end of the round, and their votes stay
     * recorded so nothing cast before the mishap is lost.
     */
    private function retireOwnPosts(OpinionPoll $poll): void
    {
        foreach ($poll->posts()->open()->get() as $post) {
            $post->update([
                'votes' => $this->stop($post),
                'closed_at' => now(),
            ]);
        }
    }

    /**
     * The poll's votes summed across every group it reached, indexed by
     * option — every group is one room, the way the quiz's leaderboard treats
     * them.
     *
     * @return array<int, int>
     */
    private function tally(OpinionPoll $poll): array
    {
        $totals = array_fill(0, count($poll->options), 0);

        foreach ($poll->posts()->get() as $post) {
            foreach ($post->votes ?? [] as $index => $votes) {
                if (array_key_exists($index, $totals)) {
                    $totals[$index] += (int) $votes;
                }
            }
        }

        return $totals;
    }

    /**
     * Close one group's copy of the poll and read its final counts, indexed by
     * option. A poll Telegram already closed — or a message someone deleted —
     * just makes the call throw; that group's votes are lost rather than the
     * whole tally.
     *
     * @return array<int, int>
     */
    private function stop(OpinionPollPost $post): array
    {
        try {
            $result = $this->telegram()->stopPoll([
                'chat_id' => $post->chat_id,
                'message_id' => $post->message_id,
            ]);

            return collect($result->getOptions() ?? [])
                ->map(fn ($option): int => (int) $option->getVoterCount())
                ->values()
                ->all();
        } catch (Throwable $exception) {
            Log::warning('Failed to stop the opinion poll', [
                'opinion_poll_post_id' => $post->id,
                'message' => $exception->getMessage(),
            ]);

            return $post->votes ?? [];
        }
    }

    /**
     * Reply to each just-closed poll with what the group decided. Best-effort,
     * and skipped entirely when nobody voted — an empty result is just noise.
     *
     * @param  Collection<int, OpinionPollPost>  $posts
     */
    private function sendRecap(OpinionPoll $poll, Collection $posts): void
    {
        if ($posts->isEmpty() || $poll->totalVotes() === 0) {
            return;
        }

        $text = $this->recapText($poll);

        foreach ($posts as $post) {
            try {
                $params = [
                    'chat_id' => $post->chat_id,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'reply_to_message_id' => $post->message_id,
                ];

                if ($post->message_thread_id !== null) {
                    $params['message_thread_id'] = $post->message_thread_id;
                }

                $this->telegram()->sendMessage($params);
            } catch (Throwable $exception) {
                Log::warning('Failed to send the opinion poll recap', [
                    'opinion_poll_post_id' => $post->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * The recap body: the question, every option by share of the vote, and the
     * turnout. Ranked rather than kept in ballot order — the point of the
     * message is what the group actually thinks.
     */
    private function recapText(OpinionPoll $poll): string
    {
        $total = $poll->totalVotes();
        $medals = ['🥇', '🥈', '🥉'];

        $ranked = collect($poll->options)
            ->map(fn (string $option, int $index): array => [
                'option' => $option,
                'votes' => $poll->tally()[$index] ?? 0,
            ])
            ->sortByDesc('votes')
            ->values();

        $lines = $ranked
            ->map(fn (array $row, int $rank): string => sprintf(
                '%s %s — %d٪ (%d)',
                $medals[$rank] ?? '▫️',
                $this->escape($row['option']),
                (int) round($row['votes'] / $total * 100),
                $row['votes'],
            ))
            ->implode("\n");

        return "📊 <b>نتيجة الاستطلاع</b>\n«".$this->escape($poll->question)."»\n\n"
            .$lines
            ."\n\n🧑‍🎓 صوّت: ".ArabicPlural::people($total).' — شكراً لكل من شارك 🌟';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** At least an hour, so a misconfigured zero can't close a poll instantly. */
    private function openHours(): int
    {
        return max(1, $this->settings->open_hours);
    }

    private function telegram(): Api
    {
        return $this->telegram ??= new Api((string) config('services.telegram.token'), false);
    }
}
