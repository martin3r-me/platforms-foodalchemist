{{-- Spec-42-Vollzug S3a — Buch-Ebenen-Planung (Leitplanken + Briefing + Leitidee-Canvas) in der
     Leitstelle. Markup portiert aus dem Foodbook-Kontext-Tab. Blade-Regeln: heroicons inline, keine
     Direktiven-Tokens in Kommentaren, wire:key. --}}
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
@endphp
<div data-planung-fbkontext>
    @if(! $fb)
        <div class="text-xs text-gray-400 px-1">Kein Owner-Foodbook.</div>
    @else
        {{-- Leitplanken (Schreibstil · Kundentyp · Niveau) — steuern die Generierung. --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3" wire:key="fbkontext-{{ $fb->id }}" data-fbkontext-leitplanken>
            <div>
                <label class="{{ $label }}">Schreibstil / Ton</label>
                <select wire:change="tonalitaetSetzen($event.target.value)" class="{{ $input }}" data-fbkontext-schreibstil>
                    <option value="">— erben —</option>
                    @foreach($schreibstile as $s)
                        <option value="{{ $s->id }}" @selected((int) ($fb->writing_style_id ?? 0) === (int) $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="{{ $label }}">Kundentyp</label>
                <select wire:change="leitplankeSetzen('kundentyp', $event.target.value)" class="{{ $input }}" data-fbkontext-kundentyp>
                    <option value="">— erben —</option>
                    @foreach($kundentypen as $wert => $lbl)
                        <option value="{{ $wert }}" @selected(($fb->kundentyp ?? '') === $wert)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="{{ $label }}">Niveau</label>
                <select wire:change="leitplankeSetzen('default_niveau', $event.target.value)" class="{{ $input }}" data-fbkontext-niveau>
                    <option value="">— erben (Segment) —</option>
                    @foreach($niveauLabels as $wert => $lbl)
                        <option value="{{ $wert }}" @selected(($fb->default_niveau ?? '') === $wert)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Briefing / Einleitung (Kundentext) — blur-persistiert; KI-Vorschlag landet in der Vorschau. --}}
        <div class="mt-3" data-fbkontext-briefing>
            <div class="flex items-center justify-between">
                <label class="{{ $label }}">Briefing / Einleitung (Kundentext)</label>
                <button type="button" wire:click="kiEinleitung" wire:loading.attr="disabled" wire:target="kiEinleitung" class="{{ $btnAi }}" data-fbkontext-ki>
                    <span wire:loading.remove wire:target="kiEinleitung">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') KI-Text</span>
                    <span wire:loading wire:target="kiEinleitung">schreibt …</span>
                </button>
            </div>
            <textarea wire:model.blur="beschreibung" rows="3"
                      class="{{ $input }} w-full resize-none min-h-[4.5rem]"
                      placeholder="Briefing / Einleitungstext fürs Angebot"></textarea>

            @if($kiVorschau !== null)
                <div class="mt-2 rounded-md border border-violet-500/20 bg-violet-500/[0.04] p-2" data-fbkontext-ki-vorschau>
                    <p class="{{ $label }} !mb-0">KI-Vorschlag — noch nicht übernommen{{ $kiConfidence !== null ? ' · Konfidenz '.number_format($kiConfidence * 100, 0).' %' : '' }}</p>
                    <p class="text-xs text-gray-700 whitespace-pre-line mt-1">{{ $kiVorschau }}</p>
                    <div class="flex items-center gap-2 mt-2">
                        <button type="button" wire:click="kiUebernehmen" class="{{ $btnPrimary }}" data-fbkontext-ki-uebernehmen>{{ trim($beschreibung) !== '' ? 'Ersetzen' : 'Übernehmen' }}</button>
                        <button type="button" wire:click="kiVerwerfen" class="{{ $btnGhost }}">Verwerfen</button>
                    </div>
                </div>
            @endif
            @if($kiHinweis !== null)<p class="text-[11px] text-amber-600 mt-1" data-fbkontext-ki-hinweis>{{ $kiHinweis }}</p>@endif
        </div>

        {{-- Foodbook-Leitidee (Canvas) — inline, owner=foodbook (ManagesCanvas). --}}
        <div class="relative overflow-hidden {{ $card }} p-5 mt-3" wire:key="fbcanvas-{{ $fb->id }}" data-fbkontext-canvas>
            <div class="{{ $cardAccent }}"></div>
            <p class="{{ $label }} mb-2">Leitidee-Canvas — was muss rein · welche Konzepte · was es erfüllen muss</p>
            @include('foodalchemist::livewire.canvas.partials.board')
        </div>
    @endif
</div>
