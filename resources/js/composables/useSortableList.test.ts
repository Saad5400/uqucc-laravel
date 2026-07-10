import { describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import { useSortableList } from './useSortableList';

interface Row {
    id: number;
    name: string;
}

function makeRows(...ids: number[]): Row[] {
    return ids.map((id) => ({ id, name: `row-${id}` }));
}

function dragEvent(dropEffect: 'move' | 'none' = 'move'): DragEvent {
    return {
        preventDefault: vi.fn(),
        dataTransfer: { effectAllowed: 'move', dropEffect, setData: vi.fn() },
    } as unknown as DragEvent;
}

describe('useSortableList', () => {
    it('exposes a local copy of the source items', () => {
        const rows = makeRows(1, 2, 3);
        const { items } = useSortableList(() => rows, vi.fn().mockResolvedValue(undefined));

        expect(items.value).toEqual(rows);
        expect(items.value).not.toBe(rows);
    });

    it('syncs the local copy when the source changes', async () => {
        const source = ref(makeRows(1, 2));
        const { items } = useSortableList(() => source.value, vi.fn().mockResolvedValue(undefined));

        source.value = makeRows(2, 1);
        await nextTick();

        expect(items.value.map((row) => row.id)).toEqual([2, 1]);
    });

    it('live-previews the order while dragging over other rows', () => {
        const rows = makeRows(1, 2, 3);
        const onReorder = vi.fn().mockResolvedValue(undefined);
        const { items, startDrag, dragOver, isDragging } = useSortableList(() => rows, onReorder);

        startDrag(items.value[0], dragEvent());
        expect(isDragging.value).toBe(true);

        dragOver(items.value[2], dragEvent());

        expect(items.value.map((row) => row.id)).toEqual([2, 3, 1]);
        expect(onReorder).not.toHaveBeenCalled();
    });

    it('commits the new order on drag end', () => {
        const rows = makeRows(1, 2, 3);
        const onReorder = vi.fn().mockResolvedValue(undefined);
        const { items, startDrag, dragOver, endDrag } = useSortableList(() => rows, onReorder);

        startDrag(items.value[0], dragEvent());
        dragOver(items.value[1], dragEvent());
        endDrag(dragEvent('move'));

        expect(onReorder).toHaveBeenCalledWith([2, 1, 3]);
    });

    it('does not commit when the order is unchanged', () => {
        const rows = makeRows(1, 2, 3);
        const onReorder = vi.fn().mockResolvedValue(undefined);
        const { items, startDrag, endDrag } = useSortableList(() => rows, onReorder);

        startDrag(items.value[0], dragEvent());
        endDrag(dragEvent('move'));

        expect(onReorder).not.toHaveBeenCalled();
    });

    it('reverts the preview when the drag is cancelled', () => {
        const rows = makeRows(1, 2, 3);
        const onReorder = vi.fn().mockResolvedValue(undefined);
        const { items, startDrag, dragOver, endDrag } = useSortableList(() => rows, onReorder);

        startDrag(items.value[0], dragEvent());
        dragOver(items.value[2], dragEvent());
        endDrag(dragEvent('none'));

        expect(items.value.map((row) => row.id)).toEqual([1, 2, 3]);
        expect(onReorder).not.toHaveBeenCalled();
    });

    it('reverts to the source order when the reorder request fails', async () => {
        const rows = makeRows(1, 2, 3);
        const onReorder = vi.fn().mockRejectedValue(new Error('server error'));
        const { items, moveDown } = useSortableList(() => rows, onReorder);

        moveDown(items.value[0]);
        expect(items.value.map((row) => row.id)).toEqual([2, 1, 3]);

        await vi.waitFor(() => {
            expect(items.value.map((row) => row.id)).toEqual([1, 2, 3]);
        });
    });

    it('moves items up and down and commits each move', () => {
        const rows = makeRows(1, 2, 3);
        const onReorder = vi.fn().mockResolvedValue(undefined);
        const { items, moveUp, moveDown } = useSortableList(() => rows, onReorder);

        moveUp(items.value[1]);
        expect(items.value.map((row) => row.id)).toEqual([2, 1, 3]);
        expect(onReorder).toHaveBeenLastCalledWith([2, 1, 3]);

        moveDown(items.value[2]);
        expect(items.value.map((row) => row.id)).toEqual([2, 1, 3]);
    });

    it('ignores out-of-bounds keyboard moves', () => {
        const rows = makeRows(1, 2);
        const onReorder = vi.fn().mockResolvedValue(undefined);
        const { items, moveUp, moveDown } = useSortableList(() => rows, onReorder);

        moveUp(items.value[0]);
        moveDown(items.value[1]);

        expect(items.value.map((row) => row.id)).toEqual([1, 2]);
        expect(onReorder).not.toHaveBeenCalled();
    });
});
