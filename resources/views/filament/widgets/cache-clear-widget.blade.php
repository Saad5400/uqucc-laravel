<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between">
            <x-filament::button
                wire:click="clearCache"
                color="danger"
                icon="heroicon-o-trash"
                wire:confirm="هل أنت متأكد من مسح الذاكرة المؤقتة؟"
            >
                مسح الذاكرة المؤقتة
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
