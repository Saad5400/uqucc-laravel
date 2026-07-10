<script setup lang="ts">
import type { TagsInputItemDeleteProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { X } from "lucide-vue-next"
import { TagsInputItemDelete, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<TagsInputItemDeleteProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardProps(delegatedProps)
</script>

<template>
  <TagsInputItemDelete
    data-slot="tags-input-item-delete"
    v-bind="forwarded"
    :class="cn('rounded-sm opacity-70 transition-opacity hover:opacity-100 focus-visible:ring-ring/50 focus-visible:ring-[3px] focus-visible:outline-none', props.class)"
  >
    <slot>
      <X class="size-3" />
    </slot>
  </TagsInputItemDelete>
</template>
