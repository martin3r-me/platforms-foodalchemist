{{-- Wiederverwendbares Canvas-Board (Trait ManagesCanvas). Rendert das feste Template je canvas_type.
     Eingebunden in Food DNA, Einstellungen, Kunden-DNA, Angebot, Foodbook-Rail und Concepter-Editor
     (dort im Werkbank-Modus und ohne eigenes Speichern).
     fa-pass 2026-10-05: auf Bausteine <x-fa::…> umgestellt (nur Tokens, stimmt hell und dunkel).
     Felder, wire:model, Methoden und data-Marker unverändert. --}}
@php
    $canvasTpl = $this->canvasTemplateData();
    // Spec 82 §7 — nur an der Team-DNA gefüllt, sonst leer/false: die anderen Hosts bleiben unverändert
    $verer = $this->canvasVererbung();
    $verbMoeglich = $this->canvasVerbindlichMoeglich();
    $reiter = $this->canvasReiter() && count($canvasTpl['gruppen']) > 1;
    $gruppenNamen = array_keys($canvasTpl['gruppen']);
@endphp

<div class="flex flex-col gap-3 min-w-0" data-canvas-board="{{ $canvasType }}"
     @if($reiter) x-data="{ tab: @js($gruppenNamen[0] ?? '') }" @endif>
    @if($reiter)
        <div class="flex flex-wrap gap-1 border-b border-[var(--fa-line)]" role="tablist" data-canvas-reiter>
            @foreach($gruppenNamen as $gn)
                <button type="button" role="tab" x-on:click="tab = @js($gn)" :aria-selected="tab === @js($gn)"
                        :class="tab === @js($gn) ? 'border-[var(--fa-accent)] text-[var(--fa-ink)]' : 'border-transparent text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]'"
                        class="px-3 py-2 text-[length:var(--fa-text-md)] font-medium border-b-2 -mb-px" data-canvas-reiter-tab="{{ md5($gn) }}">{{ $gn }}</button>
            @endforeach
        </div>
    @endif
    @if($canvasGespeichert)
        <x-fa::notice tone="ok" data-canvas-gespeichert>Gespeichert. Fließt als Rahmen in die KI-Texte ein.</x-fa::notice>
    @endif

    @foreach($canvasTpl['gruppen'] as $gruppe => $felder)
        <div wire:key="canvas-gruppe-{{ md5($gruppe) }}" @if($reiter) x-show="tab === @js($gruppe)" @endif>
        <x-fa::section :title="$gruppe">
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
                                {{-- geerbt vom Oberteam: nur lesbar, verbindliche Einträge bleiben immer --}}
                                @foreach(collect($verer[$f['key']]['wert'] ?? [])->filter(fn ($i) => (int) $i['quelle_team_id'] !== (int) ($canvasOwnerId ?? 0)) as $g)
                                    <div wire:key="welt-geerbt-{{ $g['id'] }}" class="flex items-start gap-2 rounded-[var(--fa-radius-control)] border border-dashed border-[var(--fa-line)] px-3 py-2" data-canvas-geerbt>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)] break-words"><span class="font-medium">{{ $g['value'] }}</span>@if(!empty($g['meta']['claim']))<span class="text-[var(--fa-ink-2)]"> · {{ $g['meta']['claim'] }}</span>@endif</p>
                                        </div>
                                        <x-fa::badge tone="{{ $g['verbindlich'] ? 'accent' : 'neutral' }}">{{ $g['verbindlich'] ? 'Verbindlich' : 'Geerbt' }}</x-fa::badge>
                                    </div>
                                @endforeach
                                @forelse($canvasWelten as $w)
                                    <div wire:key="welt-{{ $w['id'] }}" class="flex items-start gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] px-3 py-2">
                                        <div class="flex-1 min-w-0">
                                            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)] break-words">
                                                <span class="font-medium">{{ $w['value'] }}</span>
                                                @if($w['claim'])<span class="text-[var(--fa-ink-2)]"> · {{ $w['claim'] }}</span>@endif
                                            </p>
                                            @if($w['description'])<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] break-words">{{ $w['description'] }}</p>@endif
                                        </div>
                                        @if($verbMoeglich)
                                            <x-fa::button size="sm" variant="{{ !empty($w['verbindlich']) ? 'primary' : 'ghost' }}" wire:click="weltVerbindlichSchalten({{ $w['id'] }})" data-canvas-eintrag-verbindlich="{{ $w['id'] }}">{{ !empty($w['verbindlich']) ? 'Verbindlich' : 'Verbindlich machen' }}</x-fa::button>
                                        @elseif(!empty($w['verbindlich']))
                                            <x-fa::badge tone="accent">Verbindlich</x-fa::badge>
                                        @endif
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
                        @php $v = $verer[$f['key']] ?? null; @endphp
                        <x-fa::field :label="$f['label']" for="{{ $feldId }}">
                            <x-fa::select id="{{ $feldId }}" wire:model="canvasForm.{{ $f['key'] }}" :disabled="$v && $v['gesperrt']">
                                <option value="">{{ $v && !$v['eigen'] && $v['wert'] ? 'Wie Oberteam (geerbt)' : 'Neutral, kein eigener Stil' }}</option>
                                @foreach($schreibstile as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                            </x-fa::select>
                        </x-fa::field>
                        @include('foodalchemist::livewire.canvas.partials.feld-vererbung', ['f' => $f, 'v' => $v, 'verbMoeglich' => $verbMoeglich])
                    @elseif(($f['type'] ?? '') === 'text')
                        @php $v = $verer[$f['key']] ?? null; @endphp
                        <x-fa::field :label="$f['label']" for="{{ $feldId }}">
                            @if($v && $v['gesperrt'])
                                <x-fa::input id="{{ $feldId }}" value="{{ $v['wert'] }}" disabled data-canvas-gesperrt="{{ $f['key'] }}" />
                            @else
                                <x-fa::input id="{{ $feldId }}" wire:model="canvasForm.{{ $f['key'] }}" placeholder="{{ $v && !$v['eigen'] && is_string($v['wert']) ? $v['wert'] : '' }}" />
                            @endif
                        </x-fa::field>
                        @include('foodalchemist::livewire.canvas.partials.feld-vererbung', ['f' => $f, 'v' => $v, 'verbMoeglich' => $verbMoeglich])
                    @else
                        {{-- Wächst mit dem Inhalt statt intern zu scrollen (Dominique 2026-07-21).
                             x-effect auf $wire.canvasForm passt die Höhe auch nach Laden/Speichern/Wechsel an. --}}
                        @php $v = $verer[$f['key']] ?? null; @endphp
                        <x-fa::field :label="$f['label']" for="{{ $feldId }}">
                            @if($v && $v['gesperrt'])
                                <x-fa::textarea id="{{ $feldId }}" rows="2" disabled data-canvas-gesperrt="{{ $f['key'] }}" class="resize-none min-h-[3.5rem]">{{ $v['wert'] }}</x-fa::textarea>
                            @else
                                <x-fa::textarea id="{{ $feldId }}" wire:model="canvasForm.{{ $f['key'] }}" rows="2"
                                    placeholder="{{ $v && !$v['eigen'] && is_string($v['wert']) ? \Illuminate\Support\Str::limit($v['wert'], 120) : '' }}"
                                    x-data
                                    x-effect="$wire.canvasForm; $el.style.height='auto'; $el.style.height=$el.scrollHeight+'px'"
                                    x-on:input="$el.style.height='auto'; $el.style.height=$el.scrollHeight+'px'"
                                    class="resize-none overflow-hidden min-h-[3.5rem]" />
                            @endif
                        </x-fa::field>
                        @include('foodalchemist::livewire.canvas.partials.feld-vererbung', ['f' => $f, 'v' => $v, 'verbMoeglich' => $verbMoeglich])
                    @endif
                @endforeach
            </div>
        </x-fa::section>
        </div>
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
