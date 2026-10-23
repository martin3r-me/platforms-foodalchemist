{{-- M7-08: KI-Settings — Provider, Tiering, Nutzung, Kill-Switch.
     Reihenfolge wie bisher: KI an/aus · Sprachbefehl (Agenten-Modus, Panel, Vorlesen, Stimme) · Modellstufen · Nutzung.
     Häufigste Aufgabe: den Sprachbefehl einstellen bzw. Nutzung und Kosten ablesen. --}}
@php
    use Platform\FoodAlchemist\Livewire\Settings\Wissenssteuerung as WS;
    use Platform\FoodAlchemist\Support\KiKlassen;

    $zeileTitel = 'text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]';
    $zeileHilfe = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[70ch]';
    $schluessel = 'font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $zahl = fn ($n) => number_format((int) $n, 0, ',', '.');
    $stufenTon = ['stark' => 'accent', 'mittel' => 'info', 'leicht' => 'neutral', 'S' => 'accent', 'M' => 'info', 'L' => 'neutral', 'A' => 'accent', 'B' => 'neutral', 'C' => 'info', 'D' => 'warn'];
    $zeige = fn (string $a) => $abschnitt === null || $abschnitt === $a;   // E-4: der Unter-Reiter zeigt nur seinen Abschnitt, null = alles
    $nachKlasse = collect(KiKlassen::ALLE)->mapWithKeys(fn ($k) => [$k => collect($registry)->filter(fn ($kl) => $kl === $k)]);
@endphp

