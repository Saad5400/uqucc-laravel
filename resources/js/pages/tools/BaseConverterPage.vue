<template>
    <SeoHead :seo="seo" />
    <DocsLayout>
        <PageHeader title="تحويل الأعداد" icon="solar:code-square-broken" />

        <!-- Rich content from database -->
        <div v-if="hasContent" class="typography mb-6">
            <RichContentRenderer :content="page?.html_content" />
        </div>

        <div class="typography">
            <Alert>
                <AlertDescription>
                    اكتب العدد واختر الأساسين، وستظهر النتيجة مع خطوات الحل كاملة. تقبل الأداة الكسور مثل
                    <span dir="ltr" class="inline-block font-mono text-sm">13.375</span>
                    والأرقام الست عشرية مثل
                    <span dir="ltr" class="inline-block font-mono text-sm">2AF</span>
                    وأي أساس بين ٢ و ٣٦.
                </AlertDescription>
            </Alert>

            <div class="!mb-4 space-y-4">
                <div class="space-y-1">
                    <Label for="number">العدد:</Label>
                    <Input
                        id="number"
                        v-model="numberInput"
                        type="text"
                        dir="ltr"
                        autocomplete="off"
                        spellcheck="false"
                        class="mt-2 mb-0 font-mono tabular-nums"
                        placeholder="2AF"
                        :aria-invalid="errorMessage ? true : undefined"
                        :aria-describedby="errorMessage ? 'number-error' : undefined"
                    />
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-40 flex-1 space-y-1">
                        <Label for="from-base">من الأساس:</Label>
                        <Select :model-value="String(fromBase)" @update:model-value="fromBase = Number($event)">
                            <SelectTrigger id="from-base" class="mt-2 w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="base in baseOptions" :key="base.value" :value="String(base.value)">
                                    {{ base.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <Button variant="outline" size="icon" class="mb-0.5" title="بدّل بين الأساسين" aria-label="بدّل بين الأساسين" @click="swapBases">
                        <Icon icon="solar:transfer-horizontal-broken" class="size-4" />
                    </Button>

                    <div class="min-w-40 flex-1 space-y-1">
                        <Label for="to-base">إلى الأساس:</Label>
                        <Select :model-value="String(toBase)" @update:model-value="toBase = Number($event)">
                            <SelectTrigger id="to-base" class="mt-2 w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="base in baseOptions" :key="base.value" :value="String(base.value)">
                                    {{ base.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            </div>

            <p v-if="errorMessage" id="number-error" class="!mb-2 text-xs text-destructive">
                {{ errorMessage }}
            </p>

            <!-- Empty state: teach by example -->
            <div v-if="!conversion && !errorMessage" class="!mb-4 space-y-2">
                <p class="text-muted-foreground">جرّب أحد الأمثلة:</p>
                <div class="flex flex-wrap gap-2">
                    <Button v-for="example in examples" :key="example.label" variant="outline" size="sm" @click="applyExample(example)">
                        {{ example.label }}
                    </Button>
                </div>
            </div>

            <template v-if="conversion">
                <div class="!mb-6 rounded-lg border bg-muted/40 p-4">
                    <p dir="ltr" class="!my-0 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 font-mono text-xl break-all tabular-nums">
                        <span>
                            {{ conversion.input }}<sub class="text-muted-foreground">{{ conversion.from_base }}</sub>
                        </span>
                        <span class="text-muted-foreground">=</span>
                        <span class="font-bold text-primary">
                            {{ conversion.result }}<sub class="font-normal text-muted-foreground">{{ conversion.to_base }}</sub>
                        </span>
                    </p>

                    <p v-if="showDecimal" class="!mt-2 !mb-0 text-center text-sm text-muted-foreground">
                        بالنظام العشري: <span dir="ltr" class="font-mono tabular-nums">{{ conversion.decimal }}</span>
                    </p>

                    <p v-if="conversion.is_approximate" class="!mt-2 !mb-0 text-center text-sm text-muted-foreground">
                        قيمة تقريبية — الكسر لا ينتهي في هذا الأساس، فاكتفينا بأول الأرقام.
                    </p>
                </div>

                <h2>خطوات الحل</h2>

                <div class="!mb-6 space-y-5">
                    <div v-for="(step, index) in conversion.steps" :key="index" class="space-y-2">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-sm font-bold text-primary tabular-nums"
                            >
                                {{ arabicDigits(index + 1) }}
                            </span>
                            <h3 class="!my-0 text-base font-semibold">{{ step.title }}</h3>
                        </div>

                        <p v-if="step.note" class="!my-0 ps-10 text-sm text-muted-foreground">{{ step.note }}</p>

                        <div dir="ltr" class="ms-10 overflow-x-auto rounded-lg border bg-muted/40 p-3">
                            <div
                                v-for="(line, lineIndex) in step.lines"
                                :key="lineIndex"
                                class="font-mono text-sm leading-7 whitespace-pre tabular-nums"
                            >
                                {{ line }}
                            </div>
                        </div>

                        <p v-if="step.result" class="!my-0 ps-10 text-sm font-medium text-primary">{{ step.result }}</p>
                    </div>
                </div>
            </template>

            <!-- Reference -->
            <h2>الأنظمة الشائعة</h2>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full border-collapse text-start text-sm">
                    <thead>
                        <tr class="border-b bg-muted/50">
                            <th class="border-e px-3 py-2 text-start font-semibold">النظام</th>
                            <th class="border-e px-3 py-2 text-start font-semibold">الأساس</th>
                            <th class="px-3 py-2 text-start font-semibold">أرقامه</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="system in commonSystems" :key="system.base" class="border-b last:border-b-0">
                            <td class="border-e px-3 py-1.5 whitespace-nowrap">{{ system.name }}</td>
                            <td class="border-e px-3 py-1.5 tabular-nums">{{ arabicDigits(system.base) }}</td>
                            <td dir="ltr" class="px-3 py-1.5 text-start font-mono whitespace-nowrap">{{ system.digits }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-sm text-muted-foreground">
                في الأساسات الأكبر من عشرة تُستخدم الحروف بعد الأرقام:
                <span dir="ltr" class="font-mono">A = 10</span>، <span dir="ltr" class="font-mono">B = 11</span>، وهكذا حتى
                <span dir="ltr" class="font-mono">Z = 35</span>.
            </p>
        </div>
    </DocsLayout>
</template>

<script setup lang="ts">
import { convertBase } from '@/actions/App/Http/Controllers/ToolController';
import DocsLayout from '@/components/layout/DocsLayout.vue';
import PageHeader from '@/components/page/PageHeader.vue';
import RichContentRenderer from '@/components/RichContentRenderer.vue';
import SeoHead, { type SeoData } from '@/components/SeoHead.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { arabicDigits } from '@/lib/arabic';
import { postJson } from '@/lib/http';
import { Icon } from '@iconify/vue';
import { computed, ref, watch } from 'vue';

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

interface ConversionStep {
    title: string;
    lines: string[];
    note: string | null;
    result: string | null;
}

interface ConversionResult {
    input: string;
    from_base: number;
    to_base: number;
    result: string;
    decimal: string;
    is_approximate: boolean;
    summary: string;
    steps: ConversionStep[];
}

interface Example {
    label: string;
    number: string;
    from: number;
    to: number;
}

/** Bases with a name students actually use; every other base is «الأساس N». */
const namedBases: Record<number, string> = {
    2: 'ثنائي',
    8: 'ثماني',
    10: 'عشري',
    16: 'ست عشري',
};

const baseOptions = Array.from({ length: 35 }, (_, index) => {
    const value = index + 2;
    const name = namedBases[value];

    return { value, label: name ? `${name} (${value})` : `الأساس ${value}` };
});

const commonSystems = [
    { name: 'ثنائي (binary)', base: 2, digits: '0 1' },
    { name: 'ثماني (octal)', base: 8, digits: '0 1 2 3 4 5 6 7' },
    { name: 'عشري (decimal)', base: 10, digits: '0 1 2 3 4 5 6 7 8 9' },
    { name: 'ست عشري (hexadecimal)', base: 16, digits: '0-9 A B C D E F' },
];

const examples: Example[] = [
    { label: '٢٥٥ من العشري إلى الثنائي', number: '255', from: 10, to: 2 },
    { label: '2AF من الست عشري إلى الثنائي', number: '2AF', from: 16, to: 2 },
    { label: '١٣٫٣٧٥ من العشري إلى الثنائي', number: '13.375', from: 10, to: 2 },
    { label: '١١٠١١٠ من الثنائي إلى العشري', number: '110110', from: 2, to: 10 },
    { label: '٧٥٥ من الثماني إلى الست عشري', number: '755', from: 8, to: 16 },
];

const numberInput = ref('');
const fromBase = ref(10);
const toBase = ref(2);
const conversion = ref<ConversionResult | null>(null);
const errorMessage = ref<string | null>(null);

/** The decimal value is worth repeating only when it is neither side of the answer. */
const showDecimal = computed(() => conversion.value !== null && conversion.value.from_base !== 10 && conversion.value.to_base !== 10);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;
let requestSequence = 0;

watch([numberInput, fromBase, toBase], () => {
    clearTimeout(debounceTimer);

    if (!numberInput.value.trim()) {
        conversion.value = null;
        errorMessage.value = null;
        return;
    }

    debounceTimer = setTimeout(() => convert(numberInput.value.trim(), fromBase.value, toBase.value), 300);
});

function applyExample(example: Example) {
    fromBase.value = example.from;
    toBase.value = example.to;
    numberInput.value = example.number;
}

function swapBases() {
    [fromBase.value, toBase.value] = [toBase.value, fromBase.value];
}

async function convert(value: string, from: number, to: number) {
    const sequence = ++requestSequence;

    try {
        const result = await postJson<ConversionResult>(
            convertBase.url(),
            { number: value, from_base: from, to_base: to },
            'تعذّر تحويل العدد، حاول مرة أخرى',
        );

        if (sequence === requestSequence) {
            conversion.value = result;
            errorMessage.value = null;
        }
    } catch (error) {
        if (sequence === requestSequence) {
            conversion.value = null;
            errorMessage.value = error instanceof Error ? error.message : 'تعذّر تحويل العدد، حاول مرة أخرى';
        }
    }
}
</script>
