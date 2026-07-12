<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import EmptyState from '@/components/manage/EmptyState.vue';
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import UserCreateDialog from '@/components/manage/users/UserCreateDialog.vue';
import UserEditDialog from '@/components/manage/users/UserEditDialog.vue';
import { formatPagesCount, roleLabels, type UserRow } from '@/components/manage/users/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Head, router, usePage } from '@inertiajs/vue3';
import { EllipsisVertical, FileText, MailWarning, Pencil, Plus, Send, ShieldCheck, Trash2, Users } from 'lucide-vue-next';
import { computed, ref } from 'vue';

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    users: UserRow[];
    roleOptions: string[];
}>();

const page = usePage();

const authUser = computed(() => (page.props.auth?.user ?? null) as unknown as { id: number; permissions: string[] } | null);
const canAssignRoles = computed(() => authUser.value?.permissions.includes('assign-roles') ?? false);

const search = ref('');

const filteredUsers = computed(() => {
    const query = search.value.trim();

    return query === '' ? props.users : props.users.filter((user) => user.name.includes(query) || user.email.includes(query));
});

function isSelf(user: UserRow): boolean {
    return user.id === authUser.value?.id;
}

function nameInitial(user: UserRow): string {
    return user.name.trim().charAt(0) || '؟';
}

const creating = ref(false);

const formDialogOpen = ref(false);
const editingUser = ref<UserRow | null>(null);

function openEdit(user: UserRow): void {
    editingUser.value = user;
    formDialogOpen.value = true;
}

const deletingUser = ref<UserRow | null>(null);
const confirmingDeletion = ref(false);
const deleting = ref(false);

function confirmDelete(user: UserRow): void {
    deletingUser.value = user;
    confirmingDeletion.value = true;
}

function deleteUser(): void {
    if (!deletingUser.value) {
        return;
    }

    deleting.value = true;

    router.delete(`/manage/users/${deletingUser.value.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            confirmingDeletion.value = false;
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}
</script>

<template>
    <Head title="المستخدمون" />
    <PageHeader title="المستخدمون" description="إدارة مستخدمي لوحة الإدارة وأدوارهم">
        <template #actions>
            <Button @click="creating = true">
                <Plus />
                إضافة مستخدم
            </Button>
        </template>
    </PageHeader>

    <div class="space-y-4">
        <div v-if="users.length" class="flex flex-wrap items-center gap-2">
            <Input v-model="search" type="search" placeholder="ابحث بالاسم أو البريد…" class="max-w-xs" aria-label="البحث في المستخدمين" />
        </div>

        <EmptyState
            v-if="!users.length"
            :icon="Users"
            title="لا يوجد مستخدمون بعد"
            description="المستخدمون هم من يملكون الدخول إلى لوحة الإدارة، ويُنسب إليهم تأليف الصفحات في الموقع العام."
        >
            <Button @click="creating = true">
                <Plus />
                إضافة مستخدم
            </Button>
        </EmptyState>

        <p v-else-if="!filteredUsers.length" class="py-8 text-center text-sm text-muted-foreground">لا نتائج مطابقة لبحثك.</p>

        <ul v-else class="overflow-hidden rounded-lg border border-border">
            <li v-for="user in filteredUsers" :key="user.id" class="flex items-center gap-3 border-b border-border p-3 last:border-b-0">
                <div
                    class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted font-medium text-muted-foreground"
                    aria-hidden="true"
                >
                    {{ nameInitial(user) }}
                </div>
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-medium">{{ user.name }}</span>
                        <Badge v-for="role in user.roles" :key="role" :variant="role === 'admin' ? 'default' : 'secondary'">
                            {{ roleLabels[role] ?? role }}
                        </Badge>
                        <Badge v-if="user.requires_review" variant="outline" class="gap-1">
                            <ShieldCheck class="size-3" />
                            مراجعة إلزامية
                        </Badge>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                        <span dir="ltr" class="truncate">{{ user.email }}</span>
                        <span v-if="!user.verified" class="inline-flex items-center gap-1">
                            <MailWarning class="size-3 shrink-0" />
                            غير موثّق
                        </span>
                        <span class="inline-flex items-center gap-1">
                            <FileText class="size-3 shrink-0" />
                            {{ formatPagesCount(user.pages_count) }}
                        </span>
                        <span v-if="user.telegram_id" class="inline-flex items-center gap-1">
                            <Send class="size-3 shrink-0" />
                            تيليجرام
                            <span dir="ltr">{{ user.telegram_id }}</span>
                        </span>
                    </div>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" size="icon" :aria-label="`إجراءات ${user.name}`">
                            <EllipsisVertical />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem @select="openEdit(user)">
                            <Pencil />
                            تعديل
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem variant="destructive" :disabled="isSelf(user)" @select="confirmDelete(user)">
                            <Trash2 />
                            <span class="flex flex-col items-start">
                                حذف
                                <span v-if="isSelf(user)" class="text-xs text-muted-foreground">لا يمكنك حذف حسابك</span>
                            </span>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </li>
        </ul>

        <UserCreateDialog v-model:open="creating" :role-options="roleOptions" :can-assign-roles="canAssignRoles" />

        <UserEditDialog
            v-model:open="formDialogOpen"
            :user="editingUser"
            :role-options="roleOptions"
            :can-assign-roles="canAssignRoles"
            :auth-user-id="authUser?.id ?? 0"
        />

        <ConfirmDialog
            v-model:open="confirmingDeletion"
            title="حذف المستخدم"
            destructive
            confirm-label="حذف"
            :processing="deleting"
            @confirm="deleteUser"
        >
            <template v-if="deletingUser">
                سيتم حذف المستخدم «{{ deletingUser.name }}» نهائياً.
                {{
                    deletingUser.pages_count > 0
                        ? `له ${formatPagesCount(deletingUser.pages_count)} منسوبة إليه — سيبقى المحتوى، وسيُزال الربط بالمؤلف.`
                        : 'لا توجد صفحات منسوبة إليه.'
                }}
            </template>
        </ConfirmDialog>
    </div>
</template>
