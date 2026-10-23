{{-- PR 3 · Necta-Prüffälle (Master-Team): Filter links oben, Liste FA damals | FA jetzt | Necta, Einzel- und Sammel-Entscheidung. --}}
@php
    $aktionenFuer = fn (string $art) => match (true) {
        $art === 'eingestellt_widerspruch' => ['eingestellt_bestaetigen', 'aktiv_lassen'],
        in_array($art, ['allergen_aenderung', 'deklaration_aenderung', 'manuell_gepflegt', 'produktpass_abweichung'], true) => ['necta_uebernehmen', 'fa_behalten'],
        default => ['fa_behalten'],
    };
    $summeOffen = array_sum($zaehler['je_art']);
    $letzteSeite = max(1, (int) ceil($liste['gesamt'] / $liste['pro_seite']));
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Necta-Prüffälle" icon="heroicon-o-clipboard-document-check" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Lieferanten', 'href' => route('foodalchemist.suppliers.index')],
            ['label' => 'Necta-Prüffälle'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <div class="flex flex-col gap-4" data-necta-pruefaelle @if($sammelLauf) wire:poll.3s="sammelStand" @endif>
            <x-fa::page-header title="Necta-Prüffälle" :subtitle="number_format($liste['gesamt'], 0, ',', '.') . ' Fälle in der Auswahl'" />

            @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
            @if($meldung)<x-fa::notice tone="ok">{{ $meldung }}</x-fa::notice>@endif

            <div class="flex flex-wrap items-end gap-3">
                <x-fa::field label="Art" for="pf-art">
                    <x-fa::select id="pf-art" size="sm" wire:model.live="art">
                        <option value="">Alle Arten ({{ number_format($summeOffen, 0, ',', '.') }})</option>
                        @foreach($arten as $wert => $label)
                            <option value="{{ $wert }}">{{ $label }} ({{ number_format($zaehler['je_art'][$wert] ?? 0, 0, ',', '.') }})</option>
                        @endforeach
                    </x-fa::select>
                </x-fa::field>
                <x-fa::field label="Lieferant" for="pf-lieferant">
                    <x-fa::select id="pf-lieferant" size="sm" wire:model.live="lieferant">
                        <option value="">Alle Lieferanten</option>
                        @foreach($zaehler['je_lieferant'] as $l)
                            <option value="{{ $l['id'] }}">{{ $l['name'] }} ({{ number_format($l['n'], 0, ',', '.') }})</option>
                        @endforeach
                    </x-fa::select>
                </x-fa::field>
                <x-fa::field label="Status" for="pf-status">
                    <x-fa::select id="pf-status" size="sm" wire:model.live="status" :options="['offen' => 'Offen', 'uebernommen' => 'Übernommen', 'verworfen' => 'Verworfen', 'alle' => 'Alle']" />
                </x-fa::field>
                <x-fa::field label="Artikel suchen" for="pf-suche">
                    <x-fa::input id="pf-suche" size="sm" type="search" wire:model.live.debounce.300ms="suche" placeholder="Bezeichnung" />
                </x-fa::field>
                @if($art === 'eingestellt_widerspruch')
                    <x-fa::field label="Necta-Normalpreis jünger als (Tage)" for="pf-tage" hint="leer = alle">
                        <x-fa::input id="pf-tage" size="sm" type="number" min="1" numeric wire:model.live.debounce.400ms="normalpreisTage" placeholder="90" />
                    </x-fa::field>
                @endif
            </div>

            @if($sammelErlaubt)
                <div class="flex flex-wrap items-center gap-2" data-sammel-leiste>
                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">Alle {{ number_format($liste['gesamt'], 0, ',', '.') }} dieser Auswahl:</span>
                    @foreach($aktionenFuer($art) as $a)
                        <x-fa::button size="sm" wire:click="sammelPruefen('{{ $a }}')">{{ $aktionLabel[$a] }} …</x-fa::button>
                    @endforeach
                </div>
            @elseif($status === 'offen')
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Für eine Sammel-Entscheidung eine Art wählen (Lieferant und Suche schränken optional ein).</p>
            @endif

            @if($sammelAktion !== null)
                <x-fa::notice tone="{{ ($sammelVorschau['allergene'] ?? []) !== [] && $sammelAktion === 'necta_uebernehmen' ? 'crit' : 'info' }}" data-sammel-bestaetigung>
                    @php $jeLief = $sammelVorschau['je_lieferant'] ?? []; arsort($jeLief); @endphp
                    <p class="font-medium" data-sammel-frage>{{ number_format($sammelVorschau['anzahl'] ?? 0, 0, ',', '.') }} Prüffälle bei {{ count($jeLief) }} {{ count($jeLief) === 1 ? 'Lieferant' : 'Lieferanten' }}: {{ $aktionLabel[$sammelAktion] }}?</p>
                    <p class="mt-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" data-sammel-lieferanten>
                        {{ collect($jeLief)->take(8)->map(fn ($n, $l) => $l.' '.number_format($n, 0, ',', '.'))->implode(' · ') }}{{ count($jeLief) > 8 ? ' · …' : '' }}
                    </p>
                    @if(($sammelVorschau['anzahl'] ?? 0) > \Platform\FoodAlchemist\Services\Necta\NectaPruefallService::SAMMEL_SOFORT_MAX)
                        <p class="mt-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Läuft im Hintergrund in Teilmengen; der Fortschritt erscheint hier.</p>
                    @endif
                    @if(($sammelVorschau['allergene'] ?? []) !== [] && $sammelAktion === 'necta_uebernehmen')
                        <p class="mt-2">Betroffene Allergene und Deklarationen (wird gelockert):</p>
                        <ul class="mt-1 list-disc pl-5" data-sammel-allergene>
                            @foreach($sammelVorschau['allergene'] as $al)
                                <li>{{ $al['label'] }}: {{ $al['von_text'] ?? 'unbekannt' }} → {{ $al['nach_text'] ?? 'unbekannt' }} · {{ $al['n'] }} Artikel
                                    <span class="text-[var(--fa-ink-3)]">({{ implode(', ', array_slice($al['artikel'], 0, 5)) }}{{ count($al['artikel']) > 5 ? ' …' : '' }})</span></li>
                            @endforeach
                        </ul>
                    @endif
                    @if($sammelVorschau['admin_noetig'] ?? false)
                        <p class="mt-2">Nur FA-Admins dürfen das gesammelt bestätigen.</p>
                    @endif
                    <div class="mt-3 flex gap-2">
                        <x-fa::button size="sm" variant="primary" wire:click="sammelBestaetigen">Bestätigen</x-fa::button>
                        <x-fa::button size="sm" variant="ghost" wire:click="sammelAbbrechen">Abbrechen</x-fa::button>
                    </div>
                </x-fa::notice>
            @endif

            <x-fa::section title="Prüffälle" :meta="(string) $liste['gesamt']">
                @if($liste['faelle'] === [])
                    <x-fa::empty compact icon="heroicon-o-check-circle" title="Keine Prüffälle in dieser Auswahl">Filter ändern oder später wieder schauen.</x-fa::empty>
                @else
                    <div class="-mx-4 -mb-4 overflow-x-auto border-t border-[var(--fa-line)]">
                        <table class="fa-table">
                            <thead>
                                <tr>
                                    <th>Artikel</th>
                                    <th>Feld</th>
                                    <th>FA damals</th>
                                    <th>FA jetzt</th>
                                    <th>Necta</th>
                                    @if($art === 'eingestellt_widerspruch')<th>Letzter Normalpreis</th>@endif
                                    <th><span class="sr-only">Aktionen</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($liste['faelle'] as $f)
                                    <tr wire:key="pf-{{ $f['id'] }}" data-pruefall="{{ $f['id'] }}">
                                        <td>
                                            {{-- Dominique 23.10.: Artikel direkt öffnen (Allergene, Produktpass), ohne die gefilterte Liste zu verlassen --}}
                                            @if($f['artikel_id'] !== null)
                                                <button type="button" class="font-medium text-left text-[var(--fa-accent)] hover:underline"
                                                        wire:click="$dispatch('item-modal.oeffnen', { id: {{ (int) $f['artikel_id'] }} })"
                                                        title="Artikel öffnen" data-pruefall-artikel="{{ (int) $f['artikel_id'] }}">{{ $f['artikel'] ?? 'Necta-ID '.$f['legacy_id'] }}</button>
                                            @else
                                                <div class="font-medium">{{ $f['artikel'] ?? 'Necta-ID '.$f['legacy_id'] }}</div>
                                            @endif
                                            <div class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $f['lieferant'] ?? '—' }} · {{ $arten[$f['art']] ?? $f['art'] }}</div>
                                        </td>
                                        <td>
                                            {{ $f['feld_label'] }}
                                            @if($f['kritisch'])<x-fa::badge tone="crit">Allergen</x-fa::badge>@endif
                                            @if($f['produktpass'])<x-fa::badge tone="info" title="Wert stammt aus dem Produktpass">Produktpass</x-fa::badge>@endif
                                        </td>
                                        <td>{{ $f['fa_damals_text'] ?? '—' }}</td>
                                        <td>
                                            {{ $f['fa_jetzt_text'] ?? '—' }}
                                            @if($f['veraltet'])<x-fa::badge tone="warn" title="Der FA-Wert hat sich seit dem Prüffall geändert">geändert</x-fa::badge>@endif
                                        </td>
                                        <td class="{{ $f['kritisch'] ? 'text-[var(--fa-crit)]' : '' }}">{{ $f['necta_text'] ?? '—' }}</td>
                                        @if($art === 'eingestellt_widerspruch')<td class="tabular-nums">{{ $f['letzter_normalpreis'] ? substr($f['letzter_normalpreis'], 0, 10) : '—' }}</td>@endif
                                        <td class="whitespace-nowrap">
                                            @if($f['status'] === 'offen')
                                                <div class="flex justify-end gap-1">
                                                    @foreach($aktionenFuer($f['art']) as $a)
                                                        <x-fa::button size="sm" :variant="$a === 'fa_behalten' || $a === 'aktiv_lassen' ? 'ghost' : 'secondary'"
                                                            wire:click="entscheide({{ $f['id'] }}, '{{ $a }}')">{{ $a === 'fa_behalten' && count($aktionenFuer($f['art'])) === 1 ? 'Zur Kenntnis' : $aktionLabel[$a] }}</x-fa::button>
                                                    @endforeach
                                                </div>
                                            @else
                                                <x-fa::badge :tone="$f['status'] === 'uebernommen' ? 'ok' : 'neutral'">{{ $f['status'] === 'uebernommen' ? 'Necta übernommen' : 'FA behalten' }}</x-fa::badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-fa::section>

            @if($letzteSeite > 1)
                <div class="flex items-center justify-between">
                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">Seite {{ $liste['seite'] }} von {{ $letzteSeite }}</span>
                    <div class="flex gap-1">
                        <x-fa::icon-button icon="heroicon-m-chevron-left" label="Vorherige Seite" size="sm" wire:click="blaettern(-1)" :disabled="$liste['seite'] <= 1" />
                        <x-fa::icon-button icon="heroicon-m-chevron-right" label="Nächste Seite" size="sm" wire:click="blaettern(1)" :disabled="$liste['seite'] >= $letzteSeite" />
                    </div>
                </div>
            @endif
        </div>

        <livewire:foodalchemist.suppliers.item-modal key="necta-pruefaelle--suppliers.item-modal" />
    </x-ui-page-container>
</x-ui-page>
