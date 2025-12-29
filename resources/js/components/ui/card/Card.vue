<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import { cardVariants, type CardVariants } from '.'

const props = withDefaults(
  defineProps<{
    class?: HTMLAttributes['class']
    size?: CardVariants['size']
  }>(),
  {
    size: 'md'
  }
)
</script>

<template>
  <div
    data-slot="card"
    :class="cn(cardVariants({ size: props.size }), props.class)"
  >
    <slot />
  </div>
</template>
