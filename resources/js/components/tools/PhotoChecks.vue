<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { PhotoCheck } from '@/lib/studentPhoto/checks';
import { MANUAL_REQUIREMENTS } from '@/lib/studentPhoto/requirements';
import { Check, CircleAlert, TriangleAlert } from 'lucide-vue-next';

interface Props {
    /** Verdicts the tool worked out from the pixels and the file. */
    checks: PhotoCheck[];
    /** Which of the human-judgement requirements the student has ticked. */
    confirmed: Record<string, boolean>;
}

defineProps<Props>();

const emit = defineEmits<{
    (event: 'update:confirmed', confirmed: Record<string, boolean>): void;
}>();

const STATUS_ICONS = { pass: Check, warn: TriangleAlert, fail: CircleAlert };

const STATUS_CLASSES = {
    pass: 'text-primary',
    warn: 'text-amber-600 dark:text-amber-500',
    fail: 'text-destructive',
};

function toggle(current: Record<string, boolean>, id: string, value: boolean | 'indeterminate'): void {
    emit('update:confirmed', { ...current, [id]: value === true });
}
</script>

<template>
    <div class="space-y-6">
        <section class="space-y-3">
            <h3 class="!my-0 text-base font-semibold">ما تحقّقنا منه تلقائيًا</h3>

            <ul class="!my-0 space-y-2 !ps-0">
                <li v-for="check in checks" :key="check.id" class="flex items-start gap-2 rounded-lg bg-muted/50 p-3">
                    <component
                        :is="STATUS_ICONS[check.status]"
                        class="mt-0.5 size-4 shrink-0"
                        :class="STATUS_CLASSES[check.status]"
                        aria-hidden="true"
                    />
                    <div class="space-y-0.5">
                        <p class="!my-0 text-sm font-medium">{{ check.label }}</p>
                        <p class="!my-0 text-xs text-muted-foreground">{{ check.detail }}</p>
                    </div>
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <div>
                <h3 class="!my-0 text-base font-semibold">ما يجب أن تتأكد منه بنفسك</h3>
                <p class="!my-0 text-xs text-muted-foreground">هذه شروط لا يمكن لأي برنامج الحكم عليها من الصورة، وهي أكثر أسباب رفض صورة البطاقة.</p>
            </div>

            <ul class="!my-0 space-y-2 !ps-0">
                <li v-for="requirement in MANUAL_REQUIREMENTS" :key="requirement.id" class="flex items-start gap-2 rounded-lg p-1">
                    <Checkbox
                        :id="`requirement-${requirement.id}`"
                        class="mt-0.5"
                        :model-value="confirmed[requirement.id] === true"
                        @update:model-value="toggle(confirmed, requirement.id, $event)"
                    />
                    <div class="space-y-0.5">
                        <Label :for="`requirement-${requirement.id}`" class="text-sm leading-5 font-medium">
                            {{ requirement.label }}
                        </Label>
                        <p class="!my-0 text-xs text-muted-foreground">{{ requirement.detail }}</p>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
