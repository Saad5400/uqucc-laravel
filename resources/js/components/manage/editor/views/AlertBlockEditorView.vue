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

/** One-click common icons; the stored attr stays the same plain Iconify slug. */
const ICON_PRESETS: { icon: string; label: string }[] = [
    { icon: 'solar:info-circle-linear', label: 'معلومة' },
    { icon: 'solar:danger-triangle-linear', label: 'تحذير' },
    { icon: 'solar:danger-circle-linear', label: 'خطر' },
    { icon: 'solar:check-circle-linear', label: 'نجاح' },
];

function updateConfig(patch: Record<string, unknown>): void {
    props.updateAttributes({ config: { ...config.value, ...patch } });
}
</script>

<template>
    <NodeViewWrapper class="my-4" dir="rtl">
        <div class="rounded-lg border bg-card shadow-xs" :class="selected && 'ring-[3px] ring-ring/50'">
            <div class="flex flex-wrap items-center gap-2 border-b px-3 py-2">
                <Icon :icon="iconName" class="size-4 shrink-0 text-muted-foreground" />
                <span class="text-xs font-medium text-muted-foreground">تنبيه</span>
                <div class="ms-auto flex flex-wrap items-center gap-1">
                    <button
                        v-for="preset in ICON_PRESETS"
                        :key="preset.icon"
                        type="button"
                        :title="`أيقونة ${preset.label}`"
                        :aria-label="`أيقونة ${preset.label}`"
                        :aria-pressed="iconName === preset.icon"
                        class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                        :class="iconName === preset.icon && 'bg-accent text-accent-foreground'"
                        @click="updateConfig({ icon: preset.icon })"
                    >
                        <Icon :icon="preset.icon" class="size-4" />
                    </button>
                    <span
                        class="inline-flex size-7 shrink-0 items-center justify-center rounded-md border border-input"
                        title="معاينة الأيقونة الحالية"
                    >
                        <Icon :icon="iconName" class="size-4 text-foreground" />
                    </span>
                    <Input
                        :model-value="(config.icon as string) ?? ''"
                        dir="ltr"
                        :placeholder="DEFAULT_ICON"
                        title="أيقونة Iconify (اختيارية — يمكن كتابة أي معرّف)"
                        aria-label="أيقونة Iconify"
                        class="h-7 w-44 font-mono text-xs"
                        @update:model-value="updateConfig({ icon: String($event) })"
                    />
                </div>
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
