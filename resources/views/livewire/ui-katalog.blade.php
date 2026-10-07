{{-- fa-pass Welle 0: Musterseite der Bausteinbibliothek. Jede neue/umgebaute View nutzt NUR diese Bausteine. --}}
@php
    $farben = [
        ['Grund', '--fa-ground'], ['Fläche', '--fa-surface'], ['Linie', '--fa-line'], ['Text', '--fa-ink'],
        ['Text 2', '--fa-ink-2'], ['Text 3', '--fa-ink-3'], ['Akzent (Logo-Blau)', '--fa-accent'], ['Akzent weich', '--fa-accent-soft'],
        ['Freigegeben', '--fa-ok'], ['Prüfen', '--fa-warn'], ['Fehlt / enthalten', '--fa-crit'], ['Info', '--fa-info'],
    ];
    $schrift = [
        ['--fa-text-3xl', '28 px · Seitentitel', 'Basisrezepte'],
        ['--fa-text-2xl', '24 px · Hauptkennzahl', '7,74 €'],
        ['--fa-text-lg', '16 px · Abschnitt', 'Allergene und Diät'],
        ['--fa-text-base', '14 px · Fließtext', 'Herzhafter Hülsenfruchtsalat auf Basis von Berglinsen.'],
        ['--fa-text-md', '13 px · Tabelle, Formular', 'Tomatenketchup: konserviert, Bio'],
        ['--fa-text-sm', '12 px · Label, Hilfetext', 'EK je kg'],
    ];
    $zutaten = [
        ['3.5', 'kg', 'Ketchup: konserviert', 10, 34.9, 7.63],
        ['3.5', 'kg', 'Tomatenketchup: konserviert, Bio', 10, 34.9, 20.23],
        ['1', 'l', 'Cola: konserviert', 25, 10.0, 2.42],
        ['1.33', 'l', 'Leitungswasser: frisch', 15, 13.3, null],
        ['80', 'g', 'Currypulver Madrocas: trocken, gemahlen', 0, 0.8, 1.63],
    ];
@endphp

