<script setup lang="ts">
import { Icon } from '@iconify/vue';
import { NodeViewWrapper } from '@tiptap/vue-3';
import DOMPurify from 'isomorphic-dompurify';
import { computed } from 'vue';

import Alert from '@/components/ui/alert/Alert.vue';
import AlertDescription from '@/components/ui/alert/AlertDescription.vue';

const props = defineProps<{
    node: {
        attrs: Record<string, unknown>;
    };
}>();

const alertBody = computed(() => {
    const raw = props.node?.attrs?.config;
    let content = '';

    if (typeof raw === 'string') {
        try {
            content = JSON.parse(raw)?.content ?? '';
        } catch (error) {
            content = '';
        }
    } else if (raw && typeof raw === 'object') {
        content = (raw as Record<string, unknown>)?.content as string;
    }

    return DOMPurify.sanitize(content ?? '');
});

const alertIcon = computed(() => {
    const raw = props.node?.attrs?.config;
    if (typeof raw === 'string') {
        try {
            return JSON.parse(raw)?.icon || 'solar:info-circle-linear';
        } catch (error) {
            return 'solar:info-circle-linear';
        }
    }
    return (raw as Record<string, unknown>)?.icon || 'solar:info-circle-linear';
});
</script>

<template>
    <NodeViewWrapper class="block">
        <Alert>
            <Icon :icon="alertIcon" />
            <AlertDescription v-html="alertBody" />
        </Alert>
    </NodeViewWrapper>
</template>
