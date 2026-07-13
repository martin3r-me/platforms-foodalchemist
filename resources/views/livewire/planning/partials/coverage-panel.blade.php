{{-- R4.2 Coverage-Panel: Soll/Ist-Ampeln gegen das Planungs-Gerüst — live beim Befüllen.
     Erwartet: $coverage (CoverageService::coverage). Optional: $coverageFillAction
     (Livewire-Methode für den Lücken-Klick, bekommt diet_form) ODER $coverageFillRoute
     (Basis-URL des VK-Browsers — Lücken-Klick öffnet gefilterte Gericht-Suche als Link). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($coverageFillAction = $coverageFillAction ?? null)
@php($coverageFillRoute = $coverageFillRoute ?? null)
@php($ampelDot = ['erfuellt' => 'bg-emerald-500', 'teilerfuellt' => 'bg-amber-500', 'verletzt' => 'bg-rose-500', 'info' => 'bg-gray-400'])
@php($z = $coverage['zusammenfassung'])

<div class="relative overflow-hidden {{ $card }}" data-coverage-panel data-coverage-gesamt="{{ $coverage['ampel_gesamt'] }}">
    <div class="{{ $cardAccent }}"></div>
    <div class="px-4 py-3 space-y-2" x-data="{ covAuf: {{ ($z['verletzt'] ?? 0) > 0 ? 'true' : 'false' }} }">
        <button type="button" @click="covAuf = !covAuf" class="w-full flex items-center gap-2 text-left">
            <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $ampelDot[$coverage['ampel_gesamt']] ?? 'bg-gray-400' }}"></span>
            <span class="text-[11px] uppercase tracking-wider text-gray-500 dark:text-gray-400">Soll/Ist-Coverage (Planungs-Gerüst)</span>
            <span class="ml-auto flex items-center gap-2 text-[11px] tabular-nums">
                @if(($z['erfuellt'] ?? 0) > 0)<span class="text-emerald-600 dark:text-emerald-400">● {{ $z['erfuellt'] }}</span>@endif
                @if(($z['teilerfuellt'] ?? 0) > 0)<span class="text-amber-600 dark:text-amber-400">● {{ $z['teilerfuellt'] }}</span>@endif
                @if(($z['verletzt'] ?? 0) > 0)<span class="text-rose-600 dark:text-rose-400">● {{ $z['verletzt'] }}</span>@endif
                <span class="text-gray-500 dark:text-gray-400" x-text="covAuf ? '▾' : '▸'"></span>
            </span>
        </button>

        <div x-show="covAuf" x-cloak class="space-y-1">
            @forelse($coverage['befunde'] as $i => $b)
                <div wire:key="cov-{{ $i }}" class="flex items-start gap-2 rounded-lg px-2 py-1 text-[11px] {{ $b['ampel'] === 'verletzt' ? 'bg-rose-500/5' : '' }}" data-coverage-befund="{{ $b['dimension'] }}">
                    <span class="mt-1 w-2 h-2 rounded-full shrink-0 {{ $ampelDot[$b['ampel']] ?? 'bg-gray-400' }}"></span>
                    <div class="flex-1 min-w-0">
                        <span class="font-medium text-gray-800 dark:text-gray-200">{{ $b['label'] }}</span>
                        <span class="text-gray-600 dark:text-gray-400"> — Soll: {{ $b['soll'] }} · Ist: {{ $b['ist'] }}</span>
                        @if($b['hinweis'])<div class="text-gray-500 dark:text-gray-400">{{ $b['hinweis'] }}</div>@endif
                    </div>
                    @if($b['fill_filter'] !== null && $coverageFillAction !== null)
                        <button type="button" wire:click="{{ $coverageFillAction }}('{{ $b['fill_filter']['diet_form'] ?? '' }}')"
                                class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 shrink-0" title="Gefilterte Gericht-Suche zum Füllen" data-coverage-fuellen>
                            → füllen
                        </button>
                    @elseif($b['fill_filter'] !== null && $coverageFillRoute !== null)
                        @php($fillKlasse = ($b['fill_filter']['diet_form'] ?? null) !== null
                            ? \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::visibleToTeam(auth()->user()->currentTeamRelation)->where('diet_form', $b['fill_filter']['diet_form'])->value('id')
                            : null)
                        <a href="{{ $coverageFillRoute }}{{ $fillKlasse !== null ? '?class=' . $fillKlasse : '' }}" target="_blank"
                           class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 shrink-0" title="Gefilterte Gericht-Suche im VK-Browser" data-coverage-fuellen>
                            → suchen
                        </a>
                    @endif
                </div>
            @empty
                <p class="text-[11px] text-gray-500 dark:text-gray-400">Gerüst vorhanden, aber ohne prüfbare Vorgaben — im Planungs-Gerüst Soll-Werte setzen.</p>
            @endforelse
        </div>
    </div>
</div>
