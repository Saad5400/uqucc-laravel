<script setup lang="ts">
import type { TagsInputInputProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TagsInputInput, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<TagsInputInputProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardProps(delegatedProps)
</script>

<template>
  <TagsInputInput
    data-slot="tags-input-input"
    v-bind="forwarded"
    :class="cn(
      'min-w-24 flex-1 bg-transparent text-base outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
      props.class,
    )"
  />
</template>
