<script setup lang="ts">
import { Icon } from '@iconify/vue';
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3';
import { Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

import Input from '@/components/ui/input/Input.vue';

import InlineHtmlEditor from '../InlineHtmlEditor.vue';

const props = defineProps(nodeViewProps);

const DEFAULT_ICON = 'solar:info-circle-linear';

function parseConfig(raw: unknown): Record<string, unknown> {
    if (typeof raw === 'string') {
        try {
            return JSON.parse(raw) ?? {};
        } catch {
            return {};
        }
    }
    if (raw && typeof raw === 'object') {
        return raw as Record<string, unknown>;
    }
    return {};
}

const config = computed(() => parseConfig(props.node.attrs.config));
const iconName = computed(() => (config.value.icon as string) || DEFAULT_ICON);
const content = computed(() => (config.value.content as string) ?? '');

function updateConfig(patch: Record<string, unknown>): void {
    props.updateAttributes({ config: { ...config.value, ...patch } });
}
</script>

<template>
    <NodeViewWrapper class="my-4" dir="rtl">
        <div class="rounded-lg border bg-card shadow-xs" :class="selected && 'ring-[3px] ring-ring/50'">
            <div class="flex items-center gap-2 border-b px-3 py-2">
                <Icon :icon="iconName" class="size-4 shrink-0 text-muted-foreground" />
                <span class="text-xs font-medium text-muted-foreground">تنبيه</span>
                <Input
                    :model-value="(config.icon as string) ?? ''"
                    dir="ltr"
                    :placeholder="DEFAULT_ICON"
                    title="أيقونة Iconify"
                    aria-label="أيقونة Iconify"
                    class="ms-auto h-7 max-w-56 font-mono text-xs"
                    @update:model-value="updateConfig({ icon: String($event) })"
                />
                <button
                    type="button"
                    title="حذف التنبيه"
                    aria-label="حذف التنبيه"
                    class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:text-destructive"
                    @click="deleteNode()"
                >
                    <Trash2 class="size-4" />
                </button>
            </div>
            <div class="p-3">
                <InlineHtmlEditor :model-value="content" aria-label="محتوى التنبيه" @update:model-value="updateConfig({ content: $event })" />
            </div>
        </div>
    </NodeViewWrapper>
</template>
