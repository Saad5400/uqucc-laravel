<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\StoreOpinionPollRequest;
use App\Http\Requests\Manage\UpdateOpinionPollRequest;
use App\Http\Requests\Manage\UpdateOpinionPollSettingsRequest;
use App\Models\OpinionPoll;
use App\Models\TelegramChatSetting;
use App\Services\OpinionPoll\OpinionPollPoster;
use App\Services\OpinionPoll\OpinionPollSchedule;
use App\Settings\OpinionPollSettings;
use App\Support\OpinionPollSuggestions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The opinion poll control room — one page, because the whole feature is a
 * queue of hand-written polls, the one that is live right now, and the results
 * the finished ones left behind.
 */
class OpinionPollController extends Controller
{
    /** How many finished polls the results section shows. */
    private const RECENT_RESULTS = 8;

    /** How far ahead the editor looks for a free day before giving up. */
    private const MAX_SCHEDULING_HORIZON_DAYS = 365;

    public function index(OpinionPollSettings $settings, OpinionPollSchedule $schedule): Response
    {
        return Inertia::render('manage/polls/Index', [
            'settings' => [
                'enabled' => $settings->enabled,
                'chat_ids' => $settings->chat_ids,
                'post_time' => $settings->post_time,
                'open_hours' => $settings->open_hours,
            ],
            'groupChats' => TelegramChatSetting::query()
                ->whereIn('type', ['group', 'supergroup'])
                ->orderBy('title')
                ->get()
                ->map(fn (TelegramChatSetting $chat): array => [
                    'chat_id' => (string) $chat->chat_id,
                    'title' => $chat->title,
                ]),
            'schedule' => [
                'post_time' => $schedule->postTimeFor(null),
                'today_posts_at' => $schedule->todayPostsAt()->toISOString(),
            ],
            'livePoll' => $this->payload($this->livePoll()),
            'currentPoll' => $this->payload($this->currentPoll()),
            'upcoming' => $this->upcoming(),
            'recent' => $this->recent(),
            'suggestions' => OpinionPollSuggestions::all(),
            'limits' => [
                'question' => OpinionPoll::MAX_QUESTION_CHARS,
                'option' => OpinionPoll::MAX_OPTION_CHARS,
                'min_options' => OpinionPoll::MIN_OPTIONS,
                'max_options' => OpinionPoll::MAX_OPTIONS,
                'max_open_hours' => UpdateOpinionPollSettingsRequest::MAX_OPEN_HOURS,
            ],
            'today' => today()->toDateString(),
            'nextFreeDate' => $this->nextFreeDate(),
        ]);
    }

    public function updateSettings(UpdateOpinionPollSettingsRequest $request, OpinionPollSettings $settings): RedirectResponse
    {
        $settings->enabled = $request->boolean('enabled');
        $settings->chat_ids = array_values($request->validated('chat_ids'));
        $settings->post_time = $request->validated('post_time');
        $settings->open_hours = (int) $request->validated('open_hours');
        $settings->save();

        return back()->with('success', 'تم حفظ إعدادات استطلاع الرأي.');
    }

    public function store(StoreOpinionPollRequest $request): RedirectResponse
    {
        OpinionPoll::create([
            ...$request->pollAttributes(),
            'status' => OpinionPoll::STATUS_READY,
        ]);

        return back()->with('success', 'تمت إضافة الاستطلاع إلى الطابور.');
    }

    public function update(UpdateOpinionPollRequest $request, OpinionPoll $poll): RedirectResponse
    {
        $poll->update($request->pollAttributes());

        return back()->with('success', 'تم حفظ الاستطلاع.');
    }

    public function destroy(OpinionPoll $poll): RedirectResponse
    {
        if (! $poll->isReady()) {
            return back()->withErrors(['poll' => 'لا يمكن حذف استطلاع بعد نشره.']);
        }

        $poll->delete();

        return back()->with('success', 'تم حذف الاستطلاع.');
    }

    /**
     * Send a poll to the groups right now, ahead of its moment — and re-send
     * one that already went out, the escape hatch for a message someone
     * deleted. Runs inline rather than queued so the admin sees the Telegram
     * outcome on the spot.
     */
    public function post(OpinionPollSettings $settings, OpinionPollPoster $poster, OpinionPoll $poll): RedirectResponse
    {
        if (! $settings->isConfigured()) {
            return back()->withErrors(['post' => 'استطلاع الرأي غير مفعّل أو بلا مجموعات مستهدفة.']);
        }

        if ($poll->isClosed()) {
            return back()->withErrors(['post' => 'هذا الاستطلاع أُغلق ولا يمكن نشره من جديد.']);
        }

        $reposting = $poll->isPosted();

        try {
            $poster->post($poll, force: true);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['post' => $exception->getMessage()]);
        }

