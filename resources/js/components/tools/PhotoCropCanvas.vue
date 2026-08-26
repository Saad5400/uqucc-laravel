<script setup lang="ts">
import { clampView, cropFromView, panView, zoomView, type CropView, type Size } from '@/lib/studentPhoto/geometry';
import type { WorkingImage } from '@/lib/studentPhoto/render';
import { ASPECT_RATIO } from '@/lib/studentPhoto/requirements';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface Props {
    working: WorkingImage;
    view: CropView;
    showGuides?: boolean;
}

const props = withDefaults(defineProps<Props>(), { showGuides: true });

const emit = defineEmits<{
    (event: 'update:view', view: CropView): void;
    (event: 'reset'): void;
}>();

/** Breathing room between the crop frame and the edge of the viewport, in CSS pixels. */
const FRAME_PADDING = 20;
const KEYBOARD_STEP = 12;
const KEYBOARD_STEP_FAST = 48;
const KEYBOARD_ZOOM_FACTOR = 1.12;

const container = ref<HTMLDivElement | null>(null);
const canvas = ref<HTMLCanvasElement | null>(null);
const viewport = ref({ width: 0, height: 0 });

/** Active pointers, so one finger pans and two fingers pinch. */
const pointers = new Map<number, { x: number; y: number }>();
let pinchStart: { distance: number; zoom: number } | null = null;
let frameRequest = 0;
let resizeObserver: ResizeObserver | null = null;

const imageSize = computed<Size>(() => ({ width: props.working.width, height: props.working.height }));

/** The fixed 3:4 window the photo is dragged behind. */
const frame = computed(() => {
    const availableWidth = Math.max(0, viewport.value.width - FRAME_PADDING * 2);
    const availableHeight = Math.max(0, viewport.value.height - FRAME_PADDING * 2);
    const height = Math.min(availableHeight, availableWidth / ASPECT_RATIO);
    const width = height * ASPECT_RATIO;

    return {
        x: (viewport.value.width - width) / 2,
        y: (viewport.value.height - height) / 2,
        width,
        height,
    };
});

/** Screen pixels per image pixel at the current zoom. */
const scale = computed(() => {
    const crop = cropFromView(props.view, imageSize.value);

    return crop.width > 0 ? frame.value.width / crop.width : 0;
});

function draw(): void {
    const element = canvas.value;

    if (!element || frame.value.width <= 0) {
        return;
    }

    const ratio = window.devicePixelRatio || 1;
    const cssWidth = viewport.value.width;
    const cssHeight = viewport.value.height;

    element.width = Math.max(1, Math.round(cssWidth * ratio));
    element.height = Math.max(1, Math.round(cssHeight * ratio));

    const context = element.getContext('2d');

    if (!context) {
        return;
    }

    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.clearRect(0, 0, cssWidth, cssHeight);
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';

    const crop = cropFromView(props.view, imageSize.value);
    const pixelScale = scale.value;
    const originX = frame.value.x - crop.x * pixelScale;
    const originY = frame.value.y - crop.y * pixelScale;

    context.drawImage(props.working.canvas, originX, originY, props.working.width * pixelScale, props.working.height * pixelScale);

    drawScrim(context, cssWidth, cssHeight);
    drawFrame(context);

    if (props.showGuides) {
        drawGuides(context);
    }
}

/**
 * Overlay colours are deliberately plain black/white rather than theme tokens:
 * they sit on top of an arbitrary photograph, where contrast has to come from
 * the scrim itself and not from the page's palette.
 */
function drawScrim(context: CanvasRenderingContext2D, width: number, height: number): void {
    const { x, y, width: frameWidth, height: frameHeight } = frame.value;

    context.fillStyle = 'rgba(0, 0, 0, 0.55)';
    context.fillRect(0, 0, width, y);
    context.fillRect(0, y + frameHeight, width, height - (y + frameHeight));
    context.fillRect(0, y, x, frameHeight);
    context.fillRect(x + frameWidth, y, width - (x + frameWidth), frameHeight);
}

function drawFrame(context: CanvasRenderingContext2D): void {
    const { x, y, width, height } = frame.value;

    context.save();
    context.strokeStyle = 'rgba(255, 255, 255, 0.95)';
    context.lineWidth = 2;
    context.strokeRect(x + 1, y + 1, width - 2, height - 2);
    context.restore();
}

