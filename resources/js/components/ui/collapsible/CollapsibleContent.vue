<script setup lang="ts">
import type { CollapsibleContentProps } from "reka-ui"
import { CollapsibleContent } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<CollapsibleContentProps & { class?: string }>()
</script>

<template>
  <CollapsibleContent data-slot="collapsible-content" :class="cn('collapsible-content', props.class)" v-bind="props">
    <slot />
  </CollapsibleContent>
</template>

<style scoped>
.collapsible-content {
  overflow: hidden;
}
.collapsible-content[data-state="open"] {
  animation: slideDown 200ms ease-out;
}
.collapsible-content[data-state="closed"] {
  animation: slideUp 200ms ease-out;
}

@keyframes slideDown {
  from {
    height: 0;
  }

  to {
    height: var(--reka-collapsible-content-height);
  }
}

@keyframes slideUp {
  from {
    height: var(--reka-collapsible-content-height);
  }

  to {
    height: 0;
  }
}
</style>