        return back()->with('success', $reposting
            ? 'أُعيد نشر الاستطلاع في المجموعات — الأصوات المسجّلة سابقاً محفوظة.'
            : 'نُشر الاستطلاع في المجموعات.');
    }

    /**
     * End a live poll now instead of waiting out its window — the votes are
     * counted and the result announced immediately.
     */
    public function close(OpinionPollPoster $poster, OpinionPoll $poll): RedirectResponse
    {
        if (! $poll->isPosted()) {
            return back()->withErrors(['post' => 'لا يوجد استطلاع مفتوح لإغلاقه.']);
        }

        try {
            $poster->close($poll);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['post' => $exception->getMessage()]);
        }

        return back()->with('success', 'أُغلق الاستطلاع ونُشرت نتيجته في المجموعات.');
    }

    /** The poll the group is voting in right now, if any. */
    private function livePoll(): ?OpinionPoll
    {
        return OpinionPoll::query()
            ->where('status', OpinionPoll::STATUS_POSTED)
            ->orderByDesc('posted_at')
            ->first();
    }

    /**
     * The poll the admin can act on right now: today's while it is still
     * unposted, otherwise the nearest queued day. A `ready` poll dated before
     * today never posts on its own (the command only looks today up), so it is
     * left out rather than shown as if it were about to go.
     */
    private function currentPoll(): ?OpinionPoll
    {
        return OpinionPoll::query()
            ->where('status', OpinionPoll::STATUS_READY)
            ->whereDate('poll_date', '>=', today())
            ->orderBy('poll_date')
            ->first();
    }

    /**
     * The queue as a compact strip — enough to see how many days are covered
     * and where the gaps are without loading every poll.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function upcoming(): Collection
    {
        return OpinionPoll::query()
            ->whereDate('poll_date', '>=', today())
            ->orderBy('poll_date')
            ->get()
            ->map(fn (OpinionPoll $poll): array => [
                'id' => $poll->id,
                'poll_date' => $poll->poll_date->toDateString(),
                'status' => $poll->status,
                'question' => $poll->question,
                'post_time' => $poll->post_time,
            ])
            ->toBase();
    }

    /**
     * The finished polls with their results — the part of this page worth
     * coming back to, and the only place the group's answers are kept.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function recent(): Collection
    {
        return OpinionPoll::query()
            ->where('status', OpinionPoll::STATUS_CLOSED)
            ->orderByDesc('poll_date')
            ->limit(self::RECENT_RESULTS)
            ->get()
            ->map(fn (OpinionPoll $poll): array => $this->payload($poll))
            ->toBase();
    }

    /**
     * The first day from today on with no poll yet — where a new one lands by
     * default, so filling the queue never means picking dates by hand.
     */
    private function nextFreeDate(): string
    {
        $taken = OpinionPoll::query()
            ->whereDate('poll_date', '>=', today())
            ->pluck('poll_date')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->all();

        $date = today();

        for ($day = 0; $day < self::MAX_SCHEDULING_HORIZON_DAYS; $day++) {
            if (! in_array($date->toDateString(), $taken, true)) {
                break;
            }

            $date = $date->addDay();
        }

        return $date->toDateString();
    }

    /**
     * One poll as the page consumes it — the same shape everywhere it appears.
     *
     * @return array<string, mixed>|null
     */
    private function payload(?OpinionPoll $poll): ?array
    {
        if ($poll === null) {
            return null;
        }

        return [
            'id' => $poll->id,
            'poll_date' => $poll->poll_date->toDateString(),
            'question' => $poll->question,
            'options' => $poll->options,
            'status' => $poll->status,
            'post_time' => $poll->post_time,
            'results' => $poll->tally(),
            'total_votes' => $poll->totalVotes(),
            'posted_at' => $poll->posted_at?->toISOString(),
            'closes_at' => $poll->closes_at?->toISOString(),
            'closed_at' => $poll->closed_at?->toISOString(),
        ];
    }
}
