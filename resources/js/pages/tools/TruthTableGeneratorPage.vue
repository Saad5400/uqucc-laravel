<template>
  <DocsLayout>
    <PageHeader title="Truth Table Generator" icon="mdi:table-pivot" />

    <div class="typography">
      <TruthTableGenerator />
    </div>
  </DocsLayout>
</template>

<script setup lang="ts">
import DocsLayout from '@/components/layout/DocsLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import TruthTableGenerator from '@/components/tools/TruthTableGenerator.vue'

defineOptions({
  layout: false
})
</script>
