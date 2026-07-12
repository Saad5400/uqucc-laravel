<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { watch } from 'vue';
import RolesField from './RolesField.vue';

const props = defineProps<{
    roleOptions: string[];
    canAssignRoles: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm<{
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    roles: string[];
    requires_review: boolean;
}>({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    roles: [],
    requires_review: false,
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.reset();
    }
});

function submit(): void {
    form.transform((data) => ({
        name: data.name,
        email: data.email,
        password: data.password,
        password_confirmation: data.password_confirmation,
        ...(props.canAssignRoles ? { roles: data.roles, requires_review: data.requires_review } : {}),
    })).post('/manage/users', {
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
                <DialogTitle>إضافة مستخدم</DialogTitle>
                <DialogDescription>أدخل بيانات المستخدم الجديد. سيُعتبر بريده موثّقاً تلقائياً.</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="create-user-name">الاسم</Label>
                    <Input id="create-user-name" v-model="form.name" type="text" required :aria-invalid="form.errors.name ? true : undefined" />
                    <p v-if="form.errors.name" class="text-sm text-destructive-foreground">{{ form.errors.name }}</p>
                </div>
                <div class="space-y-2">
                    <Label for="create-user-email">البريد الإلكتروني</Label>
                    <Input
                        id="create-user-email"
                        v-model="form.email"
                        type="email"
                        dir="ltr"
                        class="text-start"
                        required
                        :aria-invalid="form.errors.email ? true : undefined"
                    />
                    <p v-if="form.errors.email" class="text-sm text-destructive-foreground">{{ form.errors.email }}</p>
                </div>
                <div class="space-y-2">
                    <Label for="create-user-password">كلمة المرور</Label>
                    <Input
                        id="create-user-password"
                        v-model="form.password"
                        type="password"
                        dir="ltr"
                        class="text-start"
                        required
                        autocomplete="new-password"
                        :aria-invalid="form.errors.password ? true : undefined"
                    />
                    <p v-if="form.errors.password" class="text-sm text-destructive-foreground">{{ form.errors.password }}</p>
                </div>
                <div class="space-y-2">
                    <Label for="create-user-password-confirmation">تأكيد كلمة المرور</Label>
                    <Input
                        id="create-user-password-confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        dir="ltr"
                        class="text-start"
                        required
                        autocomplete="new-password"
                    />
                </div>
                <div v-if="canAssignRoles" class="space-y-2">
                    <Label>الأدوار</Label>
                    <RolesField v-model="form.roles" :role-options="roleOptions" />
                    <p v-if="form.errors.roles" class="text-sm text-destructive-foreground">{{ form.errors.roles }}</p>
                </div>
                <div v-if="canAssignRoles" class="flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <Label for="create-user-requires-review">إلزام المراجعة</Label>
                        <p class="text-xs text-muted-foreground">تُرسل تعديلات هذا المستخدم على المحتوى للمراجعة قبل نشرها.</p>
                    </div>
                    <Switch id="create-user-requires-review" v-model="form.requires_review" />
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="open = false">إلغاء</Button>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                        إضافة
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
