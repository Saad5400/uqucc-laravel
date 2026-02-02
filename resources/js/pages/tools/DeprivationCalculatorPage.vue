<template>
  <DocsLayout>
    <PageHeader title="حاسبة الحرمان" icon="solar:danger-triangle-broken" />

    <!-- Rich content from database -->
    <div v-if="hasContent" class="typography mb-6">
      <RichContentRenderer :content="page.html_content" />
    </div>

    <div class="typography">
      <!-- Information Alerts -->
      <Alert>
        <AlertDescription>
          <b>كل نشاط منفصل</b>، مثلا تقدر تغيب 10% في <code>نشاط 1</code> و10% في
          <code>نشاط 2</code> بدون حرمان، نفس الشيء مع النشاط النظري والعملي.
        </AlertDescription>
      </Alert>
      <Alert>
        <AlertDescription>
          عدد الساعات يقصد فيه <b>الساعات الفعلية اللي تحتاج تحضرها</b>، فمثلا اذا كانت مدة
          المحاضرة ساعتين وكانت محاضرة واحدة فقط كل اسبوع، هذا يعني انه عدد الساعات في الاسبوع
          هو 2
        </AlertDescription>
      </Alert>

      <div class="space-y-2">
        <div class="my-4 grid grid-cols-1 !gap-y-0 gap-x-2 !space-y-0 md:grid-cols-2 lg:grid-cols-3">
          <div class="space-y-1 !mb-2">
            <Label for="lecturesPerWeek">عدد الساعات في الأسبوع:</Label>
            <Input class="mt-2 mb-0" id="lecturesPerWeek" type="number" v-model="lecsPerWk" :min="1" :max="4" />
            <span class="pointer-events-none text-muted-foreground text-xs">
              {{ formatLecturesPerWeek(lecsPerWk) }}
            </span>
          </div>

          <div class="space-y-1">
            <Label for="currentNoExcusedAbsences">ساعات الغياب الحالية بدون عذر:</Label>
            <Input id="currentNoExcusedAbsences" type="number" v-model="unexcCnt" />
          </div>

          <div class="space-y-1 my-0">
            <Label for="currentExcusedAbsences">ساعات الغياب الحالية بعذر:</Label>
            <Input id="currentExcusedAbsences" type="number" v-model="excCnt" />
          </div>
        </div>

        <!-- Results Cards -->
        <div class="grid grid-cols-2 gap-2 !space-y-0 lg:grid-cols-4">
          <!-- غياب بدون عذر -->
          <Card size="sm">
            <CardHeader size="sm">
              <CardTitle>تقدر تغيب بدون عذر</CardTitle>
            </CardHeader>
            <CardContent size="sm" class="text-end text-2xl font-bold">
              {{ isDeprived ? 'محروم' : formatHours(unexcLeft) }}
            </CardContent>
          </Card>

          <!-- غياب بعذر -->
          <Card size="sm">
            <CardHeader size="sm">
              <CardTitle>تقدر تغيب بعذر</CardTitle>
            </CardHeader>
            <CardContent size="sm" class="text-end text-2xl font-bold">
              {{ isDeprived ? 'محروم' : formatHours(absLeft) }}
            </CardContent>
          </Card>

          <!-- نسبة الغياب الحالية -->
          <Card size="sm">
            <CardHeader size="sm">
              <CardTitle>نسبة الغياب الحالية</CardTitle>
            </CardHeader>
            <CardContent size="sm" class="text-end text-2xl font-bold">
              {{ `${currentAbsRate}%` }}
            </CardContent>
          </Card>

          <!-- نسبة الغياب للساعة الواحدة -->
          <Card class="m-0" size="sm">
            <CardHeader size="sm">
              <CardTitle>نسبة الغياب للساعة الواحدة</CardTitle>
            </CardHeader>
            <CardContent size="sm" class="text-end text-2xl font-bold">
              {{ lecWeight ? `${lecWeight}%` : '' }}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  </DocsLayout>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import DocsLayout from '@/components/layout/DocsLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import RichContentRenderer from '@/components/RichContentRenderer.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
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

const weeks = 17 // عدد أسابيع المقرر
const maxUnexcRate = 0.15 // %15 حد الغياب بدون عذر
const maxAbsRate = 0.25 // %25 حد الغياب الكلي

const lecsPerWk = ref<string>('2')
const unexcCnt = ref<string>('0') // ساعات غياب بدون عذر
const excCnt = ref<string>('0') // ساعات غياب بعذر

/** وزن كل محاضرة كنسبة مئوية من إجمالي الساعات */
const lecWeight = computed(() => {
  const weight = (1 * 100) / (weeks * parseInt(lecsPerWk.value))
  return Math.round(weight * 100) / 100 // دقة منزلتين
})

/** إجمالي عدد الساعات في الفصل */
const totalHours = computed(() => weeks * parseInt(lecsPerWk.value))

/** الساعات المتبقية قبل تجاوز 15% غياب بدون عذر */
const unexcLeft = computed(() => {
  const total = parseInt(unexcCnt.value) + parseInt(excCnt.value)
  const maxUnexcHours = Math.floor(totalHours.value * maxUnexcRate)
  const maxAbsHours = Math.floor(totalHours.value * maxAbsRate)

  // 1) by the unexcused‐only cap
  const byUnexcRule = maxUnexcHours - parseInt(unexcCnt.value)
  // 2) by the overall cap (subtract what you've already used)
  const byTotalRule = maxAbsHours - total

  // you can only take the stricter of the two
  return Math.min(byUnexcRule, byTotalRule)
})

/** الساعات المتبقية قبل تجاوز 25% غياب كلي */
const absLeft = computed(() => {
  const absCnt = parseInt(unexcCnt.value) + parseInt(excCnt.value)
  const maxAbsHours = Math.floor(totalHours.value * maxAbsRate)
  return maxAbsHours - absCnt // قد تكون سالبة إن تجاوز الحد
})

const currentAbsRate = computed(() => {
  const absCnt = parseInt(unexcCnt.value) + parseInt(excCnt.value)
  const rate = totalHours.value > 0 ? (absCnt / totalHours.value) * 100 : 0
  return Math.round(rate * 100) / 100 // دقة منزلتين
})

/** هل الطالب محروم؟ */
const isDeprived = computed(() => unexcLeft.value < 0 || absLeft.value < 0)

function formatLecturesPerWeek(lecturesPerWeek: string | number) {
  switch (lecturesPerWeek) {
    case '1':
    case 1:
      return 'محاضرة فردية'
    case '2':
    case 2:
      return 'محاضرة زوجية'
    case '3':
    case 3:
      return 'محاضرة زوجية ومحاضرة فردية'
    case '4':
    case 4:
      return 'محاضرتين زوجية'
    default:
      return ''
  }
}

function formatHours(hours: number | undefined) {
  if (!hours && hours !== 0) {
    return ''
  } else if (hours < 0) {
    return 'محروم'
  } else if (hours === 0) {
    return 'لا يمكنك الغياب والا تنحرم'
  } else if (hours === 1) {
    return 'ساعة واحدة'
  } else if (hours === 2) {
    return 'ساعتين'
  } else {
    return `${hours} ساعات`
  }
}
</script>
