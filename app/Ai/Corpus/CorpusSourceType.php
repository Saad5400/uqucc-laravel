<?php

namespace App\Ai\Corpus;

/**
 * The kind of source a corpus item was ingested from. CMS pages today;
 * uploaded documents are the planned second source — adding one is a new case
 * plus an ingest path, no schema change.
 */
enum CorpusSourceType: string
{
    case Page = 'page';
    case Document = 'document';
}
