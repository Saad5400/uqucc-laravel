<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { search } from '@/routes';
import { show as showPage } from '@/routes/pages';
import { router } from '@inertiajs/vue3';
import { CornerDownLeft, LoaderCircle, SearchX, Sparkles } from 'lucide-vue-next';
import { nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';

/**
 * Command-palette style AI search over the ingested corpus (Ctrl+K / Cmd+K).
 *
 * Feature-flag tradeoff: HandleInertiaRequests is owned by a concurrent
 * session, so the "search enabled" flag is not a shared Inertia prop yet.
 * The trigger always renders; the endpoint answers 503 with `enabled: false`
 * when the toggle is off and the palette shows a disabled state (cached for
 * the rest of the visit). Once HandleInertiaRequests is editable, share
 * AiSettings->isFeatureEnabled('search') as a prop and v-if the trigger.
 */

interface AiSearchResult {
    title: string;
    slug: string | null;
    heading: string | null;
    snippet: string;
    score: number;
}

type PaletteStatus = 'idle' | 'loading' | 'success' | 'empty' | 'disabled' | 'rate-limited' | 'error';

const isOpen = ref(false);
const query = ref('');
const results = ref<AiSearchResult[]>([]);
const activeIndex = ref(0);
const status = ref<PaletteStatus>('idle');
const searchFeatureDisabled = ref(false);
const inputElement = useTemplateRef<HTMLInputElement>('searchInput');

const DEBOUNCE_MS = 300;
const MIN_QUERY_LENGTH = 2;

let debounceTimer: ReturnType<typeof setTimeout> | undefined;
let abortController: AbortController | undefined;

const resetPalette = () => {
    clearTimeout(debounceTimer);
    abortController?.abort();
    query.value = '';
    results.value = [];
    activeIndex.value = 0;
    status.value = searchFeatureDisabled.value ? 'disabled' : 'idle';
};

const openPalette = () => {
    isOpen.value = true;
};

watch(isOpen, (open) => {
    if (open) {
        resetPalette();
        void nextTick(() => inputElement.value?.focus());
    }
});

watch(query, (value) => {
    clearTimeout(debounceTimer);

    if (searchFeatureDisabled.value) {
        return;
    }

    const trimmed = value.trim();

    if (trimmed.length < MIN_QUERY_LENGTH) {
        abortController?.abort();
        results.value = [];
        status.value = 'idle';
        return;
    }

    status.value = 'loading';
    debounceTimer = setTimeout(() => void runSearch(trimmed), DEBOUNCE_MS);
});

const runSearch = async (q: string) => {
    abortController?.abort();
    abortController = new AbortController();
    status.value = 'loading';

    try {
        const response = await fetch(search.url({ query: { q } }), {
            headers: { Accept: 'application/json' },
            signal: abortController.signal,
        });

        if (response.status === 503) {
            searchFeatureDisabled.value = true;
            results.value = [];
            status.value = 'disabled';
            return;
        }

        if (response.status === 429) {
            status.value = 'rate-limited';
            return;
        }

        if (!response.ok) {
            status.value = 'error';
            return;
        }

        const payload = (await response.json()) as { results: AiSearchResult[] };
        results.value = payload.results;
        activeIndex.value = 0;
        status.value = payload.results.length > 0 ? 'success' : 'empty';
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            status.value = 'error';
        }
    }
};

const visitResult = (result: AiSearchResult) => {
    if (!result.slug) {
        return;
    }

    isOpen.value = false;
    router.visit(showPage.url({ slug: result.slug.replace(/^\/+/, '') }) || '/');
};

const onInputKeydown = (event: KeyboardEvent) => {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, results.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const result = results.value[activeIndex.value];
        if (result) {
            visitResult(result);
        }
    }
};

const onGlobalKeydown = (event: KeyboardEvent) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        isOpen.value = !isOpen.value;
    }
};

onMounted(() => window.addEventListener('keydown', onGlobalKeydown));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onGlobalKeydown);
    clearTimeout(debounceTimer);
    abortController?.abort();
});
</script>

