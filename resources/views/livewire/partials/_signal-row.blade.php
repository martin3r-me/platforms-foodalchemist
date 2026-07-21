{{-- Veredelte Signal-Zeile (Cockpit) — genutzt im Überblick + Signale-Tab (DRY).
     Erwartet: $sig (FoodAlchemistSignal), $kiPanelId (int|null). Reine Darstellung. --}}
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
    $ki = \Platform\FoodAlchemist\Support\SignalCockpit::kiAffordance($sig->type->value);
    $sevMap = [
        'kritisch' => ['bar' => 'bg-rose-500',  'tint' => 'bg-rose-500/10 text-rose-600',  'text' => 'text-rose-600'],
        'warnung'  => ['bar' => 'bg-amber-400', 'tint' => 'bg-amber-500/10 text-amber-600', 'text' => 'text-amber-600'],
        'info'     => ['bar' => 'bg-sky-400',   'tint' => 'bg-sky-500/10 text-sky-600',     'text' => 'text-sky-600'],
    ];
    $sv = $sevMap[$sig->severity->value] ?? $sevMap['info'];
    $pl = is_array($sig->payload) ? $sig->payload : [];
@endphp
<div class="group relative rounded-xl hover:bg-black/[0.02] transition-colors" wire:key="sig-{{ $sig->id }}">
    <span class="absolute left-0 top-3 bottom-3 w-[3px] rounded-full {{ $sv['bar'] }}"></span>
    <div class="flex items-start gap-3 pl-4 pr-1 py-2.5">
        <span class="shrink-0 grid place-items-center w-9 h-9 rounded-xl {{ $sv['tint'] }}" title="{{ $sig->severity->label() }}">
            @svg($sig->type->icon(), 'w-[18px] h-[18px]')
        </span>
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-[13px] font-medium tracking-tight text-gray-900">{{ $sig->title }}</span>
                <span class="text-[9px] font-semibold uppercase tracking-wider {{ $sv['text'] }}">{{ $sig->severity->label() }}</span>
            </div>
            <p class="text-[11px] text-gray-500 mt-0.5">
                <span class="text-gray-400">{{ $sig->type->label() }}</span>@if($sig->description) · {{ \Illuminate\Support\Str::limit($sig->description, 130) }}@endif
            </p>

            @if($sig->type->value === 'preis_sprung_marge_impact' && $pl)
                @php
                    $md = (float) ($pl['marge_delta_eur'] ?? 0);
                    $wd = (float) ($pl['wpct_delta'] ?? 0);
                    $mdClass = $md < 0 ? 'text-rose-600' : 'text-emerald-600';
                    $wdSign = $wd > 0 ? '+' : '';
                @endphp
                <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[10px] text-gray-600">
                    @isset($pl['preis_alt'], $pl['preis_neu'])
                        <span>{{ number_format($pl['preis_alt'], 2, ',', '.') }} € → <span class="font-medium text-gray-800">{{ number_format($pl['preis_neu'], 2, ',', '.') }} €</span></span>
                    @endisset
                    <span>{{ $pl['n_gerichte'] ?? 0 }} Gericht(e) · {{ $pl['n_concepts'] ?? 0 }} Konzept(e)</span>
                    @if($md != 0.0)
                        <span class="font-medium {{ $mdClass }}">Marge {{ number_format($md, 2, ',', '.') }} €@if($wd != 0.0) ({{ $wdSign }}{{ number_format($wd, 1, ',', '.') }} W%-Pkt.)@endif</span>
                    @endif
                    @if(!empty($pl['guenstigere_alternative']['label']))
                        <span class="text-sky-600" title="günstigere Alternative">↓ {{ \Illuminate\Support\Str::limit($pl['guenstigere_alternative']['label'], 28) }} ({{ $pl['guenstigere_alternative']['diff_pct'] }} %)</span>
                    @endif
                </div>
                @if(!empty($pl['beispiele']))
                    <div class="mt-1 flex flex-wrap gap-x-2 gap-y-0.5 text-[10px]">
                        @foreach(array_slice($pl['beispiele'], 0, 6) as $bsp)
                            <a href="{{ route('foodalchemist.verkauf.index', ['rezept' => $bsp['recipe_id']]) }}" wire:navigate
                               class="text-sky-600 hover:underline" title="Marge {{ $bsp['marge_pct_alt'] }} % → {{ $bsp['marge_pct_neu'] }} %">
                                {{ \Illuminate\Support\Str::limit($bsp['name'], 26) }}@if(($bsp['marge_delta_eur'] ?? 0) != 0) <span class="text-gray-500">({{ number_format($bsp['marge_delta_eur'], 2, ',', '.') }} €)</span>@endif
                            </a>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        <div class="shrink-0 flex items-center gap-1 pt-0.5">
            <button type="button" wire:click="toggleDetail({{ $sig->id }})"
                    class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[11px] font-medium text-gray-500 hover:text-gray-800 hover:bg-black/5 transition-colors {{ ($detailPanelId ?? null) === $sig->id ? 'bg-black/[0.06] text-gray-800' : '' }}"
                    title="Betroffene Objekte anzeigen">
                @svg('heroicon-o-chevron-down', 'w-3.5 h-3.5 transition-transform '.(($detailPanelId ?? null) === $sig->id ? 'rotate-180' : ''))
                Reinschauen
            </button>
            @if($sig->status->istOffen())
                @if($ki)
                    <button type="button" wire:click="toggleKiPanel({{ $sig->id }})"
                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-medium text-violet-600 bg-violet-500/[0.06] border border-violet-500/15 hover:bg-violet-500/[0.12] transition-colors {{ $kiPanelId === $sig->id ? 'bg-violet-500/[0.12] ring-1 ring-violet-500/30' : '' }}"
                            title="{{ $ki['flavorLabel'] }}">
                        @svg('heroicon-o-sparkles', 'w-3.5 h-3.5') KI erledigen lassen
                    </button>
                @endif
                <button type="button" wire:click="signalErledigt({{ $sig->id }})"
                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-medium text-emerald-600 hover:bg-emerald-500/10 transition-colors" title="Als behoben markieren">
                    @svg('heroicon-o-check', 'w-3.5 h-3.5') Erledigt
                </button>
                <button type="button" wire:click="signalIgnorieren({{ $sig->id }})"
                        class="inline-flex items-center px-2.5 py-1 rounded-lg text-[11px] font-medium text-gray-400 hover:text-gray-600 hover:bg-black/5 transition-colors" title="Bewusst akzeptieren">
                    Ignorieren
                </button>
            @else
                <span class="{{ $pill }} {{ $variantPill[$sig->status->badgeVariant()] }}">{{ $sig->status->label() }}</span>
                <button type="button" wire:click="signalWiederOeffnen({{ $sig->id }})"
                        class="inline-flex items-center px-2.5 py-1 rounded-lg text-[11px] font-medium text-gray-400 hover:text-gray-600 hover:bg-black/5 transition-colors">Wieder öffnen</button>
            @endif
        </div>
    </div>

    @if($ki && $sig->status->istOffen() && $kiPanelId === $sig->id)
        <div class="mx-4 mb-3 -mt-1 rounded-xl border border-violet-500/20 bg-gradient-to-br from-violet-500/[0.05] to-indigo-500/[0.03] px-4 py-3" wire:key="kpanel-{{ $sig->id }}">
            <div class="flex items-center gap-2 mb-1.5">
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $ki['flavor'] === 'fix' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-sky-500/10 text-sky-600' }}">
                    @svg($ki['flavor'] === 'fix' ? 'heroicon-o-bolt' : 'heroicon-o-sparkles', 'w-3 h-3') {{ $ki['flavorLabel'] }}
                </span>
                <span class="text-[11px] font-medium text-gray-700">So würde die KI das angehen</span>
            </div>
            <p class="text-[11px] leading-relaxed text-gray-600">{{ $ki['plan'] }}</p>
            <div class="mt-2.5 flex items-center gap-2">
                <button type="button" disabled
                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-medium text-white bg-gradient-to-r from-violet-500 to-indigo-500 opacity-40 cursor-not-allowed"
                        title="Kommt bald — die Fix-Logik ist bewusst nachgelagert">
                    @svg('heroicon-o-play', 'w-3.5 h-3.5') Ausführen <span class="opacity-80">(kommt bald)</span>
                </button>
                <button type="button" wire:click="toggleKiPanel({{ $sig->id }})" class="px-2.5 py-1 rounded-lg text-[11px] text-gray-500 hover:bg-black/5 transition-colors">Schließen</button>
            </div>
        </div>
    @endif

    @if(($detailPanelId ?? null) === $sig->id)
        @php $dd = $detailData ?? null; @endphp
        <div class="mx-4 mb-3 -mt-1 rounded-xl border border-black/10 bg-black/[0.015] px-4 py-3" wire:key="detail-{{ $sig->id }}">
            @if($dd && count($dd['items']))
                <div class="flex items-center justify-between mb-2">
                    <span class="text-[11px] font-medium text-gray-700">Betroffene Objekte</span>
                    <span class="text-[10px] text-gray-400 tabular-nums">{{ $dd['gezeigt'] }} von {{ number_format($dd['total'], 0, ',', '.') }}</span>
                </div>
                <div class="grid sm:grid-cols-2 gap-x-6 gap-y-0.5">
                    @foreach($dd['items'] as $it)
                        @if($it['kind'] === 'recipe')
                            <button type="button" wire:click="$dispatch('{{ $it['is_sales_recipe'] ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $it['id'] }} })"
                                    class="flex items-center gap-1.5 text-left text-[11px] text-sky-600 hover:text-sky-700 hover:underline py-0.5 min-w-0">
                                @svg('heroicon-o-arrow-top-right-on-square', 'w-3 h-3 shrink-0 opacity-60')<span class="truncate">{{ $it['name'] }}</span>
                            </button>
                        @elseif($it['kind'] === 'gp')
                            <a href="{{ route('foodalchemist.gps.index', ['gp' => $it['id']]) }}" wire:navigate
                               class="flex items-center gap-1.5 text-[11px] text-violet-600 hover:text-violet-700 hover:underline py-0.5 min-w-0">
                                @svg('heroicon-o-arrow-top-right-on-square', 'w-3 h-3 shrink-0 opacity-60')<span class="truncate">{{ $it['name'] }}</span>
                            </a>
                        @else
                            <span class="text-[11px] text-gray-600 truncate py-0.5">{{ $it['name'] }}</span>
                        @endif
                    @endforeach
                </div>
                @if($dd['total'] > $dd['gezeigt'])
                    <p class="text-[10px] text-gray-400 mt-2">… und {{ number_format($dd['total'] - $dd['gezeigt'], 0, ',', '.') }} weitere. Zum Bearbeiten ins jeweilige Modul.</p>
                @endif
            @elseif($dd)
                <p class="text-[11px] text-gray-500">Für diesen Signaltyp gibt es (noch) keine Einzelaufstellung — der Befund ist aggregiert.</p>
            @else
                <p class="text-[11px] text-gray-400">Lade …</p>
            @endif
        </div>
    @endif
</div>
