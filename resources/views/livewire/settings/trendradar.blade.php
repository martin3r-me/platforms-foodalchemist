{{-- Einstellungen → Trendradar: Google Trends über DataForSEO (Spec 79, pro Team). --}}
@php
    $haken = 'mt-0.5 w-4 h-4 shrink-0 rounded accent-[var(--fa-accent)]';
@endphp

<div class="flex flex-col gap-5" data-settings-trendradar>
    {{-- Spec 65: erst „Bearbeiten" (sperrt den Bereich für das Team), dann Abbrechen/Speichern --}}
    @php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => false])

    {{-- Bestand --}}
    <x-fa::kpis :items="[
        ['label' => 'Trends', 'value' => number_format($anzahlTrends, 0, ',', '.')],
        ['label' => 'Auf dem Radar', 'value' => number_format($aufRadar, 0, ',', '.')],
        ['label' => 'Google Trends diesen Monat', 'value' => number_format($verbraucht, 2, ',', '.') . ' $'],
    ]" />

    {{-- Rückmeldung vom Speichern --}}
    @if($meldung !== null)
        <x-fa::notice tone="ok" data-trendradar-meldung>{{ $meldung }}</x-fa::notice>
    @endif

    @if(! $hostAktiv)
        <x-fa::notice tone="warn" title="Zeitplan auf dem Server abgeschaltet">
            Die wöchentliche Messung ist auf dem Server ausgeschaltet. Deine Einstellung wird gespeichert, wirkt aber erst, wenn der Zeitplan wieder läuft.
        </x-fa::notice>
    @endif

    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    {{-- Spec 79: Google Trends über DataForSEO --}}
    <x-fa::section title="Google Trends (DataForSEO)" icon="heroicon-o-chart-bar"
                   description="Misst für jeden Trend, wie sich das Suchinteresse in Deutschland über 12 Monate entwickelt. Jede Abfrage kostet etwa {{ number_format($kostenJeAbfrage, 3, ',', '.') }} $ je Suchbegriff. Die Messung bestätigt einen Trend nie allein, sie zeigt Richtung und Hype-Spitzen.">
        <div class="flex flex-col gap-4 max-w-[60ch]" data-trendradar-dataforseo>
            @unless($anbindung)
                <x-fa::notice tone="warn">Die DataForSEO-Anbindung (Integrations-Modul) ist auf dieser Plattform nicht installiert.</x-fa::notice>
            @endunless
            <label class="flex items-start gap-2.5 cursor-pointer">
                <input type="checkbox" wire:model="dfsAktiv" class="{{ $haken }}" />
                <span>
                    <span class="block text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Wöchentlich messen</span>
                    <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Dienstags um {{ $messZeit }} Uhr alle offenen Trends. Ohne Haken nur per Knopf „Jetzt messen“ im Trend.</span>
                </span>
            </label>
            <x-fa::field label="Monatsbudget in $" for="dfs-budget" hint="Ist es erreicht, stoppt jede weitere Abfrage bis zum Monatswechsel.">
                <x-fa::input id="dfs-budget" type="number" min="0" max="500" step="0.5" wire:model="dfsBudget" numeric class="max-w-[8rem]" />
            </x-fa::field>
            <x-fa::field label="Verbindung (ID)" for="dfs-verbindung" optional hint="Leer = die DataForSEO-Verbindung eines Teammitglieds oder eine fürs Team freigegebene.">
                <x-fa::input id="dfs-verbindung" type="number" min="0" wire:model="dfsVerbindung" numeric class="max-w-[8rem]" />
            </x-fa::field>
        </div>
        @unless($sperrLesen)
            <div class="flex justify-end pt-3 border-t border-[var(--fa-line)]">
                <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern" data-trendradar-speichern>Einstellungen speichern</x-fa::button>
            </div>
        @endunless
    </x-fa::section>

    {{-- Spec 93 A: Standards für die Quellen des Trendradars --}}
    <x-fa::section title="Quellen und Eingang" icon="heroicon-o-rss"
                   description="Standard für neue Quellen und für den Stichwort-Filter vor dem Eingang. Jede Quelle kann eigene Werte haben (Trendradar → Reiter „Quellen“).">
        <div class="flex flex-col gap-4 max-w-[60ch]" data-trendradar-quellen>
            @unless($quellenHostAktiv)
                <x-fa::notice tone="info">Der tägliche Abruf ist auf dem Server noch ausgeschaltet. Quellen, Eingang und MCP funktionieren trotzdem.</x-fa::notice>
            @endunless
            <x-fa::field label="Rhythmus neuer Quellen" for="tq-rhythmus" hint="„Aus“ = neue Quellen werden nicht automatisch abgerufen.">
                <x-fa::select id="tq-rhythmus" wire:model="quellenRhythmus" :options="$rhythmen" class="max-w-[14rem]" />
            </x-fa::field>
            <x-fa::field label="Stichworte ein" for="tq-ein" optional
                         hint="Mindestens eins muss in Titel oder Teaser vorkommen; leer = alles durch. Komma-getrennt, Wortanfang genügt, Umlaute egal, Phrasen in Anführungszeichen exakt. Beispiel, keine Vorgabe: {{ implode(', ', \Platform\FoodAlchemist\Support\TrendVokabular::STICHWORT_BEISPIEL_EIN) }}.">
                <x-fa::input id="tq-ein" wire:model="stichworteEin" />
            </x-fa::field>
            <x-fa::field label="Stichworte aus" for="tq-aus" optional
                         hint="Kommt eins davon vor, landet der Artikel nicht im Eingang. Beispiel, keine Vorgabe: {{ implode(', ', \Platform\FoodAlchemist\Support\TrendVokabular::STICHWORT_BEISPIEL_AUS) }}.">
                <x-fa::input id="tq-aus" wire:model="stichworteAus" />
            </x-fa::field>
        </div>
        @unless($sperrLesen)
            <div class="flex justify-end pt-3 border-t border-[var(--fa-line)]">
                <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern" data-trendradar-quellen-speichern>Einstellungen speichern</x-fa::button>
            </div>
        @endunless
    </x-fa::section>

    {{-- Spec 93 C: Trend-Automatik --}}
    <x-fa::section title="Trend-Automatik" icon="heroicon-o-cpu-chip"
                   description="Ordnet automatische Themen nach festen Regeln ein und legt Trends an. Im Schatten-Modus rechnet sie nur (Trendradar → Reiter „Automatik“). Von Hand gesetzte Felder fasst sie nie an, Mega- und Metatrends setzt sie nie selbst.">
        <div class="flex flex-col gap-4" data-trendradar-automatik>
            <x-fa::field label="Stufe" for="ta-stufe">
                <x-fa::select id="ta-stufe" wire:model="automatikStufe" :options="$stufen" class="max-w-[28rem]" />
            </x-fa::field>
            <label class="flex items-start gap-2.5 cursor-pointer">
                <input type="checkbox" wire:model="automatikSchatten" class="{{ $haken }}" />
                <span>
                    <span class="block text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Schatten-Modus</span>
                    <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Rechnet alles, schreibt aber keine Trends — zum Kalibrieren der Schwellen.</span>
                </span>
            </label>
            <x-fa::notice tone="info">Die vorgegebenen Schwellen sind Startwerte, nicht belegt (Annahmen, Sarah Spork nennt keine Zahlen). Leer = Startwert.</x-fa::notice>
            <div class="grid gap-3 grid-cols-[repeat(auto-fill,minmax(min(100%,16rem),1fr))]">
                @foreach($regelSchluessel as $schluessel => [$label])
                    <x-fa::field :label="$label" for="ta-{{ $schluessel }}" hint="Startwert {{ $startwerte[$schluessel] }}, nicht belegt">
                        <x-fa::input id="ta-{{ $schluessel }}" wire:model="regeln.{{ $schluessel }}" placeholder="{{ $startwerte[$schluessel] }}" numeric />
                    </x-fa::field>
                @endforeach
            </div>
            <x-fa::field label="Trend-KI-Budget je Monat in €" for="ta-budget" optional hint="Leer = kein eigener Deckel (es gilt der KI-Deckel des Teams). Nur Plattform-KI zählt.">
                <x-fa::input id="ta-budget" type="number" min="0" step="0.5" wire:model="kiBudget" numeric class="max-w-[8rem]" />
            </x-fa::field>
        </div>
        @unless($sperrLesen)
            <div class="flex justify-end pt-3 border-t border-[var(--fa-line)]">
                <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern" data-trendradar-automatik-speichern>Einstellungen speichern</x-fa::button>
            </div>
        @endunless
    </x-fa::section>

    </fieldset>
</div>
