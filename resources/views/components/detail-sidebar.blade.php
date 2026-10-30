{{--
    Eigener rechter Sidebar-Wrapper (Modul-Fix 2026-07-24): x-ui-page-sidebar (Core/UI-Modul)
    kennt nur zwei App-weite Store-Scopes ("page_sidebar" links, "activity" rechts) — alle
    Detail-Panels im Modul teilten sich daher EIN Toggle-Feld. Klappte man es irgendwo zu,
    war es überall im Food Alchemisten zu (Symptom: "rechtes Panel fehlt überall").
    Löst die Kollision, indem jeder Screen einen eigenen Scope bekommt ("scope"-Prop) —
    nutzt weiterhin dieselbe generische Core-Store-API ($store.ui.m/mSet), nur ohne die
    2-Buckets-Beschränkung von page-sidebar.blade.php. Core selbst bleibt unangetastet.
--}}
@props([
    'title' => '',
    'width' => 'w-96',       // expanded width (Tailwind class — wird zu Default-px geparst)
    'defaultOpen' => false,
    'scope',                 // PFLICHT: eindeutiger Scope-Name je Screen, z.B. 'activity_recipes'
    'side' => 'right',       // 'left' | 'right'
    'icon' => null,          // optionaler Heroicon-Name fürs Rail
    'minWidth' => 200,       // resize min in px
    'maxWidth' => 640,       // resize max in px
    'aufziehen' => false,    // Sprung per Link (?gp= / ?rezept=): beim Ankommen öffnen, auch wenn zuletzt zugeklappt
    'auswahl' => false,      // Handy (#1003): gewählter Eintrag (id) bzw. null = nichts gewählt; false = Seite meldet nichts
    'lesemodus' => true,     // Handy (#1003): Ändern-Aktionen aus (Support\HandyLesemodus); false z. B. in der Planung
])

@php
    $borderClass = $side === 'right' ? 'border-l border-[var(--ui-border)]/60' : 'border-r border-[var(--ui-border)]/60';
    $expandIcon = $side === 'right' ? 'heroicon-o-chevron-double-left' : 'heroicon-o-chevron-double-right';

    $widthMap = [
        'w-56' => 224, 'w-60' => 240, 'w-64' => 256, 'w-72' => 288,
        'w-80' => 320, 'w-96' => 384,
    ];
    $defaultWidthPx = $widthMap[$width] ?? 320;
    // '-' = Seite meldet keine Auswahl (Handy: Leiste wie bisher per Antippen); '' = nichts gewählt.
    $auswahlWert = $auswahl === false ? '-' : (string) ($auswahl ?? '');
@endphp

{{-- fa-pass 2026-10-05: unter 1536 px (Laptop) legt sich das offene Panel als Schublade ÜBER den Inhalt,
     statt ihn zusammenzudrücken (Concepter 1280 px: Mitte schrumpfte auf ~90 px). Im Fluss bleibt nur die
     Rail-Breite. Ab 1536 px wie bisher daneben, aber höchstens 30 % der Fensterbreite.
     Handy-Oberfläche (#1003, Entscheidung Support\HandyLesemodus::JS_MODUS: Touch-Gerät und schmal, nie ein
     schmales Laptop-Fenster): ganze Breite über dem Inhalt. Meldet die Seite ihre Auswahl (data-fa-auswahl),
     öffnet die Leiste beim Antippen eines Eintrags und ist ohne Auswahl ganz weg; Zurück schließt sie.
     Der Handy-Zustand ist lokal und ändert die gespeicherte Computer-Einstellung nicht. --}}
