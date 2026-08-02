{{-- Spec 32 · C3 — Erlösseite: Verkaufs-Ist einlesen → offene Zeilen zuordnen → Matrix lesen.
     Die drei Blöcke stehen in dieser Reihenfolge, weil sie eine Kette sind. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($eur = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', '.') . ' €')
@php($quadLabel = ['star' => 'Star', 'renner' => 'Renner', 'schlaefer' => 'Schläfer', 'penner' => 'Penner'])
@php($quadPill = ['star' => $variantPill['success'], 'renner' => $variantPill['info'],
                  'schlaefer' => $variantPill['warning'], 'penner' => $variantPill['danger']])

<div class="space-y-4" data-ctrl-erfolg>

    {{-- ── 1. Import ───────────────────────────────────────────────────────── --}}
    <div class="{{ $sectionCard }}" data-ctrl-sales-import>
        <h4 class="text-xs font-medium text-gray-900">Verkaufs-Ist einlesen</h4>
        <p class="text-[11px] text-gray-500 mt-0.5">
            CSV aus Kasse oder Abrechnung. Die Spalten ordnest du selbst zu — es gibt kein
            einheitliches Exportformat, deshalb wartet der Import nicht auf eine bestimmte
            Kopfzeile. Geschrieben wird erst nach dem Trockenlauf.
        </p>

        <div class="flex flex-wrap items-end gap-3 mt-3">
            <div>
                <label class="block {{ $label }} mb-1">Datei hochladen</label>
                <input type="file" wire:model="datei" accept=".csv,.tsv,.txt" class="text-xs" data-ctrl-sales-datei />
            </div>
            <button type="button" wire:click="hochladen" class="{{ $btnGhostXs }}" @disabled(! $datei)>Ablegen</button>

            @if(count($dateien))
                <div>
                    <label class="block {{ $label }} mb-1">oder abgelegte Datei</label>
                    <select wire:model.live="dateiname" wire:change="kopfLesen" class="{{ $input }} !w-64">
                        <option value="">– wählen –</option>
                        @foreach($dateien as $d)
                            <option value="{{ $d }}">{{ $d }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        @error('datei')<p class="text-[11px] text-rose-700 mt-1">{{ $message }}</p>@enderror
        @if($hinweis)<p class="text-[11px] text-emerald-700 mt-2" data-ctrl-sales-hinweis>{{ $hinweis }}</p>@endif
        @if($fehler)<p class="text-[11px] text-rose-700 mt-2" data-ctrl-sales-fehler>{{ $fehler }}</p>@endif

        @if($kopf)
            <div class="mt-3 pt-3 border-t border-black/5">
                <p class="{{ $label }} mb-2">Spalten zuordnen</p>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-2">
                    @foreach($felder as $feld)
                        <div>
                            <label class="block text-[10px] text-gray-600 mb-1">
                                {{ ucfirst($feld) }}@if(in_array($feld, ['bezeichnung', 'umsatz', 'datum'], true))<span class="text-rose-600">*</span>@endif
                            </label>
                            <select wire:model="mapping.{{ $feld }}" class="{{ $input }}" data-ctrl-map="{{ $feld }}">
                                <option value="">– keine –</option>
                                @foreach($kopf['spalten'] as $i => $sp)
                                    <option value="{{ $i }}">{{ $sp !== '' ? $sp : 'Spalte ' . ($i + 1) }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>

                @if(count($kopf['beispiel']))
                    <p class="text-[10px] text-gray-500 mt-2">
                        Erste Zeile der Datei: {{ implode(' · ', array_map(fn ($v) => (string) $v, $kopf['beispiel'][0])) }}
                    </p>
                @endif

                <div class="flex items-center gap-2 mt-3">
                    <button type="button" wire:click="trockenlauf" class="{{ $btnGhostXs }} text-violet-600" data-ctrl-sales-dry>Trockenlauf</button>
                    <button type="button" wire:click="scharf" wire:confirm="Import wirklich schreiben?"
                            class="{{ $btnPrimary }}" data-ctrl-sales-apply @disabled(! $bericht)>Import schreiben</button>
                </div>
            </div>
        @endif

        @if($bericht)
            <div class="mt-3 rounded-lg bg-black/[0.03] px-3 py-2" data-ctrl-sales-bericht>
                <p class="text-xs text-gray-900">
                    {{ $bericht['apply'] ? 'Geschrieben' : 'Trockenlauf' }}:
                    <strong>{{ $bericht['gelesen'] }}</strong> Zeilen gelesen —
                    {{ $bericht['neu'] }} neu, {{ $bericht['aktualisiert'] }} aktualisiert,
                    {{ $bericht['uebersprungen'] }} übersprungen.
                    Zuordnung: {{ $bericht['gematcht'] }} getroffen, <strong>{{ $bericht['ungematcht'] }}</strong> offen.
                    Umsatz: {{ $eur($bericht['umsatz']) }}.
                </p>
                @if(count($bericht['fehler']))
                    <ul class="mt-1 space-y-0.5">
                        @foreach($bericht['fehler'] as $f)
                            <li class="text-[10px] text-amber-700">{{ $f }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </div>

    {{-- ── 2. Offene Zuordnungen ───────────────────────────────────────────── --}}
    @if($offen->count())
        <div class="{{ $sectionCard }}" data-ctrl-sales-offen>
            <h4 class="text-xs font-medium text-gray-900">Nicht zugeordnete Verkaufszeilen ({{ $offen->count() }})</h4>
            <p class="text-[11px] text-gray-500 mt-0.5">
                Diese Umsätze sind erfasst, hängen aber an keinem Gericht — sie fehlen darum in der
                Matrix unten. Verworfen wird nichts; eine Handzuordnung überlebt jeden Re-Import.
            </p>
            <div class="overflow-x-auto mt-2">
                <table class="{{ $table }}">
                    <thead>
                        <tr>
                            <th class="{{ $th }} text-left">Bezeichnung aus der Datei</th>
                            <th class="{{ $th }} text-right">Zeilen</th>
                            <th class="{{ $th }} text-right">Umsatz</th>
                            <th class="{{ $th }} text-right">Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($offen as $o)
                            <tr class="{{ $tr }}" wire:key="offen-{{ $o->id }}">
                                <td class="{{ $td }} text-gray-900">{{ $o->raw_label }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500">{{ $o->n }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-700">{{ $eur($o->umsatz) }}</td>
                                <td class="{{ $td }} text-right">
                                    @if($zuordnenId === (int) $o->id)
                                        <div class="flex items-center gap-2 justify-end">
                                            <input type="text" wire:model.live.debounce.300ms="zuordnenSuche"
                                                   placeholder="Gericht suchen…" class="{{ $input }} !w-48" />
                                            <button type="button" wire:click="zuordnenAbbrechen" class="{{ $btnGhostXs }}">×</button>
                                        </div>
                                        @if(count($treffer))
                                            <div class="mt-1 flex flex-wrap gap-1 justify-end">
                                                @foreach($treffer as $t)
                                                    <button type="button" wire:click="zuordnen({{ $t['id'] }})"
                                                            class="{{ $btnGhostXs }} text-violet-600">{{ $t['name'] }}</button>
                                                @endforeach
                                            </div>
                                        @endif
                                    @else
                                        <button type="button" wire:click="zuordnenOeffnen({{ $o->id }})"
                                                class="{{ $btnGhostXs }}" data-ctrl-zuordnen="{{ $o->id }}">zuordnen</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ── 3. Menu-Engineering ─────────────────────────────────────────────── --}}
    <div class="{{ $sectionCard }}" data-ctrl-matrix>
        <div class="flex items-end justify-between gap-3 flex-wrap">
            <div>
                <h4 class="text-xs font-medium text-gray-900">Menu-Engineering</h4>
                <p class="text-[11px] text-gray-500 mt-0.5">
                    Jedes Gericht gegen den Durchschnitt des Portfolios: Popularität waagerecht,
                    Deckungsbeitrag senkrecht.
                    @if($matrix && $matrix['quelle'] === 'feedback')
                        <span class="text-amber-700">
                            Popularität kommt aus dem Praxis-Feedback, nicht aus Verkaufszahlen —
                            das ist Akzeptanz, nicht Absatz. Sobald Verkaufs-Ist eingelesen ist, zählt das.
                        </span>
                    @endif
                </p>
            </div>
            <div class="flex items-end gap-2">
                <div>
                    <label class="block text-[10px] text-gray-600 mb-1">von</label>
                    <input type="date" wire:model.live="von" class="{{ $input }} !w-36" />
                </div>
                <div>
                    <label class="block text-[10px] text-gray-600 mb-1">bis</label>
                    <input type="date" wire:model.live="bis" class="{{ $input }} !w-36" />
                </div>
            </div>
        </div>

        @if($matrix === null || $matrix['n'] === 0)
            <p class="text-xs text-gray-500 mt-3">
                Noch keine Matrix: dafür braucht es Gerichte mit Preis UND einem Popularitäts-Signal
                (Verkaufs-Ist oder Praxis-Feedback) im gewählten Zeitraum.
            </p>
        @else
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-3">
                @foreach($quadLabel as $key => $lab)
                    <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                        <div class="{{ $label }}">{{ $lab }}</div>
                        <div class="text-lg font-semibold tabular-nums text-gray-900">{{ $matrix['quadranten'][$key] }}</div>
                    </div>
                @endforeach
            </div>

            <p class="text-[10px] text-gray-500 mt-2">
                {{ $matrix['n'] }} Gerichte · Ø Deckungsbeitrag {{ $eur($matrix['avg_db']) }} ·
                Ø Popularität {{ number_format((float) $matrix['avg_pop'], 2, ',', '.') }}
                @if($matrix['quelle'] === 'sales') · Umsatz im Zeitraum {{ $eur($matrix['umsatz']) }} @endif
            </p>

            <div class="overflow-x-auto mt-2">
                <table class="{{ $table }}">
                    <thead>
                        <tr>
                            <th class="{{ $th }} text-left">Gericht</th>
                            <th class="{{ $th }} text-left">Quadrant</th>
                            <th class="{{ $th }} text-right">Popularität</th>
                            <th class="{{ $th }} text-right">VK</th>
                            <th class="{{ $th }} text-right">DB</th>
                            <th class="{{ $th }} text-right">W%</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($matrix['zeilen'] as $z)
                            <tr class="{{ $tr }}" wire:key="me-{{ $z['recipe_id'] }}">
                                <td class="{{ $td }} text-gray-900">{{ $z['name'] }}</td>
                                <td class="{{ $td }}"><span class="{{ $pill }} {{ $quadPill[$z['quadrant']] }}">{{ $quadLabel[$z['quadrant']] }}</span></td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-700">{{ number_format((float) $z['popularitaet'], 2, ',', '.') }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-700">{{ $eur($z['sales_net']) }}</td>
                                <td class="{{ $td }} text-right tabular-nums font-medium text-gray-900">{{ $eur($z['db_eur']) }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500">{{ $z['wareneinsatz_pct'] !== null ? number_format((float) $z['wareneinsatz_pct'], 1, ',', '.') . ' %' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
