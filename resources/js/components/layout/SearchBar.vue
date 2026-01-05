<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import Fuse from 'fuse.js'
import { Search, ArrowUpRight, Sparkles } from 'lucide-vue-next'
import {
  ComboboxAnchor,
  ComboboxContent,
  ComboboxEmpty,
  ComboboxInput,
  ComboboxItem,
  ComboboxPortal,
  ComboboxRoot,
  ComboboxViewport,
} from 'reka-ui'
import type { AppPageProps, SearchItem } from '@/types'

const page = usePage<AppPageProps<{ searchData: SearchItem[] }>>()
const searchIndex = computed<SearchItem[]>(() => page.props.searchData ?? [])

const query = ref('')
const isOpen = ref(false)
const selectedItem = ref<SearchItem | null>(null)

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

const goTo = (item: SearchItem) => {
  isOpen.value = false
  query.value = item.title
  router.visit(item.slug)
}

const submitFirst = () => {
  const item = results.value[0]
  if (item) {
    goTo(item)
  }
}

watch(trimmedQuery, () => {
  if (!isOpen.value) {
    isOpen.value = true
  }
})

watch(selectedItem, (item) => {
  if (item) {
    goTo(item)
  }
})

const compareItems = (a: SearchItem | null, b: SearchItem | null) => a?.id === b?.id
</script>

<template>
  <ComboboxRoot
    v-model="selectedItem"
    v-model:open="isOpen"
    :by="compareItems"
    :ignore-filter="true"
    :highlight-on-hover="true"
    :open-on-click="true"
    :open-on-focus="true"
    :reset-search-term-on-blur="false"
    :reset-search-term-on-select="false"
  >
    <div class="relative w-full max-w-xl">
      <ComboboxAnchor as-child>
        <form
          role="search"
          class="group relative flex h-11 items-center gap-2 rounded-md border border-input bg-background pr-2 shadow-sm ring-0 transition focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/30"
          @submit.prevent="submitFirst"
        >
          <Search class="absolute right-3 size-4 text-muted-foreground" />
          <ComboboxInput
            v-model="query"
            autocomplete="off"
            aria-autocomplete="list"
            type="search"
            placeholder="ابحث بسرعة عن أي صفحة (ذكي)"
            class="h-full w-full border-0 bg-transparent pl-3 pr-9 text-sm outline-none focus-visible:ring-0 disabled:cursor-not-allowed disabled:opacity-50"
          />
          <span class="hidden text-xs text-muted-foreground sm:inline-flex items-center gap-1">
            <Sparkles class="size-3.5" />
            بحث ذكي
          </span>
        </form>
      </ComboboxAnchor>

      <ComboboxPortal>
        <ComboboxContent
          v-if="isOpen"
          position="popper"
          align="end"
          :side-offset="8"
          class="z-50 w-[min(32rem,90vw)] overflow-hidden rounded-md border border-border/80 bg-popover shadow-lg ring-1 ring-black/5 data-[state=closed]:animate-out data-[state=open]:animate-in data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95"
        >
          <ComboboxViewport class="max-h-80 overflow-y-auto p-2">
            <template v-if="hasResults">
              <ComboboxItem
                v-for="item in results"
                :key="item.id"
                :value="item"
                :text-value="item.title"
                class="group/link flex cursor-pointer flex-col gap-1 rounded-lg px-3 py-2 outline-none transition data-[highlighted]:bg-muted data-[state=checked]:bg-muted"
              >
                <div class="flex min-w-0 items-start justify-between gap-2">
                  <div class="flex min-w-0 items-center gap-2">
                    <Sparkles v-if="item.smart" class="size-4 shrink-0 text-amber-500" />
                    <span class="truncate font-medium leading-tight">{{ item.title }}</span>
                  </div>
                  <ArrowUpRight
                    class="size-4 shrink-0 text-muted-foreground transition group-hover/link:text-primary"
                  />
                </div>
                <p class="line-clamp-2 text-xs leading-tight text-muted-foreground">
                  {{ item.preview || item.breadcrumb }}
                </p>
                <p class="line-clamp-1 text-[11px] text-muted-foreground/80">{{ item.breadcrumb }}</p>
              </ComboboxItem>
            </template>
            <ComboboxEmpty v-else class="px-3 py-2 text-sm text-muted-foreground">
              لا توجد نتائج، حاول كلمات مفتاحية أقل أو مختلفة.
            </ComboboxEmpty>
          </ComboboxViewport>
        </ComboboxContent>
      </ComboboxPortal>
    </div>
  </ComboboxRoot>
</template>