/** Passport-style aids: where the head should sit and where the eyes should land. */
function drawGuides(context: CanvasRenderingContext2D): void {
    const { x, y, width, height } = frame.value;

    context.save();
    context.strokeStyle = 'rgba(255, 255, 255, 0.65)';
    context.lineWidth = 1.5;
    context.setLineDash([6, 6]);

    context.beginPath();
    context.ellipse(x + width / 2, y + height * 0.44, width * 0.3, height * 0.32, 0, 0, Math.PI * 2);
    context.stroke();

    context.beginPath();
    context.moveTo(x + width * 0.12, y + height * 0.42);
    context.lineTo(x + width * 0.88, y + height * 0.42);
    context.stroke();

    context.restore();
}

function scheduleDraw(): void {
    if (frameRequest) {
        return;
    }

    frameRequest = requestAnimationFrame(() => {
        frameRequest = 0;
        draw();
    });
}

function distanceBetweenPointers(): number {
    const [first, second] = [...pointers.values()];

    return Math.hypot(first.x - second.x, first.y - second.y);
}

function handlePointerDown(event: PointerEvent): void {
    (event.target as HTMLElement).setPointerCapture?.(event.pointerId);
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (pointers.size === 2) {
        pinchStart = { distance: distanceBetweenPointers(), zoom: props.view.zoom };
    }
}

function handlePointerMove(event: PointerEvent): void {
    const previous = pointers.get(event.pointerId);

    if (!previous) {
        return;
    }

    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (pointers.size >= 2) {
        if (pinchStart && pinchStart.distance > 0) {
            const factor = distanceBetweenPointers() / pinchStart.distance;

            emit('update:view', clampView({ ...props.view, zoom: pinchStart.zoom * factor }, imageSize.value));
        }

        return;
    }

    emit('update:view', panView(props.view, event.clientX - previous.x, event.clientY - previous.y, scale.value, imageSize.value));
}

function handlePointerUp(event: PointerEvent): void {
    pointers.delete(event.pointerId);

    if (pointers.size < 2) {
        pinchStart = null;
    }
}

function handleWheel(event: WheelEvent): void {
    emit('update:view', zoomView(props.view, Math.exp(-event.deltaY * 0.0015), imageSize.value));
}

function handleKeydown(event: KeyboardEvent): void {
    const step = event.shiftKey ? KEYBOARD_STEP_FAST : KEYBOARD_STEP;
    const pan = (deltaX: number, deltaY: number) => emit('update:view', panView(props.view, deltaX, deltaY, scale.value, imageSize.value));

    switch (event.key) {
        case 'ArrowRight':
            pan(step, 0);
            break;
        case 'ArrowLeft':
            pan(-step, 0);
            break;
        case 'ArrowUp':
            pan(0, step);
            break;
        case 'ArrowDown':
            pan(0, -step);
            break;
        case '+':
        case '=':
            emit('update:view', zoomView(props.view, KEYBOARD_ZOOM_FACTOR, imageSize.value));
            break;
        case '-':
        case '_':
            emit('update:view', zoomView(props.view, 1 / KEYBOARD_ZOOM_FACTOR, imageSize.value));
            break;
        case '0':
            emit('reset');
            break;
        default:
            return;
    }

    event.preventDefault();
}

function measureViewport(): void {
    const element = container.value;

    if (!element) {
        return;
    }

    viewport.value = { width: element.clientWidth, height: element.clientHeight };
    scheduleDraw();
}

onMounted(() => {
    measureViewport();

    if (typeof ResizeObserver !== 'undefined' && container.value) {
        resizeObserver = new ResizeObserver(measureViewport);
        resizeObserver.observe(container.value);
    } else {
        window.addEventListener('resize', measureViewport);
    }
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    window.removeEventListener('resize', measureViewport);

    if (frameRequest) {
        cancelAnimationFrame(frameRequest);
    }
});

watch(() => [props.view, props.working, props.showGuides], scheduleDraw, { deep: true });
</script>

<template>
    <div ref="container" class="relative h-[26rem] w-full overflow-hidden rounded-xl bg-muted select-none sm:h-[30rem]">
        <canvas
            ref="canvas"
            class="size-full touch-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            tabindex="0"
            role="application"
            aria-label="إطار قص الصورة — اسحب لتحريك الصورة، واستخدم أسهم لوحة المفاتيح للتحريك و + و − للتكبير والتصغير"
            @pointerdown="handlePointerDown"
            @pointermove="handlePointerMove"
            @pointerup="handlePointerUp"
            @pointercancel="handlePointerUp"
            @lostpointercapture="handlePointerUp"
            @wheel.prevent="handleWheel"
            @keydown="handleKeydown"
        ></canvas>
    </div>
</template>
