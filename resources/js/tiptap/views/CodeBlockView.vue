<script setup lang="ts">
import { NodeViewContent, NodeViewWrapper, type NodeViewProps } from '@tiptap/vue-3';
import { Check, Copy, X } from 'lucide-vue-next';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

defineProps<NodeViewProps>();

const copyState = ref<'idle' | 'copied' | 'error'>('idle');

async function copyCode(node: HTMLElement) {
    const codeText = node.textContent ?? '';

    try {
        await navigator.clipboard.writeText(codeText);
        copyState.value = 'copied';
        setTimeout(() => {
            copyState.value = 'idle';
        }, 2000);
    } catch {
        copyState.value = 'error';
        toast.error('Failed to copy code to clipboard');
        setTimeout(() => {
            copyState.value = 'idle';
        }, 2000);
    }
}
</script>

<template>
    <NodeViewWrapper class="relative my-0">
        <button
            @click="copyCode($el as HTMLElement)"
            :disabled="copyState !== 'idle'"
            class="absolute start-1 top-1 z-10 my-0 rounded-md border border-border/50 bg-background/80 p-1.5 backdrop-blur-sm disabled:opacity-50"
            :title="copyState === 'copied' ? 'Copied!' : copyState === 'error' ? 'Failed to copy' : 'Copy code'"
        >
            <Copy v-if="copyState === 'idle'" class="my-0 block size-3.5" />
            <Check v-else-if="copyState === 'copied'" class="my-0 block size-3.5 text-green-500" />
            <X v-else class="my-0 block size-3.5 text-destructive" />
        </button>
        <pre class="!my-0"><NodeViewContent as="code" /></pre>
    </NodeViewWrapper>
</template>
