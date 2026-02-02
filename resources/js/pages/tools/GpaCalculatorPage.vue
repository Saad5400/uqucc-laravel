<template>
  <DocsLayout>
    <PageHeader title="حاسبة المعدل" icon="solar:calculator-broken" />

    <!-- Rich content from database -->
    <div v-if="hasContent" class="typography mb-6">
      <RichContentRenderer :content="page.html_content" />
    </div>

    <div class="typography">
      <div v-auto-animate>
        <div v-if="totalCredits" class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
          <!-- Real GPA -->
          <Card size="sm">
            <CardHeader size="sm">
              <CardTitle class="text-lg">المعدل الدقيق</CardTitle>
            </CardHeader>
            <CardContent size="sm" class="text-end text-2xl font-bold">
              {{ gpa }}
            </CardContent>
          </Card>

          <!-- Approximate GPA -->
          <Card size="sm">
            <CardHeader size="sm">
              <CardTitle class="text-lg">المعدل التقريبي</CardTitle>
            </CardHeader>
            <CardContent size="sm" class="text-end text-2xl font-bold">
              {{ approximateGpa }}
            </CardContent>
          </Card>

          <!-- Total Credits -->
          <Card size="sm">
            <CardHeader size="sm">
              <CardTitle class="text-lg">إجمالي الساعات</CardTitle>
            </CardHeader>
            <CardContent size="sm" class="text-end text-2xl font-bold">
              {{ totalCredits }}
            </CardContent>
          </Card>

          <!-- Total Points -->
          <Card size="sm">
            <CardHeader size="sm">
              <CardTitle class="text-lg">إجمالي النقاط</CardTitle>
            </CardHeader>
            <CardContent size="sm" class="text-end text-2xl font-bold">
              {{ totalPoints }}
            </CardContent>
          </Card>
        </div>

        <p v-else class="!mb-2 text-muted-foreground">املأ البيانات لحساب المعدل</p>
      </div>

      <div class="!my-4 flex items-center justify-between gap-2 overflow-x-auto">
        <Button @click="addCourse" class="flex-1">
          إضافة مقرر
          <Plus />
        </Button>
        <div class="flex gap-2">
          <Button variant="secondary" @click="exportCourses" class="flex-1">
            تصدير البيانات
            <FileDown />
          </Button>
          <Button variant="secondary" @click="importCourses" class="flex-1">
            استيراد البيانات
            <FileUp />
          </Button>
        </div>
      </div>

      <div v-auto-animate class="!space-y-2">
        <div v-for="course in courses" :key="course.id" class="my-0 flex gap-2">
          <Button class="!size-9" aria-label="حذف" variant="destructive" @click="removeCourse(course.id)">
            <Trash />
          </Button>
          <Input v-model="course.name" placeholder="اسم المقرر" />
          <Input v-model="course.credits" placeholder="الساعات" class="w-42" />
          <Select
            :model-value="course.grade?.value"
            @update:model-value="
              (val) => updateCourseField(course.id, 'grade', { value: val, label: val })
            "
          >
            <SelectTrigger class="w-42">
              <SelectValue placeholder="التقدير" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="grade in Object.keys(gradeValues)"
                :key="grade"
                :value="grade"
              >
                {{ grade }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>
    </div>
  </DocsLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { FileDown, FileUp, Plus, Trash } from 'lucide-vue-next'
import { nanoid } from 'nanoid'
import { vAutoAnimate } from '@formkit/auto-animate/vue'
import { toast } from 'vue-sonner'
import DocsLayout from '@/components/layout/DocsLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import RichContentRenderer from '@/components/RichContentRenderer.vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue
} from '@/components/ui/select'

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

interface Course {
  id: string
  name: string
  credits: string
  grade?: {
    value: string
    label: string
  }
}

