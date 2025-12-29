<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"
import { cardHeaderVariants, type CardHeaderVariants } from '.'

const props = withDefaults(
  defineProps<{
    class?: HTMLAttributes["class"]
    size?: CardHeaderVariants['size']
  }>(),
  {
    size: 'md'
  }
)
</script>

<template>
  <div :class="cn(cardHeaderVariants({ size: props.size }), props.class)">
    <slot />
  </div>
</template>
