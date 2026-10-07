{{-- Erstell-Reiter (Basisrezept ODER Gericht): EIGENES Briefing + EIGENE Leitplanken je Scope + Erstellen + Wissen vorab.
     Erwartet: $scope (rezept|gericht), $vk (bool), $goLabel, $goIcon. Jeder Reiter ist unabhängig (eingabe.{scope}
     + regler.{scope}). Erstellen schaltet auf den Reiter „Fortschritt" (Alpine-Tab `worker`).
     fa-pass (Laptop-Höhe): die klebende Erstell-Leiste unten ist EINE kompakte Zeile. Wissens-Vorschau und
     Composer-Hinweis stehen davor im normalen Fluss, damit die Leiste nicht den halben Bildschirm
     überdeckt. Felder, Bindings, Diktat-Ziel und data-Marker unverändert. --}}
@php
    $titelEcho = trim((string) ($eingabe[$scope]['titel'] ?? ''));
    // Leitplanken-Befund: die KI liefert Feldschlüssel (occasion, ziel_portion_g …). Für die Anzeige lesbar machen.
    $feldNamen = [
        'occasion' => 'Anlass', 'serviceform' => 'Servierform', 'kompositions_stil' => 'Kompositionsstil',
        'pax' => 'Personen', 'ziel_portion_g' => 'Portionsgewicht', 'saison' => 'Saison', 'ziel_we_pct' => 'Wareneinsatz-Ziel',
        'ziel_einheit' => 'Ziel-Einheit', 'ziel_menge' => 'Ziel-Menge', 'ziel_vk' => 'Ziel-Verkaufspreis',
        'convenience' => 'Convenience', 'frische' => 'Frische', 'bestand' => 'Bestand', 'bio_praeferenz' => 'Bio',
        'level' => 'Niveau', 'sektor' => 'Sektor', 'diaet_hart' => 'Ernährungsform', 'allergen_nogo' => 'Allergen-Ausschluss',
        'aroma' => 'Aroma', 'aroma_kueche' => 'Küche', 'menue_typ' => 'Menü oder Buffet', 'menue_gaenge' => 'Gänge',
        'menue_preis_min' => 'Preis ab', 'menue_preis_ziel' => 'Zielpreis', 'menue_preis_max' => 'Preis bis',
        'menue_quote_vegan' => 'Anteil vegan', 'menue_quote_vegetarisch' => 'Anteil vegetarisch', 'menue_balance' => 'Vielfalt',
    ];
    $feldName = function (string $roh) use ($feldNamen) {
        [$schluessel, $wert] = array_pad(explode('=', $roh, 2), 2, null);
        $schluessel = preg_replace('/_(pct|pp)$/', '', trim($schluessel));
        $name = $feldNamen[$schluessel] ?? \Illuminate\Support\Str::ucfirst(str_replace('_', ' ', $schluessel));

        return $wert !== null && $wert !== '' ? $name . ': ' . $wert : $name;
    };
    $befund = ($leitplankenBefund['scope'] ?? null) === $scope ? $leitplankenBefund : null;
    $goTitel = $scope === 'gericht' ? 'Gericht-Bauplan vorschlagen' : $goLabel . ' erstellen';
