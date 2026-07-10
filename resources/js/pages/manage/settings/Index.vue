<script setup lang="ts">
import ManageLayout from '@/components/manage/ManageLayout.vue';
import PageHeader from '@/components/manage/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { TagsInput, TagsInputInput, TagsInputItem, TagsInputItemDelete, TagsInputItemText } from '@/components/ui/tags-input';
import { Head, useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed } from 'vue';

defineOptions({ layout: ManageLayout });

const props = defineProps<{
    telegram: {
        allowed_chat_ids: string[];
        auto_delete_messages: boolean;
    };
}>();

const form = useForm({
    allowed_chat_ids: [...props.telegram.allowed_chat_ids],
    auto_delete_messages: props.telegram.auto_delete_messages,
});

/** First error for the chat ids field, including per-element errors like `allowed_chat_ids.0`. */
const chatIdsError = computed(() => {
    const errors = form.errors as Record<string, string>;
    const key = Object.keys(errors).find((errorKey) => errorKey === 'allowed_chat_ids' || errorKey.startsWith('allowed_chat_ids.'));

    return key ? errors[key] : null;
});

function submit(): void {
    form.put('/manage/settings/telegram', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <Head title="الإعدادات" />
    <PageHeader title="الإعدادات" description="إعدادات الموقع والبوت" />

    <Card class="max-w-2xl">
        <CardHeader>
            <CardTitle>إعدادات تيليجرام</CardTitle>
        </CardHeader>
        <CardContent>
            <form class="space-y-6" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="allowed-chat-ids">معرّفات المحادثات المسموح لها</Label>
                    <TagsInput id="allowed-chat-ids" v-model="form.allowed_chat_ids" :aria-invalid="chatIdsError ? true : undefined">
                        <TagsInputItem v-for="chatId in form.allowed_chat_ids" :key="chatId" :value="chatId" dir="ltr">
                            <TagsInputItemText />
                            <TagsInputItemDelete :aria-label="`إزالة ${chatId}`" />
                        </TagsInputItem>
                        <TagsInputInput placeholder="أضف معرّف محادثة…" dir="ltr" class="text-start" inputmode="numeric" />
                    </TagsInput>
                    <p class="text-xs text-muted-foreground">
                        معرّفات المحادثات (Chat IDs) المسموح لها باستخدام أوامر إدارة الصفحات. اتركها فارغة للسماح لجميع المحادثات. معرّفات المجموعات
                        تبدأ بإشارة سالبة.
                    </p>
                    <p v-if="chatIdsError" class="text-sm text-destructive-foreground">{{ chatIdsError }}</p>
                </div>

                <div class="flex items-start justify-between gap-4">
                    <div class="space-y-1">
                        <Label for="auto-delete-messages">حذف رسائل إدارة الصفحات تلقائياً</Label>
                        <p class="text-xs text-muted-foreground">عند التفعيل، تُحذف رسائل أوامر إدارة الصفحات تلقائياً بعد اكتمال العملية.</p>
                    </div>
                    <Switch id="auto-delete-messages" v-model="form.auto_delete_messages" />
                </div>
                <p v-if="form.errors.auto_delete_messages" class="text-sm text-destructive-foreground">{{ form.errors.auto_delete_messages }}</p>

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
