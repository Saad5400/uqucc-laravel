<script setup lang="ts">
/**
 * Create a team, or rename it / move it between categories — one light dialog
 * for both, so the team row itself stays readable instead of carrying a picker.
 */
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import { NO_CATEGORY, type Team, type TeamCategory } from './types';

const props = defineProps<{
    chatId: string;
    categories: TeamCategory[];
    /** `null` creates a new team. */
    team: Team | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm<{ name: string; category_id: string }>({ name: '', category_id: NO_CATEGORY });

const isEditing = computed(() => props.team !== null);

watch(open, (value) => {
    if (!value) {
        return;
    }

    form.clearErrors();
    form.defaults({
        name: props.team?.name ?? '',
        category_id: props.team?.category_id == null ? NO_CATEGORY : String(props.team.category_id),
    });
    form.reset();
});

function submit(): void {
    const payload = form.transform((data) => ({
        name: data.name,
        category_id: data.category_id === NO_CATEGORY ? null : Number(data.category_id),
    }));

    const options = {
        errorBag: 'team-form',
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.team) {
        payload.put(`/manage/telegram-teams/teams/${props.team.id}`, options);

        return;
    }

    payload.post(`/manage/telegram-teams/${props.chatId}/teams`, options);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent :show-close-button="!form.processing">
            <DialogHeader>
                <DialogTitle>{{ isEditing ? 'تعديل الفريق' : 'فريق جديد' }}</DialogTitle>
                <DialogDescription>
                    {{
                        isEditing
                            ? 'الاسم الجديد يعمل فورًا في تيليجرام، والاختصارات القديمة تبقى كما هي.'
                            : 'الاسم هو ما يكتبه الأعضاء في تيليجرام للانضمام («انضم اسم الفريق»).'
                    }}
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="team-name">اسم الفريق</Label>
                    <Input
                        id="team-name"
                        v-model="form.name"
                        required
                        maxlength="64"
                        :aria-invalid="form.errors.name ? true : undefined"
                        placeholder="مثال: علوم الحاسب"
                    />
                    <p v-if="form.errors.name" class="text-sm text-destructive-foreground">{{ form.errors.name }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="team-category">التصنيف</Label>
                    <Select v-model="form.category_id">
                        <SelectTrigger id="team-category" class="w-full">
                            <SelectValue placeholder="بدون تصنيف" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NO_CATEGORY">بدون تصنيف</SelectItem>
                            <SelectItem v-for="category in categories" :key="category.id" :value="String(category.id)">
                                {{ category.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.category_id" class="text-sm text-destructive-foreground">{{ form.errors.category_id }}</p>
                    <p v-else class="text-xs text-muted-foreground">التصنيف يرتّب القائمة هنا فقط.</p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="open = false">إلغاء</Button>
                    <Button type="submit" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                        {{ isEditing ? 'حفظ' : 'إنشاء الفريق' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
