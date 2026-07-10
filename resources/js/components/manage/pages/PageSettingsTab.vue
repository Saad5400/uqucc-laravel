<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Icon } from '@iconify/vue';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed } from 'vue';
import ParentPicker from './ParentPicker.vue';
import type { PageWorkspace, ParentOption } from './types';

const props = defineProps<{
    page: PageWorkspace;
    parentOptions: ParentOption[];
    /** The page itself + its descendants — invalid parents, excluded from the picker. */
    descendantIds: number[];
}>();

const form = useForm<{
    slug: string;
    parent_id: number | null;
    icon: string;
    hidden: boolean;
    hidden_from_bot: boolean;
    smart_search: boolean;
    requires_prefix: boolean;
}>({
    slug: props.page.slug,
    parent_id: props.page.parent_id,
    icon: props.page.icon ?? '',
    hidden: props.page.hidden,
    hidden_from_bot: props.page.hidden_from_bot,
    smart_search: props.page.smart_search,
    requires_prefix: props.page.requires_prefix,
});

const isDirty = computed(() => form.isDirty);

defineExpose({ isDirty });

const excludedParentIds = computed(() => [props.page.id, ...props.descendantIds]);

const urlPreview = computed(() => `${window.location.origin}${form.slug}`);

const toggles: { field: 'hidden' | 'hidden_from_bot' | 'smart_search' | 'requires_prefix'; label: string; description: string }[] = [
    { field: 'hidden', label: 'مخفي', description: 'إخفاء الصفحة من الموقع الإلكتروني.' },
    { field: 'hidden_from_bot', label: 'مخفي من البوت', description: 'إخفاء الصفحة من بوت تيليجرام.' },
    { field: 'smart_search', label: 'البحث الذكي', description: 'عند التفعيل، يمكن العثور على الصفحة بالبحث في أي جزء من العنوان.' },
    {
        field: 'requires_prefix',
        label: 'يتطلب كلمة «دليل»',
        description: 'عند التفعيل، يجب على المستخدم كتابة «دليل» قبل اسم الصفحة للعثور عليها في البوت.',
    },
];

function submit(): void {
    form.transform((data) => ({ ...data, icon: data.icon.trim() === '' ? null : data.icon.trim() })).put(`/manage/pages/${props.page.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <Card class="max-w-3xl">
        <CardHeader>
            <CardTitle>إعدادات الصفحة</CardTitle>
        </CardHeader>
        <CardContent>
            <form class="space-y-6" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="page-slug">الرابط</Label>
                    <Input
                        id="page-slug"
                        v-model="form.slug"
                        type="text"
                        dir="ltr"
                        class="text-start font-mono"
                        :aria-invalid="form.errors.slug ? true : undefined"
                    />
                    <p class="text-xs text-muted-foreground">
                        العنوان الكامل: <code dir="ltr" class="rounded bg-muted px-1">{{ urlPreview }}</code>
                    </p>
                    <p v-if="form.errors.slug" class="text-sm text-destructive-foreground">{{ form.errors.slug }}</p>
                </div>

                <div class="space-y-2">
                    <Label>الصفحة الأب</Label>
                    <ParentPicker v-model="form.parent_id" :options="parentOptions" :excluded-ids="excludedParentIds" />
                    <p class="text-xs text-muted-foreground">لا يمكن نقل الصفحة تحت نفسها أو تحت إحدى صفحاتها الفرعية.</p>
                    <p v-if="form.errors.parent_id" class="text-sm text-destructive-foreground">{{ form.errors.parent_id }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="page-icon">الأيقونة</Label>
                    <div class="flex items-center gap-2">
                        <Input
                            id="page-icon"
                            v-model="form.icon"
                            type="text"
                            dir="ltr"
                            class="text-start"
                            placeholder="heroicons:document-text"
                            :aria-invalid="form.errors.icon ? true : undefined"
                        />
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-md border border-input" aria-hidden="true">
                            <Icon v-if="form.icon.trim()" :icon="form.icon.trim()" class="size-5" />
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">اسم الأيقونة كما يظهر في القائمة الجانبية للموقع.</p>
                    <p v-if="form.errors.icon" class="text-sm text-destructive-foreground">{{ form.errors.icon }}</p>
                </div>

                <div class="space-y-4">
                    <div v-for="toggle in toggles" :key="toggle.field" class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <Label :for="`page-${toggle.field}`">{{ toggle.label }}</Label>
                            <p class="text-xs text-muted-foreground">{{ toggle.description }}</p>
                            <p v-if="form.errors[toggle.field]" class="text-sm text-destructive-foreground">{{ form.errors[toggle.field] }}</p>
                        </div>
                        <Switch :id="`page-${toggle.field}`" v-model="form[toggle.field]" />
                    </div>
                </div>

                <div class="flex justify-end">
                    <span :title="!form.isDirty && !form.processing ? 'لا توجد تغييرات لحفظها' : undefined">
                        <Button type="submit" :disabled="!form.isDirty || form.processing">
                            <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                            حفظ الإعدادات
                        </Button>
                    </span>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
