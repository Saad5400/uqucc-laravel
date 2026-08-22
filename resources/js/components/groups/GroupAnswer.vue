<script setup lang="ts">
/**
 * One of the two groups a student joins, with a supervisor already picked for
 * them. Used for both the batch's global group and their programme group, so
 * the two can never drift apart in behaviour or in looks.
 */
import { Badge } from '@/components/ui/badge';
import { arabicSupervisors } from '@/lib/arabic';
import { Info } from 'lucide-vue-next';
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
}>();

const section = computed(() => (props.group && props.sectionKey ? sectionFor(props.group, props.sectionKey) : null));
</script>

<template>
    <section class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4 shadow-sm sm:p-5">
        <header class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <h3 class="m-0 font-bold">{{ title }}</h3>
                <p v-if="subtitle" class="m-0 truncate text-xs text-muted-foreground">{{ subtitle }}</p>
            </div>
            <Badge v-if="section" variant="secondary">
                <span class="tabular-nums">{{ arabicSupervisors(section.supervisors.length) }}</span>
            </Badge>
        </header>

        <template v-if="section">
            <SupervisorHero :section="section" />

            <p class="flex items-start gap-2 text-xs text-muted-foreground">
                <Info class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                نرشّح مشرفاً عشوائياً في كل زيارة حتى تتوزّع الطلبات بالتساوي.
            </p>

            <SupervisorRoster :section="section" />
        </template>

        <div v-else class="flex items-center justify-center rounded-xl border border-dashed border-border px-4 py-10 text-center">
            <p class="text-sm text-muted-foreground">
                <slot name="empty">لا يوجد مشرف متاح هنا حالياً. جرّب زيارة الصفحة لاحقاً.</slot>
            </p>
        </div>
    </section>
</template>
