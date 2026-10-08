{{-- Spec 66 · Lager (Stufe 1): Bestand, Bewegungen, Inventuren. Periodisches Lager: Bestand aus
     Wareneingang + Inventur. Spec 66b: Einrichten (Stellplätze, Stammplätze), Filter, Zählen in Gebinden. --}}
@php
    $segment = 'h-8 px-3 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-md)] font-medium transition-colors duration-150';
    $segmentAn = 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm';
    $segmentAus = 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]';
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $quellen = ['' => 'Alle Quellen', 'wareneingang' => 'Wareneingang', 'inventur' => 'Inventur'];
    $quelleText = ['wareneingang' => 'Wareneingang', 'inventur' => 'Inventur', 'produktion' => 'Produktion'];
    $zahl = fn ($v) => $v === null ? '' : (rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',') ?: '0');
    $eingabe = fn ($v) => $v === null ? '' : str_replace('.', ',', (string) (0 + (float) $v));
    $feld = 'fa-control h-7 text-right tabular-nums text-[length:var(--fa-text-sm)]';
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-foodalchemist::shell.page-navbar title="Lager" icon="heroicon-o-archive-box" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Lager" subtitle="Bestand aus Wareneingang und Inventur. Der Verbrauch ergibt sich aus Anfangsbestand + Einkauf − Endbestand." />

        <div class="inline-flex items-center gap-0.5 p-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] self-start" role="tablist" data-lager-reiter>
            @foreach(['bestand' => 'Bestand', 'bewegungen' => 'Bewegungen', 'eigenproduktion' => 'Eigenproduktion', 'lagerartikel' => 'Lagerartikel', 'inventur' => 'Inventuren', 'einrichten' => 'Einrichten'] as $k => $l)
                <button type="button" wire:click="reiterSetzen('{{ $k }}')" class="{{ $segment }} {{ $reiter === $k ? $segmentAn : $segmentAus }}" role="tab" aria-selected="{{ $reiter === $k ? 'true' : 'false' }}">{{ $l }}</button>
            @endforeach
        </div>

        @if($fehler)<x-fa::notice tone="crit" data-lager-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($hinweis)<x-fa::notice tone="info">{{ $hinweis }}</x-fa::notice>@endif

        {{-- Spec 74: Signal — Lagerartikel kurz vor leer --}}
        @if($unterMindest !== [] && $reiter !== 'lagerartikel')
            <x-fa::notice tone="warn" data-lager-unter-mindest>
                {{ count($unterMindest) }} Lagerartikel unter Mindestbestand: {{ collect($unterMindest)->take(5)->pluck('name')->implode(', ') }}{{ count($unterMindest) > 5 ? ' …' : '' }}.
                <button type="button" wire:click="reiterSetzen('lagerartikel')" class="underline">Ansehen</button> · Nachfüllen in der Bestellrunde unter „Lagerartikel aus dem Vorrat".
            </x-fa::notice>
        @endif

        @if($orte->isEmpty())
            <x-fa::notice tone="info">Noch kein Lagerort angelegt. Der erste Wareneingang legt automatisch ein „Hauptlager" an; weitere Lagerorte (Kühlhaus, TK, Trocken) unter Einstellungen → Einkauf.</x-fa::notice>
        @endif

        {{-- ── Bestand ─────────────────────────────────────────────────── --}}
        @if($reiter === 'bestand')
            <x-fa::section title="Bestand" icon="heroicon-o-archive-box" data-lager-bestand>
                <x-slot:actions>
                    <span class="text-[length:var(--fa-text-md)] font-semibold tabular-nums">Wert <x-fa::money :value="$bestandWert" /></span>
                </x-slot:actions>
                @include('foodalchemist::livewire.lager.partials.filter', ['mitLagerort' => true, 'mitLadenhueter' => true, 'mitOhnePreis' => true, 'mitPlatzOrt' => $lagerortId === null || $lagerortId === ''])
                <p class="{{ $leise }}">{{ count($bestand) }} von {{ $bestandGesamt }} Positionen{{ $ohnePreis > 0 ? " · {$ohnePreis} ohne Preis, nicht im Wert enthalten" : "" }}</p>
                @if($bestand === [])
                    <x-fa::empty compact icon="heroicon-o-archive-box" :title="$bestandGesamt > 0 ? 'Nichts gefunden' : 'Kein Bestand'">{{ $bestandGesamt > 0 ? 'Kein Bestand passt zu den Filtern.' : 'Bestand entsteht durch Wareneingang oder Inventur.' }}</x-fa::empty>
                @else
                    <div class="overflow-x-auto">
                        <table class="fa-table fa-table--compact min-w-[640px]">
                            <thead><tr><th>Grundprodukt</th><th>Lieferant</th><th>Lagerort</th><th>Stellplatz</th><th class="text-right">Menge</th><th class="text-right">EK je kg/l/Stk</th><th class="text-right">Wert</th><th>Letzte Bewegung</th></tr></thead>
                            <tbody>
                                @foreach($bestand as $r)
                                    <tr wire:key="b-{{ $r['stock_id'] }}">
                                        <td class="font-medium">{{ $r['name'] }}</td>
                                        <td>{{ $r['lieferant'] ?? '–' }}</td>
                                        <td>{{ $r['lagerort'] }}</td>
                                        <td>{{ $r['stellplatz'] ?? '' }}@if($r['stellplatz'] === null)<span class="{{ $leise }}">–</span>@endif</td>
                                        <td class="text-right tabular-nums {{ $r['menge'] < 0 ? 'text-[var(--fa-crit)]' : '' }}">{{ $r['anzeige'] }}</td>
                                        <td class="text-right tabular-nums">@if($r['preis'] !== null){{ number_format($r['preis'] * ($r['base_unit'] === 'Stk' ? 1 : 1000), 2, ',', '.') }} €@else<span class="{{ $leise }}">–</span>@endif</td>
                                        <td class="text-right"><x-fa::money :value="$r['wert']" missing="ohne Preis" /></td>
                                        <td class="{{ $leise }}">{{ $r['zuletzt'] ?? '–' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-fa::section>
        @endif

        {{-- ── Lagerartikel (Spec 74) ──────────────────────────────────── --}}
        @if($reiter === 'lagerartikel')
            <x-fa::section title="Lagerartikel" icon="heroicon-o-archive-box-arrow-down" description="Grundvorrat wie Gewürze, Öle, Salz: geht nicht mit jedem Rezept in die Bestellung, sondern wird nachgefüllt, sobald der Bestand unter den Mindestbestand fällt — auf den Sollbestand (ohne Soll: das Doppelte des Mindestbestands)." data-lager-lagerartikel>
                @if($lagerartikel === [])
                    <x-fa::empty compact icon="heroicon-o-archive-box" title="Noch keine Lagerartikel">Unten ein Grundprodukt ergänzen oder unter „Einrichten" mehrere markieren.</x-fa::empty>
                @else
                    <div class="overflow-x-auto">
                        <table class="fa-table fa-table--compact min-w-[640px]">
                            <thead><tr><th>Grundprodukt</th><th class="text-right">Bestand</th><th class="text-right">Mindestbestand</th><th class="text-right">Sollbestand</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                @foreach($lagerartikel as $la)
                                    <tr wire:key="la-{{ $la['gp_id'] }}" data-lagerartikel="{{ $la['gp_id'] }}">
                                        <td class="font-medium">{{ $la['name'] }}</td>
                                        <td class="text-right tabular-nums">{{ $zahl($la['bestand']) }} {{ $la['einheit'] }}</td>
                                        <td class="text-right"><span class="inline-flex items-center gap-1"><input type="text" inputmode="decimal" wire:model="vorrat.{{ $la['gp_id'] }}.min" class="{{ $feld }} w-20" aria-label="Mindestbestand" /><span class="{{ $leise }} w-6 text-left">{{ $la['einheit'] }}</span></span></td>
                                        <td class="text-right"><span class="inline-flex items-center gap-1"><input type="text" inputmode="decimal" wire:model="vorrat.{{ $la['gp_id'] }}.soll" class="{{ $feld }} w-20" aria-label="Sollbestand" /><span class="{{ $leise }} w-6 text-left">{{ $la['einheit'] }}</span></span></td>
                                        <td>
                                            @switch($la['status'])
                                                @case('leer')<x-fa::badge tone="crit">leer</x-fa::badge>@break
                                                @case('unter_min')<x-fa::badge tone="warn">unter Minimum</x-fa::badge>@break
                                                @case('ohne_min')<x-fa::badge tone="neutral">ohne Minimum</x-fa::badge>@break
                                                @default<x-fa::badge tone="ok">ok</x-fa::badge>
                                            @endswitch
                                        </td>
                                        <td class="text-right whitespace-nowrap">
                                            <x-fa::button size="sm" wire:click="lagerartikelSpeichern({{ $la['gp_id'] }})">Speichern</x-fa::button>
                                            <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-x-mark" label="Kein Lagerartikel mehr" wire:click="lagerartikelEntfernen({{ $la['gp_id'] }})" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @if($warengruppenVorschlag !== [])
                    {{-- Spec 74: Vorschlag nach Warengruppe — typische Vorrats-Gruppen (Gewürze, Öle, Essig …) zuerst --}}
                    <div class="flex flex-col gap-1.5 pt-2" data-lager-wg-vorschlag>
                        <p class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Vorschlag nach Warengruppe</p>
                        <p class="{{ $leise }}">Grundprodukte, die dieser Betrieb nutzt (Bestand oder im letzten Jahr bestellt) und die noch kein Lagerartikel sind.</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach(array_slice($warengruppenVorschlag, 0, 12) as $wg)
                                <x-fa::button size="sm" :variant="$wg['typisch'] ? 'primary' : 'ghost'" wire:click="warengruppeAlsLagerartikel('{{ $wg['code'] }}')" wire:confirm="{{ $wg['anzahl'] }} Grundprodukt(e) aus „{{ $wg['name'] }}“ als Lagerartikel markieren?" wire:key="wg-{{ $wg['code'] }}">{{ $wg['name'] }} ({{ $wg['anzahl'] }})</x-fa::button>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="flex flex-col gap-1.5 pt-2">
                    <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="vorratSuche" placeholder="Grundprodukt als Lagerartikel ergänzen …" class="w-80" aria-label="Lagerartikel ergänzen" />
                    @foreach($vorratKandidaten as $gp)
                        <button type="button" wire:key="vk-{{ $gp->id }}" wire:click="lagerartikelHinzu({{ $gp->id }})" class="self-start inline-flex items-center gap-1 text-[length:var(--fa-text-md)] text-[var(--fa-accent)] hover:underline">@svg('heroicon-m-plus', 'w-3.5 h-3.5') {{ $gp->name }}</button>
                    @endforeach
                </div>
            </x-fa::section>
        @endif

        {{-- ── Bewegungen ──────────────────────────────────────────────── --}}
        @if($reiter === 'bewegungen')
            @php
                $quellen = ['' => 'Alle Quellen', 'wareneingang' => 'Wareneingang', 'inventur' => 'Inventur', 'zugang' => 'Zugang (von Hand)', 'abgang' => 'Abgang (von Hand)', 'umlagerung' => 'Umlagerung', 'storno' => 'Storno', 'produktion' => 'Produktion', 'entnahme' => 'Entnahme (Eigenproduktion)'];
                $quelleText = $quelleText + ['zugang' => 'Zugang', 'abgang' => 'Abgang', 'umlagerung' => 'Umlagerung', 'storno' => 'Storno', 'produktion' => 'Produktion', 'entnahme' => 'Entnahme (Eigenproduktion)'];
                $alleGruende = $gruende['zugang'] + $gruende['abgang'];
            @endphp
            {{-- Spec 67: Hand-Buchung --}}
            @if($buchungOffen)
                <x-fa::section :title="['zugang' => 'Zugang buchen', 'abgang' => 'Abgang buchen', 'umlagerung' => 'Umlagern'][$buchung['art']] ?? 'Bewegung buchen'" icon="heroicon-o-pencil-square" data-lager-buchung>
                    <x-slot:actions>
                        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="buchungSchliessen">Abbrechen</x-fa::button>
                    </x-slot:actions>
                    <x-fa::choice name="buchung.art" :options="['zugang' => 'Zugang', 'abgang' => 'Abgang', 'umlagerung' => 'Umlagerung']" />
                    <div class="grid gap-3 md:grid-cols-2">
                        <x-fa::field label="Grundprodukt" for="b-gp">
                            @if($buchung['gp_id'])
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ $buchung['gp_name'] }}</span>
                                    <x-fa::button size="sm" variant="ghost" wire:click="$set('buchung.gp_id', null)">ändern</x-fa::button>
                                </div>
                            @else
                                <x-fa::input id="b-gp" type="search" wire:model.live.debounce.300ms="buchungGpSuche" placeholder="Grundprodukt suchen …" data-lager-buchung-gp />
                                @foreach($buchungKandidaten as $gp)
                                    <button type="button" wire:key="bk-{{ $gp->id }}" wire:click="buchungGpWaehlen({{ $gp->id }})" class="block text-left text-[length:var(--fa-text-md)] text-[var(--fa-accent)] hover:underline">{{ $gp->name }}</button>
                                @endforeach
                            @endif
                        </x-fa::field>
                        <div class="flex flex-wrap items-end gap-2">
                            <x-fa::field :label="$buchung['art'] === 'umlagerung' ? 'Von Lagerort' : 'Lagerort'" for="b-ort">
                                <x-fa::select id="b-ort" wire:model.live="buchung.location_id" :options="$orte->pluck('name', 'id')" placeholder="wählen" class="w-48" />
                            </x-fa::field>
                            @if($buchung['art'] === 'umlagerung')
                                <x-fa::field label="Nach Lagerort" for="b-ziel">
                                    <x-fa::select id="b-ziel" wire:model="buchung.ziel_location_id" :options="$orte->pluck('name', 'id')" placeholder="wählen" class="w-48" />
                                </x-fa::field>
                            @endif
                            <x-fa::field label="Datum" for="b-datum"><x-fa::input id="b-datum" type="date" wire:model="buchung.datum" /></x-fa::field>
                        </div>
                        <x-fa::field label="Menge" for="b-menge">
                            @php $bg = $buchungGebinde['gebinde'] ?? null; $be = $buchungGebinde['einheit'] ?? 'kg'; @endphp
                            <div class="flex flex-wrap items-center gap-1.5">
                                @if($bg)
                                    @if($bg['pack_units'])
                                        <x-fa::input wire:model="buchung.kartons" inputmode="decimal" class="w-16 text-right" aria-label="{{ $bg['pack_label'] }}" /> <span class="{{ $leise }}">{{ $bg['pack_label'] }}</span>
                                    @endif
                                    <x-fa::input wire:model="buchung.einheiten" inputmode="decimal" class="w-16 text-right" aria-label="{{ $bg['unit_label'] }}" /> <span class="{{ $leise }}">{{ $bg['unit_label'] }}</span>
                                    <x-fa::input wire:model="buchung.lose" inputmode="decimal" class="w-20 text-right" aria-label="lose in {{ $be }}" /> <span class="{{ $leise }}">{{ $be }} lose</span>
                                @else
                                    <x-fa::input id="b-menge" wire:model="buchung.menge" inputmode="decimal" class="w-24 text-right" data-lager-buchung-menge /> <span class="{{ $leise }}">{{ $be }}</span>
                                @endif
                            </div>
                            @if($bg)<p class="{{ $leise }}">@if($bg['pack_units'])1 {{ $bg['pack_label'] }} = {{ $zahl($bg['pack_units']) }} {{ $bg['unit_label'] }} · @endif 1 {{ $bg['unit_label'] }} = {{ $zahl($svc->anzeigeMenge($bg['unit_base'], $buchungGebinde['base_unit'])) }} {{ $be }}</p>@endif
                        </x-fa::field>
                        @if($buchung['art'] !== 'umlagerung')
                            <x-fa::field label="Grund" for="b-grund">
                                <x-fa::select id="b-grund" wire:model="buchung.grund" :options="$gruende[$buchung['art']] ?? []" placeholder="Grund wählen" class="w-56" data-lager-buchung-grund />
                            </x-fa::field>
                        @endif
                        @if($buchung['art'] === 'zugang')
                            <x-fa::field label="Preis je {{ $be }} (optional)" for="b-preis" hint="Leer = aktueller Einkaufspreis">
                                <x-fa::input id="b-preis" wire:model="buchung.preis" inputmode="decimal" class="w-28 text-right" />
                            </x-fa::field>
                        @endif
                        <x-fa::field label="Notiz" for="b-notiz"><x-fa::input id="b-notiz" wire:model="buchung.notiz" placeholder="optional" /></x-fa::field>
                    </div>
                    <div class="flex justify-end">
                        <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="bewegungBuchen" data-lager-buchung-speichern>Buchen</x-fa::button>
                    </div>
                </x-fa::section>
            @endif

            <x-fa::section title="Bewegungen" icon="heroicon-o-arrows-right-left" description="Die letzten 200 Zu- und Abgänge." data-lager-bewegungen>
                <x-slot:actions>
                    @unless($buchungOffen)
                        <div class="flex flex-wrap gap-2">
                            <x-fa::button size="sm" icon="heroicon-m-arrow-down-tray" wire:click="buchungOeffnen('zugang')" data-lager-zugang>Zugang</x-fa::button>
                            <x-fa::button size="sm" icon="heroicon-m-arrow-up-tray" wire:click="buchungOeffnen('abgang')" data-lager-abgang>Abgang</x-fa::button>
                            <x-fa::button size="sm" icon="heroicon-m-arrows-right-left" wire:click="buchungOeffnen('umlagerung')">Umlagern</x-fa::button>
                        </div>
                    @endunless
                </x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    <x-fa::select wire:model.live="quelle" size="sm" :options="$quellen" class="w-48" aria-label="Quelle" />
                    <x-fa::select wire:model.live="grundFilter" size="sm" :options="$alleGruende" placeholder="Jeder Grund" class="w-48" aria-label="Grund" />
                </div>
                @if($bewegungen->isEmpty())
                    <x-fa::empty compact icon="heroicon-o-arrows-right-left" title="Keine Bewegungen" />
                @else
                    <div class="overflow-x-auto">
                        <table class="fa-table fa-table--compact min-w-[760px]">
                            <thead><tr><th>Datum</th><th>Grundprodukt</th><th>Lagerort</th><th>Quelle</th><th>Grund</th><th class="text-right">Menge</th><th class="text-right">Wert</th><th>Notiz</th><th></th></tr></thead>
                            <tbody>
                                @foreach($bewegungen as $m)
                                    <tr wire:key="m-{{ $m->id }}" class="{{ isset($storniert[$m->id]) ? 'opacity-50' : '' }}">
                                        <td class="tabular-nums">{{ $m->moved_at?->format('d.m.Y') ?? '–' }}</td>
                                        <td class="font-medium">{{ $m->gp?->name ?? $m->supplierItem?->designation ?? $m->recipe?->name ?? '—' }}</td>
                                        <td>{{ $m->location?->name ?? '—' }}</td>
                                        <td>{{ $quelleText[$m->source] ?? $m->source }}@if($m->order?->supplier) <span class="{{ $leise }}">· {{ $m->order->supplier->name }}</span>@endif</td>
                                        <td>{{ $m->reason ? ($alleGruende[$m->reason] ?? $m->reason) : '' }}</td>
                                        <td class="text-right tabular-nums {{ $m->direction === 'out' ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ok)]' }}">{{ $m->direction === 'out' ? '−' : '+' }}{{ $zahl($svc->anzeigeMenge((float) $m->qty_base, $m->base_unit)) }} {{ $svc->anzeigeEinheit($m->base_unit) }}</td>
                                        <td class="text-right tabular-nums">@if($m->value_eur !== null)<x-fa::money :value="$m->value_eur" />@endif</td>
                                        <td class="{{ $leise }}">{{ $m->note }}{{ isset($storniert[$m->id]) ? " · storniert" : "" }}</td>
                                        <td class="text-right">
                                            @if($m->istHandbuchung() && ! isset($storniert[$m->id]) && ($m->direction === 'out' || $m->source !== 'umlagerung'))
                                                <x-fa::button size="sm" variant="ghost" wire:click="stornieren({{ $m->id }})" wire:confirm="Buchung stornieren? Es wird eine Gegenbuchung angelegt." data-lager-storno="{{ $m->id }}">Storno</x-fa::button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-fa::section>
        @endif

        {{-- ── Inventuren ──────────────────────────────────────────────── --}}
        @if($reiter === 'inventur')
            {{-- Vorne nur die Liste (Inventur Juni, September …); Zählen, Ergänzen, Buchen im Editor (Wunsch Dominique 2026-10-08) --}}
                <x-fa::section title="Neue Inventur" icon="heroicon-o-clipboard-document-check" description="Die Zählliste wird mit allem vorbelegt, was am Lagerort Bestand hat oder in den letzten 90 Tagen eingekauft wurde." data-lager-inventur-neu>
                    <div class="flex flex-wrap items-end gap-2">
                        <x-fa::field label="Lagerort" for="inv-ort"><x-fa::select id="inv-ort" wire:model="neuLagerortId" :options="$orte->pluck('name', 'id')" class="w-48" /></x-fa::field>
                        <x-fa::field label="Stichtag" for="inv-datum"><x-fa::input id="inv-datum" type="date" wire:model="neuDatum" /></x-fa::field>
                        <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="inventurAnlegen" data-lager-inventur-anlegen>Inventur anlegen</x-fa::button>
                    </div>
                </x-fa::section>

                <x-fa::section title="Inventuren" icon="heroicon-o-clipboard-document-list" data-lager-inventuren>
                    @if($inventuren->isEmpty())
                        <x-fa::empty compact icon="heroicon-o-clipboard-document-list" title="Noch keine Inventur" />
                    @else
                        <table class="fa-table fa-table--compact">
                            <thead><tr><th>Stichtag</th><th>Lagerort</th><th class="text-right">Positionen</th><th>Status</th><th class="text-right">Wert</th><th></th></tr></thead>
                            <tbody>
                                @foreach($inventuren as $c)
                                    <tr wire:key="c-{{ $c->id }}">
                                        <td class="tabular-nums font-medium">{{ $c->count_date->format('d.m.Y') }}</td>
                                        <td>{{ $c->location?->name ?? '—' }}</td>
                                        <td class="text-right tabular-nums">{{ $c->lines_count }}</td>
                                        <td>@if($c->istGebucht())<x-fa::badge tone="ok">gebucht</x-fa::badge>@else<x-fa::badge tone="warn">offen</x-fa::badge>@endif</td>
                                        <td class="text-right">@if($c->istGebucht())<x-fa::money :value="$c->value_total" />@else<span class="{{ $leise }}">–</span>@endif</td>
                                        <td class="text-right"><x-fa::button size="sm" wire:click="inventurOeffnen({{ $c->id }})">{{ $c->istGebucht() ? 'Ansehen' : 'Zählen' }}</x-fa::button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </x-fa::section>
            <x-foodalchemist::modal name="lager-inventur" fullscreen dark-canvas title="Inventur" :title-name="$inventur !== null ? $inventur->count_date->format('d.m.Y') . ' · ' . ($inventur->location?->name ?? '—') : null">
                @if($inventur !== null)
                <div class="flex flex-col gap-4" data-lager-inventur-editor>
                @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
                @if($hinweis)<x-fa::notice tone="info">{{ $hinweis }}</x-fa::notice>@endif
                @php $gebucht = $inventur->istGebucht(); @endphp
                <x-fa::section title="Zählliste" icon="heroicon-o-clipboard-document-check" data-lager-inventur="{{ $inventur->id }}">
                    <x-slot:actions>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-fa::button size="sm" icon="heroicon-m-printer" href="{{ route('foodalchemist.lager.zaehlliste', $inventur->id) }}" target="_blank">Zählliste drucken</x-fa::button>
                            @unless($gebucht)
                                <x-fa::button size="sm" variant="danger" icon="heroicon-m-trash" wire:click="loeschen" wire:confirm="Offene Inventur verwerfen?">Verwerfen</x-fa::button>
                                <x-fa::button size="sm" variant="primary" icon="heroicon-m-check" wire:click="buchen" wire:confirm="Inventur buchen? Der Bestand wird auf die gezählten Mengen gesetzt. Danach ist die Inventur gesperrt." data-lager-inventur-buchen>Buchen</x-fa::button>
                            @endunless
                        </div>
                    </x-slot:actions>

                    <x-fa::kpis :items="[
                        ['label' => 'Positionen', 'value' => $summen['positionen']],
                        ['label' => 'Gezählt', 'value' => $summen['gezaehlt'] . ' / ' . $summen['positionen'], 'tone' => $summen['offen'] > 0 ? 'warn' : 'ok'],
                        ['label' => 'Wert gezählt', 'value' => number_format($summen['wert'], 2, ',', '.') . ' €', 'primary' => true],
                        ['label' => 'Differenz zum Soll', 'value' => number_format($summen['differenz_wert'], 2, ',', '.') . ' €', 'tone' => $summen['differenz_wert'] < 0 ? 'crit' : null],
                    ]" />
                    @if($gebucht)
                        <p class="{{ $leise }}">Gebucht am {{ $inventur->booked_at?->format('d.m.Y H:i') }} — Bestandswert {{ number_format((float) $inventur->value_total, 2, ',', '.') }} €.@if($inventur->uncounted_zeroed) Nicht gezählte Positionen wurden als 0 gebucht.@endif</p>
                    @else
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                            @if($summen['offen'] > 0)
                                <p class="{{ $leise }}">{{ $summen['offen'] }} Position(en) noch nicht gezählt.</p>
                            @endif
                            <label class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" data-lager-null-buchen>
                                <input type="checkbox" wire:model.live="nichtGezaehltNull" class="w-4 h-4 rounded accent-[var(--fa-accent)]" />
                                Nicht Gezähltes beim Buchen als 0 übernehmen
                            </label>
                        </div>
                    @endif

                    @include('foodalchemist::livewire.lager.partials.filter', ['mitStatus' => true, 'mitOhnePreis' => true])

                    @if($zeilen->isEmpty())
                        <x-fa::empty compact icon="heroicon-o-funnel" title="Keine Position passt zu den Filtern" />
                    @else
                    <div class="overflow-x-auto">
                        <table class="fa-table fa-table--compact min-w-[760px]" data-lager-zaehlliste>
                            <thead><tr><th>Grundprodukt</th><th class="text-right">Soll</th><th class="text-right">Gezählt</th><th class="text-right">Differenz</th><th class="text-right">EK</th><th class="text-right">Wert</th></tr></thead>
                            <tbody>
                                @php $letzterPlatz = false; @endphp
                                @foreach($zeilen as $l)
                                    @php
                                        $einheit = $svc->anzeigeEinheit($l->base_unit);
                                        $soll = $svc->anzeigeMenge((float) $l->qty_expected, $l->base_unit);
                                        $ist = $svc->anzeigeMenge($l->qty_counted !== null ? (float) $l->qty_counted : null, $l->base_unit);
                                        $diff = $ist !== null ? round($ist - $soll, 3) : null;
                                        $platz = $l->storage_bin_id;
                                        $einheitInhalt = $l->hatGebinde() ? $svc->anzeigeMenge((float) $l->unit_base, $l->base_unit) : null;
                                    @endphp
                                    @if($platz !== $letzterPlatz)
                                        @php $letzterPlatz = $platz; @endphp
                                        <tr wire:key="pl-{{ $platz ?? 'ohne' }}" class="bg-[var(--fa-ground)]">
                                            <td colspan="6" class="font-semibold text-[var(--fa-ink-2)]">
                                                <span class="inline-flex items-center gap-1.5">@svg('heroicon-m-map-pin', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]') {{ $l->bin?->name ?? 'Ohne Stellplatz' }}</span>
                                                @if($platz !== null)<a href="{{ route('foodalchemist.lager.zaehlliste', ['count' => $inventur->id, 'stellplatz' => $platz]) }}" target="_blank" class="ml-2 text-[length:var(--fa-text-sm)] font-normal text-[var(--fa-accent)] hover:underline">nur diesen Platz drucken</a>@endif
                                            </td>
                                        </tr>
                                    @endif
                                    <tr wire:key="l-{{ $l->id }}">
                                        <td>
                                            <div class="font-medium">{{ $l->gp?->name ?? $l->supplierItem?->designation ?? $l->recipe?->name ?? '—' }}</div>
                                            @if($l->hatGebinde())
                                                <div class="{{ $leise }}">@if($l->pack_units)1 {{ $l->pack_label }} = {{ $zahl($l->pack_units) }} {{ $l->unit_label }} · @endif 1 {{ $l->unit_label }} = {{ $zahl($einheitInhalt) }} {{ $einheit }}</div>
                                            @endif
                                        </td>
                                        <td class="text-right align-top"><x-fa::menge :value="$soll" :unit="$einheit" /></td>
                                        <td class="text-right align-top">
                                            @if($gebucht)
                                                <x-fa::menge :value="$ist" :unit="$einheit" />
                                                @if($l->counted_packs !== null || $l->counted_units !== null)
                                                    <div class="{{ $leise }}">@if($l->counted_packs !== null){{ $zahl($l->counted_packs) }} {{ $l->pack_label }} @endif @if($l->counted_units !== null){{ $zahl($l->counted_units) }} {{ $l->unit_label }} @endif @if($l->counted_loose !== null)+ {{ $zahl($l->counted_loose) }} {{ $einheit }}@endif</div>
                                                @endif
                                            @elseif($l->hatGebinde())
                                                {{-- Zählen wie im Regal: Karton · Einheit · lose; jede Änderung schickt alle drei Felder --}}
                                                <span class="inline-flex items-center gap-1 flex-wrap justify-end" data-lager-gebinde="{{ $l->id }}">
                                                    @if($l->pack_units)
                                                        <input type="text" inputmode="decimal" value="{{ $eingabe($l->counted_packs) }}" data-g="k" class="{{ $feld }} w-14" aria-label="{{ $l->pack_label }}"
                                                               wire:change="zaehlenGebinde({{ $l->id }}, $event.target.closest('[data-lager-gebinde]').querySelector('[data-g=k]').value, $event.target.closest('[data-lager-gebinde]').querySelector('[data-g=e]').value, $event.target.closest('[data-lager-gebinde]').querySelector('[data-g=l]').value)" />
                                                        <span class="{{ $leise }}">{{ $l->pack_label }}</span>
                                                    @endif
                                                    <input type="text" inputmode="decimal" value="{{ $eingabe($l->counted_units) }}" data-g="e" class="{{ $feld }} w-14" aria-label="{{ $l->unit_label }}"
                                                           wire:change="zaehlenGebinde({{ $l->id }}, ($event.target.closest('[data-lager-gebinde]').querySelector('[data-g=k]') || {}).value, $event.target.value, $event.target.closest('[data-lager-gebinde]').querySelector('[data-g=l]').value)" />
                                                    <span class="{{ $leise }}">{{ $l->unit_label }}</span>
                                                    <input type="text" inputmode="decimal" value="{{ $eingabe($l->counted_loose ?? ($l->qty_counted !== null && $l->counted_packs === null && $l->counted_units === null ? $ist : null)) }}" data-g="l" class="{{ $feld }} w-16" aria-label="lose in {{ $einheit }}"
                                                           wire:change="zaehlenGebinde({{ $l->id }}, ($event.target.closest('[data-lager-gebinde]').querySelector('[data-g=k]') || {}).value, $event.target.closest('[data-lager-gebinde]').querySelector('[data-g=e]').value, $event.target.value)" />
                                                    <span class="{{ $leise }} w-6 text-left">{{ $einheit }}</span>
                                                </span>
                                                @if($ist !== null)<div class="{{ $leise }} tabular-nums">= {{ $zahl($ist) }} {{ $einheit }}</div>@endif
                                            @else
                                                <span class="inline-flex items-center gap-1">
                                                    <input type="text" inputmode="decimal" value="{{ $ist !== null ? str_replace('.', ',', (string) $ist) : '' }}" wire:change="zaehlen({{ $l->id }}, $event.target.value)"
                                                           class="{{ $feld }} w-24" aria-label="Gezählt in {{ $einheit }}" data-lager-zaehlen="{{ $l->id }}" />
                                                    <span class="{{ $leise }} w-6 text-left">{{ $einheit }}</span>
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-right align-top tabular-nums {{ $diff !== null && $diff < 0 ? 'text-[var(--fa-crit)]' : '' }}">@if($diff !== null){{ $diff > 0 ? '+' : '' }}{{ $zahl($diff) }} {{ $einheit }}@else<span class="{{ $leise }}">–</span>@endif</td>
                                        <td class="text-right align-top tabular-nums">@if($l->price_per_base !== null){{ number_format((float) $l->price_per_base * ($l->base_unit === 'Stk' ? 1 : 1000), 2, ',', '.') }} €<span class="{{ $leise }}">/{{ $einheit }}</span>@else<span class="{{ $leise }}">kein Preis</span>@endif</td>
                                        <td class="text-right align-top">@if($l->qty_counted !== null)<x-fa::money :value="$l->wert()" missing="ohne Preis" />@else<span class="{{ $leise }}">–</span>@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif

                    @unless($gebucht)
                        <div class="flex flex-col gap-1.5 pt-2" data-lager-position-hinzu>
                            <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="positionSuche" placeholder="Grundprodukt ergänzen …" class="w-72" aria-label="Grundprodukt ergänzen" />
                            @foreach($kandidaten as $gp)
                                <button type="button" wire:key="k-{{ $gp->id }}" wire:click="positionHinzu({{ $gp->id }})" class="self-start inline-flex items-center gap-1 text-[length:var(--fa-text-md)] text-[var(--fa-accent)] hover:underline">@svg('heroicon-m-plus', 'w-3.5 h-3.5') {{ $gp->name }}</button>
                            @endforeach
                        </div>
                    @endunless
                </x-fa::section>
                </div>
                @endif
            </x-foodalchemist::modal>
        @endif

        {{-- ── Eigenproduktion (Spec 69) ─────────────────────────────────── --}}
        @if($reiter === 'eigenproduktion')
            <x-fa::section title="Einlagern" icon="heroicon-o-arrow-down-tray" description="Selbst Hergestelltes als Charge einlagern. „Verbrauchen bis“ kommt aus Lagerart und Haltbarkeit am Rezept." data-lager-einlagern>
                @if(! empty($einlagern['recipe_id']))
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ $einlagern['name'] }}</span>
                        <x-fa::button size="sm" variant="ghost" wire:click="$set('einlagern', [])">ändern</x-fa::button>
                    </div>
                    <div class="grid gap-3 md:grid-cols-3">
                        <x-fa::field :label="'Menge (' . $einlagern['einheit'] . ')'" for="ep-menge"><x-fa::input id="ep-menge" wire:model="einlagern.menge" inputmode="decimal" class="w-28 text-right" data-lager-einlagern-menge /></x-fa::field>
                        <x-fa::field label="Lagerort" for="ep-ort"><x-fa::select id="ep-ort" wire:model="einlagern.location_id" :options="$orte->pluck('name', 'id')" placeholder="Standardlager" /></x-fa::field>
                        <x-fa::field label="Lagerart" for="ep-art"><x-fa::select id="ep-art" wire:model.live="einlagern.lagerart" :options="! empty($einlagern['lagerarten']) ? array_intersect_key(\Platform\FoodAlchemist\Services\EigenproduktionService::LAGERARTEN, array_flip($einlagern['lagerarten'])) : \Platform\FoodAlchemist\Services\EigenproduktionService::LAGERARTEN" /></x-fa::field>
                        <x-fa::field label="Hergestellt am" for="ep-prod"><x-fa::input id="ep-prod" type="date" wire:model="einlagern.produziert_am" /></x-fa::field>
                        @if(($einlagern['lagerart'] ?? '') === 'tiefgekuehlt')
                            <x-fa::field label="Eingefroren am" for="ep-tk" hint="leer = Herstelldatum"><x-fa::input id="ep-tk" type="date" wire:model="einlagern.eingefroren_am" /></x-fa::field>
                        @endif
                        <x-fa::field label="Verbrauchen bis" for="ep-bis" hint="leer = aus der Haltbarkeit"><x-fa::input id="ep-bis" type="date" wire:model="einlagern.verbrauchen_bis" /></x-fa::field>
                    </div>
                    <x-fa::field label="Notiz" for="ep-notiz"><x-fa::input id="ep-notiz" wire:model="einlagern.notiz" placeholder="optional" /></x-fa::field>
                    <div class="flex justify-end"><x-fa::button variant="primary" icon="heroicon-m-check" wire:click="einlagernSpeichern" data-lager-einlagern-speichern>Einlagern</x-fa::button></div>
                @else
                    <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="einlagernSuche" placeholder="Basisrezept oder Gericht suchen …" class="w-80" aria-label="Rezept suchen" data-lager-einlagern-suche />
                    @foreach($einlagernTreffer as $r)
                        <button type="button" wire:key="ept-{{ $r->id }}" wire:click="einlagernRezept({{ $r->id }})" class="self-start text-left text-[length:var(--fa-text-md)] text-[var(--fa-accent)] hover:underline">{{ $r->name }}<span class="{{ $leise }}"> · {{ $r->is_sales_recipe ? 'Gericht' : 'Basisrezept' }}</span></button>
                    @endforeach
                    @if(! empty($einlagern['letzte_charge']))
                        <a href="{{ route('foodalchemist.etiketten.index', ['quelle' => 'charge', 'id' => $einlagern['letzte_charge']]) }}" target="_blank" class="self-start inline-flex items-center gap-1 text-[length:var(--fa-text-md)] text-[var(--fa-accent)] hover:underline" data-lager-etikett-charge>@svg('heroicon-m-tag', 'w-4 h-4') Etiketten für die neue Charge drucken</a>
                    @endif
                @endif
            </x-fa::section>

            <x-fa::section title="Chargen im Lager" icon="heroicon-o-archive-box" :meta="$chargen->count()" data-lager-chargen>
                <div class="flex flex-wrap items-center gap-3">
                    <x-fa::select wire:model.live="lagerortId" size="sm" placeholder="Alle Lagerorte" :options="$orte->pluck('name', 'id')" class="w-48" aria-label="Lagerort" />
                    <label class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]"><input type="checkbox" wire:model.live="nurAblaufend" class="w-4 h-4 rounded accent-[var(--fa-accent)]" /> Nur bald ablaufend (≤ 3 Tage)</label>
                    @if($ablaufendAnzahl > 0)<x-fa::badge tone="warn">{{ $ablaufendAnzahl }} bald ablaufend</x-fa::badge>@endif
                </div>
                @if($chargen->isEmpty())
                    <x-fa::empty compact icon="heroicon-o-archive-box" title="Keine offenen Chargen" />
                @else
                    <div class="overflow-x-auto">
                        <table class="fa-table fa-table--compact min-w-[760px]">
                            <thead><tr><th>Charge</th><th>Rezept</th><th>Lagerort</th><th>Lagerart</th><th class="text-right">Rest</th><th>Hergestellt</th><th>Verbrauchen bis</th><th>Entnehmen</th><th></th></tr></thead>
                            <tbody>
                                @foreach($chargen as $b)
                                    @php $tage = $b->tageBisAblauf(); @endphp
                                    <tr wire:key="ch-{{ $b->id }}">
                                        <td class="tabular-nums font-medium">{{ $b->charge }}</td>
                                        <td>{{ $b->recipe?->name ?? '—' }}</td>
                                        <td>{{ $b->location?->name ?? '—' }}</td>
                                        <td>{{ \Platform\FoodAlchemist\Services\EigenproduktionService::LAGERARTEN[$b->storage_type] ?? $b->storage_type }}</td>
                                        <td class="text-right tabular-nums">{{ $zahl($eigen->anzeigeMenge((float) $b->qty_rest, $b->base_unit)) }} {{ $eigen->anzeigeEinheit($b->base_unit) }}</td>
                                        <td class="tabular-nums">{{ $b->produced_at?->format('d.m.Y') }}</td>
                                        <td class="tabular-nums {{ $tage !== null && $tage < 0 ? 'text-[var(--fa-crit)] font-semibold' : ($tage !== null && $tage <= 3 ? 'text-[var(--fa-warn)] font-semibold' : '') }}">{{ $b->best_before?->format('d.m.Y') ?? '–' }}{{ $tage !== null && $tage < 0 ? ' · abgelaufen' : '' }}</td>
                                        <td>
                                            <span class="inline-flex items-center gap-1">
                                                <input type="text" inputmode="decimal" wire:model="entnahme.{{ $b->id }}.menge" class="fa-control h-7 w-16 text-right tabular-nums text-[length:var(--fa-text-sm)]" aria-label="Menge in {{ $eigen->anzeigeEinheit($b->base_unit) }}" />
                                                <select wire:model="entnahme.{{ $b->id }}.grund" class="fa-control fa-select h-7 pr-8 text-[length:var(--fa-text-sm)]" aria-label="Grund">
                                                    @foreach(\Platform\FoodAlchemist\Services\EigenproduktionService::ENTNAHME_GRUENDE as $gk => $gl)<option value="{{ $gk }}">{{ $gl }}</option>@endforeach
                                                </select>
                                                <x-fa::button size="sm" wire:click="entnehmen({{ $b->id }})" data-lager-entnehmen="{{ $b->id }}">Entnehmen</x-fa::button>
                                            </span>
                                        </td>
                                        <td class="text-right"><x-fa::icon-button size="sm" icon="heroicon-m-tag" label="Etikett dieser Charge" :href="route('foodalchemist.etiketten.index', ['quelle' => 'charge', 'id' => $b->id])" target="_blank" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-fa::section>
        @endif

        {{-- ── Einrichten (Spec 66b) ───────────────────────────────────── --}}
        @if($reiter === 'einrichten')
            @if($orte->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2" data-lager-einrichten-ort>
                    <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Lagerort</span>
                    <x-fa::select wire:model.live="lagerortId" size="sm" :options="$orte->pluck('name', 'id')" class="w-56" aria-label="Lagerort" />
                    <span class="{{ $leise }}">Weitere Lagerorte unter Einstellungen → Einkauf.</span>
                </div>

                <div class="grid gap-4 lg:grid-cols-[minmax(17rem,22rem)_minmax(0,1fr)] items-start">
                    {{-- Stellplätze --}}
                    <x-fa::section title="Stellplätze" icon="heroicon-o-map-pin" description="In der Reihenfolge deines Laufwegs. Die Zone steuert den Vorschlag." data-lager-plaetze>
                        @if($plaetze->isEmpty())
                            <p class="{{ $leise }}">Noch keine Stellplätze. Zum Beispiel: Kühlhaus, TK-Raum, Trockenlager Regal 1.</p>
                        @else
                            <ul class="flex flex-col divide-y divide-[var(--fa-line)]">
                                @foreach($plaetze as $p)
                                    <li wire:key="p-{{ $p->id }}" class="flex items-center gap-1.5 py-1.5">
                                        <div class="flex flex-col">
                                            <x-fa::icon-button size="sm" icon="heroicon-m-chevron-up" label="Im Laufweg nach vorn" wire:click="platzVerschieben({{ $p->id }}, -1)" :disabled="$loop->first" />
                                            <x-fa::icon-button size="sm" icon="heroicon-m-chevron-down" label="Im Laufweg nach hinten" wire:click="platzVerschieben({{ $p->id }}, 1)" :disabled="$loop->last" />
                                        </div>
                                        <div class="flex-1 min-w-0 flex flex-col gap-1">
                                            <input type="text" value="{{ $p->name }}" wire:change="platzUmbenennen({{ $p->id }}, $event.target.value)" class="fa-control h-7 w-full text-[length:var(--fa-text-md)]" aria-label="Name des Stellplatzes" />
                                            <div class="flex items-center gap-2">
                                                <select wire:change="platzZone({{ $p->id }}, $event.target.value)" class="fa-control fa-select h-7 pr-8 text-[length:var(--fa-text-sm)]" aria-label="Zone">
                                                    <option value="">ohne Zone</option>
                                                    @foreach($zonen as $z => $zl)<option value="{{ $z }}" @selected($p->zone === $z)>{{ $zl }}</option>@endforeach
                                                </select>
                                                <span class="{{ $leise }}">{{ $p->zuordnungen_count }} GP</span>
                                            </div>
                                        </div>
                                        <x-fa::icon-button size="sm" icon="heroicon-m-tag" label="Regal-Etikett drucken" :href="route('foodalchemist.etiketten.index', ['quelle' => 'stellplatz', 'id' => $p->id])" target="_blank" />
                                        <x-fa::icon-button size="sm" tone="danger" icon="heroicon-m-trash" label="Stellplatz löschen" wire:click="platzLoeschen({{ $p->id }})" wire:confirm="Stellplatz löschen? Die zugeordneten Grundprodukte verlieren ihren Stammplatz." />
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <form wire:submit="platzAnlegen" class="flex flex-wrap items-end gap-2 pt-2" data-lager-platz-neu>
                            <x-fa::input wire:model="neuPlatzName" size="sm" placeholder="z. B. Kühlhaus Regal A" class="flex-1 min-w-[10rem]" aria-label="Neuer Stellplatz" />
                            <x-fa::select wire:model="neuPlatzZone" size="sm" placeholder="Zone" :options="$zonen" class="w-32" aria-label="Zone" />
                            <x-fa::button size="sm" type="submit" icon="heroicon-m-plus">Anlegen</x-fa::button>
                        </form>
                    </x-fa::section>

                    {{-- Stammplätze --}}
                    <x-fa::section title="Grundprodukte einsortieren" icon="heroicon-o-squares-plus" data-lager-stammplaetze>
                        <x-slot:actions>
                            <x-fa::button size="sm" icon="heroicon-m-sparkles" wire:click="vorschlagUebernehmen" :disabled="$plaetze->whereNotNull('zone')->isEmpty()" data-lager-vorschlag>Automatisch einsortieren</x-fa::button>
                        </x-slot:actions>
                        <p class="{{ $leise }}">„Automatisch einsortieren" legt alles ohne Stellplatz nach Zustand und Warengruppe ab: TK → Tiefkühlung, frisches Fleisch, Fisch, Molkerei, Obst und Gemüse → Kühlung, Getränke → Getränke, Rest → Trocken. Vorhandene Zuordnungen bleiben.</p>
                        @include('foodalchemist::livewire.lager.partials.filter', [])
                        <div class="flex flex-wrap items-center gap-2" data-lager-masse>
                            <span class="{{ $leise }}">{{ count($einrichten) }} von {{ $einrichtenGesamt }} · {{ count($auswahl) }} markiert</span>
                            <x-fa::button size="sm" variant="ghost" wire:click="alleMarkieren">Alle sichtbaren markieren</x-fa::button>
                            @if($auswahl !== [])
                                <x-fa::button size="sm" variant="ghost" wire:click="$set('auswahl', [])">Markierung aufheben</x-fa::button>
                            @endif
                            <x-fa::select wire:model="zielPlatzId" size="sm" class="w-52" aria-label="Ziel-Stellplatz">
                                <option value="">Stellplatz entfernen</option>
                                @foreach($plaetze as $p)<option value="{{ $p->id }}">→ {{ $p->name }}</option>@endforeach
                            </x-fa::select>
                            <x-fa::button size="sm" variant="primary" wire:click="auswahlZuordnen" :disabled="$auswahl === []">Markierte zuordnen</x-fa::button>
                            <x-fa::button size="sm" icon="heroicon-m-archive-box" wire:click="auswahlAlsLagerartikel" :disabled="$auswahl === []" data-lager-als-lagerartikel>Als Lagerartikel markieren</x-fa::button>
                        </div>
                        @if($einrichten === [])
                            <x-fa::empty compact icon="heroicon-o-squares-plus" title="Keine Grundprodukte">{{ $einrichtenGesamt > 0 ? 'Kein Grundprodukt passt zu den Filtern.' : 'Hier erscheint, was an diesem Lagerort Bestand hat oder zuletzt eingekauft wurde.' }}</x-fa::empty>
                        @else
                            <div class="overflow-x-auto">
                                <table class="fa-table fa-table--compact min-w-[420px]">
                                    <thead><tr><th class="w-8"></th><th>Grundprodukt</th><th>Zustand</th><th>Stellplatz</th></tr></thead>
                                    <tbody>
                                        @foreach($einrichten as $r)
                                            @php $vorschlag = $r['bin_id'] === null && $r['bin_vorschlag'] !== null ? $plaetze->firstWhere('id', $r['bin_vorschlag']) : null; @endphp
                                            <tr wire:key="e-{{ $r['gp_id'] }}">
                                                <td><input type="checkbox" value="{{ $r['gp_id'] }}" wire:model.live="auswahl" class="w-4 h-4 rounded accent-[var(--fa-accent)]" aria-label="{{ $r['name'] }} markieren" /></td>
                                                <td class="font-medium">{{ $r['name'] }}</td>
                                                <td>{{ $r['zustand'] ?? '–' }}@if($r['warengruppe'])<span class="{{ $leise }}"> · WG {{ $r['warengruppe'] }}</span>@endif</td>
                                                <td>
                                                    <span class="inline-flex items-center gap-2">
                                                        <select wire:change="stammplatz({{ $r['gp_id'] }}, $event.target.value)" class="fa-control fa-select h-7 pr-8 text-[length:var(--fa-text-sm)] w-48" aria-label="Stellplatz für {{ $r['name'] }}">
                                                            <option value="">– kein Stellplatz –</option>
                                                            @foreach($plaetze as $p)<option value="{{ $p->id }}" @selected($r['bin_id'] === $p->id)>{{ $p->name }}</option>@endforeach
                                                        </select>
                                                        @if($vorschlag)<button type="button" wire:click="stammplatz({{ $r['gp_id'] }}, {{ $vorschlag->id }})" class="text-[length:var(--fa-text-sm)] text-[var(--fa-accent)] hover:underline" title="Vorschlag aus Zustand und Warengruppe">Vorschlag: {{ $vorschlag->name }}</button>@endif
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </x-fa::section>
                </div>
            @endif
        @endif
    </x-ui-page-container>
</x-ui-page>
