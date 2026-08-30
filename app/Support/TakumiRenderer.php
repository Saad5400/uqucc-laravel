<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Rasterizes an HTML fragment to a PNG with the Takumi engine.
 *
 * This is the app's image renderer: hand it markup a Blade template produced
 * and it hands back PNG bytes. The layout and rasterization happen in
 * scripts/takumi-render.mjs, a Rust engine behind a thin Node entrypoint, which
 * this class runs once per render — the payload goes in on stdin, the image
 * comes back on stdout.
 *
 * Unlike the headless browser it replaces there is no page to load, no fonts to
 * fetch and no event loop to wait on: the faces are read off disk by the script
 * and the whole thing is a few hundred milliseconds of pure layout. What it
 * costs is a browser's tolerance — Takumi lays out flexbox, not the full CSS
 * box model, and it has no emoji font unless one is vendored — so a template
 * meant for it stays within {@see \App\Services\Quiz\QuizImageRenderer} shapes:
 * flex containers, explicit sizes, images instead of inline <svg>.
 *
 * Every failure is logged here before it is rethrown. The callers decide what a
 * missing image means to them; none of them can diagnose a broken render from
 * the exception alone, because the reason is almost always on the script's
 * stderr.
 */
class TakumiRenderer
{
    /**
     * Seconds to wait on one render.
     *
     * Generous next to the ~1s a card actually takes: the ceiling is here to
     * stop a wedged process holding a queue worker, not to police the layout.
     */
    private const TIMEOUT = 30;

    /**
     * The fragment rendered to PNG bytes.
     *
     * @param  string  $html  The markup to lay out. `<style>` blocks in it are honoured.
     * @param  int  $width  Layout width in CSS pixels.
     * @param  int|null  $height  Layout height in CSS pixels, or null to size the
     *                            image to its content — the equivalent of a
     *                            full-page screenshot, and what a card whose
     *                            height follows its text wants.
     * @param  int|null  $minHeight  Floor for that content-derived height, in CSS
     *                               pixels. It has to be given here rather than
     *                               as CSS: Takumi measures the content when it
     *                               sizes an image and a `min-height` on the
     *                               root is not part of that answer.
     * @param  float  $scale  Device pixel ratio; the PNG comes out this much
     *                        larger than the CSS pixel box.
     * @param  string  $lang  BCP-47 language for the root, which is what decides
     *                        shaping and script selection for text that does not
     *                        set its own.
     *
     * @throws RuntimeException when the render fails for any reason.
     */
    public function render(string $html, int $width, ?int $height = null, ?int $minHeight = null, float $scale = 2.0, string $lang = 'ar'): string
    {
        $payload = array_filter([
            'html' => $html,
            'width' => $width,
            'height' => $height,
            'minHeight' => $minHeight,
            'scale' => $scale,
            'lang' => $lang,
        ], fn (mixed $value): bool => $value !== null);

        $process = new Process(
            [$this->nodeBinary(), base_path('scripts/takumi-render.mjs')],
            base_path(),
            timeout: self::TIMEOUT,
        );

        // JSON_UNESCAPED_UNICODE is not cosmetic: the Arabic goes over the pipe
        // as UTF-8 rather than as \u escapes, which keeps the payload a third of
        // the size and readable when a render has to be debugged by hand.
        $process->setInput(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $process->run();

        if (! $process->isSuccessful()) {
            return $this->fail('the render process exited with '.$process->getExitCode(), [
                'stderr' => trim($process->getErrorOutput()),
            ]);
        }

        $png = $process->getOutput();

        // A zero-exit process that produced nothing, or produced something that
        // is not a PNG, is a broken render dressed as a success — catching it
        // here keeps the corrupt bytes from reaching Telegram as an image.
        if (! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            return $this->fail('the render process produced no PNG', [
                'bytes' => strlen($png),
                'stderr' => trim($process->getErrorOutput()),
            ]);
        }

        return $png;
    }

    /**
     * Log the reason a render failed, then throw. Nothing calls this expecting
     * a return value; the signature is `never`-shaped for the callers' sake.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws RuntimeException
     */
    private function fail(string $reason, array $context): never
    {
        Log::error('Takumi render failed: '.$reason, $context);

        throw new RuntimeException('Takumi render failed: '.$reason);
    }

    /**
     * The Node interpreter to run the script with — `node` unless the
     * deployment pinned an absolute path, which the Nix-based image does
     * because PHP-FPM does not inherit its PATH.
     */
    private function nodeBinary(): string
    {
        $binary = config('services.takumi.node_binary');

        return is_string($binary) && $binary !== '' ? $binary : 'node';
    }
}
