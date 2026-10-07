{{-- Einstellungen → Trendradar: Automatisierung + Signal (pro Team) + manueller Import/Cluster.
     Häufigste Aufgabe: die tägliche Automatisierung an- oder abschalten. --}}
@php
    $haken = 'mt-0.5 w-4 h-4 shrink-0 rounded accent-[var(--fa-accent)]';
@endphp

<div class="flex flex-col gap-5" data-settings-trendradar>
    {{-- Spec 65: erst „Bearbeiten" (sperrt den Bereich für das Team), dann Abbrechen/Speichern --}}
    @php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => false])

    {{-- Bestand --}}
    <x-fa::kpis :items="[
        ['label' => 'Trend-Dossiers', 'value' => number_format($trendDocs, 0, ',', '.')],
        ['label' => 'Eingeordnet', 'value' => number_format($geclustert, 0, ',', '.')],
        ['label' => 'Noch nicht eingeordnet', 'value' => number_format($ungeclustert, 0, ',', '.'), 'tone' => $ungeclustert > 0 ? 'warn' : null],
        ['label' => 'Klassen zur Freigabe', 'value' => number_format($tentativeKlassen, 0, ',', '.'), 'tone' => $tentativeKlassen > 0 ? 'warn' : null],
    ]" />

    {{-- Rückmeldung von Speichern UND Einlesen: steht oben, weil sie zu beiden Abschnitten gehört. --}}
    @if($meldung !== null)
        <x-fa::notice tone="ok" data-trendradar-meldung>{{ $meldung }}</x-fa::notice>
    @endif

    @if(! $kiAktiv)
        <x-fa::notice tone="crit" title="KI ist ausgeschaltet">
            Für dieses Team ist die KI abgeschaltet. Die Automatisierung erzeugt nichts, bis die KI unter Einstellungen › KI wieder eingeschaltet ist.
        </x-fa::notice>
    @endif
    @if(! $hostAktiv)
        <x-fa::notice tone="warn" title="Zeitplan auf dem Server abgeschaltet">
            Der tägliche Lauf ist auf dem Server ausgeschaltet. Deine Einstellung wird gespeichert, wirkt aber erst, wenn der Zeitplan wieder läuft.
        </x-fa::notice>
    @endif

    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    {{-- Automatisierung --}}
    <x-fa::section title="Tägliche Konzept-Automatisierung" icon="heroicon-o-clock"
                   description="Holt jeden Morgen um {{ $zeit }} Uhr die stärksten Trends und legt daraus Konzept-Entwürfe im Concepter an.">
        <div class="flex flex-col gap-4 max-w-[60ch]">
            <label class="flex items-start gap-2.5 cursor-pointer">
                <input type="checkbox" wire:model="autoEnabled" class="{{ $haken }}" />
                <span>
                    <span class="block text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Automatisierung einschalten</span>
                    <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Ohne Haken läuft nichts automatisch. Der Knopf unten bleibt trotzdem nutzbar.</span>
                </span>
            </label>

            <x-fa::field label="Konzepte je Lauf" for="trend-limit" hint="1 bis 10 Entwürfe pro Morgen.">
                <x-fa::input id="trend-limit" type="number" min="1" max="10" wire:model="limit" numeric class="max-w-[8rem]" />
            </x-fa::field>

            <label class="flex items-start gap-2.5 cursor-pointer">
                <input type="checkbox" wire:model="signalEnabled" class="{{ $haken }}" />
                <span>
                    <span class="block text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Neue Entwürfe als Signal melden</span>
                    <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Legt nach jedem Lauf einen Hinweis in die Signale, damit niemand die Entwürfe übersieht.</span>
                </span>
            </label>
        </div>

        @unless($sperrLesen)
            <div class="flex justify-end pt-3 border-t border-[var(--fa-line)]">
                <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="speichern" data-trendradar-speichern>Einstellungen speichern</x-fa::button>
            </div>
        @endunless
    </x-fa::section>

    {{-- Manueller Anstoß --}}
    <x-fa::section title="Trends jetzt einlesen" icon="heroicon-o-arrow-path"
                   description="Holt neue Trend-Dossiers aus der Wissensbasis und ordnet sie per KI in Kategorien und Klassen ein. Läuft im Hintergrund. Neue Klassen erscheinen als vorläufig im Trendradar und warten dort auf deine Freigabe.">
        <div class="flex justify-end">
            <x-fa::button variant="ai" icon="heroicon-m-sparkles" wire:click="jetztImportieren"
                          wire:confirm="Trends jetzt einlesen und einordnen? Dafür wird die KI für jeden neuen Trend aufgerufen.">
                Trends einlesen und einordnen
            </x-fa::button>
        </div>
    </x-fa::section>
    </fieldset>
</div>
