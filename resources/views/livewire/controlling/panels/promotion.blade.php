{{-- Spec 33 P6 — Umsatz je laufender Ausgabe. Die Vorbehalte stehen gleichrangig neben den
     Zahlen, nicht als Fußnote: sonst liest sich die Liste genauer, als sie ist. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($eur = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', '.') . ' €')

<div class="space-y-3" data-ctrl-promotion>
    <div class="flex items-end justify-between gap-3 flex-wrap">
        <p class="text-[11px] text-gray-500 max-w-2xl">
            Umsatz je laufender Ausgabe, jeweils in ihrem eigenen Gültigkeitsfenster. Grundlage
            ist das Verkaufsjournal — ohne eingelesenes Verkaufs-Ist bleibt die Liste leer.
        </p>
        <div class="flex items-end gap-2">
            <div>
                <label class="{{ $label }} block mb-1">Stichtag</label>
                <input type="date" wire:model.live="stichtag" class="{{ $input }} !w-40" data-ctrl-promo-stichtag />
            </div>
            <button type="button" wire:click="heute" class="{{ $btnGhostXs }}">Heute</button>
        </div>
    </div>

    @if($p === null)
        <p class="text-xs text-gray-500">Kein Team zugeordnet.</p>
    @else
        <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
            <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                <div class="{{ $label }}">Umsatz gesamt</div>
                <div class="text-lg font-semibold tabular-nums text-gray-900">{{ $eur($p['umsatz_gesamt']) }}</div>
            </div>
            <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                <div class="{{ $label }}">davon zugeordnet</div>
                <div class="text-lg font-semibold tabular-nums text-gray-900">{{ $eur($p['umsatz_zugeordnet']) }}</div>
                <div class="text-[10px] text-gray-500">
                    {{ $p['abdeckung_pct'] === null ? '—' : number_format($p['abdeckung_pct'], 1, ',', '.') . ' % an einem Gericht' }}
                </div>
            </div>
            <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                <div class="{{ $label }}">Laufende Ausgaben</div>
                <div class="text-lg font-semibold tabular-nums text-gray-900">{{ count($p['zeilen']) }}</div>
            </div>
            <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                <div class="{{ $label }}">Stand</div>
                <div class="text-lg font-semibold tabular-nums text-gray-900">{{ \Illuminate\Support\Carbon::parse($p['stichtag'])->format('d.m.Y') }}</div>
            </div>
        </div>

        @if($p['hinweis'])
            <p class="text-[11px] text-amber-700" data-ctrl-promo-hinweis>{{ $p['hinweis'] }}</p>
        @endif

        @if(count($p['zeilen']))
            <div class="overflow-x-auto">
                <table class="{{ $table }}">
                    <thead>
                        <tr>
                            <th class="{{ $th }} text-left">Ausgabe</th>
                            <th class="{{ $th }} text-left">Art</th>
                            <th class="{{ $th }} text-left">Zuordnung</th>
                            <th class="{{ $th }} text-right">Gerichte</th>
                            <th class="{{ $th }} text-right">Menge</th>
                            <th class="{{ $th }} text-right">Umsatz</th>
                            <th class="{{ $th }} text-right">davon exklusiv</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($p['zeilen'] as $z)
                            <tr class="{{ $tr }}" wire:key="promo-{{ $z['art'] }}-{{ $z['id'] }}">
                                <td class="{{ $td }}"><a href="{{ $z['route'] }}" wire:navigate class="text-violet-700 hover:underline">{{ $z['name'] }}</a></td>
                                <td class="{{ $td }} text-gray-600">{{ $z['art_label'] }}</td>
                                <td class="{{ $td }} text-gray-600">{{ $z['outlet_name'] ?? $z['kunde'] ?? '—' }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500">
                                    {{ $z['n_gerichte'] }}
                                    @if($z['n_gerichte_exklusiv'] < $z['n_gerichte'])
                                        <span class="text-[10px] text-amber-700" title="Der Rest steckt auch in einer anderen laufenden Ausgabe">({{ $z['n_gerichte_exklusiv'] }} exkl.)</span>
                                    @endif
                                </td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500">{{ number_format((float) $z['menge'], 0, ',', '.') }}</td>
                                <td class="{{ $td }} text-right tabular-nums font-medium text-gray-900">{{ $eur($z['umsatz']) }}</td>
                                {{-- Der exklusive Anteil sagt, wie belastbar die Zahl links ist. --}}
                                <td class="{{ $td }} text-right tabular-nums {{ ($z['exklusiv_pct'] ?? 100) < 100 ? 'text-amber-700' : 'text-gray-500' }}">
                                    {{ $eur($z['umsatz_exklusiv']) }}
                                    @if($z['exklusiv_pct'] !== null)
                                        <span class="text-[10px]">({{ number_format($z['exklusiv_pct'], 0, ',', '.') }} %)</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="text-[10px] text-gray-500">
                Ein Gericht kann in mehreren laufenden Ausgaben stehen — sein Umsatz zählt dann
                bei beiden. Die Summe dieser Spalte ist deshalb <strong>größer</strong> als der
                Gesamtumsatz oben; das ist kein Rechenfehler, sondern die Natur der Frage.
                Die Spalte „davon exklusiv" nennt den Teil, der eindeutig dieser Ausgabe gehört.
            </p>
        @endif
    @endif
</div>
