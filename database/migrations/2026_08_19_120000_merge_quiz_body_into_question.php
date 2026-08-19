<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The daily question is now authored as one HTML field and rendered to an
 * image, so the scenario/code preamble no longer lives in a separate `body`
 * column posted as its own Telegram message. Fold every existing body into the
 * front of its question — the preamble came before the question, so it leads —
 * then drop the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('daily_quizzes')->whereNotNull('body')->get(['id', 'question', 'body']) as $quiz) {
            DB::table('daily_quizzes')
                ->where('id', $quiz->id)
                ->update(['question' => $this->fold((string) $quiz->body, (string) $quiz->question)]);
        }

        Schema::table('daily_quizzes', function (Blueprint $table): void {
            $table->dropColumn('body');
        });
    }

    public function down(): void
    {
        Schema::table('daily_quizzes', function (Blueprint $table): void {
            $table->text('body')->nullable()->after('question');
        });
    }

    /**
     * Turn the old plain-text question and markdown-ish body into a single HTML
     * fragment: fenced ``` blocks become directional <pre> code, everything
     * else becomes right-to-left paragraphs, and the question closes the card.
     */
    private function fold(string $body, string $question): string
    {
        $html = '';

        foreach (preg_split('/(```[a-zA-Z0-9+_.-]*\n?.*?```)/s', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^```[a-zA-Z0-9+_.-]*\n?(.*?)```$/s', $segment, $matches) === 1) {
                $html .= '<pre dir="ltr"><code>'.e(trim($matches[1], "\n")).'</code></pre>';

                continue;
            }

            foreach (preg_split('/\n{2,}/', trim($segment)) ?: [] as $paragraph) {
                $paragraph = trim($paragraph);

                if ($paragraph !== '') {
                    $html .= '<p dir="rtl">'.nl2br(e($paragraph)).'</p>';
                }
            }
        }

        return $html.'<p dir="rtl">'.nl2br(e(trim($question))).'</p>';
    }
};
