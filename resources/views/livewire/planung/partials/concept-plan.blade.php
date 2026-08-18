{{-- Inline-Plan-Panel (A0/A1): der vorbereitete KI-Kopf-Plan bleibt SICHTBAR + editierbar in der
     Leitstelle — kein Wegsprung mehr in den Conceptor. „Ein Ort, ein Kontext": die Semantik-Felder
     (Leitidee & co.) SIND der LLM-Kontext fürs Erzeugen; hier ändern steuert direkt, was beim Go
     entsteht. Erwartet $planConceptId gesetzt. Lese-Anzeige via $this->planVorschau(). --}}
@php $pv = $this->planVorschau(); @endphp
@if($pv !== null)
    <div class="rounded-lg border border-emerald-500/40 bg-emerald-50/50 p-3 mb-3 space-y-3" data-planung-plan-panel>
        <div class="flex items-center justify-between gap-2 flex-wrap">
            <p class="text-xs font-semibold text-gray-900 inline-flex items-center gap-1.5">
                @svg('heroicon-o-sparkles', 'w-4 h-4 text-emerald-600') Ausgearbeiteter Plan{{ $pv['name'] !== '' ? ' — ' . $pv['name'] : '' }}
            </p>
            {{-- Conceptor bleibt optionaler Tiefen-Editor (Entscheid 2026-08-18) — per Knopf, kein Pflicht-Sprung. --}}
            <button type="button"
                    wire:click="$dispatch('concepter-editor.oeffnen', { type: 'concepts', id: {{ (int) $planConceptId }}, startTab: 'konzept' })"
                    class="text-[11px] text-violet-600 hover:text-violet-500 underline inline-flex items-center gap-1">
                @svg('heroicon-o-arrow-top-right-on-square', 'w-3.5 h-3.5') Im Conceptor tief bearbeiten
            </button>
        </div>

        {{-- Semantik-Felder — editierbar. „Plan-Text speichern" schreibt in die concept.plan-Canvas
             (= der LLM-Kontext, der beim Go in die Gerichte fließt). --}}
        <div class="grid md:grid-cols-2 gap-x-4 gap-y-3">
            <div class="md:col-span-2">
                <label class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-1 block">Name + Claim</label>
                <input type="text" wire:model="planForm.name_claim" class="{{ $input }} !py-1.5" data-plan-name-claim />
            </div>
            <div class="md:col-span-2">
                <label class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-1 block">Leitidee</label>
                <textarea wire:model="planForm.leitidee" rows="2" class="{{ $input }}" data-plan-leitidee></textarea>
            </div>
            <div>
                <label class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-1 block">Vorteil / USP + Eignung</label>
                <textarea wire:model="planForm.usp_eignung" rows="2" class="{{ $input }}"></textarea>
            </div>
            <div>
                <label class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-1 block">Inszenierung &amp; Servierform</label>
                <textarea wire:model="planForm.inszenierung" rows="2" class="{{ $input }}"></textarea>
            </div>
        </div>
        <div class="flex items-center gap-3 flex-wrap">
            <button type="button" wire:click="planFeldSpeichern" @disabled($laeuft)
                    class="text-[11px] text-emerald-700 hover:text-emerald-600 underline inline-flex items-center gap-1 disabled:opacity-40" data-plan-speichern>
                @svg('heroicon-o-check', 'w-3.5 h-3.5') Plan-Text speichern
            </button>
            <span class="text-[11px] text-gray-500">Leitidee &amp; co. fließen als Kontext in die Erzeugung — hier ändern steuert das Ergebnis.</span>
        </div>

        {{-- Geschmackswelten (Lese-Anzeige; feinjustiert wird im Conceptor). --}}
        @if($pv['geschmackswelten'] !== [])
            <div>
                <p class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Geschmackswelten</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($pv['geschmackswelten'] as $welt)
                        <span class="px-2 py-0.5 rounded-full border border-black/10 bg-white/60 text-[11px] text-gray-700"
                              title="{{ $welt['meta']['description'] ?? '' }}">{{ $welt['value'] }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Vorgeschlagene Menü-Positionen — WELCHE Speisen der Plan vorsieht (die Gerichte selbst
             entstehen beim „Go"). Genau das, was vorher nur im Conceptor sichtbar war. --}}
        @if($pv['speisen'] !== [])
            <div>
                <p class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Menü-Aufbau — {{ count($pv['speisen']) }} Position(en); die Gerichte entstehen beim „Go"</p>
                <ol class="space-y-1">
                    @foreach($pv['speisen'] as $sp)
                        <li class="flex items-center gap-2 text-[11px] text-gray-700" data-plan-speise>
                            <span class="text-gray-400 tabular-nums">{{ $loop->iteration }}.</span>
                            <span class="font-medium">{{ $sp['titel'] !== '' ? $sp['titel'] : ($sp['rolle'] !== '' ? $sp['rolle'] : 'Position') }}</span>
                            @if($sp['titel'] !== '' && $sp['rolle'] !== '')<span class="text-gray-400">· {{ $sp['rolle'] }}</span>@endif
                            @if($sp['pflicht'])<span class="text-[10px] text-emerald-600 uppercase tracking-wide">Pflicht</span>@endif
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    </div>
@endif
