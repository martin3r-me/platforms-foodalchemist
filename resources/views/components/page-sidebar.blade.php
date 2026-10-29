{{--
    Linke Seitenleiste (Filter, Baum) des Food Alchemisten — Ersatz für x-ui-page-sidebar (ui-tailwind),
    Issue #1003. Am Computer gleiches Verhalten und gleicher Store-Scope wie der ui-tailwind-Baustein
    (offen/zu und Breite bleiben, was der Nutzer eingestellt hat). Auf der Handy-Oberfläche (Support\HandyLesemodus::JS_MODUS:
    Touch-Gerät und schmal, nie ein schmales Laptop-Fenster) ist sie
    zu: nur die schmale Leiste am Rand. Antippen öffnet sie als Schublade ÜBER dem Inhalt, die Liste
    behält ihre Breite. Der Handy-Zustand ist lokal und überschreibt die Computer-Einstellung nicht.
    ui-tailwind selbst bleibt unverändert.
--}}
@props([
    'title' => '',
    'width' => 'w-80',
    'defaultOpen' => true,
    'storeKey' => 'sidebarOpen',
    'icon' => null,
    'minWidth' => 200,
    'maxWidth' => 640,
])

@php
    $scope = $storeKey === 'activityOpen' ? 'activity' : 'page_sidebar';
    $widthMap = [
        'w-56' => 224, 'w-60' => 240, 'w-64' => 256, 'w-72' => 288,
        'w-80' => 320, 'w-96' => 384,
    ];
    $defaultWidthPx = $widthMap[$width] ?? 320;
@endphp

<div
    x-data="{
        scope: @js($scope),
        defaultOpen: @js((bool) $defaultOpen),
        defaultWidth: {{ $defaultWidthPx }},
        resizing: false,
        handy: {!! \Platform\FoodAlchemist\Support\HandyLesemodus::JS_MODUS !!}(),
        handyOffen: false,
        vw: window.innerWidth,
        init() {
            window.matchMedia('(hover: none) and (pointer: coarse) and (max-width: 47.99rem)').addEventListener('change', () => { this.handy = window.faHandyModus(); this.handyOffen = false; });
            window.addEventListener('resize', () => { this.vw = window.innerWidth; });
        },
        get gespeichertOffen() {
            const v = this.$store.ui?.m(this.scope, 'open');
            return v === undefined ? this.defaultOpen : v;
        },
        get open() { return this.handy ? this.handyOffen : this.gespeichertOffen; },
        get width() {
            const v = this.$store.ui?.m(this.scope, 'width');
            const w = v === undefined ? this.defaultWidth : v;
            return Math.max({{ $minWidth }}, Math.min({{ $maxWidth }}, w));
        },
        get breite() { return this.handy ? Math.min(this.defaultWidth, this.vw - 56) : this.width; },
        setOpen(v) {
            if (this.handy) { this.handyOffen = v; return; }
            this.$store.ui?.mSet(this.scope, 'open', v);
        },
        startResize(e) {
            this.resizing = true;
            const startX = e.clientX;
            const startWidth = this.width;
            const onMouseMove = (ev) => {
                const next = Math.max({{ $minWidth }}, Math.min({{ $maxWidth }}, startWidth + ev.clientX - startX));
                this.$store.ui?.mSet(this.scope, 'width', next);
            };
            const onMouseUp = () => {
                this.resizing = false;
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
            };
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        }
    }"
    @keydown.escape.window="if (handy && handyOffen) handyOffen = false"
    :style="(open && ! handy) ? ('width: ' + width + 'px') : 'width: 44px'"
    :class="resizing ? '' : 'transition-all duration-200'"
    class="relative flex-shrink-0 h-full"
    data-fa-page-sidebar
    {{ $attributes }}
