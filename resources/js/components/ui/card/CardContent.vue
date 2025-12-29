<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"
import { cardContentVariants, type CardContentVariants } from '.'

const props = withDefaults(
  defineProps<{
    class?: HTMLAttributes["class"]
    size?: CardContentVariants['size']
  }>(),
  {
    size: 'md'
  }
)
</script>

<template>
  <div :class="cn(cardContentVariants({ size: props.size }), props.class)">
    <slot />
  </div>
</template>
