<template>
  <div class="space-y-6">
    <Card>
      <CardHeader>
        <CardTitle>أدخل الصيغة المنطقية</CardTitle>
        <p class="text-sm text-muted-foreground">
          يدعم المحلل عدة صيغ للمُعاملات كما في أداة ستانفورد. جرّب كتابة القيم باستخدام أي من الصيغ
          التالية.
        </p>
      </CardHeader>
      <CardContent class="space-y-3">
        <div class="flex flex-wrap gap-2">
          <button
            v-for="sample in samples"
            :key="sample"
            type="button"
            class="rounded-md border px-3 py-1 text-sm transition hover:border-primary hover:text-primary"
            @click="() => (expression = sample)"
          >
            {{ sample }}
          </button>
        </div>

        <Input
          v-model="expression"
          dir="ltr"
          placeholder="مثال: p /\\ q -> ~r"
          class="font-mono text-base"
        />

        <Alert v-if="error" variant="destructive">
          <AlertDescription>{{ error }}</AlertDescription>
        </Alert>

        <div class="grid gap-3 md:grid-cols-2">
          <div>
            <h3 class="mb-2 text-sm font-semibold text-foreground/80">المعاملات (AND / OR / NOT)</h3>
            <div class="flex flex-wrap gap-2">
              <OperatorPill
                v-for="operator in basicOperators"
                :key="operator.label"
                :label="operator.label"
                :symbols="operator.symbols"
              />
            </div>
          </div>
          <div>
            <h3 class="mb-2 text-sm font-semibold text-foreground/80">المحتمِلات الإضافية</h3>
            <div class="flex flex-wrap gap-2">
              <OperatorPill label="الاستلزام (IMPLIES)" :symbols="['->', '=>', '→']" />
              <OperatorPill label="التكافؤ (IFF)" :symbols="['<->', '<=>', '↔']" />
              <OperatorPill label="XOR" :symbols="['xor', '^', '⊕']" />
              <OperatorPill label="الثوابت" :symbols="['T / ⊤', 'F / ⊥']" />
            </div>
          </div>
        </div>
      </CardContent>
    </Card>

    <Card>
      <CardHeader>
        <div class="flex items-center justify-between gap-3">
          <div>
            <CardTitle>جدول الحقيقة</CardTitle>
            <p v-if="tableData" class="text-sm text-muted-foreground">
              الصيغة بعد التطبيع: <span class="font-mono text-foreground">{{ tableData.normalized }}</span>
            </p>
          </div>
          <div v-if="tableData" class="rounded-md bg-muted px-3 py-1 text-sm font-mono text-muted-foreground">
            {{ tableData.rows.length }} صفوف
          </div>
        </div>
      </CardHeader>
      <CardContent>
        <p v-if="!expression.trim()" class="text-muted-foreground">
          اكتب صيغة منطقية لعرض جدول الحقيقة.
        </p>
        <p v-else-if="!tableData && !error" class="text-muted-foreground">يتم التحليل...</p>

        <div v-if="tableData" class="overflow-x-auto">
          <table class="min-w-full border-collapse text-center">
            <thead>
              <tr>
                <th
                  v-for="column in tableData.columns"
                  :key="column.label"
                  class="border border-border bg-muted/40 px-3 py-2 font-semibold text-foreground"
                >
                  <span class="font-mono">{{ column.label }}</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in tableData.rows" :key="index">
                <td
                  v-for="column in tableData.columns"
                  :key="column.label"
                  class="border border-border px-3 py-2 font-mono"
                  :class="column.label === tableData.columns.at(-1)?.label ? 'bg-muted/60 font-semibold' : ''"
                >
                  {{ row[column.label] ? 'T' : 'F' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  </div>
</template>

<script setup lang="ts">
import { defineComponent, h, ref, watchEffect, type PropType } from 'vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { generateTruthTable, type TruthTableResult } from '@/lib/truth-table'

const samples = [
  'p /\\ q -> ~r',
  '(p or q) <-> (q or p)',
  'not (p and q) -> (not p or not q)',
  '(p xor q) => (p or q)'
]

const basicOperators = [
  { label: 'AND', symbols: ['/\\', '&&', '∧', 'and'] },
  { label: 'OR', symbols: ['\\/', '||', '∨', 'or'] },
  { label: 'NOT', symbols: ['~', '!', '¬', 'not'] }
]

const expression = ref(samples[0])
const error = ref<string | null>(null)
const tableData = ref<TruthTableResult | null>(null)

watchEffect(() => {
  if (!expression.value.trim()) {
    error.value = null
    tableData.value = null
    return
  }

  try {
    tableData.value = generateTruthTable(expression.value)
    error.value = null
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'حدث خطأ غير متوقع.'
    tableData.value = null
  }
})
defineOptions({ name: 'TruthTableGenerator' })

const OperatorPill = defineComponent({
  name: 'OperatorPill',
  props: {
    label: { type: String, required: true },
    symbols: { type: Array as PropType<string[]>, required: true }
  },
  setup(props) {
    return () =>
      h('div', { class: 'flex items-center gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-xs' }, [
        h('span', { class: 'font-semibold text-foreground' }, props.label),
        h('span', { class: 'text-muted-foreground' }, '•'),
        h('span', { class: 'font-mono text-muted-foreground' }, props.symbols.join(' / '))
      ])
  }
})
</script>
