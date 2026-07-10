<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { computed } from 'vue';
import { extractionStatusLabels, indexStatusLabels, type CorpusDocumentStatus, type CorpusIndexStatus } from './types';

const props = defineProps<{
    /** `extraction` reads the document status; `index` reads the corpus-item status (null = never indexed). */
    kind: 'extraction' | 'index';
    status: CorpusDocumentStatus | CorpusIndexStatus | null;
    /** Extraction error, shown as the badge tooltip when present. */
    error?: string | null;
}>();

const label = computed(() => {
    if (props.status === null) {
        return 'غير مفهرس';
    }

    return props.kind === 'extraction'
        ? extractionStatusLabels[props.status as CorpusDocumentStatus]
        : indexStatusLabels[props.status as CorpusIndexStatus];
});

/** In-progress states get a warm tint, success a positive one (chart tokens — no semantic success/warning tokens exist). */
const tintClass = computed(() => {
    switch (props.status) {
        case 'ready':
            return 'border-transparent bg-(--chart-2)/15 text-(--chart-2)';
        case 'extracting':
        case 'processing':
            return 'border-transparent bg-(--chart-5)/15 text-(--chart-5)';
        default:
            return '';
    }
});
</script>

<template>
    <Badge :variant="status === 'failed' ? 'destructive' : 'outline'" :class="tintClass" :title="error ?? undefined">
        {{ label }}
    </Badge>
</template>
