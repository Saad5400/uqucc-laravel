<script setup lang="ts">
/**
 * A team's extra names, inline on the team row — members type these in
 * Telegram («انضم cs»), so they must be readable at a glance, not buried in a
 * dialog. Adding validates server-side; removing an alias touches nothing else
 * (the team and its memberships stay), so it needs no confirmation.
 */
import { badgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/vue3';
import { Loader2, Plus, X } from 'lucide-vue-next';
import { nextTick, ref } from 'vue';
import type { Team, TeamAlias } from './types';

const props = defineProps<{
    team: Team;
}>();

/** Each row posts its own name field; scope the errors so only this row shows them. */
const errorBag = `team-alias-${props.team.id}`;

const form = useForm({ name: '' });

const adding = ref(false);
const input = ref<InstanceType<typeof Input> | null>(null);

async function startAdding(): Promise<void> {
    form.reset();
    form.clearErrors();
    adding.value = true;

    await nextTick();
    (input.value?.$el as HTMLInputElement | undefined)?.focus();
}

function cancelAdding(): void {
    adding.value = false;
    form.reset();
    form.clearErrors();
}

function submit(): void {
    form.post(`/manage/telegram-teams/teams/${props.team.id}/aliases`, {
        errorBag,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            form.reset();
            adding.value = false;
        },
    });
}

const removingId = ref<number | null>(null);

function removeAlias(alias: TeamAlias): void {
    removingId.value = alias.id;

    router.delete(`/manage/telegram-teams/aliases/${alias.id}`, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            removingId.value = null;
        },
    });
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5">
        <span v-if="team.aliases.length" class="text-xs text-muted-foreground">الاختصارات</span>

        <span v-for="alias in team.aliases" :key="alias.id" :class="cn(badgeVariants({ variant: 'secondary' }), 'gap-0.5 py-0 ps-2 pe-0.5')">
            <bdi>{{ alias.name }}</bdi>
            <button
                type="button"
                class="rounded-sm p-0.5 text-secondary-foreground/70 transition-colors hover:bg-destructive/10 hover:text-destructive-foreground disabled:opacity-50"
                :disabled="removingId === alias.id"
                :aria-label="`حذف الاختصار ${alias.name} من ${team.name}`"
                :title="`حذف الاختصار ${alias.name}`"
                @click="removeAlias(alias)"
            >
                <Loader2 v-if="removingId === alias.id" class="size-3 animate-spin" />
                <X v-else class="size-3" />
            </button>
        </span>

        <form v-if="adding" class="flex items-center gap-1" @submit.prevent="submit">
            <Input
                ref="input"
                v-model="form.name"
                class="h-7 w-28 px-2 py-0 text-xs md:text-xs"
                :aria-invalid="form.errors.name ? true : undefined"
                :aria-label="`اختصار جديد لفريق ${team.name}`"
                placeholder="اختصار…"
                maxlength="32"
                required
                @keydown.esc="cancelAdding"
            />
            <Button type="submit" size="sm" class="h-7 px-2 text-xs" :disabled="form.processing">
                <Loader2 v-if="form.processing" class="size-3 animate-spin" />
                حفظ
            </Button>
            <Button type="button" variant="ghost" size="sm" class="h-7 px-2 text-xs" :disabled="form.processing" @click="cancelAdding">
                إلغاء
            </Button>
        </form>

        <button
            v-else
            type="button"
            :class="
                cn(
                    badgeVariants({ variant: 'outline' }),
                    'cursor-pointer gap-0.5 border-dashed text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground',
                )
            "
            :aria-label="`إضافة اختصار لفريق ${team.name}`"
            title="اسم مختصر إضافي يُنادى به الفريق داخل تيليجرام"
            @click="startAdding"
        >
            <Plus class="size-3" />
            اختصار
        </button>

        <p v-if="form.errors.name" class="w-full text-xs text-destructive-foreground">{{ form.errors.name }}</p>
    </div>
</template>
