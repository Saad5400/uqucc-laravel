import { mergeAttributes, Node } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';

import AlertBlockView from '../views/AlertBlockView.vue';

const AlertBlock = Node.create({
    name: 'alertBlock',
    group: 'block',
    atom: true,

    addAttributes() {
        return {
            id: {
                default: 'alert',
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
                tag: 'div[data-type="customBlock"][data-id="alert"]',
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
                'data-id': 'alert',
                'data-config': config,
            }),
        ];
    },

    addNodeView() {
        return VueNodeViewRenderer(AlertBlockView);
    },
});

export default AlertBlock;
