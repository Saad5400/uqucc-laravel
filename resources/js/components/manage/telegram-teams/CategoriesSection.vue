<script setup lang="ts">
/**
 * Categories only order the team list, and are edited once in a while — so the
 * whole section starts collapsed and stays out of the way of the daily work.
 */
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { arabicCategories, arabicTeams } from '@/lib/arabic';
import { router, useForm } from '@inertiajs/vue3';
import { ChevronDown, Loader2, Pencil, Plus, Tags, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { Team, TeamCategory } from './types';

const props = defineProps<{
    chatId: string;
    categories: TeamCategory[];
    teams: Team[];
}>();

const open = ref(false);

function teamCount(category: TeamCategory): number {
    return props.teams.filter((team) => team.category_id === category.id).length;
}

/* ------------------------------------------------------------------ */
/* Create                                                              */
/* ------------------------------------------------------------------ */

const createForm = useForm({ name: '' });

function createCategory(): void {
    createForm.post(`/manage/telegram-teams/${props.chatId}/categories`, {
        errorBag: 'category-create',
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => createForm.reset(),
    });
}

/* ------------------------------------------------------------------ */
/* Rename                                                              */
/* ------------------------------------------------------------------ */

const renaming = ref<TeamCategory | null>(null);
const renameValue = ref('');
const renameError = ref<string | null>(null);
const renameProcessing = ref(false);

function openRename(category: TeamCategory): void {
    renaming.value = category;
    renameValue.value = category.name;
    renameError.value = null;
}

function submitRename(): void {
    if (!renaming.value) {
        return;
    }

    renameProcessing.value = true;

    router.put(
        `/manage/telegram-teams/categories/${renaming.value.id}`,
        { name: renameValue.value },
        {
            errorBag: 'category-rename',
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                renaming.value = null;
            },
            onError: (errors) => {
                renameError.value = errors.name ?? 'تعذر الحفظ.';
            },
            onFinish: () => {
                renameProcessing.value = false;
            },
        },
    );
}

/* ------------------------------------------------------------------ */
/* Delete                                                              */
/* ------------------------------------------------------------------ */

const deleting = ref<TeamCategory | null>(null);
const deleteProcessing = ref(false);

const deleteConsequence = computed(() => {
    if (!deleting.value) {
        return '';
    }

    const affected = teamCount(deleting.value);

    return affected === 0
        ? `سيُحذف تصنيف «${deleting.value.name}» — لا فرق مرتبطة به.`
        : `سيُحذف تصنيف «${deleting.value.name}» وتنتقل ${arabicTeams(affected)} إلى «بدون تصنيف». لا تتأثر العضويات.`;
});

function deleteCategory(): void {
    if (!deleting.value) {
        return;
    }

    deleteProcessing.value = true;

    router.delete(`/manage/telegram-teams/categories/${deleting.value.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            deleting.value = null;
        },
        onFinish: () => {
            deleteProcessing.value = false;
        },
    });
}
</script>

<template>
    <div>
        <Collapsible v-model:open="open" class="rounded-lg border border-border bg-card">
            <CollapsibleTrigger
                class="flex w-full items-center gap-2 rounded-lg p-3 text-start transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <Tags class="size-4 shrink-0 text-muted-foreground" />
                <span class="font-medium">التصنيفات</span>
                <span class="text-xs text-muted-foreground tabular-nums">{{
                    categories.length ? arabicCategories(categories.length) : 'لا تصنيفات'
                }}</span>
                <ChevronDown class="ms-auto size-4 shrink-0 text-muted-foreground transition-transform" :class="{ 'rotate-180': open }" />
            </CollapsibleTrigger>

            <CollapsibleContent>
                <div class="space-y-3 border-t border-border p-3">
                    <p class="text-sm text-muted-foreground">
                        التصنيف اختياري ويُستخدم لترتيب قائمة الفرق فقط («الدفعة»، «التخصص»، «الفرع»…) — لا يغيّر شيئًا في تيليجرام.
                    </p>

                    <ul v-if="categories.length" class="flex flex-wrap gap-2">
                        <li v-for="category in categories" :key="category.id">
                            <span class="inline-flex items-center gap-1 rounded-md border border-border bg-muted/40 py-1 ps-3 pe-1 text-sm">
                                <bdi>{{ category.name }}</bdi>
                                <span class="text-xs text-muted-foreground tabular-nums">{{ arabicTeams(teamCount(category)) }}</span>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-6"
                                    :aria-label="`إعادة تسمية ${category.name}`"
                                    title="إعادة تسمية"
                                    @click="openRename(category)"
                                >
                                    <Pencil class="size-3.5" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-6 text-destructive-foreground"
                                    :aria-label="`حذف ${category.name}`"
                                    title="حذف التصنيف"
                                    @click="deleting = category"
                                >
                                    <Trash2 class="size-3.5" />
                                </Button>
                            </span>
                        </li>
                    </ul>

                    <form class="flex flex-wrap items-start gap-2" @submit.prevent="createCategory">
                        <div class="space-y-1">
                            <Input
                                v-model="createForm.name"
                                placeholder="تصنيف جديد…"
                                class="w-48"
                                aria-label="اسم التصنيف الجديد"
                                :aria-invalid="createForm.errors.name ? true : undefined"
                                required
                            />
                            <p v-if="createForm.errors.name" class="text-sm text-destructive-foreground">{{ createForm.errors.name }}</p>
                        </div>
                        <Button type="submit" variant="outline" :disabled="createForm.processing">
                            <Loader2 v-if="createForm.processing" class="size-4 animate-spin" />
                            <Plus v-else class="size-4" />
                            إضافة تصنيف
                        </Button>
                    </form>
                </div>
            </CollapsibleContent>
        </Collapsible>

        <Dialog :open="renaming !== null" @update:open="(value) => !value && (renaming = null)">
            <DialogContent :show-close-button="!renameProcessing">
                <DialogHeader>
                    <DialogTitle>إعادة تسمية التصنيف</DialogTitle>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submitRename">
                    <div class="space-y-2">
                        <Label for="category-rename">الاسم الجديد</Label>
                        <Input id="category-rename" v-model="renameValue" required :aria-invalid="renameError ? true : undefined" />
                        <p v-if="renameError" class="text-sm text-destructive-foreground">{{ renameError }}</p>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" :disabled="renameProcessing" @click="renaming = null">إلغاء</Button>
                        <Button type="submit" :disabled="renameProcessing">
                            <Loader2 v-if="renameProcessing" class="size-4 animate-spin" />
                            حفظ
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="deleting !== null"
            title="حذف التصنيف"
            destructive
            confirm-label="حذف"
            :processing="deleteProcessing"
            @confirm="deleteCategory"
            @update:open="(value) => !value && (deleting = null)"
        >
            {{ deleteConsequence }}
        </ConfirmDialog>
    </div>
</template>
