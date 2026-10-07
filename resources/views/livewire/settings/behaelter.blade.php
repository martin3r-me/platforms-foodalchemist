{{-- R5: Behälter & Geräte — 3 Container-Vokabulare (D-6 §4.6) + Koch-Equipment (D-5 §2.3), mit Anlegen.
     Spec 51: der Behälter-Katalog trägt jetzt Maße und Freigaben, damit der Bedarf gerechnet werden
     kann statt getippt — und damit neue Lager-/Regenerationsbehälter ohne Deployment dazukommen.
     fa-pass 2026-10-05: je Liste ein Abschnitt (Anordnung wie bisher: Einträge nach Gruppe, darunter
     Bearbeiten und Anlegen). Einträge als Chips; Bearbeiten, Deaktivieren und Löschen erscheinen beim
     Überfahren oder per Tastatur-Fokus. Der Tooltip zeigt Maße und Freigaben in Klartext. --}}
@php
    $chipKnopf = 'inline-flex items-center justify-center w-5 h-5 rounded-full transition-colors';
@endphp

<div class="flex flex-col gap-4" data-settings-behaelter>
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei --}}
    @php $sperrLesen = in_array($sperr['modus'], ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    @if($fehler !== null)<x-fa::notice tone="crit" data-behaelter-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($meldung !== null)<x-fa::notice tone="ok" data-behaelter-meldung>{{ $meldung }}</x-fa::notice>@endif

    @foreach($listen as $key => $vokabular)
        @php($mitGruppen = $vokabular['zeilen']->pluck('group_name')->filter()->isNotEmpty())
        <x-fa::section :title="$vokabular['label']" :meta="$vokabular['zeilen']->count() . ' Einträge'" data-vokabular="{{ $key }}">
            @if($vokabular['zeilen']->isEmpty())
                <x-fa::empty compact icon="heroicon-o-archive-box" title="Noch keine Einträge">Unten den ersten anlegen.</x-fa::empty>
            @else
                <div class="flex flex-col gap-2">
                    @foreach($vokabular['zeilen']->groupBy(fn ($z) => $z->group_name ?? 'sonstig') as $gruppe => $zeilen)
                        <div class="flex flex-col sm:flex-row sm:items-start gap-1.5 sm:gap-3">
                            @if($mitGruppen)
                                <span class="shrink-0 sm:w-36 pt-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">{{ $gruppe === 'sonstig' ? 'Ohne Gruppe' : $gruppe }}</span>
                            @endif
                            <div class="flex flex-wrap gap-1.5 min-w-0">
                                @foreach($zeilen as $zeile)
                                    @php($masse = $vokabular['kapazitaet'] ? \Illuminate\Support\Str::after(\Platform\FoodAlchemist\Livewire\Settings\Behaelter::titel($zeile), ' · ') : ($zeile->is_inactive ? 'deaktiviert' : ''))
                                    <span wire:key="vk-{{ $key }}-{{ $zeile->id }}" tabindex="0"
                                          class="group inline-flex items-center gap-1 h-7 pl-2.5 pr-1.5 rounded-[var(--fa-radius-pill)] border border-[var(--fa-line)] bg-[var(--fa-surface)] text-[length:var(--fa-text-md)] text-[var(--fa-ink)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--fa-accent)] {{ $zeile->is_inactive ? 'opacity-60 line-through' : '' }}"
                                          @if($masse !== '') title="{{ $masse }}" @endif>
                                        {{ $zeile->name }}
                                        <span class="hidden group-hover:inline-flex group-focus-within:inline-flex group-focus:inline-flex items-center">
                                            @if($vokabular['kapazitaet'])
                                                <button type="button" wire:click="bearbeitenStart({{ $zeile->id }})"
                                                        class="{{ $chipKnopf }} text-[var(--fa-ink-3)] hover:text-[var(--fa-accent)] hover:bg-[var(--fa-hover)]" title="Bearbeiten" aria-label="{{ $zeile->name }} bearbeiten"
                                                        data-behaelter-edit="{{ $zeile->id }}">@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5')</button>
                                            @endif
                                            <button type="button" wire:click="toggleInactive('{{ $key }}', {{ $zeile->id }})"
                                                    class="{{ $chipKnopf }} text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]"
                                                    title="{{ $zeile->is_inactive ? 'Aktivieren' : 'Deaktivieren' }}" aria-label="{{ $zeile->name }} {{ $zeile->is_inactive ? 'aktivieren' : 'deaktivieren' }}">@if($zeile->is_inactive)
                                                    @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5')
                                                @else
                                                    @svg('heroicon-o-eye-slash', 'w-3.5 h-3.5')
                                                @endif</button>
                                            <button type="button" wire:click="delete('{{ $key }}', {{ $zeile->id }})" wire:confirm="Diesen Eintrag löschen?"
                                                    class="{{ $chipKnopf }} text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]" title="Löschen (nur wenn ungenutzt)" aria-label="{{ $zeile->name }} löschen">@svg('heroicon-o-trash', 'w-3.5 h-3.5')</button>
                                        </span>
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if($vokabular['kapazitaet'] && $editId !== null)
                <div class="flex flex-col gap-3 p-3 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)]" data-behaelter-editform>
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Behälter bearbeiten</p>
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Die interne Kennung bleibt gleich, Rezepte hängen daran.</p>
                    </div>
                    @include('foodalchemist::livewire.settings.partials.behaelter-felder', ['praefix' => 'edit', 'f' => $edit])
                    <div class="flex justify-end gap-2">
                        <x-fa::button size="sm" variant="ghost" wire:click="zeileAbbrechen">Abbrechen</x-fa::button>
                        <x-fa::button size="sm" variant="primary" wire:click="bearbeitenSpeichern" data-behaelter-edit-speichern>Speichern</x-fa::button>
                    </div>
                </div>
            @endif

            <div class="pt-3 border-t border-[var(--fa-line)]" data-vokabular-anlegen="{{ $key }}">
                @if($vokabular['kapazitaet'])
                    <div class="flex flex-col gap-3">
                        <p class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Neuer Behälter</p>
                        @include('foodalchemist::livewire.settings.partials.behaelter-felder', ['praefix' => "neu.{$key}", 'f' => $neu[$key]])
                        <div class="flex justify-end">
                            <x-fa::button :variant="$editId === null ? 'primary' : 'secondary'" icon="heroicon-m-plus" wire:click="create('{{ $key }}')" data-vokabular-neu="{{ $key }}">Behälter anlegen</x-fa::button>
                        </div>
                    </div>
                @else
                    <div class="flex flex-wrap items-end gap-2">
                        <x-fa::field label="Neuer Eintrag" for="vk-neu-{{ $key }}-name" class="w-56 max-w-full">
                            <x-fa::input id="vk-neu-{{ $key }}-name" wire:model="neu.{{ $key }}.name" placeholder="Name" />
                        </x-fa::field>
                        <x-fa::field label="Gruppe" for="vk-neu-{{ $key }}-gruppe" optional class="w-48 max-w-full">
                            <x-fa::input id="vk-neu-{{ $key }}-gruppe" wire:model="neu.{{ $key }}.group_name" />
                        </x-fa::field>
                        <x-fa::button icon="heroicon-m-plus" wire:click="create('{{ $key }}')" data-vokabular-neu="{{ $key }}">Anlegen</x-fa::button>
                    </div>
                @endif
            </div>
        </x-fa::section>
    @endforeach
    </fieldset>
</div>
