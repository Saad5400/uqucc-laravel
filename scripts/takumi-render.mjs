// Renders an HTML fragment to a PNG with the Takumi renderer — a Rust layout
// and raster engine, no headless browser, no network, no running app.
//
// This is the app's one image-rendering bridge: PHP (App\Support\TakumiRenderer)
// writes a JSON payload to stdin and reads the PNG back off stdout. Every card
// the app draws — the daily quiz question today, more later — authors its own
// markup in Blade and hands the rendered HTML here, so a card stays editable as
// a template and previewable in a browser.
//
// Run it by hand for a preview with:
//   echo '{"html":"<div style=\"display:flex\">مرحبا</div>","width":900}' \
//     | node scripts/takumi-render.mjs > /tmp/card.png
//
// Payload (all sizes are CSS pixels, before `scale`):
//   html      string  the fragment to render; <style> blocks in it are honoured
//   width     number  layout width; required
//   height    number  layout height; omitted means "as tall as the content"
//   minHeight number  floor for the content-derived height; ignored with `height`
//   scale     number  device pixel ratio, default 2
//   lang      string  BCP-47 default language for the root, default "ar"
//
// Note on `width`/`height` vs `scale`: Takumi reads width/height as OUTPUT
// pixels and lays out at width/scale, so this script multiplies both by the
// scale itself. Callers pass CSS pixels and get a 2x image.
import { Renderer } from '@takumi-rs/core';
import { fromHtml } from '@takumi-rs/helpers/html';
import fs from 'node:fs';
import path from 'node:path';

/**
 * The faces the cards draw with, all vendored in this repo — nothing is
 * fetched at render time.
 *
 * IBM Plex Sans Arabic is the site face, shipped as two per-weight coverage
 * subsets (arabic + latin). They register as distinct families joined by
 * `subsetOf`, so `font-family: 'IBM Plex Sans Arabic'` expands to both and each
 * script routes to the subset that covers it; registering them under one name
 * instead would have the second file overwrite the first at every weight.
 *
 * DejaVu Sans Mono carries code. It is registered last on purpose: the render
 * fallback chain is registration order, so its wide symbol coverage (the maths
 * and arrow glyphs a question can reach for — ∪, ∩, →) backs up the Plex
 * subsets instead of leaving a tofu box where a browser would have fallen back
 * to a system font.
 */
const FONTS = [
    ...[400, 500, 600, 700].flatMap((weight) => [
        { file: `public/fonts/ibm-plex/ibm-plex-arabic-${weight}.woff2`, name: `IBM Plex Sans Arabic Arabic ${weight}`, weight, subsetOf: 'IBM Plex Sans Arabic', subsetRank: 0 },
        { file: `public/fonts/ibm-plex/ibm-plex-latin-${weight}.woff2`, name: `IBM Plex Sans Arabic Latin ${weight}`, weight, subsetOf: 'IBM Plex Sans Arabic', subsetRank: 1 },
    ]),
    { file: 'resources/fonts/DejaVuSansMono.ttf', name: 'DejaVu Sans Mono', weight: 400, generic: 'monospace' },
    { file: 'resources/fonts/DejaVuSansMono-Bold.ttf', name: 'DejaVu Sans Mono', weight: 700, generic: 'monospace' },
];

function readPayload() {
    const raw = fs.readFileSync(0, 'utf8').trim();

    if (raw === '') {
        throw new Error('takumi-render: empty payload on stdin');
    }

    return JSON.parse(raw);
}

async function registerFonts(renderer) {
    for (const { file, ...details } of FONTS) {
        const absolute = path.resolve(process.cwd(), file);

        // A missing face is fatal rather than skipped: a card silently drawn in
        // a fallback face is worse than a failed render the caller can log.
        if (!fs.existsSync(absolute)) {
            throw new Error(`takumi-render: missing font file ${file}`);
        }

        await renderer.registerFont({ data: fs.readFileSync(absolute), ...details });
    }
}

const payload = readPayload();

if (typeof payload.html !== 'string' || payload.html === '') {
    throw new Error('takumi-render: payload.html is required');
}

if (!Number.isFinite(payload.width) || payload.width <= 0) {
    throw new Error('takumi-render: payload.width is required');
}

const scale = Number.isFinite(payload.scale) && payload.scale > 0 ? payload.scale : 2;
const renderer = new Renderer();
await registerFonts(renderer);

// `css` carries the <style> blocks fromHtml lifted out of the fragment; drop it
// and every class selector in the template is silently ignored.
const { node, css } = fromHtml(payload.html);

const options = {
    width: Math.round(payload.width * scale),
    devicePixelRatio: scale,
    format: 'png',
    css,
    lang: typeof payload.lang === 'string' && payload.lang !== '' ? payload.lang : 'ar',
};

const height = await resolveHeight(renderer, node, options);

if (height !== null) {
    options.height = height;
}

process.stdout.write(await renderer.render(node, options));

/**
 * The output height in device pixels, or null to let Takumi size the image to
 * its content — the equivalent of a full-page screenshot, and what a card whose
 * height follows its text wants.
 *
 * A `minHeight` needs the layout measured first: Takumi derives an automatic
 * height from the content alone and ignores a CSS `min-height` on the root
 * while doing it, so the floor has to be applied to a number we asked for. The
 * measure pass is layout without rasterization and costs a few milliseconds.
 */
async function resolveHeight(renderer, node, options) {
    if (Number.isFinite(payload.height) && payload.height > 0) {
        return Math.round(payload.height * scale);
    }

    if (!Number.isFinite(payload.minHeight) || payload.minHeight <= 0) {
        return null;
    }

    const measured = await renderer.measure(node, { ...options, width: payload.width, devicePixelRatio: 1 });

    return Math.round(Math.max(payload.minHeight, measured.height) * scale);
}
