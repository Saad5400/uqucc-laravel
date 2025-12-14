import { computed } from 'vue';
import { useDark, useToggle } from '@vueuse/core';

export function useColorMode() {
    const isDark = useDark({
        selector: 'html',
        attribute: 'class',
        valueDark: 'dark',
        valueLight: '',
        initialValue: 'dark',
    });

    const toggleDark = useToggle(isDark);

    return {
        value: computed(() => (isDark.value ? 'dark' : 'light')),
        preference: computed({
            get: () => (isDark.value ? 'dark' : 'light'),
            set: (val: string) => {
                isDark.value = val === 'dark';
            },
        }),
        toggle: toggleDark,
        isDark,
    };
}
