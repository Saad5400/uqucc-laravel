<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Fuse from 'fuse.js'
import { Search, ArrowUpRight, Sparkles } from 'lucide-vue-next'
import { Input } from '@/components/ui/input'
import { Card, CardContent } from '@/components/ui/card'
import { cn } from '@/lib/utils'
import type { AppPageProps, SearchItem } from '@/types'

const page = usePage<AppPageProps<{ searchData: SearchItem[] }>>()
const searchIndex = computed<SearchItem[]>(() => page.props.searchData ?? [])

const query = ref('')
const isOpen = ref(false)
const activeIndex = ref(0)
const containerRef = ref<HTMLElement | null>(null)
const listId = 'search-combobox-list'

const fuse = computed(
  () =>
    new Fuse(searchIndex.value, {
      keys: [
        { name: 'title', weight: 0.6 },
        { name: 'keywords', weight: 0.3 },
        { name: 'breadcrumb', weight: 0.1 },
      ],
      threshold: 0.32,
      ignoreLocation: true,
      includeScore: true,
      minMatchCharLength: 1,
    }),
)

const trimmedQuery = computed(() => query.value.trim())

const results = computed(() => {
  if (!trimmedQuery.value) {
    return searchIndex.value.slice(0, 6)
  }

  return fuse.value.search(trimmedQuery.value).slice(0, 8).map((item) => item.item)
})

const hasResults = computed(() => results.value.length > 0)
const activeDescendant = computed(() => {
  if (!hasResults.value) return undefined

  const current = results.value[activeIndex.value] ?? results.value[0]
  return current ? `search-option-${current.id}` : undefined
})

const openPanel = () => {
  isOpen.value = true
}

const closePanel = () => {
  isOpen.value = false
}

const goTo = (item: SearchItem) => {
  closePanel()
  query.value = item.title
  router.visit(item.slug)
}

const submitFirst = () => {
  const item = results.value[0]
  if (item) {
    goTo(item)
  }
}

const handleKeydown = (event: KeyboardEvent) => {
  if (!isOpen.value && ['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) {
    openPanel()
  }

  if (!hasResults.value) return

  if (event.key === 'ArrowDown') {
    event.preventDefault()
    activeIndex.value = (activeIndex.value + 1) % results.value.length
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    activeIndex.value = (activeIndex.value - 1 + results.value.length) % results.value.length
  } else if (event.key === 'Enter') {
    event.preventDefault()
    submitFirst()
  } else if (event.key === 'Escape') {
    closePanel()
  }
}

watch(trimmedQuery, () => {
  activeIndex.value = 0
})

const handleClickOutside = (event: MouseEvent) => {
  if (!containerRef.value) return
  if (!containerRef.value.contains(event.target as Node)) {
    closePanel()
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<template>
  <div ref="containerRef" class="relative w-full max-w-xl">
    <form
      role="search"
      class="group relative flex h-11 items-center gap-2 rounded-md border border-input bg-background pr-2 shadow-sm ring-0 transition focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/30"
      @submit.prevent="submitFirst"
    >
      <Search class="absolute right-3 size-4 text-muted-foreground" />
      <Input
        v-model="query"
        placeholder="ابحث بسرعة عن أي صفحة (ذكي)"
        class="h-full border-0 pl-3 pr-9 shadow-none focus-visible:ring-0"
        role="combobox"
        type="search"
        autocomplete="off"
        aria-autocomplete="list"
        :aria-expanded="isOpen"
        :aria-activedescendant="activeDescendant"
        :aria-controls="listId"
        @focus="openPanel"
        @keydown="handleKeydown"
      />
      <span class="hidden text-xs text-muted-foreground sm:inline-flex items-center gap-1">
        <Sparkles class="size-3.5" />
        بحث ذكي
      </span>
    </form>

    <Card
      v-if="isOpen && hasResults"
      size="sm"
      class="absolute right-0 z-20 mt-2 w-[min(32rem,90vw)] overflow-hidden border-border/80 bg-popover shadow-lg ring-1 ring-black/5"
    >
      <CardContent class="p-2">
        <ul :id="listId" role="listbox" class="flex flex-col divide-y divide-border/80">
          <li v-for="(item, index) in results" :key="item.id">
            <Link
              :id="`search-option-${item.id}`"
              role="option"
              :aria-selected="index === activeIndex"
              :href="item.slug"
              class="group/link flex flex-col gap-1 rounded-lg px-3 py-2 transition hover:bg-muted"
              :class="cn({ 'bg-muted': index === activeIndex })"
              @mouseenter="activeIndex = index"
            >
              <div class="flex items-start justify-between gap-2 min-w-0">
                <div class="flex min-w-0 items-center gap-2">
                  <Sparkles v-if="item.smart" class="size-4 shrink-0 text-amber-500" />
                  <span class="font-medium leading-tight truncate">{{ item.title }}</span>
                </div>
                <ArrowUpRight
                  class="size-4 shrink-0 text-muted-foreground transition group-hover/link:text-primary"
                />
              </div>
              <p class="text-xs text-muted-foreground leading-tight line-clamp-2">
                {{ item.preview || item.breadcrumb }}
              </p>
              <p class="text-[11px] text-muted-foreground/80 line-clamp-1">{{ item.breadcrumb }}</p>
            </Link>
          </li>
        </ul>
      </CardContent>
    </Card>

    <Card
      v-else-if="isOpen && !hasResults"
      size="sm"
      class="absolute right-0 z-20 mt-2 w-[min(32rem,90vw)] border-border/80 bg-popover shadow-lg ring-1 ring-black/5"
    >
      <CardContent class="p-4 text-sm text-muted-foreground">
        لا توجد نتائج، حاول كلمات مفتاحية أقل أو مختلفة.
      </CardContent>
    </Card>
  </div>
</template>
