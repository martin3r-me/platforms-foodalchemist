{{-- Spec 82 §6 — Teamprofil: was die Küche kann. Equipment dreiwertig (vorhanden / fehlt / unbekannt);
     Standardausstattung gilt ohne Angabe als vorhanden. Schreibt sofort, Leiste Bearbeiten → Fertig (Spec 65). --}}
<div class="flex flex-col gap-4" data-settings-teamprofil>
    @php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true, 'hint' => 'Fakten über deine Küche. Die DNA beschreibt, wie ihr kochen wollt, das Profil, was ihr könnt.'])
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    @if($fehler)<x-fa::notice tone="crit" data-teamprofil-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($meldung)<x-fa::notice tone="ok" data-teamprofil-meldung>{{ $meldung }}</x-fa::notice>@endif

    <x-fa::section title="Kennzahlen" data-teamprofil-kennzahlen
        description="Wie viele Gäste die Küche parallel zum Tagesgeschäft schafft und wie viel selbst gekocht wird.">
        <div class="flex flex-wrap items-end gap-3">
            <x-fa::field label="Max. Gäste parallel" for="tp-pax" class="w-44">
                <x-fa::input id="tp-pax" numeric inputmode="numeric" wire:model="maxPax" placeholder="unbekannt" data-teamprofil-pax />
            </x-fa::field>
            <x-fa::field label="Convenience-Grad" for="tp-conv" class="w-72 max-w-full">
                <x-fa::select id="tp-conv" wire:model="convenience" placeholder="Keine Angabe" data-teamprofil-convenience>
                    @foreach($convenienceWerte as $wert => $label)<option value="{{ $wert }}">{{ $label }}</option>@endforeach
                </x-fa::select>
            </x-fa::field>
        </div>
        <x-fa::field label="Besonderheiten, Wege, Engpässe" for="tp-notizen" class="mt-3">
            <x-fa::textarea id="tp-notizen" rows="3" wire:model="notizen" placeholder="z. B. Anlieferung nur über den Hof, kein Kühlhaus im Haus" data-teamprofil-notizen></x-fa::textarea>
        </x-fa::field>
        <div class="pt-3">
            <x-fa::button variant="primary" wire:click="profilSpeichern" data-teamprofil-speichern>Kennzahlen speichern</x-fa::button>
        </div>
    </x-fa::section>

    <x-fa::section title="Ausstattung" data-teamprofil-ausstattung
        description="Je Gerät: vorhanden, fehlt oder unbekannt. Gewarnt wird nur bei „fehlt“. Bei „unbekannt“ gibt es nur einen Hinweis, dass das Profil unvollständig ist. Standardausstattung gilt ohne Angabe als vorhanden.">
        @if($unbekannt > 0)
            <x-fa::signal tone="info" data-teamprofil-unvollstaendig>{{ $unbekannt }} von {{ $gesamt }} Geräten sind noch unbekannt.</x-fa::signal>
        @endif
        @forelse($gruppen as $gruppe => $eintraege)
            <div class="mt-3" wire:key="tp-gruppe-{{ \Illuminate\Support\Str::slug($gruppe) }}">
                <h4 class="mb-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]">{{ $gruppe }}</h4>
                <ul class="divide-y divide-[var(--fa-line)] border-y border-[var(--fa-line)]">
                    @foreach($eintraege as $e)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-1.5" wire:key="tp-eq-{{ $e['equipment_id'] }}" data-teamprofil-equipment="{{ $e['equipment_id'] }}">
                            <span class="min-w-0 flex-1 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $e['name'] }}
                                @if($e['standard'])<x-fa::badge tone="neutral" class="ml-1" title="Standardausstattung: gilt ohne Angabe als vorhanden">Standard</x-fa::badge>@endif
                            </span>
                            <x-fa::select size="sm" class="w-44" aria-label="{{ $e['name'] }}"
                                wire:change="equipmentSetzen({{ $e['equipment_id'] }}, $event.target.value)" data-teamprofil-zustand>
                                <option value="unbekannt" @selected($e['status'] === 'unbekannt')>{{ $e['standard'] ? 'Standard (gilt als vorhanden)' : 'Unbekannt' }}</option>
                                <option value="vorhanden" @selected($e['status'] === 'vorhanden')>Vorhanden</option>
                                <option value="nicht_vorhanden" @selected($e['status'] === 'nicht_vorhanden')>Fehlt</option>
                            </x-fa::select>
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <x-fa::empty compact icon="heroicon-o-wrench-screwdriver" title="Noch kein Equipment-Vokabular">Sobald Geräte im Vokabular stehen, erscheinen sie hier.</x-fa::empty>
        @endforelse
    </x-fa::section>

    <x-fa::section title="Flächen" data-teamprofil-flaechen
        description="Räume und Flächen mit ihrer Gästegrenze und Saison, zum Beispiel Terrasse, Saal oder Rooftop.">
        <div class="overflow-x-auto -mx-4">
            <table class="fa-table">
                <thead><tr><th>Name</th><th class="num">Max. Gäste</th><th class="num">Saison von (Monat)</th><th class="num">bis (Monat)</th><th></th></tr></thead>
                <tbody>
                    @forelse($flaechen as $f)
                        <tr wire:key="tp-fl-{{ $f['id'] }}" data-teamprofil-flaeche="{{ $f['id'] }}">
                            <td><x-fa::input size="sm" value="{{ $f['name'] }}" wire:change="flaecheFeld({{ $f['id'] }}, 'name', $event.target.value)" aria-label="Name" /></td>
                            <td class="num"><x-fa::input size="sm" numeric inputmode="numeric" value="{{ $f['max_pax'] }}" wire:change="flaecheFeld({{ $f['id'] }}, 'max_pax', $event.target.value)" aria-label="Max. Gäste" class="w-20" placeholder="frei" /></td>
                            <td class="num"><x-fa::input size="sm" numeric inputmode="numeric" value="{{ $f['saison_von'] }}" wire:change="flaecheFeld({{ $f['id'] }}, 'saison_von', $event.target.value)" aria-label="Saison von" class="w-16" placeholder="1–12" /></td>
                            <td class="num"><x-fa::input size="sm" numeric inputmode="numeric" value="{{ $f['saison_bis'] }}" wire:change="flaecheFeld({{ $f['id'] }}, 'saison_bis', $event.target.value)" aria-label="Saison bis" class="w-16" placeholder="1–12" /></td>
                            <td class="text-right"><x-fa::button size="sm" variant="ghost" icon="heroicon-o-trash" wire:click="flaecheLoeschen({{ $f['id'] }})" wire:confirm="Fläche „{{ $f['name'] }}“ löschen?" data-teamprofil-flaeche-loeschen>Löschen</x-fa::button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-fa::empty compact icon="heroicon-o-home-modern" title="Noch keine Flächen">Unten die erste Fläche anlegen.</x-fa::empty></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-end gap-2 pt-3 border-t border-[var(--fa-line)]" data-teamprofil-flaeche-neu>
            <x-fa::field label="Neue Fläche" for="tp-fl-name" class="w-60 max-w-full">
                <x-fa::input id="tp-fl-name" wire:model="neueFlaeche.name" wire:keydown.enter="flaecheAnlegen" placeholder="z. B. Rooftop" data-teamprofil-flaeche-name />
            </x-fa::field>
            <x-fa::field label="Max. Gäste" for="tp-fl-pax" class="w-28">
                <x-fa::input id="tp-fl-pax" numeric inputmode="numeric" wire:model="neueFlaeche.max_pax" placeholder="120" />
            </x-fa::field>
            <x-fa::field label="Saison von" for="tp-fl-von" class="w-24">
                <x-fa::input id="tp-fl-von" numeric inputmode="numeric" wire:model="neueFlaeche.saison_von" placeholder="5" />
            </x-fa::field>
            <x-fa::field label="bis" for="tp-fl-bis" class="w-24">
                <x-fa::input id="tp-fl-bis" numeric inputmode="numeric" wire:model="neueFlaeche.saison_bis" placeholder="9" />
            </x-fa::field>
            <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="flaecheAnlegen" data-teamprofil-flaeche-anlegen>Fläche anlegen</x-fa::button>
        </div>
    </x-fa::section>
    </fieldset>
</div>
