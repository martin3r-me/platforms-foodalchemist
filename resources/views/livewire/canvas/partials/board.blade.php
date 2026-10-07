{{-- Wiederverwendbares Canvas-Board (Trait ManagesCanvas). Rendert das feste Template je canvas_type.
     Eingebunden in Food DNA, Einstellungen, Kunden-DNA, Angebot, Foodbook-Rail und Concepter-Editor
     (dort im Werkbank-Modus und ohne eigenes Speichern).
     fa-pass 2026-10-05: auf Bausteine <x-fa::…> umgestellt (nur Tokens, stimmt hell und dunkel).
     Felder, wire:model, Methoden und data-Marker unverändert. --}}
@php
    $canvasTpl = $this->canvasTemplateData();
@endphp

<div class="flex flex-col gap-3 min-w-0" data-canvas-board="{{ $canvasType }}">
    @if($canvasGespeichert)
        <x-fa::notice tone="ok" data-canvas-gespeichert>Gespeichert. Fließt als Rahmen in die KI-Texte ein.</x-fa::notice>
    @endif

    @foreach($canvasTpl['gruppen'] as $gruppe => $felder)
        <x-fa::section :title="$gruppe" wire:key="canvas-gruppe-{{ md5($gruppe) }}">
            <div class="flex flex-col gap-3">
                @foreach($felder as $f)
                    @php
                        $feldId = 'canvas-' . $canvasType . '-' . ($f['key'] ?? $loop->index);
                    @endphp
                    @if(($f['type'] ?? 'text') === 'repeatable')
                        {{-- Wiederholbar (Geschmackswelten): Liste + Hinzufügen --}}
                        <div class="flex flex-col gap-2 min-w-0">
                            <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">{{ $f['label'] }}</p>
                            <div class="flex flex-col gap-1.5">
                                @forelse($canvasWelten as $w)
                                    <div wire:key="welt-{{ $w['id'] }}" class="flex items-start gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] px-3 py-2">
                                        <div class="flex-1 min-w-0">
                                            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)] break-words">
                                                <span class="font-medium">{{ $w['value'] }}</span>
                                                @if($w['claim'])<span class="text-[var(--fa-ink-2)]"> · {{ $w['claim'] }}</span>@endif
                                            </p>
                                            @if($w['description'])<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] break-words">{{ $w['description'] }}</p>@endif
                                        </div>
                                        <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash" label="{{ $w['value'] }} entfernen" wire:click="weltLoeschen({{ $w['id'] }})" />
                                    </div>
                                @empty
                                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Noch keine Einträge. Unten den ersten anlegen.</p>
                                @endforelse
                            </div>
                            <div class="grid gap-2 items-end grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))]">
                                <x-fa::field label="Name" for="{{ $feldId }}-wert">
                                    <x-fa::input id="{{ $feldId }}-wert" wire:model="canvasNeuWelt.value" placeholder="Zum Beispiel: Italien" />
                                </x-fa::field>
                                <x-fa::field label="Leitsatz" for="{{ $feldId }}-claim" optional>
                                    <x-fa::input id="{{ $feldId }}-claim" wire:model="canvasNeuWelt.claim" />
                                </x-fa::field>
                                <x-fa::field label="Beschreibung" for="{{ $feldId }}-beschreibung" optional>
                                    <x-fa::input id="{{ $feldId }}-beschreibung" wire:model="canvasNeuWelt.description" />
                                </x-fa::field>
                                <div>
                                    <x-fa::button icon="heroicon-m-plus" wire:click="weltHinzu">Eintrag hinzufügen</x-fa::button>
                                </div>
                            </div>
                        </div>
                    @elseif(($f['type'] ?? '') === 'ref_schreibstil')
                        @php
                            $schreibstile = $this->canvasSchreibstile();
                        @endphp
                        <x-fa::field :label="$f['label']" for="{{ $feldId }}">
                            <x-fa::select id="{{ $feldId }}" wire:model="canvasForm.{{ $f['key'] }}">
                                <option value="">Neutral, kein eigener Stil</option>
                                @foreach($schreibstile as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                            </x-fa::select>
                        </x-fa::field>
                    @elseif(($f['type'] ?? '') === 'text')
                        <x-fa::field :label="$f['label']" for="{{ $feldId }}">
                            <x-fa::input id="{{ $feldId }}" wire:model="canvasForm.{{ $f['key'] }}" />
                        </x-fa::field>
                    @else
                        {{-- Wächst mit dem Inhalt statt intern zu scrollen (Dominique 2026-07-21).
                             x-effect auf $wire.canvasForm passt die Höhe auch nach Laden/Speichern/Wechsel an. --}}
                        <x-fa::field :label="$f['label']" for="{{ $feldId }}">
                            <x-fa::textarea id="{{ $feldId }}" wire:model="canvasForm.{{ $f['key'] }}" rows="2"
                                x-data
                                x-effect="$wire.canvasForm; $el.style.height='auto'; $el.style.height=$el.scrollHeight+'px'"
                                x-on:input="$el.style.height='auto'; $el.style.height=$el.scrollHeight+'px'"
                                class="resize-none overflow-hidden min-h-[3.5rem]" />
                        </x-fa::field>
                    @endif
                @endforeach
            </div>
        </x-fa::section>
    @endforeach

    {{-- hideSave: der Concepter blendet das eigene Speichern aus und sichert über EIN Reiter-Speichern
         (konzeptSpeichern). Alle anderen Einbindungen zeigen es weiter. --}}
    @unless($hideSave ?? false)
        <div class="flex items-center justify-end gap-3">
            <span wire:loading wire:target="canvasSpeichern" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Wird gespeichert …</span>
            <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="canvasSpeichern" data-canvas-speichern>Speichern</x-fa::button>
        </div>
    @endunless
</div>
