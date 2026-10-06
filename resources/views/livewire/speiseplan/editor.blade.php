{{-- Speiseplan-Editor (Fullscreen-Dark, pro Plan) — Kalender (Wochen-Matrix/Monat + Inline-Picker)
     · Menü-Linien · Stammdaten (+ Öffnungstage, Zyklus-Ausrollen). Rechts eine Live-Kennzahlen-Rail
     (VK/EK · Budget je Gast · Wareneinsatz je Linie · Kostformen · LMIV · DGE · Wiederholungen), die
     bei jeder Zellen-Änderung mitrechnet. Spec 57: Zellen „auf einen Blick“ (partials/zelle), Linie
     als Ausgabestelle, Tagesfuß — alle Zahlen aus SpeiseplanService::zellenKennzahlen. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($tagKurz = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'])
@php($monatNamen = [1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'])

<x-foodalchemist::modal name="speiseplan-editor" fullscreen dark-canvas title="Speiseplan bearbeiten"
    :title-name="$sp?->name">

    <x-slot:actions>
        @if($sp)
            <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-sp-speichern>Speichern</button>
            {{-- P5 / Spec 57 · 10.1: Voll-Kaskade — erst prüfen (leere Zellen, Kosten-Hinweis), dann im Feld unten starten. --}}
            <button type="button" wire:click="vollKaskadePruefen" class="{{ $btnPrimary }}" wire:loading.attr="disabled" data-sp-voll-kaskade>
                <span wire:loading.remove wire:target="vollKaskadePruefen">@svg('heroicon-o-bolt', 'w-4 h-4 inline-block align-middle') Voll-Kaskade …</span>
                <span wire:loading wire:target="vollKaskadePruefen">Prüfe …</span>
            </button>
            <button type="button" wire:click="loeschen({{ $sp->id }})" wire:confirm="Speiseplan löschen?" class="{{ $btnGhostXs }} text-red-600" data-sp-loeschen>Löschen</button>
            @if($ausrollenInfo)<span class="text-[12px] text-violet-300 ml-2 self-center" data-sp-ausrollen-info>{{ $ausrollenInfo }}</span>@endif
            @if($kaskadeMeldung)<span class="text-[12px] text-amber-300 ml-2 self-center">{{ $kaskadeMeldung }}</span>@endif
            @if($prodHinweis)<span class="text-[12px] text-emerald-300 ml-2 self-center" data-sp-prod-hinweis>✓ {{ $prodHinweis }}</span>@endif
            @if($prodFehler)<span class="text-[12px] text-rose-300 ml-2 self-center" data-sp-prod-fehler>{{ $prodFehler }}</span>@endif
        @endif
    </x-slot:actions>

    @if($sp && $kosten)
        @php($kfOk = collect($kostformen)->where('erfuellt', true)->count())
        @php($kfN = count($kostformen))
        @php($zkWoche = $zk['woche'] ?? null)
        <x-slot:kpiHeader>
            <x-foodalchemist::kpi-tiles marker="sp-kpis" :tiles="[
                ['kpi' => 'umsatz', 'label' => 'Umsatz · Woche (Prognose)', 'tone' => 'accent',
                 'value' => $zkWoche ? number_format($zkWoche['umsatz'], 0, ',', '.') . ' €' : '—'],
                ['kpi' => 'wes', 'label' => 'Ø Wareneinsatz · Woche',
                 'tone' => ($zkWoche['status'] ?? 'unbekannt') === 'ok' ? 'good' : (in_array($zkWoche['status'] ?? '', ['ueber', 'weit_ueber'], true) ? 'warn' : 'neutral'),
                 'value' => ($zkWoche['wes'] ?? null) !== null ? number_format($zkWoche['wes'], 1, ',', '.') . ' %' : '—'],
                ['kpi' => 'essen', 'label' => ($zk['gaeste_aus_rollen'] ?? false) ? 'Gäste · Woche (Hauptgänge)' : 'Portionen · Woche',
                 'value' => $zkWoche ? number_format(($zk['gaeste_aus_rollen'] ?? false) ? $zkWoche['gaeste'] : $zkWoche['portionen'], 0, ',', '.') : '—'],
                ['kpi' => 'kostform', 'label' => 'Kostformen · Woche',
                 'tone' => $kfN > 0 && $kfOk === $kfN ? 'good' : ($kfOk > 0 ? 'warn' : 'neutral'),
                 'value' => $kfN > 0 ? $kfOk . '/' . $kfN . ' abgedeckt' : '—'],
                ['kpi' => 'wdh', 'label' => 'Wdh.-Konflikte',
                 'tone' => count($wiederholungen) > 0 ? 'warn' : 'good',
                 'value' => (string) count($wiederholungen)],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($sp === null)
        <p class="pt-4 text-[12px] text-gray-500">Kein Plan geladen.</p>
    @else
        {{-- Spec 57 · 10.1: Bestätigung vor der Voll-Kaskade (KI-Läufe kosten Zeit und Tokens). --}}
        @if($kaskadeVorschau)
            <div class="mt-4 rounded-xl border border-violet-400/30 bg-violet-500/10 p-3 text-[12px] text-gray-200" data-sp-kaskade-bestaetigung>
                <div class="font-medium text-violet-100">Voll-Kaskade starten?</div>
                <p class="text-gray-300 mt-1">
                    {{ $kaskadeVorschau['leer'] }} leere Zelle(n) im {{ $kaskadeVorschau['wochen'] }}-Wochen-Zyklus
                    ({{ $kaskadeVorschau['linien'] }} Linien × Öffnungstage, jede Linie in ihrer Mahlzeit).
                    Dieser Lauf startet {{ $kaskadeVorschau['dieser_lauf'] }} KI-Läufe — jeder erzeugt ein Gericht als Entwurf, das in der Leitstelle freigegeben wird.
                    @if($kaskadeVorschau['gedeckelt']) Der Rest folgt mit dem nächsten Lauf (höchstens 6 Wochen je Lauf). @endif
                </p>
                <div class="flex gap-2 mt-2">
                    <button type="button" wire:click="vollKaskadeStarten" wire:loading.attr="disabled" class="{{ $btnPrimary }}" data-sp-kaskade-start>
                        <span wire:loading.remove wire:target="vollKaskadeStarten">Starten</span>
                        <span wire:loading wire:target="vollKaskadeStarten">Starte …</span>
                    </button>
                    <button type="button" wire:click="vollKaskadeAbbrechen" class="{{ $btnGhost }}">Abbrechen</button>
                </div>
            </div>
        @endif
        {{-- 2-Spalten: Editor-Tabs (links, breit) + Live-Kennzahlen-Rail (rechts).
             -mx-6 hebt das Body-px-6 auf (Spalten randbündig); die Mitte bekommt px-6 zurück,
             damit die sticky editor-tabs-Leiste (-mx-6) wieder auf Spaltenbreite spannt; die
             Rail behält pr-6. --}}
        {{-- Spec 59: `markiert` = Eintrag-Ids, die ein Chip der Abwechslungs-Karte hervorhebt (rein clientseitig;
             die Zellen in der Matrix lesen es per :class, siehe partials/zelle). --}}
        <div class="flex gap-4 -mx-6 items-start" x-data="{ markiert: null, markierKey: null }">
            <div class="flex-1 min-w-0 px-6">
                <x-foodalchemist::editor-tabs marker="sp" wire-key="sp-tabs-{{ $sp->id }}" :init="'kalender'"
                    :tabs="[
                        'kalender' => 'Kalender',
                        'mengen' => 'Mengen',
                        'bedarf' => 'Bedarf',
                        'planist' => 'Plan/Ist',
                        'linien' => 'Menü-Linien',
                        'stammdaten' => 'Stammdaten',
                        'praesentation' => 'Ausgabe & Aushang',
                    ]">

                    {{-- ═══ Tab: KALENDER ═══ --}}
                    <div x-show="tab === 'kalender'" x-cloak class="pt-4 space-y-4" data-sp-tab-kalender>
                        {{-- Toolbar: Ansicht + Mahlzeit + Navigation --}}
                        <div class="flex items-center gap-3 flex-wrap">
                            <span class="inline-flex rounded-lg overflow-hidden border border-white/15">
                                @foreach(['woche' => 'Woche', 'monat' => 'Monat'] as $av => $al)
                                    <button type="button" wire:click="ansichtSetzen('{{ $av }}')" class="px-3 py-1.5 text-xs {{ $ansicht === $av ? 'bg-violet-500/20 text-violet-200 font-medium' : 'text-gray-400 hover:bg-white/[0.04]' }}">{{ $al }}</button>
                                @endforeach
                            </span>
                            <span class="flex items-center gap-1 flex-wrap">
                                @foreach($mahlzeiten as $mk => $ml)
                                    <button type="button" wire:click="mahlzeitSetzen('{{ $mk }}')" class="{{ $pill }} {{ $mahlzeit === $mk ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $ml }}</button>
                                @endforeach
                            </span>
                            @if($ansicht === 'woche')
                                {{-- Spec 57 · Paket 1: Zell-Dichte --}}
                                <span class="inline-flex rounded-lg overflow-hidden border border-white/15" data-sp-dichte>
                                    @foreach(['kompakt' => 'Kompakt', 'detail' => 'Detail'] as $dv => $dl)
                                        <button type="button" wire:click="dichteSetzen('{{ $dv }}')" class="px-2.5 py-1.5 text-xs {{ $dichte === $dv ? 'bg-white/10 text-gray-100 font-medium' : 'text-gray-400 hover:bg-white/[0.04]' }}" aria-pressed="{{ $dichte === $dv ? 'true' : 'false' }}">{{ $dl }}</button>
                                    @endforeach
                                </span>
                            @endif
                            <a href="{{ route('foodalchemist.speiseplan.dokument', ['id' => $sp->id, 'mahlzeit' => $mahlzeit, 'montag' => $montagDt->format('Y-m-d')]) }}" target="_blank"
                               class="{{ $btnGhostXs }}" title="Wochen-Aushang (Druck/PDF) mit Allergen- & Zusatzstoff-Legende" data-sp-aushang>@svg('heroicon-o-printer', 'w-3.5 h-3.5 inline-block align-middle') Aushang</a>
                            @if($ansicht === 'woche')
                                <button type="button" wire:click="wocheKopierenOeffnen" class="{{ $btnGhostXs }}" title="Diese Woche auf eine andere Woche kopieren" data-sp-woche-kopieren-btn>@svg('heroicon-o-document-duplicate', 'w-3.5 h-3.5 inline-block align-middle') Woche kopieren</button>
                            @endif
                            <button type="button" wire:click="anProduktion"
                                    wire:confirm="Diese Woche ({{ $mahlzeiten[$mahlzeit] ?? '' }}) an die Produktion übergeben? Je Werktag mit Belegung wird ein Produktionsauftrag angelegt (Menge = Teilnehmerzahl)."
                                    class="{{ $btnGhostXs }}" title="Woche × Teilnehmerzahl → Produktionsaufträge (je Werktag einer)" data-sp-produktion>@svg('heroicon-o-fire', 'w-3.5 h-3.5 inline-block align-middle') → Produktion</button>
                            <span class="flex items-center gap-2 ml-auto">
                                @if($ansicht === 'woche')
                                    <button type="button" wire:click="wocheVerschieben(-1)" class="{{ $btnGhostXs }}">◀</button>
                                    @php($letzterTag = $wochenTage !== [] ? end($wochenTage) : $montagDt->copy()->addDays(4))
                                    <span class="text-sm font-medium tabular-nums text-gray-200">KW {{ (int) $montagDt->format('W') }} · {{ ($wochenTage[0] ?? $montagDt)->format('d.m.') }}–{{ $letzterTag->format('d.m.Y') }}</span>
                                    <button type="button" wire:click="wocheVerschieben(1)" class="{{ $btnGhostXs }}">▶</button>
                                    <button type="button" wire:click="heute" class="{{ $btnGhostXs }}">Heute</button>
                                @else
                                    <button type="button" wire:click="monatVerschieben(-1)" class="{{ $btnGhostXs }}">◀</button>
                                    <span class="text-sm font-medium text-gray-200">{{ $monatNamen[(int) $monatStart->month] }} {{ $monatStart->year }}</span>
                                    <button type="button" wire:click="monatVerschieben(1)" class="{{ $btnGhostXs }}">▶</button>
                                @endif
                            </span>
                        </div>

                        @if($ansicht === 'woche')
                            {{-- Wochen-Matrix: Linien × Öffnungstage (Spec 57 · Paket 1/2/9) --}}
                            @php($zkEintraege = $zk['eintraege'] ?? [])
                            @php($zkTage = $zk['tage'] ?? [])
                            @php($zkLinien = $zk['linien'] ?? [])
                            @php($tagStatusText = ['ok' => 'text-emerald-300', 'unter' => 'text-sky-300', 'ueber' => 'text-amber-300', 'weit_ueber' => 'text-rose-300', 'unbekannt' => 'text-gray-400'])
                            <x-foodalchemist::modal-section title="Wochen-Matrix">
                                @if($umbauHinweis)<div class="mb-2 rounded-lg bg-violet-500/10 border border-violet-400/30 text-violet-100 text-xs px-3 py-1.5" data-sp-umbau-hinweis>{{ $umbauHinweis }}</div>@endif
                                <div class="overflow-x-auto" data-sp-matrix x-data="{ dragId: null }">
                                    <table class="{{ $table }}" style="table-layout:fixed; width:100%; min-width:{{ max(640, 150 + count($wochenTage) * 150) }}px;">
                                        <thead><tr class="text-left">
                                            <th class="{{ $th }}" style="width:150px">Linie</th>
                                            @foreach($wochenTage as $tag)
                                                <th class="{{ $th }} text-center {{ $tag->isToday() ? 'text-violet-300' : '' }}">{{ $tagKurz[$tag->isoWeekday()] }} <span class="text-gray-400 font-normal">{{ $tag->format('d.m.') }}</span></th>
                                            @endforeach
                                        </tr></thead>
                                        <tbody>
                                            @php($zeilenLinien = $matrixLinien->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name, 'color' => $l->color, 'role' => $l->role, 'plu' => $l->plu, 'is_standing' => (bool) $l->is_standing])->values())
                                            @if(isset($raster[0]))
                                                @php($zeilenLinien->push(['id' => 0, 'name' => 'Ohne Linie', 'color' => null, 'role' => null, 'plu' => null, 'is_standing' => false]))
                                            @endif
                                            @foreach($zeilenLinien as $zl)
                                                @php($lk = $zkLinien[$zl['id']] ?? null)
                                                <tr class="border-t border-white/10 align-top" wire:key="zeile-{{ $zl['id'] }}">
                                                    <td class="{{ $td }}" data-sp-linie-kopf="{{ $zl['id'] }}">
                                                        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full shrink-0" style="background: {{ $zl['color'] ?: '#94a3b8' }}"></span><span class="font-medium {{ $zl['id'] === 0 ? 'text-amber-200' : 'text-gray-200' }}">{{ $zl['name'] }}</span></span>
                                                        @if($zl['id'] === 0)
                                                            <div class="text-[10px] text-amber-200/70 mt-0.5">Keiner Linie zugeordnet</div>
                                                        @else
                                                            <div class="text-[10px] text-gray-400 mt-0.5">{{ collect([$rollen[$zl['role']] ?? null, $zl['plu'] ? 'Kasse ' . $zl['plu'] : null])->filter()->implode(' · ') ?: 'ohne Rolle' }}</div>
                                                            @if($lk)
                                                                <div class="text-[10px] text-gray-400 tabular-nums" title="{{ ($lk['band']['quelle'] ?? '') === 'team' ? 'Kein eigenes Zielband — es gilt das Team-/Betriebs-Ziel' : 'Zielband der Linie' }}">
                                                                    Ziel {{ $lk['band']['min'] !== null ? number_format((float) $lk['band']['min'], 0) . '–' : '≤ ' }}{{ number_format((float) $lk['band']['max'], 0) }} %
                                                                </div>
                                                            @endif
                                                            @if($zl['is_standing'])<div class="text-[10px] text-lime-300/80">Dauerangebot</div>@endif
                                                        @endif
                                                    </td>
                                                    @foreach($wochenTage as $tag)
                                                        @php($ymd = $tag->format('Y-m-d'))
                                                        @php($eintraege = $raster[$zl['id']][$ymd] ?? [])
                                                        <td class="{{ $td }} align-top {{ ($cellDatum === $ymd && $cellLinie === ($zl['id'] ?: null)) ? 'bg-violet-500/10 rounded-lg' : '' }}"
                                                            x-on:dragover.prevent
                                                            x-on:drop="if (dragId) { $wire.eintragVerschieben(dragId, '{{ $ymd }}', {{ $zl['id'] }}); dragId = null }"
                                                            data-sp-drop="{{ $zl['id'] }}|{{ $ymd }}">
                                                            <div class="space-y-1">
                                                                @foreach($eintraege as $e)
                                                                    @include('foodalchemist::livewire.speiseplan.partials.zelle', ['e' => $e, 'k' => $zkEintraege[$e->id] ?? null, 'farbe' => $zl['color'], 'dichte' => $dichte, 'sp' => $sp, 'detailId' => $detailEintragId])
                                                                @endforeach
                                                                @if($zl['id'] !== 0)
                                                                    <button type="button" wire:click="zelleOeffnen('{{ $ymd }}', {{ $zl['id'] }})"
                                                                            aria-label="{{ $zl['name'] }} am {{ $tagKurz[$tag->isoWeekday()] }} {{ $tag->format('d.m.') }} belegen"
                                                                            class="w-full text-[11px] text-gray-400 hover:text-violet-300 rounded-lg border border-dashed border-white/15 hover:border-violet-400/40 py-0.5">+</button>
                                                                @endif
                                                            </div>
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                            @if($zeilenLinien->isEmpty())
                                                <tr><td colspan="{{ count($wochenTage) + 1 }}" class="{{ $td }} text-center text-gray-400 text-xs py-4">Für diese Mahlzeit gibt es keine Linie. Im Tab „Menü-Linien“ eine anlegen (oder eine Linie „alle Mahlzeiten“ lassen), dann Gerichte in die Tage setzen.</td></tr>
                                            @else
                                                {{-- Spec 57 · Paket 1: Tagesfuß --}}
                                                <tr class="border-t border-white/15 bg-white/[0.03]" data-sp-tagesfuss>
                                                    <td class="{{ $td }}"><span class="{{ $label }}">Tag gesamt</span></td>
                                                    @foreach($wochenTage as $tag)
                                                        @php($tf = $zkTage[$tag->format('Y-m-d')] ?? null)
                                                        <td class="{{ $td }} text-[10.5px] tabular-nums text-gray-300">
                                                            @if($tf && $tf['portionen'] > 0)
                                                                <div class="flex justify-between"><span class="text-gray-500">{{ ($zk['gaeste_aus_rollen'] ?? false) ? 'Gäste' : 'Portionen' }}</span><span>{{ number_format(($zk['gaeste_aus_rollen'] ?? false) ? $tf['gaeste'] : $tf['portionen'], 0, ',', '.') }}</span></div>
                                                                <div class="flex justify-between"><span class="text-gray-500">Umsatz</span><span>{{ number_format($tf['umsatz'], 0, ',', '.') }} €</span></div>
                                                                <div class="flex justify-between"><span class="text-gray-500">WES</span><span class="{{ $tagStatusText[$tf['status']] ?? 'text-gray-400' }}">{{ $tf['wes'] !== null ? number_format($tf['wes'], 0, ',', '.') . ' %' : '—' }}</span></div>
                                                                @if($tf['ek_je_gast'] !== null)
                                                                    <div class="flex justify-between"><span class="text-gray-500">EK/Gast</span><span>{{ number_format($tf['ek_je_gast'], 2, ',', '.') }} €</span></div>
                                                                @endif
                                                            @else
                                                                <span class="text-gray-500">–</span>
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @endif
                                        </tbody>
                                    </table>
                                </div>
                                <div class="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-[10.5px] text-gray-400" data-sp-legende>
                                    <span class="inline-flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>Wareneinsatz im Zielband</span>
                                    <span class="inline-flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>darüber</span>
                                    <span class="inline-flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span>weit darüber</span>
                                    <span class="inline-flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-sky-400"></span>darunter</span>
                                    <span>Vg vegan · Vt vegetarisch · Sw Schwein · Rd Rind · Fi Fisch · Fl Fleisch · Buchstaben/Zahlen = LMIV · * am Preis = Linienpreis</span>
                                </div>

                                {{-- Spec 57 · Paket 5: Woche kopieren --}}
                                @if($wocheKopierenOffen)
                                    <div class="mt-3 rounded-xl border border-white/15 bg-white/5 p-3 text-xs text-gray-200 space-y-2" data-sp-woche-kopieren>
                                        <div class="font-medium">KW {{ (int) $montagDt->format('W') }} kopieren (alle Mahlzeiten)</div>
                                        <div class="flex flex-wrap items-end gap-3">
                                            <label class="flex flex-col gap-1"><span class="{{ $label }}">Zielwoche</span>
                                                <select wire:model="wocheKopierenZiel" class="{{ $input }} h-8">
                                                    @foreach($zielWochen as $zw)
                                                        <option value="{{ $zw->format('Y-m-d') }}">KW {{ $zw->isoWeek() }} · {{ $zw->format('d.m.') }}–{{ $zw->copy()->addDays(6)->format('d.m.Y') }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="wocheKopierenMerge" /> mit vorhandenen Einträgen zusammenführen</label>
                                            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="wocheKopierenPax" /> Mengen (Pax) mitnehmen</label>
                                            <button type="button" wire:click="wocheKopieren" class="{{ $btnPrimary }} h-8">Kopieren</button>
                                            <button type="button" wire:click="$set('wocheKopierenOffen', false)" class="{{ $btnGhost }} h-8">Abbrechen</button>
                                        </div>
                                        <p class="text-[11px] text-gray-400">Ohne Zusammenführen werden belegte Zellen der Zielwoche ersetzt. Einzelne Einträge kopierst du über ihr Detail.</p>
                                    </div>
                                @endif

                                {{-- Spec 57 · Paket 5: Eintrag-Detail — Tastatur-Weg zu Ersetzen, Verschieben, Kopieren (MVP-032) --}}
                                @if($detailEintrag)
                                    @php($dk = $detailKennzahlen ?? [])
                                    <div class="mt-3 pt-3 border-t border-white/10 space-y-3" data-sp-eintrag-detail="{{ $detailEintrag->id }}">
                                        <div class="flex items-start justify-between gap-2">
                                            <div>
                                                <div class="{{ $label }}">{{ $linien->firstWhere('id', $detailEintrag->line_id)?->name ?? 'Ohne Linie' }} · {{ $tagKurz[$detailEintrag->entry_date->isoWeekday()] ?? '' }} {{ $detailEintrag->entry_date->format('d.m.') }} · {{ $mahlzeiten[$detailEintrag->meal] ?? $detailEintrag->meal }}</div>
                                                <div class="text-sm font-medium text-gray-100">{{ $dk['titel'] ?? $detailEintrag->inhaltName() }}</div>
                                                @if(! empty($dk['untertitel']))<div class="text-[11px] text-gray-400">{{ $dk['untertitel'] }}</div>@endif
                                            </div>
                                            <button type="button" wire:click="eintragSchliessen" class="{{ $btnGhostXs }}" aria-label="Detail schließen">schließen</button>
                                        </div>
                                        @if($dk !== [])
                                            <div class="grid grid-cols-3 gap-2 text-center text-xs tabular-nums">
                                                <div class="rounded-lg bg-white/5 p-2"><div class="{{ $label }}">VK netto</div><div class="text-gray-100">{{ number_format((float) $dk['vk'], 2, ',', '.') }} €</div></div>
                                                <div class="rounded-lg bg-white/5 p-2"><div class="{{ $label }}">EK</div><div class="text-gray-100">{{ number_format((float) $dk['ek'], 2, ',', '.') }} €</div></div>
                                                <div class="rounded-lg bg-white/5 p-2"><div class="{{ $label }}">Wareneinsatz</div><div class="text-gray-100">{{ $dk['wes'] !== null ? number_format((float) $dk['wes'], 1, ',', '.') . ' %' : '—' }}</div></div>
                                            </div>
                                        @endif
                                        <div class="flex flex-wrap items-end gap-3 text-xs">
                                            <button type="button" wire:click="eintragErsetzenStarten({{ $detailEintrag->id }})" class="{{ $btnGhost }} h-8">Ersetzen …</button>
                                            <div class="flex items-end gap-2" data-sp-verschieben>
                                                <label class="flex flex-col gap-1"><span class="{{ $label }}">Verschieben nach</span>
                                                    <select wire:model="verschiebeDatum" class="{{ $input }} h-8">
                                                        @foreach($wochenTage as $wt)
                                                            <option value="{{ $wt->format('Y-m-d') }}">{{ $tagKurz[$wt->isoWeekday()] }} {{ $wt->format('d.m.') }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <label class="flex flex-col gap-1"><span class="{{ $label }}">Linie</span>
                                                    <select wire:model="verschiebeLinie" class="{{ $input }} h-8">
                                                        <option value="">Ohne Linie</option>
                                                        @foreach($matrixLinien as $ml)
                                                            <option value="{{ $ml->id }}">{{ $ml->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <button type="button" wire:click="eintragVerschiebenAusDetail" class="{{ $btnGhost }} h-8">Verschieben</button>
                                            </div>
                                            <div class="flex items-end gap-2" data-sp-kopieren>
                                                <fieldset class="flex flex-col gap-1"><legend class="{{ $label }}">Auf Tage kopieren</legend>
                                                    <span class="flex flex-wrap gap-1">
                                                        @foreach($wochenTage as $wt)
                                                            @php($wtYmd = $wt->format('Y-m-d'))
                                                            <label class="flex items-center gap-1 px-1.5 py-1 rounded border border-white/10 {{ $wtYmd === $detailEintrag->entry_date->format('Y-m-d') ? 'opacity-40' : '' }}">
                                                                <input type="checkbox" wire:model="kopierTage" value="{{ $wtYmd }}" @disabled($wtYmd === $detailEintrag->entry_date->format('Y-m-d')) /> {{ $tagKurz[$wt->isoWeekday()] }}
                                                            </label>
                                                        @endforeach
                                                    </span>
                                                </fieldset>
                                                <button type="button" wire:click="eintragKopieren" class="{{ $btnGhost }} h-8">Kopieren</button>
                                            </div>
                                            <button type="button" wire:click="eintragRaus({{ $detailEintrag->id }})" wire:confirm="Eintrag entfernen?" class="{{ $btnGhostXs }} text-red-400 h-8">Entfernen</button>
                                        </div>
                                    </div>
                                @endif

                                {{-- Inhalts-Picker für die aktive Zelle (inline, Livewire-sicher) --}}
                                @if($cellDatum !== null)
                                    <div class="mt-3 pt-3 border-t border-white/10 space-y-2" data-sp-picker>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="{{ $label }}">{{ $pickerErsetzenId ? 'Ersetzen' : 'Einfügen' }} · {{ \Illuminate\Support\Carbon::parse($cellDatum)->format('d.m.') }} · {{ $linien->firstWhere('id', $cellLinie)?->name ?? '—' }}:</span>
                                            @foreach(['gericht' => 'Gericht', 'concept' => 'Concept', 'paket' => 'Paket'] as $tv => $tl)
                                                <button type="button" wire:click="$set('pickerTyp', '{{ $tv }}')" class="{{ $pill }} {{ $pickerTyp === $tv ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $tl }}</button>
                                            @endforeach
                                            <input type="search" wire:model.live.debounce.300ms="pickerSuche" placeholder="{{ ['gericht' => 'Gericht', 'concept' => 'Concept', 'paket' => 'Paket'][$pickerTyp] }} suchen …" class="{{ $input }} w-56" />
                                            <button type="button" wire:click="cellSchliessen" class="{{ $btnGhostXs }}">schließen</button>
                                        </div>

                                        {{-- Spec 42: Facetten (Hauptgruppe → Unterklasse) nur für Gerichte — wie Speisekarte/Verkauf-Browser --}}
                                        @if($pickerTyp === 'gericht' && $pickerHauptgruppen->isNotEmpty())
                                            <div class="flex items-center gap-1 flex-wrap" data-sp-picker-facetten>
                                                <button type="button" wire:click="pickerWaehleHg(null)" class="{{ $pill }} {{ $pickerHauptgruppe === null ? $variantPill['primary'] : $variantPill['secondary'] }}">Alle</button>
                                                @foreach($pickerHauptgruppen as $hg)
                                                    <button type="button" wire:click="pickerWaehleHg({{ $hg->id }})" class="{{ $pill }} {{ (int) $pickerHauptgruppe === (int) $hg->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $hg->label }}</button>
                                                @endforeach
                                            </div>
                                            @if($pickerUntergruppen->isNotEmpty())
                                                <div class="flex items-center gap-1 flex-wrap pl-3" data-sp-picker-unterklassen>
                                                    @foreach($pickerUntergruppen as $uk)
                                                        <button type="button" wire:click="pickerWaehleKlasse({{ $uk->id }})" class="{{ $pill }} {{ (int) $pickerDishClass === (int) $uk->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $uk->label }}</button>
                                                    @endforeach
                                                </div>
                                            @endif
                                        @endif

                                        @if($kandidaten->isNotEmpty())
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-1 max-h-56 overflow-y-auto">
                                                @foreach($kandidaten as $k)
                                                    <button type="button" wire:key="kand-{{ $pickerTyp }}-{{ $k->id }}" wire:click="inhaltHinzu('{{ $pickerTyp }}', {{ $k->id }})"
                                                            class="flex items-center justify-between gap-2 px-2 py-1 rounded-lg text-xs hover:bg-violet-500/15 text-left text-gray-200">
                                                        <span class="truncate">{{ $k->name }}</span>
                                                        <span class="flex items-center gap-1 shrink-0">
                                                            @if($pickerTyp === 'gericht' && ($k->dishClass?->diet_form))
                                                                <span class="text-[10px] px-1 rounded bg-white/10 text-gray-300">{{ $k->dishClass->diet_form }}</span>
                                                            @endif
                                                            @if($pickerTyp === 'gericht' && $k->sales_net)
                                                                <span class="text-[10px] text-gray-400">{{ number_format((float) $k->sales_net, 2, ',', '.') }} €</span>
                                                            @elseif($pickerTyp === 'concept' && ($k->price_per_person_cache ?? null))
                                                                <span class="text-[10px] text-gray-400">{{ number_format((float) $k->price_per_person_cache, 2, ',', '.') }} €/P</span>
                                                            @endif
                                                            <span class="text-violet-300">+</span>
                                                        </span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @else
                                            <p class="text-[11px] text-gray-400">Keine Treffer.</p>
                                        @endif
                                    </div>
                                @endif
                            </x-foodalchemist::modal-section>
                        @else
                            {{-- Monats-Kalender --}}
                            @php($gridStart = $monatStart->copy()->startOfWeek(\Illuminate\Support\Carbon::MONDAY))
                            <x-foodalchemist::modal-section title="Monat">
                                <div style="display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:4px;">
                                    @foreach([1,2,3,4,5,6,7] as $wd)
                                        <div class="text-center {{ $label }} pb-1">{{ $tagKurz[$wd] }}</div>
                                    @endforeach
                                    @for($i = 0; $i < 42; $i++)
                                        @php($tag = $gridStart->copy()->addDays($i))
                                        @php($ymd = $tag->format('Y-m-d'))
                                        @php($imMonat = (int) $tag->month === (int) $monatStart->month)
                                        @php($info = $monatsRaster[$ymd] ?? null)
                                        <button type="button" wire:key="cal-{{ $ymd }}" wire:click="tagOeffnen('{{ $ymd }}')"
                                                class="text-left rounded-lg border p-1.5 h-20 transition-colors {{ $imMonat ? 'border-white/10 hover:bg-violet-500/10' : 'border-transparent opacity-40' }} {{ $tag->isToday() ? 'ring-1 ring-violet-400' : '' }}">
                                            <div class="flex items-center justify-between">
                                                <span class="text-[11px] {{ $tag->isToday() ? 'text-violet-300 font-semibold' : 'text-gray-400' }}">{{ $tag->format('j') }}</span>
                                                @if($info)<span class="{{ $pill }} {{ $variantPill['secondary'] }} text-[9px]">{{ $info['count'] }}</span>@endif
                                            </div>
                                            @if($info && $info['vk'] > 0)
                                                <div class="mt-1 text-[10px] text-gray-400 tabular-nums">{{ number_format($info['vk'], 2, ',', '.') }} €</div>
                                            @endif
                                        </button>
                                    @endfor
                                </div>
                                <p class="mt-3 text-[11px] text-gray-400">Tag anklicken → springt in die Wochenansicht. Belegung der Mahlzeit „{{ $mahlzeiten[$mahlzeit] ?? '' }}".</p>
                            </x-foodalchemist::modal-section>
                        @endif
                    </div>

                    {{-- ═══ Spec 57 · Paket 3: Tab MENGEN (Essen je Linie × Tag) ═══ --}}
                    <div x-show="tab === 'mengen'" x-cloak class="pt-4 space-y-3" data-sp-tab-mengen>
                        <x-foodalchemist::modal-section title="Mengen · KW {{ (int) $montagDt->format('W') }} · {{ $mahlzeiten[$mahlzeit] ?? '' }}">
                            <x-slot:actions>
                                <span class="text-[11px] text-gray-400">Woche und Mahlzeit wie im Kalender</span>
                            </x-slot:actions>
                            <div class="flex flex-wrap items-end gap-3 mb-3 text-xs">
                                <button type="button" wire:click="mengenVorwoche" class="{{ $btnGhost }} h-8" data-sp-mengen-vorwoche>↺ Mengen aus Vorwoche übernehmen</button>
                                <label class="flex items-center gap-2 text-gray-300">Skalieren um Faktor
                                    <input type="text" inputmode="decimal" wire:model="mengenFaktor" class="{{ $input }} h-8 w-20 text-right tabular-nums" aria-label="Skalierungsfaktor" />
                                </label>
                                <button type="button" wire:click="mengenSkalieren" class="{{ $btnGhost }} h-8">Anwenden</button>
                                @if($mengenHinweis)<span class="text-violet-300" data-sp-mengen-hinweis>{{ $mengenHinweis }}</span>@endif
                            </div>
                            @if($mengen && $mengen['zeilen'] !== [])
                                <div class="overflow-x-auto">
                                    <table class="{{ $table }}" style="min-width:{{ 520 + count($mengen['tage']) * 70 }}px" data-sp-mengen-matrix>
                                        <thead><tr class="text-left">
                                            <th class="{{ $th }}">Linie</th>
                                            <th class="{{ $th }} text-right" title="Summe der Vorwoche (Planwerte)">Vorwoche</th>
                                            <th class="{{ $th }} text-right" title="Ø der letzten vier Wochen (Planwerte)">Ø 4 Wo.</th>
                                            @foreach($mengen['tage'] as $mt)
                                                <th class="{{ $th }} text-center">{{ $tagKurz[\Illuminate\Support\Carbon::parse($mt)->isoWeekday()] }}</th>
                                            @endforeach
                                            <th class="{{ $th }} text-right">Σ</th><th class="{{ $th }} text-right">Anteil</th><th class="{{ $th }} text-right">WES</th><th class="{{ $th }} text-right">Ø VK</th><th class="{{ $th }} text-right">Umsatz</th>
                                        </tr></thead>
                                        <tbody>
                                            @foreach($mengen['zeilen'] as $mz)
                                                <tr class="border-t border-white/10" wire:key="mz-{{ $mz['line_id'] }}">
                                                    <td class="{{ $td }}"><span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full" style="background: {{ $mz['color'] ?: '#94a3b8' }}"></span><span class="text-xs text-gray-200">{{ $mz['name'] }}</span></span></td>
                                                    <td class="{{ $td }} text-right text-xs tabular-nums text-gray-400">{{ $mz['vorwoche'] ?: '—' }}</td>
                                                    <td class="{{ $td }} text-right text-xs tabular-nums text-gray-400">{{ $mz['schnitt4'] > 0 ? number_format($mz['schnitt4'], 0, ',', '.') : '—' }}</td>
                                                    @foreach($mengen['tage'] as $mt)
                                                        @php($mp = $mz['zellen'][$mt] ?? null)
                                                        <td class="{{ $td }} text-center">
                                                            @if($mp !== null)
                                                                <input type="number" min="0" value="{{ $mp }}"
                                                                       wire:change="mengenSetzen({{ $mz['line_id'] }}, '{{ $mt }}', $event.target.value)"
                                                                       aria-label="Essen {{ $mz['name'] }} am {{ \Illuminate\Support\Carbon::parse($mt)->format('d.m.') }}"
                                                                       class="{{ $input }} h-7 w-16 text-right tabular-nums text-xs" />
                                                            @else
                                                                <span class="text-[11px] text-gray-500" title="Zelle nicht belegt — erst im Kalender ein Gericht setzen">–</span>
                                                            @endif
                                                        </td>
                                                    @endforeach
                                                    <td class="{{ $td }} text-right text-xs tabular-nums font-medium text-gray-100">{{ number_format($mz['summe'], 0, ',', '.') }}</td>
                                                    <td class="{{ $td }} text-right text-xs tabular-nums text-gray-400">{{ $mz['anteil'] !== null ? number_format($mz['anteil'], 1, ',', '.') . ' %' : '—' }}</td>
                                                    <td class="{{ $td }} text-right text-xs tabular-nums text-gray-300">{{ $mz['wes'] !== null ? number_format($mz['wes'], 1, ',', '.') . ' %' : '—' }}</td>
                                                    <td class="{{ $td }} text-right text-xs tabular-nums text-gray-300">{{ $mz['vk_schnitt'] !== null ? number_format($mz['vk_schnitt'], 2, ',', '.') . ' €' : '—' }}</td>
                                                    <td class="{{ $td }} text-right text-xs tabular-nums text-gray-100">{{ number_format($mz['umsatz'], 0, ',', '.') }} €</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr class="border-t border-white/15 bg-white/[0.03]">
                                                <td class="{{ $td }}"><span class="{{ $label }}">Gesamt</span></td>
                                                <td class="{{ $td }} text-right text-xs tabular-nums text-gray-400">{{ number_format($mengen['summe']['vorwoche'], 0, ',', '.') }}</td>
                                                <td class="{{ $td }} text-right text-xs tabular-nums text-gray-400">{{ number_format($mengen['summe']['schnitt4'], 0, ',', '.') }}</td>
                                                @foreach($mengen['tage'] as $mt)
                                                    <td class="{{ $td }} text-center text-xs tabular-nums text-gray-300">{{ number_format($mengen['summe']['je_tag'][$mt] ?? 0, 0, ',', '.') }}</td>
                                                @endforeach
                                                <td class="{{ $td }} text-right text-xs tabular-nums font-semibold text-gray-100">{{ number_format($mengen['summe']['summe'], 0, ',', '.') }}</td>
                                                <td class="{{ $td }}"></td>
                                                <td class="{{ $td }} text-right text-xs tabular-nums text-gray-300">{{ $mengen['summe']['wes'] !== null ? number_format($mengen['summe']['wes'], 1, ',', '.') . ' %' : '—' }}</td>
                                                <td class="{{ $td }}"></td>
                                                <td class="{{ $td }} text-right text-xs tabular-nums font-semibold text-gray-100">{{ number_format($mengen['summe']['umsatz'], 0, ',', '.') }} €</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <p class="text-[11px] text-gray-400 mt-2">Eine Zahl gilt für alle Einträge der Zelle. Leer oder 0 setzt zurück auf den Standard der Linie bzw. des Plans. „Vorwoche“ und „Ø 4 Wo.“ sind Planwerte; echte Verkaufszahlen kommen mit dem Plan/Ist-Abgleich.</p>
                            @else
                                <p class="text-[11px] text-gray-400">Für diese Mahlzeit gibt es keine Linien.</p>
                            @endif
                        </x-foodalchemist::modal-section>
                    </div>

                    {{-- ═══ Spec 57 · Paket 4: Tab BEDARF (Zutaten aus Plan × Mengen, nur lesend) ═══ --}}
                    <div x-show="tab === 'bedarf'" x-cloak class="pt-4 space-y-3" data-sp-tab-bedarf>
                        <x-foodalchemist::modal-section title="Bedarf · KW {{ (int) $montagDt->format('W') }} · {{ $mahlzeiten[$mahlzeit] ?? '' }}">
                            <div class="flex flex-wrap items-center gap-2 mb-3 text-xs">
                                <span class="inline-flex rounded-lg overflow-hidden border border-white/15">
                                    <button type="button" wire:click="bedarfTagSetzen(null)" class="px-2.5 py-1.5 {{ $bedarfTag === null ? 'bg-white/10 text-gray-100 font-medium' : 'text-gray-400' }}">Woche</button>
                                    @foreach($wochenTage as $wt)
                                        <button type="button" wire:click="bedarfTagSetzen('{{ $wt->format('Y-m-d') }}')" class="px-2.5 py-1.5 {{ $bedarfTag === $wt->format('Y-m-d') ? 'bg-white/10 text-gray-100 font-medium' : 'text-gray-400' }}">{{ $tagKurz[$wt->isoWeekday()] }}</button>
                                    @endforeach
                                </span>
                                @unless($bedarfAn)
                                    <button type="button" wire:click="bedarfBerechnen" class="{{ $btnPrimary }} h-8" data-sp-bedarf-berechnen>Bedarf berechnen</button>
                                @endunless
                                <span class="text-gray-400">Rezepte bis zum Grundprodukt aufgelöst, in Basiseinheit, mit Lead-Lieferantenartikel und ganzen Gebinden.</span>
                            </div>
                            @if($bedarfAn && $bedarf)
                                @if($bedarf['liste'] === null)
                                    <p class="text-[11px] text-gray-400">In diesem Zeitraum ist nichts geplant.</p>
                                @else
                                    @foreach($bedarf['liste']['lieferanten'] as $lf)
                                        <div class="rounded-xl border border-white/10 bg-white/[0.03] mb-2" wire:key="bedarf-{{ $loop->index }}">
                                            <div class="flex items-center justify-between px-3 py-2 border-b border-white/10">
                                                <span class="text-xs font-medium text-gray-100">{{ $lf['lieferant'] }}</span>
                                                <span class="text-[11px] tabular-nums text-gray-400">{{ count($lf['positionen']) }} Positionen · EK {{ number_format((float) $lf['ek_summe'], 2, ',', '.') }} €{{ $lf['ek_vollstaendig'] ? '' : ' (unvollständig)' }}</span>
                                            </div>
                                            <div class="overflow-x-auto">
                                                <table class="{{ $table }}" style="min-width:560px">
                                                    <thead><tr class="text-left"><th class="{{ $th }}">Grundprodukt</th><th class="{{ $th }} text-right">Menge</th><th class="{{ $th }}">Gebinde / Artikel</th><th class="{{ $th }} text-right">EK</th></tr></thead>
                                                    <tbody>
                                                        @foreach($lf['positionen'] as $pos)
                                                            <tr class="border-t border-white/5">
                                                                <td class="{{ $td }} text-xs text-gray-200">{{ $pos['gp'] }}</td>
                                                                <td class="{{ $td }} text-xs text-right tabular-nums text-gray-100">{{ number_format((float) $pos['menge_kg'], 3, ',', '.') }} kg</td>
                                                                <td class="{{ $td }} text-[11px] text-gray-400">
                                                                    {{ $pos['lead_artikel'] ?? '—' }}{{ $pos['lead_artikel_nr'] ? ' · ' . $pos['lead_artikel_nr'] : '' }}
                                                                    @if(($pos['gebinde']['berechenbar'] ?? false) && isset($pos['gebinde']['qty_packs']))
                                                                        · {{ $pos['gebinde']['qty_packs'] }}× {{ $pos['gebinde']['packaging_unit'] ?: 'Gebinde' }}{{ $pos['gebinde']['pack_qty'] ? ' à ' . rtrim(rtrim(number_format((float) $pos['gebinde']['pack_qty'], 3, ',', ''), '0'), ',') . ' ' . ($pos['gebinde']['pack_unit_code'] ?? '') : '' }}
                                                                    @elseif(! empty($pos['gebinde']['grund']))
                                                                        · <span class="text-amber-300/80">{{ $pos['gebinde']['grund'] }}</span>
                                                                    @endif
                                                                </td>
                                                                <td class="{{ $td }} text-xs text-right tabular-nums text-gray-300">{{ $pos['bestell_ek_eur'] !== null ? number_format((float) $pos['bestell_ek_eur'], 2, ',', '.') . ' €' : '—' }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    @endforeach
                                    @if(! empty($bedarf['liste']['warnungen']))
                                        <div class="rounded-lg border border-amber-400/30 bg-amber-400/10 px-3 py-2 text-[11px] text-amber-200">
                                            @foreach(array_slice($bedarf['liste']['warnungen'], 0, 5) as $w)<div>{{ $w }}</div>@endforeach
                                        </div>
                                    @endif
                                    <p class="text-[11px] text-gray-400 mt-2">An den Einkauf geht der Bedarf über die Produktion: „→ Produktion“ im Kalender, dann im Produktionsauftrag „Bedarf freigeben“. So wird nichts doppelt bestellt.</p>
                                @endif
                            @endif
                        </x-foodalchemist::modal-section>
                    </div>

                    {{-- ═══ Spec 57 · Paket 8: Tab PLAN/IST (nur lesend, Verkaufsjournal) ═══ --}}
                    <div x-show="tab === 'planist'" x-cloak class="pt-4 space-y-3" data-sp-tab-planist>
                        <x-foodalchemist::modal-section title="Plan/Ist · KW {{ (int) $montagDt->format('W') }} · {{ $mahlzeiten[$mahlzeit] ?? '' }}">
                            @if($planIst)
                                @php($pis = $planIst['summe'])
                                <div class="grid grid-cols-2 lg:grid-cols-4 gap-2 mb-3">
                                    <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3"><div class="{{ $label }}">Essen geplant</div><div class="text-lg font-semibold tabular-nums text-gray-100">{{ number_format($pis['plan'], 0, ',', '.') }}</div></div>
                                    <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3"><div class="{{ $label }}">Verkauft (Ist)</div><div class="text-lg font-semibold tabular-nums text-gray-100">{{ $planIst['hat_ist'] ? number_format($pis['ist'], 0, ',', '.') : '—' }}</div>
                                        @if($pis['abweichung_pct'] !== null)<div class="text-[11px] tabular-nums {{ $pis['abweichung_pct'] < 0 ? 'text-rose-300' : 'text-emerald-300' }}">{{ ($pis['abweichung_pct'] > 0 ? '+' : '') . number_format($pis['abweichung_pct'], 1, ',', '.') }} % zum Plan</div>@endif
                                    </div>
                                    <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3"><div class="{{ $label }}">Umsatz Plan</div><div class="text-lg font-semibold tabular-nums text-gray-100">{{ number_format($pis['plan_umsatz'], 0, ',', '.') }} €</div></div>
                                    <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3"><div class="{{ $label }}">Umsatz Ist</div><div class="text-lg font-semibold tabular-nums text-gray-100">{{ $planIst['hat_ist'] ? number_format($pis['ist_umsatz'], 0, ',', '.') . ' €' : '—' }}</div></div>
                                </div>
                                @unless($planIst['hat_ist'])
                                    <p class="text-[11px] text-amber-300/90 mb-2">Für diese Woche liegen keine Verkaufszahlen vor. Verkäufe werden im Controlling importiert (CSV aus der Kasse) und dort den Gerichten zugeordnet.</p>
                                @endunless
                                @if($planIst['zeilen'] !== [])
                                    <div class="overflow-x-auto">
                                        <table class="{{ $table }}" style="min-width:560px" data-sp-planist>
                                            <thead><tr class="text-left"><th class="{{ $th }}">Gericht</th><th class="{{ $th }} text-right">Plan</th><th class="{{ $th }} text-right">Ist</th><th class="{{ $th }} text-right">Δ</th><th class="{{ $th }} text-right">Umsatz Plan</th><th class="{{ $th }} text-right">Umsatz Ist</th></tr></thead>
                                            <tbody>
                                                @foreach($planIst['zeilen'] as $pz)
                                                    <tr class="border-t border-white/10">
                                                        <td class="{{ $td }} text-xs text-gray-200">{{ $pz['name'] }}</td>
                                                        <td class="{{ $td }} text-xs text-right tabular-nums">{{ number_format($pz['plan'], 0, ',', '.') }}</td>
                                                        <td class="{{ $td }} text-xs text-right tabular-nums">{{ $pz['ist'] !== null ? number_format($pz['ist'], 0, ',', '.') : '—' }}</td>
                                                        <td class="{{ $td }} text-xs text-right tabular-nums {{ ($pz['abweichung_pct'] ?? 0) < 0 ? 'text-rose-300' : 'text-emerald-300' }}">{{ $pz['abweichung_pct'] !== null ? (($pz['abweichung_pct'] > 0 ? '+' : '') . number_format($pz['abweichung_pct'], 1, ',', '.') . ' %') : '—' }}</td>
                                                        <td class="{{ $td }} text-xs text-right tabular-nums text-gray-300">{{ number_format($pz['plan_umsatz'], 2, ',', '.') }} €</td>
                                                        <td class="{{ $td }} text-xs text-right tabular-nums text-gray-300">{{ $pz['ist_umsatz'] !== null ? number_format($pz['ist_umsatz'], 2, ',', '.') . ' €' : '—' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                                @if($planIst['nicht_vergleichbar'] !== [])
                                    <p class="text-[11px] text-gray-400 mt-2">Nicht vergleichbar (Concept/Paket, in der Kasse kein einzelnes Gericht): {{ implode(', ', $planIst['nicht_vergleichbar']) }}.</p>
                                @endif
                                <p class="text-[10px] text-gray-500 mt-1">{{ $planIst['hinweis'] }}</p>
                            @endif
                        </x-foodalchemist::modal-section>
                    </div>

                    {{-- ═══ Tab: MENÜ-LINIEN ═══ --}}
                    <div x-show="tab === 'linien'" x-cloak class="pt-4" data-sp-tab-linien>
                        <x-foodalchemist::modal-section title="Menü-Linien">
                            <x-slot:actions>
                                <span class="text-[11px] text-gray-400">Zeilen der Matrix · pro Plan frei</span>
                            </x-slot:actions>
                            {{-- Spec 57 · Paket 2: jede Linie ist eine Ausgabestelle (Rolle, Kasse, Preis, Zielband). --}}
                            <div class="overflow-x-auto">
                                <table class="{{ $table }}" style="min-width:760px">
                                    <thead><tr class="text-left">
                                        <th class="{{ $th }}">Linie</th><th class="{{ $th }}">Rolle</th><th class="{{ $th }}">Mahlzeit</th><th class="{{ $th }}">Kasse</th>
                                        <th class="{{ $th }} text-right">Preis</th><th class="{{ $th }} text-center">Zielband WES</th><th class="{{ $th }} text-right">Essen</th><th class="{{ $th }}"></th>
                                    </tr></thead>
                                    <tbody>
                                        @foreach($linien as $linie)
                                            <tr wire:key="linie-{{ $linie->id }}" class="border-t border-white/10">
                                                <td class="{{ $td }}">
                                                    <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full shrink-0" style="background: {{ $linie->color ?: '#94a3b8' }}"></span><span class="text-xs text-gray-200">{{ $linie->name }}</span></span>
                                                    <span class="flex gap-1 mt-0.5">
                                                        @if($linie->is_vegetarian)<span class="{{ $pill }} {{ $variantPill['success'] }}">veg</span>@endif
                                                        @if($linie->is_standing)<span class="{{ $pill }} {{ $variantPill['secondary'] }}" title="Zählt nicht für die Wiederholungsregel">Dauerangebot</span>@endif
                                                    </span>
                                                </td>
                                                <td class="{{ $td }} text-xs text-gray-300">{{ $rollen[$linie->role] ?? '—' }}</td>
                                                <td class="{{ $td }} text-xs text-gray-300">{{ $linie->meal ? ($mahlzeiten[$linie->meal] ?? $linie->meal) : 'alle' }}</td>
                                                <td class="{{ $td }} text-xs text-gray-300 tabular-nums">{{ $linie->plu ?: '—' }}</td>
                                                <td class="{{ $td }} text-xs text-right tabular-nums text-gray-300">{{ $linie->manuellerPreis() !== null ? number_format($linie->manuellerPreis(), 2, ',', '.') . ' € (fest)' : 'vom Gericht' }}</td>
                                                <td class="{{ $td }} text-xs text-center tabular-nums text-gray-300">
                                                    @if($linie->target_wes_min_pct !== null || $linie->target_wes_max_pct !== null)
                                                        {{ $linie->target_wes_min_pct !== null ? number_format($linie->target_wes_min_pct, 0) : '0' }}–{{ $linie->target_wes_max_pct !== null ? number_format($linie->target_wes_max_pct, 0) : '…' }} %
                                                    @else
                                                        <span class="text-gray-500" title="Es gilt das Team-/Betriebs-Ziel">Team-Ziel</span>
                                                    @endif
                                                </td>
                                                <td class="{{ $td }} text-xs text-right tabular-nums text-gray-300">{{ $linie->default_pax ?: '—' }}</td>
                                                <td class="{{ $td }} whitespace-nowrap text-right">
                                                    <button type="button" wire:click="linieVerschieben({{ $linie->id }}, -1)" class="text-gray-500 hover:text-violet-300 text-[10px] px-0.5" aria-label="{{ $linie->name }} nach oben">▲</button>
                                                    <button type="button" wire:click="linieVerschieben({{ $linie->id }}, 1)" class="text-gray-500 hover:text-violet-300 text-[10px] px-0.5" aria-label="{{ $linie->name }} nach unten">▼</button>
                                                    <button type="button" wire:click="linieEdit({{ $linie->id }})" class="text-gray-400 hover:text-violet-300 text-xs px-0.5" aria-label="{{ $linie->name }} bearbeiten">@svg('heroicon-o-pencil', 'w-3.5 h-3.5 inline-block align-middle')</button>
                                                    <button type="button" wire:click="linieRaus({{ $linie->id }})" wire:confirm="Linie entfernen? Einträge bleiben (ohne Linie)." class="text-gray-400 hover:text-red-400 text-xs px-0.5" aria-label="{{ $linie->name }} entfernen">✕</button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="flex items-center gap-1 mt-2">
                                <input type="text" wire:model="neueLinie" wire:keydown.enter="linieAdd" placeholder="+ Linie …" class="{{ $input }} w-40 h-8 text-xs" aria-label="Name der neuen Linie" />
                                <button type="button" wire:click="linieAdd" class="{{ $btnGhostXs }}">Linie hinzufügen</button>
                            </div>
                            @if($editLinieId !== null)
                                <div class="mt-3 pt-3 border-t border-white/10 grid grid-cols-2 md:grid-cols-6 gap-3 items-end" data-sp-linie-form>
                                    <div class="md:col-span-2"><label class="{{ $label }}">Name</label><input type="text" wire:model="linieForm.name" class="{{ $input }} h-8" /></div>
                                    <div><label class="{{ $label }}">Farbe</label><input type="color" wire:model="linieForm.color" class="h-8 w-12 rounded border border-white/15 bg-transparent" /></div>
                                    <div><label class="{{ $label }}">Rolle</label>
                                        <select wire:model="linieForm.role" class="{{ $input }} h-8">
                                            <option value="">— keine —</option>
                                            @foreach($rollen as $rk => $rl)<option value="{{ $rk }}">{{ $rl }}</option>@endforeach
                                        </select>
                                    </div>
                                    <div><label class="{{ $label }}">Mahlzeit</label>
                                        <select wire:model="linieForm.meal" class="{{ $input }} h-8">
                                            <option value="">alle Mahlzeiten</option>
                                            @foreach($mahlzeiten as $mk => $ml)<option value="{{ $mk }}">{{ $ml }}</option>@endforeach
                                        </select>
                                    </div>
                                    <div><label class="{{ $label }}">Kassen-Nr.</label><input type="text" wire:model="linieForm.plu" maxlength="32" class="{{ $input }} h-8" /></div>
                                    <div><label class="{{ $label }}">Preis</label>
                                        <select wire:model.live="linieForm.price_mode" class="{{ $input }} h-8">
                                            <option value="auto">vom Gericht</option>
                                            <option value="manuell">fester Linienpreis</option>
                                        </select>
                                    </div>
                                    @if(($linieForm['price_mode'] ?? 'auto') === 'manuell')
                                        <div><label class="{{ $label }}">Linienpreis netto (€)</label><input type="text" inputmode="decimal" wire:model="linieForm.price_value" class="{{ $input }} h-8 text-right tabular-nums" placeholder="z. B. 6,40" /></div>
                                    @endif
                                    <div><label class="{{ $label }}">WES von (%)</label><input type="text" inputmode="decimal" wire:model="linieForm.target_wes_min_pct" class="{{ $input }} h-8 text-right tabular-nums" placeholder="leer = keine" /></div>
                                    <div><label class="{{ $label }}">WES bis (%)</label><input type="text" inputmode="decimal" wire:model="linieForm.target_wes_max_pct" class="{{ $input }} h-8 text-right tabular-nums" placeholder="leer = Team-Ziel" /></div>
                                    <div><label class="{{ $label }}">Essen je Tag</label><input type="number" min="0" wire:model="linieForm.default_pax" class="{{ $input }} h-8 text-right tabular-nums" placeholder="Plan-Standard" /></div>
                                    <label class="flex items-center gap-1.5 text-xs pb-1.5 text-gray-300"><input type="checkbox" wire:model="linieForm.is_vegetarian" /> nur vegetarisch</label>
                                    <label class="flex items-center gap-1.5 text-xs pb-1.5 text-gray-300" title="Dauerangebote (Salatbar …) zählen nicht für die Wiederholungsregel"><input type="checkbox" wire:model="linieForm.is_standing" /> Dauerangebot</label>
                                    <div class="flex gap-2 md:col-span-2">
                                        <button type="button" wire:click="linieSpeichern" class="{{ $btnPrimary }} h-8">Speichern</button>
                                        <button type="button" wire:click="$set('editLinieId', null)" class="{{ $btnGhost }} h-8">Abbrechen</button>
                                    </div>
                                </div>
                            @endif
                            <p class="text-[11px] text-gray-400 mt-2">Das Zielband ersetzt für diese Linie das eine Team-Ziel: Suppe und Dessert dürfen anders kalkulieren als der Hauptgang. Hauptgang-Linien zählen die Gäste des Tages (Tagesfuß, Budget je Gast).</p>
                        </x-foodalchemist::modal-section>
                    </div>

                    {{-- ═══ Tab: STAMMDATEN ═══ --}}
                    <div x-show="tab === 'stammdaten'" x-cloak class="pt-4 space-y-4" data-sp-tab-stammdaten>
                        <x-foodalchemist::modal-section title="Plan-Stammdaten">
                            <x-slot:actions>
                                <button type="button" wire:click="speichern" class="{{ $btnGhostXs }}" data-sp-stammdaten-speichern>Speichern</button>
                            </x-slot:actions>
                            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                                <div class="md:col-span-2"><label class="{{ $label }}">Name</label><input type="text" wire:model="form.name" class="{{ $input }}" /></div>
                                <div><label class="{{ $label }}">Start (Montag)</label><input type="date" wire:model.live="form.start_date" wire:change="speichern" class="{{ $input }}" /></div>
                                <div><label class="{{ $label }}">Zyklus (Wochen)</label><input type="number" min="1" wire:model.live="form.cycle_weeks" wire:change="speichern" class="{{ $input }} text-right tabular-nums" /></div>
                                <div><label class="{{ $label }}">Min. Abstand (T.)</label><input type="number" min="0" wire:model.live="form.min_abstand_tage" wire:change="speichern" class="{{ $input }} text-right tabular-nums" title="0 = keine Wiederholungsregel" /></div>
                            </div>

                            {{-- Spec 57 · Paket 9: Öffnungstage — steuern Matrix, Aushang, Produktion und Kaskade. --}}
                            <div class="mt-3" data-sp-oeffnungstage>
                                <label class="{{ $label }}">Öffnungstage</label>
                                <div class="flex flex-wrap gap-1 mt-1" role="group" aria-label="Öffnungstage">
                                    @foreach($tagKurz as $iso => $kurz)
                                        @php($offen = in_array($iso, array_map('intval', (array) ($form['opening_days'] ?? [])), true))
                                        <button type="button" wire:click="oeffnungstagUmschalten({{ $iso }})" aria-pressed="{{ $offen ? 'true' : 'false' }}"
                                                class="px-2.5 py-1 rounded-md border text-xs {{ $offen ? 'border-violet-400/50 bg-violet-500/15 text-violet-100' : 'border-white/10 text-gray-500 hover:text-gray-300' }}">{{ $kurz }}</button>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Spec 33 P5: Status und Zuordnung aus dem geteilten Bauteil. Hier stand
                                 bis dahin ein eigenes Dropdown mit `draft`/`active` — Werte, die weder
                                 Migration noch Service kannten. Ein Gültigkeitsfenster hat der Plan
                                 bewusst nicht: es steht in seinen Einträgen. --}}
                            <div class="mt-3 pt-3 border-t border-black/5">
                                <x-foodalchemist::ausgabe-status
                                    status-model="form.status"
                                    outlet-model="form.outlet_id"
                                    :betriebe="$betriebe" :zustand="$plan->laufZustand()" :grund="$plan->laufGrund()"
                                    :fenster-hinweis="$fensterHinweis" :konflikt="$portfolioKonflikt"
                                    toggle="aktivUmschalten" />
                            </div>
                            <x-foodalchemist::crm-kunde-picker
                                :ausgabe="$plan" :crm-verfuegbar="$crmVerfuegbar" :firmen="$firmen" :kontakte="$kontakte" />
                        </x-foodalchemist::modal-section>

                        <x-foodalchemist::modal-section title="Teilnehmer & Wareneinsatz-Budget">
                            <p class="text-[11px] text-gray-400 mb-2">Default-Kopfzahl für die Produktions-Übergabe (je Zelle überschreibbar) + EK-Zielwert pro Person für die Budget-Ampel.</p>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <div><label class="{{ $label }}">Teilnehmer (Default)</label><input type="number" min="1" wire:model.live="form.default_pax" wire:change="speichern" class="{{ $input }} text-right tabular-nums" data-sp-default-pax /></div>
                                <div><label class="{{ $label }}">Budget EK/Person (€)</label><input type="text" inputmode="decimal" wire:model.live="form.budget_wareneinsatz" wire:change="speichern" placeholder="z. B. 1,80" class="{{ $input }} text-right tabular-nums" title="Wareneinsatz-Ziel pro Person/Mahlzeit — Ampel in der Rail" /></div>
                            </div>
                        </x-foodalchemist::modal-section>

                        {{-- Spec 59: Vorgaben je Woche (mind./höchstens je Prüf-Chip) --}}
                        @include('foodalchemist::livewire.speiseplan.partials.vorgaben')

                        {{-- Spec 57 · Paket 7: Vorlage für Betriebe (verknüpfte Kopie je Betrieb, im eigenen Team) --}}
                        <x-foodalchemist::modal-section title="Vorlage für Betriebe">
                            @if($vorlageHinweis)<div class="mb-2 rounded-lg bg-violet-500/10 border border-violet-400/30 text-violet-100 text-xs px-3 py-1.5" data-sp-vorlage-hinweis>{{ $vorlageHinweis }}</div>@endif
                            @if($sp->source_plan_id !== null)
                                {{-- Dieser Plan ist die Kopie eines Betriebs --}}
                                <div data-sp-kopie-abgleich>
                                    <p class="text-[11px] text-gray-400 mb-2">
                                        Kopie der Vorlage „{{ $vorlagenAbgleich['vorlage']['name'] ?? '—' }}“ · zuletzt abgeglichen {{ $sp->source_synced_at?->format('d.m.Y H:i') ?? '—' }}.
                                        Preise, Mengen und Öffnungstage pflegt der Betrieb selbst.
                                    </p>
                                    @if(($vorlagenAbgleich['vorlage'] ?? null) === null)
                                        <p class="text-[11px] text-amber-300">Die Vorlage gibt es nicht mehr — dieser Plan ist jetzt frei.</p>
                                    @elseif($vorlagenAbgleich['zellen'] === [] && $vorlagenAbgleich['neue_linien'] === [])
                                        <p class="text-[11px] text-emerald-300">Ab heute stimmt der Plan mit der Vorlage überein.</p>
                                    @else
                                        @if($vorlagenAbgleich['neue_linien'] !== [])
                                            <p class="text-[11px] text-violet-200 mb-1">Neue Linien in der Vorlage: {{ collect($vorlagenAbgleich['neue_linien'])->pluck('name')->implode(', ') }}</p>
                                        @endif
                                        <div class="overflow-x-auto">
                                            <table class="{{ $table }}" style="min-width:620px">
                                                <thead><tr class="text-left"><th class="{{ $th }}">Tag</th><th class="{{ $th }}">Linie</th><th class="{{ $th }}">Vorlage</th><th class="{{ $th }}">Betrieb</th><th class="{{ $th }}"></th></tr></thead>
                                                <tbody>
                                                    @foreach(array_slice($vorlagenAbgleich['zellen'], 0, 40) as $vz)
                                                        <tr class="border-t border-white/10" wire:key="vz-{{ $vz['key'] }}">
                                                            <td class="{{ $td }} text-xs tabular-nums">{{ \Illuminate\Support\Carbon::parse($vz['datum'])->format('d.m.') }} · {{ $mahlzeiten[$vz['mahlzeit']] ?? $vz['mahlzeit'] }}</td>
                                                            <td class="{{ $td }} text-xs">{{ $vz['linie'] }}</td>
                                                            <td class="{{ $td }} text-xs text-gray-200">{{ implode(', ', $vz['vorlage']) ?: '—' }}</td>
                                                            <td class="{{ $td }} text-xs text-gray-400">{{ implode(', ', $vz['betrieb']) ?: '—' }}</td>
                                                            <td class="{{ $td }} text-right whitespace-nowrap">
                                                                <span class="{{ $pill }} {{ $vz['art'] === 'vorlage_geaendert' ? $variantPill['warning'] : $variantPill['secondary'] }}">{{ $vz['art'] === 'vorlage_geaendert' ? 'aus der Vorlage' : 'lokal' }}</span>
                                                                <button type="button" wire:click="ausVorlageUebernehmen('{{ $vz['key'] }}')" class="{{ $btnGhostXs }}">übernehmen</button>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        <button type="button" wire:click="ausVorlageUebernehmen(null)" wire:confirm="Alle Änderungen der Vorlage übernehmen? Lokale Abweichungen bleiben." class="{{ $btnPrimary }} mt-2" data-sp-vorlage-uebernehmen>Änderungen aus der Vorlage übernehmen</button>
                                    @endif
                                </div>
                            @else
                                <label class="flex items-center gap-2 text-xs text-gray-300" data-sp-vorlage-schalter>
                                    <input type="checkbox" @checked($sp->is_template) wire:click="vorlageUmschalten" />
                                    Als Vorlage freigeben — Betriebe bekommen eine verknüpfte Kopie und übernehmen Änderungen per Abgleich.
                                </label>
                                @if($sp->is_template)
                                    <div class="mt-3 space-y-2" data-sp-betriebskopien>
                                        @forelse($betriebsKopien as $bk)
                                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2 text-xs" wire:key="bk-{{ $bk['id'] }}">
                                                <span class="text-gray-100">{{ $bk['outlet'] ?? $bk['name'] }} <span class="text-gray-500">· {{ $bk['status'] }}</span></span>
                                                <span class="flex items-center gap-2">
                                                    @if($bk['aus_vorlage'] > 0)<span class="{{ $pill }} {{ $variantPill['warning'] }}">{{ $bk['aus_vorlage'] }} Änderung(en) offen</span>@else<span class="{{ $pill }} {{ $variantPill['success'] }}">aktuell</span>@endif
                                                    @if($bk['lokal'] > 0)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $bk['lokal'] }} lokal</span>@endif
                                                    <button type="button" wire:click="$dispatch('speiseplan-editor.bearbeiten', { id: {{ $bk['id'] }} })" class="{{ $btnGhostXs }}">öffnen</button>
                                                </span>
                                            </div>
                                        @empty
                                            <p class="text-[11px] text-gray-500">Noch keine Betriebs-Kopie.</p>
                                        @endforelse
                                        @if($betriebe->isNotEmpty())
                                            <div class="flex flex-wrap items-end gap-2">
                                                <select wire:model="kopieOutletId" class="{{ $input }} h-8 w-56" aria-label="Betrieb für die Kopie">
                                                    <option value="">— Betrieb wählen —</option>
                                                    @foreach($betriebe as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                                                </select>
                                                <button type="button" wire:click="betriebsKopieAnlegen" class="{{ $btnGhost }} h-8" data-sp-kopie-anlegen>+ Kopie für Betrieb anlegen</button>
                                            </div>
                                        @else
                                            <p class="text-[11px] text-amber-300">Noch keine Betriebe angelegt — unter <em>Einstellungen › Betriebe</em>.</p>
                                        @endif
                                    </div>
                                @endif
                            @endif
                        </x-foodalchemist::modal-section>

                        <x-foodalchemist::modal-section title="Zyklus ausrollen">
                            <p class="text-[11px] text-gray-400 mb-2">Den {{ $sp->cycle_weeks }}-Wochen-Block ab Start auf alle Folgewochen bis zum Zieldatum kopieren. Belegte Zellen bleiben unberührt, außer du wählst „ersetzen“. Mengen (Pax) wandern mit.</p>
                            <div class="flex items-center gap-2 flex-wrap">
                                <input type="date" wire:model="ausrollenBis" class="{{ $input }} w-44" title="Zyklus-Vorlage bis zu diesem Datum ausrollen" />
                                <label class="flex items-center gap-1.5 text-xs text-gray-300"><input type="checkbox" wire:model.live="ausrollenErsetzen" /> belegte Zellen ersetzen</label>
                                <button type="button" wire:click="ausrollen" @if($ausrollenErsetzen) wire:confirm="Belegte Zellen in den Folgewochen werden durch die Vorlage ersetzt. Fortfahren?" @endif class="{{ $btnGhost }}" data-sp-ausrollen>⟳ Zyklus ausrollen</button>
                                @if($ausrollenInfo)<span class="text-[11px] text-violet-300">{{ $ausrollenInfo }}</span>@endif
                            </div>
                        </x-foodalchemist::modal-section>
                    </div>

                    {{-- ═══ Spec 43: Tab BRANDING & PRÄSENTATION (digitaler Aushang) ═══ --}}
                    <div x-show="tab === 'praesentation'" x-cloak class="pt-4 space-y-4" data-sp-tab-praesentation>
                        @if($brandingFehler)<div class="rounded-lg bg-rose-500/15 border border-rose-500/30 text-rose-200 text-xs px-3 py-2">{{ $brandingFehler }}</div>@endif

                        {{-- Spec 57 · Paket 6: Druck & Export der sichtbaren Woche/Mahlzeit --}}
                        <x-foodalchemist::modal-section title="Druck & Export · KW {{ (int) $montagDt->format('W') }} · {{ $mahlzeiten[$mahlzeit] ?? '' }}">
                            <div class="flex flex-wrap items-end gap-3 mb-3 text-xs">
                                <label class="flex flex-col gap-1"><span class="{{ $label }}">Tag (Aufsteller, Schilder, Tagesliste)</span>
                                    <select wire:model.live="ausgabeTag" class="{{ $input }} h-8">
                                        @foreach($wochenTage as $wt)
                                            <option value="{{ $wt->format('Y-m-d') }}" @selected($wt->format('Y-m-d') === $ausgabeTagEffektiv)>{{ $tagKurz[$wt->isoWeekday()] }} {{ $wt->format('d.m.') }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="flex flex-col gap-1"><span class="{{ $label }}">Linie (Linien-/Buffetschild)</span>
                                    <select wire:model.live="ausgabeLinie" class="{{ $input }} h-8">
                                        <option value="">alle Linien</option>
                                        @foreach($matrixLinien as $ml)<option value="{{ $ml->id }}">{{ $ml->name }}</option>@endforeach
                                    </select>
                                </label>
                                <label class="flex items-center gap-1.5 text-gray-300 pb-1.5"><input type="checkbox" wire:model.live="ausgabePreise" /> Preise zeigen</label>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2" data-sp-ausgabe-formate>
                                @foreach([
                                    ['woche', 'Wochenaushang A4', 'Linien × Tage, Kennzeichnung und Legende'],
                                    ['tag', 'Tischaufsteller', 'Zeltkarte A4 quer, ein Tag, alle Linien'],
                                    ['schild', 'Linienschilder', 'je Linie ein Schild (A5 quer)'],
                                    ['buffet', 'Buffetschilder', 'je Gericht und Komponente ein Zeltkärtchen, 6 pro A4'],
                                    ['liste_woche', 'Allergen- & Komponentenliste · Woche', 'für den Ordner an der Ausgabe'],
                                    ['liste_tag', 'Allergen- & Komponentenliste · Tag', 'nur der gewählte Tag'],
                                    ['csv', 'CSV-Export', 'Woche als Tabelle (Semikolon, Excel-tauglich)'],
                                ] as [$fk, $fl, $fs])
                                    <a href="{{ $ausgabeLinks[$fk] ?? '#' }}" target="_blank" class="rounded-xl border border-white/10 bg-white/[0.03] hover:bg-white/[0.06] px-3 py-2 block" data-sp-format="{{ $fk }}">
                                        <span class="block text-xs font-medium text-gray-100">{{ $fl }}</span>
                                        <span class="block text-[11px] text-gray-400">{{ $fs }}</span>
                                    </a>
                                @endforeach
                            </div>
                            <p class="text-[11px] text-gray-400 mt-2">Kennzeichnung (Allergene, Zusatzstoffe, Kostform) kommt immer aus den Rezepten. Logo und Farben der Gäste-Drucke kommen aus Branding und Präsentations-Design (unten). Jede Vorlage lässt sich im neuen Tab als PDF herunterladen.</p>
                        </x-foodalchemist::modal-section>

                        <x-foodalchemist::modal-section title="Branding">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <label class="text-xs text-gray-300">Markenfarbe
                                    <input type="color" wire:model="brandColor" class="block w-full h-8 rounded border border-white/10 bg-transparent">
                                </label>
                                <label class="text-xs text-gray-300">Bandfarbe (optional)
                                    <input type="color" wire:model="bandColor" class="block w-full h-8 rounded border border-white/10 bg-transparent">
                                </label>
                                <label class="text-xs text-gray-300">Footer-Text
                                    <input type="text" wire:model="footerText" class="{{ $input }} w-full" placeholder="z.B. Küche XY">
                                </label>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 mt-3">
                                <button type="button" wire:click="brandingSpeichern" class="{{ $btnGhost }}" data-sp-branding-speichern>Branding speichern</button>
                                <div class="text-xs text-gray-400">Logo
                                    @if($brandingBilder['logo'])<img src="{{ $brandingBilder['logo'] }}" class="inline-block h-6 align-middle ml-1 rounded bg-white/10"> <button type="button" wire:click="brandingLogoEntfernen" class="text-rose-300 text-[11px]">entfernen</button>@endif
                                    <input type="file" wire:model="logoUpload" accept="image/*" class="block text-[11px] mt-1">
                                </div>
                                <div class="text-xs text-gray-400">Coverbild
                                    @if($brandingBilder['cover'])<img src="{{ $brandingBilder['cover'] }}" class="inline-block h-6 align-middle ml-1 rounded bg-white/10"> <button type="button" wire:click="brandingCoverEntfernen" class="text-rose-300 text-[11px]">entfernen</button>@endif
                                    <input type="file" wire:model="coverUpload" accept="image/*" class="block text-[11px] mt-1">
                                </div>
                            </div>
                        </x-foodalchemist::modal-section>

                        <x-foodalchemist::modal-section title="Präsentation · digitaler Aushang">
                            @if($presentationHinweis)<div class="rounded-lg bg-emerald-500/15 border border-emerald-500/30 text-emerald-200 text-xs px-3 py-2 mb-2" data-sp-praes-hinweis>{{ $presentationHinweis }}</div>@endif
                            @if($presentationFehler)<div class="rounded-lg bg-rose-500/15 border border-rose-500/30 text-rose-200 text-xs px-3 py-2 mb-2" data-sp-praes-fehler>{{ $presentationFehler }}</div>@endif

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="text-xs text-gray-300">Design
                                    <select wire:model="presentationDesign" class="{{ $input }} w-full" data-sp-praes-design>
                                        @foreach($presentationDesignOptionen as $opt)
                                            <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="text-xs text-gray-300">Gültig bis <span class="text-rose-400">*</span>
                                    <input type="date" wire:model="presentationGueltigBis" class="{{ $input }} w-full" data-sp-praes-gueltig>
                                </label>
                            </div>
                            <label class="flex items-center gap-2 text-xs text-gray-300 mt-2" data-sp-praes-laufend>
                                <input type="checkbox" wire:model="presentationLaufendeWoche">
                                Immer die laufende Woche zeigen (jeden Montag neu eingefroren) — sonst KW {{ (int) $montagDt->format('W') }}, {{ $mahlzeiten[$mahlzeit] ?? '' }}
                            </label>
                            <label class="flex items-center gap-2 text-xs text-gray-300 mt-2"><input type="checkbox" wire:model="presentationPreisAnzeige" data-sp-praes-preis> Preise anzeigen (optional — Default aus)</label>
                            {{-- Ebene 2 · Republish-Preis-Schutz (nur relevant mit Preisen) --}}
                            <label class="flex items-center gap-2 text-xs text-gray-300" title="Aus: beim erneuten Veröffentlichen bleiben die eingefrorenen Preise stehen. An: aktuelle VK ziehen. Nur mit Preisen relevant; Erstveröffentlichung immer aktuell."><input type="checkbox" wire:model="presentationPreiseAktualisieren"> Preise aktualisieren</label>
                            <p class="text-[11px] text-gray-400 mt-1">GV-Aushang ist per Default preislos; die LMIV-Kennzeichnung ist immer Pflicht und sichtbar. Preise z.B. für Café-/Bistro-Pläne — folgen dem aktiven Betrieb.</p>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                                <label class="text-xs text-gray-300">CTA-Text (optional)
                                    <input type="text" wire:model="presentationCtaText" class="{{ $input }} w-full" placeholder="z.B. Mehr Infos">
                                </label>
                                <label class="text-xs text-gray-300">CTA-Link (optional)
                                    <input type="url" wire:model="presentationCtaLink" class="{{ $input }} w-full" placeholder="https://…">
                                </label>
                            </div>

                            @if($presentationInfo['design_veraltet'] ?? false)
                                {{-- Bug-Runde 2026-09-17 #2: Aushang rendert nur den eingefrorenen Snapshot
                                     (Editor ist dunkel gescopet — eigene Tonwerte statt der hellen Variante). --}}
                                <div class="rounded-lg border border-amber-400/40 bg-amber-400/10 px-3 py-2 text-[12px] text-amber-200 mt-3" data-fa-design-veraltet>
                                    <strong>Design wurde nach der Veröffentlichung geändert.</strong>
                                    Der Aushang zeigt weiter den Stand von {{ $presentationInfo['published_at'] ?? '—' }}.
                                    Zum Übernehmen unten <em>Neu veröffentlichen</em>.
                                </div>
                            @endif

                            <div class="flex flex-wrap items-center gap-2 mt-3">
                                <a href="{{ route('foodalchemist.speiseplan.praesentation', ['id' => $sp->id, 'design' => $presentationDesign]) }}" target="_blank" class="{{ $btnGhost }}">Vorschau öffnen</a>
                                <button type="button" wire:click="veroeffentlichen" wire:confirm="Diesen Aushang veröffentlichen? Der Snapshot wird eingefroren." class="{{ $btnPrimary }}" data-sp-praes-publish @disabled(! $presentationGueltigBis)>
                                    {{ ($presentationInfo['enabled'] ?? false) ? 'Neu veröffentlichen' : 'Veröffentlichen' }}
                                </button>
                                @if($presentationInfo['enabled'] ?? false)
                                    <button type="button" wire:click="zuruckziehen" wire:confirm="Veröffentlichung zurückziehen? Der Link liefert dann 404." class="{{ $btnGhost }}" data-sp-praes-withdraw>Zurückziehen</button>
                                @endif
                            </div>
                            @unless($presentationGueltigBis)
                                <p class="text-[11px] text-amber-300 mt-1">Zum Veröffentlichen ein „gültig bis"-Datum setzen (Pflicht).</p>
                            @endunless

                            @if($presentationLink)
                                <div class="rounded-lg bg-white/5 border border-white/10 p-3 text-xs mt-3" x-data>
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 rounded px-2 py-1 font-mono text-[11px] break-all select-all bg-black/30 border border-white/10 text-gray-200" data-sp-praes-link>{{ $presentationLink }}</div>
                                        <button type="button" class="{{ $btnGhost }}" x-on:click="navigator.clipboard.writeText('{{ $presentationLink }}'); $el.textContent='Kopiert ✓'">Link kopieren</button>
                                    </div>
                                    <p class="text-[11px] text-gray-400 mt-1">Freigegeben am {{ $presentationInfo['published_at'] ?? '—' }} · gültig bis {{ $presentationInfo['expires_at'] ?? '—' }} · {{ ($presentationInfo['live'] ?? false) ? 'aktiv' : 'inaktiv/abgelaufen' }}</p>
                                </div>
                            @endif

                            {{-- ── Slice F: Betriebs-Links — pro Betrieb ein eigener Aushang-Link (eigene Vorlage + Name) ── --}}
                            <div class="rounded-lg border border-violet-400/25 bg-violet-500/[0.06] p-3 space-y-3 mt-3">
                                <div class="flex items-center gap-2">
                                    <span class="inline-block w-2 h-2 rounded-full bg-violet-400"></span>
                                    <h4 class="text-xs font-semibold text-violet-200">Betriebs-Links · eigener Aushang je Betrieb</h4>
                                </div>
                                <p class="text-[11px] text-gray-400">Ein zusätzlicher Aushang-Link pro Betrieb — mit der <strong class="text-gray-200">Vorlage</strong> und dem <strong class="text-gray-200">Namen</strong> dieses Betriebs, eigene Freigabe. Der Standard-Link oben bleibt bestehen.</p>

                                @forelse($betriebsLinks as $bl)
                                    <div class="rounded-lg bg-white/[0.04] border border-white/10 p-2 text-xs" x-data>
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-gray-100">{{ $bl['outlet_name'] }}</span>
                                            <span class="text-[10px] px-1.5 py-0.5 rounded {{ $bl['enabled'] ? 'bg-emerald-500/15 text-emerald-300' : 'bg-white/10 text-gray-400' }}">{{ $bl['enabled'] ? 'aktiv' : 'inaktiv' }}</span>
                                            <span class="ml-auto text-[10px] text-gray-500">Vorlage: {{ $bl['design'] }}</span>
                                        </div>
                                        <div class="flex items-center gap-2 mt-1">
                                            <div class="flex-1 rounded px-2 py-1 font-mono text-[11px] break-all select-all bg-black/30 border border-white/10 text-gray-200">{{ $bl['url'] }}</div>
                                            <button type="button" class="{{ $btnGhost }}" x-on:click="navigator.clipboard.writeText('{{ $bl['url'] }}'); $el.textContent='Kopiert ✓'">Kopieren</button>
                                            @if($bl['enabled'])
                                                <button type="button" wire:click="betriebZuruckziehen({{ $bl['outlet_id'] }})" wire:confirm="Diesen Betriebs-Link zurückziehen? Er liefert dann 404." class="{{ $btnGhost }}">Zurückziehen</button>
                                            @else
                                                <button type="button" wire:click="betriebWiederFreigeben({{ $bl['outlet_id'] }})" class="{{ $btnPrimary }}">Wieder freigeben</button>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-[11px] text-gray-500">Noch kein Betriebs-Link angelegt.</p>
                                @endforelse

                                @if(count($betriebsOptionen) > 0)
                                    <div class="rounded-lg border border-dashed border-violet-400/30 bg-white/[0.02] p-2.5 space-y-2">
                                        <p class="text-[11px] font-medium text-violet-200">Weiteren Betrieb hinzufügen</p>
                                        <div class="flex flex-wrap items-end gap-2">
                                            <div>
                                                <label class="block text-[10px] text-gray-400">Betrieb</label>
                                                <select wire:model="outletPublishId" class="mt-1 block text-sm rounded px-2 py-1">
                                                    <option value="">— wählen —</option>
                                                    @foreach($betriebsOptionen as $o)
                                                        <option value="{{ $o['id'] }}">{{ $o['name'] }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-[10px] text-gray-400">gültig bis (optional)</label>
                                                <input type="date" wire:model="outletPublishGueltigBis" class="mt-1 block text-sm rounded px-2 py-1">
                                            </div>
                                            <div>
                                                <label class="block text-[10px] text-gray-400">Vorlage (optional)</label>
                                                <select wire:model="outletPublishDesign" class="mt-1 block text-sm rounded px-2 py-1">
                                                    <option value="">— Betriebs-Vorlage / wie Dokument —</option>
                                                    @foreach($presentationDesignOptionen as $opt)
                                                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-[10px] text-gray-400">Link-Name (optional)</label>
                                                <input type="text" wire:model="outletPublishSlug" placeholder="z.B. broich-nord-2027" class="mt-1 block text-sm rounded px-2 py-1">
                                            </div>
                                            <button type="button" wire:click="betriebVeroeffentlichen" class="{{ $btnPrimary }}">＋ Betrieb hinzufügen</button>
                                        </div>
                                        <p class="text-[10px] text-gray-500">Beliebig viele Betriebe möglich — je Betrieb ein eigener Link. Ohne eigenes Datum gilt das „gültig bis" des Standard-Links.</p>
                                    </div>
                                @else
                                    <p class="text-[11px] text-amber-300">Noch keine Betriebe angelegt — lege sie unter <em>Einstellungen › Betriebe</em> an.</p>
                                @endif
                            </div>
                        </x-foodalchemist::modal-section>
                    </div>
                </x-foodalchemist::editor-tabs>
            </div>

            {{-- ═══ Live-Kennzahlen-Rail (Cockpit) ═══
                 Rechnet bei jeder Zellen-/Linien-Änderung mit — VK/EK je Person, Veggie-Tagescheck,
                 Wiederholungs-Konflikte. Bewusst auf Tab-Ebene: aus jedem Tab sichtbar. --}}
            <aside class="w-72 shrink-0 pr-6 sticky top-0 self-start max-h-[85vh] overflow-y-auto space-y-3 pt-4" data-sp-kennzahlen>
                <h3 class="{{ $label }} px-1">Kennzahlen · Woche</h3>

                @if($kosten)
                    <div class="rounded-xl border border-white/10 bg-white/[0.04] p-4 text-center">
                        <div class="text-2xl font-semibold text-gray-100 tabular-nums">{{ number_format($kosten['woche']['vk'], 2, ',', '.') }} €</div>
                        <div class="{{ $label }}">VK/Person · {{ $mahlzeiten[$mahlzeit] ?? '' }} · EK {{ number_format($kosten['woche']['ek'], 2, ',', '.') }} €</div>
                        <div class="text-[10px] text-gray-500 mt-1">Default {{ $sp->default_pax }} Teilnehmer</div>
                    </div>
                @endif

                {{-- Wareneinsatz-Budget-Ampel (GV) · Spec 57 · E2: Ø EK je GAST und Tag (Hauptgang-Linien) vs. Budget —
                     gerechnet im Service (budgetAmpel), nicht hier. --}}
                @if($budget)
                    <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3 space-y-1" data-sp-budget>
                        <div class="flex items-center justify-between text-[11px]">
                            <span class="{{ $label }}">Wareneinsatz-Budget</span>
                            <span class="{{ $pill }} {{ $variantPill[$budget['ampel']] }}">{{ $budget['ampel'] === 'success' ? 'im Ziel' : ($budget['ampel'] === 'warning' ? $budget['ueber_tage'] . ' Tag(e) drüber' : 'über Ziel') }}</span>
                        </div>
                        <div class="text-[11px] text-gray-400">Ø EK {{ number_format($budget['avg'], 2, ',', '.') }} € / Budget {{ number_format($budget['budget'], 2, ',', '.') }} € {{ $budget['basis'] === 'je_gast' ? 'je Gast und Tag' : 'p. P./Tag (Summe aller Linien)' }}</div>
                        @if($budget['basis'] !== 'je_gast')
                            <p class="text-[10px] text-amber-300/80">Für „je Gast“ im Tab „Menü-Linien“ die Hauptgang-Linien als Rolle „Hauptgang“ markieren.</p>
                        @endif
                    </div>
                @endif

                {{-- Spec 57 · Paket 2: Wareneinsatz je Linie gegen ihr Zielband (Woche). --}}
                @if(! empty($zk['linien']))
                    @php($ampelBalken = ['ok' => 'bg-emerald-400', 'unter' => 'bg-sky-400', 'ueber' => 'bg-amber-400', 'weit_ueber' => 'bg-rose-400', 'unbekannt' => 'bg-gray-500'])
                    @php($ampelText = ['ok' => 'text-emerald-300', 'unter' => 'text-sky-300', 'ueber' => 'text-amber-300', 'weit_ueber' => 'text-rose-300', 'unbekannt' => 'text-gray-400'])
                    <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3 space-y-2" data-sp-linien-ampel>
                        <div class="{{ $label }}">Wareneinsatz je Linie</div>
                        @foreach($zk['linien'] as $lid => $la)
                            <div class="text-[11px]" wire:key="la-{{ $lid }}">
                                <div class="flex justify-between gap-2"><span class="text-gray-300 truncate">{{ $la['name'] }}</span><span class="tabular-nums {{ $ampelText[$la['status']] ?? 'text-gray-400' }}">{{ $la['wes'] !== null ? number_format($la['wes'], 0, ',', '.') . ' %' : '—' }}</span></div>
                                <div class="relative h-1.5 rounded-full bg-white/10 mt-0.5" aria-hidden="true">
                                    <div class="absolute h-full rounded-full bg-emerald-400/25" style="left: {{ min(100, (float) ($la['band']['min'] ?? 0)) }}%; width: {{ max(0, min(100, (float) $la['band']['max']) - min(100, (float) ($la['band']['min'] ?? 0))) }}%"></div>
                                    @if($la['wes'] !== null)
                                        <div class="absolute -top-0.5 w-1 h-2.5 rounded {{ $ampelBalken[$la['status']] ?? 'bg-gray-500' }}" style="left: calc({{ min(98, max(0, (float) $la['wes'])) }}% - 2px)"></div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                        <p class="text-[10px] text-gray-500">Grün hinterlegt: Zielband (Skala 0–100 %). Ohne eigenes Band gilt das Team-Ziel {{ number_format($zk['team_ziel'] ?? 0, 0) }} %.</p>
                    </div>
                @endif

                {{-- Kostformen-Abdeckung (GV): ist jede Kostform an jedem Werktag vertreten? --}}
                <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3 space-y-1.5" data-sp-kostformen>
                    <div class="{{ $label }}">Kostformen-Abdeckung</div>
                    @foreach($kostformen as $kf)
                        <div class="flex items-center justify-between gap-2 text-[11px]">
                            <span class="text-gray-300 truncate">{{ $kf['label'] }}</span>
                            @if($kf['erfuellt'])
                                <span class="{{ $pill }} {{ $variantPill['success'] }} shrink-0" title="an jedem Werktag vertreten">✓ täglich</span>
                            @elseif($kf['abgedeckt'] > 0)
                                <span class="{{ $pill }} {{ $variantPill['warning'] }} shrink-0" title="fehlt: {{ implode(', ', array_map(fn($d) => \Illuminate\Support\Carbon::parse($d)->format('d.m.'), $kf['fehltage'])) }}">{{ $kf['abgedeckt'] }}/{{ $kf['tage'] }}</span>
                            @else
                                <span class="{{ $pill }} {{ $variantPill['secondary'] }} shrink-0">—</span>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- LMIV-Kennzeichnung der Woche (deklarationspflichtig, ALL-MAXIMAL über alle Gerichte) --}}
                @if($kennzeichnung)
                    @php($algEnth = collect($kennzeichnung['woche']['allergene'])->whereIn('status', ['enthalten', 'spuren']))
                    @php($zusJa = collect($kennzeichnung['woche']['zusatzstoffe'])->where('status', 'ja'))
                    <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3 space-y-1.5" data-sp-kennzeichnung>
                        <div class="{{ $label }}">Kennzeichnung · Woche</div>
                        @if($algEnth->isEmpty())
                            <p class="text-[11px] text-gray-400">Keine Allergene deklariert (oder unbekannt).</p>
                        @else
                            <div class="flex flex-wrap gap-1">
                                @foreach($algEnth as $a)
                                    <span class="{{ $pill }} {{ $a['status'] === 'enthalten' ? $variantPill['danger'] : $variantPill['warning'] }}" title="{{ $a['status'] === 'spuren' ? 'Spuren' : 'enthalten' }}">{{ $a['label'] }}{{ $a['status'] === 'spuren' ? ' (Sp.)' : '' }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if($zusJa->isNotEmpty())
                            <div class="flex flex-wrap gap-1 pt-1.5 border-t border-white/10">
                                @foreach($zusJa as $z)
                                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}" title="Zusatzstoff (LMIV)">{{ $z['label'] }}</span>
                                @endforeach
                            </div>
                        @endif
                        <p class="text-[10px] text-gray-500">Vollständige Tages-Kennzeichnung im Aushang-Export.</p>
                    </div>
                @endif

                {{-- DGE-Nährwert-Wochenbilanz (Ø je Person/Tag) --}}
                @if($naehrwerte && $naehrwerte['tage_mit_daten'] > 0)
                    @php($n = $naehrwerte['schnitt'])
                    <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3 space-y-1" data-sp-naehrwerte>
                        <div class="{{ $label }}">Nährwerte · Ø/Person/Tag</div>
                        <div class="grid grid-cols-2 gap-x-3 gap-y-0.5 text-[11px] text-gray-300">
                            <div class="flex justify-between"><span class="text-gray-500">kcal</span><span class="tabular-nums">{{ $n['kcal'] !== null ? number_format($n['kcal'], 0, ',', '.') : '—' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Eiweiß</span><span class="tabular-nums">{{ $n['protein_g'] !== null ? number_format($n['protein_g'], 1, ',', '.') . ' g' : '—' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Fett</span><span class="tabular-nums">{{ $n['fett_g'] !== null ? number_format($n['fett_g'], 1, ',', '.') . ' g' : '—' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">ges. Fett</span><span class="tabular-nums">{{ $n['gesfett_g'] !== null ? number_format($n['gesfett_g'], 1, ',', '.') . ' g' : '—' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Salz</span><span class="tabular-nums">{{ $n['salz_g'] !== null ? number_format($n['salz_g'], 2, ',', '.') . ' g' : '—' }}</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">Zucker</span><span class="tabular-nums">{{ $n['zucker_g'] !== null ? number_format($n['zucker_g'], 1, ',', '.') . ' g' : '—' }}</span></div>
                        </div>
                        @if($naehrwerte['confidence'] !== 'high')<p class="text-[10px] text-amber-300/80">Konfidenz {{ $naehrwerte['confidence'] }} — nicht alle Gerichte mit Nährwert/Portionsgramm.</p>@endif
                    </div>
                @endif

                {{-- Abwechslung/Häufigkeit: Diät-Mix + Warengruppen der Woche + Spec 59 Plan-Vorgaben --}}
                @if($abwechslung)
                    @include('foodalchemist::livewire.speiseplan.partials.abwechslung', ['ab' => $abwechslung])
                @endif

                <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3 space-y-1">
                    @if(!empty($wiederholungen))
                        <div class="{{ $label }} text-amber-300">Wiederholungen ({{ count($wiederholungen) }})</div>
                        @foreach($wiederholungen as $w)
                            <p class="text-[11px] {{ $variantPill['warning'] }} {{ $pill }} w-full justify-between"><span class="truncate">{{ $w['name'] }}</span><span class="shrink-0 ml-2">{{ $w['vorkommen'] }}× · {{ $w['min_abstand'] }} T.</span></p>
                        @endforeach
                    @else
                        <p class="text-[11px] text-gray-400 text-center">Keine Wiederholungs-Konflikte.</p>
                    @endif
                </div>
            </aside>
        </div>
    @endif
</x-foodalchemist::modal>
