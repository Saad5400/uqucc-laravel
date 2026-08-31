<script setup lang="ts">
/**
 * Everyone else in the same section, collapsed by default.
 *
 * Frequency-driven prominence: the hero above already handed this student one
 * contact, which is all almost anyone needs. The roster is for the minority who
 * want to choose for themselves, so it costs one click instead of a screenful.
 */
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { arabicSupervisors } from '@/lib/arabic';
import { buildJoinMessage, withPrefilledMessage, type JoinRequest } from '@/lib/joinMessage';
import { ChevronDown, MessageCircle, Send } from 'lucide-vue-next';
import { ref } from 'vue';
import { handOffTo } from './handoff';
import { contactLabel, initialOf, type GroupSection, type GroupSupervisor, type SupervisorContact } from './types';

const props = defineProps<{
    section: GroupSection;
    /** The join request, completed with whichever supervisor is tapped here. */
    join: Omit<JoinRequest, 'supervisor'>;
}>();

/** Same draft the hero writes — picking from the list must not cost the message. */
const messageFor = (supervisor: GroupSupervisor) => buildJoinMessage({ ...props.join, supervisor: supervisor.name });

const open = ref(false);

const iconFor = (kind: string) => (kind === 'whatsapp' ? MessageCircle : Send);

const hrefFor = (contact: SupervisorContact, supervisor: GroupSupervisor) => withPrefilledMessage(contact.url, messageFor(supervisor));
</script>

<template>
    <Collapsible v-model:open="open">
        <div class="text-center">
            <CollapsibleTrigger as-child>
                <Button variant="link" size="sm" class="text-muted-foreground">
                    <ChevronDown class="transition-transform" :class="open ? 'rotate-180' : ''" />
                    {{ open ? 'إخفاء القائمة' : 'أو اختر بنفسك من' }}
                    <span class="tabular-nums">{{ arabicSupervisors(section.supervisors.length) }}</span>
                </Button>
            </CollapsibleTrigger>
        </div>

        <CollapsibleContent>
            <ul class="mt-3 grid list-none gap-1 p-0">
                <li v-for="supervisor in section.supervisors" :key="supervisor.id">
                    <div class="flex items-center gap-3 rounded-xl border border-border p-3">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-medium text-muted-foreground"
                            aria-hidden="true"
                        >
                            {{ initialOf(supervisor.name) }}
                        </span>
                        <span class="min-w-0 flex-1 truncate font-medium">{{ supervisor.name }}</span>
                        <span class="flex shrink-0 items-center gap-1">
                            <a
                                v-for="contact in supervisor.contacts"
                                :key="contact.kind"
                                :href="hrefFor(contact, supervisor)"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                                :aria-label="`مراسلة ${supervisor.name} على ${contactLabel(contact.kind)}`"
                                @click="handOffTo(contact, messageFor(supervisor))"
                            >
                                <component :is="iconFor(contact.kind)" class="size-4 shrink-0" />
                                <bdi dir="ltr" class="hidden md:inline">{{ contact.handle }}</bdi>
                            </a>
                        </span>
                    </div>
                </li>
            </ul>
        </CollapsibleContent>
    </Collapsible>
</template>
