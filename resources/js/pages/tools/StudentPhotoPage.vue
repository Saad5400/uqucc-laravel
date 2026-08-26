<script setup lang="ts">
import DocsLayout from '@/components/layout/DocsLayout.vue';
import PageHeader from '@/components/page/PageHeader.vue';
import RichContentRenderer from '@/components/RichContentRenderer.vue';
import SeoHead, { type SeoData } from '@/components/SeoHead.vue';
import PhotoChecks from '@/components/tools/PhotoChecks.vue';
import PhotoCropCanvas from '@/components/tools/PhotoCropCanvas.vue';
import PhotoDropzone from '@/components/tools/PhotoDropzone.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { formatFileSize, formatFullDate } from '@/lib/formatters';
import type { PhotoMetrics } from '@/lib/studentPhoto/analysis';
import { buildPhotoChecks, worstStatus, type PhotoCheck } from '@/lib/studentPhoto/checks';
import { decodePhotoFile, PhotoDecodeError, type DecodedPhoto } from '@/lib/studentPhoto/decode';
import { clampView, cropFromView, defaultView, maxZoom, rotateView, type CropView, type Size } from '@/lib/studentPhoto/geometry';
import { buildWorkingImage, measureCrop, renderCroppedJpeg, type RenderedPhoto, type WorkingImage } from '@/lib/studentPhoto/render';
import {
    chooseOutputSize,
    DEFAULT_OUTPUT_SIZE,
    MANUAL_REQUIREMENTS,
    OUTPUT_FILE_NAME,
    RESPONSIBILITY_LABELS,
    UNIVERSITY_RULES,
    UPLOAD_STEPS,
} from '@/lib/studentPhoto/requirements';
import { Check, Download, RotateCcw, RotateCw, TriangleAlert, Undo2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { toast } from 'vue-sonner';

defineOptions({
    layout: false,
});

interface Props {
    page?: {
        html_content: any;
        title?: string;
    };
    hasContent?: boolean;
    seo: SeoData;
}

withDefaults(defineProps<Props>(), {
    hasContent: false,
});

/** Re-rendering on every pointer move would encode dozens of JPEGs a second. */
const RENDER_DEBOUNCE_MS = 120;

const decoded = shallowRef<DecodedPhoto | null>(null);
const working = shallowRef<WorkingImage | null>(null);
const rendered = shallowRef<RenderedPhoto | null>(null);
const metrics = shallowRef<PhotoMetrics | null>(null);
const view = ref<CropView | null>(null);
const quarterTurns = ref(0);
const previewUrl = ref<string | null>(null);
/** Width of the cropped region in original photo pixels — what the output can really carry. */
const cropWidth = ref(0);
const confirmed = ref<Record<string, boolean>>({});
const showGuides = ref(true);
const isDecoding = ref(false);
const isRendering = ref(false);
const errorMessage = ref<string | null>(null);

/** Guards against an older render finishing after a newer one. */
let renderToken = 0;
let renderTimer: ReturnType<typeof setTimeout> | null = null;

const workingSize = computed<Size>(() => ({ width: working.value?.width ?? 0, height: working.value?.height ?? 0 }));

/**
 * The output size is decided for the student, not by them: the largest size the
 * university allows that this crop can fill with real pixels.
 */
const outputSize = computed(() => (cropWidth.value > 0 ? chooseOutputSize(cropWidth.value) : DEFAULT_OUTPUT_SIZE));

const checks = computed<PhotoCheck[]>(() => {
    if (!rendered.value) {
        return [];
    }

    return buildPhotoChecks({
        outputWidth: rendered.value.width,
        outputHeight: rendered.value.height,
        outputBytes: rendered.value.bytes,
        cropWidth: cropWidth.value,
        metrics: metrics.value,
        takenAt: decoded.value?.takenAt ?? null,
        now: new Date(),
    });
});

const summaryStatus = computed(() => worstStatus(checks.value));

const failedChecks = computed(() => checks.value.filter((check) => check.status === 'fail'));
const missingConfirmations = computed(() => MANUAL_REQUIREMENTS.filter((requirement) => confirmed.value[requirement.id] !== true));

/**
 * The download is never gated: the student's own photo is theirs to take, and a
 * checklist they have not ticked is not evidence the photo is wrong. What the
 * tool owes them instead is a clear warning about what the university is likely
 * to reject.
 */
const downloadWarning = computed<string | null>(() => {
    if (failedChecks.value.length > 0) {
        return `قد تُرفض الصورة بسبب: ${failedChecks.value.map((check) => check.label).join('، ')}.`;
    }

    if (missingConfirmations.value.length > 0) {
        return `تبقّى ${missingConfirmations.value.length} من الشروط التي عليك التأكد منها بنفسك — راجعها قبل الرفع.`;
    }

    return null;
});

const zoomCeiling = computed(() => (working.value ? maxZoom(workingSize.value) : 1));
const canZoom = computed(() => zoomCeiling.value > 1.001);

/** The slider is exponential so the low zoom levels stay controllable. */
const zoomPercent = computed({
    get: (): number => {
        if (!view.value || !canZoom.value) {
            return 0;
        }

        return Math.round((100 * Math.log(view.value.zoom)) / Math.log(zoomCeiling.value));
    },
    set: (percent: number): void => {
        if (!view.value || !canZoom.value) {
            return;
        }

        view.value = clampView({ ...view.value, zoom: zoomCeiling.value ** (percent / 100) }, workingSize.value);
    },
});

const originalSummary = computed(() => {
    if (!decoded.value || !working.value) {
        return null;
    }

    return {
        name: decoded.value.fileName,
        dimensions: `${working.value.width} × ${working.value.height}`,
        bytes: decoded.value.fileBytes,
        takenAt: decoded.value.takenAt,
        wasStraightened: decoded.value.orientation !== 1,
    };
});

function releasePreview(): void {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = null;
    }
}

