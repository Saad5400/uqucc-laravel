<script setup lang="ts">
import type { LabelHTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'

interface Props extends /* @vue-ignore */ LabelHTMLAttributes {
  class?: string
}

const props = defineProps<Props>()
</script>

<template>
  <label
    v-bind="props"
    :class="
      cn(
        'text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70',
        props.class
      )
    "
  >
    <slot />
  </label>
</template>
