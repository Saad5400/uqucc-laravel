import Heading from '@tiptap/extension-heading';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import { mergeAttributes } from '@tiptap/core';
import HeadingWithAnchor from './HeadingWithAnchor.vue';

export const slugify = (text: string): string => {
    return text
        .toLowerCase()
        .replace(/[^a-z0-9\u0600-\u06FF]+/g, '-')
        .replace(/^-+|-+$/g, '');
};

// SSR-compatible extension that adds IDs via renderHTML
export const HeadingWithIdSSR = Heading.extend({
    renderHTML({ node, HTMLAttributes }) {
        const level = node.attrs.level;
        const textContent = node.textContent;
        const id = slugify(textContent);

        return [
            `h${level}`,
            mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, {
                id: id || undefined,
                class: 'heading-with-anchor',
            }),
            0,
        ];
    },
});

// Client-side extension with Vue node view for interactive anchors
export const HeadingWithId = Heading.extend({
    addNodeView() {
        return VueNodeViewRenderer(HeadingWithAnchor);
    },
});

export default HeadingWithId;
