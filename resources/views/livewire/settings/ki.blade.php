{{-- M7-08: KI-Settings — Provider, Tiering, Nutzung, Kill-Switch.
     Reihenfolge wie bisher: KI an/aus · Sprachbefehl (Agenten-Modus, Panel, Vorlesen, Stimme) · Modellstufen · Nutzung.
     Häufigste Aufgabe: den Sprachbefehl einstellen bzw. Nutzung und Kosten ablesen. --}}
@php
    use Platform\FoodAlchemist\Livewire\Settings\Wissenssteuerung as WS;

    $zeileTitel = 'text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]';
    $zeileHilfe = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[70ch]';
    $schluessel = 'font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $zahl = fn ($n) => number_format((int) $n, 0, ',', '.');
    $stufenTon = ['A' => 'accent', 'B' => 'neutral', 'C' => 'info', 'D' => 'warn'];
    $nachStufe = collect($registry)->groupBy(fn ($tier) => $tier, true)->sortKeys();
@endphp

<div class="flex flex-col gap-5" data-settings-ki>
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei --}}
    @php $sperrLesen = in_array($sperr['modus'], ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    @if($meldung !== null)
        <x-fa::notice :tone="$kiAktiv ? 'ok' : 'warn'" data-ki-meldung>{{ $meldung }}</x-fa::notice>
    @endif

    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
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

    </fieldset>

    {{-- ── Modellstufen ── --}}
    <x-fa::section title="Modellstufen" icon="heroicon-o-rectangle-stack"
                   :meta="$registry->count() . ' Arbeitsschritte'"
                   description="Jeder KI-Arbeitsschritt hat eine feste Stufe, jede Stufe ein Modell. So laufen einfache Aufgaben günstig und anspruchsvolle mit dem stärkeren Modell. Nur zur Ansicht.">
        <div class="flex flex-col gap-3" data-ki-tiers>
            @foreach($nachStufe as $stufe => $schritte)
                <div class="flex flex-col gap-1.5" wire:key="stufe-{{ $stufe }}">
                    <p class="flex flex-wrap items-center gap-2 text-[length:var(--fa-text-md)]">
                        <x-fa::badge :tone="$stufenTon[$stufe] ?? 'neutral'">Stufe {{ $stufe }}</x-fa::badge>
                        <span class="font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $tiers[$stufe] ?? 'Standardmodell der Plattform' }}</span>
                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $schritte->count() }} {{ $schritte->count() === 1 ? 'Arbeitsschritt' : 'Arbeitsschritte' }}</span>
                    </p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($schritte as $key => $tier)
                            <x-fa::badge wire:key="tier-{{ $key }}" title="{{ $key }}">{{ WS::schrittLabel($key) }}</x-fa::badge>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
            Im gewählten Zeitraum tatsächlich genutzt:
            <span class="font-mono text-[var(--fa-ink-2)]">{{ $aktiveModelle->isEmpty() ? 'keine Aufrufe' : $aktiveModelle->implode(', ') }}</span>
        </p>
        @if($registryLuecken->isNotEmpty())
            <x-fa::notice tone="warn" title="Aufrufe ohne hinterlegte Stufe" data-ki-registry-luecken>
                Diese Arbeitsschritte stehen im Nutzungsprotokoll, haben aber keinen hinterlegten KI-Auftrag:
                {{ $registryLuecken->map(fn ($k) => WS::schrittLabel($k))->implode(', ') }}
            </x-fa::notice>
        @endif
    </x-fa::section>

    {{-- ── Nutzung ── --}}
    <x-fa::section title="Nutzung und Kosten" icon="heroicon-o-chart-bar" description="Alle KI-Aufrufe dieses Teams, je Arbeitsschritt und Modell.">
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
                            <th>Stufe</th>
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
                                $kostenKey = $z->feature . '|' . ($z->tier ?? '') . '|' . ($z->model ?? '');
                            @endphp
                            <tr wire:key="st-{{ $z->feature }}-{{ $z->tier }}-{{ $z->model ?? 'ohne-modell' }}">
                                <td>
                                    <span class="block text-[var(--fa-ink)]">{{ WS::schrittLabel($z->feature) }}</span>
                                    <span class="block {{ $schluessel }}">{{ $z->feature }}</span>
                                </td>
                                <td>@if($z->tier)<x-fa::badge :tone="$stufenTon[$z->tier] ?? 'neutral'">{{ $z->tier }}</x-fa::badge>@else<span class="text-[var(--fa-ink-3)]">–</span>@endif</td>
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
            </p>
        @endif
    </x-fa::section>
</div>
