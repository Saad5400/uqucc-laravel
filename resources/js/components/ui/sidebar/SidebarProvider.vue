<script setup lang="ts">
import type { HTMLAttributes, Ref } from "vue"
import { useMediaQuery } from "@vueuse/core"
import { TooltipProvider } from "reka-ui"
import { computed, ref, onMounted, watch } from "vue"
import { cn } from "@/lib/utils"
import { provideSidebarContext, SIDEBAR_COOKIE_MAX_AGE, SIDEBAR_COOKIE_NAME, SIDEBAR_KEYBOARD_SHORTCUT, SIDEBAR_WIDTH, SIDEBAR_WIDTH_ICON } from "./utils"

const props = withDefaults(defineProps<{
  defaultOpen?: boolean
  open?: boolean
  class?: HTMLAttributes["class"]
}>(), {
  defaultOpen: true,
  open: undefined,
})

const emits = defineEmits<{
  "update:open": [open: boolean]
}>()

// Track if we're mounted (client-side)
const isMounted = ref(false)

// Media query - but we'll only use it after mount to avoid SSR mismatch
const mediaQueryMobile = useMediaQuery("(max-width: 768px)")

// Start with false during SSR, then sync with actual value after mount
// This ensures SSR always renders the desktop sidebar
const isMobile = ref(false)

const openMobile = ref(false)

// Determine initial open state
// During SSR, use defaultOpen prop
// On client, try to read from cookie after mount
const getInitialOpen = () => {
  if (props.open !== undefined) return props.open
  return props.defaultOpen ?? true
}

const open = ref(getInitialOpen())

// Sync with v-model if provided
watch(() => props.open, (newVal) => {
  if (newVal !== undefined) {
    open.value = newVal
  }
})

// Emit changes
watch(open, (newVal) => {
  emits("update:open", newVal)
})

onMounted(() => {
  isMounted.value = true
  
  // Now that we're on the client, sync with actual mobile state
  // Use nextTick-like delay to ensure hydration completes first
  requestAnimationFrame(() => {
    isMobile.value = mediaQueryMobile.value
  })
  
  // Try to read cookie state on client
  if (props.open === undefined) {
    const cookieValue = document.cookie
      .split('; ')
      .find(row => row.startsWith(`${SIDEBAR_COOKIE_NAME}=`))
      ?.split('=')[1]
    
    if (cookieValue !== undefined) {
      open.value = cookieValue !== 'false'
    }
  }
})

// Keep isMobile in sync with media query after mount
watch(mediaQueryMobile, (newVal) => {
  if (isMounted.value) {
    isMobile.value = newVal
  }
})

function setOpen(value: boolean) {
  open.value = value

  // Only set cookie on client
  if (typeof document !== 'undefined') {
    document.cookie = `${SIDEBAR_COOKIE_NAME}=${value}; path=/; max-age=${SIDEBAR_COOKIE_MAX_AGE}`
  }
}

function setOpenMobile(value: boolean) {
  openMobile.value = value
}

function toggleSidebar() {
  return isMobile.value ? setOpenMobile(!openMobile.value) : setOpen(!open.value)
}

// Keyboard shortcut - only on client
onMounted(() => {
  const handleKeydown = (event: KeyboardEvent) => {
    if (event.key === SIDEBAR_KEYBOARD_SHORTCUT && (event.metaKey || event.ctrlKey)) {
      event.preventDefault()
      toggleSidebar()
    }
  }
  
  document.addEventListener("keydown", handleKeydown)
  
  // Cleanup handled by Vue's onUnmounted if needed
})

const state = computed(() => open.value ? "expanded" : "collapsed")

provideSidebarContext({
  state,
  open: open as Ref<boolean>,
  setOpen,
  isMobile: isMobile as Ref<boolean>,
  openMobile,
  setOpenMobile,
  toggleSidebar,
})
</script>

<template>
  <TooltipProvider :delay-duration="0">
    <div
      :style="{
        '--sidebar-width': SIDEBAR_WIDTH,
        '--sidebar-width-icon': SIDEBAR_WIDTH_ICON,
      }"
      :class="cn('group/sidebar-wrapper flex min-h-svh w-full has-[[data-variant=inset]]:bg-sidebar', props.class)"
      v-bind="$attrs"
    >
      <slot />
    </div>
  </TooltipProvider>
</template>
