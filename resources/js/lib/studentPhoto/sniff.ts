/**
 * Magic-byte file sniffing. The browser's `File.type` comes from the OS and
 * lies often enough to matter here — an iPhone HEIC renamed to `.jpg` still
 * arrives as `image/jpeg` — so the tool decides what it was actually handed by
 * reading the header, and uses that to explain failures precisely.
 */

export type SniffedType = 'jpeg' | 'png' | 'gif' | 'webp' | 'bmp' | 'avif' | 'heif' | 'tiff' | 'svg' | 'pdf' | 'video' | 'unknown';

/** Types the tool will hand to the browser decoder (HEIF/AVIF only decode on some browsers). */
const RASTER_TYPES: SniffedType[] = ['jpeg', 'png', 'gif', 'webp', 'bmp', 'avif', 'heif'];

export function isRasterType(type: SniffedType): boolean {
    return RASTER_TYPES.includes(type);
}

const HEIF_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];
const AVIF_BRANDS = ['avif', 'avis'];

function ascii(bytes: Uint8Array, start: number, length: number): string {
    let out = '';

    for (let index = start; index < start + length && index < bytes.length; index += 1) {
        out += String.fromCharCode(bytes[index]);
    }

    return out;
}

function matches(bytes: Uint8Array, signature: number[]): boolean {
    if (bytes.length < signature.length) {
        return false;
    }

    return signature.every((byte, index) => bytes[index] === byte);
}

/**
 * Identify a file from its leading bytes. Only the first ~64 bytes are read for
 * binary formats; SVG needs a slightly wider text window because of comments
 * and XML declarations before the root element.
 */
export function sniffFileType(bytes: Uint8Array): SniffedType {
    if (bytes.length === 0) {
        return 'unknown';
    }

    if (matches(bytes, [0xff, 0xd8, 0xff])) {
        return 'jpeg';
    }

    if (matches(bytes, [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])) {
        return 'png';
    }

    if (ascii(bytes, 0, 4) === 'GIF8') {
        return 'gif';
    }

    if (ascii(bytes, 0, 4) === 'RIFF' && ascii(bytes, 8, 4) === 'WEBP') {
        return 'webp';
    }

    if (matches(bytes, [0x42, 0x4d])) {
        return 'bmp';
    }

    if (matches(bytes, [0x49, 0x49, 0x2a, 0x00]) || matches(bytes, [0x4d, 0x4d, 0x00, 0x2a])) {
        return 'tiff';
    }

    if (ascii(bytes, 4, 4) === 'ftyp') {
        const brand = ascii(bytes, 8, 4).toLowerCase();

        if (HEIF_BRANDS.includes(brand)) {
            return 'heif';
        }

        if (AVIF_BRANDS.includes(brand)) {
            return 'avif';
        }

        return 'video';
    }

    if (ascii(bytes, 0, 4) === '%PDF') {
        return 'pdf';
    }

    const head = ascii(bytes, 0, Math.min(bytes.length, 512)).trimStart().toLowerCase();

    if (head.startsWith('<svg') || (head.startsWith('<?xml') && head.includes('<svg'))) {
        return 'svg';
    }

    return 'unknown';
}
