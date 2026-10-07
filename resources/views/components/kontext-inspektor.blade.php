{{-- Kontext-Inspektor (2026-08-07): zeigt transparent, AUF WELCHES WISSEN der Generator beim
     Erstellen zugegriffen hat — gruppiert je Kanal (Cross-Cutting/Domäne/Niveau/Pairing/…),
     plus gematchte Rezept-Templates + Zeichen-Budget. Read-only, fail-safe bei null/leer.
     fa-pass 2026-10-05: nur Tokens (läuft hell und im Werkbank-Modus), Heroicon statt Emoji,
     Schrift ab 12 px, Küchensprache statt Technikbegriffen (Anfrage statt Prompt, Recherche statt
     Retrieval, Kontextdaten statt Kontext-JSON). Werte und data-Marker unverändert. --}}
@props(['kontext' => null])

@php
    $wissen = is_array($kontext) ? (array) ($kontext['wissen'] ?? []) : [];
    // Spec 53 Paket B Aufgabe 6 — was gebaut, aber NICHT gesendet wurde, getrennt nach Kanal
    // ('retrieval' aus dem Budget-Schnitt der Fuzzy-Discovery, 'kanon' aus gedroppten
    // wenn_platz-Dossiers). Optional: ein Aufrufer ohne dieses Feld zeigt einfach keine Chips
    // (fail-safe wie der Rest der Komponente).
    $verworfenRoh = is_array($kontext) ? (array) ($kontext['wissen_verworfen'] ?? []) : [];
    $verworfenRetrieval = array_values((array) ($verworfenRoh['retrieval'] ?? []));
    $verworfenKanon = array_values((array) ($verworfenRoh['kanon'] ?? []));
    $templates = is_array($kontext) ? (array) ($kontext['templates'] ?? []) : [];
    $chars = is_array($kontext) ? (int) ($kontext['chars'] ?? 0) : 0;
    // W3-5: die ECHTEN Prompt-Größen (Messsonde). `$chars` oben ist NUR der Retrieval-Topf —
    // gemessen ~36.000 Zeichen, wo der Prompt ~77.500 hat. Wer allein diese Zahl liest,
    // unterschätzt den Prompt um mehr als die Hälfte. `null` = Sonde (noch) ohne Daten.
    $prompt = is_array($kontext) && is_array($kontext['prompt'] ?? null) ? $kontext['prompt'] : null;

    $labels = [
        // Spec 50 Welle 2: Kanon-Dossiers (pflicht/wenn_platz je Prompt-Key) — ersetzt am
        // Generator die gebundenen Regelwerke; steht bewusst zuerst, es ist der verbindliche Teil.
        'kanon' => 'Kanon (verbindlich)',
        'cross_cutting' => 'Cross-Cutting',
        'domain' => 'Domänen',
        'niveau' => 'Niveau',
        'kueche' => 'Küche',
        'kreativ_input' => 'Kreativ-Input',
        'pairing' => 'Pairing-Anker',
        'pairing_grounding' => 'Pairing-Doku',
        'trend' => 'Trends',
        'concept' => 'Konzept',
        'gebunden' => 'Gebundene Regelwerke',
        'achse' => 'Anlass & Segment',
    ];
    $order = array_keys($labels);
    $bekannt = array_values(array_filter($order, fn ($k) => ! empty($wissen[$k])));
    $unbekannt = array_values(array_filter(array_keys($wissen), fn ($k) => ! in_array($k, $order, true) && ! empty($wissen[$k])));
    $kanaele = array_merge($bekannt, $unbekannt);

    $docCount = array_sum(array_map(fn ($v) => is_array($v) ? count($v) : 0, $wissen));
    $hatInhalt = $docCount > 0 || $templates !== [] || $verworfenRetrieval !== [] || $verworfenKanon !== [];

    // Quellen behalten ihre Version; nur das technische graph:-Präfix entfällt.
    $pretty = fn (string $e): string => (string) preg_replace('/^graph:/', '', $e);

    $gruppenTitel = 'mb-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]';
    $chip = 'inline-block rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-1.5 py-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink)] break-all';
    $chipWarn = 'inline-block rounded-[var(--fa-radius-control)] bg-[var(--fa-warn-soft)] px-1.5 py-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-warn)] break-all';
    $chipLeise = 'inline-block rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)] px-1.5 py-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]';
@endphp

