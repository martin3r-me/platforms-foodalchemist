{{-- Einstellungen → Trendradar: Automatisierung + Signal (pro Team) + manueller Import/Cluster. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-5" data-settings-trendradar>
    {{-- Bestand --}}
    <div class="flex flex-wrap gap-2">
        <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $trendDocs }} Trend-Docs</span>
        <span class="{{ $pill }} {{ $variantPill['primary'] }}">{{ $geclustert }} geclustert</span>
        <span class="{{ $pill }} {{ $variantPill['warning'] }}">{{ $ungeclustert }} offen</span>
        <span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $tentativeKlassen }} tentative Klassen</span>
    </div>

    @if(! $kiAktiv)
        <x-foodalchemist::alert tone="danger">
            KI ist für dieses Team deaktiviert (Kill-Switch) — die Automatisierung erzeugt nichts, bis die KI wieder an ist.
        </x-foodalchemist::alert>
    @endif
    @if(! $hostAktiv)
        <x-foodalchemist::alert tone="warning">
            Der Host-Zeitplan für die Trend-Automatisierung ist abgeschaltet — die Einstellung greift erst, wenn er wieder läuft.
        </x-foodalchemist::alert>
    @endif

    {{-- Automatisierung --}}
    <div class="{{ $card }} p-4 space-y-4">
        <div>
            <p class="text-sm font-semibold text-gray-800">Tägliche Konzept-Automatisierung</p>
            <p class="text-[11px] text-gray-500 mt-0.5">
                Zieht morgens um {{ $zeit }} die Top-Trends und erzeugt daraus Konzept-Entwürfe (Draft) im Konzepter.
            </p>
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
            <input type="checkbox" wire:model="autoEnabled" />
            Automatisierung aktiv
        </label>

        <div class="flex items-center gap-2">
            <label class="text-xs text-gray-600 w-40">Konzepte pro Lauf</label>
            <input type="number" min="1" max="10" wire:model="limit" class="{{ $input }} w-24" />
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
            <input type="checkbox" wire:model="signalEnabled" />
            Vorschlag als Signal in die Inbox legen
        </label>

        <div class="flex items-center gap-3 pt-1">
            <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">
                @svg('heroicon-o-check', 'w-4 h-4')
                Speichern
            </button>
            @if($meldung !== null)
                <span class="text-xs text-emerald-600" data-trendradar-meldung>{{ $meldung }}</span>
            @endif
        </div>
    </div>

    {{-- Manueller Anstoß --}}
    <div class="{{ $card }} p-4 space-y-3">
        <div>
            <p class="text-sm font-semibold text-gray-800">Trends jetzt importieren & clustern</p>
            <p class="text-[11px] text-gray-500 mt-0.5">
                Holt neue Trend-Dateien aus der Wissensbasis und ordnet sie per KI in die Kategorie→Klasse-Taxonomie ein.
                Läuft im Hintergrund; neue Klassen landen als „tentativ" im Trendradar zur Freigabe.
            </p>
        </div>
        <button type="button" wire:click="jetztImportieren"
                wire:confirm="Import & Clustern jetzt starten? Das ruft die KI für die neuen Trends."
                class="{{ $btnGhost }}">
            @svg('heroicon-o-arrow-path', 'w-4 h-4')
            Jetzt importieren & clustern
        </button>
    </div>
</div>
