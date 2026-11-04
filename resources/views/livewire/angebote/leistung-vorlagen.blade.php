{{-- Spec 94 · Leistungs-Vorlagen: Personal, Equipment, Logistik, Sonstiges mit Mengenregel, EK und VK. Konfiguratoren kopieren sie. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $f = $form;
    $euro = fn ($w) => $w !== null && $w !== '' ? number_format((float) $w, 2, ',', '.') . ' €' : '–';
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-foodalchemist::shell.page-navbar title="Leistungs-Vorlagen" icon="heroicon-o-wrench-screwdriver" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <div><x-fa::button size="sm" variant="ghost" icon="heroicon-m-arrow-left" :href="route('foodalchemist.angebote.konfiguratoren')" data-lv-zurueck>Konfiguratoren</x-fa::button></div>
        <x-fa::page-header title="Leistungs-Vorlagen"
            subtitle="Personal, Equipment, Logistik und Sonstiges einmal mit Mengenregel, EK und VK pflegen. Konfiguratoren übernehmen die Vorlagen als Kopie; der VK kommt aus der Leistungs-Aufschlagsklasse oder wird mit Begründung fest gesetzt." />

        @if($fehler)<x-fa::notice tone="crit" data-lv-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($hinweis)<x-fa::notice tone="info">{{ $hinweis }}</x-fa::notice>@endif
        @if($klassen->isEmpty())
            <x-fa::notice tone="warn" title="Keine Leistungs-Aufschlagsklasse">
                Ohne Leistungsklasse rechnet der VK-Vorschlag mit dem Basissatz und 100 %. Leistungsklassen (z. B. Personal, Logistik) legst du unter Einstellungen → Aufschlagsklassen mit „Gilt für: Leistungen“ an.
            </x-fa::notice>
        @endif

        @if($editId !== null)
            <x-fa::section :title="$editId === 0 ? 'Neue Vorlage' : 'Vorlage bearbeiten'" icon="heroicon-o-pencil-square" data-lv-formular>
                <form wire:submit="speichern" class="flex flex-col gap-4">
                    @include('foodalchemist::livewire.angebote.partials.leistung-regel', ['f' => $f, 'm' => 'form', 'sm' => 'stufenText', 'pm' => 'planungText', 'px' => 'lv'])

                    <x-fa::field label="Notiz" for="lv-notiz" optional><x-fa::textarea id="lv-notiz" wire:model="form.notiz" rows="2" /></x-fa::field>

                    <div class="flex flex-wrap justify-end gap-2">
                        <x-fa::button variant="ghost" wire:click="abbrechen">Abbrechen</x-fa::button>
                        <x-fa::button type="submit" variant="primary" icon="heroicon-m-check" data-lv-speichern>Speichern</x-fa::button>
                    </div>
                </form>
            </x-fa::section>
        @endif

        <x-fa::section title="Vorlagen" icon="heroicon-o-wrench-screwdriver" :meta="$gesamt" data-lv-liste>
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div class="flex flex-wrap items-end gap-2">
                    <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="suche" placeholder="Vorlage suchen …" class="w-64" aria-label="Vorlage suchen" />
                    <x-fa::select size="sm" wire:model.live="kategorieFilter" :options="$kategorien" placeholder="Alle Kategorien" class="w-48" aria-label="Kategorie" />
                </div>
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="neu('{{ $kategorieFilter }}')" data-lv-neu>Neue Vorlage</x-fa::button>
            </div>
            @if($gruppen->isEmpty())
                <x-fa::empty compact icon="heroicon-o-wrench-screwdriver" title="{{ $suche === '' && $kategorieFilter === '' ? 'Noch keine Vorlagen' : 'Keine Vorlage passt' }}">
                    Lege z. B. „Servicekraft, 1 je 30 Gäste, EK 30 € je Stunde“ oder „LKW, 1 je 250 Gäste“ an.
                </x-fa::empty>
            @else
                <table class="fa-table fa-table--compact" data-lv-tabelle>
                    <thead><tr><th>Leistung</th><th>Menge</th><th>Preis je</th><th class="num">EK</th><th class="num">VK</th><th class="num">Aufschlag</th><th></th></tr></thead>
                    <tbody>
                        @foreach($gruppen as $kategorie => $vorlagen)
                            <tr wire:key="lvk-{{ $kategorie }}" class="bg-[var(--fa-ground)]"><td colspan="7" class="font-semibold text-[var(--fa-ink-2)]">{{ $kategorieNamen[$kategorie] ?? $kategorie }} <span class="{{ $leise }} font-normal">· {{ $vorlagen->count() }}</span></td></tr>
                            @foreach($vorlagen as $v)
                                @php
                                    $eigen = $v->isOwnedBy($team);
                                    $aufschlag = $v->vk_satz !== null && (float) $v->ek_satz > 0 ? ((float) $v->vk_satz / (float) $v->ek_satz - 1) * 100 : null;
                                @endphp
                                <tr wire:key="lv-{{ $v->id }}" class="{{ $v->aktiv ? '' : 'opacity-60' }}" data-lv-zeile="{{ $v->id }}">
                                    <td>
                                        <span class="font-medium">{{ $v->bezeichnung }}</span>
                                        @if(! $eigen)<x-fa::badge class="ml-1.5" title="Vom Oberteam geerbt, nur lesbar">Geerbt</x-fa::badge>@endif
                                        @if(! $v->aktiv)<x-fa::badge class="ml-1.5">Inaktiv</x-fa::badge>@endif
                                        @if($v->notiz)<div class="{{ $leise }}">{{ $v->notiz }}</div>@endif
                                    </td>
                                    <td>{{ \Platform\FoodAlchemist\Livewire\Angebote\LeistungVorlagen::regelText($v) }}</td>
                                    <td>
                                        {{ $v->preis_je === 'stunde' ? 'Stunde' : 'Stück' }}
                                        @if($v->preis_je === 'stunde')<div class="{{ $leise }}">{{ $v->stunden_quelle === 'fest' ? number_format((float) $v->stunden, 1, ',', '.') . ' h fest' : 'Einsatzzeit' . ($v->stunden_zuschlag ? ' + ' . number_format((float) $v->stunden_zuschlag, 1, ',', '.') . ' h' : '') }}</div>@endif
                                        @if($v->haeufigkeit === 'je_tageszeit')<div class="{{ $leise }}">je Tageszeit</div>@endif
                                    </td>
                                    <td class="num">{{ $euro($v->ek_satz) }}</td>
                                    <td class="num">
                                        {{ $euro($v->vk_satz) }}
                                        <div class="{{ $leise }}">{{ $v->vk_modus === 'fixed' ? 'fest' : ($v->markupClass?->code ?? 'ohne Klasse') }}</div>
                                    </td>
                                    <td class="num">{{ $aufschlag !== null ? number_format($aufschlag, 0, ',', '.') . ' %' : '–' }}</td>
                                    <td class="text-right whitespace-nowrap">
                                        @if($eigen)
                                            <x-fa::button size="sm" variant="ghost" wire:click="aktivUmschalten({{ $v->id }})">{{ $v->aktiv ? 'Deaktivieren' : 'Aktivieren' }}</x-fa::button>
                                            <x-fa::button size="sm" wire:click="bearbeiten({{ $v->id }})" data-lv-bearbeiten="{{ $v->id }}">Bearbeiten</x-fa::button>
                                            <x-fa::button size="sm" variant="danger" wire:click="loeschen({{ $v->id }})" wire:confirm="Vorlage „{{ $v->bezeichnung }}“ löschen? Kopien in Konfiguratoren bleiben.">Löschen</x-fa::button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-fa::section>
    </x-ui-page-container>
</x-ui-page>
