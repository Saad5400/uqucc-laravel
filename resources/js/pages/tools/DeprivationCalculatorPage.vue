<template>
    <SeoHead :seo="seo" />
    <DocsLayout>
        <PageHeader title="حاسبة الحرمان" icon="solar:danger-triangle-broken" />

        <!-- Rich content from database -->
        <div v-if="hasContent" class="typography mb-6">
            <RichContentRenderer :content="page?.html_content" />
        </div>

        <div class="typography">
            <!-- Information Alerts -->
            <Alert>
                <AlertDescription>
                    <b>كل نشاط منفصل</b>، مثلا تقدر تغيب 10% في <b>نشاط 1</b> و10% في <b>نشاط 2</b> بدون حرمان، نفس الشيء مع النشاط النظري والعملي.
                </AlertDescription>
            </Alert>
            <Alert>
                <AlertDescription>
                    عدد الساعات يقصد فيه <b>الساعات الفعلية اللي تحتاج تحضرها</b>، فمثلا اذا كانت مدة المحاضرة ساعتين وكانت محاضرة واحدة فقط كل اسبوع،
                    هذا يعني انه عدد الساعات في الاسبوع هو 2
                </AlertDescription>
            </Alert>

            <div class="space-y-2">
                <div class="my-4 grid grid-cols-1 !space-y-0 gap-x-2 !gap-y-0 md:grid-cols-2 lg:grid-cols-3">
                    <div class="!mb-2 space-y-1">
                        <Label for="lecturesPerWeek">عدد الساعات في الأسبوع:</Label>
                        <Input
                            class="mt-2 mb-0 text-end tabular-nums"
                            id="lecturesPerWeek"
                            type="text"
                            inputmode="numeric"
                            dir="ltr"
                            v-model="lecsPerWk"
                            placeholder="من 1 إلى 4"
                        />
                        <span class="pointer-events-none text-xs text-muted-foreground">
                            {{ formatLecturesPerWeek(lecsPerWk) }}
                        </span>
                    </div>

                    <div class="space-y-1">
                        <Label for="currentNoExcusedAbsences">ساعات الغياب الحالية بدون عذر:</Label>
                        <Input
                            id="currentNoExcusedAbsences"
                            type="text"
                            inputmode="numeric"
                            dir="ltr"
                            class="text-end tabular-nums"
                            v-model="unexcCnt"
                            :aria-invalid="absenceOverflowWarning ? true : undefined"
                            :aria-describedby="absenceOverflowWarning ? 'absence-overflow-warning' : undefined"
                        />
                    </div>

                    <div class="my-0 space-y-1">
                        <Label for="currentExcusedAbsences">ساعات الغياب الحالية بعذر:</Label>
                        <Input
                            id="currentExcusedAbsences"
                            type="text"
                            inputmode="numeric"
                            dir="ltr"
                            class="text-end tabular-nums"
                            v-model="excCnt"
                            :aria-invalid="absenceOverflowWarning ? true : undefined"
                            :aria-describedby="absenceOverflowWarning ? 'absence-overflow-warning' : undefined"
                        />
                    </div>
                </div>

                <p v-if="absenceOverflowWarning" id="absence-overflow-warning" class="!mb-2 text-xs text-destructive">
                    {{ absenceOverflowWarning }}
                </p>

                <!-- Results Cards -->
                <p v-if="!hasValidInput" class="text-muted-foreground">أدخل عدد الساعات في الأسبوع لعرض نتائج الحرمان</p>
                <div v-else class="grid grid-cols-2 gap-2 !space-y-0 lg:grid-cols-4">
                    <!-- غياب بدون عذر -->
                    <Card size="sm">
                        <CardHeader size="sm" class="px-4 pt-3">
                            <CardTitle class="text-sm font-medium text-muted-foreground">تقدر تغيب بدون عذر</CardTitle>
                        </CardHeader>
                        <CardContent size="sm" class="px-4 pb-3 text-end text-xl font-bold tabular-nums sm:text-2xl">
                            {{ isDeprived ? 'محروم' : formatHours(unexcLeft) }}
                        </CardContent>
                    </Card>

                    <!-- غياب بعذر -->
                    <Card size="sm">
                        <CardHeader size="sm" class="px-4 pt-3">
                            <CardTitle class="text-sm font-medium text-muted-foreground">تقدر تغيب بعذر</CardTitle>
                        </CardHeader>
                        <CardContent size="sm" class="px-4 pb-3 text-end text-xl font-bold tabular-nums sm:text-2xl">
                            {{ isDeprived ? 'محروم' : formatHours(absLeft) }}
                        </CardContent>
                    </Card>

                    <!-- نسبة الغياب الحالية -->
                    <Card size="sm">
                        <CardHeader size="sm" class="px-4 pt-3">
                            <CardTitle class="text-sm font-medium text-muted-foreground">نسبة الغياب الحالية</CardTitle>
                        </CardHeader>
                        <CardContent size="sm" class="px-4 pb-3 text-end text-xl font-bold sm:text-2xl">
                            <span dir="ltr" class="inline-block tabular-nums">{{ `${displayedAbsRate}%` }}</span>
                        </CardContent>
                    </Card>

                    <!-- نسبة الغياب للساعة الواحدة -->
                    <Card class="m-0" size="sm">
                        <CardHeader size="sm" class="px-4 pt-3">
                            <CardTitle class="text-sm font-medium text-muted-foreground">نسبة الغياب للساعة الواحدة</CardTitle>
                        </CardHeader>
                        <CardContent size="sm" class="px-4 pb-3 text-end text-xl font-bold sm:text-2xl">
                            <span dir="ltr" class="inline-block tabular-nums">{{ lecWeight ? `${lecWeight}%` : '' }}</span>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </DocsLayout>
