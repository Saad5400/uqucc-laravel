<script setup lang="ts">
import { roleLabels } from './types';

const props = defineProps<{
    roleOptions: string[];
    /** Roles that cannot be unchecked, mapped to the human reason shown on hover. */
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
    <div class="flex flex-wrap gap-4">
        <label
            v-for="role in roleOptions"
            :key="role"
            class="flex items-center gap-2 text-sm"
            :class="isLocked(role) ? 'cursor-not-allowed opacity-70' : 'cursor-pointer'"
            :title="isLocked(role) ? lockedRoles?.[role] : undefined"
        >
            <input type="checkbox" class="size-4 accent-primary" :checked="isSelected(role)" :disabled="isLocked(role)" @change="toggleRole(role)" />
            {{ roleLabels[role] ?? role }}
        </label>
    </div>
</template>
