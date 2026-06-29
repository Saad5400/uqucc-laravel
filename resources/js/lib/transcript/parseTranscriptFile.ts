import * as pdfjsLib from 'pdfjs-dist';
import PdfjsWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?worker';
import { parsePdf, type TranscriptResult } from './uquTranscriptParser';

/**
 * Browser-only entry point for transcript parsing. Imports of this module pull
 * in pdf.js and its web worker, so it is meant to be loaded lazily (e.g. via a
 * dynamic `import()` inside a click handler) to keep it out of the SSR bundle
 * and off the initial page load.
 */

pdfjsLib.GlobalWorkerOptions.workerPort = new PdfjsWorker();

export const parseTranscriptFile = async (file: File): Promise<TranscriptResult> => {
    const buffer = await file.arrayBuffer();
    return parsePdf(pdfjsLib, buffer);
};