function resetPhotoState(): void {
    if (renderTimer) {
        clearTimeout(renderTimer);
        renderTimer = null;
    }

    renderToken += 1;
    releasePreview();
    decoded.value?.release();
    decoded.value = null;
    working.value = null;
    rendered.value = null;
    metrics.value = null;
    view.value = null;
    quarterTurns.value = 0;
    cropWidth.value = 0;
    confirmed.value = {};
}

async function loadFile(file: File): Promise<void> {
    errorMessage.value = null;
    isDecoding.value = true;

    let nextPhoto: DecodedPhoto;

    try {
        nextPhoto = await decodePhotoFile(file);
    } catch (error) {
        // A file we could not open must not cost the student the photo they
        // already have open and framed.
        errorMessage.value = error instanceof PhotoDecodeError ? error.message : 'تعذّر فتح هذه الصورة. جرّب صورة أخرى بصيغة JPG أو PNG.';
        isDecoding.value = false;

        return;
    }

    try {
        resetPhotoState();
        decoded.value = nextPhoto;
        working.value = buildWorkingImage(nextPhoto, 0);
        view.value = defaultView({ width: working.value.width, height: working.value.height });

        await refreshOutput();
    } catch {
        resetPhotoState();
        errorMessage.value = 'تعذّر تجهيز الصورة داخل المتصفح. جرّب تحديث الصفحة أو متصفحًا آخر.';
    } finally {
        isDecoding.value = false;
    }
}

async function refreshOutput(): Promise<void> {
    if (!working.value || !view.value) {
        return;
    }

    const token = ++renderToken;
    const crop = cropFromView(view.value, workingSize.value);
    const scale = working.value.scale > 0 ? working.value.scale : 1;

    cropWidth.value = crop.width / scale;
    isRendering.value = true;

    try {
        const measured = measureCrop(working.value, crop);
        const size = chooseOutputSize(cropWidth.value);
        const result = await renderCroppedJpeg(working.value, crop, { width: size.width, height: size.height });

        if (token !== renderToken) {
            return;
        }

        releasePreview();
        metrics.value = measured;
        rendered.value = result;
        previewUrl.value = URL.createObjectURL(result.blob);
    } catch {
        if (token === renderToken) {
            errorMessage.value = 'تعذّر تجهيز الصورة داخل المتصفح. جرّب تحديث الصفحة أو متصفحًا آخر.';
        }
    } finally {
        if (token === renderToken) {
            isRendering.value = false;
        }
    }
}

function scheduleRefresh(): void {
    if (renderTimer) {
        clearTimeout(renderTimer);
    }

    renderTimer = setTimeout(() => {
        renderTimer = null;
        void refreshOutput();
    }, RENDER_DEBOUNCE_MS);
}

