{{-- R5: Behälter & Geräte — 3 Container-Vokabulare (D-6 §4.6) + Koch-Equipment (D-5 §2.3), mit Anlegen.
     Spec 51: der Behälter-Katalog trägt Maße und Freigaben, damit der Bedarf gerechnet werden kann statt getippt.
     E-3c (Dominique 23.10.): Katalog statt Formularwand. Je Liste Name + Zähler, die Einträge als Chips (die ersten 12, der Rest
     auf Klick); Bearbeiten, Deaktivieren und Löschen beim Überfahren/Fokus. „+ Neu“ öffnet einen kompakten Dialog (Art wählen, dann
     nur deren Felder); auch „Behälter bearbeiten“ steht im Dialog. Alle Felder bleiben im DOM (x-show). --}}
@php
    $chipKnopf = 'inline-flex items-center justify-center w-5 h-5 rounded-full transition-colors';
    $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true);
    $sichtbar = 12;
@endphp

<div class="flex flex-col gap-4" data-settings-behaelter x-data="{ neu: false, art: @js(array_key_first($listen)) }" x-on:keydown.escape.window="neu = false">
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei (im Reiter: die Leiste des Reiters) --}}
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    @if($fehler !== null)<x-fa::notice tone="crit" data-behaelter-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($meldung !== null)<x-fa::notice tone="ok" data-behaelter-meldung>{{ $meldung }}</x-fa::notice>@endif

    <div class="flex justify-end">
        <x-fa::button variant="primary" icon="heroicon-m-plus" x-on:click="neu = true" data-behaelter-neu-oeffnen>Neu</x-fa::button>
    </div>

    @foreach($listen as $key => $vokabular)
        @php($mitGruppen = $vokabular['zeilen']->pluck('group_name')->filter()->isNotEmpty())
        <div class="flex flex-col gap-2" data-vokabular="{{ $key }}" x-data="{ alle: false }">
            <h3 class="flex items-center gap-2 text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">
                {{ $vokabular['label'] }}
                <span class="inline-flex items-center px-2 h-5 rounded-full bg-[var(--fa-accent-soft)] text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-accent)] tabular-nums">{{ $vokabular['zeilen']->count() }}</span>
            </h3>
            @if($vokabular['zeilen']->isEmpty())
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Noch keine Einträge, mit „Neu“ den ersten anlegen.</p>
            @else
                @php($nr = 0)
                <div class="flex flex-col gap-2">
                    @foreach($vokabular['zeilen']->groupBy(fn ($z) => $z->group_name ?? 'sonstig') as $gruppe => $zeilen)
                        <div class="flex flex-col sm:flex-row sm:items-start gap-1.5 sm:gap-3">
                            @if($mitGruppen)
                                <span class="shrink-0 sm:w-36 pt-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">{{ $gruppe === 'sonstig' ? 'Ohne Gruppe' : $gruppe }}</span>
                            @endif
                            <div class="flex flex-wrap gap-1.5 min-w-0">
                                @foreach($zeilen as $zeile)
                                    @php($nr++)
                                    @php($masse = $vokabular['kapazitaet'] ? \Illuminate\Support\Str::after(\Platform\FoodAlchemist\Livewire\Settings\Behaelter::titel($zeile), ' · ') : ($zeile->is_inactive ? 'deaktiviert' : ''))
                                    <span wire:key="vk-{{ $key }}-{{ $zeile->id }}" tabindex="0" @if($nr > $sichtbar) x-show="alle" x-cloak @endif
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
                    @if($vokabular['zeilen']->count() > $sichtbar)
                        <button type="button" x-on:click="alle = ! alle" class="self-start text-[length:var(--fa-text-sm)] text-[var(--fa-accent)] hover:underline" data-vokabular-alle="{{ $key }}"
                                x-text="alle ? 'Weniger zeigen' : @js('+ ' . ($vokabular['zeilen']->count() - $sichtbar) . ' weitere')"></button>
                    @endif
                </div>
            @endif
        </div>
    @endforeach

    {{-- Dialog „Neu“: Art wählen, dann nur deren Felder --}}
    <div class="fixed inset-0 z-50 flex items-start justify-center p-4 pt-[10vh] bg-[rgba(19,26,38,0.28)]" x-show="neu" x-cloak x-transition.opacity
         x-on:click.self="neu = false" data-behaelter-dialog>
        <div role="dialog" aria-modal="true" aria-label="Neuer Eintrag" class="w-full max-w-lg max-h-[80vh] overflow-y-auto fa-surface p-5 flex flex-col gap-4 shadow-xl">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">Neu in Behälter & Geräte</h3>
                <x-fa::icon-button icon="heroicon-m-x-mark" label="Schließen" size="sm" x-on:click="neu = false" />
            </div>
            <x-fa::field label="Art" for="behaelter-neu-art">
                <select id="behaelter-neu-art" x-model="art" class="fa-control h-9 w-full text-[length:var(--fa-text-md)]" data-behaelter-neu-art>
                    @foreach($listen as $key => $vokabular)<option value="{{ $key }}">{{ $vokabular['label'] }}</option>@endforeach
                </select>
            </x-fa::field>
            @foreach($listen as $key => $vokabular)
                <div class="flex flex-col gap-3" x-show="art === @js($key)" x-cloak data-vokabular-anlegen="{{ $key }}">
                    @if($vokabular['kapazitaet'])
                        @include('foodalchemist::livewire.settings.partials.behaelter-felder', ['praefix' => "neu.{$key}", 'f' => $neu[$key]])
                    @else
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-fa::field label="Name" for="vk-neu-{{ $key }}-name">
                                <x-fa::input id="vk-neu-{{ $key }}-name" wire:model="neu.{{ $key }}.name" placeholder="Name" />
                            </x-fa::field>
                            <x-fa::field label="Gruppe" for="vk-neu-{{ $key }}-gruppe" optional>
                                <x-fa::input id="vk-neu-{{ $key }}-gruppe" wire:model="neu.{{ $key }}.group_name" />
                            </x-fa::field>
                        </div>
                    @endif
                    <div class="flex justify-end gap-2">
                        <x-fa::button variant="ghost" x-on:click="neu = false">Abbrechen</x-fa::button>
                        <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="create('{{ $key }}')" data-vokabular-neu="{{ $key }}">Anlegen</x-fa::button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Dialog „Behälter bearbeiten“ (serverseitig offen, solange editId gesetzt ist) --}}
    @if($editId !== null)
        <div class="fixed inset-0 z-50 flex items-start justify-center p-4 pt-[10vh] bg-[rgba(19,26,38,0.28)]" wire:click.self="zeileAbbrechen" data-behaelter-editform>
            <div role="dialog" aria-modal="true" aria-label="Behälter bearbeiten" class="w-full max-w-lg max-h-[80vh] overflow-y-auto fa-surface p-5 flex flex-col gap-4 shadow-xl">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">Behälter bearbeiten</h3>
                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Die interne Kennung bleibt gleich, Rezepte hängen daran.</p>
                </div>
                @include('foodalchemist::livewire.settings.partials.behaelter-felder', ['praefix' => 'edit', 'f' => $edit])
                <div class="flex justify-end gap-2">
                    <x-fa::button variant="ghost" wire:click="zeileAbbrechen">Abbrechen</x-fa::button>
                    <x-fa::button variant="primary" wire:click="bearbeitenSpeichern" data-behaelter-edit-speichern>Speichern</x-fa::button>
                </div>
            </div>
        </div>
    @endif
    </fieldset>
</div>
