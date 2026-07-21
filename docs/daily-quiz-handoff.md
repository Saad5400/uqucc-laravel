# Handoff — Daily CS Quiz + Leaderboard (Telegram bot)

A once-a-day AI-generated multiple-choice question posted to the main Telegram
group, with a streak-based leaderboard. Fun daily ritual for the ~10k mixed
College-of-Computing audience.

Branch: `claude/telegram-bot-umm-al-qura-ciecpk`.

---

## 1. Locked decisions (from product owner)

| # | Decision | Choice |
|---|----------|--------|
| Topics | Where questions come from | **Admin-editable topic list in `/manage`** |
| Quality gate | Before posting to 10k | **Fully automatic** (no approval queue); admins can also edit/delete a live/queued question |
| Leaderboard | Period | **Weekly + all-time** (weekly winner announcement + permanent board) |
| Scoring | Points model | **Correct answer + daily streak bonus** |
| Diversity | 7 majors × 4 years | **Mostly foundational (~6/7 days), ~1 major-"spotlight" day/week** |
| Anonymity | Poll type | **Non-anonymous quiz poll** (required to attribute answers per user) — confirmed acceptable |
| Per-major boards (phase 2) | Self-declared major | **Not built now.** Keep a nullable `major` column on the data model so it's possible later without a migration rewrite. |

Why streak scoring solves the diversity worry: it rewards *showing up daily*,
not raw expertise, so year/major knowledge gaps barely affect ranking. A
first-year who plays every day out-scores a senior who skips.

---

## 2. Data flow

```
nightly  quiz:generate ──► pick topic (weighted rotation) ──► QuizMaster agent
                              (mostly foundational, 1/7 major)   (one-shot JSON)
                                                                     │
                                                    validate + insert daily_quizzes
                                                        (status=scheduled, for tomorrow)
daily    quiz:post ──► stopPoll(previous) ──► sendPoll(type=quiz, is_anonymous=false)
                                                 store poll_id + message_id, status=posted

live     Telegram poll_answer update ──► ProcessTelegramUpdate ──► QuizAnswerHandler
                                             match poll_id → quiz → score + streak

weekly   quiz:announce-winners ──► post weekly top-3, snapshot, reset weekly points
on-demand  «المتصدرين» command ──► top-N (weekly + all-time)
```

Everything hangs off the **existing long-poll → queue → handler** pipeline.
`getUpdates` already receives `poll_answer` in its default update set (only
`chat_member`/reaction updates are excluded by default), so **no
`allowed_updates` change is needed**.

---

## 3. Data model (4 migrations)

### `quiz_topics` — admin-editable pool
| column | type | notes |
|--------|------|-------|
| id | id | |
| label | string | e.g. "Big-O notation", "SQL joins" (Arabic or English) |
| category | string (enum) | `foundational` \| `major` |
| major | string nullable | set only when category=major (e.g. "cybersecurity") |
| difficulty | string (enum) | `easy` \| `medium` \| `hard` |
| weight | unsigned int, default 1 | rotation weighting |
| is_active | bool, default true | |
| last_used_at | timestamp nullable | for least-recently-used rotation |
| timestamps | | |

Seed with a starter foundational set + one topic per major so the feature works
on day one (`QuizTopicSeeder`).

### `daily_quizzes` — one generated question
| column | type | notes |
|--------|------|-------|
| id | id | |
| quiz_topic_id | FK nullable (nullOnDelete) | snapshot below survives topic deletion |
| topic_label | string | snapshot |
| major | string nullable | snapshot (drives spotlight days / future boards) |
| difficulty | string | snapshot |
| question | text | |
| options | json | exactly 4 strings |
| correct_option | unsigned tinyint | 0–3 |
| explanation | text | shown on tap (quiz mode) |
| status | string (enum) | `scheduled` \| `posted` \| `closed` \| `failed` |
| scheduled_for | date, indexed | the day it should post |
| chat_id | bigint | target group |
| poll_id | string nullable, indexed | Telegram poll id (match on answer) |
| message_id | bigint nullable | for stopPoll / delete |
| posted_at / closed_at | timestamp nullable | |
| timestamps | | |

