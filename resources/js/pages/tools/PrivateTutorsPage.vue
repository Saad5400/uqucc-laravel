<template>
  <DocsLayout>
    <PageHeader title="الخصوصيين" icon="solar:users-group-rounded-broken" />

    <!-- Rich content from database -->
    <div v-if="hasContent" class="typography mb-6">
      <RichContentRenderer :content="page.html_content" />
    </div>

    <div class="typography">
      <div class="text-sm typography">
        <p>
          <b> 🚨 تنويه مهم: </b>
          نود أن نوضح أننا غير مسؤولين عن الخصوصيين المذكورين أدناه أو عن أي تعامل يتم معهم،
          وجميعهم غير تابعين للكلية أو الجامعة رسميًا.
        </p>

        <ul class="mt-0">
          <li>لا يوجد أي خصوصي يطلب عربون أو دفع مقابل مشاهدة الشرح التجريبي.</li>
          <li>مافي خصوصي يطلب عربون مقابل مشاهدة الشرح التجريبي.</li>
          <li>في حال واجهتم أي مشكلة مع أحد الخصوصيين، يرجى التواصل معنا مباشرة.</li>
        </ul>
      </div>

      <!-- Tabs -->
      <div class="flex gap-2 mb-4">
        <Button :variant="activeTab === 'courses' ? 'default' : 'ghost'" @click="activeTab = 'courses'">
          حسب المادة
        </Button>
        <Button :variant="activeTab === 'tutors' ? 'default' : 'ghost'" @click="activeTab = 'tutors'">
          حسب الخصوصي
        </Button>
      </div>

      <!-- Search Bar -->
      <div class="relative mb-6">
        <Search class="absolute right-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
        <Input
          v-model="searchQuery"
          :placeholder="activeTab === 'courses' ? 'ابحث عن مادة...' : 'ابحث عن خصوصي...'"
          class="pr-10"
        />
      </div>

      <!-- Content -->
      <div v-auto-animate>
        <!-- By Course Tab -->
        <template v-if="activeTab === 'courses'">
          <div v-if="filteredCourses.length === 0" class="text-center py-8 text-muted-foreground">
            لا توجد نتائج
          </div>
          <div v-else class="space-y-4">
            <Card v-for="course in filteredCourses" :key="course.id" size="sm">
              <CardHeader size="sm">
                <CardTitle class="text-lg flex items-center gap-2">
                  <GraduationCap class="size-5" />
                  {{ course.name }}
                </CardTitle>
              </CardHeader>
              <CardContent size="sm">
                <div v-if="course.tutors.length === 0" class="text-muted-foreground text-sm">
                  لا يوجد خصوصيين لهذه المادة
                </div>
                <ul v-else class="space-y-2 list-none p-0 m-0">
                  <li v-for="tutor in course.tutors" :key="tutor.id" class="flex items-center gap-2">
                    <User class="size-4 text-muted-foreground shrink-0" />
                    <a
                      v-if="tutor.url"
                      :href="tutor.url"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="text-primary hover:underline"
                    >
                      {{ tutor.name }}
                    </a>
                    <span v-else>{{ tutor.name }}</span>
                  </li>
                </ul>
              </CardContent>
            </Card>
          </div>
        </template>

        <!-- By Tutor Tab -->
        <template v-else>
          <div v-if="filteredTutors.length === 0" class="text-center py-8 text-muted-foreground">
            لا توجد نتائج
          </div>
          <div v-else class="space-y-4">
            <Card v-for="tutor in filteredTutors" :key="tutor.id" size="sm">
              <CardHeader size="sm">
                <CardTitle class="text-lg flex items-center gap-2">
                  <User class="size-5" />
                  <a
                    v-if="tutor.url"
                    :href="tutor.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-primary hover:underline"
                  >
                    {{ tutor.name }}
                  </a>
                  <span v-else>{{ tutor.name }}</span>
                </CardTitle>
              </CardHeader>
              <CardContent size="sm">
                <div v-if="tutor.courses.length === 0" class="text-muted-foreground text-sm">
                  لا توجد مواد لهذا الخصوصي
                </div>
                <ul v-else class="space-y-2 list-none p-0 m-0">
                  <li v-for="course in tutor.courses" :key="course.id" class="flex items-center gap-2">
                    <GraduationCap class="size-4 text-muted-foreground shrink-0" />
                    <span>{{ course.name }}</span>
                  </li>
                </ul>
              </CardContent>
            </Card>
          </div>
        </template>
      </div>
    </div>
  </DocsLayout>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { vAutoAnimate } from '@formkit/auto-animate/vue'
import { Search, User, GraduationCap } from 'lucide-vue-next'
import DocsLayout from '@/components/layout/DocsLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import RichContentRenderer from '@/components/RichContentRenderer.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card'

defineOptions({
  layout: false
})

interface Tutor {
  id: number
  name: string
  url: string | null
}

