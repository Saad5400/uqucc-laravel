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
                <CardHeader size="sm"> </CardHeader>
                <CardContent size="sm">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <Label for="weighted-score" class="!mb-2 block">النسبة الموزونة</Label>
                            <Input
                                id="weighted-score"
                                v-model="weightedScore"
                                type="text"
                                inputmode="decimal"
                                placeholder="مثال: 99"
                                variant="visible"
                                class="text-base"
                            />
                        </div>
                        <div>
                            <Label for="cumulative-gpa" class="!mb-2 block">المعدل التراكمي</Label>
                            <Input
                                id="cumulative-gpa"
                                v-model="cumulativeGpa"
                                type="text"
                                inputmode="decimal"
                                placeholder="مثال: 3.7"
                                variant="visible"
                                class="text-base"
                            />
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
                <p v-else class="text-muted-foreground">أدخل النسبة الموزونة والمعدل التراكمي لعرض مركبة التحويل</p>
            </div>
        </div>
    </DocsLayout>
</template>

<script setup lang="ts">
import DocsLayout from '@/components/layout/DocsLayout.vue';
import PageHeader from '@/components/page/PageHeader.vue';
import RichContentRenderer from '@/components/RichContentRenderer.vue';
import SeoHead, { type SeoData } from '@/components/SeoHead.vue';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { parseArabicNumber } from '@/lib/calculators/parseArabicNumber';
import { computeTransferScore } from '@/lib/calculators/transfer';
import { vAutoAnimate } from '@formkit/auto-animate/vue';
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
