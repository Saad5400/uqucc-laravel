<script setup lang="ts">
/**
 * One of the groups a student has to join, presented as a step in an ordered
 * list rather than as one of two cards offered at once.
 *
 * Students read the previous layout — two identical cards, two identical teal
 * buttons, nothing saying which came first or that both were needed — as a
 * choice between two groups. So the sequence is made literal here: a number, a
 * line saying who this particular group is for, and only one step expanded at a
 * time, starting with the general group.
 */
import { Badge } from '@/components/ui/badge';
import { arabicDigits, arabicSupervisors } from '@/lib/arabic';
import type { JoinRequest } from '@/lib/joinMessage';
import { ChevronDown } from 'lucide-vue-next';
import { computed } from 'vue';
import SupervisorHero from './SupervisorHero.vue';
import SupervisorRoster from './SupervisorRoster.vue';
import type { GroupSection } from './types';

const props = defineProps<{
    /** Its place in the sequence — «١» is the general group everyone starts with. */
    order: number;
    title: string;
    /** One line on who this particular group is for: the distinction the old UI never drew. */
    purpose: string;
    subtitle?: string;
    /** The supervisors of this group who serve the student's section, or `null` if there are none. */
    section: GroupSection | null;
    /** The message this step drafts, short of the supervisor who is tapped. */
    join: Omit<JoinRequest, 'supervisor'>;
    open: boolean;
}>();

const emit = defineEmits<{
    toggle: [];
}>();

/** A group with nobody available has nothing to fold away; it explains itself instead. */
const isUnavailable = computed(() => props.section === null);

const showBody = computed(() => props.open || isUnavailable.value);
</script>

<template>
    <section
        class="overflow-hidden rounded-2xl border bg-card shadow-sm transition-colors"
        :class="showBody && !isUnavailable ? 'border-primary/40' : 'border-border'"
    >
        <button
            type="button"
            class="flex w-full items-center gap-3 p-4 text-start transition-colors"
            :class="isUnavailable ? 'cursor-default' : 'hover:bg-accent/40'"
            :disabled="isUnavailable"
            :aria-expanded="showBody"
            @click="emit('toggle')"
        >
            <!-- Deliberately not the solid primary disc the page's own steps use:
                 these are steps *inside* step 3, and two identical markers on one
                 screen would restart the confusion this layout exists to end. -->
            <span
                class="flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-bold tabular-nums"
                :class="open && !isUnavailable ? 'bg-primary/15 text-primary ring-2 ring-primary/30' : 'bg-muted text-muted-foreground'"
                aria-hidden="true"
            >
                {{ arabicDigits(order) }}
            </span>

            <span class="min-w-0 flex-1">
                <span class="flex flex-wrap items-baseline gap-x-2">
                    <span class="font-bold">{{ title }}</span>
                    <span v-if="subtitle" class="text-xs text-muted-foreground">{{ subtitle }}</span>
                </span>
                <span class="mt-0.5 block text-xs leading-relaxed text-muted-foreground">{{ purpose }}</span>
            </span>

            <Badge v-if="section" variant="secondary" class="shrink-0">
                <span class="tabular-nums">{{ arabicSupervisors(section.supervisors.length) }}</span>
            </Badge>

            <ChevronDown v-if="!isUnavailable" class="size-4 shrink-0 text-muted-foreground transition-transform" :class="open ? 'rotate-180' : ''" />
        </button>

        <div v-if="showBody" class="space-y-2 border-t border-border p-4">
            <template v-if="section">
                <SupervisorHero :section="section" :join="join" />
                <SupervisorRoster :section="section" :join="join" />
            </template>

            <p v-else class="px-2 py-4 text-center text-sm text-muted-foreground">
                <slot name="empty">لا يوجد مشرف متاح هنا حالياً. جرّب زيارة الصفحة لاحقاً.</slot>
            </p>
        </div>
    </section>
</template>