<div
    x-data="{
        scope: @js($scope),
        weit: window.matchMedia('(min-width: 96rem)').matches,
        handy: {!! \Platform\FoodAlchemist\Support\HandyLesemodus::JS_MODUS !!}(),
        auswahl: @js($auswahlWert),
        handyOffen: false,
        vw: window.innerWidth,
        init() {
            const mq = window.matchMedia('(min-width: 96rem)');
            mq.addEventListener('change', (e) => { this.weit = e.matches; });
            window.matchMedia('(hover: none) and (pointer: coarse) and (max-width: 47.99rem)').addEventListener('change', () => { this.handy = window.faHandyModus(); this.handyOffen = this.gewaehlt; });
            window.addEventListener('resize', () => { this.vw = window.innerWidth; });
            this.handyOffen = this.gewaehlt;
            // Handy-Lesemodus serverseitig: Livewire-Anfragen der Handy-Oberfläche tragen X-FA-Handy: 1 (Support\HandyLesemodus)
            if (window.Livewire && ! window.faHandyKopfzeile) {
                window.faHandyKopfzeile = true;
                window.Livewire.hook('request', ({ options }) => {
                    options.headers = options.headers || {};
                    options.headers['X-FA-Handy'] = window.faHandyModus() ? '1' : '0';
                });
            }
            new MutationObserver(() => {
                this.auswahl = this.$el.dataset.faAuswahl ?? '-';
                this.handyOffen = this.gewaehlt;
                this.merke();
            }).observe(this.$el, { attributes: true, attributeFilter: ['data-fa-auswahl'] });
            if (@js((bool) $aufziehen) && ! this.handy && ! this.open) this.setOpen(true);
            this.merke();
        },
        // Handy: geöffnete Detailseite als „Zuletzt angesehen“ merken (Handy-Suche, pro Gerät, localStorage fa-zuletzt)
        merke() {
            if (! this.handy || ! this.gewaehlt) return;
            setTimeout(() => {
                const titel = this.$el.querySelector('[data-fa-detail-kopf] h2')?.textContent?.trim();
                if (! titel) return;
                const url = location.pathname + location.search;
                let liste = [];
                try { liste = JSON.parse(localStorage.getItem('fa-zuletzt') || '[]'); } catch (e) {}
                liste = [{ titel, url }].concat(liste.filter((e) => e.url !== url)).slice(0, 10);
                try { localStorage.setItem('fa-zuletzt', JSON.stringify(liste)); } catch (e) {}
            }, 800);
        },
        get gewaehlt() { return this.auswahl !== '-' && this.auswahl !== ''; },
        get handyLeiste() { return this.auswahl !== ''; },
        get schublade() { return this.open && ! this.weit; },
        get breite() {
            if (this.handy) return this.vw;
            return Math.min(this.width, Math.round(this.vw * (this.weit ? 0.3 : 0.9)));
        },
        defaultOpen: @js((bool) $defaultOpen),
        defaultWidth: {{ $defaultWidthPx }},
        resizing: false,
        get open() {
            if (this.handy) return this.handyOffen;
            const v = this.$store.ui?.m(this.scope, 'open');
            return v === undefined ? this.defaultOpen : v;
        },
        get width() {
            const v = this.$store.ui?.m(this.scope, 'width');
            const w = v === undefined ? this.defaultWidth : v;
            return Math.max({{ $minWidth }}, Math.min({{ $maxWidth }}, w));
        },
        setOpen(v) {
            if (this.handy) { this.handyOffen = v; return; }
            this.$store.ui?.mSet(this.scope, 'open', v);
        },
        startResize(e) {
            this.resizing = true;
            const startX = e.clientX;
            const startWidth = this.width;
            const side = '{{ $side }}';
            const MIN = {{ $minWidth }};
            const MAX = {{ $maxWidth }};
            const onMouseMove = (ev) => {
                const delta = ev.clientX - startX;
                const adjusted = side === 'left' ? startWidth + delta : startWidth - delta;
                const next = Math.max(MIN, Math.min(MAX, adjusted));
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
    @keydown.escape.window="if (handy && handyOffen) setOpen(false)"
    :style="handy ? (handyLeiste ? 'width: 44px' : 'width: 0px') : ((open && weit) ? ('width: ' + breite + 'px') : 'width: 44px')"
    :class="resizing ? '' : 'transition-all duration-200'"
    class="relative flex-shrink-0 h-full"
    data-fa-auswahl="{{ $auswahlWert }}"
    data-fa-detail-sidebar
    @if($lesemodus) data-fa-handy-lesen @endif
    {{ $attributes }}
>
  {{-- Dunkler Rahmen: Detail-Panels im Werkbank-Modus (wie Navigation, Chat-Leiste, Editoren). --}}
  <div
    data-fa-theme="dark"
    x-show="! handy || handyLeiste"
    :style="open ? ('width: ' + breite + 'px') : 'width: 44px'"
    :class="(handy && open) ? 'fixed inset-0 z-50' : (schublade ? 'absolute inset-y-0 {{ $side === 'right' ? 'right-0' : 'left-0' }} z-40 shadow-2xl' : 'relative h-full')"
    class="h-full bg-[var(--fa-ground)] overflow-x-hidden {{ $borderClass }}"
  >
    {{-- Collapsed Rail --}}
    <button
        type="button"
        x-show="!open"
        x-cloak
        @click="setOpen(true)"
        class="group/rail h-full w-full flex flex-col items-center justify-between py-3 px-2 hover:bg-[var(--ui-muted-10)] transition-colors cursor-pointer"
        title="@if($title){{ $title }} öffnen @else Öffnen @endif"
    >
        @if($icon)
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-md bg-white shadow-sm flex-shrink-0">
                @svg($icon, 'w-3.5 h-3.5 text-[var(--ui-secondary)]')
            </span>
        @else
            @svg($expandIcon, 'w-4 h-4 text-[var(--ui-secondary)] group-hover/rail:text-[var(--ui-primary)] transition-colors flex-shrink-0')
        @endif

        @if($title)
            <span
                class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] group-hover/rail:text-[var(--ui-secondary)] transition-colors my-2 truncate"
                style="writing-mode: vertical-rl; transform: rotate(180deg); max-height: 100%;"
            >
                {{ $title }}
            </span>
        @else
            <span class="flex-1"></span>
        @endif

        @if($icon)
            @svg($expandIcon, 'w-4 h-4 text-[var(--ui-secondary)] group-hover/rail:text-[var(--ui-primary)] transition-colors flex-shrink-0')
        @else
            <span class="block w-1.5 h-1.5"></span>
        @endif
    </button>

    {{-- Expanded Content --}}
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
            <div class="px-6 h-14 flex-shrink-0 flex items-center border-b border-[var(--ui-border)]/60 bg-[var(--ui-surface)]/90 backdrop-blur">
                @if($icon)
                    <span class="inline-flex items-center justify-center w-5 h-5 mr-2 text-[var(--ui-secondary)]">
                        @svg($icon, 'w-4 h-4')
                    </span>
                @endif
                <h2 class="text-sm font-semibold text-[var(--ui-secondary)] m-0 tracking-wide uppercase truncate">{{ $title }}</h2>
                <button
                    type="button"
                    @click="setOpen(false)"
                    class="ml-auto inline-flex items-center justify-center rounded-md text-[var(--ui-secondary)] hover:text-[var(--ui-primary)] hover:bg-[var(--ui-muted-10)] transition-colors flex-shrink-0"
                    :class="handy ? 'w-11 h-11' : 'w-7 h-7'"
                    :title="handy ? 'Zurück zur Liste' : 'Einklappen'"
                    data-fa-detail-schliessen
                >
                    @if($side === 'right')
                        @svg('heroicon-o-chevron-double-right', 'w-4 h-4')
                    @else
                        @svg('heroicon-o-chevron-double-left', 'w-4 h-4')
                    @endif
                </button>
            </div>
        @endif

        <div class="flex-1 overflow-y-auto overflow-x-hidden">
            {{ $slot }}
        </div>
    </div>

    {{-- Resize-Handle (nur im expanded state, klebt an der nach außen zeigenden Kante) --}}
    <div
        x-show="open"
        @mousedown.prevent="startResize($event)"
        class="absolute top-0 {{ $side === 'right' ? 'left-0' : 'right-0' }} w-1 h-full cursor-ew-resize group/resize z-20"
    >
        <div class="absolute inset-y-0 {{ $side === 'right' ? 'left-0' : 'right-0' }} w-px bg-transparent group-hover/resize:bg-[var(--ui-primary)]/40 transition"></div>
        <div class="absolute top-1/2 -translate-y-1/2 {{ $side === 'right' ? 'left-0' : 'right-0' }} h-8 w-1 rounded-full bg-transparent group-hover/resize:bg-[var(--ui-primary)]/30 transition"></div>
    </div>
  </div>
</div>
