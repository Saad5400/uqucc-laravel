<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import CategoriesSection from '@/components/manage/telegram-teams/CategoriesSection.vue';
import TeamCard from '@/components/manage/telegram-teams/TeamCard.vue';
import TeamFormDialog from '@/components/manage/telegram-teams/TeamFormDialog.vue';
import { sortTeams, teamMatches, type Team, type TeamCategory, type TeamMember } from '@/components/manage/telegram-teams/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { arabicMembers, arabicMemberships, arabicTeams } from '@/lib/arabic';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Plus, UsersRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    chat: { chat_id: string; title: string | null };
    categories: TeamCategory[];
    teams: Team[];
}>();

const chatLabel = computed(() => props.chat.title ?? 'مجموعة بدون اسم');

const totalMembers = computed(() => props.teams.reduce((total, team) => total + team.members.length, 0));

/* ------------------------------------------------------------------ */
/* Search + grouping (teams live under their category, never flat)     */
/* ------------------------------------------------------------------ */

/** Below this a search box is just noise — the whole list fits on screen. */
const SEARCHABLE_FROM = 6;

const search = ref('');

const isSearching = computed(() => search.value.trim() !== '');

interface TeamGroup {
    key: string;
    name: string;
    teams: Team[];
}

const showGroupHeadings = computed(() => props.categories.length > 0);

const groups = computed<TeamGroup[]>(() => {
    const matching = props.teams.filter((team) => teamMatches(team, search.value));
    const known = new Set(props.categories.map((category) => category.id));

    const grouped: TeamGroup[] = props.categories.map((category) => ({
        key: `category-${category.id}`,
        name: category.name,
        teams: sortTeams(matching.filter((team) => team.category_id === category.id)),
    }));

    const uncategorized = sortTeams(matching.filter((team) => team.category_id === null || !known.has(team.category_id)));

    if (uncategorized.length > 0) {
        grouped.push({ key: 'uncategorized', name: 'بدون تصنيف', teams: uncategorized });
    }

    /* Empty categories still show (they teach what the grouping is) — except while searching. */
    return grouped.filter((group) => group.teams.length > 0 || !isSearching.value);
});

const matchingTeamsCount = computed(() => groups.value.reduce((total, group) => total + group.teams.length, 0));

/* ------------------------------------------------------------------ */
/* Create / edit a team                                                */
/* ------------------------------------------------------------------ */

const teamDialogOpen = ref(false);
const editingTeam = ref<Team | null>(null);

function openCreateTeam(): void {
    editingTeam.value = null;
    teamDialogOpen.value = true;
}

function openEditTeam(team: Team): void {
    editingTeam.value = team;
    teamDialogOpen.value = true;
}

/* ------------------------------------------------------------------ */
/* Deletes (destructive — each behind a ConfirmDialog)                 */
/* ------------------------------------------------------------------ */

type DeleteTarget = { type: 'team'; team: Team } | { type: 'member'; member: TeamMember; team: Team };

const deleteTarget = ref<DeleteTarget | null>(null);
const deleteProcessing = ref(false);

function confirmRemoveMember(team: Team, member: TeamMember): void {
    deleteTarget.value = { type: 'member', member, team };
}

function runDelete(): void {
    if (!deleteTarget.value) {
        return;
    }

    const url =
        deleteTarget.value.type === 'team'
            ? `/manage/telegram-teams/teams/${deleteTarget.value.team.id}`
            : `/manage/telegram-teams/members/${deleteTarget.value.member.id}`;

    deleteProcessing.value = true;

    router.delete(url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            deleteTarget.value = null;
        },
        onFinish: () => {
            deleteProcessing.value = false;
        },
    });
}

const deleteDialogCopy = computed(() => {
    if (!deleteTarget.value) {
        return { title: '', body: '' };
    }

    if (deleteTarget.value.type === 'team') {
        const team = deleteTarget.value.team;
        const aliases = team.aliases.length > 0 ? ` وتُحذف اختصاراته (${team.aliases.map((alias) => alias.name).join('، ')})` : '';

        return {
            title: 'حذف الفريق',
            body: `سيُحذف فريق «${team.name}» نهائيًا مع ${arabicMembers(team.members.length)}${aliases}. لإعادة أي عضو يلزم طلب انضمام جديد من داخل تيليجرام.`,
        };
    }

    return {
        title: 'إزالة العضو',
        body: `سيُزال «${deleteTarget.value.member.name}» من فريق «${deleteTarget.value.team.name}». لإعادته يلزم طلب انضمام جديد من داخل تيليجرام.`,
    };
});
</script>

