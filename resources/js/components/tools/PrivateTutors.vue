<template>
  <div>
      <div class="text-sm typography">
          <p>
              <b>
                  🚨 تنويه مهم:
              </b>
              نود أن نوضح أننا غير مسؤولين عن الخصوصيين المذكورين أدناه أو عن أي تعامل يتم معهم، وجميعهم غير تابعين للكلية أو الجامعة رسميًا.
          </p>

          <ul class="mt-0">
              <li>
                  لا يوجد أي خصوصي يطلب عربون أو دفع مقابل مشاهدة الشرح التجريبي.
              </li>
              <li>
                  مافي خصوصي يطلب عربون مقابل مشاهدة الشرح التجريبي.
              </li>
              <li>
                  في حال واجهتم أي مشكلة مع أحد الخصوصيين، يرجى التواصل معنا مباشرة.
              </li>
          </ul>
      </div>

    <!-- Tabs -->
    <div class="flex gap-2 mb-4">
      <Button
        :variant="activeTab === 'courses' ? 'default' : 'ghost'"
        @click="activeTab = 'courses'"
      >
        حسب المادة
      </Button>
      <Button
        :variant="activeTab === 'tutors' ? 'default' : 'ghost'"
        @click="activeTab = 'tutors'"
      >
        حسب الخصوصي
      </Button>
    </div>

    <!-- Search Bar -->
    <div class="relative mb-6">
      <Search class="absolute right-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
      <Input
        v-model="searchQuery"
        :placeholder="activeTab === 'courses' ? 'ابحث عن مادة أو خصوصي...' : 'ابحث عن خصوصي أو مادة...'"
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
                <li
                  v-for="tutor in course.tutors"
                  :key="tutor.id"
                  class="flex items-center gap-2"
                >
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
                <li
                  v-for="course in tutor.courses"
                  :key="course.id"
                  class="flex items-center gap-2"
                >
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
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { vAutoAnimate } from '@formkit/auto-animate/vue'
import { Search, User, GraduationCap } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card'

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

const props = defineProps<{
  courses: CourseWithTutors[]
  tutors: TutorWithCourses[]
}>()

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
    .replace(/[\u0622\u0623\u0625\u0627]/g, '\u0627')
    // Normalize teh marbuta to heh
    .replace(/\u0629/g, '\u0647')
    // Normalize alef maksura to yeh
    .replace(/\u0649/g, '\u064A')
    // Remove extra spaces
    .replace(/\s+/g, ' ')
    .trim()
}

/**
 * Check if text matches the search query
 * Uses fuzzy matching for better Arabic search experience
 */
function matchesSearch(text: string, query: string): boolean {
  if (!query) return true

  const normalizedText = normalizeArabic(text)
  const normalizedQuery = normalizeArabic(query)

  // Split query into words and check if all words are found
  const queryWords = normalizedQuery.split(' ').filter(w => w.length > 0)

  return queryWords.every(word => normalizedText.includes(word))
}

const filteredCourses = computed(() => {
  if (!searchQuery.value) return props.courses

  return props.courses
    .map(course => {
      // Check if course name matches
      const courseMatches = matchesSearch(course.name, searchQuery.value)

      // Filter tutors that match
      const matchingTutors = course.tutors.filter(tutor =>
        matchesSearch(tutor.name, searchQuery.value)
      )

      // Include course if course name matches OR any tutor matches
      if (courseMatches || matchingTutors.length > 0) {
        return {
          ...course,
          tutors: courseMatches ? course.tutors : matchingTutors
        }
      }

      return null
    })
    .filter((course): course is CourseWithTutors => course !== null)
})

const filteredTutors = computed(() => {
  if (!searchQuery.value) return props.tutors

  return props.tutors
    .map(tutor => {
      // Check if tutor name matches
      const tutorMatches = matchesSearch(tutor.name, searchQuery.value)

      // Filter courses that match
      const matchingCourses = tutor.courses.filter(course =>
        matchesSearch(course.name, searchQuery.value)
      )

      // Include tutor if tutor name matches OR any course matches
      if (tutorMatches || matchingCourses.length > 0) {
        return {
          ...tutor,
          courses: tutorMatches ? tutor.courses : matchingCourses
        }
      }

      return null
    })
    .filter((tutor): tutor is TutorWithCourses => tutor !== null)
})
</script>
