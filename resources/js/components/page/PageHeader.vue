<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { Icon } from '@iconify/vue'
import {
  Breadcrumb,
  BreadcrumbList,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbSeparator
} from '@/components/ui/breadcrumb'

interface Breadcrumb {
  title: string
  path: string
}

interface Props {
  title: string
  icon?: string
  breadcrumbs?: Breadcrumb[]
}

const props = defineProps<Props>()
</script>

<template>
  <div class="space-y-6">
    <!-- Breadcrumbs -->
    <Breadcrumb v-if="breadcrumbs && breadcrumbs.length > 1" class="mb-2">
      <BreadcrumbList>
        <template v-for="(breadcrumb, index) in breadcrumbs" :key="index">
          <BreadcrumbItem>
            <BreadcrumbLink
              v-if="index !== breadcrumbs.length - 1"
              :href="breadcrumb.path"
              as-child
            >
              <Link :href="breadcrumb.path">
                {{ breadcrumb.title }}
              </Link>
            </BreadcrumbLink>
          </BreadcrumbItem>
          <BreadcrumbSeparator v-if="index < breadcrumbs.length - 2" />
        </template>
      </BreadcrumbList>
    </Breadcrumb>

    <!-- Page title with icon -->
    <div class="flex items-center gap-3">
      <Icon v-if="icon" :icon="icon" class="!size-8" />
      <h1 class="m-0 text-3xl font-semibold">{{ title }}</h1>
    </div>
  </div>
</template>