<template>
    <Head :title="`فرق ${chatLabel}`" />

    <div class="mb-2">
        <Link href="/manage/telegram-teams" class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
            <ArrowRight class="size-4" />
            كل المجموعات
        </Link>
    </div>

    <PageHeader
        :title="`فرق ${chatLabel}`"
        description="إضافة الأعضاء تتم من داخل تيليجرام فقط (رسالة «انضم» من العضو وموافقة مشرف بالرد «أضف») — من هنا الإدارة والإزالة."
    >
        <template #actions>
            <Button @click="openCreateTeam">
                <Plus />
                فريق جديد
            </Button>
        </template>
    </PageHeader>

    <div class="-mt-4 mb-6 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
        <span>
            معرّف المجموعة
            <bdi dir="ltr" class="tabular-nums">{{ chat.chat_id }}</bdi>
        </span>
        <span aria-hidden="true" class="text-muted-foreground/50">·</span>
        <span class="tabular-nums">{{ arabicTeams(teams.length) }}</span>
        <span aria-hidden="true" class="text-muted-foreground/50">·</span>
        <span class="tabular-nums">{{ arabicMemberships(totalMembers) }}</span>
    </div>

    <div class="space-y-6">
        <CategoriesSection :chat-id="chat.chat_id" :categories="categories" :teams="teams" />

        <section class="space-y-4">
            <div v-if="teams.length >= SEARCHABLE_FROM" class="flex flex-wrap items-center gap-2">
                <Input v-model="search" type="search" class="max-w-xs" placeholder="ابحث باسم الفريق أو اختصاره…" aria-label="البحث في الفرق" />
                <p v-if="isSearching" class="text-xs text-muted-foreground tabular-nums">{{ arabicTeams(matchingTeamsCount) }} مطابقة</p>
            </div>

            <EmptyState
                v-if="!teams.length"
                :icon="UsersRound"
                title="لا توجد فرق في هذه المجموعة"
                description="الفريق وحدة يُنادى بها مجموعة من الأعضاء دفعة واحدة. أنشئه من هنا، أو من داخل المجموعة برسالة «فريق جديد اسم الفريق»."
            >
                <Button @click="openCreateTeam">
                    <Plus />
                    فريق جديد
                </Button>
            </EmptyState>

            <p v-else-if="!matchingTeamsCount" class="py-8 text-center text-sm text-muted-foreground">
                لا فريق يطابق «{{ search.trim() }}» — جرّب اسمًا آخر أو اختصارًا.
            </p>

            <div v-else class="space-y-6">
                <div v-for="group in groups" :key="group.key" class="space-y-2">
                    <div v-if="showGroupHeadings" class="flex items-center gap-2 border-b border-border pb-1">
                        <h2 class="text-sm font-semibold">
                            <bdi>{{ group.name }}</bdi>
                        </h2>
                        <span class="text-xs text-muted-foreground tabular-nums">{{ arabicTeams(group.teams.length) }}</span>
                    </div>

                    <p v-if="!group.teams.length" class="pb-2 text-xs text-muted-foreground">لا فرق في هذا التصنيف بعد.</p>

                    <ul v-else class="space-y-2">
                        <TeamCard
                            v-for="team in group.teams"
                            :key="team.id"
                            :team="team"
                            @edit="openEditTeam(team)"
                            @delete="deleteTarget = { type: 'team', team }"
                            @remove-member="(member) => confirmRemoveMember(team, member)"
                        />
                    </ul>
                </div>
            </div>
        </section>
    </div>

    <TeamFormDialog v-model:open="teamDialogOpen" :chat-id="chat.chat_id" :categories="categories" :team="editingTeam" />

    <ConfirmDialog
        :open="deleteTarget !== null"
        :title="deleteDialogCopy.title"
        destructive
        :confirm-label="deleteTarget?.type === 'member' ? 'إزالة' : 'حذف'"
        :processing="deleteProcessing"
        @confirm="runDelete"
        @update:open="(value) => !value && (deleteTarget = null)"
    >
        {{ deleteDialogCopy.body }}
    </ConfirmDialog>
</template>