function rotate(direction: 1 | -1): void {
    if (!decoded.value || !working.value || !view.value) {
        return;
    }

    const sizeBefore = { width: working.value.width, height: working.value.height };

    quarterTurns.value = (quarterTurns.value + direction + 4) % 4;
    working.value = buildWorkingImage(decoded.value, quarterTurns.value);
    view.value = rotateView(view.value, sizeBefore, direction === 1 ? 1 : 3);

    scheduleRefresh();
}

function resetFraming(): void {
    if (!working.value) {
        return;
    }

    view.value = defaultView(workingSize.value);
    scheduleRefresh();
}

function download(): void {
    if (!previewUrl.value) {
        return;
    }

    const link = document.createElement('a');

    link.href = previewUrl.value;
    link.download = OUTPUT_FILE_NAME;
    document.body.appendChild(link);
    link.click();
    link.remove();

    toast.success('نُزِّلت الصورة — ارفعها الآن من البوابة الأكاديمية');
}

watch(view, scheduleRefresh);

onBeforeUnmount(resetPhotoState);
</script>

<template>
    <SeoHead :seo="seo" />
    <DocsLayout>
        <PageHeader title="صورة البطاقة الجامعية" icon="solar:camera-broken" />

        <!-- Rich content from database -->
        <div v-if="hasContent" class="typography mb-6">
            <RichContentRenderer :content="page?.html_content" />
        </div>

        <div class="typography">
            <Alert>
                <AlertDescription>
                    اضبط صورتك على شروط البطاقة الجامعية: قصّ بمقاس ٣ × ٤، وحفظ بصيغة JPG بحجم أقل من ٣٠٠ كيلوبايت. الأداة تعمل داخل متصفحك بالكامل —
                    صورتك لا تُرفع لأي خادم — وهي تقصّ وتصغّر فقط ولا تعدّل ملامح الصورة، لأن الصور المعدّلة إلكترونيًا مرفوضة.
                </AlertDescription>
            </Alert>

            <p v-if="errorMessage" class="!mb-4 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                {{ errorMessage }}
            </p>

            <!-- Empty state: teach the rules before asking for a file -->
            <template v-if="!working">
                <PhotoDropzone :busy="isDecoding" @select="loadFile" @reject="errorMessage = $event" />

                <section class="!mt-6 space-y-3">
                    <h2 class="!my-0 text-lg font-semibold">شروط الجامعة لصورة البطاقة</h2>

                    <ul class="!my-0 space-y-2 !ps-0">
                        <li v-for="rule in UNIVERSITY_RULES" :key="rule.text" class="flex items-start gap-2 text-sm">
                            <Badge
                                :variant="rule.responsibility === 'student' ? 'outline' : rule.responsibility === 'fixed' ? 'default' : 'secondary'"
                                class="mt-0.5 shrink-0"
                            >
                                {{ RESPONSIBILITY_LABELS[rule.responsibility] }}
                            </Badge>
                            <span>{{ rule.text }}</span>
                        </li>
                    </ul>
                </section>
            </template>

            <!-- Editing state -->
            <div v-else class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_24rem]">
                <div class="space-y-3">
                    <PhotoCropCanvas
                        v-if="view"
                        :working="working"
                        :view="view"
                        :show-guides="showGuides"
                        @update:view="view = $event"
                        @reset="resetFraming"
                    />

                    <div class="flex flex-wrap items-center gap-2">
                        <Button type="button" variant="outline" size="sm" title="تدوير لليسار" @click="rotate(-1)">
                            <RotateCcw class="size-4" />
                            تدوير
                        </Button>
                        <Button type="button" variant="outline" size="sm" title="تدوير لليمين" @click="rotate(1)">
                            <RotateCw class="size-4" />
                            تدوير
                        </Button>
                        <Button type="button" variant="outline" size="sm" @click="resetFraming">
                            <Undo2 class="size-4" />
                            إعادة الضبط
                        </Button>
                        <Button type="button" variant="ghost" size="sm" @click="resetPhotoState">صورة أخرى</Button>
                    </div>

                    <div class="flex items-center gap-3">
                        <Label for="zoom" class="shrink-0 text-sm">التكبير</Label>
                        <input
                            id="zoom"
                            v-model.number="zoomPercent"
                            type="range"
                            min="0"
                            max="100"
                            step="1"
                            class="h-2 w-full accent-primary"
                            :disabled="!canZoom"
                            :title="canZoom ? undefined : 'الصورة بالكاد تكفي للإطار، فلا مجال للتكبير'"
                        />
                    </div>

                    <div class="flex items-center gap-2">
                        <Switch id="guides" :model-value="showGuides" @update:model-value="showGuides = $event" />
                        <Label for="guides" class="text-sm">إظهار دليل موضع الوجه والعينين</Label>
                    </div>

                    <p class="!my-0 text-xs text-muted-foreground">
                        اسحب الصورة لتحريكها، وقرّب بإصبعين أو بعجلة الفأرة. من لوحة المفاتيح: الأسهم للتحريك، + و − للتكبير والتصغير، و ٠ لإعادة
                        الضبط.
                    </p>

                    <p v-if="originalSummary" class="!my-0 text-xs text-muted-foreground">
                        الأصل:
                        <span dir="ltr" class="inline-block text-start">{{ originalSummary.name }}</span>
                        — <span dir="ltr" class="inline-block tabular-nums">{{ originalSummary.dimensions }}</span> بكسل،
                        <span dir="ltr" class="inline-block tabular-nums">{{ formatFileSize(originalSummary.bytes) }}</span>
                        <template v-if="originalSummary.takenAt">، صُوّرت في {{ formatFullDate(originalSummary.takenAt) }}</template>
                        <template v-if="originalSummary.wasStraightened">، وقد عُدِّل ميلانها تلقائيًا</template>
                    </p>
                </div>

                <div class="space-y-4">
                    <div class="space-y-2 rounded-xl border p-4">
                        <h3 class="!my-0 text-base font-semibold">الناتج</h3>

                        <!-- Shown at its true pixel size, so the preview is the file. -->
                        <div class="flex flex-col items-center gap-2">
                            <img
                                v-if="previewUrl"
                                :src="previewUrl"
                                alt="معاينة الصورة الناتجة"
                                class="!my-0 h-auto max-w-full rounded-md border bg-card"
                                :width="outputSize.width"
                                :height="outputSize.height"
                            />

                            <div v-if="rendered" class="flex flex-wrap items-center justify-center gap-x-3 text-xs text-muted-foreground">
                                <span>
                                    <span dir="ltr" class="inline-block tabular-nums">{{ rendered.width }} × {{ rendered.height }}</span>
                                    بكسل
                                </span>
                                <span>
                                    <span dir="ltr" class="inline-block tabular-nums">{{ formatFileSize(rendered.bytes) }}</span>
                                    بصيغة JPG
                                </span>
                                <span v-if="isRendering">جارٍ التحديث…</span>
                            </div>
                        </div>

                        <p class="!my-0 text-xs text-muted-foreground">
                            اخترنا لك أكبر مقاس تقبله الجامعة وتستطيع صورتك ملأه بتفاصيل حقيقية، وبأعلى جودة تحت حد
                            <span dir="ltr" class="inline-block tabular-nums">300 KB</span>.
                        </p>

                        <Button
                            type="button"
                            class="w-full"
                            :disabled="!previewUrl"
                            :title="previewUrl ? 'تنزيل الصورة بصيغة JPG' : 'جارٍ تجهيز الصورة…'"
                            @click="download"
                        >
                            <Download class="size-4" />
                            تنزيل الصورة
                        </Button>

                        <p v-if="failedChecks.length > 0" class="!my-0 flex items-start gap-1 text-xs text-destructive">
                            <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                            {{ downloadWarning }}
                        </p>
                        <p v-else-if="downloadWarning" class="!my-0 text-xs text-muted-foreground">{{ downloadWarning }}</p>
                        <p v-else class="!my-0 flex items-center gap-1 text-xs text-primary">
                            <Check class="size-3.5" />
                            جاهزة للرفع
                        </p>
                    </div>

                    <div
                        class="rounded-xl border p-4"
                        :class="{
                            'border-destructive/40': summaryStatus === 'fail',
                            'border-amber-500/40': summaryStatus === 'warn',
                        }"
                    >
                        <PhotoChecks :checks="checks" :confirmed="confirmed" @update:confirmed="confirmed = $event" />
                    </div>
                </div>
            </div>

            <section class="!mt-8 space-y-2">
                <h2 class="!my-0 text-lg font-semibold">بعد التنزيل: أين ترفعها</h2>
                <ol class="!my-0 !ps-5">
                    <li v-for="step in UPLOAD_STEPS" :key="step">{{ step }}</li>
                </ol>
            </section>
        </div>
    </DocsLayout>
</template>