<template>
    <Button variant="outline" aria-label="Search" class="gap-2 border-primary/30 bg-primary/5 hover:bg-primary/10" @click="openPalette">
        <Sparkles class="size-4 text-amber-500" />
        <span class="hidden lg:inline">Search</span>
        <kbd
            class="pointer-events-none hidden items-center gap-1 rounded border border-border bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground lg:inline-flex"
        >
            Ctrl K
        </kbd>
    </Button>

    <Dialog v-model:open="isOpen">
        <DialogContent dir="rtl" class="top-24 translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-xl" :show-close-button="false">
            <DialogTitle class="sr-only">Search</DialogTitle>
            <DialogDescription class="sr-only">ابحث في محتوى الدليل بالذكاء الاصطناعي</DialogDescription>

            <div class="flex items-center gap-2 border-b border-border px-4">
                <Sparkles class="size-4 shrink-0 text-amber-500" />
                <input
                    ref="searchInput"
                    v-model="query"
                    type="text"
                    dir="rtl"
                    autocomplete="off"
                    placeholder="ابحث في الدليل..."
                    aria-label="نص البحث"
                    class="h-12 w-full border-0 bg-transparent text-base outline-none placeholder:text-muted-foreground md:text-sm"
                    @keydown="onInputKeydown"
                />
                <LoaderCircle v-if="status === 'loading'" class="size-4 shrink-0 animate-spin text-muted-foreground" />
            </div>

            <div class="max-h-80 overflow-y-auto p-2">
                <template v-if="status === 'success'">
                    <ul class="flex flex-col gap-1" role="listbox" aria-label="نتائج البحث">
                        <li
                            v-for="(result, index) in results"
                            :key="`${result.slug}-${index}`"
                            role="option"
                            :aria-selected="index === activeIndex"
                            class="group flex cursor-pointer flex-col gap-1 rounded-lg px-3 py-2 transition"
                            :class="index === activeIndex ? 'bg-muted' : 'hover:bg-muted/60'"
                            @click="visitResult(result)"
                            @mousemove="activeIndex = index"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate leading-tight font-medium">{{ result.title }}</span>
                                <CornerDownLeft
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                    :class="index === activeIndex ? 'opacity-100' : 'opacity-0'"
                                />
                            </div>
                            <p v-if="result.heading && result.heading !== result.title" class="text-[11px] text-muted-foreground/80">
                                {{ result.heading }}
                            </p>
                            <p class="line-clamp-2 text-xs text-muted-foreground">{{ result.snippet }}</p>
                        </li>
                    </ul>
                </template>

                <div v-else-if="status === 'loading'" class="flex flex-col gap-2 p-1" aria-hidden="true">
                    <div v-for="i in 3" :key="i" class="flex flex-col gap-2 rounded-lg px-3 py-2">
                        <div class="h-4 w-1/3 animate-pulse rounded bg-muted" />
                        <div class="h-3 w-full animate-pulse rounded bg-muted/70" />
                    </div>
                </div>

                <div v-else-if="status === 'empty'" class="flex flex-col items-center gap-2 px-3 py-8 text-center text-sm text-muted-foreground">
                    <SearchX class="size-5" />
                    لا توجد نتائج، جرّب كلمات مفتاحية مختلفة.
                </div>

                <div v-else-if="status === 'disabled'" class="px-3 py-8 text-center text-sm text-muted-foreground">البحث الذكي غير متاح حالياً.</div>

                <div v-else-if="status === 'rate-limited'" class="px-3 py-8 text-center text-sm text-muted-foreground">
                    محاولات كثيرة خلال وقت قصير، انتظر دقيقة ثم أعد المحاولة.
                </div>

                <div v-else-if="status === 'error'" class="px-3 py-8 text-center text-sm text-muted-foreground">
                    حدث خطأ أثناء البحث، أعد المحاولة.
                </div>

                <div v-else class="px-3 py-8 text-center text-sm text-muted-foreground">اكتب حرفين على الأقل للبحث في محتوى الدليل.</div>
            </div>
        </DialogContent>
    </Dialog>
</template>