### `quiz_answers` — one row per (quiz, user)
| column | type | notes |
|--------|------|-------|
| id | id | |
| daily_quiz_id | FK cascade | |
| telegram_user_id | bigint, indexed | |
| user_id | FK users nullable | linked if the TG user is a known panel user |
| chosen_option | unsigned tinyint | 0–3 |
| is_correct | bool | |
| points_awarded | unsigned int | base + streak bonus, stored for audit |
| answered_at | timestamp | |
| | | **unique(daily_quiz_id, telegram_user_id)** — blocks double-scoring |

### `quiz_participants` — leaderboard aggregate (O(1) updates, no 10k sweeps)
| column | type | notes |
|--------|------|-------|
| id | id | |
| telegram_user_id | bigint **unique** | |
| user_id | FK users nullable | |
| display_name | string nullable | last-seen first name for the board |
| current_streak | unsigned int, default 0 | |
| longest_streak | unsigned int, default 0 | |
| last_correct_quiz_id | FK daily_quizzes nullable | drives "consecutive?" check |
| total_points | unsigned int, default 0 | all-time board |
| weekly_points | unsigned int, default 0 | weekly board |
| week_key | string nullable | e.g. `2026-W30`; lazy weekly reset |
| major | string nullable | phase-2 placeholder, unused for now |
| timestamps | | |

---

## 4. Scoring & streak logic (exact)

On each `poll_answer` for a quiz poll (evaluated once per user, enforced by the
unique index):

```
isCorrect = (chosen_option === quiz.correct_option)

if isCorrect:
    consecutive = participant.last_correct_quiz_id === previousQuiz.id  // the quiz before this one
    participant.current_streak = consecutive ? current_streak + 1 : 1
    participant.longest_streak = max(longest_streak, current_streak)
    participant.last_correct_quiz_id = quiz.id

    bonus  = min(current_streak - 1, STREAK_BONUS_CAP)   // day1=0, day2=+1, ... capped
    points = BASE_POINTS + bonus                          // BASE_POINTS = 1

    // lazy weekly reset
    if participant.week_key !== currentWeekKey:
        participant.weekly_points = 0
        participant.week_key = currentWeekKey

    participant.total_points  += points
    participant.weekly_points += points
else:
    points = 0
    // wrong answer does NOT reset streak here; a *missed* day breaks the
    // consecutive chain implicitly (last_correct_quiz_id stays stale), so the
    // next correct answer sees consecutive=false and restarts at 1.

quiz_answers.create(..., points_awarded: points)
```

Constants (tunable): `BASE_POINTS = 1`, `STREAK_BONUS_CAP = 6` (max 7 pts/day).
"Miss resets streak" falls out of the `last_correct_quiz_id === previousQuiz.id`
check — no nightly sweep over all participants needed.

`display_name` is refreshed from the poll_answer's `user` each time.

---

## 5. AI generation (`QuizMaster` agent)

Mirror the existing single-purpose agent pattern (`PageCopilotAgent`,
`DocumentExtractionAgent`): a small agent called one-shot with `->prompt()`,
returning `$response->text`, then **decode + validate JSON in PHP** (same as
`PageCopilot::decodeSeoMeta`). No tools, no conversation.

- Model: default to `AiSettings->chat_model`; allow a `QuizSettings->model`
  override. Provider stays behind `config('ai.default')`.
- Prompt asks for strict JSON: `{question, options[4], correct_index, explanation, difficulty}`,
  in Arabic, one unambiguous correct answer, plausible distractors, accessible
  to non-specialists on major-spotlight days.
- **Validation gate before insert** (this is the only quality check, since the
  decision was fully-automatic): exactly 4 non-empty distinct options;
  `correct_index ∈ 0..3`; question & explanation non-empty; length caps for
  Telegram (poll question ≤ 300 chars, each option ≤ 100 chars, explanation ≤
  200 chars — Bot API limits). On failure: retry once, then log + mark the run
  failed and skip a day rather than post garbage.
- Spend: check `SpendLedger->hasBudgetRemaining()` first; record with a new
  `quiz` feature key after generation.
- Dedup: pass the last ~30 questions' text into the prompt as "don't repeat
  these", and/or hash-check the question against recent rows.

Topic rotation (in `quiz:generate`):
- 6 of 7 days: pick an active `foundational` topic, least-recently-used,
  weighted by `weight`.
- 1 day/week (configurable spotlight weekday): pick an active `major` topic,
  rotating majors by least-recently-used.
- Stamp `last_used_at` on the chosen topic.

---

## 6. Posting (`quiz:post`)

