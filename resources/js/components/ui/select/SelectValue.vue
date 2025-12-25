<script setup lang="ts">
import { SelectValue, type SelectValueProps } from 'reka-ui'
import { type HTMLAttributes } from 'vue'

defineProps<SelectValueProps & { class?: HTMLAttributes['class'] }>()
</script>

<template>
  <SelectValue v-bind="$props">
    <slot />
  </SelectValue>
</template>
