<script setup lang="ts">
/**
 * Everything a visitor needs once they have found their group: one suggested
 * supervisor per section, why it was suggested, and the full roster behind a
 * click. Shared by the general group and every specialized one so the two never
 * drift apart.
 */
import { Info } from 'lucide-vue-next';
import { computed } from 'vue';
import SupervisorRoster from './SupervisorRoster.vue';
import SupervisorSpotlight from './SupervisorSpotlight.vue';
import { sectionMatchesFilter, type SectionFilter, type StudentGroup } from './types';

const props = defineProps<{
    group: StudentGroup;
    filter: SectionFilter;
}>();

const visibleSections = computed(() => props.group.sections.filter((section) => sectionMatchesFilter(section.key, props.filter)));

/** A single mixed roster needs no «للشطرين» heading — there is nothing to tell it apart from. */
const hideSectionLabels = computed(() => visibleSections.value.length === 1 && visibleSections.value[0].key === 'both');

/** Whether the filter — rather than an empty group — is what hid everything. */
const hiddenByFilter = computed(() => visibleSections.value.length === 0 && props.group.sections.length > 0);
</script>

<template>
    <div v-if="visibleSections.length" class="space-y-4">
        <!-- A lone card has nothing to sit beside, so cap it rather than stretch it across the page -->
        <div class="grid gap-4" :class="visibleSections.length > 1 ? 'md:grid-cols-2' : 'sm:max-w-md'">
            <SupervisorSpotlight v-for="section in visibleSections" :key="section.key" :section="section" :hide-label="hideSectionLabels" />
        </div>

        <p class="flex items-start gap-2 text-xs text-muted-foreground">
            <Info class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            نرشّح لك مشرفاً عشوائياً في كل زيارة حتى تتوزّع الطلبات على المشرفين بالتساوي، ولك أن تختار غيره أو تراسل أي مشرف من القائمة.
        </p>

        <SupervisorRoster :sections="visibleSections" />
    </div>

    <p v-else-if="hiddenByFilter" class="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
        لا يوجد مشرفون لهذا الشطر في هذا القروب. غيّر الشطر لعرض البقية.
    </p>

    <p v-else class="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
        لا يوجد مشرف متاح لهذا القروب حالياً. جرّب زيارة الصفحة لاحقاً.
    </p>
</template>
