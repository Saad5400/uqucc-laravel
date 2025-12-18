import { mergeAttributes, Node } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';

import CollapsibleBlockView from '../views/CollapsibleBlockView.vue';

const CollapsibleBlock = Node.create({
    name: 'collapsibleBlock',
    group: 'block',
    atom: true,

    addAttributes() {
        return {
            id: {
                default: 'collapsible',
            },
            config: {
                default: {},
            },
            label: {
                default: null,
            },
            preview: {
                default: null,
            },
        };
    },

    parseHTML() {
        return [
            {
                tag: 'div[data-type="customBlock"][data-id="collapsible"]',
            },
        ];
    },

    renderHTML({ HTMLAttributes }) {
        const config =
            typeof HTMLAttributes.config === 'string'
                ? HTMLAttributes.config
                : JSON.stringify(HTMLAttributes.config ?? {});

        return [
            'div',
            mergeAttributes(HTMLAttributes, {
                'data-type': 'customBlock',
                'data-id': 'collapsible',
                'data-config': config,
            }),
        ];
    },

    addNodeView() {
        return VueNodeViewRenderer(CollapsibleBlockView);
    },
});

export default CollapsibleBlock;
