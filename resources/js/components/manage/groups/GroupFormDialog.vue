<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import { fromSelectValue, NO_VALUE, toSelectValue, type GroupRow, type Taxonomy } from './types';

const props = defineProps<{
    cohortId: number;
    taxonomy: Taxonomy;
    /** `null` creates a new group. */
    group: GroupRow | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const isEditing = computed(() => props.group !== null);

const form = useForm<{ major: string; branch: string; is_active: boolean; cohort_ids: number[] }>({
    major: NO_VALUE,
    branch: NO_VALUE,
    is_active: true,
    cohort_ids: [],
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.major = toSelectValue(props.group?.major ?? null);
        form.branch = toSelectValue(props.group?.branch ?? null);
        form.is_active = props.group?.is_active ?? true;
        form.cohort_ids = props.group ? [props.cohortId, ...props.group.shared_with.map((cohort) => cohort.id)] : [props.cohortId];
    }
});

/** The intake being edited from always stays ticked — untick it elsewhere. */
function toggleCohort(id: number, checked: boolean): void {
    form.cohort_ids = checked ? [...new Set([...form.cohort_ids, id])] : form.cohort_ids.filter((current) => current !== id);
}

const isGeneral = computed(() => form.major === NO_VALUE);

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
        major: fromSelectValue(data.major),
        branch: fromSelectValue(data.branch),
    }));

    if (props.group) {
        payload.put(`/manage/groups/${props.group.id}`, options);
    } else {
        payload.post(`/manage/cohorts/${props.cohortId}/groups`, options);
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent :show-close-button="!form.processing">
            <DialogHeader>
                <DialogTitle>{{ isEditing ? 'تعديل القروب' : 'إضافة قروب' }}</DialogTitle>
                <DialogDescription> القروب يُعرَّف بتخصصه وفرعه، ولا يحتاج اسماً. اتركه بلا تخصص لإنشاء القروب العام للدفعة. </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="group-major">التخصص</Label>
                    <Select v-model="form.major">
                        <SelectTrigger id="group-major" class="w-full">
                            <SelectValue placeholder="اختر التخصص" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NO_VALUE">بدون تخصص — القروب العام</SelectItem>
                            <SelectItem v-for="major in taxonomy.majors" :key="major.value" :value="major.value">
                                {{ major.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.major" class="text-sm text-destructive-foreground">{{ form.errors.major }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="group-branch">الفرع</Label>
                    <Select v-model="form.branch">
                        <SelectTrigger id="group-branch" class="w-full">
                            <SelectValue placeholder="اختر الفرع" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NO_VALUE">كل الفروع</SelectItem>
                            <SelectItem v-for="branch in taxonomy.branches" :key="branch.value" :value="branch.value">
                                {{ branch.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.branch" class="text-sm text-destructive-foreground">{{ form.errors.branch }}</p>
                    <p v-else-if="isGeneral" class="text-xs text-muted-foreground">القروب العام يُعرض قبل قروبات التخصصات.</p>
                </div>

                <div class="space-y-2">
                    <Label>الدفعات</Label>
                    <div class="space-y-2 rounded-lg border border-border p-3">
                        <div v-for="option in taxonomy.cohorts" :key="option.value" class="flex items-center gap-2">
                            <Checkbox
                                :id="`group-cohort-${option.value}`"
                                :model-value="form.cohort_ids.includes(Number(option.value))"
                                @update:model-value="toggleCohort(Number(option.value), $event === true)"
                            />
                            <Label :for="`group-cohort-${option.value}`" class="font-normal">{{ option.label }}</Label>
                        </div>
                    </div>
                    <p v-if="form.errors.cohort_ids" class="text-sm text-destructive-foreground">{{ form.errors.cohort_ids }}</p>
                    <p v-else class="text-xs text-muted-foreground">القروب الواحد يخدم أكثر من دفعة عند الحاجة، بدل تكراره ومزامنته يدوياً.</p>
                </div>

                <div class="flex items-start justify-between gap-4 rounded-lg border border-border p-3">
                    <div class="space-y-1">
                        <Label for="group-active">معروض في الموقع</Label>
                        <p class="text-xs text-muted-foreground">أوقف العرض لإخفاء القروب مع الاحتفاظ بمشرفيه.</p>
                    </div>
                    <Switch id="group-active" :model-value="form.is_active" @update:model-value="form.is_active = $event" />
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
