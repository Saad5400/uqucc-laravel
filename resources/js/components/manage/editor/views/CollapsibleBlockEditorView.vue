<script setup lang="ts">
import { NodeViewWrapper, nodeViewProps } from '@tiptap/vue-3';
import { ChevronsUpDown, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';

import Input from '@/components/ui/input/Input.vue';

import InlineHtmlEditor from '../InlineHtmlEditor.vue';

const props = defineProps(nodeViewProps);

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
const question = computed(() => (config.value.question as string) ?? '');
const answer = computed(() => (config.value.answer as string) ?? '');

function updateConfig(patch: Record<string, unknown>): void {
    props.updateAttributes({ config: { ...config.value, ...patch } });
}
</script>

<template>
    <NodeViewWrapper class="my-4" dir="rtl">
        <div class="rounded-lg border shadow-xs" :class="selected && 'ring-[3px] ring-ring/50'">
            <div class="flex items-center gap-2 rounded-t-lg border-b bg-secondary/60 px-3 py-2">
                <ChevronsUpDown class="size-4 shrink-0 text-muted-foreground" />
                <span class="shrink-0 text-xs font-medium text-muted-foreground">قسم قابل للطي</span>
                <Input
                    :model-value="question"
                    placeholder="العنوان (السؤال)"
                    aria-label="عنوان القسم القابل للطي"
                    class="h-7 bg-background text-sm"
                    @update:model-value="updateConfig({ question: String($event) })"
                />
                <button
                    type="button"
                    title="حذف القسم"
                    aria-label="حذف القسم"
                    class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:text-destructive"
                    @click="deleteNode()"
                >
                    <Trash2 class="size-4" />
                </button>
            </div>
            <div class="rounded-b-lg bg-secondary/20 p-3 ps-6">
                <InlineHtmlEditor :model-value="answer" aria-label="محتوى القسم القابل للطي" @update:model-value="updateConfig({ answer: $event })" />
            </div>
        </div>
    </NodeViewWrapper>
</template>