@if($hatInhalt)
    <details class="group mt-3 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-ground)]" data-generator-kontext>
        <summary class="cursor-pointer select-none px-3 py-2 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">
            @svg('heroicon-o-academic-cap', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
            Verwendetes Wissen
            <span class="font-normal text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">· {{ $docCount }} {{ $docCount === 1 ? 'Dokument' : 'Dokumente' }}@if($templates !== []), {{ count($templates) }} {{ count($templates) === 1 ? 'Rezept-Vorlage' : 'Rezept-Vorlagen' }}@endif @if($prompt) · Anfrage {{ number_format($prompt['chars'], 0, ',', '.') }} Zeichen @elseif($chars > 0) · ~{{ number_format($chars, 0, ',', '.') }} Zeichen @endif</span>
            @svg('heroicon-m-chevron-down', 'w-4 h-4 ml-auto text-[var(--fa-ink-3)] transition-transform group-open:rotate-180')
        </summary>
        <div class="px-3 pb-3 pt-1 flex flex-col gap-2.5">
            {{-- Die sechs Töpfe der Anfrage. Vorher zeigte der Inspektor nur den Retrieval-Anteil
                 und ließ damit den größten Posten (das verbindliche Regelwerk) UND den Kontext
                 unsichtbar. `dropped` steht bewusst mit dabei: gebaut-und-weggeworfen ist eine
                 Größe, die man sehen muss, sonst sucht man den Deckel nicht. --}}
            @if($prompt)
                <div>
                    <p class="{{ $gruppenTitel }}">Umfang der Anfrage in Zeichen</p>
                    <div class="flex flex-wrap gap-1" data-prompt-groessen>
                        @foreach([
                            'Kanon (verbindlich)' => $prompt['kanon'] ?? 0,
                            'Regelwerk gebunden (Ersatzweg)' => $prompt['bound'],
                            'Recherche' => $prompt['retrieval'],
                            'Kontextdaten' => $prompt['kontext'],
                            'Aufgabe' => $prompt['task'],
                            'Rahmentext' => $prompt['huelle'],
                        ] as $label => $wert)
                            @if($wert > 0)
                                <span class="{{ $chip }} tabular-nums">{{ $label }} {{ number_format($wert, 0, ',', '.') }}</span>
                            @endif
                        @endforeach
                        @if($prompt['dropped'] > 0)
                            <span class="{{ $chipWarn }} tabular-nums" title="Gebaut und wieder verworfen, weil ein Deckel gegriffen hat">verworfen {{ number_format($prompt['dropped'], 0, ',', '.') }}</span>
                        @endif
                        @if($prompt['tokens_in'] > 0)
                            @php
                                $cacheAnteil = $prompt['tokens_cached'] > 0 ? round($prompt['tokens_cached'] / $prompt['tokens_in'] * 100) : null;
                            @endphp
                            <span class="{{ $chipLeise }} tabular-nums" title="Abgerechnete Texteinheiten der KI; der wiederverwendete Anteil kostet nur einen Bruchteil">{{ number_format($prompt['tokens_in'], 0, ',', '.') }} Abrechnungseinheiten{{ $cacheAnteil !== null ? ', ' . $cacheAnteil . ' % wiederverwendet' : '' }}</span>
                        @endif
                    </div>
                </div>
            @endif
            @foreach($kanaele as $cat)
                @php
                    $eintraege = (array) ($wissen[$cat] ?? []);
                @endphp
                @if($eintraege !== [])
                    <div>
                        <p class="{{ $gruppenTitel }}">{{ $labels[$cat] ?? ucfirst(str_replace('_', ' ', $cat)) }}</p>
                        <div class="flex flex-wrap gap-1">
                            @foreach($eintraege as $e)
                                <span class="{{ $chip }}">{{ $pretty((string) $e) }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach

            {{-- Aufgabe 6: verworfen getrennt ausweisen — eigene Chip-Gruppe je Kanal (Recherche
                 = Fuzzy-Discovery, Kanon = gedroppte wenn_platz-Dossiers). --}}
            @if($verworfenRetrieval !== [] || $verworfenKanon !== [])
                <div>
                    <p class="{{ $gruppenTitel }}">Verworfen (nicht gesendet)</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($verworfenRetrieval as $e)
                            <span class="{{ $chipWarn }}" title="Gefunden, aber aus Platzgründen nicht mitgeschickt">Recherche: {{ $pretty((string) $e) }}</span>
                        @endforeach
                        @foreach($verworfenKanon as $e)
                            <span class="{{ $chipWarn }}" title="Nur „wenn Platz ist“ vorgesehen und aus Platzgründen weggelassen">Kanon: {{ $pretty((string) $e) }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            @if($templates !== [])
                <div>
                    <p class="{{ $gruppenTitel }}">Passende Rezept-Vorlagen</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($templates as $t)
                            <span class="{{ $chip }}">{{ $t['name'] ?? '–' }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <p class="pt-1 text-[length:var(--fa-text-sm)] leading-snug text-[var(--fa-ink-3)]">Für diesen Aufruf festgehaltene Wissensquellen mit Versionsnummer. Grundprodukt-Vorschläge und der Bestand sind hier nicht enthalten.</p>
        </div>
    </details>
@endif
