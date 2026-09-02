{{-- Kontext-Inspektor (2026-08-07): zeigt transparent, AUF WELCHES WISSEN der Generator beim
     Erstellen zugegriffen hat — gruppiert je Kanal (Cross-Cutting/Domäne/Niveau/Pairing/…),
     plus gematchte Rezept-Templates + Zeichen-Budget. Read-only, fail-safe bei null/leer. --}}
@props(['kontext' => null])

@php
    $wissen = is_array($kontext) ? (array) ($kontext['wissen'] ?? []) : [];
    $templates = is_array($kontext) ? (array) ($kontext['templates'] ?? []) : [];
    $chars = is_array($kontext) ? (int) ($kontext['chars'] ?? 0) : 0;

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
            <span class="text-gray-400 font-normal">· {{ $docCount }} Doc{{ $docCount === 1 ? '' : 's' }}@if($templates !== []), {{ count($templates) }} Template{{ count($templates) === 1 ? '' : 's' }}@endif@if($chars > 0) · ~{{ number_format($chars, 0, ',', '.') }} Zeichen @endif</span>
        </summary>
        <div class="px-3 pb-3 pt-1 space-y-2">
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
