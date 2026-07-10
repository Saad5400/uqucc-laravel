<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

defineOptions({ layout: false });

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

/** Delay the in-button spinner ~300ms so fast responses don't flash it. */
const showSpinner = ref(false);
let spinnerTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => form.processing,
    (processing) => {
        clearTimeout(spinnerTimer);

        if (processing) {
            spinnerTimer = setTimeout(() => {
                showSpinner.value = true;
            }, 300);
        } else {
            showSpinner.value = false;
        }
    },
);

onBeforeUnmount(() => clearTimeout(spinnerTimer));

function submit(): void {
    form.post('/manage/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="تسجيل الدخول - لوحة الإدارة" />
    <div class="flex min-h-svh items-center justify-center bg-muted/40 p-4">
        <Card class="w-full max-w-sm">
            <CardHeader class="items-center text-center">
                <img alt="الشعار" class="mx-auto mb-2 size-12" src="/favicon.svg" />
                <CardTitle class="text-xl">لوحة الإدارة</CardTitle>
                <p class="text-sm text-muted-foreground">دليل طالب كلية الحاسبات</p>
            </CardHeader>
            <CardContent>
                <form class="space-y-4" @submit.prevent="submit">
                    <div class="space-y-2">
                        <Label for="email">البريد الإلكتروني</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            name="email"
                            dir="ltr"
                            class="text-start"
                            inputmode="email"
                            autocomplete="username"
                            autofocus
                            required
                            :aria-invalid="form.errors.email ? true : undefined"
                        />
                        <p v-if="form.errors.email" class="text-sm text-destructive-foreground">{{ form.errors.email }}</p>
                    </div>
                    <div class="space-y-2">
                        <Label for="password">كلمة المرور</Label>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            name="password"
                            dir="ltr"
                            class="text-start"
                            autocomplete="current-password"
                            required
                            :aria-invalid="form.errors.password ? true : undefined"
                        />
                        <p v-if="form.errors.password" class="text-sm text-destructive-foreground">{{ form.errors.password }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm select-none">
                        <Checkbox v-model="form.remember" name="remember" />
                        تذكرني
                    </label>
                    <Button type="submit" class="w-full" :disabled="form.processing">
                        <Loader2 v-if="showSpinner" class="size-4 animate-spin" />
                        تسجيل الدخول
                    </Button>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
