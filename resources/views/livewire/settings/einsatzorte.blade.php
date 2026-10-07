{{-- #469: Einsatzorte/Layer — Bindungs-Ziele fürs Wissen (Bereiche grob + Prompts fein)
     Seit Spec 52 · F3 nicht mehr in der Navigation (Bindungen wirken nicht mehr); die Komponente bleibt,
     solange der Wissens-Browser Alt-Bindungen daraus beschriftet.
     fa-pass 2026-10-05: Bausteine/Tokens, je Liste eine Tabelle; die technische Kennung steht klein unter dem Namen. --}}
<div class="flex flex-col gap-4" data-settings-einsatzorte>
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei --}}
    @php $sperrLesen = in_array($sperr['modus'], ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">

    {{-- Der Eigentums-Riegel muss sichtbar sein: sonst klickt jemand auf einen geerbten
         Einsatzort und es passiert wortlos nichts. --}}
    @if($fehler !== null)<x-fa::notice tone="crit" data-einsatzort-fehler>{{ $fehler }}</x-fa::notice>@endif

    @foreach([['Bereiche', 'Grobe Ziele, an die Wissen gebunden war.', $bereiche], ['KI-Schritte', 'Feine Ziele: einzelne Arbeitsschritte der KI.', $prompts]] as [$titel, $satz, $liste])
        <x-fa::section :title="$titel" :description="$satz" :meta="$liste->count() . ' Einträge'">
            <div class="overflow-x-auto -mx-4">
                <table class="fa-table">
                    <thead>
                        <tr>
                            <th class="w-1/3">Name</th>
                            <th class="w-full">Beschreibung</th>
                            <th class="num">Bindungen</th>
                            <th><span class="sr-only">Aktionen</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($liste as $l)
                            <tr class="{{ $l->active ? '' : 'opacity-60' }}" wire:key="layer-{{ $l->id }}">
                                @if($editId === $l->id)
                                    <td>
                                        <x-fa::input size="sm" wire:model="form.label" aria-label="Name" class="w-full min-w-40" />
                                        <span class="block mt-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $l->slug }}</span>
                                    </td>
                                    <td colspan="2"><x-fa::input size="sm" wire:model="form.description" aria-label="Beschreibung" class="w-full" placeholder="Beschreibung (optional)" /></td>
                                    <td class="whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <x-fa::button size="sm" variant="ghost" wire:click="cancel">Abbrechen</x-fa::button>
                                            <x-fa::button size="sm" variant="primary" wire:click="save">Speichern</x-fa::button>
                                        </div>
                                    </td>
                                @else
                                    <td>
                                        <span class="flex flex-wrap items-center gap-1.5 font-medium">{{ $l->label }}@unless($l->active)<x-fa::badge>inaktiv</x-fa::badge>@endunless</span>
                                        <span class="block mt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $l->slug }}</span>
                                    </td>
                                    <td class="text-[var(--fa-ink-2)]">{{ $l->description ?? '' }}</td>
                                    <td class="num text-[var(--fa-ink-2)]">{{ $bindCounts[$l->slug] ?? 0 }}</td>
                                    <td class="whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-1">
                                            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-pencil-square" wire:click="edit({{ $l->id }})">Bearbeiten</x-fa::button>
                                            <x-fa::button size="sm" variant="ghost" wire:click="toggleActive({{ $l->id }})">{{ $l->active ? 'Deaktivieren' : 'Aktivieren' }}</x-fa::button>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="4"><x-fa::empty compact title="Keine Einträge" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-fa::section>
    @endforeach
    </fieldset>
</div>
