<script setup lang="ts">
import RichContentEditor from '@/components/manage/editor/RichContentEditor.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useForm, usePage } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import QuickResponseAttachmentsField from './QuickResponseAttachmentsField.vue';
import QuickResponseButtonsField from './QuickResponseButtonsField.vue';
import type { AttachmentInfo, PageWorkspace, QuickResponseButtonRow } from './types';

const props = defineProps<{
    page: PageWorkspace;
    attachments: AttachmentInfo[];
}>();

const form = useForm<{
    quick_response_send_link: boolean;
    quick_response_send_screenshot: boolean;
    quick_response_auto_extract_message: boolean;
    quick_response_auto_extract_buttons: boolean;
    quick_response_auto_extract_attachments: boolean;
    quick_response_buttons: QuickResponseButtonRow[];
    quick_response_attachments: string[];
}>({
    quick_response_send_link: props.page.quick_response_send_link,
    quick_response_send_screenshot: props.page.quick_response_send_screenshot,
    quick_response_auto_extract_message: props.page.quick_response_auto_extract_message,
    quick_response_auto_extract_buttons: props.page.quick_response_auto_extract_buttons,
    quick_response_auto_extract_attachments: props.page.quick_response_auto_extract_attachments,
    quick_response_buttons: props.page.quick_response_buttons.map((button, index) => ({ ...button, id: index + 1 })),
    quick_response_attachments: [...props.page.quick_response_attachments],
});

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

const isDirty = computed(() => form.isDirty || messageIsDirty.value);

defineExpose({ isDirty });

/**
 * Master toggle mirroring Filament's `auto_extract_all`: it is not persisted
 * itself, it only fans out to the three auto-extract sub-toggles.
 */
const autoExtractAll = computed<boolean>({
    get: () => form.quick_response_auto_extract_message && form.quick_response_auto_extract_buttons && form.quick_response_auto_extract_attachments,
    set: (value) => {
        form.quick_response_auto_extract_message = value;
        form.quick_response_auto_extract_buttons = value;
        form.quick_response_auto_extract_attachments = value;
    },
});

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
            <CardTitle>ردود تيليجرام السريعة</CardTitle>
            <p class="text-sm text-muted-foreground">
                الرد السريع مفعّل دائماً. يمكنك تخصيص المحتوى أو تفعيل الاستخراج التلقائي من المحتوى الرئيسي لكل حقل.
            </p>
        </CardHeader>
        <CardContent>
            <form class="space-y-6" @submit.prevent="submit">
                <div class="flex items-start justify-between gap-4">
                    <div class="space-y-1">
                        <Label for="qr-auto-extract-all">استخراج الكل تلقائياً</Label>
                        <p class="text-xs text-muted-foreground">تفعيل/تعطيل الاستخراج التلقائي لجميع الحقول (الرسالة، الأزرار، والمرفقات).</p>
                    </div>
                    <Switch id="qr-auto-extract-all" v-model="autoExtractAll" />
                </div>

                <div class="flex items-start justify-between gap-4">
                    <div class="space-y-1">
                        <Label for="qr-send-link">إرسال رابط الصفحة مع الرد</Label>
                    </div>
                    <Switch id="qr-send-link" v-model="form.quick_response_send_link" />
                </div>

                <div v-if="!page.hidden" class="flex items-start justify-between gap-4">
                    <div class="space-y-1">
                        <Label for="qr-send-screenshot">إرسال لقطة شاشة للصفحة</Label>
                        <p class="text-xs text-muted-foreground">عند التفعيل، سيتم إرسال لقطة شاشة من الصفحة مع المحتوى المخصص.</p>
                    </div>
                    <Switch id="qr-send-screenshot" v-model="form.quick_response_send_screenshot" />
                </div>

                <div class="space-y-4 rounded-lg border border-border p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <Label for="qr-auto-message">استخراج نص الرد تلقائياً</Label>
                            <p class="text-xs text-muted-foreground">استخراج نص الرد تلقائياً من المحتوى الرئيسي.</p>
                        </div>
                        <Switch id="qr-auto-message" v-model="form.quick_response_auto_extract_message" />
                    </div>

                    <div v-if="!form.quick_response_auto_extract_message" class="space-y-2">
                        <Label>نص الرد</Label>
                        <RichContentEditor :model-value="message" variant="message" format="html" @update:model-value="handleMessageUpdate" />
                        <p class="text-xs text-muted-foreground">
                            نص قصير يرسله البوت مع الرابط في تيليجرام. التنسيقات المدعومة: عريض، مائل، تسطير، شطب، كود، روابط.
                        </p>
                        <p v-if="messageError" class="text-sm text-destructive-foreground">{{ messageError }}</p>
                    </div>
                </div>

                <div class="space-y-4 rounded-lg border border-border p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <Label for="qr-auto-buttons">استخراج الأزرار تلقائياً</Label>
                            <p class="text-xs text-muted-foreground">استخراج الأزرار تلقائياً من المحتوى الرئيسي.</p>
                        </div>
                        <Switch id="qr-auto-buttons" v-model="form.quick_response_auto_extract_buttons" />
                    </div>

                    <div v-if="!form.quick_response_auto_extract_buttons" class="space-y-2">
                        <Label>الأزرار</Label>
                        <QuickResponseButtonsField v-model="form.quick_response_buttons" :errors="form.errors as Record<string, string>" />
                        <p v-if="form.quick_response_attachments.length" class="text-xs text-muted-foreground">
                            ملاحظة: يمكن إرسال الأزرار مع الصور، لكن قد لا تعمل مع المستندات. إذا رفض تيليجرام الجمع بينهما فسيتم إرسال المحتوى بدون
                            أحدهما.
                        </p>
                        <p v-if="form.errors.quick_response_buttons" class="text-sm text-destructive-foreground">
                            {{ form.errors.quick_response_buttons }}
                        </p>
                    </div>
                </div>

                <div class="space-y-4 rounded-lg border border-border p-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <Label for="qr-auto-attachments">استخراج المرفقات تلقائياً</Label>
                            <p class="text-xs text-muted-foreground">استخراج المرفقات تلقائياً من المحتوى الرئيسي.</p>
                        </div>
                        <Switch id="qr-auto-attachments" v-model="form.quick_response_auto_extract_attachments" />
                    </div>

                    <div v-if="!form.quick_response_auto_extract_attachments" class="space-y-2">
                        <Label>مرفقات الرد (صور/ملفات)</Label>
                        <QuickResponseAttachmentsField v-model="form.quick_response_attachments" :existing-attachments="attachments" />
                        <p v-if="form.errors.quick_response_attachments" class="text-sm text-destructive-foreground">
                            {{ form.errors.quick_response_attachments }}
                        </p>
                    </div>
                </div>

                <div class="flex justify-end">
                    <span :title="!form.isDirty && !form.processing ? 'لا توجد تغييرات لحفظها' : undefined">
                        <Button type="submit" :disabled="!form.isDirty || form.processing">
                            <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                            حفظ إعدادات تيليجرام
                        </Button>
                    </span>
                </div>
            </form>
        </CardContent>
    </Card>
</template>
