<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import type { SupervisorRow, Taxonomy } from './types';

const props = defineProps<{
    groupId: number;
    taxonomy: Taxonomy;
    /** `null` creates a new supervisor. */
    supervisor: SupervisorRow | null;
    /** Section pre-selected when creating, so the dialog opens in the list it was opened from. */
    defaultSection: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const isEditing = computed(() => props.supervisor !== null);

const form = useForm<{
    name: string;
    telegram_username: string;
    whatsapp_number: string;
    section: string;
    is_available: boolean;
}>({
    name: '',
    telegram_username: '',
    whatsapp_number: '',
    section: '',
    is_available: true,
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.name = props.supervisor?.name ?? '';
        form.telegram_username = props.supervisor?.telegram_username ?? '';
        form.whatsapp_number = props.supervisor?.whatsapp_number ?? '';
        form.section = props.supervisor?.section ?? props.defaultSection;
        form.is_available = props.supervisor?.is_available ?? true;
    }
});

function submit(): void {
    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    const payload = form.transform((data) => ({
        ...data,
        telegram_username: data.telegram_username.trim() === '' ? null : data.telegram_username.trim(),
        whatsapp_number: data.whatsapp_number.trim() === '' ? null : data.whatsapp_number.trim(),
    }));

    if (props.supervisor) {
        payload.put(`/manage/supervisors/${props.supervisor.id}`, options);
    } else {
        payload.post(`/manage/groups/${props.groupId}/supervisors`, options);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90dvh] overflow-y-auto" :show-close-button="!form.processing">
            <DialogHeader>
                <DialogTitle>{{ isEditing ? 'تعديل المشرف' : 'إضافة مشرف' }}</DialogTitle>
                <DialogDescription>
                    {{
                        isEditing
                            ? 'عدّل بيانات المشرف أو انقله إلى شطر آخر.'
                            : 'المشرف هو من يراسله الطالب لينضم إلى القروب، وتُوزَّع الطلبات على مشرفي الشطر بالتساوي.'
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="supervisor-name">الاسم</Label>
                    <Input
                        id="supervisor-name"
                        v-model="form.name"
                        type="text"
                        required
                        placeholder="الاسم كما يعرفه الطلاب"
                        :aria-invalid="form.errors.name ? true : undefined"
                    />
                    <p v-if="form.errors.name" class="text-sm text-destructive-foreground">{{ form.errors.name }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="supervisor-username">معرّف تيليجرام</Label>
                    <Input
                        id="supervisor-username"
                        v-model="form.telegram_username"
                        type="text"
                        dir="ltr"
                        class="text-start"
                        placeholder="@username"
                        :aria-invalid="form.errors.telegram_username ? true : undefined"
                    />
                    <p v-if="form.errors.telegram_username" class="text-sm text-destructive-foreground">{{ form.errors.telegram_username }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="supervisor-whatsapp">رقم الواتساب</Label>
                    <Input
                        id="supervisor-whatsapp"
                        v-model="form.whatsapp_number"
                        type="tel"
                        dir="ltr"
                        class="text-start"
                        placeholder="05XXXXXXXX"
                        :aria-invalid="form.errors.whatsapp_number ? true : undefined"
                    />
                    <p v-if="form.errors.whatsapp_number" class="text-sm text-destructive-foreground">{{ form.errors.whatsapp_number }}</p>
                    <p v-else class="text-xs text-muted-foreground">
                        يكفي أحد الحقلين. الصق المعرّف أو الرابط أو الرقم كما هو؛ نحفظه بالصيغة الصحيحة.
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="supervisor-section">الشطر</Label>
                    <Select v-model="form.section">
                        <SelectTrigger id="supervisor-section" class="w-full">
                            <SelectValue placeholder="اختر الشطر" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="section in taxonomy.sections" :key="section.value" :value="section.value">
                                {{ section.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.section" class="text-sm text-destructive-foreground">{{ form.errors.section }}</p>
                    <p v-else class="text-xs text-muted-foreground">«للشطرين» لقائمة واحدة غير مقسّمة، مثل قوائم المستجدين العامة.</p>
                </div>

                <div class="flex items-start justify-between gap-4 rounded-lg border border-border p-3">
                    <div class="space-y-1">
                        <Label for="supervisor-available">متاح لاستقبال الطلبات</Label>
                        <p class="text-xs text-muted-foreground">أوقفه مؤقتاً ليخرج من التوزيع دون حذف بياناته.</p>
                    </div>
                    <Switch id="supervisor-available" :model-value="form.is_available" @update:model-value="form.is_available = $event" />
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="open = false">إلغاء</Button>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                        {{ isEditing ? 'حفظ' : 'إضافة' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