>
    {{-- Handy: Abdunklung hinter der Schublade, Antippen schließt --}}
    <div
        x-show="handy && handyOffen"
        x-cloak
        x-transition.opacity
        @click="setOpen(false)"
        class="fixed inset-0 z-40 bg-black/30"
        data-fa-page-sidebar-abdunklung
    ></div>

    <div
        :style="open ? ('width: ' + breite + 'px') : 'width: 44px'"
        :class="(handy && handyOffen) ? 'fixed inset-y-0 left-0 z-50 shadow-2xl' : 'relative h-full'"
        class="h-full bg-[color:var(--nx-bg)] overflow-x-hidden border-r border-[color:var(--nx-line)]"
        data-fa-page-sidebar-flaeche
    >
        {{-- Zugeklappt: schmale Leiste --}}
        <button
            type="button"
            x-show="!open"
            x-cloak
            @click="setOpen(true)"
            class="group/rail h-full w-full flex flex-col items-stretch hover:bg-[color:var(--nx-hover)] transition-colors cursor-pointer"
            title="@if($title){{ $title }} öffnen @else Öffnen @endif"
            data-fa-page-sidebar-oeffnen
        >
            <span class="h-11 flex-shrink-0 flex items-center justify-center border-b border-[color:var(--nx-line)]">
                @if($icon)
                    @svg($icon, 'w-4 h-4 text-[color:var(--nx-text)]')
                @else
                    @svg('heroicon-o-chevron-double-right', 'w-4 h-4 text-[color:var(--nx-muted)] group-hover/rail:text-[color:var(--nx-text)] transition-colors')
                @endif
            </span>
            <span class="flex-1 min-h-0 flex flex-col items-center justify-between py-3 px-2">
                @if($title)
                    <span class="max-h-full my-2 truncate rotate-180 [writing-mode:vertical-rl] text-[length:var(--fa-text-sm)] font-semibold uppercase tracking-wider text-[color:var(--nx-muted)] group-hover/rail:text-[color:var(--nx-text)] transition-colors">
                        {{ $title }}
                    </span>
                @else
                    <span class="flex-1"></span>
                @endif
                <span class="block w-1.5 h-1.5"></span>
            </span>
        </button>

        {{-- Aufgeklappt --}}
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="h-full overflow-hidden overflow-x-hidden flex flex-col"
        >
            @if($title)
                <div class="px-3 h-11 flex-shrink-0 flex items-center border-b border-[color:var(--nx-line)] bg-[color:var(--nx-bg)]">
                    @if($icon)
                        <span class="inline-flex items-center justify-center w-5 h-5 mr-2 text-[color:var(--nx-text)]">
                            @svg($icon, 'w-4 h-4')
                        </span>
                    @endif
                    <h2 class="text-sm font-semibold text-[color:var(--nx-text)] m-0 tracking-wide truncate">{{ $title }}</h2>
                    <button
                        type="button"
                        @click="setOpen(false)"
                        class="ml-auto inline-flex items-center justify-center rounded-md text-[color:var(--nx-text)] hover:bg-[color:var(--nx-hover)] transition-colors flex-shrink-0"
                        :class="handy ? 'w-11 h-11' : 'w-7 h-7'"
                        title="Einklappen"
                        data-fa-page-sidebar-schliessen
                    >@svg('heroicon-o-chevron-double-left', 'w-4 h-4')</button>
                </div>
            @endif

            <div class="flex-1 overflow-y-auto overflow-x-hidden">
                {{ $slot }}
            </div>
        </div>

        {{-- Breite ziehen (nur Computer) --}}
        <div
            x-show="open && ! handy"
            @mousedown.prevent="startResize($event)"
            class="absolute top-0 right-0 w-1 h-full cursor-ew-resize group/resize z-20"
        >
            <div class="absolute inset-y-0 right-0 w-px bg-transparent group-hover/resize:bg-[color:var(--nx-line-strong)] transition"></div>
            <div class="absolute top-1/2 -translate-y-1/2 right-0 h-8 w-1 rounded-full bg-transparent group-hover/resize:bg-[color:var(--nx-line-strong)] transition"></div>
        </div>
    </div>
</div>
