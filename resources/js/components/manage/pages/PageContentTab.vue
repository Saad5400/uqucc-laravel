<script setup lang="ts">
import RichContentEditor from '@/components/manage/editor/RichContentEditor.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { router, usePage } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { PageHtmlContent, PageWorkspace } from './types';

const props = defineProps<{
    page: PageWorkspace;
}>();

/**
 * The editor binds to `content` and emits stored-format TipTap JSON; the
 * dirty tracking and save wiring below compare serialized snapshots.
 *
 * A plain ref (not `useForm`) on purpose: TipTap JSON is recursively typed
 * and Inertia's `FormDataType` mapped type cannot instantiate it.
 */
const content = ref<PageHtmlContent>(props.page.html_content);
const savedContent = ref<string>(JSON.stringify(props.page.html_content));

const isDirty = computed(() => JSON.stringify(content.value) !== savedContent.value);

defineExpose({ isDirty, content });

function handleContentUpdate(value: Record<string, unknown> | string | null): void {
    content.value = value as PageHtmlContent;
}

const processing = ref(false);

const inertiaPage = usePage();
const contentError = computed(() => (inertiaPage.props.errors as Record<string, string>).html_content ?? null);

function submit(): void {
    if (processing.value) {
        return;
    }

    processing.value = true;

    router.put(
        `/manage/pages/${props.page.id}`,
        { html_content: content.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                savedContent.value = JSON.stringify(content.value);
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}
</script>

<template>
    <Card>
        <CardContent>
            <RichContentEditor
                :model-value="content"
                variant="full"
                upload-url="/manage/pages/uploads?type=editor"
                @update:model-value="handleContentUpdate"
            />
        </CardContent>

        <div
            class="sticky bottom-0 z-10 -mb-6 flex flex-wrap items-center justify-end gap-3 rounded-b-xl border-t border-border bg-card/95 px-6 py-3 backdrop-blur"
        >
            <p v-if="contentError" class="me-auto text-sm text-destructive-foreground">{{ contentError }}</p>

            <span :title="!isDirty && !processing ? 'لا توجد تغييرات لحفظها' : undefined">
                <Button type="button" :disabled="!isDirty || processing" @click="submit">
                    <template v-if="processing">
                        <Loader2 class="size-4 animate-spin" />
                        الحفظ…
                    </template>
                    <template v-else>حفظ المحتوى</template>
                </Button>
            </span>
        </div>
    </Card>
</template>
