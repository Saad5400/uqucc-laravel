<script setup lang="ts">
/**
 * Generic pagination footer for server-side paginated lists. The parent owns
 * navigation: listen to `update:page` and issue the Inertia visit so filters
 * and partial-reload options stay in one place.
 */
import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/formatters';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

defineProps<{
    page: number;
    pages: number;
    total?: number;
}>();

const emit = defineEmits<{
    'update:page': [page: number];
}>();
</script>

<template>
    <div v-if="pages > 1" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-muted-foreground tabular-nums">
            صفحة {{ formatNumber(page) }} من {{ formatNumber(pages) }}
            <template v-if="total !== undefined"> — {{ formatNumber(total) }} سجل</template>
        </p>
        <div class="flex items-center gap-2">
            <Button variant="outline" size="sm" :disabled="page <= 1" @click="emit('update:page', page - 1)">
                <ChevronRight />
                السابق
            </Button>
            <Button variant="outline" size="sm" :disabled="page >= pages" @click="emit('update:page', page + 1)">
                التالي
                <ChevronLeft />
            </Button>
        </div>
    </div>
</template>
