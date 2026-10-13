{{-- Spec 82 §9 · Standortprofil: Eigene Standorte (Teamprofil) + im Master-Team Kunden-Standorte aus Projekten. --}}
<div class="flex flex-col gap-4" data-fa-standortprofil>
    <x-fa::page-header title="Standortprofil" subtitle="Was die Küche am Standort kann: Geräte, Flächen, Gäste parallel. Das ist keine DNA, sondern Fakten." />

    @if($standortId === 0)
        <x-fa::section title="Eigene Standorte" description="Das Profil deines Teams. Gepflegt wird es in den Einstellungen." data-standortprofil-eigene>
            <div class="flex flex-wrap items-center gap-3">
                <x-fa::badge tone="neutral">Gäste parallel: {{ $eigenes['profil']['max_pax_parallel'] ?? 'unbekannt' }}</x-fa::badge>
                <x-fa::badge tone="neutral">Geräte bekannt: {{ $eigeneBekannt }} von {{ $eigeneGesamt }}</x-fa::badge>
                <x-fa::badge tone="neutral">Flächen: {{ count($eigenes['flaechen']) }}</x-fa::badge>
                <x-fa::button size="sm" variant="ghost" icon="heroicon-o-pencil-square" :href="route('foodalchemist.einstellungen', 'teamprofil')">Teamprofil pflegen</x-fa::button>
            </div>
        </x-fa::section>

        @if($istMaster)
            <x-fa::section title="Kunden-Standorte · aus Projekten" description="Standorte der Kunden aus den Projekten (Agentur-Akten). Bei der Lizenz werden sie zu Teamprofilen." data-standortprofil-kunden>
                @if($fehler)<x-fa::notice tone="warn" data-standortprofil-fehler>{{ $fehler }}</x-fa::notice>@endif
                @if($kunden->isEmpty())
                    <x-fa::empty compact icon="heroicon-o-map-pin" title="Noch kein Kunden-Standort">Standorte legst du im Projekt in der Phase Standortprofil an.</x-fa::empty>
                @else
                    <div class="overflow-x-auto -mx-4">
                        <table class="fa-table fa-table--compact" data-standortprofil-tabelle>
                            <thead><tr><th>Standort</th><th>Projekt</th><th class="num">Geräte bekannt</th><th class="num">Flächen</th><th class="num">Gäste parallel</th><th></th></tr></thead>
                            <tbody>
                                @foreach($kunden as $z)
                                    <tr wire:key="ks-{{ $z['standort']->id }}" data-standortprofil-zeile="{{ $z['standort']->id }}">
                                        <td class="font-medium">{{ $z['standort']->name }} @if($z['standort']->ist_pilot)<x-fa::badge tone="accent">Pilot</x-fa::badge>@endif</td>
                                        <td><a href="{{ route('foodalchemist.projekte.show', $z['projekt']->id) }}" wire:navigate class="hover:underline">{{ $z['projekt']->name }}</a></td>
                                        <td class="num">{{ $z['bekannt'] }} / {{ $z['gesamt'] }}</td>
                                        <td class="num">{{ $z['flaechen'] }}</td>
                                        <td class="num">{{ $z['pax'] ?? '—' }}</td>
                                        <td class="text-right"><x-fa::button size="sm" variant="ghost" wire:click="standortOeffnen({{ $z['standort']->id }})" data-standortprofil-oeffnen="{{ $z['standort']->id }}">Profil öffnen</x-fa::button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-fa::section>
        @endif
    @elseif($offen !== null)
        <div class="flex flex-wrap items-center gap-2" data-standortprofil-offen="{{ $offen->id }}">
            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-arrow-left" wire:click="schliessen">Alle Standorte</x-fa::button>
            <x-fa::badge tone="accent" icon="heroicon-m-map-pin">{{ $offen->name }}</x-fa::badge>
            @if(! $darfSchreiben)<x-fa::badge tone="neutral">nur lesen</x-fa::badge>@endif
        </div>
        <fieldset @disabled(! $darfSchreiben) class="contents">
            @include('foodalchemist::livewire.settings.partials.teamprofil-body')
        </fieldset>
    @endif
</div>
