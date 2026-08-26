import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * The tool promises the student that their photo never leaves the device, and
 * that promise is the whole reason it is safe to use with a personal ID photo.
 * This test pins it at the source level: none of the files that touch the photo
 * may reference an API capable of sending it anywhere.
 *
 * It is deliberately a text scan rather than a runtime assertion — a network
 * call added in a branch that no test happens to hit would still be a broken
 * promise, and this catches it the moment it is written.
 */
const PHOTO_HANDLING_FILES = [
    '../../lib/studentPhoto/analysis.ts',
    '../../lib/studentPhoto/checks.ts',
    '../../lib/studentPhoto/decode.ts',
    '../../lib/studentPhoto/exif.ts',
    '../../lib/studentPhoto/geometry.ts',
    '../../lib/studentPhoto/render.ts',
    '../../lib/studentPhoto/requirements.ts',
    '../../lib/studentPhoto/sniff.ts',
    '../../components/tools/PhotoChecks.vue',
    '../../components/tools/PhotoCropCanvas.vue',
    '../../components/tools/PhotoDropzone.vue',
    '../../pages/tools/StudentPhotoPage.vue',
];

/** Anything that could carry bytes off the device. */
const NETWORK_APIS: { label: string; pattern: RegExp }[] = [
    { label: 'fetch()', pattern: /\bfetch\s*\(/ },
    { label: 'XMLHttpRequest', pattern: /XMLHttpRequest/ },
    { label: 'navigator.sendBeacon', pattern: /sendBeacon/ },
    { label: 'WebSocket', pattern: /\bWebSocket\b/ },
    { label: 'EventSource', pattern: /\bEventSource\b/ },
    { label: 'axios', pattern: /\baxios\b/ },
    { label: 'Inertia router', pattern: /@inertiajs\/vue3/ },
    { label: 'form submission', pattern: /useForm|\.submit\s*\(/ },
    { label: 'an absolute URL', pattern: /https?:\/\/(?!www\.w3\.org)/ },
];

function read(relativePath: string): string {
    return readFileSync(fileURLToPath(new URL(relativePath, import.meta.url)), 'utf8');
}

describe('the photo never leaves the device', () => {
    it.each(PHOTO_HANDLING_FILES)('%s calls no network API', (file) => {
        const source = read(file);

        for (const api of NETWORK_APIS) {
            expect(api.pattern.test(source), `${file} must not use ${api.label}`).toBe(false);
        }
    });

    it('keeps the rendered photo in a local object URL and downloads it from there', () => {
        const page = read('../../pages/tools/StudentPhotoPage.vue');

        expect(page).toContain('URL.createObjectURL');
        expect(page).toContain('URL.revokeObjectURL');
        expect(page).toContain('link.download = OUTPUT_FILE_NAME');
    });

    it('reads the picked file only through local File/Blob APIs', () => {
        const decode = read('../../lib/studentPhoto/decode.ts');

        expect(decode).toContain('file.slice');
        expect(decode).toContain('createImageBitmap');
        expect(decode).toContain('URL.createObjectURL');
    });
});
