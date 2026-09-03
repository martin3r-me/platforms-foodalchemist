{{-- Kontext-Inspektor (2026-08-07): zeigt transparent, AUF WELCHES WISSEN der Generator beim
     Erstellen zugegriffen hat — gruppiert je Kanal (Cross-Cutting/Domäne/Niveau/Pairing/…),
     plus gematchte Rezept-Templates + Zeichen-Budget. Read-only, fail-safe bei null/leer. --}}
@props(['kontext' => null])

@php
    $wissen = is_array($kontext) ? (array) ($kontext['wissen'] ?? []) : [];
    $templates = is_array($kontext) ? (array) ($kontext['templates'] ?? []) : [];
    $chars = is_array($kontext) ? (int) ($kontext['chars'] ?? 0) : 0;
    // W3-5: die ECHTEN Prompt-Größen (Messsonde). `$chars` oben ist NUR der Retrieval-Topf —
    // gemessen ~36.000 Zeichen, wo der Prompt ~77.500 hat. Wer allein diese Zahl liest,
    // unterschätzt den Prompt um mehr als die Hälfte. `null` = Sonde (noch) ohne Daten.
    $prompt = is_array($kontext) && is_array($kontext['prompt'] ?? null) ? $kontext['prompt'] : null;

    $labels = [
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
    $hatInhalt = $docCount > 0 || $templates !== [];

    // "slug@vN" / "graph:anker" → lesbarer Slug (Version + graph:-Präfix weg).
    $pretty = fn (string $e): string => (string) preg_replace('/^graph:/', '', explode('@', $e, 2)[0]);
@endphp

@if($hatInhalt)
    <details class="mt-3 rounded-lg border border-black/10 bg-gray-50/70" data-generator-kontext>
        <summary class="cursor-pointer select-none px-3 py-2 text-[11px] font-medium text-gray-700 flex items-center gap-1.5">
            🧠 Verwendetes Wissen
            <span class="text-gray-400 font-normal">· {{ $docCount }} Doc{{ $docCount === 1 ? '' : 's' }}@if($templates !== []), {{ count($templates) }} Template{{ count($templates) === 1 ? '' : 's' }}@endif@if($prompt) · Prompt {{ number_format($prompt['chars'], 0, ',', '.') }} Zeichen @elseif($chars > 0) · ~{{ number_format($chars, 0, ',', '.') }} Zeichen @endif</span>
        </summary>
        <div class="px-3 pb-3 pt-1 space-y-2">
            {{-- Die sechs Töpfe des Prompts. Vorher zeigte der Inspektor nur den Retrieval-Anteil
                 und ließ damit den größten Posten (das verbindliche Regelwerk) UND den Kontext
                 unsichtbar. `dropped` steht bewusst mit dabei: gebaut-und-weggeworfen ist eine
                 Größe, die man sehen muss, sonst sucht man den Deckel nicht. --}}
            @if($prompt)
                <div>
                    <p class="text-[10px] uppercase tracking-wide text-gray-400 mb-1">Prompt-Größen</p>
                    <div class="flex flex-wrap gap-1" data-prompt-groessen>
                        @foreach([
                            'Regelwerk (verbindlich)' => $prompt['bound'],
                            'Retrieval' => $prompt['retrieval'],
                            'Kontext-JSON' => $prompt['kontext'],
                            'Aufgabe' => $prompt['task'],
                            'Hüllen' => $prompt['huelle'],
                        ] as $label => $wert)
                            @if($wert > 0)
                                <span class="inline-block rounded bg-white border border-black/10 px-1.5 py-0.5 text-[10px] text-gray-700">
                                    {{ $label }} {{ number_format($wert, 0, ',', '.') }}
                                </span>
                            @endif
                        @endforeach
                        @if($prompt['dropped'] > 0)
                            <span class="inline-block rounded bg-amber-50 border border-amber-200 px-1.5 py-0.5 text-[10px] text-amber-800" title="Gebaut und wieder verworfen, weil ein Deckel gegriffen hat">
                                verworfen {{ number_format($prompt['dropped'], 0, ',', '.') }}
                            </span>
                        @endif
                        @if($prompt['tokens_in'] > 0)
                            <span class="inline-block rounded bg-gray-100 border border-black/10 px-1.5 py-0.5 text-[10px] text-gray-600">
                                @php($cacheAnteil = $prompt['tokens_cached'] > 0 ? round($prompt['tokens_cached'] / $prompt['tokens_in'] * 100) : null)
                                {{ number_format($prompt['tokens_in'], 0, ',', '.') }} Token{{ $cacheAnteil !== null ? ', ' . $cacheAnteil . ' % aus dem Cache' : '' }}
                            </span>
                        @endif
                    </div>
                </div>
            @endif
            @foreach($kanaele as $cat)
                @php($eintraege = (array) ($wissen[$cat] ?? []))
                @if($eintraege !== [])
                    <div>
                        <p class="text-[10px] uppercase tracking-wide text-gray-400 mb-1">{{ $labels[$cat] ?? ucfirst(str_replace('_', ' ', $cat)) }}</p>
                        <div class="flex flex-wrap gap-1">
                            @foreach($eintraege as $e)
                                <span class="inline-block rounded bg-white border border-black/10 px-1.5 py-0.5 text-[10px] text-gray-700">{{ $pretty((string) $e) }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach

            @if($templates !== [])
                <div>
                    <p class="text-[10px] uppercase tracking-wide text-gray-400 mb-1">Rezept-Templates (gematcht)</p>
                    <div class="flex flex-wrap gap-1">
                        @foreach($templates as $t)
                            <span class="inline-block rounded bg-white border border-black/10 px-1.5 py-0.5 text-[10px] text-gray-700">{{ $t['name'] ?? '—' }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <p class="text-[10px] text-gray-400 pt-1 leading-snug">Wissens-Grounding, das der Generator beim Erstellen gelesen hat. GP-Kandidaten, Bestands-Inventar und gebundene Regelwerk-Layer sind separate Kanäle (hier nicht gelistet).</p>
        </div>
    </details>
@endif
