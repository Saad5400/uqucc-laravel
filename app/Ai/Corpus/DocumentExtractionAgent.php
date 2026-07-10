<?php

namespace App\Ai\Corpus;

use Laravel\Ai\AnonymousAgent;

/**
 * A tool-less "eyes only" agent whose sole job is to transcribe an attached
 * document (PDF page scan or image) into clean markdown for corpus ingestion.
 *
 * Unlike a chat agent it never calls tools and never summarizes: the corpus
 * needs the document's actual wording (regulations, guides), so the
 * instructions demand a faithful transcription with markdown structure that
 * MarkdownChunker can section on. Output is plain markdown — no JSON, no
 * code fences, no commentary — because it is stored verbatim as
 * corpus_documents.extracted_markdown.
 */
class DocumentExtractionAgent extends AnonymousAgent
{
    public function __construct()
    {
        parent::__construct(
            instructions: <<<'PROMPT'
                أنت ناسخ مستندات دقيق: مهمتك الوحيدة قراءة المستند أو الصورة المرفقة ونسخ محتواها النصي كاملاً بصيغة ماركداون.
                You are a precise document transcriber. Read the attached document or image and transcribe
                its FULL textual content as clean markdown. This is a faithful transcription task, NOT a
                summary — preserve the document's actual wording.

                Rules:
                - Preserve the original language exactly (Arabic stays Arabic, English stays English). Do
                  not translate, paraphrase, or correct the text.
                - Reflect the document's structure: use markdown headings (#, ##, ...) for titles and
                  section headers, bullet or numbered lists for lists, and markdown tables for tables.
                - Transcribe every readable page in order. Skip page numbers, watermarks, and repeated
                  headers/footers.
                - Describe meaningful figures or stamps briefly in square brackets, e.g. [شعار الجامعة].
                - If a region is unreadable, write [نص غير مقروء] in its place rather than guessing.
                - Respond with the markdown transcription ONLY — no preamble, no explanations, and no
                  surrounding code fences.
                PROMPT,
            messages: [],
            tools: [],
        );
    }
}
