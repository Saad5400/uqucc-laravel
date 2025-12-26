<template>
  <DocsLayout>
    <PageHeader title="مولد جدول الحقيقة" icon="solar:table-bold-duotone" />

    <div class="typography space-y-6">
      <p class="text-muted-foreground">
        أدخل صيغة منطقية واحصل على جدول الحقيقة الخاص بها مع دعم لمعظم صيغ المعاملات
        الشائعة كما في أداة ستانفورد.
      </p>

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
