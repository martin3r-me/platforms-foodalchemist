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
    'titleName' => null,                                              {{-- 2026-07-31: hebt einen Namen (z.B. Rezept) im Titel als gerahmten Akzent-Chip hervor — präsenter, nicht grösser --}}
    'tabInit' => null,                                                {{-- 2026-07-31: aktiviert eine fixe Tab-Leiste im Kopf (via <x-slot:tabs>); Wert = Default-Start-Tab. Alpine-`tab` lebt am Panel, umspannt Kopf-Tabs + Body-Panels. Ein `modal.open`-Dispatch darf per `tab:`-Detail einen anderen Start-Tab erzwingen (Scope-Treue: „Freies Basisrezept" öffnet auf dem Basisrezept-Tab) — ohne Detail bleibt tabInit. --}}
    'zFest' => false,                                                 {{-- Live-Befund Dominique (2026-09-18): der schwebende Sprachbefehl-Mikro-Knopf verschwand
                                                                            hinter geöffneten Editoren (RecipeModal/VkModal/... — alle über DIESE Komponente, alle
                                                                            z-[100] + `bringToFront`s global wachsendem Zähler = "zuletzt geöffnet gewinnt"). Für ein
                                                                            Modal, das IMMER über jedem Editor bleiben soll (aktuell nur Sprachbefehl), pinnt `zFest`
                                                                            eine feste z-[190] und nimmt NICHT am Zähler-Wettlauf teil — sonst würde ein später
                                                                            geöffneter Editor es trotzdem wieder überdecken. Bewusst additiv (Default false): JEDES
                                                                            andere Modal verhält sich exakt wie vorher. --}}
])


<div x-data="{
        open: false,
        @if($tabInit) tab: '{{ $tabInit }}', @endif
        bringToFront(el) {
            @if($zFest)
            // zFest: fest auf z-[190] gepinnt (Klasse unten) — KEINE Teilnahme am globalen
            // Zähler, sonst würde ein danach geöffneter Editor es wieder überdecken.
            @else
            window.__foodAlchemistModalZ = Math.max(window.__foodAlchemistModalZ || 100, 100) + 1;
            el.style.zIndex = String(window.__foodAlchemistModalZ);
            @endif
        },
        close() { this.open = false; this.$dispatch('modal.closed', { name: '{{ $name }}' }); },
        closeWithState() { @if($closeVia) this.$wire.{{ $closeVia }}(); @endif this.close(); },
     }"
     {{-- Alpine 3.15 interpretiert Punkte in statischen x-on-Namen als Modifier.
          Deshalb bleiben die bewährten nativen Listener für `modal.open`/`modal.close` erhalten. --}}
     x-init="
        window.addEventListener('modal.open', e => { if (e.detail?.name === '{{ $name }}') { bringToFront($el); open = true; @if($tabInit) tab = e.detail?.tab || '{{ $tabInit }}'; @endif } });
        window.addEventListener('modal.close', e => { if (!e.detail?.name || e.detail.name === '{{ $name }}') closeWithState() });
     "
     x-show="open" x-cloak
     @keydown.window.escape="if (open) closeWithState()"
     class="fixed inset-0 {{ $zFest ? 'z-[190]' : 'z-[100]' }} flex items-center justify-center p-4"
     data-modal="{{ $name }}"
     role="dialog" aria-modal="true" @if($title) aria-label="{{ trim($title . ($titleName !== null ? ': ' . $titleName : '')) }}" @endif>

    {{-- 2026-07-31: echter dunkler Editor (nur bei darkCanvas). Gescopet auf .fa-editor-panel,
         damit helle Kontexte (Settings, kleine Modals) unberührt bleiben.
         Spec 28 / E0.3: die Kaskade liegt in einer eigenen Partial — sie wächst pro Editor-Fläche
         und gehört nicht mitten ins Modal-Markup. Wartungsregeln stehen dort im Kopf. --}}
    {{-- fa-pass Welle 3: darkCanvas = Werkbank-Modus. Das Panel bekommt data-fa-theme="dark" und schaltet
         damit NUR Tokens um (resources/css/foodalchemist-pass.css) — keine Überschreib-Kaskade mehr. --}}

    {{-- Backdrop --}}
    <div class="absolute inset-0 bg-[var(--fa-rail)]/60" @click="closeWithState()"></div>

    {{-- Panel (frosted, DESIGN.md) --}}
    {{-- max-h: 85vh — Wert MUSS im Host-CSS-Build existieren (arbitrary value!);
         92vh war nie gebaut ⇒ Panel ohne Höhen-Limit ⇒ innerer Scroll tot (Bug 2026-06-12).
         fullscreen: h-full füllt den fixed-Wrapper (Viewport minus p-4) — nur Standard-Klassen. --}}
    <div class="relative w-full {{ $fullscreen ? 'max-w-none h-full' : $size . ' max-h-[85vh]' }} flex flex-col overflow-hidden rounded-[var(--fa-radius-surface)] bg-[var(--fa-surface)] text-[var(--fa-ink)] border border-[var(--fa-line)] shadow-2xl shadow-black/30 {{ $darkCanvas ? 'fa-editor-canvas' : '' }}" @if($darkCanvas) data-fa-theme="dark" @endif>

        {{-- Kopf: Titel + Schließen, darunter fixe Aktionen oben links (P-2) --}}
        <div class="shrink-0 border-b border-[var(--fa-line)] bg-[var(--fa-surface)]">
            <div class="px-6 pt-4 pb-3 flex items-center justify-between gap-4">
                @if($titleName !== null)
                    {{-- Name als gerahmter Akzent-Chip — gleiche Schriftgrösse wie der Titel, aber
                         auffälliger (violetter Rahmen). Farben als rohes CSS, damit sie auf hellem
                         UND dunklem Editor-Grund (.fa-editor-panel) sauber sitzen. --}}
                    <h2 class="text-[length:var(--fa-text-lg)] font-semibold tracking-tight text-[var(--fa-ink)] truncate flex items-center gap-2 min-w-0">
                        <span class="shrink-0 text-[var(--fa-ink-2)] font-medium">{{ $title }}</span>
                        <span class="min-w-0 truncate fa-titel-name" data-modal-title-name>{{ $titleName }}</span>
                        @isset($titleExtra)
                            <span class="min-w-0 shrink flex flex-wrap items-center gap-1.5" data-modal-zone="title-extra">
                                {{ $titleExtra }}
                            </span>
                        @endisset
                    </h2>
                @else
                    <h2 class="text-[length:var(--fa-text-lg)] font-semibold tracking-tight text-[var(--fa-ink)] truncate flex items-center gap-2 min-w-0">
                        <span class="min-w-0 truncate">{{ $title }}</span>
                        @isset($titleExtra)
                            <span class="min-w-0 shrink flex flex-wrap items-center gap-1.5" data-modal-zone="title-extra">
                                {{ $titleExtra }}
                            </span>
                        @endisset
                    </h2>
                @endif
                <x-fa::icon-button icon="heroicon-m-x-mark" label="Schließen" x-on:click="closeWithState()" />
            </div>
            @isset($actions)
                <div class="px-6 pb-3 flex flex-wrap items-center gap-2 min-w-0" data-modal-zone="actions">
                    {{ $actions }}
                </div>
            @endisset
            {{-- KPI-Streifen: fix im Kopf (scrollt nie weg) — geteilt über alle Editoren --}}
            @isset($kpiHeader)
                <div class="px-6 pb-3 border-t border-[var(--fa-line)] pt-3 min-w-0" data-modal-zone="kpi-header">
                    {{ $kpiHeader }}
                </div>
            @endisset
            {{-- Tab-Leiste: fix im Kopf unter den KPIs (2026-07-31) — scrollt nie mit dem Body --}}
            @isset($tabs)
                <div class="px-6 border-t border-[var(--fa-line)] pt-2" data-modal-zone="tabs">
                    {{ $tabs }}
                </div>
            @endisset
        </div>

        {{-- Körper: scrollt, Sektionen via <x-foodalchemist::modal-section>.
             2026-07-31: dunklerer Slate-Canvas (Light-Theme, kein dark: — README §158),
             damit die helleren Frosted-Cards darüber schweben (DESIGN.md-Tiefe). darkCanvas =
             kräftiges Slate für die grossen Editoren, sonst dezent. --}}
        <div class="flex-1 overflow-y-auto overflow-x-hidden px-6 py-4 space-y-4 bg-[var(--fa-ground)]" data-modal-zone="body">
            {{ $slot }}
        </div>

        {{-- Footer-Aktionen-Slot (optional) --}}
        @isset($footer)
            <div class="shrink-0 px-6 py-4 border-t border-[var(--fa-line)] bg-[var(--fa-surface)] flex items-center justify-end gap-2" data-modal-zone="footer">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
