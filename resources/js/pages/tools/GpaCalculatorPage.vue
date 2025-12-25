<template>
  <DocsLayout>
    <PageHeader title="حاسبة المعدل" icon="solar:calculator-broken" />

    <div class="typography">
      <GpaCalculator />
    </div>
  </DocsLayout>
</template>

<script setup lang="ts">
import DocsLayout from '@/components/layout/DocsLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import GpaCalculator from '@/components/tools/GpaCalculator.vue'

defineOptions({
  layout: false
})
</script>
