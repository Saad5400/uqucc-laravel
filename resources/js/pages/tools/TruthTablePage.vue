<template>
    <SeoHead :seo="seo" />
    <DocsLayout>
        <PageHeader title="جدول الصواب" icon="solar:checklist-minimalistic-broken" />

        <!-- Rich content from database -->
        <div v-if="hasContent" class="typography mb-6">
            <RichContentRenderer :content="page?.html_content" />
        </div>

        <div class="typography">
            <Alert>
                <AlertDescription>
                    اكتب صيغة منطقية بأي طريقة تناسبك — كل هذه الصيغ تعطي نفس النتيجة:
                    <span dir="ltr" class="inline-block font-mono text-sm">p ∧ q → ¬r</span>
                    أو
                    <span dir="ltr" class="inline-block font-mono text-sm">p /\ q -> ~r</span>
                    أو
                    <span dir="ltr" class="inline-block font-mono text-sm">p and q => not r</span>
                </AlertDescription>
            </Alert>

            <div class="!mb-2 space-y-1">
                <Label for="formula">الصيغة المنطقية:</Label>
                <Input
                    id="formula"
                    v-model="formula"
                    type="text"
                    dir="ltr"
                    autocomplete="off"
                    spellcheck="false"
                    class="mt-2 mb-0 font-mono"
                    placeholder="p /\ q -> ~r"
                    :aria-invalid="errorMessage ? true : undefined"
                    :aria-describedby="errorMessage ? 'formula-error' : undefined"
                />
            </div>

            <p v-if="errorMessage" id="formula-error" class="!mb-2 text-xs text-destructive">
                {{ errorMessage }}
            </p>

            <!-- Empty state: teach by example -->
            <div v-if="!table && !errorMessage" class="!mb-4 space-y-2">
                <p class="text-muted-foreground">جرّب أحد الأمثلة:</p>
                <div class="flex flex-wrap gap-2">
                    <Button v-for="example in examples" :key="example" variant="outline" size="sm" @click="formula = example">
                        <span dir="ltr" class="font-mono">{{ example }}</span>
                    </Button>
                </div>
            </div>

            <template v-if="table">
                <p class="!mb-2 flex flex-wrap items-center gap-2">
                    <span class="text-muted-foreground">الصيغة:</span>
                    <span dir="ltr" class="inline-block rounded bg-muted px-2 py-0.5 font-mono">{{ table.formula }}</span>
                    <Badge v-if="table.is_tautology" variant="secondary">تحصيل حاصل — صادقة دائمًا</Badge>
                    <Badge v-else-if="table.is_contradiction" variant="destructive">تناقض — كاذبة دائمًا</Badge>
                </p>

                <div dir="ltr" class="overflow-x-auto rounded-lg border">
                    <table class="w-full border-collapse text-center font-mono text-sm">
                        <thead>
                            <tr class="border-b bg-muted/50">
                                <th
                                    v-for="(column, index) in table.columns"
                                    :key="column"
                                    class="border-e px-3 py-2 font-semibold whitespace-nowrap last:border-e-0"
                                    :class="{ 'bg-primary/10': index === table.columns.length - 1 }"
                                >
                                    {{ column }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, rowIndex) in table.rows" :key="rowIndex" class="border-b last:border-b-0">
                                <td
                                    v-for="(value, columnIndex) in row"
                                    :key="columnIndex"
                                    class="border-e px-3 py-1.5 last:border-e-0"
                                    :class="[
                                        value ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400',
                                        { 'bg-primary/10 font-bold': columnIndex === row.length - 1 },
                                    ]"
                                >
                                    {{ value ? 'T' : 'F' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- Syntax reference -->
            <h2>الرموز المقبولة</h2>
            <div dir="ltr" class="overflow-x-auto rounded-lg border">
                <table class="w-full border-collapse text-center text-sm">
                    <thead>
                        <tr class="border-b bg-muted/50">
                            <th class="border-e px-3 py-2 font-semibold">الرابط المنطقي</th>
                            <th class="px-3 py-2 font-semibold">الكتابات المقبولة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in syntaxReference" :key="row.name" class="border-b last:border-b-0">
                            <td class="border-e px-3 py-1.5 whitespace-nowrap">{{ row.name }}</td>
                            <td class="px-3 py-1.5 font-mono whitespace-nowrap">{{ row.forms }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </DocsLayout>
</template>

<script setup lang="ts">
import { generateTruthTable } from '@/actions/App/Http/Controllers/ToolController';
import DocsLayout from '@/components/layout/DocsLayout.vue';
import PageHeader from '@/components/page/PageHeader.vue';
import RichContentRenderer from '@/components/RichContentRenderer.vue';
import SeoHead, { type SeoData } from '@/components/SeoHead.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { postJson } from '@/lib/http';
import { ref, watch } from 'vue';

defineOptions({
    layout: false,
});

interface Props {
    page?: {
        html_content: any;
        title?: string;
    };
    hasContent?: boolean;
    seo: SeoData;
}

withDefaults(defineProps<Props>(), {
    hasContent: false,
});

interface TruthTableResult {
    formula: string;
    variables: string[];
    columns: string[];
    rows: boolean[][];
    is_tautology: boolean;
    is_contradiction: boolean;
}

const examples = ['p /\\ q -> ~r', 'p and q or not r', '(p -> q) <-> (~q -> ~p)', 'p ∧ ¬p'];

const syntaxReference = [
    { name: 'النفي (not)', forms: '¬p    ~p    !p    not p' },
    { name: 'العطف (and)', forms: 'p ∧ q    p /\\ q    p && q    p and q' },
    { name: 'الفصل (or)', forms: 'p ∨ q    p \\/ q    p || q    p or q' },
    { name: 'الشرط (implies)', forms: 'p → q    p -> q    p => q    p implies q' },
    { name: 'التكافؤ (iff)', forms: 'p ↔ q    p <-> q    p <=> q    p iff q' },
    { name: 'الثوابت (constants)', forms: '⊤  T  true    /    ⊥  F  false' },
];

const formula = ref('');
const table = ref<TruthTableResult | null>(null);
const errorMessage = ref<string | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;
let requestSequence = 0;

watch(formula, (value) => {
    clearTimeout(debounceTimer);

    if (!value.trim()) {
        table.value = null;
        errorMessage.value = null;
        return;
    }

    debounceTimer = setTimeout(() => generate(value.trim()), 350);
});

async function generate(value: string) {
    const sequence = ++requestSequence;

    try {
        const result = await postJson<TruthTableResult>(generateTruthTable.url(), { formula: value }, 'تعذّر توليد الجدول، حاول مرة أخرى');

        if (sequence === requestSequence) {
            table.value = result;
            errorMessage.value = null;
        }
    } catch (error) {
        if (sequence === requestSequence) {
            table.value = null;
            errorMessage.value = error instanceof Error ? error.message : 'تعذّر توليد الجدول، حاول مرة أخرى';
        }
    }
}
</script>
