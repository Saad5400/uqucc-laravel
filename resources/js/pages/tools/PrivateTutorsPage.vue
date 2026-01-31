<template>
  <DocsLayout>
    <PageHeader title="الخصوصيين" icon="solar:users-group-rounded-broken" />

    <div class="typography">
      <PrivateTutors :courses="courses" :tutors="tutors" />
    </div>
  </DocsLayout>
</template>

<script setup lang="ts">
import DocsLayout from '@/components/layout/DocsLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import PrivateTutors from '@/components/tools/PrivateTutors.vue'

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

defineProps<{
  courses: CourseWithTutors[]
  tutors: TutorWithCourses[]
}>()

defineOptions({
  layout: false
})
</script>
