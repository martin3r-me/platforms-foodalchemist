{{-- M7-07: Küchen-Profil — Soft-Default des Generators (explizite Hooks gewinnen)
     fa-pass 2026-10-05: Bausteine/Tokens. Speicher-Leiste bleibt oben (EinstellungenSchirmTest).
     Küchentyp als Auswahlkarten (Name + Merkmale), Typ-Farben als eigene Gruppe darunter. --}}
<div class="flex flex-col gap-4" data-settings-kueche>

    {{-- Spec 65: erst „Bearbeiten" (sperrt den Bereich für das Team), dann Abbrechen/Speichern --}}
    @php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => false, 'hint' => 'Grundrichtung für Rezeptvorschläge.'])
    @unless($sperrLesen)
    <x-foodalchemist::save-bar :meldung="$meldung" data-kueche-meldung
        hint="Grundrichtung für Rezeptvorschläge. Was du im Rezept ausdrücklich vorgibst, geht immer vor." />
    @endunless
    @if($sperrLesen && $meldung)<x-fa::notice tone="ok" data-kueche-meldung>{{ $meldung }}</x-fa::notice>@endif

    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">

    <x-fa::section title="Küchentyp" description="Gibt der KI die Grundrichtung für Chargen, Technik und Convenience vor.">
        <div class="grid gap-2 grid-cols-[repeat(auto-fit,minmax(min(100%,20rem),1fr))]" role="radiogroup" aria-label="Küchentyp" data-kueche-typen>
            @php($optionen = ['' => 'Kein Profil (Vorschläge ohne Grundrichtung)'] + $typen)
            @foreach($optionen as $slug => $description)
                @php($teile = explode(' (', $description, 2))
                <label class="flex items-start gap-2.5 p-3 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] cursor-pointer transition-colors hover:bg-[var(--fa-hover)] has-[:checked]:border-[var(--fa-accent)] has-[:checked]:bg-[var(--fa-accent-soft)]"
                       wire:key="kt-{{ $slug === '' ? 'kein' : $slug }}">
                    <input type="radio" wire:model="kuechenTyp" value="{{ $slug }}" class="mt-0.5 accent-[var(--fa-accent)]" />
                    <span class="min-w-0">
                        <span class="block text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $teile[0] }}</span>
                        @if(isset($teile[1]))<span class="block mt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ rtrim($teile[1], ')') }}</span>@endif
                    </span>
                </label>
            @endforeach
        </div>
    </x-fa::section>

    {{-- Phase 5: Typ-Farben — GP / Basisrezept / Gericht durchgängig im Editor + Concepter --}}
    <x-fa::section title="Farben je Positionstyp" data-settings-typfarben
        description="Kennzeichnet Grundprodukte, Basisrezepte und Gerichte überall gleich: in Listen, Positionstabellen und im Concepter.">
        <x-slot:actions>
            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-arrow-path" wire:click="farbenZuruecksetzen">Standardfarben einsetzen</x-fa::button>
        </x-slot:actions>
        <div class="flex flex-wrap gap-3">
            @foreach($farbTypen as $key => $label)
                <label class="inline-flex items-center gap-2.5 h-11 pl-1.5 pr-3 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] text-[length:var(--fa-text-md)] text-[var(--fa-ink)] cursor-pointer hover:bg-[var(--fa-hover)]" wire:key="tf-{{ $key }}">
                    <input type="color" wire:model="typFarben.{{ $key }}" aria-label="Farbe für {{ $label }}"
                           class="h-8 w-9 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-transparent cursor-pointer p-0.5" />
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block w-3 h-3 rounded-full" style="background-color: {{ $typFarben[$key] }}"></span>
                        {{ $label }}
                    </span>
                </label>
            @endforeach
        </div>
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Neue Farben gelten nach dem Speichern oben.</p>
    </x-fa::section>
    </fieldset>

</div>
