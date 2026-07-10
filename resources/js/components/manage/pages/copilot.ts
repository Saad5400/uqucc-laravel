import { postJson } from '@/lib/http';
import type { PageHtmlContent } from './types';

/**
 * Client for the page-workspace copilot endpoints. Each call runs one
 * generation server-side and returns the result — nothing is saved; the
 * admin reviews the filled field and confirms by saving the page.
 */

export interface SeoMetaSuggestion {
    /** The suggested meta title (shown to the admin, not stored anywhere). */
    title: string;
    /** The description as the HTML string the quick-response message field stores. */
    message: string;
}

/** «تحسين النص» — returns the improved TipTap document for the editor. */
export async function improvePageText(pageId: number, content: PageHtmlContent, instruction: string): Promise<PageHtmlContent> {
    const data = await postJson<{ content: PageHtmlContent }>(
        `/manage/pages/${pageId}/copilot/improve-text`,
        { content, instruction },
        'تعذر تحسين النص.',
    );

    return data.content;
}

/** «مسودة قسم» — returns the TipTap document with the drafted section appended. */
export async function draftPageSection(pageId: number, content: PageHtmlContent, topic: string): Promise<PageHtmlContent> {
    const data = await postJson<{ content: PageHtmlContent }>(
        `/manage/pages/${pageId}/copilot/draft-section`,
        { content, topic },
        'تعذر توليد مسودة القسم.',
    );

    return data.content;
}

/** «توليد وصف SEO» — returns a suggested meta title + the description HTML for the quick-response message field. */
export async function generatePageSeoMeta(pageId: number): Promise<SeoMetaSuggestion> {
    return postJson<SeoMetaSuggestion>(`/manage/pages/${pageId}/copilot/seo-meta`, {}, 'تعذر توليد وصف SEO.');
}
