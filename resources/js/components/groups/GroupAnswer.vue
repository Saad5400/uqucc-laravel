<script setup lang="ts">
/**
 * One of the two groups a student joins, with a supervisor already picked for
 * them. Used for both the batch's global group and their programme group, so
 * the two can never drift apart in behaviour or in looks.
 *
 * The heading sits outside the card on purpose: a bordered card wrapping a
 * bordered hero is two frames doing one job, and at 390px those nested borders
 * eat real width.
 */
import { Badge } from '@/components/ui/badge';
import { arabicSupervisors } from '@/lib/arabic';
import type { JoinRequest, StudentDetails } from '@/lib/joinMessage';
import { computed } from 'vue';
import SupervisorHero from './SupervisorHero.vue';
import SupervisorRoster from './SupervisorRoster.vue';
import { sectionFor, type StudentGroup } from './types';

const props = defineProps<{
    title: string;
    subtitle?: string;
    group: StudentGroup | null;
    /** `null` until the student says which section they are in. */
    sectionKey: string | null;
    /** What they answered in step 1, for the message the buttons below write. */
    student: StudentDetails;
}>();

const section = computed(() => (props.group && props.sectionKey ? sectionFor(props.group, props.sectionKey) : null));

/**
 * «القروب العام» is a name already; a programme group is stored as bare
 * «علوم الحاسب», which needs the word to read as a list in a sentence.
 */
const groupLabel = computed(() => (props.group === null ? '' : props.group.is_general ? props.group.name : `قروب ${props.group.name}`));

/** Everything the message needs except who it is addressed to. */
const join = computed<Omit<JoinRequest, 'supervisor'>>(() => ({ ...props.student, group: groupLabel.value }));
</script>

<template>
    <section class="space-y-2">
        <header class="flex flex-wrap items-baseline gap-x-2 gap-y-1 px-1">
            <h3 class="m-0 font-bold">{{ title }}</h3>
            <p v-if="subtitle" class="m-0 min-w-0 flex-1 truncate text-xs text-muted-foreground">{{ subtitle }}</p>
            <Badge v-if="section" variant="secondary">
                <span class="tabular-nums">{{ arabicSupervisors(section.supervisors.length) }}</span>
            </Badge>
        </header>

        <template v-if="section">
            <SupervisorHero :section="section" :join="join" />
            <SupervisorRoster :section="section" :join="join" />
        </template>

        <p v-else class="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
            <slot name="empty">لا يوجد مشرف متاح هنا حالياً. جرّب زيارة الصفحة لاحقاً.</slot>
        </p>
    </section>
</template>
