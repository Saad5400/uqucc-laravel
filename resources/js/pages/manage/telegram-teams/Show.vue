<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDateTime, formatRelativeTime } from '@/lib/formatters';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowRight, Loader2, Pencil, Plus, Trash2, UsersRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: ManageLayout });

interface Category {
    id: number;
    name: string;
}

interface Member {
    id: number;
    telegram_user_id: string;
    name: string;
    username: string | null;
    consented_at: string | null;
    added_by_telegram_id: string;
}

interface Team {
    id: number;
    name: string;
    category_id: number | null;
    members: Member[];
}

const props = defineProps<{
    chat: { chat_id: string; title: string | null };
    categories: Category[];
    teams: Team[];
}>();

const chatLabel = computed(() => props.chat.title ?? 'مجموعة بدون اسم');

/** Sentinel for "no category" — reka-ui selects reserve the empty string. */
const NONE = 'none';

/* ------------------------------------------------------------------ */
/* Create forms                                                        */
/* ------------------------------------------------------------------ */

const categoryForm = useForm({ name: '' });

function createCategory(): void {
    categoryForm.post(`/manage/telegram-teams/${props.chat.chat_id}/categories`, {
        preserveScroll: true,
        onSuccess: () => categoryForm.reset(),
    });
}

const teamForm = useForm<{ name: string; category_id: string }>({ name: '', category_id: NONE });

function createTeam(): void {
    teamForm
        .transform((data) => ({
            name: data.name,
            category_id: data.category_id === NONE ? null : Number(data.category_id),
        }))
        .post(`/manage/telegram-teams/${props.chat.chat_id}/teams`, {
            preserveScroll: true,
            onSuccess: () => teamForm.reset(),
        });
}

/* ------------------------------------------------------------------ */
/* Rename (one dialog for teams and categories)                        */
/* ------------------------------------------------------------------ */

const renameTarget = ref<{ type: 'team' | 'category'; id: number } | null>(null);
const renameValue = ref('');
const renameProcessing = ref(false);
const renameError = ref<string | null>(null);

function openRename(type: 'team' | 'category', id: number, currentName: string): void {
    renameTarget.value = { type, id };
    renameValue.value = currentName;
    renameError.value = null;
}

