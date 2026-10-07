{{-- Inline-Plan (A0/A1): der von der KI ausgearbeitete Plan bleibt SICHTBAR + bearbeitbar in der
     Leitstelle, kein Wegsprung in den Conceptor. „Ein Ort, ein Kontext": die Felder (Leitidee und co.)
     SIND der Kontext fürs Erstellen; hier ändern steuert direkt, was beim Erstellen entsteht.
     Erwartet $planConceptId gesetzt. Lese-Anzeige via $this->planVorschau().
     fa-pass: eigene Karte mit Bausteinen (Tokens, hell + Werkbank) statt grüner Sonderfläche. --}}
@php $pv = $this->planVorschau(); @endphp
@if($pv !== null)
    <x-foodalchemist::modal-section icon="heroicon-o-document-text" :title="'Ausgearbeiteter Plan' . ($pv['name'] !== '' ? ': ' . $pv['name'] : '')" data-planung-plan-panel>
        <x-slot:actions>
            <x-fa::badge tone="ok" icon="heroicon-m-check">Plan liegt vor</x-fa::badge>
            {{-- Conceptor bleibt optionaler Tiefen-Editor (Entscheid 2026-08-18): per Knopf, kein Pflicht-Sprung. --}}
            <x-fa::button variant="ghost" size="sm" icon="heroicon-o-arrow-top-right-on-square"
                wire:click="$dispatch('concepter-editor.oeffnen', { type: 'concepts', id: {{ (int) $planConceptId }}, startTab: 'konzept' })">Im Conceptor bearbeiten</x-fa::button>
        </x-slot:actions>

        <p class="mb-3 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-2xl">Leitidee, Vorteil und Inszenierung fließen als Vorgabe in die Erstellung. Was du hier änderst, steuert das Ergebnis.</p>

        {{-- Semantik-Felder, bearbeitbar. „Plan-Text speichern" schreibt in die concept.plan-Canvas
             (= der Kontext, der beim Erstellen in die Gerichte fließt). --}}
        <div class="grid md:grid-cols-2 gap-x-4 gap-y-3">
            <x-fa::field label="Name und Claim" class="md:col-span-2">
                <x-fa::input wire:model="planForm.name_claim" data-plan-name-claim />
            </x-fa::field>
            <x-fa::field label="Leitidee" class="md:col-span-2">
                <x-fa::textarea wire:model="planForm.leitidee" rows="2" data-plan-leitidee />
            </x-fa::field>
            <x-fa::field label="Vorteil und Eignung">
                <x-fa::textarea wire:model="planForm.usp_eignung" rows="2" />
            </x-fa::field>
            <x-fa::field label="Inszenierung und Servierform">
                <x-fa::textarea wire:model="planForm.inszenierung" rows="2" />
            </x-fa::field>
        </div>
        <div class="mt-3">
            <x-fa::button size="sm" icon="heroicon-o-check" wire:click="planFeldSpeichern" :disabled="$laeuft" data-plan-speichern>Plan-Text speichern</x-fa::button>
        </div>

        {{-- Geschmackswelten (nur Anzeige; fein eingestellt wird im Conceptor). --}}
        @if($pv['geschmackswelten'] !== [])
            <div class="mt-4 flex flex-col gap-1.5">
                <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Geschmackswelten</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($pv['geschmackswelten'] as $welt)
                        <x-fa::badge title="{{ $welt['meta']['description'] ?? '' }}">{{ $welt['value'] }}</x-fa::badge>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Vorgesehene Menü-Positionen: WELCHE Speisen der Plan vorsieht (die Gerichte selbst
             entstehen beim Erstellen). Genau das, was vorher nur im Conceptor sichtbar war. --}}
        @if($pv['speisen'] !== [])
            <div class="mt-4 flex flex-col gap-1.5">
                <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Menü-Aufbau <span class="font-normal text-[var(--fa-ink-3)]">· {{ count($pv['speisen']) }} {{ count($pv['speisen']) === 1 ? 'Position' : 'Positionen' }}, die Gerichte entstehen beim Erstellen</span></p>
                <ol class="flex flex-col divide-y divide-[var(--fa-line)] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                    @foreach($pv['speisen'] as $sp)
                        <li class="flex items-center gap-2 px-3 py-1.5 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" data-plan-speise>
                            <span class="w-5 text-right text-[var(--fa-ink-3)] tabular-nums">{{ $loop->iteration }}.</span>
                            <span class="font-medium min-w-0 break-words">{{ $sp['titel'] !== '' ? $sp['titel'] : ($sp['rolle'] !== '' ? $sp['rolle'] : 'Position') }}</span>
                            @if($sp['titel'] !== '' && $sp['rolle'] !== '')<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">· {{ $sp['rolle'] }}</span>@endif
                            @if($sp['pflicht'])<x-fa::badge tone="accent" class="ml-auto">Pflicht</x-fa::badge>@endif
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    </x-foodalchemist::modal-section>
@endif
