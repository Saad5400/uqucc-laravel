<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { ExternalLink, Loader2, Paperclip, X } from 'lucide-vue-next';
import { computed, ref, useTemplateRef } from 'vue';
import type { AttachmentInfo } from './types';
import { uploadPageFile } from './uploads';

const props = defineProps<{
    /** URL/name info for attachments that already existed when the page loaded. */
    existingAttachments: AttachmentInfo[];
}>();

/** Ordered list of stored paths (relative to the `public` disk). */
const model = defineModel<string[]>({ required: true });

const fileInput = useTemplateRef('fileInput');
const uploadingNames = ref<string[]>([]);
const uploadError = ref<string | null>(null);

/** URLs for freshly uploaded files (existing ones come from the server prop). */
const uploadedUrls = ref<Record<string, string>>({});

const rows = computed(() =>
    model.value.map((path) => {
        const existing = props.existingAttachments.find((attachment) => attachment.path === path);

        return {
            path,
            name: existing?.name ?? (path.split('/').pop() || path),
            url: existing?.url ?? uploadedUrls.value[path] ?? null,
        };
    }),
);

async function handleFilesSelected(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const files = [...(input.files ?? [])];

    input.value = '';
    uploadError.value = null;

    for (const file of files) {
        uploadingNames.value = [...uploadingNames.value, file.name];

        try {
            const uploaded = await uploadPageFile(file, 'quick_response');

            uploadedUrls.value = { ...uploadedUrls.value, [uploaded.path]: uploaded.url };
            model.value = [...model.value, uploaded.path];
        } catch (error) {
            uploadError.value = error instanceof Error ? error.message : 'تعذر رفع الملف.';
        } finally {
            uploadingNames.value = uploadingNames.value.filter((name) => name !== file.name);
        }
    }
}

function removeAttachment(path: string): void {
    model.value = model.value.filter((candidate) => candidate !== path);
}
</script>

<template>
    <div class="space-y-2">
        <ul v-if="rows.length || uploadingNames.length" class="overflow-hidden rounded-md border border-border">
            <li v-for="row in rows" :key="row.path" class="flex items-center gap-2 border-b border-border p-2 last:border-b-0">
                <Paperclip class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span dir="ltr" class="min-w-0 flex-1 truncate text-start text-sm">{{ row.name }}</span>
                <Button v-if="row.url" as-child variant="ghost" size="icon-sm" :aria-label="`فتح ${row.name}`">
                    <a :href="row.url" target="_blank" rel="noopener noreferrer">
                        <ExternalLink />
                    </a>
                </Button>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    class="text-destructive-foreground"
                    :aria-label="`إزالة ${row.name}`"
                    @click="removeAttachment(row.path)"
                >
                    <X />
                </Button>
            </li>
            <li
                v-for="name in uploadingNames"
                :key="name"
                class="flex items-center gap-2 border-b border-border p-2 text-muted-foreground last:border-b-0"
            >
                <Loader2 class="size-4 shrink-0 animate-spin" aria-hidden="true" />
                <span dir="ltr" class="min-w-0 flex-1 truncate text-start text-sm">{{ name }}</span>
            </li>
        </ul>

        <input ref="fileInput" type="file" multiple class="hidden" aria-hidden="true" tabindex="-1" @change="handleFilesSelected" />
        <Button type="button" variant="outline" size="sm" :disabled="uploadingNames.length > 0" @click="fileInput?.click()">
            <Paperclip />
            رفع مرفقات
        </Button>
        <p class="text-xs text-muted-foreground">تُحفظ المرفقات مع حفظ التبويب. الإزالة من القائمة لا تحذف الملف من التخزين.</p>
        <p v-if="uploadError" class="text-sm text-destructive-foreground">{{ uploadError }}</p>
    </div>
</template>