@endphp
<div class="flex flex-col gap-4 max-w-7xl mx-auto">
    <x-foodalchemist::modal-section icon="heroicon-o-pencil-square" title="Was soll entstehen?">
        @if($titelEcho !== '')
            <x-slot:actions>
                <x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit($titelEcho, 32) }}</x-fa::badge>
            </x-slot:actions>
        @endif
        <div class="flex flex-col gap-4">
            {{-- Schnellstart-Vorlagen (geteiltes Partial, auch im Concept-Reiter): füllen Brief + Kreativ-Modus + Leitplanken. --}}
            @include('foodalchemist::livewire.planung.partials.schnellstart-chips', ['scope' => $scope])

            <x-fa::field label="Titel" hint="Leer lassen geht auch, dann schlägt die KI einen Titel aus dem Briefing vor.">
                <div class="flex flex-wrap items-center gap-2">
                    <x-fa::input wire:model="eingabe.{{ $scope }}.titel" class="flex-1 min-w-[14rem]" placeholder="{{ $scope === 'gericht' ? 'zum Beispiel Kalbsrücken mit Morcheln' : 'zum Beispiel Tomatensauce' }}" data-planung-titel />
                    {{-- Et.4 Teil 3: nüchterner, regelkonformer Titelvorschlag aus dem Briefing (nur wenn Titelfeld leer). Kein Erstellen. --}}
                    <x-fa::button variant="ai" size="sm" icon="heroicon-o-sparkles" wire:click="titelVorschlagen('{{ $scope }}')" :disabled="$laeuft"
                        wire:loading.attr="disabled" wire:target="titelVorschlagen" data-planung-titel-vorschlag>
                        <span wire:loading.remove wire:target="titelVorschlagen">Titel vorschlagen</span>
                        <span wire:loading wire:target="titelVorschlagen">Titel wird gesucht …</span>
                    </x-fa::button>
                </div>
            </x-fa::field>

            <div class="flex flex-col gap-1.5">
                <label for="planung-brief-{{ $scope }}" class="flex items-center gap-2 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">
                    Briefing
                    @if($reglerVonAgent[$scope]['brief'] ?? false)
                        <x-fa::badge tone="info" data-regler-von-agent="brief" title="Vom Sprachassistenten vorgeschlagen. Verschwindet, sobald du selbst etwas änderst.">vom Assistenten</x-fa::badge>
                    @endif
                </label>
                <x-fa::textarea id="planung-brief-{{ $scope }}" wire:model="eingabe.{{ $scope }}.brief" rows="3" placeholder="Anlass, Richtung, Vorgaben, was auf keinen Fall hinein soll …" />
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Geht wörtlich in die Erstellung.</p>

                {{-- Kurskorrektur „pro Reiter genau EINE Diktierfunktion" (2026-09-19): der Recorder
                     dieses Bausteins ist hier AUS. Das Agent-Panel darunter ist die einzige
                     Diktierfunktion in diesem Reiter (sein eigenes Mikro füllt dasselbe Feld, siehe
                     VoiceModal::updatedAudio() → Planung\Index::agentDiktatUebernehmen()). Der
                     „Leitplanken aus Briefing"-Knopf bleibt (liest nur das Feld, unabhängig vom
                     Recorder). Die fünf flachen Ausgabeform-Briefings (fbBrief/…) haben kein Panel
                     und behalten ihren Recorder unverändert. --}}
                @include('foodalchemist::livewire.planung.partials.diktat', [
                    'ziel' => 'eingabe.' . $scope . '.brief',
                    'mitLeitplanken' => $scope,
                    'mitRecorder' => false,
                ])
            </div>

            {{-- Paket K / Agent-am-Brief: ein Panel je Scope-Reiter (diese Partial wird pro Scope
                 separat inkludiert). `wire:key` trägt Session-ID + Scope, ein Wechsel remountet
                 komplett (frisches Gedächtnis, siehe VoiceModal::sitzungIds()). Kein Höhen-/
                 Breiten-Zwang: das Panel ist selbst x-show-gesteuert, startet eingeklappt. --}}
            @if($agentPanelSichtbar)
                @livewire('foodalchemist.voice-modal', [
                    'planungsSessionId' => $sessionId,
                    'planungScope' => $scope,
                    'formularRegler' => array_intersect_key($regler[$scope] ?? [], array_flip(\Platform\FoodAlchemist\Livewire\Planung\Index::AGENT_SCHREIBBARE_REGLER)),
                    'formularBrief' => (string) ($eingabe[$scope]['brief'] ?? ''),
                ], key('voice-panel-' . $scope . '-' . ($sessionId ?? 'keine')))
            @endif

            {{-- Befund sichtbar: gesetzt / verworfen / ignoriert / offen. Ein stiller Vorschlag
                 wäre die schlechtere Hälfte: der Mensch muss sehen, was die KI NICHT wusste. --}}
            @if($befund !== null)
                <div class="flex flex-col gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)] px-3 py-2.5 text-[length:var(--fa-text-md)]" data-planung-leitplanken-befund>
                    <p class="text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-2)]">Leitplanken aus dem Briefing</p>
                    @if(!empty($befund['gesetzt']))
                        <p class="text-[var(--fa-ink)]">
                            <x-fa::signal tone="ok">Gesetzt</x-fa::signal>
                            <span class="font-medium">{{ collect($befund['gesetzt'])->map($feldName)->implode(', ') }}</span>
                            <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">· Sicherheit {{ round(($befund['confidence'] ?? 0) * 100) }} %</span>
                        </p>
                    @endif
                    @if(!empty($befund['unklar']))
                        <x-fa::notice tone="warn" title="Offen, bitte selbst entscheiden">
                            <ul class="list-disc pl-4">
                                @foreach($befund['unklar'] as $u)<li>{{ $u }}</li>@endforeach
                            </ul>
                        </x-fa::notice>
                    @endif
                    @if(!empty($befund['verworfen']))
                        <p><x-fa::signal tone="crit">Nicht übernommen, kein gültiger Wert:</x-fa::signal> <span class="text-[var(--fa-ink-2)]">{{ collect($befund['verworfen'])->map($feldName)->implode(', ') }}</span></p>
                    @endif
                    @if(!empty($befund['ignoriert']))
                        <p class="text-[var(--fa-ink-3)]">Gilt nicht für diesen Reiter: {{ collect($befund['ignoriert'])->map($feldName)->implode(', ') }}</p>
                    @endif
                    @if(($befund['begruendung'] ?? null) !== null)
                        <p class="text-[var(--fa-ink-2)]">{{ $befund['begruendung'] }}</p>
                    @endif
                </div>
            @endif

            @if($scope === 'rezept')
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Basisrezepte haben keinen Kreativ-Modus. Vorhandene Basisrezepte und Grundprodukte werden zuerst geprüft, neu entsteht nur eine echte Lücke.</p>
            @else
                <div class="flex flex-col gap-1.5">
                    <x-fa::choice name="eingabe.{{ $scope }}.creative_mode" :options="$modeLabel" label="Kreativ-Modus" :id-prefix="'planung-' . $scope" />
                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-2xl">{{ ($modeHint ?? [])[$eingabe[$scope]['creative_mode'] ?? 'voll_kreativ'] ?? '' }}</p>
                </div>
            @endif
        </div>
    </x-foodalchemist::modal-section>

    @include('foodalchemist::livewire.planung.partials.leitplanken', ['scope' => $scope])

    @include('foodalchemist::livewire.planung.partials.schnellstart-speichern', ['scope' => $scope])

    {{-- Composer-Übernahme: sichtbar machen, dass das Erstellen auf die gewählten Leit-Aromen aufsetzt. --}}
    @if(($composerSeedPin['scope'] ?? null) === $scope && !empty($composerSeedPin['slugs']))
        <x-fa::notice tone="info" title="Leit-Aromen aus dem Composer" data-composer-seed-hint>
            Die Erstellung baut verbindlich auf diesen Zutaten auf: <span class="font-medium">{{ implode(', ', $composerSeedPin['slugs']) }}</span>
            <x-slot:actions>
                <x-fa::button variant="ghost" size="sm" icon="heroicon-m-x-mark" wire:click="$set('composerSeedPin', [])">Leit-Aromen entfernen</x-fa::button>
            </x-slot:actions>
        </x-fa::notice>
    @endif

    @if($wissenVorschau !== null)
        <x-foodalchemist::modal-section icon="heroicon-o-book-open" title="Wissen, das die KI nutzen würde" data-planung-wissen-vorschau>
            <p class="mb-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Vorschau, es wurde noch nichts erstellt.</p>
            <x-foodalchemist::kontext-inspektor :kontext="$wissenVorschau" />
        </x-foodalchemist::modal-section>
    @endif

    {{-- Erstell-Leiste: klebt unten im Scroll-Bereich (Befund „Knopf unter dem Fold"), aber als EINE
         niedrige Zeile. Titel-Echo, damit sichtbar bleibt, woran gearbeitet wird. --}}
    <div class="sticky bottom-0 z-10 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] px-4 py-3 shadow-lg shadow-black/20" data-planung-erstellen-leiste>
        <div class="flex-1 min-w-[16rem] flex flex-col gap-1">
            <p class="flex flex-wrap items-center gap-2 text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">
                @svg($goIcon, 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') {{ $goTitel }}
                @if($titelEcho !== '')<x-fa::badge tone="accent">{{ \Illuminate\Support\Str::limit($titelEcho, 32) }}</x-fa::badge>@endif
            </p>
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                @if($scope === 'gericht')
                    Zuerst entsteht nur ein Bauplan mit Komponenten. Erst wenn du ihn annimmst, wird daraus ein Rezept und die Erstellung läuft weiter.
                @else
                    Entsteht im Hintergrund als Entwurf. Den Stand siehst du im Reiter „Fortschritt".
                @endif
            </p>
            @include('foodalchemist::livewire.planung.partials.worker-praesenz')
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-fa::button variant="ghost" icon="heroicon-o-magnifying-glass" wire:click="wissenVorschau('{{ $scope }}')" :disabled="$laeuft"
                wire:loading.attr="disabled" wire:target="wissenVorschau" title="Zeigt, welches Wissen die KI für diese Eingabe heranziehen würde. Erstellt nichts." data-planung-wissen-vorab>
                <span wire:loading.remove wire:target="wissenVorschau">Wissen vorab prüfen</span>
                <span wire:loading wire:target="wissenVorschau">Wissen wird geladen …</span>
            </x-fa::button>
            <x-foodalchemist::ki-action action="goKaskade('{{ $scope }}')" variant="primary" :icon="$goIcon"
                :label="$scope === 'gericht' ? 'Bauplan vorschlagen' : $goLabel . ' erstellen'"
                busy="Wird gestartet …" flash="Gestartet" :disabled="$laeuft" before="tab='worker'" />
        </div>
    </div>
</div>
