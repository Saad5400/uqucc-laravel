<script setup lang="ts">
/**
 * One team: its name and aliases always visible, its members collapsed behind
 * the count — a chat can hold twenty teams and hundreds of memberships, so an
 * always-expanded roster is unreadable.
 */
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { arabicMembers } from '@/lib/arabic';
import { ChevronDown, EllipsisVertical, Pencil, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import TeamAliases from './TeamAliases.vue';
import TeamMemberRow from './TeamMemberRow.vue';
import { memberMatches, type Team, type TeamMember } from './types';

const props = defineProps<{
    team: Team;
}>();

defineEmits<{
    edit: [];
    delete: [];
    removeMember: [member: TeamMember];
}>();

const open = ref(false);

/** Above this, finding one person to remove needs a filter, not scrolling. */
const SEARCHABLE_FROM = 10;

/** Rendering hundreds of rows at once is slow and unreadable; reveal in pages. */
const PAGE_SIZE = 30;

const search = ref('');
const visibleCount = ref(PAGE_SIZE);

const filteredMembers = computed(() => props.team.members.filter((member) => memberMatches(member, search.value)));

const visibleMembers = computed(() => filteredMembers.value.slice(0, visibleCount.value));

const hiddenCount = computed(() => Math.max(filteredMembers.value.length - visibleCount.value, 0));

function showMore(): void {
    visibleCount.value += PAGE_SIZE;
}
</script>

<template>
    <li class="rounded-lg border border-border bg-card">
        <Collapsible v-model:open="open">
            <div class="flex flex-wrap items-start gap-x-2 gap-y-3 p-3">
                <div class="min-w-0 flex-1 space-y-2">
                    <h3 class="truncate font-medium">
                        <bdi>{{ team.name }}</bdi>
                    </h3>
                    <TeamAliases :team="team" />
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    <CollapsibleTrigger as-child>
                        <Button variant="ghost" size="sm" class="gap-1.5 text-muted-foreground" :aria-label="`أعضاء فريق ${team.name}`">
                            <span class="tabular-nums">{{ team.members.length ? arabicMembers(team.members.length) : 'لا أعضاء' }}</span>
                            <ChevronDown class="size-4 transition-transform" :class="{ 'rotate-180': open }" />
                        </Button>
                    </CollapsibleTrigger>

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="ghost" size="icon" :aria-label="`إجراءات فريق ${team.name}`">
                                <EllipsisVertical />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem @select="$emit('edit')">
                                <Pencil />
                                تعديل الاسم والتصنيف
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem variant="destructive" @select="$emit('delete')">
                                <Trash2 />
                                حذف الفريق
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <CollapsibleContent>
                <div class="border-t border-border">
                    <p v-if="!team.members.length" class="px-3 py-4 text-sm text-muted-foreground">
                        لا أعضاء بعد. للانضمام يرسل العضو داخل المجموعة «انضم <bdi>{{ team.name }}</bdi
                        >» ثم يرد أحد المشرفين على رسالته بـ«أضف».
                    </p>

                    <template v-else>
                        <div v-if="team.members.length >= SEARCHABLE_FROM" class="p-3 pb-0">
                            <Input
                                v-model="search"
                                type="search"
                                class="h-8 max-w-xs"
                                :placeholder="`ابحث في أعضاء ${team.name}…`"
                                :aria-label="`البحث في أعضاء ${team.name}`"
                            />
                        </div>

                        <p v-if="!filteredMembers.length" class="px-3 py-4 text-sm text-muted-foreground">لا عضو يطابق بحثك في هذا الفريق.</p>

                        <ul v-else class="divide-y divide-border">
                            <TeamMemberRow
                                v-for="member in visibleMembers"
                                :key="member.id"
                                :member="member"
                                :team-name="team.name"
                                @remove="$emit('removeMember', member)"
                            />
                        </ul>

                        <div v-if="hiddenCount > 0" class="border-t border-border p-2 text-center">
                            <Button variant="ghost" size="sm" @click="showMore">
                                عرض المزيد
                                <span dir="ltr" class="text-muted-foreground tabular-nums">({{ hiddenCount }})</span>
                            </Button>
                        </div>
                    </template>
                </div>
            </CollapsibleContent>
        </Collapsible>
    </li>
</template>
