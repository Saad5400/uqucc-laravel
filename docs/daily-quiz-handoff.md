# Handoff — Daily CS Quiz + Leaderboard (Telegram bot)

A once-a-day AI-generated multiple-choice question posted to the main Telegram
group, with a leaderboard. Fun daily ritual for the ~10k mixed audience.

Branch: `claude/telegram-bot-umm-al-qura-ciecpk`.

## Decisions we agreed on

- **Topics:** admin-editable list in `/manage` (AI generates a question from a topic each day).
- **Quality gate:** fully automatic — no approval queue; admins can also edit a question.
- **Leaderboard:** weekly + all-time (weekly winner announcement + permanent board).
- **Scoring:** correct answer + daily streak bonus.
- **Handling 7 majors x 4 years:** one shared group poll (not personalized). Mostly
  foundational/cross-major questions, with ~1 major-"spotlight" day per week. Streak
  scoring rewards showing up daily, so knowledge-level gaps barely affect ranking.
- **Poll type:** non-anonymous quiz poll (required to attribute answers per user).
- **Per-major leaderboards:** deferred for now; keep a nullable `major` field so it's possible later.

## Plan skeleton

- **Generation (nightly command):** picks a topic -> AI agent returns a strict-JSON MCQ
  (question, 4 options, correct index, short explanation) -> store in a `daily_quizzes` table.
- **Posting (daily command):** `sendPoll` with `type=quiz`, `is_anonymous=false`, the correct
  option and explanation. Telegram natively shows correct/wrong and the explanation on tap.
- **Scoring:** handle Telegram `poll_answer` updates -> record the answer, apply streak logic.
- **Leaderboard:** a "المتصدرين" command plus an auto-posted weekly winner announcement.
