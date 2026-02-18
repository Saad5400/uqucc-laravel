import { mergeAttributes, Node } from '@tiptap/core';
import { VueNodeViewRenderer } from '@tiptap/vue-3';

import CodeBlockView from '../views/CodeBlockView.vue';

const CodeBlock = Node.create({
    name: 'codeBlock',
    group: 'block',
    content: 'text*',
    marks: '',
    defining: true,

    addOptions() {
        return {
            HTMLAttributes: {},
        };
    },

    addAttributes() {
        return {
            language: {
                default: null,
                parseHTML: (element) => element.getAttribute('data-language') || element.classList[0]?.replace('language-', '') || null,
                renderHTML: (attributes) => {
                    if (!attributes.language) {
                        return {};
                    }
                    return {
                        'data-language': attributes.language,
                        class: `language-${attributes.language}`,
                    };
                },
            },
        };
    },

    parseHTML() {
        return [
            {
                tag: 'pre',
                preserveWhitespace: 'full',
            },
        ];
    },

    renderHTML({ node, HTMLAttributes }) {
        return [
            'pre',
            mergeAttributes(this.options.HTMLAttributes, HTMLAttributes),
            ['code', {}, 0],
        ];
    },

    addCommands() {
        return {
            setCodeBlock:
                (attributes = {}) =>
                ({ commands }) => {
                    return commands.wrapIn(this.name, attributes);
                },
            toggleCodeBlock:
                (attributes = {}) =>
                ({ commands }) => {
                    return commands.toggleWrap(this.name, attributes);
                },
            unsetCodeBlock:
                () =>
                ({ commands }) => {
                    return commands.lift(this.name);
                },
        };
    },

    addKeyboardShortcuts() {
        return {
            'Mod-Alt-c': () => this.editor.commands.toggleCodeBlock(),
        };
    },

    addNodeView() {
        return VueNodeViewRenderer(CodeBlockView);
    },
});

export default CodeBlock;
