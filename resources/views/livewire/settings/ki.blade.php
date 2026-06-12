{{-- M7-08: KI-Settings — Provider, Tiering, Nutzung, Kill-Switch --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-5" data-settings-ki>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">KI</h3>
            <p class="text-xs text-gray-400 mt-0.5">Provider: <span class="font-mono">{{ $provider }}</span>{{ $fallbackModel ? " · Fallback-Modell: {$fallbackModel}" : '' }} — Modell-Strings sind Deployment-Config (06_KI).</p>
        </div>
        <button type="button" wire:click="umschalten"
                class="{{ $kiAktiv ? $btnGhost : $btnPrimary }} shrink-0 {{ $kiAktiv ? 'text-rose-600 dark:text-rose-400' : '' }}"
                data-ki-kill-switch>
            {{ $kiAktiv ? '⏻ KI deaktivieren (Kill-Switch)' : '⏻ KI wieder aktivieren' }}
        </button>
    </div>

    @if($meldung !== null)
        <p class="text-sm {{ $kiAktiv ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}" data-ki-meldung>{{ $meldung }}</p>
    @endif
    @if(! $kiAktiv)
        <div class="rounded-lg bg-rose-500/10 border border-rose-500/30 px-3 py-2 text-sm text-rose-700 dark:text-rose-300" data-ki-aus-banner>
            Kill-Switch aktiv — jeder KI-Call dieses Teams wird im Gateway gestoppt (✨-Buttons laufen ins Leere und melden es).
        </div>
    @endif

    <div>
        <p class="{{ $dt }} mb-1">Tier-Zuordnung (V-01 — je Prompt, Registry)</p>
        <div class="flex flex-wrap gap-1" data-ki-tiers>
            @foreach($registry as $key => $tier)
                <span class="{{ $pill }} {{ ['A' => $variantPill['primary'], 'B' => $variantPill['secondary'], 'C' => $variantPill['info'], 'D' => $variantPill['warning']][$tier] ?? $variantPill['secondary'] }}" wire:key="tier-{{ $key }}">{{ $key }} · {{ $tier }}</span>
            @endforeach
        </div>
        <p class="text-[10px] text-gray-400 mt-1">Tier→Modell: @foreach($tiers as $t => $m) {{ $t }}={{ $m ?? 'Plattform-Default' }} @endforeach</p>
    </div>

    <div>
        <p class="{{ $dt }} mb-1">Nutzung (ai_call_log, dieses Team)</p>
        @if($statistik->isEmpty())
            <p class="text-sm text-gray-400">Noch keine Calls geloggt.</p>
        @else
            <table class="{{ $table }}" data-ki-statistik>
                <thead><tr class="text-left">
                    @foreach(['Feature', 'Tier', 'Calls', 'Tokens in', 'Tokens out', 'Fehler', 'Accepted', '≈ Kosten'] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach
                </tr></thead>
                <tbody>
                    @foreach($statistik as $z)
                        <tr class="{{ $tr }}" wire:key="st-{{ $z->feature }}-{{ $z->tier }}">
                            <td class="{{ $td }} font-mono text-xs">{{ $z->feature }}</td>
                            <td class="{{ $td }}">{{ $z->tier }}</td>
                            <td class="{{ $td }}">{{ number_format($z->calls, 0, ',', '.') }}</td>
                            <td class="{{ $td }} text-gray-500">{{ number_format($z->t_in, 0, ',', '.') }}</td>
                            <td class="{{ $td }} text-gray-500">{{ number_format($z->t_out, 0, ',', '.') }}</td>
                            <td class="{{ $td }} {{ $z->fehler > 0 ? 'text-rose-500' : 'text-gray-500' }}">{{ $z->fehler }}</td>
                            <td class="{{ $td }} text-gray-500">{{ $z->accepted }}</td>
                            <td class="{{ $td }} text-right tabular-nums" data-ki-kosten>{{ number_format($kosten[$z->feature . '|' . $z->tier] ?? 0, 4, ',', '.') }} €</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-black/10 dark:border-white/10">
                        <td colspan="7" class="{{ $td }} text-right text-xs text-gray-400">≈ Gesamt (Tokens × Tier-Preis, Deployment-Config <code>ai.kosten_pro_mio</code>)</td>
                        <td class="{{ $td }} text-right font-medium tabular-nums" data-ki-kosten-gesamt>{{ number_format($kostenGesamt, 2, ',', '.') }} €</td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>
</div>
