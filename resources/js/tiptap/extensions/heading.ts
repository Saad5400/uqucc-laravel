import Heading from '@tiptap/extension-heading';
import { VueNodeViewRenderer } from '@tiptap/vue-3';
import HeadingWithAnchor from './HeadingWithAnchor.vue';

export const HeadingWithId = Heading.extend({
    addNodeView() {
        return VueNodeViewRenderer(HeadingWithAnchor);
    },
});

export default HeadingWithId;
