<template>
  <DocsLayout>
    <PageHeader title="حاسبة التحويل الداخلي" />

    <div class="typography">
      <TransferCalculator />
    </div>
  </DocsLayout>
</template>

<script setup lang="ts">
import DocsLayout from '@/components/layout/DocsLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import TransferCalculator from '@/components/tools/TransferCalculator.vue'

defineOptions({
  layout: false
})
</script>
