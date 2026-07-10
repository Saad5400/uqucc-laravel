import type { FormDataConvertible } from '@inertiajs/core';
import type { ComputedRef, InjectionKey } from 'vue';

/** TipTap JSON document, legacy HTML string, or empty — passed through as-is. */
export type PageHtmlContent = string | { [key: string]: FormDataConvertible } | null;

/** One node of the pages tree shared by the index route. */
export interface PageTreeNode {
    id: number;
    title: string;
    slug: string;
    icon: string | null;
    hidden: boolean;
    hidden_from_bot: boolean;
    smart_search: boolean;
    has_content: boolean;
    order: number;
    children_count: number;
    children: PageTreeNode[];
}

/** A soft-deleted page row in the trash section (deferred prop). */
export interface TrashedPageRow {
    id: number;
    title: string;
    slug: string;
    deleted_at: string;
    parent_title: string | null;
    /** Children including trashed ones — drives the typed-name force-delete confirm. */
    children_count: number;
}

export type QuickResponseButtonSize = 'full' | 'half' | 'third';

export interface QuickResponseButton {
    text: string;
    url: string;
    size: QuickResponseButtonSize;
}

/** A quick-response button with a local list key for drag reorder. */
export interface QuickResponseButtonRow extends QuickResponseButton {
    id: number;
}

/** The full page payload shared with the edit workspace. */
export interface PageWorkspace {
    id: number;
    title: string;
    slug: string;
    icon: string | null;
    hidden: boolean;
    hidden_from_bot: boolean;
    smart_search: boolean;
    requires_prefix: boolean;
    parent_id: number | null;
    order: number;
    html_content: PageHtmlContent;
    quick_response_auto_extract_message: boolean;
    quick_response_auto_extract_buttons: boolean;
    quick_response_auto_extract_attachments: boolean;
    quick_response_send_link: boolean;
    quick_response_send_screenshot: boolean;
    /** Always an HTML string (frozen contract — the bot consumes it as HTML). */
    quick_response_message: string | null;
    quick_response_buttons: QuickResponseButton[];
    quick_response_attachments: string[];
    deleted_at: string | null;
}

/** An ancestor page in the workspace breadcrumb. */
export interface ParentChainItem {
    id: number;
    title: string;
}

export interface ChildPageRow {
    id: number;
    title: string;
    slug: string;
    hidden: boolean;
    children_count: number;
}

export interface AuthorRow {
    id: number;
    name: string;
}

export interface UserOption {
    id: number;
    name: string;
}

/** A flat tree entry for the parent picker; `level` is the tree depth. */
export interface ParentOption {
    id: number;
    title: string;
    level: number;
}

/** An existing quick-response attachment with its public URL. */
export interface AttachmentInfo {
    path: string;
    url: string;
    name: string;
}

/** Shared state/actions the recursive tree lists inject from the index page. */
export interface PageTreeContext {
    /** True while a search/filter narrows the tree — expansion is forced and drag is disabled. */
    isFiltering: ComputedRef<boolean>;
    isExpanded: (id: number) => boolean;
    toggleExpanded: (id: number) => void;
    openCreateChild: (parentId: number) => void;
    confirmDelete: (node: PageTreeNode) => void;
}

export const pageTreeContextKey: InjectionKey<PageTreeContext> = Symbol('pageTreeContext');

/** Human labels for the quick-response button sizes (mirrors Filament's options). */
export const buttonSizeLabels: Record<QuickResponseButtonSize, string> = {
    full: 'عرض كامل (زر واحد في السطر)',
    half: 'نصف عرض (زران في السطر)',
    third: 'ثلث عرض (ثلاثة أزرار في السطر)',
};