</template>

<script setup lang="ts">
import DocsLayout from '@/components/layout/DocsLayout.vue';
import PageHeader from '@/components/page/PageHeader.vue';
import RichContentRenderer from '@/components/RichContentRenderer.vue';
import SeoHead, { type SeoData } from '@/components/SeoHead.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { computeDeprivationStats } from '@/lib/calculators/deprivation';
import { parseArabicNumber } from '@/lib/calculators/parseArabicNumber';
import { computed, ref } from 'vue';

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

const lecsPerWk = ref<string>('2');
const unexcCnt = ref<string>('0'); // ساعات غياب بدون عذر
const excCnt = ref<string>('0'); // ساعات غياب بعذر

/** لا تُعرض النتائج إلا بعد إدخال عدد ساعات صالح */
const hasValidInput = computed(() => parseArabicNumber(lecsPerWk.value) >= 1);

const stats = computed(() =>
    computeDeprivationStats({
        lecturesPerWeek: parseArabicNumber(lecsPerWk.value),
        unexcusedCount: parseArabicNumber(unexcCnt.value),
        excusedCount: parseArabicNumber(excCnt.value),
    }),
);

/** وزن كل محاضرة كنسبة مئوية من إجمالي الساعات */
const lecWeight = computed(() => stats.value.lectureWeight);

/** الساعات المتبقية قبل تجاوز 15% غياب بدون عذر */
const unexcLeft = computed(() => stats.value.unexcusedLeft);

/** الساعات المتبقية قبل تجاوز 25% غياب كلي */
const absLeft = computed(() => stats.value.absenceLeft);

const currentAbsRate = computed(() => stats.value.currentAbsenceRate);

/** النسبة المعروضة مقيّدة عند 100% — أكثر من ذلك يعني إدخالًا خاطئًا وحالة الحرمان تظهر في البطاقات الأخرى */
const displayedAbsRate = computed(() => Math.min(currentAbsRate.value, 100));

/** هل الطالب محروم؟ */
const isDeprived = computed(() => stats.value.isDeprived);

/** تحذير غير مانع عندما تتجاوز ساعات الغياب المدخلة إجمالي ساعات الفصل */
const absenceOverflowWarning = computed(() => {
    if (!hasValidInput.value) {
        return null;
    }
    const totalAbsences = parseArabicNumber(unexcCnt.value) + parseArabicNumber(excCnt.value);
    if (totalAbsences > stats.value.totalHours) {
        return `ساعات الغياب المدخلة (${totalAbsences}) أكثر من إجمالي ساعات الفصل (${stats.value.totalHours})، تأكد من الأرقام`;
    }
    return null;
});

function formatLecturesPerWeek(lecturesPerWeek: string | number) {
    switch (lecturesPerWeek) {
        case '1':
        case 1:
            return 'محاضرة فردية';
        case '2':
        case 2:
            return 'محاضرة زوجية';
        case '3':
        case 3:
            return 'محاضرة زوجية ومحاضرة فردية';
        case '4':
        case 4:
            return 'محاضرتين زوجية';
        default:
            return '';
    }
}

function formatHours(hours: number | undefined) {
    if (!hours && hours !== 0) {
        return '';
    } else if (hours < 0) {
        return 'محروم';
    } else if (hours === 0) {
        return 'لا يمكنك الغياب والا تنحرم';
    } else if (hours === 1) {
        return 'ساعة واحدة';
    } else if (hours === 2) {
        return 'ساعتين';
    } else if (hours <= 10) {
        return `${hours} ساعات`;
    } else {
        return `${hours} ساعة`;
    }
}
</script>
