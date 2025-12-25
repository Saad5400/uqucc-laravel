<template>
  <DocsLayout>
    <PageHeader title="المكافأة القادمة" icon="solar:wallet-money-broken" />

    <div class="typography">
      <NextReward />

      <Alert>
        <AlertDescription>
          في حال مصادفة التاريخ يوم <strong>جمعة</strong> يتم إيداع المكافأة يوم
          <strong>الخميس</strong><br />
          في حال مصادفة التاريخ يوم <strong>السبت</strong> يتم إيداع المكافأة يوم
          <strong>الأحد</strong>
        </AlertDescription>
      </Alert>
    </div>
  </DocsLayout>
</template>

<script setup lang="ts">
import DocsLayout from '@/components/layout/DocsLayout.vue'
import PageHeader from '@/components/page/PageHeader.vue'
import NextReward from '@/components/tools/NextReward.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'

defineOptions({
  layout: false
})
</script>
