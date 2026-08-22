<script setup lang="ts">
/**
 * The answer: ONE supervisor, chosen at random from the section the student
 * said they are in.
 *
 * The pick is made in the browser, on mount — never during render. The page is
 * served from a shared response cache, so a pick made server-side would be the
 * same person for every visitor until the cache expired, which is the pile-up
 * the rotation exists to prevent. Picking on mount also keeps SSR and the first
 * client render identical: both show the placeholder.
 */
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { pickAnother } from '@/lib/rotation';
import { MessageCircle, RefreshCw, Send } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { contactLabel, initialOf, type GroupSection, type GroupSupervisor } from './types';

const props = defineProps<{
    section: GroupSection;
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

// The same card answers every combination the student tries, so re-pick when
// the section under it changes rather than leaving the previous name up.
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
    <div class="rounded-2xl bg-gradient-to-b from-primary/10 to-card p-px shadow-sm ring-1 ring-primary/20">
        <div class="rounded-[calc(1rem-1px)] px-6 py-8 text-center sm:px-8">
            <Transition
                mode="out-in"
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="translate-y-1 opacity-0"
                leave-active-class="transition duration-100 ease-in"
                leave-to-class="opacity-0"
            >
                <div v-if="picked" :key="picked.id" class="flex flex-col items-center gap-4">
                    <span
                        class="flex size-16 items-center justify-center rounded-full bg-primary/15 text-2xl font-bold text-primary ring-4 ring-primary/5"
                        aria-hidden="true"
                    >
                        {{ initialOf(picked.name) }}
                    </span>

                    <div class="space-y-1">
                        <p class="text-2xl font-bold sm:text-3xl">{{ picked.name }}</p>
                        <p class="text-sm text-muted-foreground">
                            <bdi dir="ltr">{{ picked.contacts[0]?.handle }}</bdi>
                        </p>
                    </div>

                    <div class="flex w-full max-w-sm flex-col gap-2">
                        <Button
                            v-for="(contact, index) in picked.contacts"
                            :key="contact.kind"
                            as="a"
                            :href="contact.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            size="lg"
                            :variant="index === 0 ? 'default' : 'outline'"
                            class="w-full"
                            :aria-label="`مراسلة ${picked.name} على ${contactLabel(contact.kind)}`"
                        >
                            <component :is="iconFor(contact.kind)" />
                            راسل {{ picked.name }} على {{ contactLabel(contact.kind) }}
                        </Button>
                    </div>
                </div>

                <div v-else class="flex flex-col items-center gap-4">
                    <Skeleton class="size-16 rounded-full" />
                    <div class="flex flex-col items-center gap-2">
                        <Skeleton class="h-8 w-40" />
                        <Skeleton class="h-4 w-24" />
                    </div>
                    <Skeleton class="h-10 w-full max-w-sm" />
                </div>
            </Transition>

            <Button
                variant="ghost"
                size="sm"
                class="mt-3 text-muted-foreground"
                :disabled="!canReroll || !picked"
                :title="canReroll ? undefined : 'لا يوجد سوى مشرف واحد هنا'"
                @click="pick"
            >
                <RefreshCw />
                رشّح لي مشرفاً آخر
            </Button>
        </div>
    </div>
</template>
