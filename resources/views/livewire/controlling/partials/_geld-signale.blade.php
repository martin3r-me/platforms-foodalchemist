{{-- Geld-Signale: die sechs wirtschaftlichen Fälle aus den 39 Signaltypen.

     Bewusst nur ein FILTER, keine zweite Signal-Werkbank. Die Zähler kommen aus derselben
     Quelle wie die Signale-Seite (`SignalService::offeneNachTyp`) — zwei Zähl-Orte für dieselbe
     Menge wären genau der Fehler, der beim Signale-Cockpit-Umbau ausgeräumt wurde.

     Der Sprung geht auf `/zu-pruefen` mit vorgesetztem Typ-Filter; dort hängen die Aktionen
     („KI erledigen lassen", Erledigt/Ignorieren, Rausch-Policy). Mit Spec 32 · C2 ziehen die
     Zeilen-Aktionen hier herein. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div data-ctrl-geld-signale class="space-y-3">
    {{-- Ebene 2: die Zähler folgen der Betriebsbrille — Betriebs-Lane PLUS die betriebs-unabhängigen
         (Team-Core). Ohne Betrieb ist es die Team-Core-Sicht. --}}
    @if(!empty($kpi['betrieb_name']))
        <div class="flex items-center gap-1.5 text-[11px] text-indigo-600">
            @svg('heroicon-o-building-storefront', 'w-3.5 h-3.5')
            <span>Geld-Signale für <span class="font-medium">{{ $kpi['betrieb_name'] }}</span> · plus betriebs-unabhängige (Artikel/Hygiene/Rezept)</span>
        </div>
    @endif

    @if(($kpi['geld_signale'] ?? 0) === 0)
        <p class="text-xs text-gray-500">
            Keine offenen Geld-Signale. Preis-, Marge- und Wareneinsatz-Befunde erzeugt der
            nächtliche Detektor — hier landet, was wirtschaftlich weh tut.
        </p>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
        @foreach($kpi['geld_signale_je_typ'] as $typ => $anzahl)
            @php($enum = \Platform\FoodAlchemist\Enums\SignalTyp::tryFrom($typ))
            <a href="{{ route('foodalchemist.review', ['tab' => 'signale', 'sig_status' => 'offen', 'sig_typ' => $typ]) }}"
               wire:navigate
               class="flex items-center justify-between gap-3 rounded-lg bg-black/[0.03] px-3 py-2 hover:bg-black/[0.06] transition-colors duration-150"
               data-ctrl-geld-signal="{{ $typ }}">
                <span class="text-xs text-gray-900">{{ $enum?->label() ?? $typ }}</span>
                <span class="{{ $pill }} {{ $anzahl > 0 ? $variantPill['warning'] : $variantPill['secondary'] }} tabular-nums">
                    {{ number_format($anzahl, 0, ',', '.') }}
                </span>
            </a>
        @endforeach
    </div>

    <p class="text-[11px] text-gray-500">
        Bearbeitet werden die Befunde auf der Signale-Seite — dort liegen Fix, Erledigt/Ignorieren
        und die Rausch-Policy je Typ.
    </p>
</div>
