{{-- R2.2 — Was-wäre-wenn-Preissimulation. Read-only: hypothetisches Preisszenario
     (Warengruppe | Grundprodukt | Artikel, ± X %) → Portfolio-Marge-Delta + Top-20
     betroffene Gerichte. Spiegelt das MCP-Tool foodalchemist.simulation.POST. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

{{-- #502: KEIN overflow-hidden auf dem Panel — sonst clippt es das GP-Autocomplete-Dropdown
     am Seitenende. Die cardAccent-Haarlinie (1px, top) fällt an den Ecken nicht ins Gewicht. --}}
<div class="relative {{ $card }} px-5 py-4" wire:key="sim-panel">
    <div class="{{ $cardAccent }}"></div>

    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
            <h3 class="font-medium tracking-tight text-gray-900">
                Was-wäre-wenn — Preissimulation
            </h3>
            <p class="text-[11px] text-gray-500 max-w-2xl">
                Spiel einen hypothetischen Preissprung durch — <strong>Warengruppe</strong>, <strong>Grundprodukt</strong> oder einzelnen <strong>Artikel</strong> ± X % —
                und sieh sofort, wie sich die Marge übers ganze Portfolio verschiebt. <strong>Rein lesend</strong>: keine Echtdaten werden verändert.
            </p>
        </div>
        <span class="{{ $pill }} {{ $variantPill['info'] }}" title="Diese Simulation verändert keine Daten.">read-only</span>
    </div>

    {{-- ── Szenario-Formular ─────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 md:grid-cols-12 gap-3 mt-4 items-end">
        {{-- Ebene --}}
        <div class="md:col-span-3">
            <label class="{{ $label }} block mb-1">Ebene</label>
            <select wire:model.live="scope" class="{{ $input }}">
                <option value="warengruppe">Warengruppe</option>
                <option value="gp">Grundprodukt</option>
                <option value="artikel">Lieferantenartikel</option>
            </select>
        </div>

        {{-- Bezug --}}
        <div class="md:col-span-5">
            <label class="{{ $label }} block mb-1">Bezug</label>

            @if($scope === 'warengruppe')
                <select wire:model="ref" class="{{ $input }}">
                    <option value="">– Warengruppe wählen –</option>
                    @foreach($warengruppen as $wg)
                        <option value="{{ $wg->code }}">{{ $wg->code }} · {{ $wg->name }}</option>
                    @endforeach
                </select>

            @elseif($scope === 'gp')
                @if($ref !== '' && $refLabel !== '')
                    <div class="flex items-center gap-2">
                        <span class="{{ $pill }} {{ $variantPill['primary'] }}">{{ $refLabel }}</span>
                        <button type="button" wire:click="zuruecksetzen" class="{{ $btnGhostXs }}">ändern</button>
                    </div>
                @else
                    <div class="relative">
                        <input type="text" wire:model.live.debounce.300ms="gpQuery" placeholder="Grundprodukt suchen (min. 2 Zeichen)…" class="{{ $input }}" />
                        @if(count($gpTreffer))
                            <div class="absolute z-20 mt-1 w-full rounded-lg border border-black/10 bg-white shadow-lg max-h-64 overflow-auto">
                                @foreach($gpTreffer as $t)
                                    <button type="button" wire:click="waehleGp({{ $t['id'] }}, @js($t['name']))"
                                            class="flex w-full items-center justify-between gap-2 px-3 py-1.5 text-xs text-left hover:bg-violet-500/5">
                                        <span class="text-gray-900">{{ $t['name'] }}</span>
                                        @unless($t['hat_lead'])
                                            <span class="{{ $pill }} {{ $variantPill['secondary'] }}" title="ohne Lead-Lieferantenartikel — kein Preistreiber">kein Lead</span>
                                        @endunless
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

            @else
                <input type="number" wire:model="ref" placeholder="supplier_item_id" class="{{ $input }}" />
            @endif
        </div>

        {{-- Delta --}}
        <div class="md:col-span-2">
            <label class="{{ $label }} block mb-1">Änderung %</label>
            <div class="flex items-center gap-1">
                <input type="number" step="1" wire:model="deltaPct" class="{{ $input }} tabular-nums" />
            </div>
        </div>

        {{-- Auslösen --}}
        <div class="md:col-span-2">
            <button type="button" wire:click="simuliere" wire:loading.attr="disabled" class="{{ $btnPrimary }} w-full justify-center">
                <span wire:loading.remove wire:target="simuliere">Simulieren</span>
                <span wire:loading wire:target="simuliere">Rechne…</span>
            </button>
        </div>
    </div>

    @error('ref') <p class="text-[11px] text-red-500 mt-1">{{ $message }}</p> @enderror
    @error('deltaPct') <p class="text-[11px] text-red-500 mt-1">{{ $message }}</p> @enderror

    {{-- ── Ergebnis ──────────────────────────────────────────────────── --}}
    @if($result !== null)
        @php($teurer = (float) $deltaPct > 0)
        @php($md = (float) ($result['marge_delta_eur'] ?? 0))
        @php($mdCls = $md < 0 ? 'text-red-600' : ($md > 0 ? 'text-emerald-600' : 'text-gray-900'))

        <div class="mt-5">
            <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-5 gap-2">
                <div class="{{ $kpiTile }}"><div class="{{ $kpiTileAccent }}"></div><div class="{{ $label }}">Betroffene Gerichte</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ number_format((int) ($result['n_gerichte'] ?? 0), 0, ',', '.') }}</div></div>
                <div class="{{ $kpiTile }}"><div class="{{ $kpiTileAccent }}"></div><div class="{{ $label }}">Konzepte</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ number_format((int) ($result['n_concepts'] ?? 0), 0, ',', '.') }}</div></div>
                <div class="{{ $kpiTile }}"><div class="{{ $kpiTileAccent }}"></div><div class="{{ $label }}">Σ Marge-Delta</div><div class="text-lg font-semibold tabular-nums {{ $mdCls }}">{{ ($md > 0 ? '+' : '') . number_format($md, 2, ',', '.') }} €</div></div>
                <div class="{{ $kpiTile }}"><div class="{{ $kpiTileAccent }}"></div><div class="{{ $label }}">GPs im Szenario</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ number_format((int) ($result['n_gps'] ?? 0), 0, ',', '.') }}</div></div>
                <div class="{{ $kpiTile }}"><div class="{{ $kpiTileAccent }}"></div><div class="{{ $label }}">Preis-Faktor</div><div class="text-lg font-semibold tabular-nums text-gray-900">×{{ number_format((float) ($result['ratio'] ?? 1), 3, ',', '.') }}</div></div>
            </div>

            <p class="text-[11px] text-gray-500 mt-2">
                Szenario: <strong>{{ $result['scope'] }}</strong> „{{ $result['ref'] }}" {{ $teurer ? '+' : '' }}{{ number_format((float) $deltaPct, 1, ',', '.') }} % →
                {{ $teurer ? 'Marge sinkt' : 'Marge steigt' }} in den betroffenen Gerichten.
                @if($dauerMs !== null) <span class="text-gray-300">·</span> berechnet in {{ number_format($dauerMs, 0, ',', '.') }} ms @endif
            </p>

            @if(count($result['top'] ?? []))
                <div class="mt-3 overflow-x-auto {{ $sectionCard }}">
                    <div class="{{ $label }} mb-2">Top {{ count($result['top']) }} betroffene Gerichte (nach |Marge-Delta|)</div>
                    <table class="{{ $table }}">
                        <thead>
                            <tr>
                                <th class="{{ $th }} text-left">Gericht</th>
                                <th class="{{ $th }} text-right">Marge % (ist → hypo)</th>
                                <th class="{{ $th }} text-right">Δ Marge €</th>
                                <th class="{{ $th }} text-right">Wareneinsatz % (hypo)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($result['top'] as $r)
                                @php($rd = (float) ($r['marge_delta_eur'] ?? 0))
                                <tr class="{{ $tr }}">
                                    <td class="{{ $td }}">
                                        <a href="{{ route('foodalchemist.verkauf.index', ['rezept' => $r['recipe_id']]) }}" class="text-violet-600 hover:underline" wire:navigate>
                                            {{ $r['name'] }}
                                        </a>
                                    </td>
                                    <td class="{{ $td }} text-right tabular-nums">
                                        {{ number_format((float) $r['marge_pct_ist'], 1, ',', '.') }} → {{ number_format((float) $r['marge_pct_hypo'], 1, ',', '.') }} %
                                    </td>
                                    <td class="{{ $td }} text-right tabular-nums {{ $rd < 0 ? 'text-red-600' : ($rd > 0 ? 'text-emerald-600' : '') }}">
                                        {{ ($rd > 0 ? '+' : '') . number_format($rd, 2, ',', '.') }}
                                    </td>
                                    <td class="{{ $td }} text-right tabular-nums">{{ number_format((float) $r['wareneinsatz_pct_hypo'], 1, ',', '.') }} %</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-xs text-gray-500 mt-3 {{ $sectionCard }}">
                    Keine bepreisten Gerichte betroffen. (Nur Gerichte mit hinterlegtem Verkaufspreis fließen in das Marge-Delta ein.)
                </p>
            @endif

            @if(count($result['substitutions'] ?? []))
                <div class="mt-3 {{ $sectionCard }}">
                    <div class="{{ $label }} mb-2">Ersatzvorschläge (Äquivalenz-Katalog)</div>
                    <div class="flex flex-wrap gap-1">
                        @foreach($result['substitutions'] as $s)
                            <span class="{{ $pill }} {{ $variantPill['secondary'] }}">GP #{{ $s['gp_id'] }} → {{ $s['alt_name'] }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
