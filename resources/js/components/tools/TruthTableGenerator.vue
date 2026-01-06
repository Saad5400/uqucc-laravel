<template>
  <div>
    <!-- Input Section -->
    <div class="!mb-6 space-y-4">
      <div class="space-y-2">
        <Label for="formula">Enter Propositional Formula</Label>
        <Input
          id="formula"
          v-model="formula"
          placeholder="e.g., p /\ q -> ~r  or  p and q => not r"
          @keyup.enter="generateTable"
          class="font-mono"
        />
        <p class="text-sm text-muted-foreground">
          Operators: ∧ (and, /\, &&), ∨ (or, \/, ||), ¬ (not, ~, !), → (implies, ->, =>), ↔ (iff,
          <->), ⊤ (T, true), ⊥ (F, false)
        </p>
      </div>

      <div class="flex gap-2">
        <Button @click="generateTable" :disabled="loading || !formula.trim()">
          <Calculator class="!size-4" />
          Generate Truth Table
        </Button>
        <Button variant="secondary" @click="clearTable">
          <Trash class="!size-4" />
          Clear
        </Button>
      </div>

      <!-- Error Display -->
      <Alert v-if="error" variant="destructive">
        <AlertCircle class="!size-4" />
        <AlertTitle>Error</AlertTitle>
        <AlertDescription>{{ error }}</AlertDescription>
      </Alert>
    </div>

    <!-- Truth Table Display -->
    <div v-if="truthTable && truthTable.variables.length > 0" class="!mt-6">
      <div class="!mb-4 flex items-center justify-between">
        <h3 class="text-lg font-semibold !m-0">Truth Table</h3>
        <Button variant="outline" size="sm" @click="copyTable">
          <Copy class="!size-4" />
          Copy Table
        </Button>
      </div>

      <div class="overflow-x-auto">
        <table class="!w-full border-collapse">
          <thead>
            <tr class="bg-muted">
              <th
                v-for="variable in truthTable.variables"
                :key="variable"
                class="border border-border p-3 text-center font-mono font-semibold"
              >
                {{ variable }}
              </th>
              <th class="border border-border p-3 text-center font-semibold bg-primary/10">
                Result
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(row, index) in truthTable.table"
              :key="index"
              :class="{ 'bg-muted/50': index % 2 === 0 }"
            >
              <td
                v-for="variable in truthTable.variables"
                :key="variable"
                class="border border-border p-3 text-center font-mono"
              >
                <span :class="row[variable] ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                  {{ row[variable] ? 'T' : 'F' }}
                </span>
              </td>
              <td class="border border-border p-3 text-center font-mono font-semibold bg-primary/5">
                <span :class="row.result ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                  {{ row.result ? 'T' : 'F' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Analysis -->
      <div class="!mt-4 grid gap-4 md:grid-cols-3">
        <Card>
          <CardHeader size="sm">
            <CardTitle class="text-base">Variables</CardTitle>
          </CardHeader>
          <CardContent size="sm">
            <p class="text-2xl font-bold">{{ truthTable.variables.length }}</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader size="sm">
            <CardTitle class="text-base">Total Rows</CardTitle>
          </CardHeader>
          <CardContent size="sm">
            <p class="text-2xl font-bold">{{ truthTable.table.length }}</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader size="sm">
            <CardTitle class="text-base">Formula Type</CardTitle>
          </CardHeader>
          <CardContent size="sm">
            <p class="text-lg font-semibold">{{ formulaType }}</p>
          </CardContent>
        </Card>
      </div>
    </div>

    <!-- Empty State -->
    <div
      v-else-if="!loading && formula.trim()"
      class="!mt-6 rounded-lg border border-dashed border-border p-8 text-center"
    >
      <p class="text-muted-foreground">Click "Generate Truth Table" to see the results</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { Calculator, Trash, Copy, AlertCircle } from 'lucide-vue-next'
import { toast } from 'vue-sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'
import axios from 'axios'

interface TruthTableRow {
  [key: string]: boolean
  result: boolean
}

interface TruthTable {
  variables: string[]
  table: TruthTableRow[]
}

const formula = ref('')
const truthTable = ref<TruthTable | null>(null)
const loading = ref(false)
const error = ref('')

const generateTable = async () => {
  if (!formula.value.trim()) {
    error.value = 'Please enter a formula'
    return
  }

  loading.value = true
  error.value = ''

  try {
    const response = await axios.post('/api/truth-table/generate', {
      formula: formula.value
    })

    if (response.data.success) {
      truthTable.value = response.data.data
      toast.success('Truth table generated successfully')
    } else {
      error.value = response.data.error || 'Failed to generate truth table'
      truthTable.value = null
    }
  } catch (err: any) {
    if (err.response?.data?.error) {
      error.value = err.response.data.error
    } else {
      error.value = 'An error occurred while generating the truth table'
    }
    truthTable.value = null
  } finally {
    loading.value = false
  }
}

const clearTable = () => {
  formula.value = ''
  truthTable.value = null
  error.value = ''
}

const copyTable = async () => {
  if (!truthTable.value) return

  try {
    const header = [...truthTable.value.variables, 'Result'].join('\t')
    const rows = truthTable.value.table.map((row) => {
      const values = truthTable.value!.variables.map((v) => (row[v] ? 'T' : 'F'))
      values.push(row.result ? 'T' : 'F')
      return values.join('\t')
    })

    const tableText = [header, ...rows].join('\n')
    await navigator.clipboard.writeText(tableText)
    toast.success('Table copied to clipboard')
  } catch {
    toast.error('Failed to copy table')
  }
}

const formulaType = computed(() => {
  if (!truthTable.value) return 'N/A'

  const allTrue = truthTable.value.table.every((row) => row.result)
  const allFalse = truthTable.value.table.every((row) => !row.result)

  if (allTrue) return 'Tautology ✓'
  if (allFalse) return 'Contradiction ✗'
  return 'Contingent'
})
</script>

<style scoped>
* {
  margin: 0;
}

table {
  border-spacing: 0;
}
</style>
