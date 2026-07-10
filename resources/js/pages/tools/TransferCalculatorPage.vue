<template>
    <SeoHead :seo="seo" />
    <DocsLayout>
        <PageHeader title="حاسبة التحويل الداخلي" icon="solar:transfer-horizontal-bold" />

        <!-- Rich content from database -->
        <div v-if="hasContent" class="typography mb-6">
            <RichContentRenderer :content="page?.html_content" />
        </div>

        <div class="typography">
            <!-- Calculator Card -->
            <Card size="sm" class="!mb-4">
                <CardHeader size="sm">
                    <CardTitle class="text-base">أدخل بياناتك</CardTitle>
                </CardHeader>
                <CardContent size="sm">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <Label for="weighted-score" class="!mb-2 block">النسبة الموزونة</Label>
                            <Input
                                id="weighted-score"
                                v-model="weightedScore"
                                type="text"
                                inputmode="decimal"
                                dir="ltr"
                                placeholder="مثال: 99"
                                class="text-end text-base tabular-nums"
                                :aria-invalid="weightedScoreWarning ? true : undefined"
                                :aria-describedby="weightedScoreWarning ? 'weighted-score-warning' : undefined"
                            />
                            <p v-if="weightedScoreWarning" id="weighted-score-warning" class="!mt-1.5 text-xs text-destructive">
                                {{ weightedScoreWarning }}
                            </p>
                        </div>
                        <div>
                            <Label for="cumulative-gpa" class="!mb-2 block">المعدل التراكمي</Label>
                            <Input
                                id="cumulative-gpa"
                                v-model="cumulativeGpa"
                                type="text"
                                inputmode="decimal"
                                dir="ltr"
                                placeholder="مثال: 3.7"
                                class="text-end text-base tabular-nums"
                                :aria-invalid="cumulativeGpaWarning ? true : undefined"
                                :aria-describedby="cumulativeGpaWarning ? 'cumulative-gpa-warning' : undefined"
                            />
                            <p v-if="cumulativeGpaWarning" id="cumulative-gpa-warning" class="!mt-1.5 text-xs text-destructive">
                                {{ cumulativeGpaWarning }}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Result Card -->
            <div v-auto-animate class="!mb-4">
                <Card v-if="transferScore !== null" size="sm" class="border-primary/20 bg-primary/5">
                    <CardContent size="sm" class="pt-6">
                        <div class="text-center">
                            <p class="!mb-2 text-sm text-muted-foreground">مركبة التحويل</p>
                            <p dir="ltr" class="text-3xl font-bold text-primary tabular-nums">
                                {{ transferScore.toFixed(2) }}
                            </p>
                            <div dir="ltr" class="!mt-4 space-y-1 text-xs text-muted-foreground tabular-nums">
                                <p>
                                    {{ parsedWeightedScore.toFixed(2) }} × {{ weightedMultiplier.toFixed(2) }} =
                                    {{ (parsedWeightedScore * weightedMultiplier).toFixed(2) }}
                                </p>
                                <p>
                                    {{ parsedCumulativeGpa.toFixed(2) }} × {{ gpaMultiplier.toFixed(2) }} =
                                    {{ (parsedCumulativeGpa * gpaMultiplier).toFixed(2) }}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
                <div v-else class="flex flex-col items-center gap-3 rounded-xl border border-dashed px-6 py-8 text-center">
                    <ArrowLeftRight class="size-8 text-muted-foreground" aria-hidden="true" />
                    <p class="!m-0 text-sm text-muted-foreground">
                        مركبة التحويل هي معيار المفاضلة في التحويل الداخلي: 50% من نسبتك الموزونة + 50% من معدلك التراكمي بعد تحويله إلى نسبة من 100.
                        أدخل الرقمين وستظهر النتيجة فورًا.
                    </p>
                    <Button variant="outline" size="sm" @click="fillExample">جرّب مثالًا: موزونة 95.5 ومعدل 3.8</Button>
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
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { parseArabicNumber } from '@/lib/calculators/parseArabicNumber';
import { computeTransferScore } from '@/lib/calculators/transfer';
import { vAutoAnimate } from '@formkit/auto-animate/vue';
import { ArrowLeftRight } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

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

// Configuration state
const weightedPercentage = ref('50');
const gpaPercentage = ref('50');

// Input state
const weightedScore = ref('');
const cumulativeGpa = ref('');

// Computed multipliers based on percentages (shown in the result breakdown)
const weightedMultiplier = computed(() => parseArabicNumber(weightedPercentage.value) / 100);
const gpaMultiplier = computed(() => parseArabicNumber(gpaPercentage.value) / 4);

// Parsed input values
const parsedWeightedScore = computed(() => parseArabicNumber(weightedScore.value));
const parsedCumulativeGpa = computed(() => parseArabicNumber(cumulativeGpa.value));

// Calculate transfer score (null while either input is missing)
const transferScore = computed(() => computeTransferScore(weightedScore.value, cumulativeGpa.value, weightedPercentage.value, gpaPercentage.value));

/** تحذير غير مانع عند تجاوز النسبة الموزونة حدّها الطبيعي (من 100) */
const weightedScoreWarning = computed(() => {
    const value = parsedWeightedScore.value;
    if (weightedScore.value.trim() !== '' && (value <= 0 || value > 100)) {
        return 'النسبة الموزونة تكون بين 0 و100';
    }
    return null;
});

/** تحذير غير مانع عند تجاوز المعدل التراكمي حدّه الطبيعي (من 4) */
const cumulativeGpaWarning = computed(() => {
    const value = parsedCumulativeGpa.value;
    if (cumulativeGpa.value.trim() !== '' && (value <= 0 || value > 4)) {
        return 'المعدل التراكمي يكون بين 0 و4';
    }
    return null;
});

/** يملأ الحقلين بقيم مثال ليرى المستخدم شكل النتيجة مباشرة */
const fillExample = () => {
    weightedScore.value = '95.5';
    cumulativeGpa.value = '3.8';
};

// Load from localStorage
const loadData = () => {
    if (typeof window !== 'undefined') {
        const stored = localStorage.getItem('transferCalculator');
        if (stored) {
            try {
                const data = JSON.parse(stored);
                weightedPercentage.value = data.weightedPercentage || '50';
                gpaPercentage.value = data.gpaPercentage || '50';
                weightedScore.value = data.weightedScore || '';
                cumulativeGpa.value = data.cumulativeGpa || '';
            } catch (e) {
                console.error('Error loading data:', e);
            }
        }
    }
};

// Save to localStorage
const saveData = () => {
    if (typeof window !== 'undefined') {
        const data = {
            weightedPercentage: weightedPercentage.value,
            gpaPercentage: gpaPercentage.value,
            weightedScore: weightedScore.value,
            cumulativeGpa: cumulativeGpa.value,
        };
        localStorage.setItem('transferCalculator', JSON.stringify(data));
    }
};

// Watch for changes and save
watch([weightedPercentage, gpaPercentage, weightedScore, cumulativeGpa], saveData);

// Load on mount
onMounted(() => {
    loadData();
});
</script>

<style scoped>
* {
    margin: 0;
}
</style>
