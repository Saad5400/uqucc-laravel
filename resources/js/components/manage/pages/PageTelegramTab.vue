<script setup lang="ts">
import ConfirmDialog from '@/components/manage/ConfirmDialog.vue';
import RichContentEditor from '@/components/manage/editor/RichContentEditor.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { useForm, usePage } from '@inertiajs/vue3';
import { Loader2, Sparkles } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { generatePageSeoMeta } from './copilot';
import QuickResponseAttachmentsField from './QuickResponseAttachmentsField.vue';
import QuickResponseButtonsField from './QuickResponseButtonsField.vue';
import type { AttachmentInfo, PageWorkspace, QuickResponseButtonRow } from './types';

const props = defineProps<{
    page: PageWorkspace;
    attachments: AttachmentInfo[];
    /** Whether the admin copilot feature is on — the suggestion button disappears entirely while it is off. */
    copilotEnabled: boolean;
}>();

const form = useForm<{
    hidden_from_bot: boolean;
    requires_prefix: boolean;
    smart_search: boolean;
    quick_response_send_link: boolean;
    quick_response_auto_extract_message: boolean;
    quick_response_auto_extract_buttons: boolean;
    quick_response_auto_extract_attachments: boolean;
    quick_response_buttons: QuickResponseButtonRow[];
    quick_response_attachments: string[];
}>({
    hidden_from_bot: props.page.hidden_from_bot,
    requires_prefix: props.page.requires_prefix,
    smart_search: props.page.smart_search,
    quick_response_send_link: props.page.quick_response_send_link,
    quick_response_auto_extract_message: props.page.quick_response_auto_extract_message,
    quick_response_auto_extract_buttons: props.page.quick_response_auto_extract_buttons,
    quick_response_auto_extract_attachments: props.page.quick_response_auto_extract_attachments,
    quick_response_buttons: props.page.quick_response_buttons.map((button, index) => ({ ...button, id: index + 1 })),
    quick_response_attachments: [...props.page.quick_response_attachments],
});

/* ------------------------------------------------------------------ */
/* Reach: is the page in the bot, and what brings it up                */
/* ------------------------------------------------------------------ */

/** The column says "hidden"; the switch says "available" — the way an admin thinks of it. */
const availableInBot = computed<boolean>({
    get: () => !form.hidden_from_bot,
    set: (value) => {
        form.hidden_from_bot = !value;
    },
});

/**
 * The two columns behind "how is this page brought up" read as one choice:
 * the bot always answers «دليل العنوان»; `requires_prefix` off makes the bare
 * title enough; `smart_search` on makes any message carrying the title enough.
 */
type TriggerMode = 'prefix' | 'title' | 'mention';

const triggerMode = computed<TriggerMode>({
    get: () => (form.smart_search ? 'mention' : form.requires_prefix ? 'prefix' : 'title'),
    set: (mode) => {
        form.requires_prefix = mode === 'prefix';
        form.smart_search = mode === 'mention';
    },
});

const triggerOptions = computed<{ value: TriggerMode; label: string; description: string; example: string }[]>(() => [
    {
        value: 'prefix',
        label: 'بكلمة «دليل» ثم العنوان',
        description: 'الوضع الافتراضي: لا يتدخّل البوت إلا عند طلب صريح.',
        example: `دليل ${props.page.title}`,
    },
    {
        value: 'title',
        label: 'بالعنوان وحده أيضاً',
        description: 'كتابة العنوان وحده تكفي، إضافة إلى «دليل» + العنوان.',
        example: props.page.title,
    },
    {
        value: 'mention',
        label: 'بأي رسالة تتضمن العنوان',
        description: 'يرد البوت كلما ورد العنوان في رسالة. مناسب لعنوان مميز لا يُقال عرضاً.',
        example: `… ${props.page.title} …`,
    },
]);

/* ------------------------------------------------------------------ */
/* Reply: what the bot sends                                           */
/* ------------------------------------------------------------------ */

