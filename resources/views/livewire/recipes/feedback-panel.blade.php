{{-- R2.6 — Feedback-Reiter (geteilt: Gericht + Basisrezept). Praxis-Feedback aus Küche, Kunde, Event:
     Durchschnitt oben, Einträge darunter, neuer Eintrag unten. „Weiterentwickeln" leitet einen Entwurf ab.
     fa-pass (2026-10-05): Bausteine + Tokens (hell und im Werkbank-Modus des Editors). --}}
@php
    $quelleTon = ['kueche' => 'accent', 'kunde' => 'info', 'event' => 'neutral'];
    $quelleLabels = ['kueche' => 'Küche', 'kunde' => 'Kunde', 'event' => 'Event'];
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $gruendeVokabular = (array) config('foodalchemist.feedback_gruende', []);
    $kennzahlen = [
        [
            'label' => 'Durchschnitt',
            'value' => $aggregat['avg'] !== null ? number_format((float) $aggregat['avg'], 1, ',', '.') . ' von 5' : 'noch keins',
            'primary' => $aggregat['avg'] !== null,
        ],
        ['label' => 'Einträge', 'value' => (string) $aggregat['count']],
    ];
@endphp

<div class="flex flex-col gap-4" wire:key="feedback-panel-{{ $recipeId }}">

    {{-- Durchschnitt und Herkunft --}}
    <div class="flex flex-col gap-2">
        <x-fa::kpis :items="$kennzahlen" />
        @if(count($aggregat['per_source']) > 0)
            <div class="flex flex-wrap items-center gap-1.5">
                @foreach($aggregat['per_source'] as $q => $n)
                    <x-fa::badge :tone="$quelleTon[$q] ?? 'neutral'">{{ $quelleLabels[$q] ?? ucfirst((string) $q) }}: {{ $n }}</x-fa::badge>
                @endforeach
            </div>
        @endif
    </div>

    @if(session('fa_feedback_hinweis'))
        <x-fa::notice tone="ok">{{ session('fa_feedback_hinweis') }}</x-fa::notice>
    @endif

    {{-- Bestehende Einträge --}}
    <div class="flex flex-col gap-2">
        @forelse($eintraege as $f)
            <article class="fa-surface p-3 flex flex-col gap-1.5" wire:key="fb-{{ $f->id }}">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-1.5 min-w-0">
                        <x-fa::badge :tone="$quelleTon[$f->quelle->value] ?? 'neutral'">{{ $f->quelle->label() }}</x-fa::badge>
                        @if($f->score !== null)<span class="text-[length:var(--fa-text-md)] font-semibold tabular-nums text-[var(--fa-ink)]">{{ $f->score }} von 5</span>@endif
                        @if($f->created_at)<span class="{{ $leise }} tabular-nums">{{ $f->created_at->format('d.m.Y') }}</span>@endif
                        @if($f->created_via === 'mcp')<x-fa::badge tone="accent" icon="heroicon-m-sparkles" title="Von der KI angelegt">KI</x-fa::badge>@endif
                        {{-- Stempel: am Posten erfasst, während gekocht wurde. Belastbarer als „später in den
                             Editor getippt": der Eintrag entstand an der Charge, nicht aus Erinnerung. --}}
                        @if($f->created_via === 'fa_wall')<x-fa::badge tone="info" icon="heroicon-m-tv" title="Am Wandmonitor erfasst, während der Produktion" data-feedback-stempel-wand>Produktion</x-fa::badge>@endif
                    </div>
                    <div class="shrink-0 flex items-center gap-1">
                        <x-fa::button size="sm" variant="ghost" icon="heroicon-o-arrow-trending-up" wire:click="weiterentwickeln({{ $f->id }})"
                            title="Aus diesem Feedback einen neuen Rezept-Entwurf ableiten">Weiterentwickeln</x-fa::button>
                        @if($ownTeamId !== null && (int) $f->team_id === (int) $ownTeamId)
                            <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash" label="Feedback löschen" wire:click="loeschen({{ $f->id }})" wire:confirm="Diesen Feedback-Eintrag löschen?" />
                        @endif
                    </div>
                </div>
                @if($f->quelle->hatAchsen() && ($f->machbarkeit || $f->aufwand || $f->geschmack || $f->gaeste_reaktion))
                    <div class="flex flex-wrap gap-1">
                        @if($f->machbarkeit)<x-fa::badge>Machbarkeit {{ $f->machbarkeit }}</x-fa::badge>@endif
                        @if($f->aufwand)<x-fa::badge>Aufwand {{ $f->aufwand }}</x-fa::badge>@endif
                        @if($f->geschmack)<x-fa::badge>Geschmack {{ $f->geschmack }}</x-fa::badge>@endif
                        @if($f->gaeste_reaktion)<x-fa::badge>Gäste {{ $f->gaeste_reaktion }}</x-fa::badge>@endif
                    </div>
                @endif
                @if(! empty($f->gruende))
                    {{-- Die zählbare Hälfte des Feedbacks: gleiche Worte für denselben Fall. --}}
                    <div class="flex flex-wrap gap-1" data-feedback-gruende>
                        @foreach($f->gruende as $g)
                            <x-fa::badge tone="warn">{{ $gruendeVokabular[$g] ?? $g }}</x-fa::badge>
                        @endforeach
                    </div>
                @endif
                @if($f->comment)<p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">{{ $f->comment }}</p>@endif
                @if($f->kontext_label)<p class="{{ $leise }}">Anlass: {{ $f->kontext_label }}@if($f->kontext_datum) · {{ $f->kontext_datum->format('d.m.Y') }}@endif</p>@endif
                @if($f->spawned_recipe_id)<x-fa::signal tone="info" icon="heroicon-m-arrow-trending-up">Weiterentwicklung als Entwurf angelegt (Rezept {{ $f->spawned_recipe_id }})</x-fa::signal>@endif
            </article>
        @empty
            <div class="fa-surface">
                <x-fa::empty compact icon="heroicon-o-chat-bubble-left-right" title="Noch kein Feedback">Die Küche, die es kocht, ist die ehrlichste Quelle. Den ersten Eintrag unten erfassen.</x-fa::empty>
            </div>
        @endforelse
    </div>

    {{-- Neuer Eintrag --}}
    <x-fa::section title="Neues Feedback" icon="heroicon-o-pencil-square">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-start">
            <div class="md:col-span-2">
                <x-fa::choice name="quelle" label="Quelle" id-prefix="fb-{{ $recipeId }}" :options="['kueche' => 'Küche', 'kunde' => 'Kunde', 'event' => 'Event']" />
            </div>
            <x-fa::field label="Bewertung" for="fb-score-{{ $recipeId }}" hint="1 bis 5" class="md:col-span-1">
                <x-fa::input numeric type="number" min="1" max="5" id="fb-score-{{ $recipeId }}" wire:model="score" placeholder="–" />
            </x-fa::field>
            <x-fa::field label="Anlass" for="fb-kontext-{{ $recipeId }}" optional class="md:col-span-4">
                <x-fa::input id="fb-kontext-{{ $recipeId }}" wire:model="kontext_label" placeholder="z. B. Sommerfest Zentrag" />
            </x-fa::field>
        </div>

        @if($quelle === 'kueche')
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <x-fa::field label="Machbarkeit" for="fb-machbarkeit-{{ $recipeId }}"><x-fa::input numeric type="number" min="1" max="5" id="fb-machbarkeit-{{ $recipeId }}" wire:model="machbarkeit" /></x-fa::field>
                <x-fa::field label="Aufwand" for="fb-aufwand-{{ $recipeId }}"><x-fa::input numeric type="number" min="1" max="5" id="fb-aufwand-{{ $recipeId }}" wire:model="aufwand" /></x-fa::field>
                <x-fa::field label="Geschmack" for="fb-geschmack-{{ $recipeId }}"><x-fa::input numeric type="number" min="1" max="5" id="fb-geschmack-{{ $recipeId }}" wire:model="geschmack" /></x-fa::field>
                <x-fa::field label="Gäste-Reaktion" for="fb-gaeste-{{ $recipeId }}"><x-fa::input numeric type="number" min="1" max="5" id="fb-gaeste-{{ $recipeId }}" wire:model="gaeste_reaktion" /></x-fa::field>
            </div>
            <p class="{{ $leise }} -mt-1">Küchen-Feedback treibt die Weiterentwicklung. Ohne Bewertung zählt der Mittelwert aus Machbarkeit, Geschmack und Gäste-Reaktion.</p>
        @endif

        <x-fa::field label="Kommentar" for="fb-kommentar-{{ $recipeId }}" error="comment">
            <x-fa::textarea id="fb-kommentar-{{ $recipeId }}" wire:model="comment" rows="2" placeholder="Was lief, was fehlte, Idee für die Weiterentwicklung" />
        </x-fa::field>

        @error('quelle') <x-fa::signal tone="crit">{{ $message }}</x-fa::signal> @enderror

        <div class="flex justify-end">
            <x-fa::button variant="primary" wire:click="speichern" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="speichern">Feedback speichern</span>
                <span wire:loading wire:target="speichern">Wird gespeichert …</span>
            </x-fa::button>
        </div>
    </x-fa::section>
</div>
