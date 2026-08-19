{{-- Produktion: rechtes Glance-Cockpit --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="p-4 space-y-4 min-h-full bg-[var(--ui-muted-5)]" data-produktion-panel>
    @if($detail === null)
        <div class="text-center text-xs text-gray-500 py-12">
            <div class="text-2xl mb-2">⌘</div>
            Produktionsauftrag in der Tabelle anklicken —<br>Details erscheinen hier.
        </div>
    @else
        @php($zieleCount = count($detail['targets']))
        @php($warnCount = count($detail['warnungen']) + count($kapazitaetsWarnungen))
        @php($postenBelegt = collect($postenSummen)->filter(fn ($p) => $p['station_id'] !== null)->count())
        @php($status = \Platform\FoodAlchemist\Enums\ProductionOrderStatus::from($detail['status']))

        <div class="space-y-1">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <h3 class="text-base font-semibold tracking-tight text-gray-900 leading-snug truncate">{{ $detail['name'] ?: \Illuminate\Support\Carbon::parse($detail['production_date'])->format('d.m.Y') }}</h3>
                    <p class="text-[11px] text-gray-500 mt-1">{{ \Illuminate\Support\Carbon::parse($detail['production_date'])->format('d.m.Y') }}@if($detail['reference']) · {{ $detail['reference'] }}@endif</p>
                </div>
                <span class="{{ $pill }} font-medium {{ $variantPill[$status->badgeVariant()] ?? $variantPill['secondary'] }} shrink-0">{{ $detail['status_label'] }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                @if($detail['editierbar'])
                    <button type="button" wire:click="$dispatch('produktion-editor.bearbeiten', { id: {{ $detail['id'] }} })" class="{{ $btnGhostXs }}" data-produktion-bearbeiten>@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5') Bearbeiten</button>
                @endif
            </div>
        </div>

        @if($hinweis)<div class="rounded-lg bg-[var(--ui-surface)] border border-emerald-500/20 px-3 py-2 text-[12px] text-emerald-700">✓ {{ $hinweis }}</div>@endif
        @if($fehler)<div class="rounded-lg bg-[var(--ui-surface)] border border-rose-500/20 px-3 py-2 text-[12px] text-rose-700">{{ $fehler }}</div>@endif

        <div class="rounded-lg bg-[var(--ui-surface)] border border-[var(--ui-border)] px-3.5 py-3" data-kpi-karte>
            <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                <span class="text-gray-500">Ansätze <span class="text-gray-900 font-semibold tabular-nums">{{ rtrim(rtrim(number_format($detail['ansaetze_gesamt'], 2, ',', '.'), '0'), ',') }}</span></span>
                <span class="text-gray-500">Rezepte <span class="text-gray-900 font-semibold tabular-nums">{{ count($detail['zeilen']) }}</span></span>
                <span class="text-gray-500">Portionen <span class="text-gray-900 font-semibold tabular-nums">{{ $detail['portionen_gesamt'] }}</span></span>
                <span class="text-gray-500">Zeit <span class="text-gray-900 font-semibold tabular-nums">{{ $detail['arbeitszeit_gesamt_min'] }} min</span></span>
            </div>

            @if($detail['status'] !== 'planned' && $detail['fortschritt']['gesamt'] > 0)
                @php($fs = $detail['fortschritt'])
                <div class="mt-3" data-panel-fortschritt>
                    <div class="flex items-baseline justify-between text-[11px] mb-1">
                        <span class="text-gray-500">Fortschritt</span>
                        <span class="text-gray-700 tabular-nums">{{ $fs['erledigt'] }}/{{ $fs['gesamt'] }} erledigt</span>
                    </div>
                    <x-foodalchemist::meter :value="$fs['prozent']" :max="100" :tone="$fs['alle_erledigt'] ? 'success' : 'info'" />
                </div>
            @endif
        </div>

        @if($detail['is_owned'] && count($erlaubteStatus) > 0)
            @php($statusAktion = ['in_progress' => 'Produktion starten', 'done' => 'Fertig melden', 'cancelled' => 'Stornieren'])
            <div class="flex flex-wrap gap-1.5">
                @foreach($erlaubteStatus as $z)
                    <button type="button" wire:click="setStatus('{{ $z->value }}')"
                        class="{{ in_array($z->value, ['in_progress', 'done'], true) ? $btnPrimary : $btnGhost }}"
                        @if($z->value === 'cancelled')
                            onclick="return confirm('Produktion stornieren? Offene Einkaufsentwürfe werden neu berechnet; bereits ausgelöste Bestellungen bleiben als Klärfall bestehen.')"
                        @elseif($z->value === 'done' && $detail['fortschritt']['offen'] + $detail['fortschritt']['in_arbeit'] > 0)
                            onclick="return confirm('{{ $detail['fortschritt']['offen'] + $detail['fortschritt']['in_arbeit'] }} Zeile(n) sind noch nicht abgehakt. Trotzdem fertig melden?')"
                            data-produktion-done-offen="{{ $detail['fortschritt']['offen'] + $detail['fortschritt']['in_arbeit'] }}"
                        @endif
                        data-produktion-status="{{ $z->value }}">{{ $statusAktion[$z->value] ?? $z->label() }}</button>
                @endforeach
            </div>
        @endif

        <div class="rounded-lg bg-[var(--ui-surface)] border border-[var(--ui-border)] divide-y divide-[var(--ui-border)] text-[13px]" data-panel-glance>
            <div class="flex items-center justify-between gap-2 px-3 py-2">
                <span class="text-gray-500">@svg('heroicon-o-flag', 'w-3.5 h-3.5 inline-block align-[-2px] mr-1') Ziele</span>
                <span class="text-gray-900 tabular-nums">{{ $zieleCount }}</span>
            </div>
            <div class="flex items-center justify-between gap-2 px-3 py-2">
                <span class="text-gray-500">@svg('heroicon-o-archive-box-arrow-down', 'w-3.5 h-3.5 inline-block align-[-2px] mr-1') Materialbedarf</span>
                <span class="flex items-center gap-1.5">
                    @if(! empty($detail['procurement_stale']))
                        <span class="{{ $pill }} {{ $variantPill['warning'] }}" data-materialbedarf-status="geaendert">geändert</span>
                    @elseif(! empty($detail['procurement_released_at']))
                        <span class="{{ $pill }} {{ $variantPill['success'] }}" data-materialbedarf-status="freigegeben">freigegeben</span>
                    @else
                        <span class="{{ $pill }} {{ $variantPill['secondary'] }}" data-materialbedarf-status="entwurf">Entwurf</span>
                    @endif
                </span>
            </div>
            <div class="flex items-center justify-between gap-2 px-3 py-2">
                <span class="text-gray-500">@svg('heroicon-o-users', 'w-3.5 h-3.5 inline-block align-[-2px] mr-1') Posten</span>
                <span class="text-gray-900 tabular-nums">{{ $postenBelegt }} belegt · {{ count($postenSummen) }} Gruppen</span>
            </div>
            <div class="flex items-center justify-between gap-2 px-3 py-2">
                <span class="text-gray-500">@svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5 inline-block align-[-2px] mr-1') Warnungen</span>
                <span class="{{ $pill }} font-medium {{ $warnCount > 0 ? $variantPill['warning'] : $variantPill['success'] }}">{{ $warnCount }}</span>
            </div>
        </div>

        @if($warnCount > 0)
            <div class="space-y-1" data-panel-warnungen>
                @foreach(array_slice($detail['warnungen'], 0, 2) as $w)
                    <x-foodalchemist::alert tone="warning">{{ $w }}</x-foodalchemist::alert>
                @endforeach
                @foreach(array_slice($kapazitaetsWarnungen, 0, 2) as $w)
                    <x-foodalchemist::alert tone="warning" data-panel-kapazitaet>{{ $w }}</x-foodalchemist::alert>
                @endforeach
            </div>
        @endif

        @if(! empty($detail['procurement_cancel_warning']))
            <div class="space-y-2" data-produktion-storno-einkauf>
                <x-foodalchemist::alert tone="warning">Produktion storniert, aber mindestens eine Bestellung wurde bereits ausgelöst. Bitte die Lieferanten informieren und die Belege anschließend als storniert bestätigen.</x-foodalchemist::alert>
                <div class="flex flex-wrap gap-1.5">
                    @foreach(collect($detail['verknuepfte_orders'])->whereIn('status', ['sent', 'confirmed']) as $linkedOrder)
                        @if($linkedOrder['cancellation_mailto'])
                            <a href="{{ $linkedOrder['cancellation_mailto'] }}" class="{{ $btnGhostXs }} text-rose-500">@svg('heroicon-o-envelope', 'w-3.5 h-3.5') {{ $linkedOrder['cancellation_kind'] === 'partial' ? 'Änderung' : 'Storno' }} an {{ $linkedOrder['supplier'] }}</a>
                        @else
                            <span class="{{ $btnGhostXs }} opacity-40 cursor-not-allowed" title="Beim Lieferanten fehlt die Bestell-E-Mail">@svg('heroicon-o-envelope', 'w-3.5 h-3.5') {{ $linkedOrder['supplier'] }}</span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        <div class="flex flex-wrap gap-2 pt-2 border-t border-[var(--ui-border)]">
            @if($detail['is_owned'] && in_array($detail['status'], ['planned', 'in_progress'], true))
                <button type="button" wire:click="materialbedarfFreigeben" class="{{ $btnGhost }}" data-materialbedarf-freigeben>@svg('heroicon-o-check-circle', 'w-3.5 h-3.5') {{ $detail['procurement_released_at'] ? 'Bedarf erneut freigeben' : 'Materialbedarf freigeben' }}</button>
            @endif
            @if($detail['procurement_released_at'])
                <a href="{{ route('foodalchemist.orders.index', ['sicht' => 'bedarfe', 'p' => $detail['id']]) }}" class="{{ $btnGhost }}">Im Einkauf öffnen</a>
            @endif
            @if(\Illuminate\Support\Facades\Route::has('foodalchemist.produktion.auftraege.dokument'))
                <a href="{{ route('foodalchemist.produktion.auftraege.dokument', ['order' => $detail['id'], 'profil' => 'produktion']) }}" target="_blank" class="{{ $btnGhost }}" title="Produktionsdokument zusammenstellen" data-produktion-panel-dokument>@svg('heroicon-o-document-text', 'w-3.5 h-3.5 inline-block align-middle') Dokument</a>
            @endif
        </div>
    @endif
</div>
