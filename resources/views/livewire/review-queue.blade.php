{{-- M9-03 / V-10: Review-Queue — alles, was eine menschliche Entscheidung braucht, auf EINER Seite --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Zu prüfen" icon="heroicon-o-clipboard-document-check" />
    </x-slot>

    {{-- Klick-Ziele der Rezept-Listen --}}
    <livewire:foodalchemist.recipes.recipe-modal />
    <livewire:foodalchemist.verkauf.vk-modal />

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-5">
        @if($meldung !== null)<p class="text-sm text-emerald-600 dark:text-emerald-400" data-rq-meldung>{{ $meldung }}</p>@endif
        @if($fehler !== null)<p class="text-sm text-rose-600 dark:text-rose-400" data-rq-fehler>{{ $fehler }}</p>@endif

        {{-- 1. LA→GP-Match-Vorschläge (M3-11, tentative Queue) --}}
        <div class="relative overflow-hidden {{ $card }} px-5 py-4" data-rq-matches>
            <div class="{{ $cardAccent }}"></div>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-1">
                LA → GP Match-Vorschläge <span class="{{ $pill }} {{ $matchZahl > 0 ? $variantPill['warning'] : $variantPill['secondary'] }} ml-1">{{ number_format($matchZahl, 0, ',', '.') }} offen</span>
            </h3>
            <p class="text-xs text-gray-400 mb-2">Aus dem Bulk-Match (exakte Dubletten + Fuzzy) — Übernehmen verknüpft das LA mit dem GP. Zeigt die besten 50.</p>
            @forelse($matches as $m)
                <div class="flex items-center gap-2 py-1 border-t border-black/5 dark:border-white/5 text-xs" wire:key="rqm-{{ $m->id }}">
                    <span class="font-semibold shrink-0 {{ $m->score >= 0.9 ? 'text-green-600' : 'text-amber-500' }}">{{ round($m->score * 100) }} %</span>
                    <span class="min-w-0 truncate text-gray-700 dark:text-gray-200" title="{{ $m->la_name }}">{{ $m->la_name }}</span>
                    <span class="text-gray-400 shrink-0">→</span>
                    <span class="min-w-0 truncate text-violet-600 dark:text-violet-400" title="{{ $m->gp_name }}">{{ $m->gp_name }}</span>
                    <span class="text-gray-400 shrink-0">{{ $m->methode }}</span>
                    <span class="ml-auto shrink-0 flex gap-1">
                        <button type="button" wire:click="matchUebernehmen({{ $m->id }})" class="{{ $btnGhostXs }} text-emerald-600" data-rq-match-ok>Übernehmen</button>
                        <button type="button" wire:click="matchVerwerfen({{ $m->id }})" class="{{ $btnGhostXs }}" data-rq-match-nein>Verwerfen</button>
                    </span>
                </div>
            @empty
                <p class="text-xs text-gray-400">— nichts offen —</p>
            @endforelse
        </div>

        {{-- 2. KI-Vorschläge aus Bulk-Läufen (M7-06) --}}
        <div class="relative overflow-hidden {{ $card }} px-5 py-4" data-rq-bulks>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-1">
                ✨ KI-Vorschläge (Bulk-Anreicherung) <span class="{{ $pill }} {{ $bulkZahl > 0 ? $variantPill['warning'] : $variantPill['secondary'] }} ml-1">{{ number_format($bulkZahl, 0, ',', '.') }} offen</span>
            </h3>
            @forelse($bulks as $b)
                <div class="flex items-center gap-2 py-1 border-t border-black/5 dark:border-white/5 text-xs" wire:key="rqb-{{ $b->id }}">
                    <button type="button" wire:click="$dispatch('{{ $b->ist_verkaufsrezept ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $b->rezept_id }} })"
                            class="min-w-0 truncate text-sky-600 dark:text-sky-400 hover:underline text-left" title="{{ $b->rezept_name }}">{{ $b->rezept_name }}</button>
                    <span class="{{ $pill }} {{ $variantPill['info'] }} shrink-0">{{ $b->feld }}</span>
                    <span class="min-w-0 truncate text-gray-500" title="{{ is_string($b->wert) ? trim($b->wert, '"') : '' }}">{{ \Illuminate\Support\Str::limit(trim((string) $b->wert, '"'), 60) }}</span>
                    @if($b->confidence !== null)<span class="text-gray-400 shrink-0">{{ round($b->confidence * 100) }} %</span>@endif
                    <span class="ml-auto shrink-0 flex gap-1">
                        <button type="button" wire:click="bulkUebernehmen({{ $b->id }})" class="{{ $btnGhostXs }} text-emerald-600" data-rq-bulk-ok>Übernehmen</button>
                        <button type="button" wire:click="bulkVerwerfen({{ $b->id }})" class="{{ $btnGhostXs }}" data-rq-bulk-nein>Verwerfen</button>
                    </span>
                </div>
            @empty
                <p class="text-xs text-gray-400">— nichts offen —</p>
            @endforelse
        </div>

        {{-- 3.–5. Pflegelücken --}}
        <div class="grid md:grid-cols-3 gap-3">
            <div class="relative overflow-hidden {{ $card }} px-4 py-3" data-rq-vk-ohne-klasse>
                <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-1">VK ohne Klasse <span class="{{ $pill }} {{ $vkOhneKlasse->isNotEmpty() ? $variantPill['warning'] : $variantPill['secondary'] }}">{{ $vkOhneKlasse->count() }}</span></h3>
                <p class="text-[10px] text-gray-400 mb-1.5">V-22-Gate — ✨ Klassifizieren im VK-Panel</p>
                @forelse($vkOhneKlasse as $r)
                    <button type="button" wire:key="rqv-{{ $r->id }}" wire:click="$dispatch('vk-modal.oeffnen', { id: {{ $r->id }} })"
                            class="block w-full text-left text-xs text-sky-600 dark:text-sky-400 hover:underline truncate py-0.5">{{ $r->name }}</button>
                @empty
                    <p class="text-xs text-gray-400">— keine —</p>
                @endforelse
            </div>
            <div class="relative overflow-hidden {{ $card }} px-4 py-3" data-rq-review>
                <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-1">Im Review-Status <span class="{{ $pill }} {{ $imReviewZahl > 0 ? $variantPill['warning'] : $variantPill['secondary'] }}">{{ number_format($imReviewZahl, 0, ',', '.') }}</span></h3>
                <p class="text-[10px] text-gray-400 mb-1.5">Freigeben oder zurück in den Entwurf (zeigt 50)</p>
                @forelse($imReview as $r)
                    <button type="button" wire:key="rqr-{{ $r->id }}" wire:click="$dispatch('{{ $r->ist_verkaufsrezept ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $r->id }} })"
                            class="block w-full text-left text-xs text-sky-600 dark:text-sky-400 hover:underline truncate py-0.5">{{ $r->name }}</button>
                @empty
                    <p class="text-xs text-gray-400">— keine —</p>
                @endforelse
            </div>
            <div class="relative overflow-hidden {{ $card }} px-4 py-3" data-rq-ungemappt>
                <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-1">Ungemappte Zutaten <span class="{{ $pill }} {{ $ungemapptZahl > 0 ? $variantPill['danger'] : $variantPill['secondary'] }}">{{ number_format($ungemapptZahl, 0, ',', '.') }}</span></h3>
                <p class="text-[10px] text-gray-400 mb-1.5">F7.1: Allergene unbekannt, bis gemappt (zeigt 50)</p>
                @forelse($ungemappt as $r)
                    <button type="button" wire:key="rqu-{{ $r->id }}" wire:click="$dispatch('{{ $r->ist_verkaufsrezept ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $r->id }} })"
                            class="flex w-full items-center justify-between text-left text-xs text-sky-600 dark:text-sky-400 hover:underline py-0.5">
                        <span class="min-w-0 truncate">{{ $r->name }}</span><span class="text-gray-400 shrink-0 ml-1">{{ $r->n_zutaten_ungemappt }}?</span>
                    </button>
                @empty
                    <p class="text-xs text-gray-400">— keine —</p>
                @endforelse
            </div>
        </div>
    </x-ui-page-container>
</x-ui-page>
