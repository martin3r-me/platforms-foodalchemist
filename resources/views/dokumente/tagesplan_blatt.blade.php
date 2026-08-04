{{-- Spec 30 E8 — Tagesplan-Blatt: Posten- oder Gerichtssicht als papierener Rückfall. --}}
@php($vonC = \Illuminate\Support\Carbon::parse($von))
@php($bisC = \Illuminate\Support\Carbon::parse($bis))
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Tagesplan {{ $vonC->format('d.m.') }}–{{ $bisC->format('d.m.Y') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.5; margin: 0; padding: 32px; }
        .doc { max-width: 760px; margin: 0 auto; }
        .head { border-bottom: 2px solid #6d28d9; padding-bottom: 12px; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 4px; color: #111827; }
        .sub { color: #6b7280; }
        .tag-block { margin-bottom: 18px; page-break-inside: avoid; }
        .tag-block > h2 { font-size: 15px; color: #111827; margin: 14px 0 6px; border-bottom: 1px solid #ececec; padding-bottom: 3px; }
        .posten { margin: 0 0 10px; page-break-inside: avoid; }
        .posten h3 { font-size: 12px; color: #6d28d9; margin: 8px 0 2px; }
        .posten h3 .cap { color: #9ca3af; font-weight: normal; font-size: 11px; }
        .posten h3 .warn { color: #92400e; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 3px 6px; border-bottom: 1px solid #ececec; }
        td { padding: 3px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .right { text-align: right; white-space: nowrap; }
        .box { display: inline-block; width: 13px; height: 13px; border: 1px solid #9ca3af; border-radius: 2px; }
        .ctx { color: #6b7280; font-size: 11px; }
        .anweisung { margin: 3px 0 8px 22px; color: #374151; font-size: 10px; }
        .muted { color: #9ca3af; }
        .blocked { color: #92400e; }
        .foot { margin-top: 28px; color: #9ca3af; font-size: 10px; border-top: 1px solid #ececec; padding-top: 10px; }
        .actions { margin-bottom: 18px; }
        .btn { display: inline-block; padding: 6px 12px; background: #6d28d9; color: #fff; text-decoration: none; border-radius: 6px; margin-right: 6px; }
        .btn.ghost { background: #eee; color: #374151; }
        @media print { .actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="doc" data-tagesplan-blatt>
    <div class="actions">
        <a class="btn ghost" href="javascript:window.print()">Drucken</a>
    </div>

    <div class="head">
        <h1>Tagesplan · {{ $ansicht === 'gericht' ? 'Gericht-Blatt' : 'Posten-Blatt' }}</h1>
        <div class="sub">{{ $vonC->format('d.m.') }} – {{ $bisC->format('d.m.Y') }} · Stand {{ now()->format('d.m.Y H:i') }} · {{ $ansicht === 'gericht' ? 'nach Auftrag und Gericht gruppiert' : 'nach Tag und Posten gruppiert' }}</div>
    </div>

    @forelse($zeilenNachTag as $tag => $zeilen)
        @php($tagC = \Illuminate\Support\Carbon::parse($tag))
        <div class="tag-block" data-tagesplan-blatt-tag="{{ $tag }}">
            <h2>{{ $tagC->locale('de')->isoFormat('dddd, DD.MM.YYYY') }}</h2>

            @if($ansicht === 'gericht')
                @foreach($zeilen->groupBy('order_id') as $auftragZeilen)
                    <div class="posten" data-tagesplan-blatt-auftrag="{{ $auftragZeilen->first()->order_id }}">
                        <h3>{{ $auftragZeilen->first()->auftrag }} <span class="cap">· für {{ \Illuminate\Support\Carbon::parse($auftragZeilen->first()->liefertag)->format('d.m.Y') }}</span></h3>
                        <table>
                            <thead><tr><th style="width:22px">✓</th><th>Position</th><th>Posten</th><th class="right">Ansätze</th><th class="right">Zeit</th></tr></thead>
                            <tbody>
                                @foreach($auftragZeilen->sortBy(['tiefe', 'position']) as $z)
                                    <tr>
                                        <td><span class="box"></span></td>
                                        <td style="padding-left: {{ 6 + min(3, (int) $z->tiefe) * 14 }}px">{{ $z->name }} @if($z->blocked_reason)<span class="blocked">· Blocker: {{ $z->blocked_reason }}</span>@endif</td>
                                        <td class="ctx">{{ $z->station ?: 'Nicht zugeteilt' }}</td>
                                        <td class="right">{{ rtrim(rtrim(number_format((float) $z->ansaetze_effektiv, 2, ',', '.'), '0'), ',') }}</td>
                                        <td class="right">{{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : '—' }}</td>
                                    </tr>
                                    <tr><td></td><td colspan="4" class="anweisung">
                                        @if(!empty($z->schritte))
                                            @foreach($z->schritte as $s)<strong>{{ $s['nr'] ?? $loop->iteration }}.</strong> {{ $s['text'] ?? '' }}@if(!$loop->last) · @endif @endforeach
                                        @elseif(!empty($z->zubereitung))
                                            {{ $z->zubereitung }}
                                        @else
                                            <span class="muted">Keine Anleitung hinterlegt.</span>
                                        @endif
                                    </td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            @else
                @php($nachPosten = $zeilen->groupBy(fn ($z) => $z->station_id === null ? '_none' : (int) $z->station_id))
                @foreach($auslastung[$tag] ?? [] as $b)
                    @php($schluessel = $b['station_id'] === null ? '_none' : (int) $b['station_id'])
                    @php($blockZeilen = $nachPosten[$schluessel] ?? collect())
                    @continue($blockZeilen->isEmpty())
                    <div class="posten">
                        <h3>
                            {{ $b['station'] }}
                            <span class="cap">
                                · {{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null) / {{ $b['kapazitaet_min'] }}@endif min
                                @if($b['stufe'] === 'ueberlast')<span class="warn">· {{ $b['prozent'] }} % Überlast</span>@endif
                            </span>
                        </h3>
                    <table>
                        <thead>
                            <tr>
                                <th style="width:22px">✓</th>
                                <th>Position</th>
                                <th class="right">Ansätze</th>
                                <th class="right">Zeit</th>
                                <th>Auftrag</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($blockZeilen as $z)
                                <tr>
                                    <td><span class="box"></span></td>
                                    <td>{{ $z->name }} @if($z->blocked_reason)<span class="blocked">· Blocker: {{ $z->blocked_reason }}</span>@endif</td>
                                    <td class="right">{{ rtrim(rtrim(number_format((float) $z->ansaetze_effektiv, 2, ',', '.'), '0'), ',') }}</td>
                                    <td class="right">{{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : '—' }}</td>
                                    <td class="ctx">{{ $z->auftrag }} <span class="muted">· für {{ \Illuminate\Support\Carbon::parse($z->liefertag)->format('d.m.') }}</span></td>
                                </tr>
                                <tr><td></td><td colspan="4" class="anweisung">
                                    @if(!empty($z->schritte))
                                        @foreach($z->schritte as $s)<strong>{{ $s['nr'] ?? $loop->iteration }}.</strong> {{ $s['text'] ?? '' }}@if(!$loop->last) · @endif @endforeach
                                    @elseif(!empty($z->zubereitung))
                                        {{ $z->zubereitung }}
                                    @else
                                        <span class="muted">Keine Anleitung hinterlegt.</span>
                                    @endif
                                </td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endforeach
            @endif
        </div>
    @empty
        <p class="muted" data-tagesplan-blatt-leer>In diesem Zeitraum steht nichts an.</p>
    @endforelse

    <div class="foot">Food Alchemist · Tagesplan-{{ $ansicht === 'gericht' ? 'Gericht' : 'Posten' }}-Blatt · versionierter Rückfallausdruck · erstellt {{ now()->format('d.m.Y H:i') }}</div>
</div>
</body>
</html>
