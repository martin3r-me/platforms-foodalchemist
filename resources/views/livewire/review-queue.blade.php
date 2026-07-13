{{-- M9-03 / V-10: Review-Queue — alles, was eine menschliche Entscheidung braucht, auf EINER Seite --}}
@php extract(\Platform\FoodAlchemist\Support\Ui::maps()); @endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Signale" icon="heroicon-o-bell-alert" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Signale'],
        ]" />
    </x-slot>

    {{-- Klick-Ziele der Rezept-Listen --}}
    <livewire:foodalchemist.recipes.recipe-modal />
    <livewire:foodalchemist.verkauf.vk-modal />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-5">
        @if($meldung !== null)<p class="text-xs text-emerald-600" data-rq-meldung>{{ $meldung }}</p>@endif
        @if($fehler !== null)<p class="text-xs text-rose-600" data-rq-fehler>{{ $fehler }}</p>@endif

        {{-- Klasse B: Signale (#378) — vom System detektierte Auffälligkeiten --}}
        <div class="relative overflow-hidden {{ $card }} px-5 py-4" data-rq-signale>
            <div class="{{ $cardAccent }}"></div>
            <div class="flex items-center justify-between gap-2 mb-1">
                <h3 class="font-medium tracking-tight text-gray-900">
                    Signale <span class="{{ $pill }} {{ $signalOffen > 0 ? $variantPill['warning'] : $variantPill['secondary'] }} ml-1">{{ $signalOffen }} offen</span>
                </h3>
                <button type="button" wire:click="detektorLaufen" wire:target="detektorLaufen" wire:loading.attr="disabled" class="{{ $btnGhostXs }} disabled:opacity-60" title="Detektor jetzt laufen lassen">
                    <span wire:loading.remove wire:target="detektorLaufen">↻ Prüfen</span>
                    <span wire:loading wire:target="detektorLaufen">↻ Prüfe …</span>
                </button>
            </div>
            <p class="text-[11px] text-gray-500 mb-2">Vom System erkannte Auffälligkeiten (Preise, Daten, Marge). „Erledigt" = behoben, „Ignorieren" = bewusst akzeptiert.</p>

            <div class="flex flex-wrap items-center gap-1 mb-2">
                @foreach($signalStatusWerte as $sw)
                    <button type="button" wire:key="sigst-{{ $sw['value'] }}" wire:click="setSignalStatus('{{ $sw['value'] }}')"
                            class="{{ $pill }} {{ $signalStatus === $sw['value'] ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $sw['label'] }}</button>
                @endforeach
                <span class="mx-1 text-gray-300">·</span>
                <button type="button" wire:click="setSignalTyp('')" class="{{ $pill }} {{ $signalTyp === '' ? $variantPill['primary'] : $variantPill['secondary'] }}">Alle Typen</button>
                @foreach($signalTypWerte as $tw)
                    <button type="button" wire:key="sigtyp-{{ $tw['value'] }}" wire:click="setSignalTyp('{{ $tw['value'] }}')"
                            class="{{ $pill }} {{ $signalTyp === $tw['value'] ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $tw['label'] }}@if(($signalNachTyp[$tw['value']] ?? 0) > 0) ({{ $signalNachTyp[$tw['value']] }})@endif</button>
                @endforeach
            </div>

            @forelse($signale as $sig)
                <div class="flex items-start gap-2 py-1.5 border-t border-black/5 text-[11px]" wire:key="sig-{{ $sig->id }}">
                    <span class="{{ $pill }} {{ $variantPill[$sig->severity->badgeVariant()] ?? $variantPill['secondary'] }} shrink-0">{{ $sig->severity->label() }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5">
                            <span class="text-gray-500 shrink-0">{{ $sig->type->label() }}</span>
                            <span class="font-medium text-gray-900 truncate">{{ $sig->title }}</span>
                        </div>
                        @if($sig->description)<p class="text-gray-600 mt-0.5">{{ \Illuminate\Support\Str::limit($sig->description, 140) }}</p>@endif
                        @php
                            $pl = is_array($sig->payload) ? $sig->payload : [];
                            $md = (float) ($pl['marge_delta_eur'] ?? 0);
                            $wd = (float) ($pl['wpct_delta'] ?? 0);
                            $mdClass = $md < 0 ? 'text-rose-600' : 'text-emerald-600';
                            $wdSign = $wd > 0 ? '+' : '';
                        @endphp
                        @if($sig->type->value === 'preis_sprung_marge_impact' && $pl)
                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[10px] text-gray-600">
                                @isset($pl['preis_alt'], $pl['preis_neu'])
                                    <span>{{ number_format($pl['preis_alt'], 2, ',', '.') }} € → <span class="font-medium text-gray-700">{{ number_format($pl['preis_neu'], 2, ',', '.') }} €</span></span>
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
                                <div class="mt-0.5 flex flex-wrap gap-x-2 gap-y-0.5 text-[10px]">
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
                    <span class="shrink-0 flex gap-1">
                        @if($sig->status->istOffen())
                            <button type="button" wire:click="signalErledigt({{ $sig->id }})" class="{{ $btnGhostXs }} text-emerald-600">Erledigt</button>
                            <button type="button" wire:click="signalIgnorieren({{ $sig->id }})" class="{{ $btnGhostXs }}">Ignorieren</button>
                        @else
                            <span class="{{ $pill }} {{ $variantPill[$sig->status->badgeVariant()] }}">{{ $sig->status->label() }}</span>
                            <button type="button" wire:click="signalWiederOeffnen({{ $sig->id }})" class="{{ $btnGhostXs }}">Wieder öffnen</button>
                        @endif
                    </span>
                </div>
            @empty
                <p class="text-[11px] text-gray-500">— keine Signale ({{ $signalStatus }}) —</p>
            @endforelse
            <div class="mt-2">{{ $signale->links() }}</div>
        </div>

        <h3 class="text-[11px] font-medium uppercase tracking-wider text-gray-500 pt-1">Entscheidungen</h3>

        {{-- 1. LA→GP-Match-Vorschläge (M3-11, tentative Queue) --}}
        <div class="relative overflow-hidden {{ $card }} px-5 py-4" data-rq-matches>
            <div class="{{ $cardAccent }}"></div>
            <h3 class="font-medium tracking-tight text-gray-900 mb-1">
                LA → GP Match-Vorschläge <span class="{{ $pill }} {{ $matchZahl > 0 ? $variantPill['warning'] : $variantPill['secondary'] }} ml-1">{{ number_format($matchZahl, 0, ',', '.') }} offen</span>
            </h3>
            <p class="text-[11px] text-gray-500 mb-2">Aus dem Bulk-Match (exakte Dubletten + Fuzzy) — Übernehmen verknüpft das LA mit dem GP. Zeigt die besten 50.</p>
            @forelse($matches as $m)
                <div class="flex items-center gap-2 py-1 border-t border-black/5 text-[11px]" wire:key="rqm-{{ $m->id }}">
                    <span class="font-semibold shrink-0 {{ $m->score >= 0.9 ? 'text-green-600' : 'text-amber-500' }}">{{ round($m->score * 100) }} %</span>
                    <span class="min-w-0 truncate text-gray-700" title="{{ $m->la_name }}">{{ $m->la_name }}</span>
                    <span class="text-gray-500 shrink-0">→</span>
                    <span class="min-w-0 truncate text-violet-600" title="{{ $m->gp_name }}">{{ $m->gp_name }}</span>
                    <span class="text-gray-500 shrink-0">{{ $m->methode }}</span>
                    <span class="ml-auto shrink-0 flex gap-1">
                        <button type="button" wire:click="matchUebernehmen({{ $m->id }})" class="{{ $btnGhostXs }} text-emerald-600" data-rq-match-ok>Übernehmen</button>
                        <button type="button" wire:click="matchVerwerfen({{ $m->id }})" class="{{ $btnGhostXs }}" data-rq-match-nein>Verwerfen</button>
                    </span>
                </div>
            @empty
                <p class="text-[11px] text-gray-500">— nichts offen —</p>
            @endforelse
        </div>

        {{-- 2. KI-Vorschläge aus Bulk-Läufen (M7-06) --}}
        <div class="relative overflow-hidden {{ $card }} px-5 py-4" data-rq-bulks>
            <h3 class="font-medium tracking-tight text-gray-900 mb-1">
                ✨ KI-Vorschläge (Bulk-Anreicherung) <span class="{{ $pill }} {{ $bulkZahl > 0 ? $variantPill['warning'] : $variantPill['secondary'] }} ml-1">{{ number_format($bulkZahl, 0, ',', '.') }} offen</span>
            </h3>
            @forelse($bulks as $b)
                <div class="flex items-center gap-2 py-1 border-t border-black/5 text-[11px]" wire:key="rqb-{{ $b->id }}">
                    <button type="button" wire:click="$dispatch('{{ $b->is_sales_recipe ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $b->rezept_id }} })"
                            class="min-w-0 truncate text-sky-600 hover:underline text-left" title="{{ $b->rezept_name }}">{{ $b->rezept_name }}</button>
                    <span class="{{ $pill }} {{ $variantPill['info'] }} shrink-0">{{ $b->field }}</span>
                    <span class="min-w-0 truncate text-gray-600" title="{{ is_string($b->value) ? trim($b->value, '"') : '' }}">{{ \Illuminate\Support\Str::limit(trim((string) $b->value, '"'), 60) }}</span>
                    @if($b->confidence !== null)<span class="text-gray-500 shrink-0">{{ round($b->confidence * 100) }} %</span>@endif
                    <span class="ml-auto shrink-0 flex gap-1">
                        <button type="button" wire:click="bulkUebernehmen({{ $b->id }})" class="{{ $btnGhostXs }} text-emerald-600" data-rq-bulk-ok>Übernehmen</button>
                        <button type="button" wire:click="bulkVerwerfen({{ $b->id }})" class="{{ $btnGhostXs }}" data-rq-bulk-nein>Verwerfen</button>
                    </span>
                </div>
            @empty
                <p class="text-[11px] text-gray-500">— nichts offen —</p>
            @endforelse
        </div>

        {{-- 3.–5. Pflegelücken --}}
        <div class="grid md:grid-cols-3 gap-3">
            <div class="relative overflow-hidden {{ $card }} px-4 py-3" data-rq-vk-ohne-klasse>
                <h3 class="text-xs font-medium text-gray-900 mb-1">VK ohne Klasse <span class="{{ $pill }} {{ $vkOhneKlasse->isNotEmpty() ? $variantPill['warning'] : $variantPill['secondary'] }}">{{ $vkOhneKlasse->count() }}</span></h3>
                <p class="text-[10px] text-gray-500 mb-1.5">V-22-Gate — ✨ Klassifizieren im VK-Panel</p>
                @forelse($vkOhneKlasse as $r)
                    <button type="button" wire:key="rqv-{{ $r->id }}" wire:click="$dispatch('vk-modal.oeffnen', { id: {{ $r->id }} })"
                            class="block w-full text-left text-[11px] text-sky-600 hover:underline truncate py-0.5">{{ $r->name }}</button>
                @empty
                    <p class="text-[11px] text-gray-500">— keine —</p>
                @endforelse
            </div>
            <div class="relative overflow-hidden {{ $card }} px-4 py-3" data-rq-review>
                <h3 class="text-xs font-medium text-gray-900 mb-1">Im Review-Status <span class="{{ $pill }} {{ $imReviewZahl > 0 ? $variantPill['warning'] : $variantPill['secondary'] }}">{{ number_format($imReviewZahl, 0, ',', '.') }}</span></h3>
                <p class="text-[10px] text-gray-500 mb-1.5">Freigeben oder zurück in den Entwurf (zeigt 50)</p>
                @forelse($imReview as $r)
                    <button type="button" wire:key="rqr-{{ $r->id }}" wire:click="$dispatch('{{ $r->is_sales_recipe ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $r->id }} })"
                            class="block w-full text-left text-[11px] text-sky-600 hover:underline truncate py-0.5">{{ $r->name }}</button>
                @empty
                    <p class="text-[11px] text-gray-500">— keine —</p>
                @endforelse
            </div>
            <div class="relative overflow-hidden {{ $card }} px-4 py-3" data-rq-ungemappt>
                <h3 class="text-xs font-medium text-gray-900 mb-1">Ungemappte Zutaten <span class="{{ $pill }} {{ $ungemapptZahl > 0 ? $variantPill['danger'] : $variantPill['secondary'] }}">{{ number_format($ungemapptZahl, 0, ',', '.') }}</span></h3>
                <p class="text-[10px] text-gray-500 mb-1.5">F7.1: Allergene unbekannt, bis gemappt (zeigt 50)</p>
                @forelse($ungemappt as $r)
                    <button type="button" wire:key="rqu-{{ $r->id }}" wire:click="$dispatch('{{ $r->is_sales_recipe ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $r->id }} })"
                            class="flex w-full items-center justify-between text-left text-[11px] text-sky-600 hover:underline py-0.5">
                        <span class="min-w-0 truncate">{{ $r->name }}</span><span class="text-gray-500 shrink-0 ml-1">{{ $r->n_ingredients_unmapped }}?</span>
                    </button>
                @empty
                    <p class="text-[11px] text-gray-500">— keine —</p>
                @endforelse
            </div>
        </div>
    </x-ui-page-container>
</x-ui-page>
