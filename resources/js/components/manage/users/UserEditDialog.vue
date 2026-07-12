<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useForm } from '@inertiajs/vue3';
import { ChevronDown, Loader2, Send } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import RolesField from './RolesField.vue';
import type { UserRow } from './types';

const props = defineProps<{
    user: UserRow | null;
    roleOptions: string[];
    canAssignRoles: boolean;
    authUserId: number;
}>();

const open = defineModel<boolean>('open', { default: false });

const isEditingSelf = computed(() => props.user !== null && props.user.id === props.authUserId);

/** Editing yourself: the admin role is locked so you cannot lock yourself out. */
const lockedRoles = computed<Record<string, string> | undefined>(() =>
    isEditingSelf.value ? { admin: 'لا يمكنك إزالة دور المدير من حسابك.' } : undefined,
);

const changingPassword = ref(false);
const editingAuthorProfile = ref(false);

const form = useForm<{
    name: string;
    email: string;
    verified: boolean;
    password: string;
    password_confirmation: string;
    username: string;
    url: string;
    avatar: string;
    roles: string[];
    requires_review: boolean;
}>({
    name: '',
    email: '',
    verified: true,
    password: '',
    password_confirmation: '',
    username: '',
    url: '',
    avatar: '',
    roles: [],
    requires_review: false,
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        changingPassword.value = false;
        editingAuthorProfile.value = false;
        form.name = props.user?.name ?? '';
        form.email = props.user?.email ?? '';
        form.verified = props.user?.verified ?? true;
        form.password = '';
        form.password_confirmation = '';
        form.username = props.user?.username ?? '';
        form.url = props.user?.url ?? '';
        form.avatar = props.user?.avatar ?? '';
        form.roles = [...(props.user?.roles ?? [])];
        form.requires_review = props.user?.requires_review ?? false;
    }
});

/** A collapsed password section means the password stays untouched. */
watch(changingPassword, (isChanging) => {
    if (!isChanging) {
        form.password = '';
        form.password_confirmation = '';
        form.clearErrors('password', 'password_confirmation');
    }
});

function emptyToNull(value: string): string | null {
    return value.trim() === '' ? null : value.trim();
}

