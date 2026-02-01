<script setup lang="ts">
import { computed } from 'vue';
import { NodeViewWrapper, NodeViewContent } from '@tiptap/vue-3';

const props = defineProps<{
    node: {
        attrs: {
            level: number;
            textAlign?: string;
        };
        textContent: string;
    };
}>();

const slugify = (text: string): string => {
    return text
        .toLowerCase()
        .replace(/[^a-z0-9\u0600-\u06FF]+/g, '-')
        .replace(/^-+|-+$/g, '');
};

const headingId = computed(() => {
    return slugify(props.node.textContent);
});

const headingTag = computed(() => {
    return `h${props.node.attrs.level}` as 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6';
});

const textAlignStyle = computed(() => {
    const align = props.node.attrs.textAlign;
    if (align && align !== 'left') {
        return { textAlign: align };
    }
    return {};
});

const scrollToHeading = (e: MouseEvent) => {
    e.preventDefault();
    const id = headingId.value;
    if (!id) return;

    const element = document.getElementById(id);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'start' });
        history.pushState(null, '', `#${id}`);
    }
};
</script>

<template>
    <NodeViewWrapper
        :as="headingTag"
        :id="headingId"
        :style="textAlignStyle"
        class="heading-with-anchor group"
    >
        <NodeViewContent as="span" />
        <a
            v-if="headingId"
            :href="`#${headingId}`"
            class="heading-anchor"
            :aria-label="`رابط إلى ${node.textContent}`"
            @click="scrollToHeading"
        >
            #
        </a>
    </NodeViewWrapper>
</template>