/**
 * The message stays an HTML string end-to-end (frozen contract: the bot,
 * `Seo` and `QuickResponseService` all consume the column as HTML, exactly
 * as Filament's RichEditor stored it). The `format="html"` editor parses
 * the string in and emits an HTML string back — `null` when emptied.
 * It lives outside `useForm` only so the dirty snapshot logic mirrors the
 * content tab's; the payload is merged in `submit()`'s transform.
 */
const message = ref<string | null>(typeof props.page.quick_response_message === 'string' ? props.page.quick_response_message : null);
const savedMessage = ref<string | null>(message.value);

const messageIsDirty = computed(() => (message.value ?? null) !== (savedMessage.value ?? null));

const inertiaPage = usePage();
const messageError = computed(() => (inertiaPage.props.errors as Record<string, string>).quick_response_message ?? null);

function handleMessageUpdate(value: Record<string, unknown> | string | null): void {
    message.value = typeof value === 'string' ? value : null;
}

/** The bot treats a missing message as "nothing to say" — persist blank as null. */
function normalizeMessageForSave(value: string | null): string | null {
    return value === null || value.trim() === '' ? null : value;
}

/** Whether the page has anything the bot could extract: the "from the page" choice is empty without it. */
const pageHasContent = computed<boolean>(() => {
    const content = props.page.html_content;

    if (typeof content === 'string') {
        return content.trim() !== '';
    }

    return content !== null && Array.isArray(content.content) && content.content.length > 0;
});

type Source = 'page' | 'custom';

/** Each auto-extract column as a "from the page / custom" choice. */
function sourceOf(field: 'quick_response_auto_extract_message' | 'quick_response_auto_extract_buttons' | 'quick_response_auto_extract_attachments') {
    return computed<Source>({
        get: () => (form[field] ? 'page' : 'custom'),
        set: (value) => {
            form[field] = value === 'page';
        },
    });
}

const messageSource = sourceOf('quick_response_auto_extract_message');
const buttonsSource = sourceOf('quick_response_auto_extract_buttons');
const attachmentsSource = sourceOf('quick_response_auto_extract_attachments');

const sourceOptions: { value: Source; label: string }[] = [
    { value: 'page', label: 'من محتوى الصفحة' },
    { value: 'custom', label: 'مخصص للبوت' },
];

/* ------------------------------------------------------------------ */
/* Copilot: drafts the custom text (the admin still saves)             */
/* ------------------------------------------------------------------ */

const confirmingSuggestion = ref(false);
const generatingSuggestion = ref(false);

async function generateSuggestion(): Promise<void> {
    if (generatingSuggestion.value) {
        return;
    }

    generatingSuggestion.value = true;

    try {
        const meta = await generatePageSeoMeta(props.page.id);

        message.value = meta.message;
        confirmingSuggestion.value = false;
        toast.success('اقترح المساعد نصاً للرد', { description: 'راجعه ثم احفظ إعدادات البوت لاعتماده.' });
    } catch (error) {
        toast.error('تعذر اقتراح النص', { description: error instanceof Error ? error.message : undefined });
    } finally {
        generatingSuggestion.value = false;
    }
}

const isDirty = computed(() => form.isDirty || messageIsDirty.value);

defineExpose({ isDirty });