// Convert Arabic-Indic digits and separators to a JS number
const parseArabicNumber = (text = '') =>
  parseFloat(
    text
      .trim()
      .replace(/[٠-٩]/g, (digit) => '٠١٢٣٤٥٦٧٨٩'.indexOf(digit) + '')
      .replace(/[٫،,]/g, '.')
  ) || 0

const gradeValues: Record<string, number> = {
  'A+': 4,
  A: 3.75,
  'B+': 3.5,
  B: 3,
  'C+': 2.5,
  C: 2,
  'D+': 1.5,
  D: 1,
  F: 0
}

// Initialize courses with data from localStorage (client-side only)
const courses = ref<Course[]>([])

// Load courses from localStorage on client-side
const loadCourses = () => {
  if (typeof window !== 'undefined') {
    const stored = JSON.parse(localStorage.getItem('courses') || '[]')
    courses.value = stored.map((course: Course) => ({
      id: nanoid(),
      name: course.name || '',
      credits: course.credits || '',
      grade: course.grade ? { value: course.grade.value, label: course.grade.value } : undefined
    }))
  }
}

// Save courses to localStorage
const saveCourses = () => {
  if (typeof window !== 'undefined') {
    const toStore = courses.value.map(({ id, ...rest }) => rest)
    localStorage.setItem('courses', JSON.stringify(toStore))
  }
}

// Watch for changes and save to localStorage
watch(courses, saveCourses, { deep: true })

// Computed statistics
const stats = computed(() => {
  const { creditsSum, pointsSum } = courses.value.reduce(
    (acc, { credits, grade }) => {
      const creditValue = parseArabicNumber(credits)

      // Only include in calculation if BOTH credits and grade are entered
      if (creditValue > 0 && grade?.value && gradeValues[grade.value] !== undefined) {
        acc.creditsSum += creditValue
        acc.pointsSum += creditValue * gradeValues[grade.value]
      }

      return acc
    },
    { creditsSum: 0, pointsSum: 0 }
  )

  const average = creditsSum ? pointsSum / creditsSum : 0
  return {
    gpa: +average.toFixed(5),
    approximateGpa: +average.toFixed(2),
    totalCredits: creditsSum,
    totalPoints: pointsSum
  }
})

// Individual computed refs for easier template access
const gpa = computed(() => stats.value.gpa)
const approximateGpa = computed(() => stats.value.approximateGpa)
const totalCredits = computed(() => stats.value.totalCredits)
const totalPoints = computed(() => stats.value.totalPoints)

// Course management functions
const addCourse = () => {
  courses.value.unshift({
    id: nanoid(),
    name: '',
    credits: '',
    grade: undefined
  })
}

const removeCourse = (id: string) => {
  const index = courses.value.findIndex((course) => course.id === id)
  if (index > -1) {
    courses.value.splice(index, 1)
  }
}

const updateCourseField = (id: string, field: 'name' | 'credits' | 'grade', value: any) => {
  const course = courses.value.find((c) => c.id === id)
  if (course) {
    course[field] = value
  }
}

// Import/Export functions
const exportCourses = async () => {
  try {
    await navigator.clipboard.writeText(JSON.stringify({ courses: courses.value }))
    toast.success('تم نسخ البيانات للحافظة')
  } catch (error) {
    toast.error('خطأ في تصدير البيانات')
  }
}

const importCourses = async () => {
  try {
    const clipboardText = await navigator.clipboard.readText()
    const parsed = JSON.parse(clipboardText)
    if (!Array.isArray(parsed.courses)) throw new Error()

    courses.value = parsed.courses.map((course: Course) => ({
      id: nanoid(),
      name: course.name || '',
      credits: course.credits || '',
      grade: course.grade ? { value: course.grade.value, label: course.grade.value } : undefined
    }))

    toast.success('تم استيراد البيانات بنجاح')
  } catch {
    toast.error('خطأ في استيراد البيانات')
  }
}

// Load courses on mount
onMounted(() => {
  loadCourses()
})
</script>

<style scoped>
* {
  margin: 0;
}
</style>
