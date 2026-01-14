<template>
  <div>
    <!-- Information Alert -->
    <Alert class="!mb-6 border-primary/20 bg-primary/5">
      <AlertTitle class="mb-2 flex items-center gap-2 text-xl">
        🔶 نظام التحويل الداخلي بجامعة أم القرى 🔶
      </AlertTitle>
      <AlertDescription class="space-y-4 text-base leading-relaxed">
        <p>
          📌 للاطلاع على شروط التحويل حسب اللائحة المحدثة:
          <a
            href="https://uqu.edu.sa"
            target="_blank"
            rel="noopener noreferrer"
            class="font-medium text-primary underline-offset-4 hover:underline"
          >
            اضغط هنا
          </a>
        </p>

        <div class="space-y-2">
          <p class="font-bold">🔹 معلومات مهمة عن التحويل:</p>
          <ul class="space-y-1 pr-4">
            <li>• في البداية، التخصصات ما تطلب معدل معين للتحويل.</li>
            <li>
              • النسب المنشورة للسنوات السابقة تعطيك فكرة فقط عن النسب اللي انقبلت وقتها، لكن مو شرط
              تكون ثابتة.
            </li>
          </ul>
        </div>

        <div class="space-y-2">
          <p class="font-bold">عدد المقاعد هو العامل الأساسي بجانب مركبة التحويل:</p>
          <p class="pr-4">
            مثلًا، إذا تخصص الأحياء فيه 100 مقعد شاغر للتحويل وتقدم عليه 400 طالب، راح يقبلون أعلى
            100 طالب في المركبة فقط. أقل نسبة من المقبولين راح تُذكر لاحقًا في ملف النسب. البقية
            يُرفضون تلقائيًا.
          </p>
        </div>

        <div class="rounded-md bg-background/50 p-3">
          <p class="font-bold">📌 الخلاصة:</p>
          <p class="pr-4">
            قبولك يعتمد كليًا على معدلات المتقدمين معك وعدد المقاعد المتاحة للتخصص، مو على النسب
            السابقة أو أي عامل آخر.
          </p>
        </div>

        <div class="space-y-2">
          <p class="font-bold">🔹 ما هي التخصصات اللي تظهر لك وقت التقديم؟</p>
          <p class="pr-4">تعتمد على نسبتك الموزونة (اللي دخلت فيها الجامعة) 🔸 ويضاف لها 5 درجات فقط.</p>
          <div class="rounded-md bg-background/50 p-3">
            <p class="font-bold">🧠 مثال:</p>
            <p class="pr-4">
              إذا كانت موزونتك = 80<br />
              راح تظهر لك التخصصات اللي تطلب موزونة إلى 85<br />
              لكن التخصصات اللي تحتاج 86 وفوق ما راح تظهر لك.. إلا لو العمادة قللت النسب
            </p>
          </div>
        </div>

        <div class="space-y-2">
          <p class="font-bold">✅ خطوات طلب التحويل:</p>
          <ol class="space-y-1 pr-4">
            <li>1. تدخل على بوابتي الأكاديمية</li>
            <li>2. طلبات</li>
            <li>3. تختار طلب تغيير تخصص</li>
            <li>4. تحدد من رغبة واحدة إلى خمس رغبات (بالترتيب حسب رغبتك)</li>
          </ol>
        </div>

        <div class="space-y-2">
          <p class="font-bold">🔺 ملاحظات مهمة جدًا:</p>
          <ul class="space-y-1 pr-4">
            <li>• نتائج التحويل تظهر بعد انتهاء السنة الدراسية وانتهاء الترم والمفاضلة بشكل كامل</li>
            <li>
              • إذا اعتذرت عن الترم اللي راح تقدم فيه طلب التحويل → يُلغى طلب التحويل تلقائيًا
            </li>
            <li>
              • يجب أن لا تكون تجاوزت نصف الخطة الدراسية عند تقديم طلب التحويل.. أو لك أكثر من سنتين
              في الجامعة
            </li>
            <li>• لا يوجد عدول في التحويل حسب شروط السنتين الماضية</li>
          </ul>
        </div>
      </AlertDescription>
    </Alert>

    <!-- Configuration Card -->
    <Card class="!mb-6">
      <CardHeader>
        <CardTitle class="flex items-center gap-2">
          <Icon icon="solar:settings-bold" class="size-5" />
          إعدادات الحساب
        </CardTitle>
      </CardHeader>
      <CardContent class="space-y-4">
        <div class="grid gap-4 md:grid-cols-2">
          <div class="space-y-2">
            <Label for="weighted-percentage">نسبة الموزونة (%)</Label>
            <Input
              id="weighted-percentage"
              v-model="weightedPercentage"
              type="number"
              min="0"
              max="100"
              step="0.1"
              placeholder="50"
            />
            <p class="text-xs text-muted-foreground">
              القيمة الافتراضية: 50% (المضروب في الموزونة: {{ weightedMultiplier.toFixed(2) }})
            </p>
          </div>
          <div class="space-y-2">
            <Label for="gpa-percentage">نسبة المعدل التراكمي (%)</Label>
            <Input
              id="gpa-percentage"
              v-model="gpaPercentage"
              type="number"
              min="0"
              max="100"
              step="0.1"
              placeholder="50"
            />
            <p class="text-xs text-muted-foreground">
              القيمة الافتراضية: 50% (المضروب في المعدل: {{ gpaMultiplier.toFixed(2) }})
            </p>
          </div>
        </div>
        <Button variant="secondary" size="sm" @click="resetToDefaults" class="w-full md:w-auto">
          إعادة تعيين القيم الافتراضية
        </Button>
      </CardContent>
    </Card>

    <!-- Calculator Card -->
    <Card class="!mb-6">
      <CardHeader>
        <CardTitle class="flex items-center gap-2">
          <Icon icon="solar:calculator-bold" class="size-5" />
          حساب مركبة التحويل
        </CardTitle>
      </CardHeader>
      <CardContent class="space-y-4">
        <div class="grid gap-4 md:grid-cols-2">
          <div class="space-y-2">
            <Label for="weighted-score">النسبة الموزونة</Label>
            <Input
              id="weighted-score"
              v-model="weightedScore"
              type="text"
              placeholder="مثال: 99 أو ٩٩"
            />
            <p class="text-xs text-muted-foreground">النسبة الموزونة التي قُبلت بها في الجامعة (من 100)</p>
          </div>
          <div class="space-y-2">
            <Label for="cumulative-gpa">المعدل التراكمي</Label>
            <Input
              id="cumulative-gpa"
              v-model="cumulativeGpa"
              type="text"
              placeholder="مثال: 3.7 أو ٣٫٧"
            />
            <p class="text-xs text-muted-foreground">المعدل التراكمي الحالي (من 4 أو 5)</p>
          </div>
        </div>
      </CardContent>
    </Card>

    <!-- Result Card -->
    <div v-auto-animate>
      <Card v-if="transferScore !== null" class="border-primary/20 bg-primary/5">
        <CardHeader>
          <CardTitle class="flex items-center gap-2">
            <Icon icon="solar:check-circle-bold" class="size-5" />
            نتيجة الحساب
          </CardTitle>
        </CardHeader>
        <CardContent class="space-y-4">
          <div class="rounded-lg bg-background/80 p-6">
            <p class="mb-2 text-center text-sm text-muted-foreground">مركبة التحويل</p>
            <p class="text-center text-4xl font-bold text-primary">
              {{ transferScore.toFixed(2) }}
            </p>
          </div>

          <div class="space-y-3 rounded-lg bg-background/80 p-4">
            <p class="font-bold">🔹 طريقة الحساب:</p>
            <div class="space-y-2 pr-4 text-sm">
              <p>
                • الموزونة: {{ parsedWeightedScore.toFixed(2) }} × {{ weightedMultiplier.toFixed(2) }} =
                {{ (parsedWeightedScore * weightedMultiplier).toFixed(2) }}
              </p>
              <p>
                • المعدل التراكمي: {{ parsedCumulativeGpa.toFixed(2) }} × {{ gpaMultiplier.toFixed(2) }} =
                {{ (parsedCumulativeGpa * gpaMultiplier).toFixed(2) }}
              </p>
              <div class="border-t pt-2">
                <p class="font-bold">
                  • مركبة التحويل =
                  {{ (parsedWeightedScore * weightedMultiplier).toFixed(2) }} +
                  {{ (parsedCumulativeGpa * gpaMultiplier).toFixed(2) }} =
                  {{ transferScore.toFixed(2) }}
                </p>
              </div>
            </div>
          </div>

          <Alert>
            <AlertDescription>
              <p class="font-bold">💡 تذكير:</p>
              <p class="pr-4 text-sm">
                هذه النتيجة هي مركبة التحويل الخاصة بك. قبولك في التخصص يعتمد على ترتيبك بين المتقدمين
                وعدد المقاعد المتاحة، وليس على النسبة فقط.
              </p>
            </AlertDescription>
          </Alert>
        </CardContent>
      </Card>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { vAutoAnimate } from '@formkit/auto-animate/vue'
import { toast } from 'vue-sonner'
import { Icon } from '@iconify/vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'

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
  // Calculate multiplier to make the GPA contribution equal to the percentage
  // If GPA is out of 4, then to get 50 points we need: 4 * x = 50, so x = 12.5
  // But we want it to be percentage-based, so: 4 * x = percentage, so x = percentage / 4
  // However, the standard assumes GPA is out of 4 for 50%, so: x = percentage / 100 * 12.5
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
