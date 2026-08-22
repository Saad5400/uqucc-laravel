<script setup lang="ts">
/**
 * The full supervisor list, collapsed by default.
 *
 * Frequency-driven prominence: almost everyone here needs one contact and the
 * spotlight above already gave them one. The roster is for the minority who
 * want to choose for themselves, so it costs one click instead of a screenful.
 */
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { arabicSupervisors } from '@/lib/arabic';
import { ChevronDown, MessageCircle, Send } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { contactLabel, initialOf, type GroupSection } from './types';

const props = defineProps<{
    sections: GroupSection[];
}>();

const open = ref(false);

const total = computed(() => props.sections.reduce((sum, section) => sum + section.supervisors.length, 0));

const iconFor = (kind: string) => (kind === 'whatsapp' ? MessageCircle : Send);
</script>

<template>
    <Collapsible v-model:open="open">
        <CollapsibleTrigger as-child>
            <Button variant="outline" size="sm" class="w-full sm:w-auto">
                <ChevronDown class="transition-transform" :class="open ? 'rotate-180' : ''" />
                {{ open ? 'إخفاء قائمة المشرفين' : 'عرض جميع المشرفين' }}
                <span class="text-muted-foreground tabular-nums">({{ arabicSupervisors(total) }})</span>
            </Button>
        </CollapsibleTrigger>
        <CollapsibleContent>
            <div class="mt-4 grid gap-4" :class="sections.length > 1 ? 'md:grid-cols-2' : ''">
                <div v-for="section in sections" :key="section.key" class="rounded-xl border border-border p-4">
                    <h4 class="mb-3 text-sm font-medium text-muted-foreground">{{ section.label }}</h4>
                    <ul class="m-0 list-none space-y-1 p-0">
                        <li v-for="supervisor in section.supervisors" :key="supervisor.id" class="flex items-center gap-3 rounded-lg p-2">
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-medium text-muted-foreground"
                                aria-hidden="true"
                            >
                                {{ initialOf(supervisor.name) }}
                            </span>
                            <span class="min-w-0 flex-1 truncate font-medium">{{ supervisor.name }}</span>
                            <span class="flex shrink-0 items-center gap-1">
                                <a
                                    v-for="contact in supervisor.contacts"
                                    :key="contact.kind"
                                    :href="contact.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                                    :aria-label="`مراسلة ${supervisor.name} على ${contactLabel(contact.kind)}`"
                                >
                                    <component :is="iconFor(contact.kind)" class="size-3.5 shrink-0" />
                                    <bdi dir="ltr" class="hidden sm:inline">{{ contact.handle }}</bdi>
                                </a>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </CollapsibleContent>
    </Collapsible>
</template>