<div class="flex flex-col gap-5" data-settings-ki>
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei --}}
    @php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    @if($meldung !== null)
        <x-fa::notice :tone="$kiAktiv ? 'ok' : 'warn'" data-ki-meldung>{{ $meldung }}</x-fa::notice>
    @endif

    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    @if($zeige('anbieter'))
    {{-- ── KI an/aus (Kill-Switch) ── --}}
    <x-fa::section title="KI für dieses Team" icon="heroicon-o-power"
                   description="Schaltet alle KI-Funktionen dieses Teams auf einmal ab, zum Beispiel bei unerwarteten Kosten. Daten und Einstellungen bleiben erhalten.">
        <x-slot:actions>
            <x-fa::badge :tone="$kiAktiv ? 'ok' : 'crit'" icon="{{ $kiAktiv ? 'heroicon-m-check-circle' : 'heroicon-m-no-symbol' }}">{{ $kiAktiv ? 'Eingeschaltet' : 'Ausgeschaltet' }}</x-fa::badge>
        </x-slot:actions>

        @if(! $kiAktiv)
            <x-fa::notice tone="crit" data-ki-aus-banner>
                Die KI ist für dieses Team ausgeschaltet. Jeder KI-Aufruf wird gestoppt, KI-Knöpfe melden das beim Anklicken.
            </x-fa::notice>
        @endif

        <div class="flex justify-end">
            {{-- Icon steht VOR dem Ausdruck: eine Icon-Direktive in einer Echo-Klammer kompiliert nicht. --}}
            <x-fa::button :variant="$kiAktiv ? 'danger' : 'primary'" icon="heroicon-m-power" wire:click="umschalten" data-ki-kill-switch>
                {{ $kiAktiv ? 'KI für dieses Team ausschalten' : 'KI wieder einschalten' }}
            </x-fa::button>
        </div>
    </x-fa::section>

    @endif

    @if($zeige('sprachbefehl'))
    {{-- ── Sprachbefehl ── --}}
    <x-fa::section title="Sprachbefehl" icon="heroicon-o-microphone"
                   description="Der Agent hinter dem Sprachbefehl: was er selbst ausführen darf und wie er antwortet. Änderungen gelten sofort.">

        {{-- Spec 53/F: Agenten-Modus des Sprachbefehls — Claude-Code-Mode-Switcher-Vorbild.
             Radio-Gruppen-Muster übernommen aus settings/einkauf.blade.php (Lead-LA-Strategie). --}}
        <fieldset class="flex flex-col gap-2" data-settings-sprachagent>
            <legend class="mb-1.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Was darf der Agent?</legend>
            <div class="grid gap-2 grid-cols-[repeat(auto-fit,minmax(min(100%,16rem),1fr))]">
                @foreach(\Platform\FoodAlchemist\Services\TeamSettingsService::VOICE_AGENT_MODES as $m)
                    @php
                        $gewaehlt = $sprachAgentModus === $m;
                    @endphp
                    <label wire:key="modus-{{ $m }}"
                           class="flex items-start gap-2.5 p-3 rounded-[var(--fa-radius-surface)] border cursor-pointer transition-colors duration-150 {{ $gewaehlt ? 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)]' : 'border-[var(--fa-line)] hover:bg-[var(--fa-hover)]' }}">
                        <input type="radio" wire:model.live="sprachAgentModus" value="{{ $m }}" class="mt-0.5 w-4 h-4 shrink-0 accent-[var(--fa-accent)]" data-sprachagent-modus="{{ $m }}" />
                        <span class="min-w-0">
                            <span class="block {{ $zeileTitel }} {{ $gewaehlt ? 'text-[var(--fa-accent)]' : '' }}">{{ \Platform\FoodAlchemist\Livewire\Settings\Ki::MODUS_LABEL[$m] }}</span>
                            <span class="block mt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ \Platform\FoodAlchemist\Livewire\Settings\Ki::MODUS_BESCHREIBUNG[$m] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div class="flex flex-col divide-y divide-[var(--fa-line)] border-t border-[var(--fa-line)] mt-1">
            {{-- Spec 55: der Agent lebt nur noch als Panel in der Planungs-Leitstelle, kein schwebendes
                 Element mehr auf jeder Seite. --}}
            <div class="flex flex-wrap items-center justify-between gap-3 py-3" data-settings-sprachagent-panel-planung>
                <div class="min-w-0">
                    <p class="{{ $zeileTitel }}">Agenten-Panel in der Planung</p>
                    <p class="{{ $zeileHilfe }}">Zeigt das Sprachbefehl-Panel in der Planungs-Leitstelle, anfangs eingeklappt. Standard: an.</p>
                </div>
                <div class="flex items-center gap-3">
                    <x-fa::badge :tone="$sprachAgentPanelPlanung ? 'ok' : 'neutral'">{{ $sprachAgentPanelPlanung ? 'An' : 'Aus' }}</x-fa::badge>
                    <x-fa::button size="sm" icon="heroicon-m-microphone" wire:click="sprachAgentPanelPlanungUmschalten" data-sprachagent-panel-planung-switch>
                        {{ $sprachAgentPanelPlanung ? 'Panel ausblenden' : 'Panel einblenden' }}
                    </x-fa::button>
                </div>
            </div>

            {{-- Spec 53/F (3): Konversations-Modus — Antworten vorlesen + Stimmen-Wahl. --}}
            <div class="flex flex-wrap items-center justify-between gap-3 py-3" data-settings-sprachagent-tts>
                <div class="min-w-0">
                    <p class="{{ $zeileTitel }}">Antworten vorlesen</p>
                    <p class="{{ $zeileHilfe }}">Die Antwort kommt zusätzlich zum Text als Sprachausgabe.</p>
                </div>
                <div class="flex items-center gap-3">
                    <x-fa::badge :tone="$sprachTtsVorlesen ? 'ok' : 'neutral'">{{ $sprachTtsVorlesen ? 'An' : 'Aus' }}</x-fa::badge>
                    <x-fa::button size="sm" icon="heroicon-m-speaker-wave" wire:click="sprachTtsVorlesenUmschalten" data-sprachagent-tts-switch>
                        {{ $sprachTtsVorlesen ? 'Vorlesen ausschalten' : 'Vorlesen einschalten' }}
                    </x-fa::button>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 py-3" data-settings-sprachagent-tts-stimme>
                <div class="min-w-0">
                    <label for="ki-tts-stimme" class="{{ $zeileTitel }}">Stimme</label>
                    <p class="{{ $zeileHilfe }}">Mit dieser Stimme werden Antworten vorgelesen.</p>
                </div>
                <x-fa::select id="ki-tts-stimme" wire:model.live="sprachTtsStimme" class="w-44">
                    @foreach(\Platform\FoodAlchemist\Services\TeamSettingsService::VOICE_TTS_STIMMEN as $stimme)
                        <option value="{{ $stimme }}">{{ ucfirst($stimme) }}</option>
                    @endforeach
                </x-fa::select>
            </div>
        </div>
    </x-fa::section>

    @endif
    </fieldset>

    @if($zeige('modellstufen'))
    {{-- ── Modellstufen: drei KI-Klassen ── --}}
    <x-fa::section title="Modellstufen" icon="heroicon-o-rectangle-stack"
                   :meta="$registry->count() . ' Arbeitsschritte'"
                   :description="'Jeder KI-Arbeitsschritt gehört zu genau einer Klasse, jede Klasse hat ein Modell. So laufen einfache Aufgaben günstig und anspruchsvolle mit dem stärksten Modell. ' . ($klassenPflege ? 'Die Klasse je Schritt stellst du unten ein (gilt für alle Teams); die Modelle je Klasse stehen auf dem Server.' : 'Nur zur Ansicht — die Modelle werden auf dem Server eingetragen.')">
        <div class="flex flex-col gap-3" data-ki-klassen>
            @foreach($nachKlasse as $klasse => $schritte)
                <div class="flex flex-col gap-1.5" wire:key="klasse-{{ $klasse }}">
                    <p class="flex flex-wrap items-center gap-2 text-[length:var(--fa-text-md)]">
                        <x-fa::badge :tone="$stufenTon[$klasse] ?? 'neutral'">{{ KiKlassen::label($klasse) }}</x-fa::badge>
                        <span class="font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $klassen[$klasse]['model'] ?? 'Standardmodell der Plattform' }}</span>
                        @if(! empty($klassen[$klasse]['reasoning']))
                            <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Reasoning: {{ $klassen[$klasse]['reasoning'] }}</span>
                        @endif
                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $schritte->count() }} {{ $schritte->count() === 1 ? 'Arbeitsschritt' : 'Arbeitsschritte' }}</span>
                    </p>
                    <p class="{{ $zeileHilfe }}">{{ KiKlassen::beschreibung($klasse) }}</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($schritte as $key => $kl)
                            <x-fa::badge wire:key="klasse-{{ $key }}" title="{{ $key }}">{{ WS::schrittLabel($key) }}</x-fa::badge>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
        @if($klassenPflege)
            @include('foodalchemist::livewire.settings.partials.ki-klassen-pflege')
        @endif
        @if(! empty($abgeloest))
            <x-fa::notice tone="warn" title="Alte Stufen-Einstellungen ohne Wirkung" data-ki-abgeloest>
                Auf diesem Server sind noch {{ implode(', ', $abgeloest) }} gesetzt. Die Stufen A–D gibt es nicht mehr; stattdessen gelten die drei Klassen oben
                (FOODALCHEMIST_AI_KLASSE_STARK / _MITTEL / _LEICHT, optional je Klasse _REASONING).
            </x-fa::notice>
        @endif
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
            Im gewählten Zeitraum tatsächlich genutzt:
            <span class="font-mono text-[var(--fa-ink-2)]">{{ $aktiveModelle->isEmpty() ? 'keine Aufrufe' : $aktiveModelle->implode(', ') }}</span>
        </p>
        @if($registryLuecken->isNotEmpty())
            <x-fa::notice tone="warn" title="Aufrufe ohne hinterlegte Klasse" data-ki-registry-luecken>
                Diese Arbeitsschritte stehen im Nutzungsprotokoll, haben aber keinen hinterlegten KI-Auftrag:
                {{ $registryLuecken->map(fn ($k) => WS::schrittLabel($k))->implode(', ') }}
            </x-fa::notice>
        @endif
    </x-fa::section>

    @endif

    @if($zeige('nutzung'))
    {{-- ── KI-Verbrauch des Mandanten (Dominique 23.10.2026): Standort sieht sich, Hauptteam den ganzen Mandanten + je Standort ── --}}
    @if($verbrauch !== null)
        @php
            $vEur = fn ($v) => number_format((float) $v, 2, ',', '.').' €';
            $vZahl = fn ($v) => number_format((int) $v, 0, ',', '.');
        @endphp
        <x-fa::section title="KI-Verbrauch" icon="heroicon-o-banknotes" data-ki-verbrauch
                       description="{{ $verbrauch['ist_haupt'] ? 'Was KI für euren Betrieb kostet — alle Standorte zusammen und je Standort.' : 'Was KI für diesen Standort kostet.' }} Euro mit dem Preis und Kurs zum Zeitpunkt des Aufrufs.">
            <x-slot:actions>
                <x-fa::choice name="verbrauchZeitraum" :options="$verbrauchOptionen" />
            </x-slot:actions>
            <p class="{{ $zeileHilfe }}">{{ $verbrauch['von']->format('d.m.Y') }} bis {{ $verbrauch['bis']->format('d.m.Y') }}</p>

            @if($verbrauch['deckel'] !== null && $verbrauch['deckel']['eur'] !== null)
                @php $anteil = $verbrauch['deckel']['eur'] > 0 ? min(100, round($verbrauch['deckel']['verbrauch_monat'] / $verbrauch['deckel']['eur'] * 100)) : 100; @endphp
                <div class="flex flex-col gap-1" data-ki-verbrauch-deckel>
                    <p class="text-[length:var(--fa-text-sm)]">
                        {{ $vEur($verbrauch['deckel']['verbrauch_monat']) }} von {{ $vEur($verbrauch['deckel']['eur']) }} in diesem Monat
                        {{ $verbrauch['deckel']['quelle'] === 'projekt_standard' ? '(KI-Kontingent der Projektphase)' : '(KI-Budget)' }}
                    </p>
                    <div class="h-2 rounded-full bg-[var(--fa-line)] overflow-hidden"><div class="h-full {{ $anteil >= 100 ? 'bg-[var(--fa-crit)]' : ($anteil >= 80 ? 'bg-[var(--fa-warn)]' : 'bg-[var(--fa-accent)]') }}" style="width: {{ $anteil }}%"></div></div>
                    <p class="{{ $zeileHilfe }}">Ist der Betrag erreicht, ist KI bis zum 1. des nächsten Monats gesperrt{{ $verbrauch['deckel']['quelle'] === 'projekt_standard' ? ' oder bis zur Lizenz' : '' }}.</p>
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="fa-table min-w-[560px]" data-ki-verbrauch-tabelle>
                    <thead><tr><th>{{ $verbrauch['ist_haupt'] ? 'Standort / Art' : 'Art' }}</th><th class="num">Aufrufe</th><th class="num">Fehlläufe</th><th class="num">Euro</th></tr></thead>
                    <tbody>
                        @if($verbrauch['ist_haupt'])
                            @foreach($verbrauch['standorte'] as $st)
                                <tr wire:key="kv-st-{{ $st['team_id'] }}" data-ki-verbrauch-standort="{{ $st['team_id'] }}">
                                    <td>{{ $st['label'] }}@if($st['ist_haupt']) <span class="text-[var(--fa-ink-3)]">(Hauptteam)</span>@endif</td>
                                    <td class="num">{{ $vZahl($st['calls'] - $st['fehler']) }}</td><td class="num">{{ $vZahl($st['fehler']) }}</td><td class="num">{{ $vEur($st['eur']) }}</td>
                                </tr>
                            @endforeach
                        @endif
                        @foreach($verbrauch['klassen'] as $k)
                            <tr wire:key="kv-k-{{ $k['label'] }}" class="text-[var(--fa-ink-2)]" data-ki-verbrauch-art>
                                <td>{{ $verbrauch['ist_haupt'] ? 'Art: ' : '' }}{{ $k['label'] }}</td>
                                <td class="num">{{ $vZahl($k['calls'] - $k['fehler']) }}</td><td class="num">{{ $vZahl($k['fehler']) }}</td><td class="num">{{ $vEur($k['eur']) }}</td>
                            </tr>
                        @endforeach
                        @if($verbrauch['summe']['calls'] === 0)
                            <tr><td colspan="4" class="text-[var(--fa-ink-3)]">Im Zeitraum kein KI-Verbrauch.</td></tr>
                        @endif
                    </tbody>
                    <tfoot>
                        <tr data-ki-verbrauch-summe class="font-semibold">
                            <td>Summe</td><td class="num">{{ $vZahl($verbrauch['summe']['calls'] - $verbrauch['summe']['fehler']) }}</td><td class="num">{{ $vZahl($verbrauch['summe']['fehler']) }}</td><td class="num">{{ $vEur($verbrauch['summe']['eur']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="{{ $zeileHilfe }}" data-ki-verbrauch-luecke>
                Fehlläufe kosten, soweit der Anbieter dabei schon gerechnet hat, und sind im Betrag enthalten. Ohne Embeddings (Wissenssuche) — die werden noch nicht erfasst.
                @if($verbrauch['summe']['ohne_preis'] > 0) {{ $verbrauch['summe']['ohne_preis'] }} Aufrufe ohne hinterlegten Preis sind nicht im Betrag enthalten.@endif
            </p>
        </x-fa::section>
    @endif

    {{-- ── Nutzung ── --}}
    <x-fa::section title="Nutzung und Kosten" icon="heroicon-o-chart-bar" description="{{ $stammSicht ? 'Alle KI-Aufrufe dieses Teams und die der globalen Stamm-Arbeiten (ohne Team), je Arbeitsschritt und Modell.' : 'Alle KI-Aufrufe dieses Teams, je Arbeitsschritt und Modell.' }}">
        <x-slot:actions>
            <x-fa::choice name="zeitraum" :options="$zeitraumOptionen" />
        </x-slot:actions>

        @if($statistik->isEmpty())
            <x-fa::empty icon="heroicon-o-chart-bar" title="Keine KI-Aufrufe im gewählten Zeitraum" compact>
                Einen längeren Zeitraum wählen oder eine KI-Funktion nutzen. Jeder Aufruf erscheint danach hier.
            </x-fa::empty>
        @else
            <div class="overflow-x-auto -mx-4">
                <table class="fa-table min-w-[960px]" data-ki-statistik>
                    <thead>
                        <tr>
                            <th>Arbeitsschritt</th>
                            <th>Klasse</th>
                            <th>Modell</th>
                            <th class="num">Aufrufe</th>
                            <th class="num" title="Eingabemenge in Texteinheiten der KI">Eingabe</th>
                            <th class="num" title="Teil der Eingabe, der aus dem Zwischenspeicher kam und günstiger berechnet wird">davon gespeichert</th>
                            <th class="num" title="Ausgabemenge in Texteinheiten der KI">Ausgabe</th>
                            <th class="num">Fehler</th>
                            <th class="num" title="Vorschläge, die übernommen wurden">Übernommen</th>
                            <th class="num">Kosten ca.</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($statistik as $z)
                            @php
                                $kostenKey = $z->feature . '|' . ($z->tier ?? '') . '|' . ($z->model ?? '') . '|' . (int) $z->ist_global;
                            @endphp
                            <tr wire:key="st-{{ $z->feature }}-{{ $z->tier }}-{{ $z->model ?? 'ohne-modell' }}-{{ (int) $z->ist_global }}" data-ki-zeile data-global="{{ (int) $z->ist_global }}">
                                <td>
                                    <span class="block text-[var(--fa-ink)]">{{ WS::schrittLabel($z->feature) }}</span>
                                    <span class="block {{ $schluessel }}">{{ $z->feature }}</span>
                                    @if($z->ist_global)<x-fa::badge tone="neutral" title="Globaler Stamm-Job ohne Team (z. B. Non-Food-Erkennung, Produktpass lesen). Nur das Master-Team sieht diese Zeilen." data-ki-global>Global (Stamm)</x-fa::badge>@endif
                                </td>
                                <td>@if($z->tier)<x-fa::badge :tone="$stufenTon[$z->tier] ?? 'neutral'">{{ KiKlassen::logLabel($z->tier) }}</x-fa::badge>@else<span class="text-[var(--fa-ink-3)]">–</span>@endif</td>
                                <td class="font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] whitespace-nowrap">{{ $z->model ?? '–' }}</td>
                                <td class="num">{{ $zahl($z->calls) }}</td>
                                <td class="num text-[var(--fa-ink-2)]">{{ $zahl($z->t_in) }}</td>
                                <td class="num text-[var(--fa-ink-2)]">{{ $zahl($z->t_cached) }}</td>
                                <td class="num text-[var(--fa-ink-2)]">{{ $zahl($z->t_out) }}</td>
                                <td class="num {{ $z->errors > 0 ? 'text-[var(--fa-crit)] font-medium' : 'text-[var(--fa-ink-3)]' }}">{{ $zahl($z->errors) }}</td>
                                <td class="num text-[var(--fa-ink-2)]">{{ $zahl($z->accepted) }}</td>
                                <td class="num" data-ki-kosten>
                                    @if(($kosten[$kostenKey] ?? null) === null)
                                        <x-fa::badge tone="warn" title="Für dieses Modell ist kein Preis hinterlegt">Preis fehlt</x-fa::badge>
                                    @else
                                        {{ number_format($kosten[$kostenKey], 4, ',', '.') }}&nbsp;{{ $kostenSymbol }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-[var(--fa-line-strong)]">
                            <td colspan="9" class="text-right text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                                Summe ca. (genutztes Modell × offizielle Listenpreise, {{ $kostenWaehrung }}, ohne Steuer)
                                @if($kursZeile ?? null)<span class="block" data-ki-kurs>{{ $kursZeile }}</span>@endif
                                @if($kostenUnbekannt > 0)
                                    <span class="block text-[var(--fa-warn)]">{{ $kostenUnbekannt }} {{ $kostenUnbekannt === 1 ? 'Zeile' : 'Zeilen' }} ohne bekannten Preis nicht enthalten</span>
                                @endif
                            </td>
                            <td class="num font-semibold text-[var(--fa-ink)]" data-ki-kosten-gesamt>{{ number_format($kostenGesamt, 2, ',', '.') }} {{ $kostenSymbol }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                Mengen in Texteinheiten der KI. Bei älteren Aufrufen ohne gespeicherten Zwischenspeicher-Anteil wird vorsichtig der volle Eingabepreis angesetzt.
                Fehlgeschlagene Aufrufe stehen unter „Fehler“ (ohne Modell, daher ohne Preis). <strong>Embeddings</strong> (Suche, Ähnlichkeit) laufen über den Plattform-Kern und sind hier <strong>nicht enthalten</strong>.
            </p>
        @endif
    </x-fa::section>

    {{-- ── KI-Abrechnung je Mandant (nur Master-Team) ── --}}
    @if($abrechnung !== null)
        @php
            $usd = fn ($v) => number_format((float) $v, 2, ',', '.').' $';
            $eur = fn ($v) => number_format((float) $v, 2, ',', '.').' €';
        @endphp
        <x-fa::section title="Abrechnung je Mandant" icon="heroicon-o-banknotes"
                       description="KI-Verbrauch je Kunden-Hauptteam mit dem Preis und Kurs, die beim Aufruf galten (eingefroren). Aufrufe vor Einführung der Abrechnung zählen nicht. Plattform = Master-Team und Läufe ohne Nutzer, nicht abrechenbar.">
            <x-slot:actions>
                <x-fa::choice name="abrechnungZeitraum" :options="$abrechnungOptionen" />
            </x-slot:actions>
            <p class="{{ $zeileHilfe }}">{{ $abrechnung['von']->format('d.m.Y') }} bis {{ $abrechnung['bis']->format('d.m.Y') }}</p>
            <div class="overflow-x-auto">
                <table class="fa-table min-w-[820px]" data-ki-abrechnung>
                    <thead>
                        <tr>
                            <th>Mandant</th><th>Klasse</th>
                            <th class="num">Aufrufe</th><th class="num">Eingabe</th><th class="num">davon gespeichert</th><th class="num">Ausgabe</th>
                            <th class="num">USD</th><th class="num">EUR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($abrechnung['mandanten'] as $m)
                            <tr wire:key="abr-{{ $m['team_id'] }}" data-ki-abrechnung-mandant="{{ $m['team_id'] }}" class="font-medium">
                                <td>
                                    {{ $m['name'] }}
                                    @unless($m['abrechenbar'])<x-fa::badge tone="neutral" title="Kein aktiver Mandant (ohne Mandanten-Zeile oder nicht Status aktiv): der Verbrauch wird gezeigt, aber nicht abgerechnet." data-ki-nicht-abrechenbar>nicht abrechenbar</x-fa::badge>@endunless
                                </td>
                                <td>Summe</td>
                                <td class="num">{{ $zahl($m['summe']['calls']) }}</td>
                                <td class="num">{{ $zahl($m['summe']['t_in']) }}</td><td class="num">{{ $zahl($m['summe']['t_cached']) }}</td><td class="num">{{ $zahl($m['summe']['t_out']) }}</td>
                                <td class="num">{{ $usd($m['summe']['usd']) }}</td>
                                <td class="num">{{ $eur($m['summe']['eur']) }}
                                    @if($m['summe']['ohne_preis'] > 0)<x-fa::badge tone="warn" title="Aufrufe von Modellen ohne hinterlegten Preis: in der Summe NICHT enthalten" data-ki-ohne-preis>{{ $m['summe']['ohne_preis'] }} ohne Preis</x-fa::badge>@endif
                                    @if($m['summe']['ohne_kurs'] > 0)<x-fa::badge tone="warn" title="Aufrufe ohne gespeicherten USD/EUR-Kurs: nur in USD enthalten" data-ki-ohne-kurs>{{ $m['summe']['ohne_kurs'] }} ohne Kurs</x-fa::badge>@endif
                                </td>
                            </tr>
                            @foreach($m['klassen'] as $k)
                                <tr wire:key="abr-{{ $m['team_id'] }}-{{ $k['label'] }}">
                                    <td></td><td class="text-[var(--fa-ink-2)]">{{ $k['label'] }}</td>
                                    <td class="num">{{ $zahl($k['calls']) }}</td>
                                    <td class="num text-[var(--fa-ink-2)]">{{ $zahl($k['t_in']) }}</td><td class="num text-[var(--fa-ink-2)]">{{ $zahl($k['t_cached']) }}</td><td class="num text-[var(--fa-ink-2)]">{{ $zahl($k['t_out']) }}</td>
                                    <td class="num text-[var(--fa-ink-2)]">{{ $usd($k['usd']) }}</td><td class="num text-[var(--fa-ink-2)]">{{ $eur($k['eur']) }}</td>
                                </tr>
                            @endforeach
                        @empty
                            <tr><td colspan="8" class="text-[var(--fa-ink-3)]">Im Zeitraum kein Mandanten-Verbrauch.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr data-ki-abrechnung-summe>
                            <td>Abrechenbar gesamt</td><td></td>
                            <td class="num">{{ $zahl($abrechnung['summe_abrechenbar']['calls']) }}</td><td></td><td></td><td></td>
                            <td class="num">{{ $usd($abrechnung['summe_abrechenbar']['usd']) }}</td><td class="num">{{ $eur($abrechnung['summe_abrechenbar']['eur']) }}</td>
                        </tr>
                        <tr data-ki-abrechnung-plattform class="text-[var(--fa-ink-3)]">
                            <td>Plattform (nicht abrechenbar)</td><td></td>
                            <td class="num">{{ $zahl($abrechnung['plattform']['calls']) }}</td><td></td><td></td><td></td>
                            <td class="num">{{ $usd($abrechnung['plattform']['usd']) }}</td><td class="num">{{ $eur($abrechnung['plattform']['eur']) }}</td>
                        </tr>
                        @if($abrechnung['unbekannt']['calls'] > 0)
                            <tr data-ki-abrechnung-unbekannt class="text-[var(--fa-crit)]">
                                <td>Verursacher unbekannt</td><td></td>
                                <td class="num">{{ $zahl($abrechnung['unbekannt']['calls']) }}</td><td></td><td></td><td></td>
                                <td class="num">{{ $usd($abrechnung['unbekannt']['usd']) }}</td><td class="num">{{ $eur($abrechnung['unbekannt']['eur']) }}</td>
                            </tr>
                        @endif
                    </tfoot>
                </table>
            </div>
        </x-fa::section>
    @endif
    @endif
</div>