interface Course {
  id: number
  name: string
}

interface CourseWithTutors extends Course {
  tutors: Tutor[]
}

interface TutorWithCourses extends Tutor {
  courses: Course[]
}

interface Props {
  courses: CourseWithTutors[]
  tutors: TutorWithCourses[]
  page?: {
    html_content: any
    title?: string
  }
  hasContent?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  hasContent: false
})

const activeTab = ref<'courses' | 'tutors'>('courses')
const searchQuery = ref('')

/**
 * Normalize Arabic text for better search matching
 * - Removes diacritics (tashkeel)
 * - Normalizes different forms of similar letters
 */
function normalizeArabic(text: string): string {
  return text
    .toLowerCase()
    // Remove Arabic diacritics (tashkeel)
    .replace(/[\u064B-\u065F\u0670]/g, '')
    // Normalize alef variations to plain alef
    .replace(/[\u0622\u0623\u0625\u0627\u0671]/g, '\u0627')
    // Normalize teh marbuta to heh
    .replace(/\u0629/g, '\u0647')
    // Normalize alef maksura to yeh
    .replace(/\u0649/g, '\u064A')
    // Normalize waw with hamza to waw
    .replace(/\u0624/g, '\u0648')
    // Normalize yeh with hamza to yeh
    .replace(/\u0626/g, '\u064A')
    // Remove tatweel (kashida)
    .replace(/\u0640/g, '')
    // Remove extra spaces
    .replace(/\s+/g, ' ')
    .trim()
}

/**
 * Calculate similarity score between two strings (0 to 1)
 * Uses a combination of techniques for Arabic fuzzy matching
 */
function calculateSimilarity(text: string, query: string): number {
  if (text === query) return 1
  if (text.includes(query)) return 0.9
  if (query.length === 0) return 1

  // Check if all characters of query exist in text (in order)
  let queryIdx = 0
  for (let i = 0; i < text.length && queryIdx < query.length; i++) {
    if (text[i] === query[queryIdx]) {
      queryIdx++
    }
  }
  if (queryIdx === query.length) {
    return 0.7 // All characters found in sequence
  }

  // Calculate character overlap ratio
  const queryChars = new Set(query.split(''))
  const textChars = new Set(text.split(''))
  let matchCount = 0
  for (const char of queryChars) {
    if (textChars.has(char)) matchCount++
  }
  const overlapRatio = matchCount / queryChars.size

  // Levenshtein distance for short queries
  if (query.length <= 10) {
    const distance = levenshteinDistance(text.slice(0, query.length + 5), query)
    const maxLen = Math.max(text.length, query.length)
    const distanceScore = 1 - distance / maxLen
    return Math.max(overlapRatio * 0.5, distanceScore)
  }

  return overlapRatio * 0.5
}

/**
 * Levenshtein distance calculation
 */
function levenshteinDistance(a: string, b: string): number {
  const matrix: number[][] = []

  for (let i = 0; i <= b.length; i++) {
    matrix[i] = [i]
  }
  for (let j = 0; j <= a.length; j++) {
    matrix[0][j] = j
  }

  for (let i = 1; i <= b.length; i++) {
    for (let j = 1; j <= a.length; j++) {
      if (b[i - 1] === a[j - 1]) {
        matrix[i][j] = matrix[i - 1][j - 1]
      } else {
        matrix[i][j] = Math.min(
          matrix[i - 1][j - 1] + 1, // substitution
          matrix[i][j - 1] + 1, // insertion
          matrix[i - 1][j] + 1 // deletion
        )
      }
    }
  }

  return matrix[b.length][a.length]
}

/**
 * Check if text matches the search query with fuzzy matching
 * Returns true if similarity is above threshold
 */
function matchesSearch(text: string, query: string): boolean {
  if (!query) return true

  const normalizedText = normalizeArabic(text)
  const normalizedQuery = normalizeArabic(query)

  // Split query into words
  const queryWords = normalizedQuery.split(' ').filter((w) => w.length > 0)

  // For each query word, check if it fuzzy matches any part of text
  return queryWords.every((word) => {
    // Exact or substring match
    if (normalizedText.includes(word)) return true

    // Check each word in the text for fuzzy match
    const textWords = normalizedText.split(' ')
    return textWords.some((textWord) => {
      const similarity = calculateSimilarity(textWord, word)
      // Allow match if similarity is above 0.6 (60%)
      return similarity >= 0.6
    })
  })
}

const filteredCourses = computed(() => {
  if (!searchQuery.value) return props.courses

  // Search only by course name in courses tab
  return props.courses.filter((course) => matchesSearch(course.name, searchQuery.value))
})

const filteredTutors = computed(() => {
  if (!searchQuery.value) return props.tutors

  // Search only by tutor name in tutors tab
  return props.tutors.filter((tutor) => matchesSearch(tutor.name, searchQuery.value))
})
</script>
