{{-- Spec 85 D-7 PR 2 — Einstellungen › Hausstandards. Je übersteuerbarer Regel: globaler Wert, Wert im Team, Herkunft, Untergrenze.
     Abweichen / Zurück / Verlauf. Schreiben nur FA-Admin (Mensch); die globalen Regeln pflegt der Plattform-Admin unter „Regeln“. --}}
@php
    $klein = 'text-[length:var(--fa-text-sm)]';
    $sperrLesen = ! $darf || in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true);
    $offenZeile = $offen ? collect($zeilen)->firstWhere('schluessel', $offen) : null;
@endphp

<div class="flex flex-col gap-4" data-settings-hausstandards>
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
    @if($meldung)<x-fa::notice tone="ok">{{ $meldung }}</x-fa::notice>@endif

    <x-fa::section title="Hausstandards" :meta="collect($zeilen)->where('eigen', true)->count() . ' eigene Abweichungen'"
        description="Wo euer Haus anders arbeitet als der Plattform-Standard: Standard-Grundprodukte, Kleinstmengen, Hygiene-Temperaturen. Eine Abweichung gilt sofort für dieses Team und für Unter-Teams ohne eigene. Temperaturen dürfen strenger sein; lockerer nur bis zur belegten Untergrenze.">
        <div class="grid grid-cols-1 gap-4 {{ $offenZeile ? 'xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]' : '' }}">
            <div class="overflow-auto rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                <table class="fa-table">
                    <thead><tr><th>Regel</th><th>Plattform-Standard</th><th>Bei euch</th><th>Herkunft</th><th><span class="sr-only">Aktionen</span></th></tr></thead>
                    <tbody>
                        @foreach($zeilen as $z)
                            <tr wire:key="hs-{{ $z['schluessel'] }}" class="{{ $offen === $z['schluessel'] ? 'bg-[var(--fa-accent-soft)]' : '' }}">
                                <td>
                                    <span class="font-medium">{{ $z['titel'] }}</span>
                                    <span class="{{ $klein }} block text-[var(--fa-ink-3)]">{{ $z['schluessel'] }}</span>
                                </td>
                                <td class="{{ $klein }}">{{ $z['wert_global'] }}</td>
                                <td class="{{ $klein }} {{ $z['herkunft'] !== 'global' ? 'font-medium' : 'text-[var(--fa-ink-3)]' }}">
                                    {{ $z['herkunft'] === 'global' ? '—' : $z['wert_wirksam'] }}
                                    @if($z['global_neuer'])<x-fa::badge tone="warn">Standard geändert — prüfen</x-fa::badge>@endif
                                </td>
                                <td class="{{ $klein }}">
                                    @if($z['eigen'])<x-fa::badge tone="ok">eigen</x-fa::badge>
                                    @elseif($z['herkunft'] !== 'global')<x-fa::badge tone="info">Oberteam {{ $z['herkunft_team'] }}</x-fa::badge>
                                    @else<span class="text-[var(--fa-ink-3)]">Plattform</span>@endif
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <x-fa::button size="sm" variant="ghost" wire:click="oeffne('{{ $z['schluessel'] }}')">{{ $sperrLesen ? 'Ansehen' : 'Abweichen' }}</x-fa::button>
                                    @if($z['eigen'] && ! $sperrLesen)
                                        <x-fa::button size="sm" variant="ghost" wire:click="zuruecksetzen('{{ $z['schluessel'] }}')"
                                            wire:confirm="Eigene Abweichung entfernen? Danach gilt wieder der Wert des Oberteams bzw. der Plattform.">Zurücksetzen</x-fa::button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($offenZeile)
                <div class="flex flex-col gap-3 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] p-3" data-hausstandard-editor>
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-medium">{{ $offenZeile['titel'] }}</p>
                            <p class="{{ $klein }} text-[var(--fa-ink-3)]">Plattform-Standard: {{ $offenZeile['wert_global'] }}@if(! empty($offenZeile['params_global']['geltung'])) — gilt für {{ $offenZeile['params_global']['geltung'] }}@endif</p>
                            @if($offenZeile['art'] === 'schwelle')
                                <p class="{{ $klein }} text-[var(--fa-ink-3)]">
                                    @if($offenZeile['untergrenze'])Lockerer höchstens bis {{ $offenZeile['untergrenze'] }}.
                                    @else Nur strenger erlaubt — für diesen Wert ist keine Untergrenze belegt.@endif
                                </p>
                            @endif
                        </div>
                        <x-fa::icon-button size="sm" icon="heroicon-o-x-mark" label="Schließen" wire:click="schliesse" />
                    </div>

                    @if($offenZeile['art'] === 'schwelle')
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-fa::field :label="'Wert (' . ($offenZeile['params_global']['einheit'] ?? '') . ')'" for="hs-wert">
                                <x-fa::input id="hs-wert" numeric wire:model="form.wert" :disabled="$sperrLesen" />
                            </x-fa::field>
                            @if(isset($offenZeile['params_global']['dauer']))
                                <x-fa::field :label="'Dauer (' . ($offenZeile['params_global']['dauer_einheit'] ?? 'min') . ')'" for="hs-dauer">
                                    <x-fa::input id="hs-dauer" numeric wire:model="form.dauer" :disabled="$sperrLesen" />
                                </x-fa::field>
                            @endif
                        </div>
                    @else
                        <div class="max-h-[24rem] overflow-auto rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                            <table class="fa-table">
                                <thead><tr><th>Begriff</th><th>Grundprodukt</th><th>Andere Schreibweisen (Komma)</th><th><span class="sr-only">Entfernen</span></th></tr></thead>
                                <tbody>
                                    @foreach($form['zeilen'] ?? [] as $i => $z)
                                        <tr wire:key="hs-z-{{ $offen }}-{{ $i }}">
                                            <td><x-fa::input size="sm" wire:model="form.zeilen.{{ $i }}.begriff" aria-label="Begriff" :disabled="$sperrLesen" /></td>
                                            <td><x-fa::input size="sm" wire:model="form.zeilen.{{ $i }}.ziel_name" aria-label="Grundprodukt" :disabled="$sperrLesen" /></td>
                                            <td><x-fa::input size="sm" wire:model="form.zeilen.{{ $i }}.aliase" aria-label="Andere Schreibweisen" :disabled="$sperrLesen" /></td>
                                            <td>@unless($sperrLesen)<x-fa::icon-button size="sm" icon="heroicon-o-trash" label="Zeile entfernen" wire:click="zeileWeg({{ $i }})" />@endunless</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @unless($sperrLesen)<div><x-fa::button size="sm" variant="ghost" icon="heroicon-m-plus" wire:click="zeileDazu">Zeile hinzufügen</x-fa::button></div>@endunless
                        <p class="{{ $klein }} text-[var(--fa-ink-3)]">Kontext-Bedingungen (z. B. nur From Scratch) übernimmt die Abweichung vom Plattform-Eintrag gleichen Begriffs.</p>
                    @endif

                    @unless($sperrLesen)
                        <x-fa::field label="Notiz" for="hs-notiz" optional hint="Warum weicht euer Haus ab? (steht im Verlauf)">
                            <x-fa::input id="hs-notiz" wire:model="form.notiz" />
                        </x-fa::field>
                        <div><x-fa::button size="sm" variant="primary" wire:click="speichern">Als Hausstandard speichern</x-fa::button></div>
                    @endunless

                    @if($versionen->isNotEmpty())
                        <div class="flex flex-col gap-1">
                            <p class="{{ $klein }} font-medium uppercase tracking-wide text-[var(--fa-ink-3)]">Verlauf</p>
                            @foreach($versionen as $v)
                                @php $was = ! $v->aktiv ? 'entfernt' : ($offenZeile['art'] === 'schwelle' ? \Platform\FoodAlchemist\Services\Regeln\Arten\Schwelle::text((array) $v->params) : 'gesetzt'); @endphp
                                <p class="{{ $klein }} text-[var(--fa-ink-2)]">v{{ $v->version }} · {{ $v->created_at?->format('d.m.Y H:i') }} · {{ $was }}</p>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </x-fa::section>
</div>
