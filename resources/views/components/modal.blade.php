{{--
    M0-08 / P-2: Sektions-Modal — großes, scrollbares Modal als Modul-Baustein.

    DESIGN.md-konform (Regel 1: kein x-ui im Content) als Custom-Frosted-Modal.
    Bewusst als Fassade gebaut: entscheidet Martin später „x-ui-modal erlaubt",
    wird NUR das Innenleben dieser Datei getauscht — Aufrufer bleiben unberührt
    (offener Punkt „x-ui-modal im Content?", 11_UI_PATTERNS P-2 / 12_ROADMAP).

    Nutzung (ein Modal = eine Identität via name, Planner-Event-Konvention):
        <x-foodalchemist::modal name="gp-edit" title="GP bearbeiten">
            <x-slot:actions>…Speichern (primary) · Löschen · KI (P-3) — oben links fix…</x-slot:actions>
            <x-foodalchemist::modal-section title="Stammdaten">…</x-foodalchemist::modal-section>
            <x-foodalchemist::modal-section title="Eigenschaften">…</x-foodalchemist::modal-section>
            <x-slot:footer>…Footer-Aktionen…</x-slot:footer>
        </x-foodalchemist::modal>

    Öffnen/Schließen (Alpine ODER Livewire-Dispatch):
        $dispatch('modal.open',  { name: 'gp-edit' })
        $dispatch('modal.close', { name: 'gp-edit' })   // ohne name: schließt alle

    State-Leak-Vertrag: Beim Schließen feuert IMMER `modal.closed` { name } —
    die besitzende Livewire-Komponente setzt darauf ihren Form-State zurück
    (resetExcept, P-2 „Schließen ohne Speichern = kein State-Leak").
--}}
@props([
    'name',
    'title' => null,
    'size' => 'max-w-4xl',
    'fullscreen' => false,                                            {{-- Editor-Parität R4: Voll-Editor nimmt den ganzen Viewport --}}
    'closeVia' => null,                                               {{-- optional: Livewire-Methode für das ✕ (z.B. Nav-Stack-Zurück) statt Alpine-close(); Backdrop/Escape bleiben hartes Schließen --}}
    'darkCanvas' => false,                                            {{-- 2026-07-31: dunkler Editor-Grund im Body (nur grosse Editoren); Karten schweben darauf --}}
    'tabInit' => null,                                                {{-- 2026-07-31: aktiviert eine fixe Tab-Leiste im Kopf (via <x-slot:tabs>); Wert = Start-Tab. Alpine-`tab` lebt am Panel, umspannt Kopf-Tabs + Body-Panels --}}
])

@php
    $label = 'text-[11px] font-medium uppercase tracking-wider text-gray-500';
@endphp

<div x-data="{
        open: false,
        @if($tabInit) tab: '{{ $tabInit }}', @endif
        close() { this.open = false; this.$dispatch('modal.closed', { name: '{{ $name }}' }); },
     }"
     {{-- UI-Audit 2026-06-12: `.dot` wird vom gebündelten Alpine 3.15 IGNORIERT
          (Listener hörte effektiv auf `modal-open` — kein Modal konnte je per
          Livewire-Event öffnen). Punkte im Event-Namen gehen in der @-Syntax
          nicht → explizite addEventListener in x-init; Event-Namen
          `modal.open`/`modal.close` (Planner-Konvention) bleiben unverändert. --}}
     x-init="
        window.addEventListener('modal.open', e => { if (e.detail?.name === '{{ $name }}') { open = true; @if($tabInit) tab = '{{ $tabInit }}'; @endif } });
        window.addEventListener('modal.close', e => { if (!e.detail?.name || e.detail.name === '{{ $name }}') close() });
     "
     x-show="open" x-cloak
     @keydown.window.escape="if (open) close()"
     class="fixed inset-0 z-[100] flex items-center justify-center p-4"
     data-modal="{{ $name }}"
     role="dialog" aria-modal="true" @if($title) aria-label="{{ $title }}" @endif>

    {{-- 2026-07-31: echter dunkler Editor (nur bei darkCanvas). Gescopet auf .fa-editor-panel,
         damit helle Kontexte (Settings, kleine Modals) unberührt bleiben. Reines CSS statt
         dark:-Utilities, weil Ui.php nicht von Tailwind gescannt wird (Kompilat-Lücken). --}}
    @if($darkCanvas)
        <style>
            .fa-editor-panel{ background:linear-gradient(to bottom,#1e293b,#0f172a) !important; border-color:rgba(255,255,255,.08) !important; color:#e2e8f0; }
            .fa-editor-panel [data-modal-zone="body"]{ background:transparent !important; }
            .fa-editor-panel [data-modal-zone="section"]{ background:rgba(255,255,255,.05) !important; border-color:rgba(255,255,255,.10) !important; box-shadow:none !important; }
            /* Trennlinien */
            .fa-editor-panel .border-b, .fa-editor-panel .border-t, .fa-editor-panel [class*="border-black/5"]{ border-color:rgba(255,255,255,.09) !important; }
            /* Schrift aufhellen */
            .fa-editor-panel .text-gray-900{ color:#f1f5f9 !important; }
            .fa-editor-panel .text-gray-800, .fa-editor-panel .text-gray-700{ color:#e2e8f0 !important; }
            .fa-editor-panel .text-gray-600{ color:#cbd5e1 !important; }
            .fa-editor-panel .text-gray-500, .fa-editor-panel .text-gray-400{ color:#94a3b8 !important; }
            /* Flächen: Ghost-Buttons / KPI-Kacheln / frosted Tiles */
            .fa-editor-panel .bg-white\/60, .fa-editor-panel .bg-white\/70, .fa-editor-panel .bg-white\/90, .fa-editor-panel [data-editor-kpis] > div, .fa-editor-panel [data-vk-editor-kpis] > div{ background:rgba(255,255,255,.06) !important; border-color:rgba(255,255,255,.10) !important; }
            /* Sticky-Tableiste */
            .fa-editor-panel .sticky{ background:rgba(15,23,42,.92) !important; }
            /* Eingaben */
            .fa-editor-panel input, .fa-editor-panel select, .fa-editor-panel textarea{ background:rgba(255,255,255,.07) !important; color:#f1f5f9 !important; border-color:rgba(255,255,255,.10) !important; }
            .fa-editor-panel input::placeholder, .fa-editor-panel textarea::placeholder{ color:#64748b !important; }
            .fa-editor-panel option{ background:#1e293b; color:#f1f5f9; }
            /* KI-Chips (btnAi): weisse Schrift + sichtbarer violetter Rand statt violett-auf-dunkel */
            .fa-editor-panel .bg-violet-500\/10{ background:rgba(139,92,246,.14) !important; color:#fff !important; }
            .fa-editor-panel .ring-violet-500\/20{ --tw-ring-color:rgba(167,139,250,.65) !important; }
        </style>
    @endif

    {{-- Backdrop --}}
    <div class="absolute inset-0 bg-black/50 backdrop-blur-md" @click="close()"></div>

    {{-- Panel (frosted, DESIGN.md) --}}
    {{-- max-h: 85vh — Wert MUSS im Host-CSS-Build existieren (arbitrary value!);
         92vh war nie gebaut ⇒ Panel ohne Höhen-Limit ⇒ innerer Scroll tot (Bug 2026-06-12).
         fullscreen: h-full füllt den fixed-Wrapper (Viewport minus p-4) — nur Standard-Klassen. --}}
    <div class="relative w-full {{ $fullscreen ? 'max-w-none h-full' : $size . ' max-h-[85vh]' }} flex flex-col overflow-hidden rounded-2xl bg-white/80 backdrop-blur-xl border border-white/20 shadow-2xl shadow-black/20 {{ $darkCanvas ? 'fa-editor-panel' : '' }}">
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-violet-500/50 to-transparent"></div>

        {{-- Kopf: Titel + Schließen, darunter fixe Aktionen oben links (P-2) --}}
        <div class="shrink-0 border-b border-black/5">
            <div class="px-6 pt-4 pb-3 flex items-center justify-between gap-4">
                <h2 class="text-lg font-semibold tracking-tight text-gray-900 truncate">{{ $title }}</h2>
                <button type="button" @click="{{ $closeVia ? '$wire.' . $closeVia . '()' : 'close()' }}"
                        class="p-1.5 rounded-md text-gray-500 hover:text-violet-600 hover:bg-black/5 transition-colors duration-150"
                        aria-label="Schließen">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            @isset($actions)
                <div class="px-6 pb-3 flex items-center gap-2" data-modal-zone="actions">
                    {{ $actions }}
                </div>
            @endisset
            {{-- KPI-Streifen: fix im Kopf (scrollt nie weg) — geteilt über alle Editoren --}}
            @isset($kpiHeader)
                <div class="px-6 pb-3 border-t border-black/5 pt-3" data-modal-zone="kpi-header">
                    {{ $kpiHeader }}
                </div>
            @endisset
            {{-- Tab-Leiste: fix im Kopf unter den KPIs (2026-07-31) — scrollt nie mit dem Body --}}
            @isset($tabs)
                <div class="px-6 border-t border-black/5 pt-2" data-modal-zone="tabs">
                    {{ $tabs }}
                </div>
            @endisset
        </div>

        {{-- Körper: scrollt, Sektionen via <x-foodalchemist::modal-section>.
             2026-07-31: dunklerer Slate-Canvas (Light-Theme, kein dark: — README §158),
             damit die helleren Frosted-Cards darüber schweben (DESIGN.md-Tiefe). darkCanvas =
             kräftiges Slate für die grossen Editoren, sonst dezent. --}}
        <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4 {{ $darkCanvas ? 'bg-gradient-to-b from-slate-700 to-slate-800' : 'bg-gradient-to-b from-slate-500/[0.06] to-slate-500/[0.02]' }}" data-modal-zone="body">
            {{ $slot }}
        </div>

        {{-- Footer-Aktionen-Slot (optional) --}}
        @isset($footer)
            <div class="shrink-0 px-6 py-4 border-t border-black/5 flex items-center justify-end gap-2" data-modal-zone="footer">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
