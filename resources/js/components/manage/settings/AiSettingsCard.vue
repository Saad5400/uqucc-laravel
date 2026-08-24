<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed } from 'vue';
import type { AiModelsInEffect, AiSettingsValues } from './types';

const props = defineProps<{
    ai: AiSettingsValues;
    models: AiModelsInEffect;
}>();

interface FeatureToggle {
    field: 'ai_enabled' | 'search_enabled' | 'assistant_enabled' | 'telegram_ai_enabled' | 'admin_copilot_enabled' | 'admin_assistant_enabled';
    label: string;
    helper: string;
}

const featureToggles: FeatureToggle[] = [
    {
        field: 'ai_enabled',
        label: 'تفعيل الذكاء الاصطناعي',
        helper: 'مفتاح التشغيل الرئيسي. عند إيقافه تتعطل جميع ميزات الذكاء الاصطناعي بغض النظر عن المفاتيح الأخرى.',
    },
    { field: 'search_enabled', label: 'البحث الذكي', helper: 'تفعيل البحث المعزز بالذكاء الاصطناعي في الموقع.' },
    { field: 'assistant_enabled', label: 'المساعد الذكي', helper: 'تفعيل المساعد الذكي للزوار.' },
    {
        field: 'telegram_ai_enabled',
        label: 'ذكاء بوت التليجرام',
        helper: 'التشغيل العام لمساعد التليجرام. لا يرد إلا في المحادثات المفعّلة بأمر ‎/ai_on، وفقط على الرسائل التي تبدأ بكلمة «سيك».',
    },
    { field: 'admin_copilot_enabled', label: 'مساعد لوحة الإدارة', helper: 'تفعيل المساعد الذكي داخل لوحة الإدارة.' },
    {
        field: 'admin_assistant_enabled',
        label: 'المساعد الإداري',
        helper: 'تفعيل المساعد الإداري الذي ينظّم الصفحات ويضبط الإعدادات — كل تغيير يقترحه يتطلب تأكيداً منك قبل تنفيذه.',
    },
];

/**
 * Models are configuration, not operator state (ai-kit docs/DECISIONS.md #26):
 * they used to be editable rows that silently beat config, so a deploy could
 * change the model and change nothing. They are shown read-only rather than
 * hidden — an operator still needs to see what is answering students.
 */
const modelRows = computed(() => [
    { label: 'نموذج المحادثة', value: props.models.chat },
    { label: 'مستوى التفكير', value: props.models.chat_reasoning_effort },
    { label: 'نموذج الرؤية', value: props.models.vision },
    { label: 'نموذج التضمين (Embeddings)', value: props.models.embedding },
]);

const form = useForm({
    ai_enabled: props.ai.ai_enabled,
    search_enabled: props.ai.search_enabled,
    assistant_enabled: props.ai.assistant_enabled,
    telegram_ai_enabled: props.ai.telegram_ai_enabled,
    admin_copilot_enabled: props.ai.admin_copilot_enabled,
    admin_assistant_enabled: props.ai.admin_assistant_enabled,
    daily_budget_usd: props.ai.daily_budget_usd,
    per_session_rate_limit: props.ai.per_session_rate_limit,
    per_conversation_rate_limit: props.ai.per_conversation_rate_limit,
});

function submit(): void {
    form.put('/manage/settings/ai', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <Card class="max-w-2xl">
        <CardHeader>
            <CardTitle class="text-lg">إعدادات الذكاء الاصطناعي</CardTitle>
        </CardHeader>
        <CardContent>
            <form class="space-y-8" @submit.prevent="submit">
                <section class="space-y-4">
                    <div>
                        <h3 class="font-medium">التفعيل</h3>
                        <p class="text-xs text-muted-foreground">مفاتيح تشغيل ميزات الذكاء الاصطناعي</p>
                    </div>

                    <div v-for="toggle in featureToggles" :key="toggle.field" class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <Label :for="`ai-${toggle.field}`">{{ toggle.label }}</Label>
                            <p class="text-xs text-muted-foreground">{{ toggle.helper }}</p>
                        </div>
                        <Switch :id="`ai-${toggle.field}`" v-model="form[toggle.field]" />
                    </div>
                </section>

                <section class="space-y-4">
                    <div>
                        <h3 class="font-medium">النماذج</h3>
                        <p class="text-xs text-muted-foreground">
                            النماذج المستخدمة حالياً عبر OpenRouter. تُضبط من إعدادات المشروع وليس من هنا — سابقاً كان تعديلها من اللوحة يتجاوز
                            الإعدادات بصمت.
                        </p>
                    </div>

                    <dl class="divide-y divide-border rounded-md border">
                        <div v-for="model in modelRows" :key="model.label" class="flex items-center justify-between gap-4 px-3 py-2">
                            <dt class="text-sm text-muted-foreground">{{ model.label }}</dt>
                            <dd dir="ltr" class="text-start font-mono text-xs tabular-nums">{{ model.value || '—' }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="space-y-4">
                    <div>
                        <h3 class="font-medium">التكلفة والحدود</h3>
                        <p class="text-xs text-muted-foreground">ضوابط التكلفة وحدود الاستخدام اليومية</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="space-y-2">
                            <Label for="ai-daily-budget">الميزانية اليومية (دولار)</Label>
                            <Input
                                id="ai-daily-budget"
                                v-model="form.daily_budget_usd"
                                type="number"
                                dir="ltr"
                                class="text-start tabular-nums"
                                inputmode="decimal"
                                min="0"
                                step="0.5"
                                required
                                :aria-invalid="form.errors.daily_budget_usd ? true : undefined"
                            />
                            <p v-if="form.errors.daily_budget_usd" class="text-sm text-destructive-foreground">
                                {{ form.errors.daily_budget_usd }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="ai-session-rate-limit">حد الرسائل اليومي لكل جلسة</Label>
                            <Input
                                id="ai-session-rate-limit"
                                v-model="form.per_session_rate_limit"
                                type="number"
                                dir="ltr"
                                class="text-start tabular-nums"
                                inputmode="numeric"
                                min="1"
                                step="1"
                                required
                                :aria-invalid="form.errors.per_session_rate_limit ? true : undefined"
                            />
                            <p v-if="form.errors.per_session_rate_limit" class="text-sm text-destructive-foreground">
                                {{ form.errors.per_session_rate_limit }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="ai-conversation-rate-limit">حد الرسائل اليومي لكل محادثة تليجرام</Label>
                            <Input
                                id="ai-conversation-rate-limit"
                                v-model="form.per_conversation_rate_limit"
                                type="number"
                                dir="ltr"
                                class="text-start tabular-nums"
                                inputmode="numeric"
                                min="1"
                                step="1"
                                required
                                :aria-invalid="form.errors.per_conversation_rate_limit ? true : undefined"
                            />
                            <p v-if="form.errors.per_conversation_rate_limit" class="text-sm text-destructive-foreground">
                                {{ form.errors.per_conversation_rate_limit }}
                            </p>
                        </div>
                    </div>
                </section>

                <div class="flex flex-wrap items-center justify-end gap-3">
                    <p v-if="!form.isDirty && !form.processing" class="text-xs text-muted-foreground">لا توجد تغييرات لحفظها</p>
                    <Button type="submit" :disabled="!form.isDirty || form.processing">
                        <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                        حفظ إعدادات الذكاء
                    </Button>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
