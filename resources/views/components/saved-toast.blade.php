{{--
    FA-eigener, flüchtiger „Gespeichert"-Toast. EIN Mount: einmal in
    resources/views/livewire/sidebar.blade.php eingebunden — die Modul-Sidebar
    hängt via platform::layouts.app auf JEDER FA-Seite als Geschwister von
    {{ $slot }} und den Modals. Der Toast überlebt darum Modal-Close und
    wire:navigate und ist auf allen FA-Seiten präsent, ohne das (fremde) Layout
    anzufassen. Reines Alpine, KEINE DB-Zeile (bewusst kein platforms-notifications).

    Vertrag: hört auf das window-Event `fa-saved` (Bindestrich, damit @…window trotz
    des Alpine-3.15-.dot-Bugs greift, vgl. components/modal.blade.php). Payload:
    { message, type } → e.detail. x-teleport="body" hebt den fixed-Toast aus einem
    evtl. transform/overflow der fremden Sidebar; wire:ignore hält Livewire von der
    Insel fern (kein Morph-Desync). z-[200] liegt über dem Modal (z-[100]).
--}}
<div wire:ignore
     x-data="{
        show: false,
        message: 'Gespeichert',
        type: 'success',
        timer: null,
        fire(e) {
            this.message = (e.detail && e.detail.message) ? e.detail.message : 'Gespeichert';
            this.type = (e.detail && e.detail.type) ? e.detail.type : 'success';
            this.show = true;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => { this.show = false; }, 2500);
        },
     }"
     @fa-saved.window="fire($event)">
    <template x-teleport="body">
        <div x-show="show" x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-500"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             :class="type === 'error' ? 'bg-rose-600' : 'bg-emerald-600'"
             class="fixed bottom-5 right-5 z-[200] flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-white shadow-lg shadow-black/20"
             role="status" aria-live="polite" data-fa-toast>
            <svg x-show="type !== 'error'" class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <svg x-show="type === 'error'" x-cloak class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M12 21a9 9 0 100-18 9 9 0 000 18z" />
            </svg>
            <span x-text="message"></span>
        </div>
    </template>
</div>
