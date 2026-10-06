{{-- Umbau-Spec Phase 4b: Concepter-Facetten pflegen (F3–F6)
     fa-pass 2026-10-05: je Merkmal ein Abschnitt, untereinander wie bisher (Einträge, darunter Anlegen).
     Einträge als Chips; Deaktivieren und Löschen erscheinen beim Überfahren oder per Tastatur-Fokus.
     Servierformen aus der Warenwirtschaft lassen sich nur deaktivieren, nicht löschen. --}}
@php
    $chipKnopf = 'inline-flex items-center justify-center w-5 h-5 rounded-full transition-colors';
@endphp

<div class="flex flex-col gap-4" data-settings-concepter-dimensionen>
    @if($fehler !== null)<x-fa::notice tone="crit" data-dimensionen-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($meldung !== null)<x-fa::notice tone="ok" data-dimensionen-meldung>{{ $meldung }}</x-fa::notice>@endif

    @foreach($listen as $key => $vokabular)
        <x-fa::section :title="$vokabular['label']" :meta="$vokabular['zeilen']->count()" :description="$vokabular['hint']" data-dimension="{{ $key }}">
            @if($vokabular['zeilen']->isEmpty())
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Noch keine Einträge. Unten den ersten anlegen.</p>
            @else
                <div class="flex flex-wrap gap-1.5">
                    @foreach($vokabular['zeilen'] as $zeile)
                        @php($ausWawi = $key === 'servierformen' && $zeile->legacy_id !== null)
                        <span wire:key="dim-{{ $key }}-{{ $zeile->id }}" tabindex="0"
                              class="group inline-flex items-center gap-1 h-7 pl-2.5 pr-1.5 rounded-[var(--fa-radius-pill)] border border-[var(--fa-line)] bg-[var(--fa-surface)] text-[length:var(--fa-text-md)] text-[var(--fa-ink)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--fa-accent)] {{ $zeile->is_inactive ? 'opacity-60 line-through' : '' }}"
                              @if($key === 'servierformen') title="{{ $ausWawi ? 'Aus der Warenwirtschaft' : 'Eigener Eintrag' }}" @endif>
                            {{ $zeile->label ?? $zeile->name }}
                            <span class="hidden group-hover:inline-flex group-focus-within:inline-flex group-focus:inline-flex items-center">
                                <button type="button" wire:click="toggleInactive('{{ $key }}', {{ $zeile->id }})"
                                        class="{{ $chipKnopf }} text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]"
                                        title="{{ $zeile->is_inactive ? 'Aktivieren' : 'Deaktivieren' }}" aria-label="{{ $zeile->label ?? $zeile->name }} {{ $zeile->is_inactive ? 'aktivieren' : 'deaktivieren' }}">@if($zeile->is_inactive)
                                        @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5')
                                    @else
                                        @svg('heroicon-o-eye-slash', 'w-3.5 h-3.5')
                                    @endif</button>
                                @if(! $ausWawi)
                                    <button type="button" wire:click="delete('{{ $key }}', {{ $zeile->id }})" wire:confirm="Diesen Eintrag löschen?"
                                            class="{{ $chipKnopf }} text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]" title="Löschen (nur wenn ungenutzt)" aria-label="{{ $zeile->label ?? $zeile->name }} löschen">@svg('heroicon-o-trash', 'w-3.5 h-3.5')</button>
                                @endif
                            </span>
                        </span>
                    @endforeach
                </div>
            @endif

            <div class="flex gap-1.5 pt-3 border-t border-[var(--fa-line)]" data-dimension-anlegen="{{ $key }}">
                <label for="dim-neu-{{ $key }}" class="sr-only">Neuer Eintrag in {{ $vokabular['label'] }}</label>
                <x-fa::input id="dim-neu-{{ $key }}" size="sm" wire:model="neu.{{ $key }}" placeholder="Neuer Eintrag" class="flex-1 min-w-0"
                    wire:keydown.enter="create('{{ $key }}')" />
                <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="create('{{ $key }}')">Anlegen</x-fa::button>
            </div>
        </x-fa::section>
    @endforeach
</div>
