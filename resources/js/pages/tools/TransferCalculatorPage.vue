<template>
  <DocsLayout>
    <PageHeader title="حاسبة التحويل الداخلي" icon="solar:transfer-horizontal-bold" />

    <!-- Rich content from database -->
    <div v-if="hasContent" class="typography mb-6">
      <RichContentRenderer :content="page.html_content" />
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
              <p class="text-3xl font-bold text-primary">
                {{ transferScore.toFixed(2) }}
              </p>
              <div class="!mt-4 space-y-1 text-xs text-muted-foreground">
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
      </div>
    </div>
  </DocsLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { vAutoAnimate } from '@formkit/auto-animate/vue'
import { toast } from 'vue-sonner'
import DocsLayout from '@/components/layout/DocsLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import RichContentRenderer from '@/components/RichContentRenderer.vue'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

defineOptions({
  layout: false
})

interface Props {
  page?: {
    html_content: any
    title?: string
  }
  hasContent?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  hasContent: false
})

// Convert Arabic-Indic digits and separators to a JS number
const parseArabicNumber = (text = '') =>
  parseFloat(
    text
      .trim()
      .replace(/[٠-٩]/g, (digit) => '٠١٢٣٤٥٦٧٨٩'.indexOf(digit) + '')
      .replace(/[٫،,]/g, '.')
  ) || 0

// Configuration state
const weightedPercentage = ref('50')
const gpaPercentage = ref('50')

// Input state
const weightedScore = ref('')
const cumulativeGpa = ref('')

// Computed multipliers based on percentages
const weightedMultiplier = computed(() => parseArabicNumber(weightedPercentage.value) / 100)
const gpaMultiplier = computed(() => {
  // GPA is out of 4, so to get percentage points: GPA × (percentage / 4)
  // For 50%: 4 × 12.5 = 50, so multiplier = 50 / 4 = 12.5
  // For any percentage: multiplier = percentage / 4
  const percentage = parseArabicNumber(gpaPercentage.value)
  return percentage / 4
})

// Parsed input values
const parsedWeightedScore = computed(() => parseArabicNumber(weightedScore.value))
const parsedCumulativeGpa = computed(() => parseArabicNumber(cumulativeGpa.value))

// Calculate transfer score
const transferScore = computed(() => {
  const weighted = parsedWeightedScore.value
  const gpa = parsedCumulativeGpa.value

  if (weighted <= 0 || gpa <= 0) {
    return null
  }

  return weighted * weightedMultiplier.value + gpa * gpaMultiplier.value
})

// Reset to default values
const resetToDefaults = () => {
  weightedPercentage.value = '50'
  gpaPercentage.value = '50'
  toast.success('تم إعادة تعيين القيم الافتراضية')
}

// Load from localStorage
const loadData = () => {
  if (typeof window !== 'undefined') {
    const stored = localStorage.getItem('transferCalculator')
    if (stored) {
      try {
        const data = JSON.parse(stored)
        weightedPercentage.value = data.weightedPercentage || '50'
        gpaPercentage.value = data.gpaPercentage || '50'
        weightedScore.value = data.weightedScore || ''
        cumulativeGpa.value = data.cumulativeGpa || ''
      } catch (e) {
        console.error('Error loading data:', e)
      }
    }
  }
}

// Save to localStorage
const saveData = () => {
  if (typeof window !== 'undefined') {
    const data = {
      weightedPercentage: weightedPercentage.value,
      gpaPercentage: gpaPercentage.value,
      weightedScore: weightedScore.value,
      cumulativeGpa: cumulativeGpa.value
    }
    localStorage.setItem('transferCalculator', JSON.stringify(data))
  }
}

// Watch for changes and save
watch([weightedPercentage, gpaPercentage, weightedScore, cumulativeGpa], saveData)

// Load on mount
onMounted(() => {
  loadData()
})
</script>

<style scoped>
* {
  margin: 0;
}
</style>
