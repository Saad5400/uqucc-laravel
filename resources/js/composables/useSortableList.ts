import { computed, ref, watch, type Ref } from 'vue';

export interface SortableListItem {
    id: number;
}

export interface UseSortableList<T extends SortableListItem> {
    /** Local (optimistic) copy of the list, kept in sync with the source. */
    items: Ref<T[]>;
    /** Whether a drag is currently in progress. */
    isDragging: Ref<boolean>;
    /** Id of the item being dragged, or null. */
    draggingId: Ref<number | null>;
    /** Bind to `@dragstart` on the row. */
    startDrag: (item: T, event: DragEvent) => void;
    /** Bind to `@dragover` on each row; live-previews the new order. */
    dragOver: (item: T, event: DragEvent) => void;
    /** Bind to `@dragend` on the row; commits (or reverts on cancel). */
    endDrag: (event?: DragEvent) => void;
    /** Keyboard-accessible alternative: move an item one position up. */
    moveUp: (item: T) => void;
    /** Keyboard-accessible alternative: move an item one position down. */
    moveDown: (item: T) => void;
}

/**
 * Reorderable list state over native HTML5 drag events — no dependencies.
 *
 * The composable keeps an optimistic local copy of `source()`: rows are
 * reordered locally while dragging, and on drop the ordered ids are handed to
 * `onReorder` (typically an Inertia POST wrapped in a Promise). If the promise
 * rejects, the local order reverts to the server-confirmed `source()` order.
 *
 * Generic on purpose (items + callback only) so any ordered list — tutors,
 * courses, or the pages tree in a later phase — can reuse it.
 *
 * Usage:
 *   const sortable = useSortableList(
 *       () => props.tutors,
 *       (ids) =>
 *           new Promise((resolve, reject) =>
 *               router.post('/manage/tutors/reorder', { ids }, {
 *                   preserveScroll: true,
 *                   onSuccess: resolve,
 *                   onError: reject,
 *               }),
 *           ),
 *   );
 */
export function useSortableList<T extends SortableListItem>(
    source: () => T[],
    onReorder: (orderedIds: number[]) => Promise<unknown>,
): UseSortableList<T> {
    const items = ref([...source()]) as Ref<T[]>;
    const draggingId = ref<number | null>(null);
    const isDragging = computed(() => draggingId.value !== null);

    watch(source, (value) => {
        items.value = [...value];
    });

    function orderOf(list: T[]): string {
        return list.map((item) => item.id).join(',');
    }

    function moveItem(fromIndex: number, toIndex: number): void {
        if (fromIndex === toIndex || toIndex < 0 || toIndex >= items.value.length) {
            return;
        }

        const next = [...items.value];
        next.splice(toIndex, 0, ...next.splice(fromIndex, 1));
        items.value = next;
    }

    /** Push the current local order to the server; revert locally if it fails. */
    function commit(): void {
        if (orderOf(items.value) === orderOf(source())) {
            return;
        }

        onReorder(items.value.map((item) => item.id)).catch(() => {
            items.value = [...source()];
        });
    }

    function startDrag(item: T, event: DragEvent): void {
        draggingId.value = item.id;

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(item.id));
        }
    }

    function dragOver(target: T, event: DragEvent): void {
        event.preventDefault();

        if (draggingId.value === null || draggingId.value === target.id) {
            return;
        }

        const fromIndex = items.value.findIndex((item) => item.id === draggingId.value);
        const toIndex = items.value.findIndex((item) => item.id === target.id);

        if (fromIndex !== -1 && toIndex !== -1) {
            moveItem(fromIndex, toIndex);
        }
    }

    function endDrag(event?: DragEvent): void {
        if (draggingId.value === null) {
            return;
        }

        draggingId.value = null;

        const wasCancelled = event?.dataTransfer?.dropEffect === 'none';

        if (wasCancelled) {
            items.value = [...source()];
        } else {
            commit();
        }
    }

    function move(item: T, offset: number): void {
        const fromIndex = items.value.findIndex((candidate) => candidate.id === item.id);

        if (fromIndex === -1) {
            return;
        }

        moveItem(fromIndex, fromIndex + offset);
        commit();
    }

    return {
        items,
        isDragging,
        draggingId,
        startDrag,
        dragOver,
        endDrag,
        moveUp: (item) => move(item, -1),
        moveDown: (item) => move(item, 1),
    };
}