function submitRename(): void {
    if (!renameTarget.value) {
        return;
    }

    const base = renameTarget.value.type === 'team' ? 'teams' : 'categories';

    renameProcessing.value = true;

    router.put(
        `/manage/telegram-teams/${base}/${renameTarget.value.id}`,
        { name: renameValue.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                renameTarget.value = null;
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
/* Move a team between categories (saves immediately)                  */
/* ------------------------------------------------------------------ */

const movingTeamId = ref<number | null>(null);

function changeTeamCategory(team: Team, value: string): void {
    movingTeamId.value = team.id;

    router.put(
        `/manage/telegram-teams/teams/${team.id}`,
        { category_id: value === NONE ? null : Number(value) },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                movingTeamId.value = null;
            },
        },
    );
}

/* ------------------------------------------------------------------ */
/* Deletes (each behind a ConfirmDialog)                               */
/* ------------------------------------------------------------------ */

type DeleteTarget = { type: 'team'; team: Team } | { type: 'category'; category: Category } | { type: 'member'; member: Member; team: Team };

const deleteTarget = ref<DeleteTarget | null>(null);
const deleteProcessing = ref(false);

function runDelete(): void {
    if (!deleteTarget.value) {
        return;
    }

    const url =
        deleteTarget.value.type === 'team'
            ? `/manage/telegram-teams/teams/${deleteTarget.value.team.id}`
            : deleteTarget.value.type === 'category'
              ? `/manage/telegram-teams/categories/${deleteTarget.value.category.id}`
              : `/manage/telegram-teams/members/${deleteTarget.value.member.id}`;

    deleteProcessing.value = true;

    router.delete(url, {
        preserveScroll: true,
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

        return {
            title: 'حذف الفريق',
            body: `سيُحذف فريق «${team.name}» وعضوياته (${team.members.length}) نهائيًا.`,
        };
    }

    if (deleteTarget.value.type === 'category') {
        return {
            title: 'حذف التصنيف',
            body: `سيُحذف تصنيف «${deleteTarget.value.category.name}» وتبقى فرقه بدون تصنيف — لا تتأثر العضويات.`,
        };
    }

    return {
        title: 'إزالة العضو',
        body: `سيُزال «${deleteTarget.value.member.name}» من فريق «${deleteTarget.value.team.name}». لإعادته يلزم طلب انضمام جديد من داخل تيليجرام.`,
    };
});

function categoryName(id: number | null): string | null {
    return props.categories.find((category) => category.id === id)?.name ?? null;
}
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
    />

    <p class="-mt-4 mb-6 text-xs text-muted-foreground">
        معرّف المجموعة: <span dir="ltr" class="tabular-nums">{{ chat.chat_id }}</span>
    </p>

    <div class="space-y-8">
        <!-- Categories -->
        <section class="space-y-3">
            <h2 class="text-base font-semibold">تصنيفات الفرق</h2>
            <p v-if="!categories.length" class="text-sm text-muted-foreground">
                لا توجد تصنيفات — التصنيف اختياري ويُستخدم فقط لترتيب قائمة الفرق («التخصص»، «الفرع»، «الدفعة»…).
            </p>
            <ul v-else class="flex flex-wrap gap-2">
                <li v-for="category in categories" :key="category.id">
                    <span class="inline-flex items-center gap-1 rounded-md border border-border bg-muted/40 py-1 ps-3 pe-1 text-sm">
                        {{ category.name }}
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-6"
                            :aria-label="`إعادة تسمية ${category.name}`"
                            @click="openRename('category', category.id, category.name)"
                        >
                            <Pencil class="size-3.5" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="size-6 text-destructive-foreground"
                            :aria-label="`حذف ${category.name}`"
                            @click="deleteTarget = { type: 'category', category }"
                        >
                            <Trash2 class="size-3.5" />
                        </Button>
                    </span>
                </li>
            </ul>
            <form class="flex flex-wrap items-start gap-2" @submit.prevent="createCategory">
                <div class="space-y-1">
                    <Input v-model="categoryForm.name" placeholder="تصنيف جديد…" class="w-48" aria-label="اسم التصنيف الجديد" required />
                    <p v-if="categoryForm.errors.name" class="text-sm text-destructive-foreground">{{ categoryForm.errors.name }}</p>
                </div>
                <Button type="submit" variant="outline" :disabled="categoryForm.processing">
                    <Loader2 v-if="categoryForm.processing" class="size-4 animate-spin" />
                    <Plus v-else class="size-4" />
                    إضافة
                </Button>
            </form>
        </section>

        <!-- Teams -->
        <section class="space-y-3">
            <h2 class="text-base font-semibold">الفرق</h2>

            <form class="flex flex-wrap items-start gap-2" @submit.prevent="createTeam">
                <div class="space-y-1">
                    <Input v-model="teamForm.name" placeholder="فريق جديد…" class="w-48" aria-label="اسم الفريق الجديد" required />
                    <p v-if="teamForm.errors.name" class="text-sm text-destructive-foreground">{{ teamForm.errors.name }}</p>
                </div>
                <Select v-model="teamForm.category_id">
                    <SelectTrigger class="w-40" aria-label="تصنيف الفريق الجديد">
                        <SelectValue placeholder="بدون تصنيف" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="NONE">بدون تصنيف</SelectItem>
                        <SelectItem v-for="category in categories" :key="category.id" :value="String(category.id)">
                            {{ category.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Button type="submit" :disabled="teamForm.processing">
                    <Loader2 v-if="teamForm.processing" class="size-4 animate-spin" />
                    <Plus v-else class="size-4" />
                    إنشاء فريق
                </Button>
            </form>

            <EmptyState
                v-if="!teams.length"
                :icon="UsersRound"
                title="لا توجد فرق في هذه المجموعة"
                description="أنشئ فريقًا من هنا، أو من داخل المجموعة بأمر «فريق جديد اسم الفريق»."
            />

            <ul v-else class="space-y-3">
                <li v-for="team in teams" :key="team.id" class="rounded-lg border border-border">
                    <div class="flex flex-wrap items-center gap-2 border-b border-border p-3">
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="font-medium">{{ team.name }}</span>
                                <Badge variant="secondary">{{ team.members.length }} عضو</Badge>
                                <Badge v-if="categoryName(team.category_id)" variant="outline">{{ categoryName(team.category_id) }}</Badge>
                            </div>
                        </div>
                        <Select
                            :model-value="team.category_id === null ? NONE : String(team.category_id)"
                            :disabled="movingTeamId === team.id"
                            @update:model-value="(value) => changeTeamCategory(team, String(value))"
                        >
                            <SelectTrigger class="w-36" :aria-label="`تصنيف ${team.name}`">
                                <SelectValue placeholder="بدون تصنيف" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NONE">بدون تصنيف</SelectItem>
                                <SelectItem v-for="category in categories" :key="category.id" :value="String(category.id)">
                                    {{ category.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Button variant="ghost" size="icon" :aria-label="`إعادة تسمية ${team.name}`" @click="openRename('team', team.id, team.name)">
                            <Pencil class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="text-destructive-foreground"
                            :aria-label="`حذف ${team.name}`"
                            @click="deleteTarget = { type: 'team', team }"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>

                    <p v-if="!team.members.length" class="p-3 text-sm text-muted-foreground">
                        لا أعضاء بعد — الانضمام من داخل المجموعة: انضم {{ team.name }}
                    </p>
                    <ul v-else>
                        <li
                            v-for="member in team.members"
                            :key="member.id"
                            class="flex items-center gap-3 border-b border-border p-3 text-sm last:border-b-0"
                        >
                            <div class="min-w-0 flex-1 space-y-0.5">
                                <div class="flex flex-wrap items-center gap-x-2">
                                    <span>{{ member.name }}</span>
                                    <span v-if="member.username" dir="ltr" class="text-xs text-muted-foreground">@{{ member.username }}</span>
                                </div>
                                <div class="flex flex-wrap items-center gap-x-3 text-xs text-muted-foreground">
                                    <span dir="ltr" class="tabular-nums">{{ member.telegram_user_id }}</span>
                                    <span v-if="member.consented_at" :title="formatDateTime(member.consented_at)">
                                        وافق {{ formatRelativeTime(member.consented_at) }}
                                    </span>
                                    <span
                                        >أضافه <span dir="ltr" class="tabular-nums">{{ member.added_by_telegram_id }}</span></span
                                    >
                                </div>
                            </div>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="text-destructive-foreground"
                                :aria-label="`إزالة ${member.name} من ${team.name}`"
                                @click="deleteTarget = { type: 'member', member, team }"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </li>
                    </ul>
                </li>
            </ul>
        </section>

        <!-- Rename dialog -->
        <Dialog :open="renameTarget !== null" @update:open="(value) => !value && (renameTarget = null)">
            <DialogContent :show-close-button="!renameProcessing">
                <DialogHeader>
                    <DialogTitle>{{ renameTarget?.type === 'team' ? 'إعادة تسمية الفريق' : 'إعادة تسمية التصنيف' }}</DialogTitle>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submitRename">
                    <div class="space-y-2">
                        <Label for="rename-input">الاسم الجديد</Label>
                        <Input id="rename-input" v-model="renameValue" required :aria-invalid="renameError ? true : undefined" />
                        <p v-if="renameError" class="text-sm text-destructive-foreground">{{ renameError }}</p>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" :disabled="renameProcessing" @click="renameTarget = null">إلغاء</Button>
                        <Button type="submit" :disabled="renameProcessing">
                            <Loader2 v-if="renameProcessing" class="size-4 animate-spin" />
                            حفظ
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="deleteTarget !== null"
            :title="deleteDialogCopy.title"
            destructive
            confirm-label="حذف"
            :processing="deleteProcessing"
            @confirm="runDelete"
            @update:open="(value) => !value && (deleteTarget = null)"
        >
            {{ deleteDialogCopy.body }}
        </ConfirmDialog>
    </div>
</template>