function submit(): void {
    if (!props.user) {
        return;
    }

    form.transform((data) => ({
        name: data.name,
        email: data.email,
        verified: data.verified,
        username: emptyToNull(data.username),
        url: emptyToNull(data.url),
        avatar: emptyToNull(data.avatar),
        ...(changingPassword.value && data.password !== '' ? { password: data.password, password_confirmation: data.password_confirmation } : {}),
        ...(props.canAssignRoles ? { roles: data.roles, requires_review: data.requires_review } : {}),
    })).put(`/manage/users/${props.user.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent :show-close-button="!form.processing">
            <DialogHeader>
                <DialogTitle>تعديل المستخدم</DialogTitle>
                <DialogDescription>عدّل بيانات المستخدم وأدواره.</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="edit-user-name">الاسم</Label>
                    <Input id="edit-user-name" v-model="form.name" type="text" required :aria-invalid="form.errors.name ? true : undefined" />
                    <p v-if="form.errors.name" class="text-sm text-destructive-foreground">{{ form.errors.name }}</p>
                </div>
                <div class="space-y-2">
                    <Label for="edit-user-email">البريد الإلكتروني</Label>
                    <Input
                        id="edit-user-email"
                        v-model="form.email"
                        type="email"
                        dir="ltr"
                        class="text-start"
                        required
                        :aria-invalid="form.errors.email ? true : undefined"
                    />
                    <p v-if="form.errors.email" class="text-sm text-destructive-foreground">{{ form.errors.email }}</p>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <Label for="edit-user-verified">البريد موثّق</Label>
                        <p class="text-xs text-muted-foreground">حالة توثيق البريد الإلكتروني لهذا الحساب.</p>
                    </div>
                    <Switch id="edit-user-verified" v-model="form.verified" />
                </div>
                <p v-if="form.errors.verified" class="text-sm text-destructive-foreground">{{ form.errors.verified }}</p>
                <div v-if="canAssignRoles" class="space-y-2">
                    <Label>الأدوار</Label>
                    <RolesField v-model="form.roles" :role-options="roleOptions" :locked-roles="lockedRoles" />
                    <p v-if="form.errors.roles" class="text-sm text-destructive-foreground">{{ form.errors.roles }}</p>
                </div>
                <div v-if="canAssignRoles" class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <Label for="edit-user-requires-review">إلزام المراجعة</Label>
                        <p class="text-xs text-muted-foreground">
                            عند التفعيل، تُرسل تعديلات هذا المستخدم على المحتوى للمراجعة ولا تُنشر إلا بعد اعتمادها.
                        </p>
                    </div>
                    <Switch id="edit-user-requires-review" v-model="form.requires_review" />
                </div>
                <p v-if="form.errors.requires_review" class="text-sm text-destructive-foreground">{{ form.errors.requires_review }}</p>
                <div v-if="user?.telegram_id" class="flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm text-muted-foreground">
                    <Send class="size-4 shrink-0" />
                    مرتبط بتيليجرام:
                    <span dir="ltr">{{ user.telegram_id }}</span>
                </div>

                <Collapsible v-model:open="changingPassword">
                    <CollapsibleTrigger
                        class="group flex w-full items-center justify-between rounded-md border border-border px-3 py-2 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                    >
                        تغيير كلمة المرور
                        <ChevronDown class="size-4 transition-transform group-data-[state=open]:rotate-180" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="space-y-4 pt-4">
                        <div class="space-y-2">
                            <Label for="edit-user-password">كلمة المرور الجديدة</Label>
                            <Input
                                id="edit-user-password"
                                v-model="form.password"
                                type="password"
                                dir="ltr"
                                class="text-start"
                                autocomplete="new-password"
                                :aria-invalid="form.errors.password ? true : undefined"
                            />
                            <p v-if="form.errors.password" class="text-sm text-destructive-foreground">{{ form.errors.password }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="edit-user-password-confirmation">تأكيد كلمة المرور</Label>
                            <Input
                                id="edit-user-password-confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                dir="ltr"
                                class="text-start"
                                autocomplete="new-password"
                            />
                        </div>
                    </CollapsibleContent>
                </Collapsible>

                <Collapsible v-model:open="editingAuthorProfile">
                    <CollapsibleTrigger
                        class="group flex w-full items-center justify-between rounded-md border border-border px-3 py-2 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                    >
                        بيانات المؤلف
                        <ChevronDown class="size-4 transition-transform group-data-[state=open]:rotate-180" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="space-y-4 pt-4">
                        <p class="text-xs text-muted-foreground">تُستخدم هذه البيانات عند نسب الصفحات إلى المستخدم كمؤلف في الموقع العام.</p>
                        <div class="space-y-2">
                            <Label for="edit-user-username">اسم المستخدم</Label>
                            <Input
                                id="edit-user-username"
                                v-model="form.username"
                                type="text"
                                dir="ltr"
                                class="text-start"
                                :aria-invalid="form.errors.username ? true : undefined"
                            />
                            <p v-if="form.errors.username" class="text-sm text-destructive-foreground">{{ form.errors.username }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="edit-user-url">الرابط</Label>
                            <Input
                                id="edit-user-url"
                                v-model="form.url"
                                type="url"
                                dir="ltr"
                                class="text-start"
                                placeholder="https://example.com"
                                :aria-invalid="form.errors.url ? true : undefined"
                            />
                            <p v-if="form.errors.url" class="text-sm text-destructive-foreground">{{ form.errors.url }}</p>
                        </div>
                        <div class="space-y-2">
                            <Label for="edit-user-avatar">رابط الصورة الشخصية</Label>
                            <Input
                                id="edit-user-avatar"
                                v-model="form.avatar"
                                type="url"
                                dir="ltr"
                                class="text-start"
                                placeholder="https://example.com/avatar.png"
                                :aria-invalid="form.errors.avatar ? true : undefined"
                            />
                            <p v-if="form.errors.avatar" class="text-sm text-destructive-foreground">{{ form.errors.avatar }}</p>
                        </div>
                    </CollapsibleContent>
                </Collapsible>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="open = false">إلغاء</Button>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                        حفظ
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
