{{-- R4.2 Soll-Ist-Abgleich: Ampeln gegen das Planungs-Gerüst — live beim Befüllen.
     Erwartet: $coverage (CoverageService::coverage). Optional: $coverageFillAction
     (Livewire-Methode für den Klick auf eine Lücke, bekommt diet_form) ODER $coverageFillRoute
     (Basis-URL des VK-Browsers — Klick öffnet die gefilterte Gericht-Suche als Link).
     Eingebunden im Concepter-Editor (Reiter «Konzept & Planung») und in der Speisekarten-Leitstelle.
     fa-pass: nur Tokens + x-fa-Bausteine; Marker und Props unverändert. --}}
@php
    $coverageFillAction = $coverageFillAction ?? null;
    $coverageFillRoute = $coverageFillRoute ?? null;
    $ampelPunkt = ['erfuellt' => 'bg-[var(--fa-ok)]', 'teilerfuellt' => 'bg-[var(--fa-warn)]', 'verletzt' => 'bg-[var(--fa-crit)]', 'info' => 'bg-[var(--fa-ink-3)]'];
    $ampelText = ['erfuellt' => 'erfüllt', 'teilerfuellt' => 'teilweise', 'verletzt' => 'verletzt', 'info' => 'Hinweis'];
    $z = $coverage['zusammenfassung'];
    // N+1-Fix: diet_form→class_id EINMAL vorladen statt je Befund-Zeile.
    $coverageFillTeam = ($coverageFillRoute !== null && $coverageFillAction === null) ? auth()->user()?->currentTeamRelation : null;
    $covFillKlassen = [];
    $covDietForms = collect($coverage['befunde'])->pluck('fill_filter.diet_form')->filter()->unique()->values();
    if ($coverageFillTeam !== null && $covDietForms->isNotEmpty()) {
        foreach (\Platform\FoodAlchemist\Models\FoodAlchemistDishClass::visibleToTeam($coverageFillTeam)->whereIn('diet_form', $covDietForms->all())->orderBy('id')->get(['id', 'diet_form']) as $dc) {
            $covFillKlassen[$dc->diet_form] = $covFillKlassen[$dc->diet_form] ?? $dc->id;
        }
    }
@endphp

<div class="fa-surface overflow-hidden" data-coverage-panel data-coverage-gesamt="{{ $coverage['ampel_gesamt'] }}"
     x-data="{ covAuf: {{ ($z['verletzt'] ?? 0) > 0 ? 'true' : 'false' }} }">
    <button type="button" x-on:click="covAuf = !covAuf" x-bind:aria-expanded="covAuf"
            class="w-full flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-left hover:bg-[var(--fa-hover)]">
        <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $ampelPunkt[$coverage['ampel_gesamt']] ?? 'bg-[var(--fa-ink-3)]' }}"></span>
        <span class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">Soll-Ist-Abgleich</span>
        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">gegen das Planungs-Gerüst</span>
        <span class="ml-auto flex flex-wrap items-center gap-1.5">
            @if(($z['erfuellt'] ?? 0) > 0)<x-fa::badge tone="ok">{{ $z['erfuellt'] }} erfüllt</x-fa::badge>@endif
            @if(($z['teilerfuellt'] ?? 0) > 0)<x-fa::badge tone="warn">{{ $z['teilerfuellt'] }} teilweise</x-fa::badge>@endif
            @if(($z['verletzt'] ?? 0) > 0)<x-fa::badge tone="crit">{{ $z['verletzt'] }} verletzt</x-fa::badge>@endif
            <span class="text-[var(--fa-ink-3)] transition-transform" x-bind:class="covAuf ? 'rotate-180' : ''">@svg('heroicon-m-chevron-down', 'w-4 h-4')</span>
        </span>
    </button>

    <div x-show="covAuf" x-cloak class="flex flex-col border-t border-[var(--fa-line)] divide-y divide-[var(--fa-line)]">
        @forelse($coverage['befunde'] as $i => $b)
            <div wire:key="cov-{{ $i }}" class="flex flex-wrap items-start gap-x-3 gap-y-1.5 px-4 py-2.5 {{ $b['ampel'] === 'verletzt' ? 'bg-[var(--fa-crit-soft)]' : '' }}" data-coverage-befund="{{ $b['dimension'] }}">
                <span class="mt-1.5 w-2 h-2 rounded-full shrink-0 {{ $ampelPunkt[$b['ampel']] ?? 'bg-[var(--fa-ink-3)]' }}" title="{{ $ampelText[$b['ampel']] ?? $b['ampel'] }}"></span>
                <div class="flex-1 min-w-[12rem] text-[length:var(--fa-text-md)]">
                    <span class="font-medium text-[var(--fa-ink)]">{{ $b['label'] }}</span>
                    <span class="text-[var(--fa-ink-2)] tabular-nums">, Soll {{ $b['soll'] }}, Ist {{ $b['ist'] }}</span>
                    @if($b['hinweis'])<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $b['hinweis'] }}</p>@endif
                </div>
                @if($b['fill_filter'] !== null && $coverageFillAction !== null)
                    <x-fa::button size="sm" icon="heroicon-m-funnel" wire:click="{{ $coverageFillAction }}('{{ $b['fill_filter']['diet_form'] ?? '' }}')"
                            class="shrink-0" title="Wechselt in den Aufbau und filtert die Gerichte passend zur Lücke" data-coverage-fuellen>Passende Gerichte zeigen</x-fa::button>
                @elseif($b['fill_filter'] !== null && $coverageFillRoute !== null)
                    @php $fillKlasse = $covFillKlassen[$b['fill_filter']['diet_form'] ?? ''] ?? null; @endphp
                    <x-fa::button size="sm" icon="heroicon-m-arrow-top-right-on-square" href="{{ $coverageFillRoute }}{{ $fillKlasse !== null ? '?class=' . $fillKlasse : '' }}" target="_blank"
                       class="shrink-0" title="Gefilterte Gericht-Suche im Gerichte-Browser" data-coverage-fuellen>Im Browser suchen</x-fa::button>
                @endif
            </div>
        @empty
            <p class="px-4 py-3 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Das Gerüst hat noch keine prüfbaren Vorgaben. Im Planungs-Gerüst Soll-Werte setzen.</p>
        @endforelse
    </div>
</div>
