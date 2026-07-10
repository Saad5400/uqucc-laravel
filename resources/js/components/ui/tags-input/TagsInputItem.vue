<script setup lang="ts">
import type { TagsInputItemProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TagsInputItem, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<TagsInputItemProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardProps(delegatedProps)
</script>

<template>
  <TagsInputItem
    data-slot="tags-input-item"
    v-bind="forwarded"
    :class="cn(
      'inline-flex items-center gap-1 rounded-md border border-transparent bg-secondary px-2 py-0.5 text-xs font-medium text-secondary-foreground data-[state=active]:ring-ring/50 data-[state=active]:ring-[3px]',
      props.class,
    )"
  >
    <slot />
  </TagsInputItem>
</template>
