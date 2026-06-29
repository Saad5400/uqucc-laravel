<template>
    <SeoHead :seo="seo" />
    <DocsLayout>
        <PageHeader title="حاسبة المعدل" icon="solar:calculator-broken" />

        <!-- Rich content from database -->
        <div v-if="hasContent" class="typography mb-6">
            <RichContentRenderer :content="page.html_content" />
        </div>

        <div class="typography">
            <div v-auto-animate>
                <div v-if="totalCredits" class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <!-- Real GPA -->
                    <Card size="sm">
                        <CardHeader size="sm">
                            <CardTitle class="text-lg">المعدل الدقيق</CardTitle>
                        </CardHeader>
                        <CardContent size="sm" class="text-end text-2xl font-bold">
                            {{ gpa }}
                        </CardContent>
                    </Card>

                    <!-- Approximate GPA -->
                    <Card size="sm">
                        <CardHeader size="sm">
                            <CardTitle class="text-lg">المعدل التقريبي</CardTitle>
                        </CardHeader>
                        <CardContent size="sm" class="text-end text-2xl font-bold">
                            {{ approximateGpa }}
                        </CardContent>
                    </Card>

                    <!-- Total Credits -->
                    <Card size="sm">
                        <CardHeader size="sm">
                            <CardTitle class="text-lg">إجمالي الساعات</CardTitle>
                        </CardHeader>
                        <CardContent size="sm" class="text-end text-2xl font-bold">
                            {{ totalCredits }}
                        </CardContent>
                    </Card>

                    <!-- Total Points -->
                    <Card size="sm">
                        <CardHeader size="sm">
                            <CardTitle class="text-lg">إجمالي النقاط</CardTitle>
                        </CardHeader>
                        <CardContent size="sm" class="text-end text-2xl font-bold">
                            {{ totalPoints }}
                        </CardContent>
                    </Card>
                </div>

                <p v-else class="!mb-2 text-muted-foreground">املأ البيانات لحساب المعدل</p>
            </div>

            <div class="!my-4 flex flex-col gap-2">
                <div class="flex items-center justify-between gap-2 overflow-x-auto">
                    <Button @click="addCourse" class="flex-1">
                        إضافة مقرر
                        <Plus />
                    </Button>
                    <div class="flex gap-2">
                        <Button variant="secondary" @click="exportCourses" class="flex-1">
                            تصدير البيانات
                            <FileDown />
                        </Button>
                        <Button variant="secondary" @click="importCourses" class="flex-1">
                            استيراد البيانات
                            <FileUp />
                        </Button>
                    </div>
                </div>

                <Button variant="outline" class="w-full" :disabled="isParsingTranscript" @click="openTranscriptPicker">
                    {{ isParsingTranscript ? 'جارٍ استخراج البيانات...' : 'استيراد من السجل الأكاديمي' }}
                    <GraduationCap />
                </Button>

                <input ref="transcriptInput" type="file" accept="application/pdf,.pdf" class="hidden" @change="handleTranscriptUpload" />
            </div>

            <div v-auto-animate class="!space-y-2">
                <div v-for="course in courses" :key="course.id" class="my-0 flex gap-2">
                    <Button class="!size-9" aria-label="حذف" variant="destructive" @click="removeCourse(course.id)">
                        <Trash />
                    </Button>
                    <Input v-model="course.name" placeholder="اسم المقرر" />
                    <Input v-model="course.credits" placeholder="الساعات" class="w-42" />
                    <Select
                        :model-value="course.grade?.value"
                        @update:model-value="(val) => updateCourseField(course.id, 'grade', { value: val, label: val })"
                    >
                        <SelectTrigger class="w-42">
                            <SelectValue placeholder="التقدير" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="grade in Object.keys(gradeValues)" :key="grade" :value="grade">
                                {{ grade }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>
        </div>
    </DocsLayout>
</template>

<script setup lang="ts">
import DocsLayout from '@/components/layout/DocsLayout.vue';
import PageHeader from '@/components/page/PageHeader.vue';
import RichContentRenderer from '@/components/RichContentRenderer.vue';
import SeoHead, { type SeoData } from '@/components/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { deserializeCourses, serializeCourses, type PortableCourse } from '@/lib/calculators/gpaSerialization';
import { transcriptToCourses } from '@/lib/transcript/transcriptToCourses';
import { vAutoAnimate } from '@formkit/auto-animate/vue';
import { FileDown, FileUp, GraduationCap, Plus, Trash } from 'lucide-vue-next';
import { nanoid } from 'nanoid';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

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

interface Course {
    id: string;
    name: string;
    credits: string;
    grade?: {
        value: string;
        label: string;
    };
}

// Convert Arabic-Indic digits and separators to a JS number
const parseArabicNumber = (text = '') =>
    parseFloat(
        text
            .trim()
            .replace(/[٠-٩]/g, (digit) => '٠١٢٣٤٥٦٧٨٩'.indexOf(digit) + '')
            .replace(/[٫،,]/g, '.'),
    ) || 0;

const gradeValues: Record<string, number> = {
    'A+': 4,
    A: 3.75,
    'B+': 3.5,
    B: 3,
    'C+': 2.5,
    C: 2,
    'D+': 1.5,
    D: 1,
    F: 0,
};

// Initialize courses with data from localStorage (client-side only)
const courses = ref<Course[]>([]);

// Build a calculator Course (with a fresh id and the UI's grade object) from a
// portable course. Used by every entry point: localStorage, clipboard import,
// and transcript extraction.
const makeCourse = (portable: PortableCourse): Course => ({
    id: nanoid(),
    name: portable.name,
    credits: portable.credits,
    grade: portable.grade ? { value: portable.grade, label: portable.grade } : undefined,
});

// Reduce a calculator Course down to its serializable shape.
const toPortable = (course: Course): PortableCourse => ({
    name: course.name,
    credits: course.credits,
    grade: course.grade?.value ?? null,
});

// Load courses from localStorage on client-side
const loadCourses = () => {
    if (typeof window === 'undefined') {
        return;
    }

    const stored = localStorage.getItem('courses');
    if (!stored) {
        return;
    }

    try {
        courses.value = deserializeCourses(stored).map(makeCourse);
    } catch {
        courses.value = [];
    }
};

// Save courses to localStorage
const saveCourses = () => {
    if (typeof window !== 'undefined') {
        localStorage.setItem('courses', serializeCourses(courses.value.map(toPortable)));
    }
};

// Watch for changes and save to localStorage
watch(courses, saveCourses, { deep: true });

// Computed statistics
const stats = computed(() => {
    const { creditsSum, pointsSum } = courses.value.reduce(
        (acc, { credits, grade }) => {
            const creditValue = parseArabicNumber(credits);

            // Only include in calculation if BOTH credits and grade are entered
            if (creditValue > 0 && grade?.value && gradeValues[grade.value] !== undefined) {
                acc.creditsSum += creditValue;
                acc.pointsSum += creditValue * gradeValues[grade.value];
            }

            return acc;
        },
        { creditsSum: 0, pointsSum: 0 },
    );

    const average = creditsSum ? pointsSum / creditsSum : 0;
    return {
        gpa: +average.toFixed(5),
        approximateGpa: +average.toFixed(2),
        totalCredits: creditsSum,
        totalPoints: pointsSum,
    };
});

// Individual computed refs for easier template access
const gpa = computed(() => stats.value.gpa);
const approximateGpa = computed(() => stats.value.approximateGpa);
const totalCredits = computed(() => stats.value.totalCredits);
const totalPoints = computed(() => stats.value.totalPoints);

// Course management functions
const addCourse = () => {
    courses.value.unshift({
        id: nanoid(),
        name: '',
        credits: '',
        grade: undefined,
    });
};

const removeCourse = (id: string) => {
    const index = courses.value.findIndex((course) => course.id === id);
    if (index > -1) {
        courses.value.splice(index, 1);
    }
};

const updateCourseField = (id: string, field: 'name' | 'credits' | 'grade', value: any) => {
    const course = courses.value.find((c) => c.id === id);
    if (course) {
        course[field] = value;
    }
};

// Import/Export functions
const exportCourses = async () => {
    try {
        await navigator.clipboard.writeText(serializeCourses(courses.value.map(toPortable)));
        toast.success('تم نسخ البيانات للحافظة');
    } catch {
        toast.error('خطأ في تصدير البيانات');
    }
};

const importCourses = async () => {
    try {
        const clipboardText = await navigator.clipboard.readText();
        courses.value = deserializeCourses(clipboardText).map(makeCourse);
        toast.success('تم استيراد البيانات بنجاح');
    } catch {
        toast.error('خطأ في استيراد البيانات');
    }
};

// Transcript (السجل الأكاديمي) upload
const transcriptInput = ref<HTMLInputElement | null>(null);
const isParsingTranscript = ref(false);

const openTranscriptPicker = () => {
    transcriptInput.value?.click();
};

const handleTranscriptUpload = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = ''; // allow re-selecting the same file later
    if (!file) {
        return;
    }

    isParsingTranscript.value = true;
    try {
        // pdf.js is heavy and browser-only, so load the parser lazily on demand.
        const { parseTranscriptFile } = await import('@/lib/transcript/parseTranscriptFile');
        const result = await parseTranscriptFile(file);
        const imported = transcriptToCourses(result);

        if (imported.length === 0) {
            toast.error('لم نتمكن من إيجاد مقررات في السجل الأكاديمي');
            return;
        }

        courses.value = imported.map(makeCourse);
        toast.success(`تم استيراد ${imported.length} مقرر من السجل الأكاديمي`);
    } catch {
        toast.error('تعذر قراءة ملف السجل الأكاديمي، تأكد من رفع الملف الصحيح');
    } finally {
        isParsingTranscript.value = false;
    }
};

// Load courses on mount
onMounted(() => {
    loadCourses();
});
</script>

<style scoped>
* {
    margin: 0;
}
</style>
