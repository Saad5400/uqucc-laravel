<template>
  <div class="space-y-6">
    <!-- Calculator Card -->
    <Card size="sm">
      <CardHeader size="sm">
        <CardTitle>حساب مركبة التحويل</CardTitle>
      </CardHeader>
      <CardContent size="sm" class="space-y-4">
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <Label for="weighted-score" class="mb-2 block">النسبة الموزونة</Label>
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
            <Label for="cumulative-gpa" class="mb-2 block">المعدل التراكمي</Label>
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
    <div v-auto-animate>
      <Card v-if="transferScore !== null" size="sm" class="border-primary/20 bg-primary/5">
        <CardContent size="sm" class="space-y-3">
          <div class="text-center">
            <p class="mb-2 text-sm text-muted-foreground">مركبة التحويل</p>
            <p class="text-3xl font-bold text-primary">
              {{ transferScore.toFixed(2) }}
            </p>
            <div class="mt-4 space-y-1 text-xs text-muted-foreground">
              <p>{{ parsedWeightedScore.toFixed(2) }} × {{ weightedMultiplier.toFixed(2) }} = {{ (parsedWeightedScore * weightedMultiplier).toFixed(2) }}</p>
              <p>{{ parsedCumulativeGpa.toFixed(2) }} × {{ gpaMultiplier.toFixed(2) }} = {{ (parsedCumulativeGpa * gpaMultiplier).toFixed(2) }}</p>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>

    <!-- Configuration Card -->
    <Card size="sm">
      <CardHeader size="sm">
        <CardTitle>إعدادات الحساب</CardTitle>
      </CardHeader>
      <CardContent size="sm" class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2">
          <div>
            <Label for="weighted-percentage" class="mb-2 block">نسبة الموزونة (%)</Label>
            <Input
              id="weighted-percentage"
              v-model="weightedPercentage"
              type="number"
              min="0"
              max="100"
              step="0.1"
              placeholder="50"
              variant="visible"
              class="text-base"
            />
          </div>
          <div>
            <Label for="gpa-percentage" class="mb-2 block">نسبة المعدل التراكمي (%)</Label>
            <Input
              id="gpa-percentage"
              v-model="gpaPercentage"
              type="number"
              min="0"
              max="100"
              step="0.1"
              placeholder="50"
              variant="visible"
              class="text-base"
            />
          </div>
        </div>
        <Button @click="resetToDefaults">
          إعادة تعيين القيم الافتراضية
        </Button>
      </CardContent>
    </Card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { vAutoAnimate } from '@formkit/auto-animate/vue'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

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
  const percentage = parseArabicNumber(gpaPercentage.value)
  return (percentage / 100) * 12.5
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
