{{-- Spec 66 · Lager (Stufe 1): Bestand, Bewegungen, Inventuren. Periodisches Lager: Bestand aus
     Wareneingang + Inventur. --}}
@php
    $segment = 'h-8 px-3 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-md)] font-medium transition-colors duration-150';
    $segmentAn = 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm';
    $segmentAus = 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]';
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $quellen = ['' => 'Alle Quellen', 'wareneingang' => 'Wareneingang', 'inventur' => 'Inventur'];
    $quelleText = ['wareneingang' => 'Wareneingang', 'inventur' => 'Inventur', 'produktion' => 'Produktion'];
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-foodalchemist::shell.page-navbar title="Lager" icon="heroicon-o-archive-box" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Lager" subtitle="Bestand aus Wareneingang und Inventur. Der Verbrauch ergibt sich aus Anfangsbestand + Einkauf − Endbestand." />

        <div class="inline-flex items-center gap-0.5 p-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] self-start" role="tablist" data-lager-reiter>
            @foreach(['bestand' => 'Bestand', 'bewegungen' => 'Bewegungen', 'inventur' => 'Inventuren'] as $k => $l)
                <button type="button" wire:click="reiterSetzen('{{ $k }}')" class="{{ $segment }} {{ $reiter === $k ? $segmentAn : $segmentAus }}" role="tab" aria-selected="{{ $reiter === $k ? 'true' : 'false' }}">{{ $l }}</button>
            @endforeach
        </div>

        @if($fehler)<x-fa::notice tone="crit" data-lager-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($hinweis)<x-fa::notice tone="info">{{ $hinweis }}</x-fa::notice>@endif

        @if($orte->isEmpty())
            <x-fa::notice tone="info">Noch kein Lagerort angelegt. Der erste Wareneingang legt automatisch ein „Hauptlager" an; weitere Lagerorte (Kühlhaus, TK, Trocken) unter Einstellungen → Einkauf.</x-fa::notice>
        @endif

        {{-- ── Bestand ─────────────────────────────────────────────────── --}}
        @if($reiter === 'bestand')
            <x-fa::section title="Bestand" icon="heroicon-o-archive-box" data-lager-bestand>
                <x-slot:actions>
                    <span class="text-[length:var(--fa-text-md)] font-semibold tabular-nums">Wert <x-fa::money :value="$bestandWert" /></span>
                </x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    <x-fa::select wire:model.live="lagerortId" size="sm" placeholder="Alle Lagerorte" :options="$orte->pluck('name', 'id')" class="w-48" aria-label="Lagerort" />
                    <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="suche" placeholder="Grundprodukt suchen" class="w-64" aria-label="Suche" />
                    @if($ohnePreis > 0)<span class="{{ $leise }}">{{ $ohnePreis }} Position(en) ohne Preis — nicht im Wert enthalten.</span>@endif
                </div>
                @if($bestand === [])
                    <x-fa::empty compact icon="heroicon-o-archive-box" title="Kein Bestand">Bestand entsteht durch Wareneingang oder Inventur.</x-fa::empty>
                @else
                    <div class="overflow-x-auto">
                        <table class="fa-table fa-table--compact min-w-[640px]">
                            <thead><tr><th>Grundprodukt</th><th>Lagerort</th><th class="text-right">Menge</th><th class="text-right">EK je kg/l/Stk</th><th class="text-right">Wert</th><th>Letzte Bewegung</th></tr></thead>
                            <tbody>
                                @foreach($bestand as $r)
                                    <tr wire:key="b-{{ $r['stock_id'] }}">
                                        <td class="font-medium">{{ $r['name'] }}</td>
                                        <td>{{ $r['lagerort'] }}</td>
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

        {{-- ── Bewegungen ──────────────────────────────────────────────── --}}
        @if($reiter === 'bewegungen')
            <x-fa::section title="Bewegungen" icon="heroicon-o-arrows-right-left" description="Die letzten 200 Zu- und Abgänge." data-lager-bewegungen>
                <x-fa::select wire:model.live="quelle" size="sm" :options="$quellen" class="w-48 self-start" aria-label="Quelle" />
                @if($bewegungen->isEmpty())
                    <x-fa::empty compact icon="heroicon-o-arrows-right-left" title="Keine Bewegungen" />
                @else
                    <div class="overflow-x-auto">
                        <table class="fa-table fa-table--compact min-w-[640px]">
                            <thead><tr><th>Datum</th><th>Grundprodukt</th><th>Lagerort</th><th>Quelle</th><th class="text-right">Menge</th><th>Notiz</th></tr></thead>
                            <tbody>
                                @foreach($bewegungen as $m)
                                    <tr wire:key="m-{{ $m->id }}">
                                        <td class="tabular-nums">{{ $m->moved_at?->format('d.m.Y') ?? '–' }}</td>
                                        <td class="font-medium">{{ $m->gp?->name ?? $m->supplierItem?->designation ?? '—' }}</td>
                                        <td>{{ $m->location?->name ?? '—' }}</td>
                                        <td>{{ $quelleText[$m->source] ?? $m->source }}@if($m->order?->supplier) <span class="{{ $leise }}">· {{ $m->order->supplier->name }}</span>@endif</td>
                                        <td class="text-right tabular-nums {{ $m->direction === 'out' ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ok)]' }}">{{ $m->direction === 'out' ? '−' : '+' }}{{ $svc->anzeigeMenge((float) $m->qty_base, $m->base_unit) !== null ? rtrim(rtrim(number_format($svc->anzeigeMenge((float) $m->qty_base, $m->base_unit), 3, ',', '.'), '0'), ',') : '' }} {{ $svc->anzeigeEinheit($m->base_unit) }}</td>
                                        <td class="{{ $leise }}">{{ $m->note }}</td>
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
            @if($inventur === null)
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
            @else
                @php $gebucht = $inventur->istGebucht(); @endphp
                <x-fa::section :title="'Inventur ' . $inventur->count_date->format('d.m.Y') . ' · ' . ($inventur->location?->name ?? '—')" icon="heroicon-o-clipboard-document-check" data-lager-inventur="{{ $inventur->id }}">
                    <x-slot:actions>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-fa::button size="sm" variant="ghost" icon="heroicon-m-arrow-left" wire:click="inventurSchliessen">Zurück</x-fa::button>
                            <x-fa::button size="sm" icon="heroicon-m-printer" href="{{ route('foodalchemist.lager.zaehlliste', $inventur->id) }}" target="_blank">Zählliste drucken</x-fa::button>
                            @unless($gebucht)
                                <x-fa::button size="sm" variant="danger" icon="heroicon-m-trash" wire:click="loeschen" wire:confirm="Offene Inventur verwerfen?">Verwerfen</x-fa::button>
                                <x-fa::button size="sm" variant="primary" icon="heroicon-m-check" wire:click="buchen" wire:confirm="Inventur buchen? Der Bestand wird auf die gezählten Mengen gesetzt. Nicht gezählte Positionen bleiben unverändert." data-lager-inventur-buchen>Buchen</x-fa::button>
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
                        <p class="{{ $leise }}">Gebucht am {{ $inventur->booked_at?->format('d.m.Y H:i') }} — Bestandswert {{ number_format((float) $inventur->value_total, 2, ',', '.') }} €.</p>
                    @elseif($summen['offen'] > 0)
                        <p class="{{ $leise }}">{{ $summen['offen'] }} Position(en) noch nicht gezählt — sie bleiben beim Buchen unverändert. Für „nichts mehr da" bitte 0 eintragen.</p>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="fa-table fa-table--compact min-w-[640px]">
                            <thead><tr><th>Grundprodukt</th><th class="text-right">Soll</th><th class="text-right">Gezählt</th><th class="text-right">Differenz</th><th class="text-right">EK</th><th class="text-right">Wert</th></tr></thead>
                            <tbody>
                                @foreach($inventur->lines as $l)
                                    @php
                                        $einheit = $svc->anzeigeEinheit($l->base_unit);
                                        $soll = $svc->anzeigeMenge((float) $l->qty_expected, $l->base_unit);
                                        $ist = $svc->anzeigeMenge($l->qty_counted !== null ? (float) $l->qty_counted : null, $l->base_unit);
                                        $diff = $ist !== null ? round($ist - $soll, 3) : null;
                                    @endphp
                                    <tr wire:key="l-{{ $l->id }}">
                                        <td class="font-medium">{{ $l->gp?->name ?? $l->supplierItem?->designation ?? '—' }}</td>
                                        <td class="text-right"><x-fa::menge :value="$soll" :unit="$einheit" /></td>
                                        <td class="text-right">
                                            @if($gebucht)
                                                <x-fa::menge :value="$ist" :unit="$einheit" />
                                            @else
                                                <span class="inline-flex items-center gap-1">
                                                    <input type="text" inputmode="decimal" value="{{ $ist !== null ? str_replace('.', ',', (string) $ist) : '' }}" wire:change="zaehlen({{ $l->id }}, $event.target.value)"
                                                           class="fa-control h-7 w-24 text-right tabular-nums text-[length:var(--fa-text-sm)]" aria-label="Gezählt in {{ $einheit }}" data-lager-zaehlen="{{ $l->id }}" />
                                                    <span class="{{ $leise }} w-6">{{ $einheit }}</span>
                                                </span>
                                            @endif
                                        </td>
                                        <td class="text-right tabular-nums {{ $diff !== null && $diff < 0 ? 'text-[var(--fa-crit)]' : '' }}">@if($diff !== null){{ $diff > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($diff, 3, ',', '.'), '0'), ',') ?: '0' }} {{ $einheit }}@else<span class="{{ $leise }}">–</span>@endif</td>
                                        <td class="text-right tabular-nums">@if($l->price_per_base !== null){{ number_format((float) $l->price_per_base * ($l->base_unit === 'Stk' ? 1 : 1000), 2, ',', '.') }} €<span class="{{ $leise }}">/{{ $einheit }}</span>@else<span class="{{ $leise }}">kein Preis</span>@endif</td>
                                        <td class="text-right">@if($l->qty_counted !== null)<x-fa::money :value="$l->wert()" missing="ohne Preis" />@else<span class="{{ $leise }}">–</span>@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @unless($gebucht)
                        <div class="flex flex-col gap-1.5 pt-2" data-lager-position-hinzu>
                            <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="positionSuche" placeholder="Grundprodukt ergänzen …" class="w-72" aria-label="Grundprodukt ergänzen" />
                            @foreach($kandidaten as $gp)
                                <button type="button" wire:key="k-{{ $gp->id }}" wire:click="positionHinzu({{ $gp->id }})" class="self-start inline-flex items-center gap-1 text-[length:var(--fa-text-md)] text-[var(--fa-accent)] hover:underline">@svg('heroicon-m-plus', 'w-3.5 h-3.5') {{ $gp->name }}</button>
                            @endforeach
                        </div>
                    @endunless
                </x-fa::section>
            @endif
        @endif
    </x-ui-page-container>
</x-ui-page>
