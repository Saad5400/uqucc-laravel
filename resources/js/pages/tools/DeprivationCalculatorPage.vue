<template>
  <DocsLayout>
    <PageHeader title="حاسبة الحرمان" icon="solar:danger-triangle-broken" />

    <div class="typography">
      <DeprivationCalculator />
    </div>
  </DocsLayout>
</template>

<script setup lang="ts">
import DocsLayout from '@/components/layout/DocsLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import DeprivationCalculator from '@/components/tools/DeprivationCalculator.vue'

defineOptions({
  layout: false
})
</script>
