import Table from '@tiptap/extension-table';
import { mergeAttributes } from '@tiptap/core';

const CustomTable = Table.extend({
    renderHTML({ HTMLAttributes }) {
        return [
            'div',
            {
                class: 'relative w-full max-w-[calc(100%-1rem)] overflow-auto',
            },
            [
                'table',
                mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, {
                    class: 'w-full caption-bottom text-sm',
                }),
                0,
            ],
        ];
    },
});

export default CustomTable;

