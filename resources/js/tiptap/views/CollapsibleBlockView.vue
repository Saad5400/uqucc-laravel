<script setup lang="ts">
import { NodeViewWrapper, type NodeViewProps } from '@tiptap/vue-3';
import DOMPurify from 'isomorphic-dompurify';
import { computed } from 'vue';

import Button from '@/components/ui/button/Button.vue';
import Collapsible from '@/components/ui/collapsible/Collapsible.vue';
import CollapsibleContent from '@/components/ui/collapsible/CollapsibleContent.vue';
import CollapsibleTrigger from '@/components/ui/collapsible/CollapsibleTrigger.vue';

const props = defineProps<NodeViewProps>();

const faqConfig = computed(() => {
    const raw = props.node?.attrs?.config;
    if (typeof raw === 'string') {
        try {
            return JSON.parse(raw);
        } catch {
            return {};
        }
    }
    return (raw as Record<string, unknown>) ?? {};
});

const collapsibleQuestion = computed(() => (faqConfig.value?.question as string) ?? 'قسم قابل للطي');
const collapsibleAnswer = computed(() => DOMPurify.sanitize((faqConfig.value?.answer as string) ?? ''));
</script>

<template>
    <NodeViewWrapper class="block">
        <Collapsible class="collapsible-block">
            <CollapsibleTrigger as-child class="w-full">
                <Button
                    variant="secondary"
                    class="collapsible-trigger my-0 h-fit w-full justify-between border-2 text-start text-base break-words whitespace-normal text-foreground/80"
                >
                    {{ collapsibleQuestion }}
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent class="collapsible-content-panel ms-3 rounded-b-xl bg-secondary/60 shadow">
                <div class="ps-4">
                    <div class="collapsible-content" v-html="collapsibleAnswer"></div>
                </div>
            </CollapsibleContent>
        </Collapsible>
    </NodeViewWrapper>
</template>
