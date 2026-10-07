{{-- Phase 4: Herstellkosten, eigene Sektion (mehrstufige Zuschlagskalkulation + Fixkosten + Bezugsbasen, Doc 16 §10) --}}
@php
    $basisLabel = [
        'pct_mek' => '% auf Wareneinsatz',
        'pct_fek' => '% auf Lohn',
        'pct_hk' => '% auf Herstellkosten',
        'eur_pro_portion' => '€ je Portion',
        'arbeitszeit' => '€ je Stunde (Lohn)',
    ];
    $einheit = ['eur_pro_portion' => '€', 'arbeitszeit' => '€/h'];
    $fixMonatGesamt = array_sum($fixSummen ?? []);
    $blockName = fn ($key) => collect($gkBloecke)->firstWhere('key', $key)['label'] ?? $key;
@endphp

<div class="flex flex-col gap-4" data-settings-herstellkosten>
    <x-foodalchemist::save-bar :meldung="$meldung"
        hint="Zuschläge, Fixkosten und Marge wirken erst nach dem Speichern auf Selbstkosten und Verkaufspreise." />
    @if($fehler)
        <x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>
    @endif

    {{-- Ebene 2: Seiten-Wähler Team / Betrieb, steuert die GANZE Seite (lokal, nicht die globale Betriebsbrille) --}}
    @if(count($betriebeOptionen) > 0)
        <div class="fa-surface p-3 flex flex-col gap-2">
            <div class="flex flex-wrap items-end gap-x-4 gap-y-2">
                <x-fa::field label="Kosten erfassen für" for="hk-betrieb" class="w-80 max-w-full">
                    <x-fa::select id="hk-betrieb" wire:model.live="outletId">
                        <option value="">Team-Standard (Vorlage für alle Betriebe)</option>
                        @foreach($betriebeOptionen as $o)
                            <option value="{{ $o['id'] }}">Betrieb: {{ $o['name'] }}</option>
                        @endforeach
                    </x-fa::select>
                </x-fa::field>
                @if($scopeOutletName)
                    <x-fa::button variant="danger" size="sm" icon="heroicon-m-arrow-uturn-left" class="ml-auto mb-1"
                        wire:click="aufTeamZuruecksetzen"
                        wire:confirm="„{{ $scopeOutletName }}“ komplett zurücksetzen? Alle eigenen Werte und Fixkosten dieses Betriebs werden gelöscht, danach gelten wieder die Team-Werte.">
                        Auf Team-Werte zurücksetzen
                    </x-fa::button>
                @endif
            </div>
            @if($scopeOutletName)
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                    Du bearbeitest {{ $scopeOutletName }} als eigenständige Kalkulation. Die Felder sind mit den Team-Werten vorbefüllt.
                    Passe an, was abweicht. Beim Speichern werden alle Werte als eigene Werte des Betriebs gesichert, die Team-Fixkosten inklusive. Es wird nichts vererbt.
                </p>
            @else
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Der Team-Standard ist die Vorlage für neue Betriebe. Wähle einen Betrieb, um dessen eigene Kalkulation zu pflegen.</p>
            @endif
        </div>
    @endif

    {{-- Doc 16 §10: mehrstufiges Kostenblock-Schema --}}
    <x-fa::section title="Zuschlagskalkulation" icon="heroicon-o-calculator" data-hk-schema
        description="Gemeinkosten stehen auf automatisch: Du trägst unten deine Fixkosten in Euro ein, der Zuschlag rechnet sich selbst (Fixkosten je Monat geteilt durch die Bezugsbasis). Manuell nur als Ausnahme.">
        <x-slot:actions>
            <x-fa::button size="sm" icon="heroicon-m-bolt" wire:click="alleAutomatisch" title="Stellt alle Gemeinkosten auf automatische Berechnung aus den Fixkosten.">Alle Gemeinkosten automatisch</x-fa::button>
        </x-slot:actions>

        {{-- Rechenweg als abgesetzte Vorschau --}}
        <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] border border-[var(--fa-line)] px-3 py-2.5 flex flex-col gap-1.5">
            <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">So entsteht der Preis je Portion</p>
            <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                <li>Wareneinsatz (MEK)</li><li class="text-[var(--fa-ink-3)]">+</li>
                <li>Material-Gemeinkosten</li><li class="text-[var(--fa-ink-3)]">+</li>
                <li>Lohn (FEK)</li><li class="text-[var(--fa-ink-3)]">+</li>
                <li>Lohn-Gemeinkosten</li><li class="text-[var(--fa-ink-3)]">=</li>
                <li class="font-medium">Herstellkosten (HK)</li>
            </ol>
            <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                <li>Herstellkosten</li><li class="text-[var(--fa-ink-3)]">+</li>
                <li>Verwaltung und Logistik</li><li class="text-[var(--fa-ink-3)]">=</li>
                <li class="font-medium">Selbstkosten (HK2)</li><li class="text-[var(--fa-ink-3)]">×</li>
                <li>Marge</li><li class="text-[var(--fa-ink-3)]">=</li>
                <li class="font-medium text-[var(--fa-accent)]">Preisvorschlag</li>
            </ol>
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Jeder Block unten ist ein Zuschlag je Portion: Prozent auf seine Basis oder ein fester Betrag.</p>
        </div>

        <div class="overflow-x-auto -mx-4 px-4">
            <table class="fa-table min-w-[760px]">
                <thead><tr>
                    <th>Kostenblock</th>
                    <th>Berechnet als</th>
                    <th class="text-center">Aktiv</th>
                    <th>Ermittlung</th>
                    <th class="num">Satz</th>
                    <th><span class="sr-only">Entfernen</span></th>
                </tr></thead>
                <tbody>
                    @foreach($schema as $i => $b)
                        @php
                            $istGk = in_array($b['type'], ['pct_mek', 'pct_fek', 'pct_hk'], true);
                            $istAbgeleitet = $istGk && ($b['mode'] ?? 'manuell') === 'abgeleitet';
                        @endphp
                        <tr wire:key="kblock-{{ $b['key'] }}">
                            <td class="font-medium">{{ $b['label'] }}</td>
                            <td><x-fa::badge>{{ $basisLabel[$b['type']] ?? $b['type'] }}</x-fa::badge></td>
                            <td class="text-center"><input type="checkbox" wire:model="schema.{{ $i }}.active" aria-label="{{ $b['label'] }} aktiv" class="w-4 h-4 accent-[var(--fa-accent)]" /></td>
                            <td>
                                @if($istGk)
                                    <x-fa::choice name="schema.{{ $i }}.mode" :options="['abgeleitet' => 'Automatisch', 'manuell' => 'Manuell']" />
                                @else
                                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Fester Wert</span>
                                @endif
                            </td>
                            <td class="num">
                                @if($istAbgeleitet)
                                    @php
                                        $basisTyp = ['pct_mek' => 'mek', 'pct_fek' => 'fek', 'pct_hk' => 'hk'][$b['type']] ?? null;
                                        $blockSumme = (float) ($fixSummen[$b['key']] ?? 0);
                                        $blockBasis = (float) ($liveBasen[$basisTyp] ?? 0);
                                        $basisFehlt = $blockSumme > 0 && $blockBasis <= 0;
                                    @endphp
                                    <span class="font-medium text-[var(--fa-accent)]" title="Automatisch: Fixkosten je Monat geteilt durch die Bezugsbasis">{{ number_format((float) ($abgeleitet[$b['key']] ?? 0), 2, ',', '.') }}&nbsp;%</span>
                                    @if($blockSumme > 0 && $blockBasis > 0)
                                        <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ number_format($blockSumme, 0, ',', '.') }}&nbsp;€ ÷ {{ number_format($blockBasis, 0, ',', '.') }}&nbsp;€</span>
                                    @elseif($basisFehlt)
                                        <span class="block" title="Fixkosten erfasst, aber die Bezugsbasis ist 0. Der Satz bleibt 0 %, bis du unten die Bezugsbasis einträgst."><x-fa::signal tone="warn">Bezugsbasis fehlt</x-fa::signal></span>
                                    @else
                                        <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Noch keine Fixkosten</span>
                                    @endif
                                @else
                                    @php
                                        $istLohnFallback = $b['type'] === 'arbeitszeit' && $laborSource === 'station_roles';
                                    @endphp
                                    <div class="inline-flex items-center gap-1.5">
                                        <x-fa::input wire:model="schema.{{ $i }}.value" placeholder="0" numeric size="sm" aria-label="Satz {{ $b['label'] }}" class="w-24 {{ $istLohnFallback ? 'opacity-60' : '' }}" />
                                        <span class="w-7 text-left text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $einheit[$b['type']] ?? '%' }}</span>
                                    </div>
                                    @if($istLohnFallback)
                                        <span class="block mt-0.5" title="Lohnquelle ist „Rollen des Postens“: Der Lohn kommt aus den Rollen-Sätzen. Dieser Satz greift nur, wenn ein Posten keine Rollendaten hat."><x-fa::signal tone="info">Nur Rückfallwert</x-fa::signal></span>
                                    @endif
                                @endif
                            </td>
                            <td class="text-right">
                                <x-fa::icon-button icon="heroicon-o-trash" tone="danger" size="sm" label="Kostenblock {{ $b['label'] }} entfernen"
                                    wire:click="blockEntfernen({{ $i }})" wire:confirm="Kostenblock entfernen?" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Neuer Kostenblock --}}
        <div class="flex flex-wrap items-end gap-3 pt-3 border-t border-[var(--fa-line)]">
            <x-fa::field label="Neuer Kostenblock" for="hk-neu-block" class="w-64 max-w-full">
                <x-fa::input id="hk-neu-block" wire:model="neuBlock.label" wire:keydown.enter="blockHinzu" placeholder="z. B. Energie" />
            </x-fa::field>
            <x-fa::choice name="neuBlock.type" :live="false" label="Berechnet als" :options="$basisLabel" class="flex-1 min-w-[16rem]" />
            <x-fa::button icon="heroicon-m-plus" wire:click="blockHinzu">Block hinzufügen</x-fa::button>
        </div>

        {{-- Marge, Ziele, Lohn --}}
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,16rem),1fr))] gap-4 pt-3 border-t border-[var(--fa-line)]">
            <x-fa::field label="Marge" for="hk-marge" hint="Aufschlag auf die Selbstkosten (HK2), ergibt den Preisvorschlag.">
                <div class="flex items-center gap-1.5">
                    <x-fa::input id="hk-marge" wire:model="marge" placeholder="15" numeric class="w-24" />
                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">%</span>
                </div>
            </x-fa::field>
            <x-fa::field label="Ziel-Wareneinsatzquote" for="hk-ziel-we" hint="Food-Cost-Ziel, üblich sind 28 bis 35 %. Treibt Break-even und den Hinweis „Wareneinsatz über Ziel“.">
                <div class="flex items-center gap-1.5">
                    <x-fa::input id="hk-ziel-we" wire:model="zielWe" placeholder="30" numeric class="w-24" />
                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">%</span>
                </div>
            </x-fa::field>
            <x-fa::field label="Lohnnebenkosten" for="hk-lnk" hint="Arbeitgeber- und Sozialabgaben auf den Produktionslohn. So rechnen die Selbstkosten mit dem echten Personalkostensatz.">
                <div class="flex items-center gap-1.5">
                    <x-fa::input id="hk-lnk" wire:model="lnk" placeholder="0" numeric class="w-24" />
                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">%</span>
                </div>
            </x-fa::field>
            <x-fa::field :hint="$scopeOutletName ? 'Je Betrieb wählbar. Die Rollen-Sätze selbst gelten teamweit.' : 'Fehlen Posten- oder Rollendaten, gilt sichtbar der Stundensatz des Teams.'">
                <x-fa::choice name="laborSource" :live="false" label="Lohn im Auftrag aus"
                    :options="['team_flat' => 'Fester Stundensatz', 'station_roles' => 'Rollen des Postens']" />
            </x-fa::field>
        </div>
    </x-fa::section>

    {{-- M-K6/Doc 16 §10.2: Fixkosten + Bezugsbasen → abgeleitete Gemeinkosten-Sätze --}}
    <x-fa::section title="Fixkosten" icon="heroicon-o-banknotes" data-hk-fixkosten
        description="Kosten, die nicht am einzelnen Produkt hängen: Logistik, Spüle, Lager, Verwaltung. Je Kostenblock gilt: Zuschlag in % = Fixkosten je Monat ÷ Bezugsbasis × 100.">
        @if(count($fixListe) === 0 && $outletId === null)
            <x-slot:actions>
                <x-fa::button size="sm" icon="heroicon-m-calculator" wire:click="cateringBeispielwerte" title="Setzt gekennzeichnete, änderbare Beispielwerte samt Monatsbasen ein und rechnet die Kalkulation durch.">Catering-Beispiel einsetzen</x-fa::button>
            </x-slot:actions>
        @endif

        {{-- Ebene 2: Fixkosten je Betrieb, eigenständig, KEINE Vererbung (Blöcke ohne eigene Zeile = 0) --}}
        @if($scopeOutletName)
            <x-fa::notice tone="info" title="Fixkosten nur für {{ $scopeOutletName }}">
                Es zählen nur die Zeilen dieses Betriebs. Blöcke ohne eigene Zeile stehen auf 0, es gibt keinen Rückgriff auf das Team. Startpunkt: Team-Fixkosten übernehmen und dann anpassen.
                @unless($hatEigeneFix)
                    <x-slot:actions>
                        <x-fa::button size="sm" icon="heroicon-m-document-duplicate" wire:click="teamFixkostenUebernehmen">Team-Fixkosten übernehmen</x-fa::button>
                    </x-slot:actions>
                @endunless
            </x-fa::notice>
        @endif

        {{-- Bezugsbasen (monatlich) --}}
        <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] border border-[var(--fa-line)] p-3 flex flex-col gap-3">
            <div>
                <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Bezugsbasen je Monat</p>
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Durch diese Monatswerte werden die Fixkosten geteilt. Faustregel: Durchschnitt der letzten drei Monate oder Planwert. Steht eine Basis auf 0, bleibt ihr Zuschlag 0 %.</p>
            </div>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,14rem),1fr))] gap-3">
                @foreach([
                    'mek' => ['Wareneinsatz je Monat', 'Einkaufswert der verarbeiteten Ware.'],
                    'fek' => ['Lohn je Monat', 'Küchen- und Produktionslöhne.'],
                    'hk' => ['Herstellkosten je Monat', 'Wareneinsatz, Löhne und direkte Kosten.'],
                ] as $k => [$lbl, $hilfe])
                    <x-fa::field :label="$lbl" for="hk-basis-{{ $k }}" :hint="$hilfe">
                        <div class="flex items-center gap-1.5">
                            <x-fa::input id="hk-basis-{{ $k }}" wire:model.live.debounce.600ms="bezugsbasen.{{ $k }}" placeholder="0" numeric />
                            <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">€</span>
                        </div>
                    </x-fa::field>
                @endforeach
            </div>
        </div>

        {{-- Fixkosten-Liste --}}
        <div class="overflow-x-auto -mx-4 px-4">
            <table class="fa-table min-w-[640px]">
                <thead><tr>
                    <th class="w-full">Bezeichnung</th>
                    <th>Kostenblock</th>
                    <th class="num">Betrag</th>
                    <th>Zeitraum</th>
                    <th><span class="sr-only">Löschen</span></th>
                </tr></thead>
                <tbody>
                    @foreach($fixListe as $f)
                        <tr wire:key="fix-{{ $f['id'] }}">
                            <td>{{ $f['label'] }}</td>
                            <td class="text-[var(--fa-ink-2)] whitespace-nowrap">{{ $blockName($f['block_key']) }}</td>
                            <td class="num">{{ number_format((float) $f['amount'], 2, ',', '.') }}&nbsp;€</td>
                            <td class="text-[var(--fa-ink-2)] whitespace-nowrap">
                                {{ $f['periode'] === 'jaehrlich' ? 'jährlich' : 'monatlich' }}
                                @if($f['periode'] === 'jaehrlich')
                                    <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">= {{ number_format((float) ($f['monatsbetrag'] ?? 0), 2, ',', '.') }}&nbsp;€ je Monat</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <x-fa::icon-button icon="heroicon-o-trash" tone="danger" size="sm" label="Fixkosten {{ $f['label'] }} löschen"
                                    wire:click="fixLoeschen({{ $f['id'] }})" wire:confirm="Fixkosten-Zeile löschen?" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(count($fixListe) === 0)
            <x-fa::empty icon="heroicon-o-banknotes" title="{{ $scopeOutletName ? 'Noch keine eigenen Fixkosten für ' . $scopeOutletName : 'Noch keine Fixkosten erfasst' }}" compact>
                @if($scopeOutletName)
                    Die Summe steht auf 0, es wird nichts vererbt. Übernimm oben die Team-Fixkosten oder lege unten eigene Zeilen an.
                @else
                    Lege unten die erste Zeile an, z. B. Spülpersonal, Lieferwagen oder Miete.
                @endif
            </x-fa::empty>
        @endif

        {{-- Neue Fixkosten-Zeile --}}
        <div class="flex flex-wrap items-end gap-3 pt-3 border-t border-[var(--fa-line)]">
            <x-fa::field label="Neue Fixkosten" for="hk-neu-fix" required class="flex-1 min-w-[14rem]">
                <x-fa::input id="hk-neu-fix" wire:model="neuFix.label" wire:keydown.enter="fixHinzu" placeholder="z. B. Spülpersonal, Lieferwagen, Miete" />
            </x-fa::field>
            <x-fa::field label="Kostenblock" for="hk-neu-fix-block" required class="w-48">
                <x-fa::select id="hk-neu-fix-block" wire:model="neuFix.block_key" placeholder="Block wählen">
                    @foreach($gkBloecke as $gk)<option value="{{ $gk['key'] }}">{{ $gk['label'] }}</option>@endforeach
                </x-fa::select>
            </x-fa::field>
            <x-fa::field label="Betrag" for="hk-neu-fix-betrag" class="w-36">
                <div class="flex items-center gap-1.5">
                    <x-fa::input id="hk-neu-fix-betrag" wire:model="neuFix.amount" wire:keydown.enter="fixHinzu" placeholder="0" numeric />
                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">€</span>
                </div>
            </x-fa::field>
            <x-fa::choice name="neuFix.periode" :live="false" label="Zeitraum" :options="['monatlich' => 'Monatlich', 'jaehrlich' => 'Jährlich']" />
            <x-fa::button icon="heroicon-m-plus" wire:click="fixHinzu">Fixkosten hinzufügen</x-fa::button>
        </div>

        {{-- #379+: Fixkosten je Monat (Controlling-Zahl), gesamt + je Block, jährlich bereits auf Monat umgerechnet --}}
        <div class="flex flex-col gap-2 pt-3 border-t border-[var(--fa-line)]">
            <x-fa::kpis :items="[
                ['label' => $scopeOutletName ? 'Fixkosten je Monat, nur ' . $scopeOutletName : 'Fixkosten je Monat', 'value' => number_format((float) $fixMonatGesamt, 2, ',', '.') . ' €', 'primary' => true],
            ]" />
            @if($scopeOutletName && ! $hatEigeneFix)
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $scopeOutletName }} hat noch keine eigenen Fixkosten, deshalb steht die Summe auf 0. „Team-Fixkosten übernehmen“ holt die Team-Zeilen als Startpunkt.</p>
            @endif
            @if($fixMonatGesamt > 0)
                <div class="flex flex-wrap gap-1.5">
                    @foreach($fixSummen as $bk => $summe)
                        @if($summe > 0)
                            <x-fa::badge class="tabular-nums">{{ $blockName($bk) }}: {{ number_format((float) $summe, 2, ',', '.') }} € je Monat</x-fa::badge>
                        @endif
                    @endforeach
                </div>
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Je Block geteilt durch die Bezugsbasis ergibt das den automatischen Zuschlag.</p>
            @endif
        </div>
    </x-fa::section>
</div>
