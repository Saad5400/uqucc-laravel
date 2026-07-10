<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import EmptyState from '@/components/manage/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useSortableList } from '@/composables/useSortableList';
import { router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, GripVertical, UserPlus, Users, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import type { AuthorRow, PageWorkspace, UserOption } from './types';

const props = defineProps<{
    page: PageWorkspace;
    authors: AuthorRow[];
    users: UserOption[];
}>();

const syncError = ref<string | null>(null);

function syncAuthors(userIds: number[]): Promise<void> {
    return new Promise<void>((resolve, reject) => {
        router.put(
            `/manage/pages/${props.page.id}/authors`,
            { user_ids: userIds },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    syncError.value = null;
                    resolve();
                },
                onError: () => {
                    syncError.value = 'تعذر حفظ قائمة المؤلفين.';
                    reject(new Error('authors sync failed'));
                },
            },
        );
    });
}

const { items, draggingId, startDrag, dragOver, endDrag, moveUp, moveDown } = useSortableList(() => props.authors, syncAuthors);

/* Attach dialog */

const attachDialogOpen = ref(false);
const attachSearch = ref('');
const attachingId = ref<number | null>(null);

watch(attachDialogOpen, (isOpen) => {
    if (isOpen) {
        attachSearch.value = '';
    }
});

const attachableUsers = computed(() => {
    const attachedIds = new Set(props.authors.map((author) => author.id));
    const query = attachSearch.value.trim();

    return props.users.filter((user) => !attachedIds.has(user.id) && (query === '' || user.name.includes(query)));
});

async function attach(user: UserOption): Promise<void> {
    attachingId.value = user.id;

    try {
        await syncAuthors([...props.authors.map((author) => author.id), user.id]);
        attachDialogOpen.value = false;
    } catch {
        /* error surfaced via syncError */
    } finally {
        attachingId.value = null;
    }
}

/* Detach */

const detachingAuthor = ref<AuthorRow | null>(null);
const confirmingDetach = ref(false);
const detaching = ref(false);

function confirmDetach(author: AuthorRow): void {
    detachingAuthor.value = author;
    confirmingDetach.value = true;
}

async function detach(): Promise<void> {
    if (!detachingAuthor.value) {
        return;
    }

    detaching.value = true;

    try {
        await syncAuthors(props.authors.filter((author) => author.id !== detachingAuthor.value?.id).map((author) => author.id));
        confirmingDetach.value = false;
    } catch {
        /* error surfaced via syncError */
    } finally {
        detaching.value = false;
    }
}
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <EmptyState
            v-if="!authors.length"
            :icon="Users"
            title="لا يوجد مؤلفون"
            description="المؤلفون يظهرون أسفل الصفحة في الموقع العام بترتيبهم هنا."
        >
            <Button @click="attachDialogOpen = true">
                <UserPlus />
                إضافة مؤلف
            </Button>
        </EmptyState>

        <template v-else>
            <div class="flex justify-end">
                <Button variant="outline" size="sm" @click="attachDialogOpen = true">
                    <UserPlus />
                    إضافة مؤلف
                </Button>
            </div>

            <p v-if="syncError" class="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive-foreground">
                {{ syncError }}
            </p>

            <ul class="overflow-hidden rounded-lg border border-border">
                <li
                    v-for="author in items"
                    :key="author.id"
                    class="flex items-center gap-2 border-b border-border p-3 transition-opacity last:border-b-0"
                    :class="{ 'opacity-50': draggingId === author.id }"
                    draggable="true"
                    @dragstart="startDrag(author, $event)"
                    @dragover="dragOver(author, $event)"
                    @dragend="endDrag($event)"
                    @drop.prevent
                >
                    <GripVertical class="size-4 shrink-0 cursor-grab text-muted-foreground/60" aria-hidden="true" />
                    <span class="min-w-0 flex-1 truncate font-medium">{{ author.name }}</span>
                    <Button variant="ghost" size="icon-sm" :aria-label="`نقل ${author.name} لأعلى`" @click="moveUp(author)">
                        <ArrowUp />
                    </Button>
                    <Button variant="ghost" size="icon-sm" :aria-label="`نقل ${author.name} لأسفل`" @click="moveDown(author)">
                        <ArrowDown />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        class="text-destructive-foreground"
                        :aria-label="`إزالة ${author.name}`"
                        @click="confirmDetach(author)"
                    >
                        <X />
                    </Button>
                </li>
            </ul>

            <p class="text-xs text-muted-foreground">المؤلفون يظهرون أسفل الصفحة في الموقع العام بهذا الترتيب.</p>
        </template>

        <Dialog v-model:open="attachDialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>إضافة مؤلف</DialogTitle>
                    <DialogDescription>اختر مستخدماً لإضافته إلى مؤلفي هذه الصفحة.</DialogDescription>
                </DialogHeader>
                <div class="space-y-2">
                    <Input v-model="attachSearch" type="search" placeholder="ابحث بالاسم…" aria-label="البحث في المستخدمين" />
                    <ul class="max-h-56 overflow-y-auto rounded-md border border-input">
                        <li v-for="user in attachableUsers" :key="user.id">
                            <button
                                type="button"
                                class="w-full px-3 py-2 text-start text-sm transition-colors hover:bg-accent hover:text-accent-foreground disabled:opacity-50"
                                :disabled="attachingId !== null"
                                @click="attach(user)"
                            >
                                {{ user.name }}
                            </button>
                        </li>
                        <li v-if="!attachableUsers.length" class="px-3 py-4 text-center text-sm text-muted-foreground">
                            لا يوجد مستخدمون متاحون للإضافة.
                        </li>
                    </ul>
                </div>
            </DialogContent>
        </Dialog>

        <ConfirmDialog v-model:open="confirmingDetach" title="إزالة المؤلف" confirm-label="إزالة" :processing="detaching" @confirm="detach">
            <template v-if="detachingAuthor"> سيتم إزالة «{{ detachingAuthor.name }}» من مؤلفي هذه الصفحة فقط — لن يُحذف حسابه. </template>
        </ConfirmDialog>
    </div>
</template>
