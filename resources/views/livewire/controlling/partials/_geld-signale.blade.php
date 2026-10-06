{{-- Geld-Signale: die sechs wirtschaftlichen Fälle aus den 39 Signaltypen.

     Bewusst nur ein FILTER, keine zweite Signal-Werkbank. Die Zähler kommen aus derselben
     Quelle wie die Signale-Seite (`SignalService::offeneNachTyp`); zwei Zähl-Orte für dieselbe
     Menge wären genau der Fehler, der beim Signale-Cockpit-Umbau ausgeräumt wurde.

     Der Sprung geht auf `/zu-pruefen` mit vorgesetztem Typ-Filter; dort hängen die Aktionen
     („KI erledigen lassen", Erledigt/Ignorieren, Rausch-Policy). Mit Spec 32 · C2 ziehen die
     Zeilen-Aktionen hier herein.

     fa-pass 2026-10-05: Tokens + Bausteine; offene Fälle zuerst, Zähler in Zustandsfarbe. --}}
@php
    $zeilen = collect($kpi['geld_signale_je_typ'] ?? [])
        ->map(fn ($anzahl, $typ) => [
            'typ' => $typ,
            'anzahl' => (int) $anzahl,
            'label' => \Platform\FoodAlchemist\Enums\SignalTyp::tryFrom($typ)?->label() ?? \Illuminate\Support\Str::ucfirst(str_replace('_', ' ', $typ)),
        ])
        ->sortByDesc('anzahl')
        ->values();
@endphp

<div data-ctrl-geld-signale class="flex flex-col gap-3">
    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[75ch]">
        Offene Befunde, die Geld kosten: Preissprünge, veraltete Preise, Marge und Wareneinsatz über Ziel.
        Ein Klick öffnet die Signale, dort werden sie erledigt oder verworfen.
    </p>

    {{-- Ebene 2: die Zähler folgen der Betriebsbrille, Betriebs-Lane PLUS die betriebs-unabhängigen
         (Team-Core). Ohne Betrieb ist es die Team-Core-Sicht. --}}
    @if(!empty($kpi['betrieb_name']))
        <x-fa::signal tone="info" icon="heroicon-m-building-storefront">
            Für Betrieb {{ $kpi['betrieb_name'] }}, dazu alle Befunde, die keinem Betrieb gehören (Artikel, Hygiene, Rezept)
        </x-fa::signal>
    @endif

    @if(($kpi['geld_signale'] ?? 0) === 0)
        <x-fa::empty compact icon="heroicon-o-check-badge" title="Keine offenen Geld-Signale">
            Die nächtliche Prüfung meldet hier neue Befunde zu Preis, Marge und Wareneinsatz. Sofort prüfen geht über die Signale.
        </x-fa::empty>
    @endif

    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,22rem),1fr))] gap-2">
        @foreach($zeilen as $z)
            <a href="{{ route('foodalchemist.review', ['tab' => 'signale', 'sig_status' => 'offen', 'sig_typ' => $z['typ']]) }}"
               wire:navigate
               class="flex items-center justify-between gap-3 min-w-0 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-3 py-2.5 transition-colors duration-150 hover:bg-[var(--fa-hover)]"
               data-ctrl-geld-signal="{{ $z['typ'] }}">
                <span class="min-w-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $z['label'] }}</span>
                <span class="flex items-center gap-2 shrink-0">
                    @if($z['anzahl'] > 0)
                        <x-fa::badge tone="warn">{{ number_format($z['anzahl'], 0, ',', '.') }} offen</x-fa::badge>
                    @else
                        <x-fa::badge>Keine</x-fa::badge>
                    @endif
                    @svg('heroicon-m-chevron-right', 'w-4 h-4 text-[var(--fa-ink-3)]')
                </span>
            </a>
        @endforeach
    </div>
</div>