function submit(): void {
    form.transform((data) => ({
        ...data,
        quick_response_message: normalizeMessageForSave(message.value),
        quick_response_buttons: data.quick_response_buttons.map(({ text, url, size }) => ({ text, url, size })),
    })).put(`/manage/pages/${props.page.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            form.defaults();
            savedMessage.value = message.value;
        },
    });
}
</script>

<template>
    <Card class="max-w-3xl">
        <CardHeader>
            <CardTitle>بوت تيليجرام</CardTitle>
            <p class="text-sm text-muted-foreground">كيف يصل الطلاب إلى هذه الصفحة في البوت، وبماذا يرد عليهم.</p>
        </CardHeader>
        <CardContent>
            <form class="space-y-8" @submit.prevent="submit">
                <!-- ===== Reach ===== -->
                <section class="space-y-5" aria-labelledby="tg-reach-heading">
                    <h3 id="tg-reach-heading" class="text-sm font-semibold">الوصول</h3>

                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <Label for="tg-available">متاحة في البوت</Label>
                            <p class="text-xs text-muted-foreground">يرد البوت بهذه الصفحة عند طلبها في المحادثات.</p>
                            <p v-if="form.errors.hidden_from_bot" class="text-sm text-destructive-foreground">{{ form.errors.hidden_from_bot }}</p>
                        </div>
                        <Switch id="tg-available" v-model="availableInBot" />
                    </div>

                    <fieldset class="space-y-3" :disabled="!availableInBot" :title="!availableInBot ? 'فعّل «متاحة في البوت» أولاً' : undefined">
                        <legend class="text-sm font-medium" :class="{ 'text-muted-foreground': !availableInBot }">كيف تُطلب؟</legend>
                        <RadioGroup v-model="triggerMode" class="gap-2">
                            <label
                                v-for="option in triggerOptions"
                                :key="option.value"
                                :for="`tg-trigger-${option.value}`"
                                class="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3 transition-colors has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-primary/5"
                                :class="{ 'cursor-not-allowed opacity-60': !availableInBot }"
                            >
                                <RadioGroupItem :id="`tg-trigger-${option.value}`" :value="option.value" class="mt-0.5" />
                                <span class="min-w-0 space-y-1">
                                    <span class="block text-sm font-medium">{{ option.label }}</span>
                                    <span class="block text-xs text-muted-foreground">{{ option.description }}</span>
                                    <code class="block w-fit max-w-full truncate rounded bg-muted px-1.5 py-0.5 text-xs">{{ option.example }}</code>
                                </span>
                            </label>
                        </RadioGroup>
                        <p v-if="form.errors.requires_prefix || form.errors.smart_search" class="text-sm text-destructive-foreground">
                            {{ form.errors.requires_prefix ?? form.errors.smart_search }}
                        </p>
                    </fieldset>
                </section>

                <Separator />

                <!-- ===== Reply ===== -->
                <section class="space-y-5" aria-labelledby="tg-reply-heading">
                    <div class="space-y-1">
                        <h3 id="tg-reply-heading" class="text-sm font-semibold">الرد</h3>
                        <p class="text-xs text-muted-foreground">
                            رسالة واحدة: العنوان، وتحته النص داخل اقتباس مطوي يفتحه القارئ بلمسة، ثم الأزرار. الصور القليلة تُرسل قبلها، والصفحة
                            الغنية بالصور تُحيل القارئ إلى الموقع.
                        </p>
                    </div>

                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <Label for="tg-send-link" :class="page.hidden ? 'text-muted-foreground' : undefined">ربط العنوان بالصفحة</Label>
                            <p class="text-xs text-muted-foreground">
                                {{ page.hidden ? 'الصفحة مخفية من الموقع، فلا رابط يعمل لها.' : 'يفتح العنوان الصفحة في الموقع.' }}
                            </p>
                        </div>
                        <span :title="page.hidden ? 'الصفحة مخفية من الموقع' : undefined">
                            <Switch id="tg-send-link" v-model="form.quick_response_send_link" :disabled="page.hidden" />
                        </span>
                    </div>

                    <!-- Text -->
                    <div class="space-y-3 rounded-lg border border-border p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span class="text-sm font-medium">النص</span>
                            <RadioGroup v-model="messageSource" class="flex gap-4" aria-label="مصدر النص">
                                <label v-for="option in sourceOptions" :key="option.value" class="flex cursor-pointer items-center gap-2 text-sm">
                                    <RadioGroupItem :id="`tg-message-source-${option.value}`" :value="option.value" />
                                    {{ option.label }}
                                </label>
                            </RadioGroup>
                        </div>

                        <template v-if="messageSource === 'page'">
                            <p class="text-xs text-muted-foreground">يُرسل محتوى الصفحة نفسه بعد تحويله إلى تنسيق تيليجرام.</p>
                            <p v-if="!pageHasContent" class="text-xs text-muted-foreground">
                                ⚠ الصفحة بلا محتوى بعد، فلن يُرسل سوى العنوان والأزرار.
                            </p>
                        </template>

                        <div v-else class="space-y-2">
                            <RichContentEditor :model-value="message" variant="message" format="html" @update:model-value="handleMessageUpdate" />
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <p class="text-xs text-muted-foreground">
                                    التنسيقات المتاحة: عريض، مائل، تسطير، شطب، كود، روابط، اقتباس. يُستخدم هذا النص أيضاً وصفاً للصفحة في نتائج البحث.
                                </p>
                                <Button
                                    v-if="copilotEnabled"
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="generatingSuggestion"
                                    @click="confirmingSuggestion = true"
                                >
                                    <Loader2 v-if="generatingSuggestion" class="size-4 animate-spin" />
                                    <Sparkles v-else class="size-4" />
                                    اقتراح نص بالمساعد
                                </Button>
                            </div>
                            <p v-if="messageError" class="text-sm text-destructive-foreground">{{ messageError }}</p>
                        </div>
                    </div>

                    <!-- Buttons -->
                    <div class="space-y-3 rounded-lg border border-border p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span class="text-sm font-medium">الأزرار</span>
                            <RadioGroup v-model="buttonsSource" class="flex gap-4" aria-label="مصدر الأزرار">
                                <label v-for="option in sourceOptions" :key="option.value" class="flex cursor-pointer items-center gap-2 text-sm">
                                    <RadioGroupItem :id="`tg-buttons-source-${option.value}`" :value="option.value" />
                                    {{ option.label }}
                                </label>
                            </RadioGroup>
                        </div>

                        <p v-if="buttonsSource === 'page'" class="text-xs text-muted-foreground">
                            روابط الصفحة تصبح أزراراً. وتُضاف أزرار الصفحات الفرعية في الحالتين.
                        </p>
                        <div v-else class="space-y-2">
                            <QuickResponseButtonsField v-model="form.quick_response_buttons" :errors="form.errors as Record<string, string>" />
                            <p class="text-xs text-muted-foreground">تُضاف أزرار الصفحات الفرعية بعد هذه الأزرار.</p>
                            <p v-if="form.errors.quick_response_buttons" class="text-sm text-destructive-foreground">
                                {{ form.errors.quick_response_buttons }}
                            </p>
                        </div>
                    </div>

                    <!-- Attachments -->
                    <div class="space-y-3 rounded-lg border border-border p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span class="text-sm font-medium">المرفقات</span>
                            <RadioGroup v-model="attachmentsSource" class="flex gap-4" aria-label="مصدر المرفقات">
                                <label v-for="option in sourceOptions" :key="option.value" class="flex cursor-pointer items-center gap-2 text-sm">
                                    <RadioGroupItem :id="`tg-attachments-source-${option.value}`" :value="option.value" />
                                    {{ option.label }}
                                </label>
                            </RadioGroup>
                        </div>

                        <p v-if="attachmentsSource === 'page'" class="text-xs text-muted-foreground">
                            صور الصفحة تُرسل قبل الرسالة إذا كانت أربعاً أو أقل؛ وإلا يُحال القارئ إلى الموقع ليراها.
                        </p>
                        <div v-else class="space-y-2">
                            <QuickResponseAttachmentsField v-model="form.quick_response_attachments" :existing-attachments="attachments" />
                            <p v-if="form.errors.quick_response_attachments" class="text-sm text-destructive-foreground">
                                {{ form.errors.quick_response_attachments }}
                            </p>
                        </div>
                    </div>
                </section>

                <div class="flex justify-end">
                    <span :title="!isDirty && !form.processing ? 'لا توجد تغييرات لحفظها' : undefined">
                        <Button type="submit" :disabled="!isDirty || form.processing">
                            <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                            حفظ إعدادات البوت
                        </Button>
                    </span>
                </div>
            </form>
        </CardContent>
    </Card>

    <ConfirmDialog
        v-model:open="confirmingSuggestion"
        title="اقتراح نص الرد"
        confirm-label="اقتراح"
        :processing="generatingSuggestion"
        @confirm="generateSuggestion"
    >
        يكتب المساعد نصاً موجزاً من محتوى الصفحة ويضعه في الحقل لمراجعته قبل الحفظ. النص نفسه يُستخدم وصفاً للصفحة في نتائج البحث.
    </ConfirmDialog>
</template>
