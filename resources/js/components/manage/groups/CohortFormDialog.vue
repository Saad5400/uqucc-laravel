<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import RequirementsEditor from './RequirementsEditor.vue';
import type { CohortRow } from './types';

const props = defineProps<{
    cohort: CohortRow | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const isEditing = computed(() => props.cohort !== null);

const form = useForm<{
    name: string;
    description: string;
    note: string;
    requirements: string[];
    is_active: boolean;
    is_featured: boolean;
    shows_major_groups: boolean;
}>({
    name: '',
    description: '',
    note: '',
    requirements: [],
    is_active: true,
    is_featured: false,
    shows_major_groups: true,
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.name = props.cohort?.name ?? '';
        form.description = props.cohort?.description ?? '';
        form.note = props.cohort?.note ?? '';
        form.requirements = [...(props.cohort?.requirements ?? [])];
        form.is_active = props.cohort?.is_active ?? true;
        form.is_featured = props.cohort?.is_featured ?? false;
        form.shows_major_groups = props.cohort?.shows_major_groups ?? true;
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
        description: data.description.trim() === '' ? null : data.description.trim(),
        note: data.note.trim() === '' ? null : data.note.trim(),
        requirements: data.requirements.map((requirement) => requirement.trim()).filter((requirement) => requirement !== ''),
    }));

    if (props.cohort) {
        payload.put(`/manage/cohorts/${props.cohort.id}`, options);
    } else {
        payload.post('/manage/cohorts', options);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90dvh] overflow-y-auto" :show-close-button="!form.processing">
            <DialogHeader>
                <DialogTitle>{{ isEditing ? 'تعديل الدفعة' : 'إضافة دفعة' }}</DialogTitle>
                <DialogDescription>
                    {{
                        isEditing
                            ? 'عدّل اسم الدفعة وتعريفها وشروط الانضمام المشتركة بين قروباتها.'
                            : 'الدفعة تجمع قروبات التخصصات وتحمل شروط الانضمام المشتركة بينها.'
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="cohort-name">اسم الدفعة</Label>
                    <Input
                        id="cohort-name"
                        v-model="form.name"
                        type="text"
                        required
                        placeholder="مثال: دفعة ٤٨"
                        :aria-invalid="form.errors.name ? true : undefined"
                    />
                    <p v-if="form.errors.name" class="text-sm text-destructive-foreground">{{ form.errors.name }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="cohort-description">التعريف</Label>
                    <Textarea id="cohort-description" v-model="form.description" rows="3" placeholder="نبذة قصيرة تظهر أعلى قروبات الدفعة." />
                    <p v-if="form.errors.description" class="text-sm text-destructive-foreground">{{ form.errors.description }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="cohort-note">تنويه</Label>
                    <Textarea id="cohort-note" v-model="form.note" rows="2" placeholder="تحذير أو ملاحظة تظهر في إطار منفصل." />
                    <p v-if="form.errors.note" class="text-sm text-destructive-foreground">{{ form.errors.note }}</p>
                </div>

                <div class="space-y-2">
                    <Label>شروط الانضمام</Label>
                    <RequirementsEditor v-model="form.requirements" />
                    <p v-if="form.errors.requirements" class="text-sm text-destructive-foreground">{{ form.errors.requirements }}</p>
                </div>

                <div class="flex items-start justify-between gap-4 rounded-lg border border-border p-3">
                    <div class="space-y-1">
                        <Label for="cohort-active">معروضة في الموقع</Label>
                        <p class="text-xs text-muted-foreground">أوقف العرض لإخفاء الدفعة عن الزوار مع الاحتفاظ بقروباتها.</p>
                    </div>
                    <Switch id="cohort-active" :model-value="form.is_active" @update:model-value="form.is_active = $event" />
                </div>

                <div class="flex items-start justify-between gap-4 rounded-lg border border-border p-3">
                    <div class="space-y-1">
                        <Label for="cohort-featured">الدفعة المستجدة</Label>
                        <p class="text-xs text-muted-foreground">تظهر أولاً وتُفتح تلقائياً للزائر. عادةً الدفعة التي تستقبل المستجدين الآن.</p>
                    </div>
                    <Switch id="cohort-featured" :model-value="form.is_featured" @update:model-value="form.is_featured = $event" />
                </div>

                <div class="flex items-start justify-between gap-4 rounded-lg border border-border p-3">
                    <div class="space-y-1">
                        <Label for="cohort-major-groups">عرض قروبات التخصصات</Label>
                        <p class="text-xs text-muted-foreground">
                            أوقفه لتُخفى خطوة قروب التخصص من صفحة القروبات، فينضم الطالب للقروب العام وحده. القروبات ومشرفوها تبقى كما هي، وقائمة
                            التخصصات تبقى في النموذج.
                        </p>
                    </div>
                    <Switch id="cohort-major-groups" :model-value="form.shows_major_groups" @update:model-value="form.shows_major_groups = $event" />
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
