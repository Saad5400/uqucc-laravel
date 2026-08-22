<script setup lang="ts">
/**
 * Hands the visitor ONE supervisor of a section to message.
 *
 * The pick is made in the browser, on mount — never during render. The page is
 * served from a shared response cache, so a pick made server-side would be the
 * same person for every visitor until the cache expired, which is the pile-up
 * the rotation exists to prevent. Picking on mount also keeps SSR and the first
 * client render identical: both show the placeholder.
 */
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { arabicSupervisors } from '@/lib/arabic';
import { pickAnother } from '@/lib/rotation';
import { MessageCircle, RefreshCw, Send } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { contactLabel, initialOf, type GroupSection, type GroupSupervisor } from './types';

const props = defineProps<{
    section: GroupSection;
    /** Hide the section heading when the group has only one, unlabelled roster. */
    hideLabel?: boolean;
}>();

const picked = ref<GroupSupervisor | null>(null);

const canReroll = computed(() => props.section.supervisors.length > 1);

/**
 * Pick a supervisor at random, never the one already on screen — re-rolling and
 * landing on the same name reads as a broken button.
 */
function pick(): void {
    picked.value = pickAnother(props.section.supervisors, picked.value?.id ?? null);
}

onMounted(pick);

// The same card is reused as the visitor switches intake or section filter.
watch(
    () => props.section,
    () => {
        picked.value = null;
        pick();
    },
);

const iconFor = (kind: string) => (kind === 'whatsapp' ? MessageCircle : Send);
</script>

<template>
    <div class="flex flex-col gap-4 rounded-xl border border-border bg-card p-5">
        <div v-if="!hideLabel" class="flex items-center justify-between gap-2">
            <h4 class="text-sm font-medium text-muted-foreground">{{ section.label }}</h4>
            <Badge variant="secondary">
                <span class="tabular-nums">{{ arabicSupervisors(section.supervisors.length) }}</span>
            </Badge>
        </div>

        <Transition
            mode="out-in"
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-y-1 opacity-0"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="opacity-0"
        >
            <div v-if="picked" :key="picked.id" class="flex items-center gap-3">
                <span
                    class="flex size-12 shrink-0 items-center justify-center rounded-full bg-primary/10 text-lg font-semibold text-primary"
                    aria-hidden="true"
                >
                    {{ initialOf(picked.name) }}
                </span>
                <div class="min-w-0">
                    <p class="truncate text-xl font-bold">{{ picked.name }}</p>
                    <p class="truncate text-sm text-muted-foreground">
                        <bdi dir="ltr">{{ picked.contacts[0]?.handle }}</bdi>
                    </p>
                </div>
            </div>
            <div v-else class="flex items-center gap-3">
                <Skeleton class="size-12 shrink-0 rounded-full" />
                <div class="w-full space-y-2">
                    <Skeleton class="h-5 w-28" />
                    <Skeleton class="h-4 w-20" />
                </div>
            </div>
        </Transition>

        <div class="flex flex-col gap-2">
            <template v-if="picked">
                <Button
                    v-for="(contact, index) in picked.contacts"
                    :key="contact.kind"
                    as="a"
                    :href="contact.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    :variant="index === 0 ? 'default' : 'outline'"
                    class="w-full"
                    :aria-label="`مراسلة ${picked.name} على ${contactLabel(contact.kind)}`"
                >
                    <component :is="iconFor(contact.kind)" />
                    راسل {{ picked.name }} على {{ contactLabel(contact.kind) }}
                </Button>
            </template>
            <Skeleton v-else class="h-9 w-full" />

            <Button
                variant="ghost"
                size="sm"
                class="w-full"
                :disabled="!canReroll || !picked"
                :title="canReroll ? undefined : 'لا يوجد سوى مشرف واحد هنا'"
                @click="pick"
            >
                <RefreshCw />
                اختر مشرفاً آخر
            </Button>
        </div>
    </div>
</template>
