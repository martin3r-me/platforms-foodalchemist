{{-- M7-08: KI-Settings — Provider, Tiering, Nutzung, Kill-Switch --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-5" data-settings-ki>
    <div class="flex items-start justify-between gap-4">
        <button type="button" wire:click="umschalten"
                class="{{ $kiAktiv ? $btnGhost : $btnPrimary }} shrink-0 {{ $kiAktiv ? 'text-rose-600' : '' }}"
                data-ki-kill-switch>
            {{-- Icon steht VOR dem Ausdruck: eine Icon-Direktive in einer Echo-Klammer kompiliert nicht. --}}
            @svg('heroicon-o-power', 'w-4 h-4')
            {{ $kiAktiv ? 'KI deaktivieren (Kill-Switch)' : 'KI wieder aktivieren' }}
        </button>
    </div>

    @if($meldung !== null)
        <p class="text-xs {{ $kiAktiv ? 'text-emerald-600' : 'text-rose-600' }}" data-ki-meldung>{{ $meldung }}</p>
    @endif
    @if(! $kiAktiv)
        <x-foodalchemist::alert tone="danger" data-ki-aus-banner>
            Kill-Switch aktiv — jeder KI-Call dieses Teams wird im Gateway gestoppt (@svg('heroicon-o-sparkles', 'w-3.5 h-3.5 inline-block align-middle')-Buttons laufen ins Leere und melden es).
        </x-foodalchemist::alert>
    @endif

    <div>
        <p class="{{ $dt }} mb-1">Tier-Zuordnung (V-01 — je Prompt, Registry)</p>
        <div class="flex flex-wrap gap-1" data-ki-tiers>
            @foreach($registry as $key => $tier)
                <span class="{{ $pill }} {{ ['A' => $variantPill['primary'], 'B' => $variantPill['secondary'], 'C' => $variantPill['info'], 'D' => $variantPill['warning']][$tier] ?? $variantPill['secondary'] }}" wire:key="tier-{{ $key }}">{{ $key }} · {{ $tier }}</span>
            @endforeach
        </div>
        <p class="text-[10px] text-gray-500 mt-1">Tier→Modell: @foreach($tiers as $t => $m) {{ $t }}={{ $m ?? 'Plattform-Default' }} @endforeach</p>
        <p class="text-[10px] text-gray-500 mt-0.5">
            Tatsächlich im Zeitraum: {{ $aktiveModelle->isEmpty() ? 'keine Calls' : $aktiveModelle->implode(', ') }}
            · Registry: {{ $registry->count() }} Prompts
        </p>
        @if($registryLuecken->isNotEmpty())
            <p class="text-[10px] text-amber-600 mt-0.5" data-ki-registry-luecken>
                Im Log, aber nicht in der Prompt-Registry: {{ $registryLuecken->implode(', ') }}
            </p>
        @endif
    </div>

    <div>
        <div class="flex items-center justify-between gap-3 mb-1 flex-wrap">
            <p class="{{ $dt }}">Nutzung (ai_call_log, dieses Team)</p>
            <label class="flex items-center gap-2 text-[11px] text-gray-500">Zeitraum
                <select wire:model.live="zeitraum" class="{{ $input }} !w-40 !py-1">
                    @foreach($zeitraumOptionen as $val => $lbl)
                        <option value="{{ $val }}">{{ $lbl }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        @if($statistik->isEmpty())
            <p class="text-xs text-gray-500">Keine Calls im gewählten Zeitraum.</p>
        @else
            <table class="{{ $table }}" data-ki-statistik>
                <thead><tr class="text-left">
                    @foreach(['Feature', 'Tier', 'Modell', 'Calls', 'Tokens in', 'davon Cache', 'Tokens out', 'Fehler', 'Accepted', '≈ Kosten'] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach
                </tr></thead>
                <tbody>
                    @foreach($statistik as $z)
                        @php($kostenKey = $z->feature . '|' . ($z->tier ?? '') . '|' . ($z->model ?? ''))
                        <tr class="{{ $tr }}" wire:key="st-{{ $z->feature }}-{{ $z->tier }}-{{ $z->model ?? 'ohne-modell' }}">
                            <td class="{{ $td }} font-mono text-[11px]">{{ $z->feature }}</td>
                            <td class="{{ $td }}">{{ $z->tier }}</td>
                            <td class="{{ $td }} font-mono text-[10px] whitespace-nowrap">{{ $z->model ?? '—' }}</td>
                            <td class="{{ $td }}">{{ number_format($z->calls, 0, ',', '.') }}</td>
                            <td class="{{ $td }} text-gray-600">{{ number_format($z->t_in, 0, ',', '.') }}</td>
                            <td class="{{ $td }} text-gray-600">{{ number_format($z->t_cached, 0, ',', '.') }}</td>
                            <td class="{{ $td }} text-gray-600">{{ number_format($z->t_out, 0, ',', '.') }}</td>
                            <td class="{{ $td }} {{ $z->errors > 0 ? 'text-rose-500' : 'text-gray-600' }}">{{ $z->errors }}</td>
                            <td class="{{ $td }} text-gray-600">{{ $z->accepted }}</td>
                            <td class="{{ $td }} text-right tabular-nums whitespace-nowrap" data-ki-kosten>
                                @if(($kosten[$kostenKey] ?? null) === null) — @else {{ number_format($kosten[$kostenKey], 4, ',', '.') }} {{ $kostenSymbol }} @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-black/10">
                        <td colspan="9" class="{{ $td }} text-right text-[11px] text-gray-500">
                            ≈ Gesamt (tatsächliches Modell × offizielle Standard-Preise, {{ $kostenWaehrung }}; ohne Steuer)
                            @if($kostenUnbekannt > 0) · {{ $kostenUnbekannt }} Gruppe(n) ohne bekannten Preis nicht enthalten @endif
                        </td>
                        <td class="{{ $td }} text-right font-medium tabular-nums whitespace-nowrap" data-ki-kosten-gesamt>{{ number_format($kostenGesamt, 2, ',', '.') }} {{ $kostenSymbol }}</td>
                    </tr>
                </tfoot>
            </table>
            <p class="text-[10px] text-gray-500 mt-1">
                Historische Input-Tokens ohne gespeicherten Cache-Anteil werden vorsichtig zum vollen Inputpreis geschätzt. Ab diesem Stand wird der Cache separat erfasst.
            </p>
        @endif
    </div>
</div>
