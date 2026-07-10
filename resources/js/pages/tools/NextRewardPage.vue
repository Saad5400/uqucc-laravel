<template>
    <SeoHead :seo="seo" />
    <DocsLayout>
        <PageHeader title="المكافأة القادمة" icon="solar:wallet-money-broken" />

        <!-- Rich content from database -->
        <div v-if="hasContent" class="typography mb-6">
            <RichContentRenderer :content="page?.html_content" />
        </div>

        <div class="typography">
            <!-- Payment Day Celebration -->
            <div v-if="isPaymentDay" class="relative my-8 overflow-hidden rounded-2xl bg-card p-8 text-center text-primary shadow-lg">
                <!-- Animated background elements -->
                <div
                    class="absolute inset-0 opacity-60"
                    :style="{
                        background:
                            'linear-gradient(45deg, rgba(var(--color-primary-rgb), 0.05) 25%, transparent 25%, transparent 75%, rgba(var(--color-primary-rgb), 0.05) 75%), linear-gradient(45deg, rgba(var(--color-primary-rgb), 0.05) 25%, transparent 25%, transparent 75%, rgba(var(--color-primary-rgb), 0.05) 75%)',
                        backgroundSize: '30px 30px',
                        backgroundPosition: '0 0, 15px 15px',
                    }"
                ></div>

                <div class="relative z-10 mb-4 animate-bounce text-6xl">🎉💰🎊</div>

                <h2 class="relative z-10 mb-4 text-3xl font-bold text-primary">مبروك! اليوم يوم صرف المكافأة</h2>

                <p class="relative z-10 mb-6 text-xl text-primary opacity-80">ممكن تاخذ حتى 24 ساعة عشان توصل المكافأة لحسابك</p>
            </div>

            <!-- Countdown Display -->
            <template v-else>
                <div class="mb-4 grid grid-cols-4 gap-4">
                    <div class="rounded-xl bg-card p-4 shadow-md backdrop-blur-sm">
                        <div class="text-3xl font-bold text-primary tabular-nums">{{ timeLeft.days }}</div>
                        <div class="text-sm opacity-80">يوم</div>
                    </div>
                    <div class="rounded-xl bg-card p-4 shadow-md backdrop-blur-sm">
                        <div class="text-3xl font-bold text-primary tabular-nums">
                            {{ timeLeft.hours }}
                        </div>
                        <div class="text-sm opacity-80">ساعة</div>
                    </div>
                    <div class="rounded-xl bg-card p-4 shadow-md backdrop-blur-sm">
                        <div class="text-3xl font-bold text-primary tabular-nums">
                            {{ timeLeft.minutes }}
                        </div>
                        <div class="text-sm opacity-80">دقيقة</div>
                    </div>
                    <div class="rounded-xl bg-card p-4 shadow-md backdrop-blur-sm">
                        <div class="text-3xl font-bold text-primary tabular-nums">
                            {{ timeLeft.seconds }}
                        </div>
                        <div class="text-sm opacity-80">ثانية</div>
                    </div>
                </div>

                <div class="rounded-lg bg-card p-4 shadow-md">
                    <p class="!my-0 text-base">
                        موعد المكافأة القادمة:
                        <strong class="text-primary">{{ formatDate(nextPaymentDate) }}</strong>
                    </p>
                </div>
            </template>

            <Alert>
                <AlertDescription>
                    في حال مصادفة التاريخ يوم <strong>جمعة</strong> يتم إيداع المكافأة يوم <strong>الخميس</strong><br />
                    في حال مصادفة التاريخ يوم <strong>السبت</strong> يتم إيداع المكافأة يوم
                    <strong>الأحد</strong>
                </AlertDescription>
            </Alert>
        </div>
    </DocsLayout>
</template>

<script setup lang="ts">
import DocsLayout from '@/components/layout/DocsLayout.vue';
import PageHeader from '@/components/page/PageHeader.vue';
import RichContentRenderer from '@/components/RichContentRenderer.vue';
import SeoHead, { type SeoData } from '@/components/SeoHead.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { RIYADH_TIMEZONE, calculateNextPaymentDate, calculateTimeLeft, isToday, type TimeLeft } from '@/lib/calculators/reward';
import { onMounted, onUnmounted, ref } from 'vue';

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

const formatDate = (date: Date): string => {
    // Convert UTC date to display in Riyadh timezone and Hijri calendar
    return date.toLocaleDateString('ar-SA-u-ca-islamic', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        weekday: 'long',
        timeZone: RIYADH_TIMEZONE,
    });
};

// Initialize with server-side values using UTC
const paymentDate = calculateNextPaymentDate();

// Reactive state that works on both server and client
const timeLeft = ref<TimeLeft>(calculateTimeLeft(paymentDate));
const nextPaymentDate = ref<Date>(paymentDate);
const isPaymentDay = ref<boolean>(isToday(paymentDate));

// Update function that can be called both server and client side
const updateCountdown = () => {
    const currentPaymentDate = calculateNextPaymentDate();

    // Update payment date if it has changed (moved to next month)
    if (nextPaymentDate.value.getTime() !== currentPaymentDate.getTime()) {
        nextPaymentDate.value = currentPaymentDate;
    }

    // Update countdown
    timeLeft.value = calculateTimeLeft(currentPaymentDate);

    // Check if it's payment day
    isPaymentDay.value = isToday(currentPaymentDate);
};

// Timer reference for cleanup
let timer: NodeJS.Timeout | null = null;

// Client-side hydration and timer setup
onMounted(() => {
    // Update immediately on mount to sync with client time
    updateCountdown();

    // Set up interval for real-time updates (every second)
    timer = setInterval(updateCountdown, 1000);
});

onUnmounted(() => {
    if (timer) {
        clearInterval(timer);
    }
});

updateCountdown();
</script>

<style scoped>
@keyframes bounce {
    0%,
    20%,
    50%,
    80%,
    100% {
        transform: translateY(0);
    }

    40% {
        transform: translateY(-10px);
    }

    60% {
        transform: translateY(-5px);
    }
}

.animate-bounce {
    animation: bounce 1s infinite;
}
</style>
