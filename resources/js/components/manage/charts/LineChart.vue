<script setup lang="ts">
/**
 * Hand-rolled SVG line/area chart (no chart dependency). RTL-native: time
 * flows from the inline start (right) to the end (left), and the y-axis
 * labels sit on the start side. Hovering highlights the nearest point;
 * otherwise the latest point is emphasized.
 */
import { formatNumber } from '@/lib/formatters';
import { useElementSize } from '@vueuse/core';
import { computed, ref } from 'vue';

export interface LineChartPoint {
    label: string;
    value: number;
}

const props = withDefaults(
    defineProps<{
        points: LineChartPoint[];
        color?: string;
        label?: string;
    }>(),
    { color: 'var(--chart-1)', label: undefined },
);

const WIDTH = 600;
const HEIGHT = 210;
const TOP = 22;
const BOTTOM = HEIGHT - 26;
const X_START = WIDTH - 46; // inline start (right): oldest point, next to the y labels
const X_END = 10; // inline end (left): most recent point

const maxValue = computed(() => Math.max(1, ...props.points.map((point) => point.value)));

function xAt(index: number): number {
    const steps = Math.max(1, props.points.length - 1);

    return X_START - (index / steps) * (X_START - X_END);
}

function yAt(value: number): number {
    return TOP + (1 - value / maxValue.value) * (BOTTOM - TOP);
}

const linePath = computed(() =>
    props.points.map((point, index) => `${index === 0 ? 'M' : 'L'}${xAt(index).toFixed(1)},${yAt(point.value).toFixed(1)}`).join(' '),
);

const areaPath = computed(() => {
    if (props.points.length === 0) {
        return '';
    }

    const last = props.points.length - 1;

    return `${linePath.value} L${xAt(last).toFixed(1)},${BOTTOM} L${xAt(0).toFixed(1)},${BOTTOM} Z`;
});

const yTicks = computed(() => [0, Math.round(maxValue.value / 2), maxValue.value]);

const hoverIndex = ref<number | null>(null);
const svgEl = ref<SVGSVGElement | null>(null);

/**
 * Text inside the SVG is sized in viewBox units, so when the chart renders
 * narrower than its 600-unit viewBox (mobile) the labels shrink below
 * legibility. Scale font sizes by the inverse render ratio to keep them at a
 * constant on-screen size regardless of container width.
 */
const { width: renderedWidth } = useElementSize(svgEl);
const fontScale = computed(() => (renderedWidth.value > 0 ? Math.min(2.4, Math.max(1, WIDTH / renderedWidth.value)) : 1));
const tickFontSize = computed(() => 11 * fontScale.value);
const readoutFontSize = computed(() => 12 * fontScale.value);

const activeIndex = computed(() => hoverIndex.value ?? (props.points.length ? props.points.length - 1 : null));
const activePoint = computed(() => (activeIndex.value === null ? null : props.points[activeIndex.value]));

function onPointerMove(event: PointerEvent): void {
    if (!svgEl.value || props.points.length === 0) {
        return;
    }

    const rect = svgEl.value.getBoundingClientRect();
    const viewX = ((event.clientX - rect.left) / rect.width) * WIDTH;
    const ratio = (X_START - viewX) / (X_START - X_END);
    const index = Math.round(ratio * (props.points.length - 1));

    hoverIndex.value = Math.min(props.points.length - 1, Math.max(0, index));
}
</script>

<template>
    <!--
        direction is pinned to rtl on the SVG itself: SVG text-anchor semantics
        follow CSS direction, so under rtl "start" anchors the text's RIGHT edge
        at x and "end" anchors its LEFT edge. Anchors below are chosen by the
        intended visual edge; Arabic labels also keep correct bidi ordering.
    -->
    <svg
        ref="svgEl"
        :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
        class="w-full"
        style="direction: rtl"
        role="img"
        :aria-label="label"
        @pointermove="onPointerMove"
        @pointerleave="hoverIndex = null"
    >
        <g v-for="tick in yTicks" :key="tick">
            <line :x1="X_END" :x2="X_START" :y1="yAt(tick)" :y2="yAt(tick)" class="stroke-border" stroke-width="1" stroke-dasharray="3 4" />
            <!-- Right edge at the canvas edge, flowing left into the y-label gutter. -->
            <text :x="WIDTH - 2" :y="yAt(tick) + 4" class="fill-muted-foreground tabular-nums" :font-size="tickFontSize" text-anchor="start">
                {{ formatNumber(tick) }}
            </text>
        </g>

        <template v-if="points.length">
            <path :d="areaPath" :fill="color" fill-opacity="0.12" />
            <path :d="linePath" fill="none" :stroke="color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

            <!-- Oldest date (right side): right edge at its point, flowing left onto the canvas. -->
            <text :x="xAt(0)" :y="HEIGHT - 8" class="fill-muted-foreground" :font-size="tickFontSize" text-anchor="start">
                {{ points[0].label }}
            </text>
            <!-- Newest date (left side): left edge at its point, flowing right onto the canvas. -->
            <text :x="xAt(points.length - 1)" :y="HEIGHT - 8" class="fill-muted-foreground" :font-size="tickFontSize" text-anchor="end">
                {{ points[points.length - 1].label }}
            </text>

            <g v-if="activePoint !== null && activeIndex !== null">
                <line
                    v-if="hoverIndex !== null"
                    :x1="xAt(activeIndex)"
                    :x2="xAt(activeIndex)"
                    :y1="TOP"
                    :y2="BOTTOM"
                    class="stroke-border"
                    stroke-width="1"
                />
                <circle :cx="xAt(activeIndex)" :cy="yAt(activePoint.value)" r="4" :fill="color" class="stroke-background" stroke-width="2" />
                <!-- Active-point readout: left edge pinned at the inline end of the plot. -->
                <text :x="X_END" :y="readoutFontSize + 2" class="fill-foreground font-medium" :font-size="readoutFontSize" text-anchor="end">
                    {{ activePoint.label }} — {{ formatNumber(activePoint.value) }}
                </text>
            </g>
        </template>
        <text v-else :x="WIDTH / 2" :y="HEIGHT / 2" class="fill-muted-foreground" :font-size="readoutFontSize" text-anchor="middle">
            لا توجد بيانات بعد
        </text>
    </svg>
</template>
