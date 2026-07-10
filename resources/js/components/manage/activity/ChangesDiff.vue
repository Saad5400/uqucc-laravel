<script setup lang="ts">
/**
 * Old/new diff for one activity entry: a two-column table per changed key
 * (old = destructive tint, new = positive tint via the chart-2 token), with
 * the raw JSON collapsible at the bottom. Values are rendered as text only —
 * never as HTML — and non-scalar values become pretty JSON in LTR islands.
 */
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ChevronDown } from 'lucide-vue-next';
import { computed } from 'vue';
import type { ActivityChanges } from './types';

const props = defineProps<{
    changes: ActivityChanges;
}>();

const keys = computed(() => {
    const union = new Set([...Object.keys(props.changes.old ?? {}), ...Object.keys(props.changes.new ?? {})]);

    return [...union];
});

function isComplex(value: unknown): boolean {
    return typeof value === 'object' && value !== null;
}

function displayValue(value: unknown): string {
    if (value === undefined) {
        return '—';
    }

    if (value === null) {
        return 'null';
    }

    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }

    if (isComplex(value)) {
        return JSON.stringify(value, null, 2);
    }

    return String(value);
}

const rawJson = computed(() => JSON.stringify({ old: props.changes.old, attributes: props.changes.new }, null, 2));
</script>

<template>
    <div class="space-y-3">
        <div class="overflow-x-auto rounded-md border border-border">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border bg-muted/50 text-start text-xs text-muted-foreground">
                        <th class="px-3 py-2 text-start font-medium">الحقل</th>
                        <th class="px-3 py-2 text-start font-medium">القيمة القديمة</th>
                        <th class="px-3 py-2 text-start font-medium">القيمة الجديدة</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="key in keys" :key="key" class="border-b border-border align-top last:border-b-0">
                        <td class="px-3 py-2 font-mono text-xs" dir="ltr">{{ key }}</td>
                        <td class="max-w-64 bg-destructive/10 px-3 py-2">
                            <pre
                                v-if="isComplex(changes.old?.[key])"
                                dir="ltr"
                                class="max-h-40 overflow-auto text-start font-mono text-xs whitespace-pre-wrap"
                                >{{ displayValue(changes.old?.[key]) }}</pre
                            >
                            <span v-else class="break-words" :class="{ 'text-muted-foreground italic': changes.old?.[key] == null }">
                                {{ displayValue(changes.old?.[key]) }}
                            </span>
                        </td>
                        <td class="max-w-64 bg-(--chart-2)/10 px-3 py-2">
                            <pre
                                v-if="isComplex(changes.new?.[key])"
                                dir="ltr"
                                class="max-h-40 overflow-auto text-start font-mono text-xs whitespace-pre-wrap"
                                >{{ displayValue(changes.new?.[key]) }}</pre
                            >
                            <span v-else class="break-words" :class="{ 'text-muted-foreground italic': changes.new?.[key] == null }">
                                {{ displayValue(changes.new?.[key]) }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Collapsible>
            <CollapsibleTrigger class="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                <ChevronDown class="size-3.5" />
                عرض JSON الخام
            </CollapsibleTrigger>
            <CollapsibleContent>
                <pre dir="ltr" class="mt-2 max-h-64 overflow-auto rounded-md bg-muted p-3 text-start font-mono text-xs">{{ rawJson }}</pre>
            </CollapsibleContent>
        </Collapsible>
    </div>
</template>
