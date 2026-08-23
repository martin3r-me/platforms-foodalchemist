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

        {{-- Briefing / Einleitung (Kundentext) — blur-persistiert. --}}
        <div class="mt-3" data-fbkontext-briefing>
            <label class="{{ $label }}">Briefing / Einleitung (Kundentext)</label>
            <textarea wire:model.blur="beschreibung" rows="3"
                      class="{{ $input }} w-full resize-none min-h-[4.5rem]"
                      placeholder="Briefing / Einleitungstext fürs Angebot"></textarea>
        </div>

        {{-- Foodbook-Leitidee (Canvas) — inline, owner=foodbook (ManagesCanvas). --}}
        <div class="relative overflow-hidden {{ $card }} p-5 mt-3" wire:key="fbcanvas-{{ $fb->id }}" data-fbkontext-canvas>
            <div class="{{ $cardAccent }}"></div>
            <p class="{{ $label }} mb-2">Leitidee-Canvas — was muss rein · welche Konzepte · was es erfüllen muss</p>
            @include('foodalchemist::livewire.canvas.partials.board')
        </div>
    @endif
</div>