- Gate: `QuizSettings->enabled` and `AiSettings->ai_enabled`, and a configured
  target `chat_id`.
- Close the previous still-open quiz: `stopPoll(chat_id, message_id)`, set
  status=closed.
- Send today's `scheduled` quiz:
  ```
  sendPoll(
    chat_id, question, options,
    type: 'quiz', is_anonymous: false,
    correct_option_id: correct_option,
    explanation, explanation_parse_mode: 'HTML',
    is_closed: false,
  )
  ```
- Persist `poll_id` (from the returned Poll object) + `message_id`, status=posted.
- Optional: `pinChatMessage` the poll; unpin on close.

---

## 7. Answer capture wiring

In `ProcessTelegramUpdate::processUpdate`, **before** the message branch:

```php
$pollAnswer = $update->getPollAnswer();
if ($pollAnswer) {
    app(QuizAnswerHandler::class)->handle($pollAnswer);
    return;
}
```

`QuizAnswerHandler` (plain service, not a `BaseHandler` — poll_answer carries no
`Message`):
- `option_ids` empty ⇒ retraction ⇒ ignore (quiz mode disallows anyway).
- Look up `daily_quizzes` by `poll_id`; ignore if not found or `closed`.
- Upsert `quiz_participants` by `telegram_user_id`; apply §4 logic inside a DB
  transaction; create `quiz_answers` (unique index swallows duplicates).

---

## 8. Leaderboard & announcements

- **Command** `المتصدرين` (+ maybe `/leaderboard`): new `LeaderboardHandler`
  in the handler chain. Renders current-week top-N (respecting lazy week reset)
  and all-time top-N. HTML reply via `replyHtml`, `tabular-nums`-friendly
  layout, ranks with 🥇🥈🥉. Track via `BotCommandStat`.
- **Weekly announcement** `quiz:announce-winners` (scheduled, e.g. Thu night
  KSA before the new week): post weekly top-3 to the group, snapshot winners,
  and roll `week_key` forward. (Reset is lazy on next scoring, but the
  announcement job also zeroes stale `weekly_points` so an inactive week shows
  empty.)

---

## 9. Admin controls (`/manage`)

Add a **"مسابقة اليوم" (Daily Quiz)** section, following the existing
Inertia + Form Request pattern (see `TelegramSettingsController` + Vue in
`resources/js/pages/manage/settings/`):

- **Topics CRUD**: `quiz_topics` list — add/edit/toggle/delete, set
  category/major/difficulty/weight. (`QuizTopicController` + Form Requests +
  Vue page under `resources/js/pages/manage/quiz/`.)
- **Queue view**: upcoming/scheduled `daily_quizzes`; edit question text /
  options / correct answer, delete, or "regenerate". This is the manual
  override that the fully-automatic pipeline still allows.
- **Settings card**: enabled toggle, target group `chat_id`, post time,
  generate time, spotlight weekday, model override, streak cap. Store as a
  spatie `QuizSettings` (new `database/settings` migration), same as
  `TelegramSettings`/`AiSettings`.
- **"Post now" / "Skip today"** buttons dispatching the commands.
- All writes via Eloquent (model events), thin controllers + Form Requests, per
  `docs/code-principles.md`.

---

## 10. Scheduling (`routes/console.php`)

Append (times in the app timezone — confirm `config('app.timezone')`; set KSA
if not already):

```php
Schedule::command('quiz:generate')->dailyAt('03:00')->withoutOverlapping()->runInBackground();
Schedule::command('quiz:post')->dailyAt('19:00')->withoutOverlapping()->runInBackground();   // evening = peak student activity
Schedule::command('quiz:announce-winners')->weeklyOn(4, '21:00')->withoutOverlapping()->runInBackground(); // Thu
```

Post/generate/announce times are also mirrored in `QuizSettings` so admins can
change them without a deploy (the scheduler reads settings, or keep these fixed
and treat settings as display — implementer's call; simplest is fixed schedule +
settings-gated enable).

---

## 11. Config / settings additions

- `AiSettings`: add `quiz_enabled` to the feature map (`isFeatureEnabled('quiz')`)
  — needs a `database/settings` migration adding the property.
- New `QuizSettings` (spatie): `enabled`, `chat_id`, `post_time`,
  `generate_time`, `spotlight_weekday`, `model`, `streak_bonus_cap`.
- `config/ai.php`: optional `quiz` block (fallback model, prompt tuning).

