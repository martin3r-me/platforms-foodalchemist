{{--
    Spec 03 L7b: die Ergebnis-Zeile des One-Shot-Passes — eine Fläche für BEIDE
    Generator-Modals. Zeigt, was die Anreicherung wirklich getan hat, statt „fertig".

    Drei Fälle, alle drei sichtbar:
      · übernommen  — Felder, die der Pass gefüllt hat
      · übersprungen — Schritte, deren Ziel-Feld schon belegt war (nichts überschrieben;
                       das ist die GL-07-Grenze, keine Panne)
      · offen/fehler — der Pass ist an einem Schritt gescheitert. Das Rezept steht
                       trotzdem vollständig da; der Rest liegt als Lücke in der Prüfliste.

    L7b-2: dazu das Kohärenz-Glied (nur Gericht, ab zwei Komponenten). Es steht NEBEN dem
    Aroma-Wert aus der Statistik, nie verrechnet mit ihm (GL-10 §1: zwei Achsen, zwei Anzeigen).
    Fehlt das Urteil, wird das ehrlich gesagt statt weggelassen.

    L8b: zuletzt die Wirtschaftlichkeit (L8a). Ohne Portionsgröße oder Aufschlagsklasse gibt es
    keinen VK — das steht am Erzeugnis statt als stiller Null-Preis im Editor. Der Wareneinsatz
    trägt dieselbe Ampel wie das Signal-Cockpit (eine Schwelle, eine Leiter), und „vorläufig"
    sagt, dass unbepreiste Zutaten im EK stecken (#511-F2).

    fa-pass (2026-10-05): Tokens + Bausteine, Statuswerte lesbar; Texte, die Tests lesen, unverändert.
--}}
@props(['anreicherung'])
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $statusText = fn ($s) => [
        'erstellt' => 'erstellt', 'aktualisiert' => 'aktualisiert', 'bewertet' => 'bewertet',
        'unveraendert' => 'unverändert', 'uebersprungen' => 'übersprungen', 'fehler' => 'Fehler', 'offen' => 'offen',
    ][$s] ?? str_replace('_', ' ', (string) $s);
    $tonFuer = fn ($s, array $gut) => in_array($s, $gut, true) ? 'ok' : ($s === 'fehler' ? 'warn' : 'neutral');
    $gliedText = [
        'fertigung' => 'Fertigung', 'eigenschaften' => 'Eigenschaften', 'equipment' => 'Equipment', 'posten' => 'Posten',
        'aromaprofil' => 'Aromenprofil', 'eignung' => 'Eignung',
        'steps' => 'Schritte', 'sensorik' => 'Sensorik',
    ];
@endphp

@if($anreicherung !== null)
    <div class="mt-3 pt-3 flex flex-col gap-2 border-t border-[var(--fa-line)]" data-oneshot-ergebnis>
        <p class="flex items-center gap-1.5 text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">@svg('heroicon-o-sparkles', 'w-4 h-4 text-[var(--fa-accent)]') Anreicherung</p>
        <div class="flex flex-wrap gap-1.5">
            <x-fa::badge :tone="($anreicherung['uebernommen'] ?? 0) > 0 ? 'ok' : 'neutral'">{{ $anreicherung['uebernommen'] ?? 0 }} Felder gefüllt</x-fa::badge>
            @if(count($anreicherung['uebersprungen'] ?? []) > 0)
                <x-fa::badge title="Diese Felder waren schon belegt und wurden nicht überschrieben">{{ count($anreicherung['uebersprungen']) }} schon belegt</x-fa::badge>
            @endif
            @if(($anreicherung['offen'] ?? 0) > 0)
                <x-fa::badge tone="warn">{{ $anreicherung['offen'] }} offen in der Prüfliste</x-fa::badge>
            @endif
        </div>
        @if(($anreicherung['fehler'] ?? null) !== null)
            <x-fa::signal tone="warn" data-oneshot-fehler>
                <span>Anreicherung unvollständig: {{ $anreicherung['fehler'] }}. Das Rezept selbst ist fertig und im Bestand verankert, die übrigen Felder bleiben offen.</span>
            </x-fa::signal>
        @elseif(($anreicherung['uebernommen'] ?? 0) === 0 && count($anreicherung['schritte'] ?? []) === 0 && ($anreicherung['coverage'] ?? null) === null)
            <p class="{{ $leise }}">Alle Felder waren schon belegt, es war keine weitere KI-Abfrage nötig.</p>
        @endif

        @if(($anreicherung['coverage'] ?? null) !== null)
            @php
                $cov = $anreicherung['coverage'];
                $steps = $cov['steps'] ?? null;
                $sens = $cov['sensorik'] ?? null;
            @endphp
            <div class="flex flex-wrap gap-1.5" data-oneshot-coverage>
                @foreach(['fertigung', 'eigenschaften', 'equipment', 'posten', 'aromaprofil', 'eignung'] as $key)
                    @if(($cov[$key] ?? null) !== null)
                        @php
                            $status = $cov[$key]['status'] ?? 'offen';
                        @endphp
                        <x-fa::badge :tone="$tonFuer($status, ['erstellt', 'aktualisiert', 'bewertet'])">{{ $gliedText[$key] }}: {{ $statusText($status) }}</x-fa::badge>
                    @endif
                @endforeach
                @if($steps !== null)
                    @php
                        $stepStatus = $steps['status'] ?? 'offen';
                    @endphp
                    <x-fa::badge :tone="$tonFuer($stepStatus, ['erstellt', 'aktualisiert'])" data-oneshot-steps>
                        Schritte: {{ in_array($stepStatus, ['erstellt', 'aktualisiert'], true) ? (($steps['n_steps'] ?? 0) . ' ' . $statusText($stepStatus)) : $statusText($stepStatus) }}
                    </x-fa::badge>
                @endif
                @if($sens !== null)
                    @php
                        $sensStatus = $sens['status'] ?? 'offen';
                    @endphp
                    <x-fa::badge :tone="$tonFuer($sensStatus, ['bewertet', 'unveraendert'])" data-oneshot-sensorik>Sensorik: {{ $statusText($sensStatus) }}</x-fa::badge>
                @endif
            </div>
            @php
                $coverageFehler = collect($cov)->filter(fn ($glied) => is_array($glied) && ($glied['fehler'] ?? null) !== null);
            @endphp
            @if($coverageFehler->isNotEmpty())
                <div class="flex flex-col gap-0.5" data-oneshot-coverage-fehler>
                    @foreach($coverageFehler as $key => $glied)
                        <x-fa::signal tone="warn"><span>{{ $gliedText[$key] ?? ucfirst((string) $key) }} offen: {{ $glied['fehler'] }}</span></x-fa::signal>
                    @endforeach
                </div>
            @endif
        @endif

        @if(($anreicherung['kohaerenz_urteil'] ?? null) !== null)
            @php
                $koh = $anreicherung['kohaerenz_urteil'];
            @endphp
            <div class="flex flex-col gap-1" data-oneshot-kohaerenz>
                @if($koh['score'] !== null)
                    <div class="flex flex-wrap items-center gap-1.5">
                        <x-fa::badge :tone="$koh['score'] >= 70 ? 'ok' : ($koh['score'] >= 50 ? 'warn' : 'crit')" icon="heroicon-m-puzzle-piece" title="Wie gut die Komponenten zusammenpassen">Kohärenz {{ $koh['score'] }} / 100</x-fa::badge>
                        @if($koh['label'] !== null)<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $koh['label'] }}</span>@endif
                    </div>
                    @if($koh['schwachstelle'] !== null)
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">Schwachstelle: {{ $koh['schwachstelle'] }}</p>
                    @endif
                @else
                    <x-fa::signal tone="warn" data-oneshot-kohaerenz-fehler>
                        <span>Kohärenz-Urteil offen: {{ $koh['fehler'] }}. Im Gericht-Detail über «Kohärenz prüfen» nachholen.</span>
                    </x-fa::signal>
                @endif
            </div>
        @endif

        @if(($anreicherung['wirtschaftlichkeit'] ?? null) !== null)
            @php
                $w = $anreicherung['wirtschaftlichkeit'];
                $lueckenText = ['portion' => 'Portionsgröße', 'aufschlagsklasse' => 'Aufschlagsklasse', 'darreichung' => 'Standard-Darreichung'];
                // EINE Ampel-Zuordnung für beide Wareneinsatz-Werte (Ist und „bei Ziel-VK") — die Leiter selbst liegt im Service.
                $ampelTon = ['gruen' => 'ok', 'gelb' => 'warn', 'rot' => 'crit'];
            @endphp
            <div class="flex flex-col gap-1" data-oneshot-wirtschaftlichkeit>
                @if(($w['fehler'] ?? null) !== null)
                    <x-fa::signal tone="warn" data-oneshot-wirtschaft-fehler>
                        <span>Kalkulation offen: {{ $w['fehler'] }}. Das Gericht steht, der Preis wird im Gericht-Editor nachgezogen.</span>
                    </x-fa::signal>
                @else
                    <div class="flex flex-wrap items-center gap-1.5">
                        @if(($w['sales_net'] ?? null) !== null)
                            <x-fa::badge tone="ok" icon="heroicon-m-currency-euro" data-oneshot-vk>VK {{ number_format((float) $w['sales_net'], 2, ',', '.') }} €</x-fa::badge>
                        @endif
                        @if(($w['wareneinsatz_pct'] ?? null) !== null)
                            {{-- Dieselbe Leiter wie das Signal (L8a Entscheidung 4): über Ziel = gelb, über 1,5 × Ziel = rot --}}
                            <x-fa::badge :tone="$ampelTon[$w['ampel'] ?? ''] ?? 'neutral'" title="Wareneinsatz gegen Ziel" data-oneshot-we>W {{ number_format((float) $w['wareneinsatz_pct'], 1, ',', '.') }} % / Ziel {{ number_format((float) ($w['ziel_pct'] ?? 0), 0, ',', '.') }} %</x-fa::badge>
                        @endif
                        @if(($w['portion_g'] ?? null) !== null)
                            <x-fa::badge>{{ number_format((float) $w['portion_g'], 0, ',', '.') }} g / Portion</x-fa::badge>
                        @endif
                        @if($w['vorlaeufig'] ?? false)
                            <x-fa::badge tone="warn" data-oneshot-vorlaeufig>vorläufig</x-fa::badge>
                        @endif
                    </div>
                    @if($w['vorlaeufig'] ?? false)
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]">Unbepreiste Zutaten im EK. Der VK ist vorläufig, bis alle Zutaten einen Preis haben.</p>
                    @endif
                    @if(count($w['luecken'] ?? []) > 0)
                        <x-fa::signal tone="warn" data-oneshot-wirtschaft-luecken>
                            <span>Kein Auto-VK: {{ implode(' + ', array_map(fn ($l) => $lueckenText[$l] ?? $l, $w['luecken'])) }} fehlt. Im Gericht-Editor setzen, dann rechnet sich der Preis selbst.</span>
                        </x-fa::signal>
                    @endif
                    @if($w['signal'] ?? false)
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]" data-oneshot-wirtschaft-signal>
                            Wareneinsatz über Ziel, als Signal im Cockpit vermerkt.
                        </p>
                    @endif

                    {{-- L8b-2: die Vorgabe aus der Eingabe, ehrlich gegen das Ergebnis gehalten. Der Preis wurde
                         NICHT auf das Ziel gedrückt — gezeigt wird der Abstand und was der Zielpreis für den
                         Wareneinsatz bedeuten würde. --}}
                    @if(($w['ziel_vk'] ?? null) !== null)
                        <div class="mt-1 pt-2 flex flex-col gap-1 border-t border-[var(--fa-line)]" data-oneshot-ziel-vk>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-fa::badge tone="info" icon="heroicon-m-flag">Ziel-VK {{ number_format((float) $w['ziel_vk'], 2, ',', '.') }} €</x-fa::badge>
                                @if(($w['ziel_wareneinsatz_pct'] ?? null) !== null)
                                    <x-fa::badge :tone="$ampelTon[$w['ziel_ampel'] ?? ''] ?? 'neutral'" data-oneshot-ziel-we>bei Ziel-VK: W {{ number_format((float) $w['ziel_wareneinsatz_pct'], 1, ',', '.') }} % / Ziel {{ number_format((float) ($w['ziel_pct'] ?? 0), 0, ',', '.') }} %</x-fa::badge>
                                @endif
                            </div>
                            @if(($w['ziel_delta_eur'] ?? null) !== null)
                                @php
                                    $delta = (float) $w['ziel_delta_eur'];
                                @endphp
                                <p class="text-[length:var(--fa-text-sm)] {{ $delta > 0 ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-ink-2)]' }}" data-oneshot-ziel-delta>
                                    @if($delta > 0)
                                        Kalkuliert liegt der VK {{ number_format($delta, 2, ',', '.') }} € über dem Ziel. Zum Zielpreis verkauft steigt der Wareneinsatz entsprechend. Der Preis bleibt gerechnet, die Entscheidung liegt bei dir.
                                    @elseif($delta < 0)
                                        Kalkuliert liegt der VK {{ number_format(abs($delta), 2, ',', '.') }} € unter dem Ziel. Der Zielpreis ist mit dieser Aufschlagsklasse tragfähig.
                                    @else
                                        Kalkulierter VK und Ziel treffen sich.
                                    @endif
                                </p>
                            @else
                                <p class="{{ $leise }}" data-oneshot-ziel-offen>
                                    Noch kein kalkulierter VK zum Vergleichen. Erst die Lücke oben schließen.
                                </p>
                            @endif
                        </div>
                    @endif
                @endif
            </div>
        @endif
    </div>
@endif
