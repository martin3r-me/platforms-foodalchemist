{{-- Spec 94b · Leistungskategorien: Vokabular der Leistungs-Vorlagen und Konfigurator-Leistungen (Gruppen der Zusammenfassung).
     Eigene: umbenennen, Reihenfolge, deaktivieren. Geerbte: nur für dieses Team aus- bzw. einschalten. --}}
@php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
<div class="flex flex-col gap-4">
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    @if($fehler)<x-fa::notice tone="crit" data-lk-fehler>{{ $fehler }}</x-fa::notice>@endif

    <x-fa::section title="Leistungskategorien" :meta="$liste->count() . ' Kategorien'"
        description="Gruppen für Personal, Equipment, Logistik und was ihr sonst als Leistung abrechnet. Die Zusammenfassung im Konfigurator zeigt genau die Kategorien, die vorkommen.">
        <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
        <div class="overflow-x-auto -mx-4">
            <table class="fa-table">
                <thead>
                    <tr>
                        <th class="w-full">Name</th>
                        <th>Kürzel</th>
                        <th class="num">Reihenfolge</th>
                        <th>Status</th>
                        <th><span class="sr-only">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($liste as $e)
                        @php $k = $e->kategorie; $aus = $e->eigen ? $k->is_inactive : ($e->aus_hier || $e->aus_oben); @endphp
                        <tr wire:key="lk-{{ $k->id }}" class="{{ $aus ? 'opacity-60' : '' }}" data-lk-zeile="{{ $k->slug }}">
                            @if($editId === $k->id)
                                <td><x-fa::input size="sm" wire:model="form.name" wire:keydown.enter="save" wire:keydown.escape="cancel" aria-label="Name" class="w-full min-w-32" /></td>
                                <td class="text-[var(--fa-ink-2)]">{{ $k->slug }}</td>
                                <td class="num"><x-fa::input size="sm" type="number" numeric wire:model="form.sort_order" aria-label="Reihenfolge" class="w-20" /></td>
                                <td></td>
                                <td class="whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-fa::button size="sm" variant="ghost" wire:click="cancel">Abbrechen</x-fa::button>
                                        <x-fa::button size="sm" variant="primary" wire:click="save">Speichern</x-fa::button>
                                    </div>
                                </td>
                            @else
                                <td class="font-medium">{{ $k->name }}</td>
                                <td class="text-[var(--fa-ink-2)]">{{ $k->slug }}</td>
                                <td class="num text-[var(--fa-ink-2)]">{{ $k->sort_order }}</td>
                                <td class="whitespace-nowrap">
                                    @unless($e->eigen)<x-fa::badge title="Aus dem Katalog der Plattform oder eines Eltern-Teams. Ändern kann nur das Team, dem sie gehört.">geerbt</x-fa::badge>@endunless
                                    @if($aus)<x-fa::badge>{{ $e->aus_oben ? 'ausgeschaltet (Eltern-Team)' : 'ausgeschaltet' }}</x-fa::badge>@endif
                                </td>
                                <td class="whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1">
                                        @if($e->eigen)
                                            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-pencil-square" wire:click="edit({{ $k->id }})">Bearbeiten</x-fa::button>
                                        @endif
                                        @unless($e->aus_oben)
                                            <x-fa::button size="sm" variant="ghost" :icon="$aus ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-eye-slash'"
                                                wire:click="schalten({{ $k->id }}, {{ $aus ? 'true' : 'false' }})" data-lk-schalten="{{ $k->slug }}">{{ $aus ? 'Einschalten' : 'Ausschalten' }}</x-fa::button>
                                        @endunless
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Ausgeschaltete Kategorien bleiben an bestehenden Leistungen stehen, für neue sind sie nicht wählbar. Geerbte schaltest du nur für euer Team und eure Unter-Teams aus.</p>
        </fieldset>
    </x-fa::section>

    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    <x-fa::section title="Neue Kategorie" data-lk-neu>
        <div class="flex flex-wrap items-end gap-3">
            <x-fa::field label="Name" for="lk-neu" class="flex-1 min-w-[14rem]">
                <x-fa::input id="lk-neu" wire:model="neuName" wire:keydown.enter="create" placeholder="z. B. Technik, Floristik, Reinigung" />
            </x-fa::field>
            <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="create" data-lk-anlegen>Kategorie anlegen</x-fa::button>
        </div>
    </x-fa::section>
    </fieldset>
</div>