---

## 12. Edge cases / failure modes

- **AI produces bad JSON** → validate, retry once, else skip the day (log,
  status=failed). Never post an unvalidated question.
- **No scheduled quiz at post time** (generation failed) → post nothing, alert
  via log; don't crash the scheduler.
- **Duplicate poll_answer / double vote** → unique index; quiz mode locks the
  answer anyway.
- **Poll answered after close** → guard on status=closed.
- **User with no username** → use first name; fall back to "طالب".
- **Group migrated to supergroup (chat_id change)** → `chat_id` in settings must
  be updatable; document that supergroup IDs are negative `-100…`.
- **Bot not admin in group** → sendPoll/pin/stopPoll may fail; surface a clear
  admin-panel warning.
- **Timezone**: all "daily" boundaries and `week_key` computed in KSA time.

---

## 13. Testing plan (Pest, use `tests/Fakes/FakeTelegramApi.php`)

- `quiz:generate` inserts a valid quiz when the agent returns good JSON (fake
  the agent); rejects/retries on malformed JSON; respects budget exhaustion.
- Topic rotation: foundational 6/7, major 1/7; LRU + weight honored (unit).
- `quiz:post` calls `sendPoll` with `type=quiz`, `is_anonymous=false`,
  `correct_option_id`; stores poll_id/message_id; closes previous.
- Scoring: correct answer awards base+bonus; consecutive days compound; a
  skipped day restarts streak at 1; wrong answer = 0; double answer ignored.
- Weekly reset: `week_key` rollover zeroes `weekly_points`.
- `المتصدرين` returns ordered weekly + all-time boards.
- `quiz:announce-winners` posts top-3 and snapshots/resets.
- Manage: topic CRUD + settings validation (Form Request datasets).

Run focused: `php artisan test --filter=Quiz`. Then `vendor/bin/pint --dirty`.

---

## 14. File checklist

**Migrations** (`database/migrations/`)
- `create_quiz_topics_table`
- `create_daily_quizzes_table`
- `create_quiz_answers_table`
- `create_quiz_participants_table`

**Settings** (`database/settings/`)
- `create_quiz_settings` (+ add `quiz_enabled` to ai settings)

**Models** (`app/Models/`)
- `QuizTopic`, `DailyQuiz`, `QuizAnswer`, `QuizParticipant` (+ factories, `QuizTopicSeeder`)

**AI** (`app/Ai/`)
- `Agents/QuizMasterAgent.php` + `Quiz/QuizGenerator.php` (build prompt, decode, validate)

**Settings class** (`app/Settings/`)
- `QuizSettings.php`

**Commands** (`app/Console/Commands/`)
- `GenerateDailyQuiz.php` (`quiz:generate`)
- `PostDailyQuiz.php` (`quiz:post`)
- `AnnounceQuizWinners.php` (`quiz:announce-winners`)

**Telegram** (`app/Services/Telegram/`)
- `Handlers/LeaderboardHandler.php`
- `Quiz/QuizAnswerHandler.php` (or `Handlers/`), + register `poll_answer`
  branch in `app/Jobs/ProcessTelegramUpdate.php`
- Add `LeaderboardHandler` to the handler array

**Scoring** (`app/Services/Quiz/` or `app/Actions/`)
- `RecordQuizAnswer` (the §4 transaction)

**Manage** (`app/Http/Controllers/Manage/`, `app/Http/Requests/Manage/`, `routes/manage.php`, `resources/js/pages/manage/quiz/`, `resources/js/components/manage/nav.ts`)
- `QuizTopicController`, `DailyQuizController`, `QuizSettingsController` + Form Requests + Vue pages + nav entry

**Schedule**: append 3 lines to `routes/console.php`.

**Help**: add a "🧠 مسابقة اليوم / المتصدرين" line to `HelpHandler`.

---

## 15. Assumptions to confirm before coding

1. **Target group chat_id** — the one main 10k group (implementer needs the
   actual `-100…` id; can be set via `/info` in the group then saved in
   settings).
2. **Post time** — defaulting to 19:00 KSA (evening peak). Change if you prefer
   morning.
3. **Spotlight weekday** — defaulting to Sunday (start of the KSA study week).
4. Bot is (or will be) an **admin** in the group so it can send polls / pin /
   stopPoll.
5. Phase-2 per-major boards intentionally deferred; `major` columns kept as
   placeholders only.
