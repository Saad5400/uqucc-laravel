<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { roleLabels } from './types';

const props = defineProps<{
    roleOptions: string[];
    /** Roles that cannot be unchecked, mapped to the human reason shown inline. */
    lockedRoles?: Record<string, string>;
}>();

const model = defineModel<string[]>({ required: true });

function isSelected(role: string): boolean {
    return model.value.includes(role);
}

function isLocked(role: string): boolean {
    return isSelected(role) && props.lockedRoles?.[role] !== undefined;
}

function toggleRole(role: string): void {
    if (isLocked(role)) {
        return;
    }

    model.value = isSelected(role) ? model.value.filter((selected) => selected !== role) : [...model.value, role];
}
</script>

<template>
    <div class="flex flex-col">
        <div v-for="role in roleOptions" :key="role" class="flex min-h-11 items-center gap-3">
            <Checkbox :id="`role-${role}`" :model-value="isSelected(role)" :disabled="isLocked(role)" @update:model-value="toggleRole(role)" />
            <Label :for="`role-${role}`" class="flex-1 py-3 font-normal" :class="isLocked(role) ? 'cursor-not-allowed' : 'cursor-pointer'">
                {{ roleLabels[role] ?? role }}
                <span v-if="isLocked(role)" class="text-xs font-normal text-muted-foreground">— {{ lockedRoles?.[role] }}</span>
            </Label>
        </div>
    </div>
</template>
