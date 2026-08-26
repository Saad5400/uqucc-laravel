import { describe, expect, it } from 'vitest';
import { isRasterType, sniffFileType } from './sniff';

function bytes(...values: (number | string)[]): Uint8Array {
    const flat: number[] = [];

    for (const value of values) {
        if (typeof value === 'number') {
            flat.push(value);
        } else {
            for (const character of value) {
                flat.push(character.charCodeAt(0));
            }
        }
    }

    return new Uint8Array(flat);
}

describe('sniffFileType', () => {
    it('detects a JPEG', () => {
        expect(sniffFileType(bytes(0xff, 0xd8, 0xff, 0xe0))).toBe('jpeg');
    });

    it('detects a PNG', () => {
        expect(sniffFileType(bytes(0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a))).toBe('png');
    });

    it('detects a GIF', () => {
        expect(sniffFileType(bytes('GIF89a'))).toBe('gif');
    });

    it('detects a WebP but not a bare RIFF container', () => {
        expect(sniffFileType(bytes('RIFF', 0, 0, 0, 0, 'WEBP'))).toBe('webp');
        expect(sniffFileType(bytes('RIFF', 0, 0, 0, 0, 'WAVE'))).toBe('unknown');
    });

    it('detects HEIC/HEIF brands from the ftyp box', () => {
        expect(sniffFileType(bytes(0, 0, 0, 0x18, 'ftyp', 'heic'))).toBe('heif');
        expect(sniffFileType(bytes(0, 0, 0, 0x18, 'ftyp', 'mif1'))).toBe('heif');
    });

    it('detects AVIF and separates video containers', () => {
        expect(sniffFileType(bytes(0, 0, 0, 0x18, 'ftyp', 'avif'))).toBe('avif');
        expect(sniffFileType(bytes(0, 0, 0, 0x18, 'ftyp', 'isom'))).toBe('video');
    });

    it('detects the formats the browser cannot decode', () => {
        expect(sniffFileType(bytes('%PDF-1.7'))).toBe('pdf');
        expect(sniffFileType(bytes(0x49, 0x49, 0x2a, 0x00))).toBe('tiff');
        expect(sniffFileType(bytes(0x4d, 0x4d, 0x00, 0x2a))).toBe('tiff');
        expect(sniffFileType(bytes('<svg xmlns="http://www.w3.org/2000/svg">'))).toBe('svg');
        expect(sniffFileType(bytes('<?xml version="1.0"?><svg>'))).toBe('svg');
    });

    it('detects a BMP', () => {
        expect(sniffFileType(bytes('BM', 0, 0, 0, 0))).toBe('bmp');
    });

    it('treats an empty or unrecognised file as unknown', () => {
        expect(sniffFileType(new Uint8Array())).toBe('unknown');
        expect(sniffFileType(bytes(1, 2, 3, 4, 5, 6, 7, 8))).toBe('unknown');
    });

    it('reports which types are worth handing to the decoder', () => {
        expect(isRasterType('jpeg')).toBe(true);
        expect(isRasterType('heif')).toBe(true);
        expect(isRasterType('pdf')).toBe(false);
        expect(isRasterType('svg')).toBe(false);
        expect(isRasterType('unknown')).toBe(false);
    });
});
