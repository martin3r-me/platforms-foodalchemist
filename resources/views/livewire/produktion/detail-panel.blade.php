{{-- Spec 18 — Produktion: Cockpit-DetailPanel (v3-Design wie Recipes/Verkauf/Concepter) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="p-4 space-y-4 min-h-full bg-gray-500/[0.04]" data-produktion-panel>
    @if($detail === null)
        <div class="text-center text-xs text-gray-500 py-12">
            <div class="text-2xl mb-2">⌘</div>
            Produktionsauftrag in der Tabelle anklicken —<br>Details erscheinen hier.
        </div>
    @else
        <div>
            <div class="flex items-start justify-between gap-2">
                <h3 class="text-base font-semibold tracking-tight text-gray-900 leading-snug">{{ \Illuminate\Support\Carbon::parse($detail['production_date'])->format('d.m.Y') }}</h3>
                <div class="flex items-center gap-1.5 shrink-0">
                    @if($detail['editierbar'])
                        <button type="button" wire:click="$dispatch('produktion-editor.bearbeiten', { id: {{ $detail['id'] }} })" class="{{ $btnGhostXs }}" data-produktion-bearbeiten>@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5') Bearbeiten</button>
                    @endif
                    <span class="{{ $pill }} font-medium {{ $variantPill[\Platform\FoodAlchemist\Enums\ProductionOrderStatus::from($detail['status'])->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $detail['status_label'] }}</span>
                </div>
            </div>
            @if($detail['reference'])<p class="text-[11px] text-gray-500 mt-1.5">{{ $detail['reference'] }}</p>@endif
        </div>

        @if($hinweis)<div class="{{ $sectionCard }} !bg-emerald-500/[0.06] !border-emerald-500/20 text-[12px] text-emerald-700">✓ {{ $hinweis }}</div>@endif
        @if($fehler)<div class="{{ $sectionCard }} !bg-rose-500/[0.06] !border-rose-500/20 text-[12px] text-rose-700">{{ $fehler }}</div>@endif

        {{-- Cockpit-KPI-Karte --}}
        <div class="relative overflow-hidden {{ $card }} px-3.5 py-2.5" data-kpi-karte>
            <div class="{{ $cardAccent }}"></div>
            <div class="flex flex-wrap gap-x-5 gap-y-1 text-xs">
                <span class="text-gray-500">Ansätze gesamt <span class="text-gray-900 font-medium tabular-nums">{{ rtrim(rtrim(number_format($detail['ansaetze_gesamt'], 2, ',', '.'), '0'), ',') }}</span></span>
                <span class="text-gray-500">Rezepte <span class="text-gray-900 font-medium tabular-nums">{{ count($detail['zeilen']) }}</span></span>
                <span class="text-gray-500">Portionen <span class="text-gray-900 font-medium tabular-nums">{{ $detail['portionen_gesamt'] }}</span></span>
                <span class="text-gray-500">Arbeitszeit <span class="text-gray-900 font-medium tabular-nums">{{ $detail['arbeitszeit_gesamt_min'] }} min</span></span>
            </div>
        </div>

        @if($detail['editierbar'] && count($erlaubteStatus) > 0)
            <div class="flex flex-wrap gap-1.5">
                @foreach($erlaubteStatus as $z)
                    <button type="button" wire:click="setStatus('{{ $z->value }}')"
                        class="{{ $z->value === 'in_progress' ? $btnPrimary : $btnGhost }}"
                        @if($z->value === 'cancelled') onclick="return confirm('Produktion stornieren?')" @endif
                        data-produktion-status="{{ $z->value }}">{{ $z->value === 'in_progress' ? 'Produktion starten' : $z->label() }}</button>
                @endforeach
            </div>
        @endif

        <x-foodalchemist::section title="Rezepte & Ansätze" icon="heroicon-o-list-bullet" :meta="count($detail['zeilen'])">
            <div class="space-y-2">
                @foreach($detail['zeilen'] as $z)
                    <div class="border-b border-black/5 last:border-0 pb-2" wire:key="pol-{{ $z['id'] }}">
                        <div class="flex items-baseline justify-between gap-2 text-[13px]">
                            <span class="font-medium text-gray-900">{{ $z['name'] }}</span>
                            <span class="text-gray-500 tabular-nums shrink-0">
                                {{ rtrim(rtrim(number_format($z['ansaetze'], 2, ',', '.'), '0'), ',') }} Ansätze
                                @if($z['portionen'] !== null) · {{ $z['portionen'] }} Port. @endif
                                @if($z['produzierte_menge_kg'] !== null) · {{ number_format($z['produzierte_menge_kg'], 2, ',', '.') }} kg @endif
                            </span>
                        </div>
                        @if($z['zubereitung'])<p class="text-[12px] text-gray-600 mt-0.5">{{ $z['zubereitung'] }}</p>@endif
                        @if($z['darreichung'])
                            <p class="text-[11px] text-gray-500 mt-0.5">
                                @foreach($z['darreichung'] as $k => $v)<span class="mr-2">{{ $k }}: {{ $v }}</span>@endforeach
                            </p>
                        @endif
                        @if($detail['editierbar'])
                            <input type="text" value="{{ $z['note'] }}" placeholder="Küchen-Notiz …"
                                wire:change="updateLineNote({{ $z['id'] }}, $event.target.value)"
                                class="{{ $input }} !py-1 mt-1" data-produktion-notiz="{{ $z['id'] }}" />
                        @elseif($z['note'])
                            <p class="text-[11px] text-violet-600 mt-1">📝 {{ $z['note'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-foodalchemist::section>

        @if($verknuepfteOrders->isNotEmpty())
            <x-foodalchemist::section title="Bestellung" icon="heroicon-o-shopping-cart" :meta="$verknuepfteOrders->count()">
                <div class="space-y-1">
                    @foreach($verknuepfteOrders as $o)
                        <a href="{{ route('foodalchemist.orders.index', ['o' => $o->id]) }}"
                           class="flex items-center justify-between gap-2 text-[13px] px-2 py-1.5 rounded-lg bg-black/[0.02] hover:bg-black/[0.04]"
                           data-produktion-bestellung-link="{{ $o->id }}">
                            <span class="text-gray-900">{{ $o->supplier?->name ?? '—' }}</span>
                            <span class="flex items-center gap-2">
                                <span class="text-gray-500 tabular-nums">{{ number_format((float) $o->total_net, 2, ',', '.') }} €</span>
                                <span class="{{ $pill }} font-medium {{ $variantPill[$o->status->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $o->status->label() }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </x-foodalchemist::section>
        @endif

        @if(count($detail['warnungen']) > 0)
            <x-foodalchemist::section title="Warnungen" icon="heroicon-o-exclamation-triangle">
                @foreach($detail['warnungen'] as $w)
                    <x-foodalchemist::alert tone="warning">{{ $w }}</x-foodalchemist::alert>
                @endforeach
            </x-foodalchemist::section>
        @endif

        <div class="flex flex-wrap gap-2 pt-2 border-t border-black/5">
            @if($detail['editierbar'])
                <button type="button" wire:click="anBestellungUebergeben" class="{{ $btnGhost }}" data-produktion-uebergeben>→ An Bestellung übergeben</button>
            @endif
            @if(\Illuminate\Support\Facades\Route::has('foodalchemist.produktion.auftraege.dokument'))
                <a href="{{ route('foodalchemist.produktion.auftraege.dokument', ['order' => $detail['id']]) }}" target="_blank" class="{{ $btnGhost }}">🖨 Produktionsschein</a>
            @endif
        </div>
    @endif
</div>