<div class="min-h-full">
    <x-foodalchemist::shell.page-navbar title="Designsystem" icon="heroicon-o-swatch" />

    <div class="max-w-[1200px] mx-auto px-6 py-8 flex flex-col gap-8">
        <x-fa::page-header title="Bausteine des Food.Alchemist" subtitle="Eine Quelle für jede Oberfläche. Neue Views nutzen nur diese Bausteine.">
            <x-slot:actions>
                <x-fa::button icon="heroicon-m-arrow-top-right-on-square" :href="route('foodalchemist.recipes.index')">Beispiel: Basisrezepte</x-fa::button>
            </x-slot:actions>
        </x-fa::page-header>

        <x-fa::section title="Farben" icon="heroicon-o-swatch" description="Akzent nur für Klickbares und die eine Hauptzahl. Zustandsfarben nur für Zustände.">
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
                @foreach($farben as [$name, $token])
                    <div class="flex flex-col gap-1.5">
                        <div class="h-14 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)]" style="background: var({{ $token }})"></div>
                        <span class="text-[length:var(--fa-text-md)] font-medium">{{ $name }}</span>
                        <code class="font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $token }}</code>
                    </div>
                @endforeach
            </div>
        </x-fa::section>

        <x-fa::section title="Schrift" icon="heroicon-o-language" description="Geist für alles, Geist Mono nur für Schlüssel. Genau sechs Größen.">
            <div class="flex flex-col divide-y divide-[var(--fa-line)]">
                @foreach($schrift as [$token, $info, $beispiel])
                    <div class="flex items-baseline justify-between gap-4 py-2.5">
                        <span style="font-size: var({{ $token }})" class="{{ in_array($token, ['--fa-text-3xl', '--fa-text-2xl', '--fa-text-lg']) ? 'font-semibold tracking-tight' : '' }} truncate">{{ $beispiel }}</span>
                        <span class="shrink-0 font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $info }}</span>
                    </div>
                @endforeach
            </div>
        </x-fa::section>

        <div class="grid gap-8 lg:grid-cols-2">
            <x-fa::section title="Knöpfe" icon="heroicon-o-cursor-arrow-rays" description="Eine Hauptaktion je Fläche, rechts außen. Löschen liegt nie neben Speichern.">
                <div class="flex flex-wrap gap-2">
                    <x-fa::button variant="primary" icon="heroicon-m-check">Speichern</x-fa::button>
                    <x-fa::button icon="heroicon-m-arrow-path">Neu rechnen</x-fa::button>
                    <x-fa::button variant="ai" icon="heroicon-m-sparkles">KI-Assistent</x-fa::button>
                    <x-fa::button variant="ghost">Abbrechen</x-fa::button>
                    <x-fa::button variant="danger" icon="heroicon-m-trash">Löschen</x-fa::button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-fa::button size="sm" variant="primary">Übernehmen</x-fa::button>
                    <x-fa::button size="sm">Als Vorlage</x-fa::button>
                    <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" />
                    <x-fa::icon-button icon="heroicon-m-trash" label="Zeile entfernen" tone="danger" size="sm" />
                    <x-fa::button disabled>Deaktiviert</x-fa::button>
                </div>
            </x-fa::section>

            <x-fa::section title="Felder" icon="heroicon-o-pencil-square" description="Label oben, Hilfetext darunter, Fehler in Rot. Kein Platzhalter als Label.">
                <div class="grid grid-cols-2 gap-3">
                    <x-fa::field label="Menge" for="kat-menge" hint="Komma als Dezimaltrenner">
                        <x-fa::input id="kat-menge" wire:model.live="menge" numeric />
                    </x-fa::field>
                    <x-fa::field label="Einheit" for="kat-einheit">
                        <x-fa::select id="kat-einheit" :options="['kg' => 'kg', 'g' => 'g', 'l' => 'l', 'ml' => 'ml']" />
                    </x-fa::field>
                    <x-fa::field label="Titel" for="kat-titel" required error="Bitte einen Titel eingeben, z. B. Tomatensauce." class="col-span-2">
                        <x-fa::input id="kat-titel" placeholder="z. B. Tomatensauce" />
                    </x-fa::field>
                    <x-fa::field label="Beschreibung" for="kat-beschreibung" optional class="col-span-2">
                        <x-fa::textarea id="kat-beschreibung" rows="2" placeholder="Anlass, Richtung, Einschränkungen" />
                    </x-fa::field>
                </div>
            </x-fa::section>
        </div>

        <x-fa::section title="Auswahl" icon="heroicon-o-adjustments-horizontal" description="2 bis 6 Optionen als Chips, ab 7 als Auswahlliste. Echte Radio-/Checkbox-Felder, per Tastatur bedienbar.">
            <div class="grid gap-6 md:grid-cols-2">
                <x-fa::choice name="kreativModus" label="Kreativ-Modus (Einfachauswahl)" :options="['frei' => 'Frei', 'voll' => 'Voll kreativ', 'nah' => 'Nah am Bestand', 'nur_bestand' => 'Nur Bestand']" />
                <x-fa::choice name="diaeten" multiple label="Diät (Mehrfachauswahl)" :options="['vegan' => 'Vegan', 'vegetarisch' => 'Vegetarisch', 'glutenfrei' => 'Glutenfrei', 'laktosefrei' => 'Laktosefrei', 'halal' => 'Halal']" />
            </div>
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Gewählt: {{ $kreativModus }} · {{ implode(', ', $diaeten) ?: 'keine Diät' }}</p>
        </x-fa::section>

        <div class="grid gap-8 lg:grid-cols-2">
            <x-fa::section title="Status, Etiketten, Signale" icon="heroicon-o-tag">
                <div class="flex flex-wrap gap-1.5">
                    @foreach(['draft', 'review', 'approved', 'tentative', 'rejected', 'deprecated', 'merged', 'stub'] as $s)<x-fa::status :value="$s" />@endforeach
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <x-fa::badge tone="crit" icon="heroicon-m-currency-euro">Preis fehlt</x-fa::badge>
                    <x-fa::badge tone="warn" icon="heroicon-m-arrow-trending-down">Ungewöhnlich niedrig</x-fa::badge>
                    <x-fa::badge tone="accent">Kornsalat</x-fa::badge>
                    <x-fa::badge tone="info">Rezept</x-fa::badge>
                </div>
                <div class="flex flex-wrap gap-4">
                    <x-fa::signal tone="ok">10 von 10 bepreist</x-fa::signal>
                    <x-fa::signal tone="warn">Konfidenz mittel</x-fa::signal>
                    <x-fa::signal tone="crit">2 Zutaten ohne Deklaration</x-fa::signal>
                </div>
            </x-fa::section>

            <x-fa::section title="Hinweise" icon="heroicon-o-megaphone">
                <x-fa::notice tone="warn" title="Kein Hintergrund-Worker aktiv">
                    Ein Auftrag bleibt in der Warteschlange, bis der Worker läuft.
                </x-fa::notice>
                <x-fa::notice tone="info">Zuerst entsteht ein textlicher Bauplan. Erst nach deiner Annahme wird daraus ein Rezept-Entwurf.</x-fa::notice>
            </x-fa::section>
        </div>

        <x-fa::section title="Kennzahlen" icon="heroicon-o-chart-bar" description="Eine Leiste, höchstens eine große Zahl. Farbe nur für Zustände.">
            <x-fa::kpis :items="[
                ['label' => 'EK je kg', 'value' => '4,07 €', 'primary' => true],
                ['label' => 'EK gesamt', 'value' => '35,73 €'],
                ['label' => 'Yield', 'value' => '8,776 kg'],
                ['label' => 'Preise', 'value' => '10 von 10', 'tone' => 'ok'],
                ['label' => 'Allergen-Konfidenz', 'value' => 'mittel', 'tone' => 'warn'],
            ]" />
        </x-fa::section>

        <x-fa::section title="Tabelle, Geld, Menge" icon="heroicon-o-table-cells" description="Zahlen rechtsbündig in gleicher Breite. Fehlender Preis wird gezeigt, nie geschätzt. Beispieldaten.">
            <div class="overflow-x-auto">
                <table class="fa-table min-w-[640px]">
                    <thead><tr><th class="num">Menge</th><th>Zutat</th><th class="num">Garverlust</th><th class="num">Anteil</th><th class="num">EK</th></tr></thead>
                    <tbody>
                        @foreach($zutaten as [$m, $e, $name, $garv, $anteil, $ek])
                            <tr>
                                <td class="num"><x-fa::menge :value="$m" :unit="$e" /></td>
                                <td><a href="#" class="text-[var(--fa-accent)] hover:underline">{{ $name }}</a></td>
                                <td class="num text-[var(--fa-ink-2)]">{{ $garv }} %</td>
                                <td class="num text-[var(--fa-ink-2)]">{{ number_format($anteil, 1, ',', '.') }} %</td>
                                <td class="num"><x-fa::money :value="$ek" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-fa::section>

        <div class="grid gap-8 lg:grid-cols-2">
            <x-fa::section title="Allergene und Diät" icon="heroicon-o-beaker" description="Neu: Enthalten und Spuren zuerst, vollständige Liste eingeklappt. Beispieldaten.">
                <x-fa::deklaration :rezept="$beispielRezept" />
            </x-fa::section>

            <x-fa::section title="Leerzustand" icon="heroicon-o-inbox">
                <x-fa::empty icon="heroicon-o-share" title="Noch keine Aroma-Anker verknüpft">
                    Mit Ankern zeigt der Food.Alchemist, wie stimmig das Gericht ist und was es ergänzt.
                    <x-slot:action><x-fa::button size="sm" icon="heroicon-m-link">Anker verknüpfen</x-fa::button></x-slot:action>
                </x-fa::empty>
            </x-fa::section>
        </div>

        <div data-fa-theme="dark" class="rounded-[var(--fa-radius-surface)] p-6 flex flex-col gap-6">
            <x-fa::page-header title="Werkbank-Modus" subtitle="Dunkle Editoren in den Farben der Navigation. Dieselben Bausteine, nur die Tokens sind umgeschaltet.">
                <x-slot:actions>
                    <x-fa::button variant="ai" icon="heroicon-m-sparkles">KI-Assistent</x-fa::button>
                    <x-fa::button variant="primary" icon="heroicon-m-check">Speichern</x-fa::button>
                </x-slot:actions>
            </x-fa::page-header>
            <x-fa::kpis :items="[
                ['label' => 'EK je kg', 'value' => '4,07 €', 'primary' => true],
                ['label' => 'EK gesamt', 'value' => '35,73 €'],
                ['label' => 'Preise', 'value' => '10 von 10', 'tone' => 'ok'],
                ['label' => 'Allergen-Konfidenz', 'value' => 'niedrig', 'tone' => 'crit'],
            ]" />
            <div class="grid gap-6 lg:grid-cols-2">
                <x-fa::section title="Zutaten" icon="heroicon-o-list-bullet" meta="5">
                    <table class="fa-table">
                        <tbody>
                            @foreach($zutaten as [$m, $e, $name, $garv, $anteil, $ek])
                                <tr><td class="num"><x-fa::menge :value="$m" :unit="$e" /></td><td>{{ $name }}</td><td class="num"><x-fa::money :value="$ek" /></td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-fa::section>
                <x-fa::section title="Allergene und Diät" icon="heroicon-o-beaker">
                    <x-fa::deklaration :rezept="$beispielRezept" />
                </x-fa::section>
            </div>
            <div class="grid gap-3 md:grid-cols-3">
                <x-fa::field label="Menge" for="kat-d-menge"><x-fa::input id="kat-d-menge" value="3,5" numeric /></x-fa::field>
                <x-fa::field label="Einheit" for="kat-d-einheit"><x-fa::select id="kat-d-einheit" :options="['kg' => 'kg', 'g' => 'g']" /></x-fa::field>
                <x-fa::choice name="kreativModus" id-prefix="dunkel" label="Kreativ-Modus" :options="['frei' => 'Frei', 'voll' => 'Voll kreativ']" />
            </div>
            <x-fa::notice tone="warn">Kein Hintergrund-Worker aktiv.</x-fa::notice>
        </div>
    </div>
</div>
