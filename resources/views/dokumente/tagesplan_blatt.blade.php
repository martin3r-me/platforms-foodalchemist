{{-- Spec 30 E8 — Tagesplan-Blatt: Posten- oder Gerichtssicht als papierener Rückfall.
     fa-pass Druck-Muster (2026-10-05, wie dokumente/report): feste Werte statt CSS-Variablen
     (DomPDF kann keine), Kopf mit Wortmarke, Abhak-Kästchen je Position, Anleitung darunter. --}}
@php
    $pdf = $istPdf ?? false;
    $vonC = \Illuminate\Support\Carbon::parse($von);
    $bisC = \Illuminate\Support\Carbon::parse($bis);
    $brand = '#0a3dd6';
    $c = [
        'ink' => '#131a26', 'ink2' => '#4a5466', 'ink3' => '#657084',
        'line' => '#dde2ea', 'line2' => '#c5ccd8', 'soft' => '#f3f5f8', 'rail' => '#0d1424', 'railText' => '#b6c0d2',
        'warn' => '#8a5200', 'warnSoft' => '#fbefd9', 'warnLine' => '#f0d49a',
        'crit' => '#a8231c', 'critSoft' => '#fbe6e4',
    ];
    $logoPfad = dirname((new \ReflectionClass(\Platform\FoodAlchemist\FoodAlchemistServiceProvider::class))->getFileName(), 2) . '/resources/brand/fa-wordmark-900.png';
    $logo = is_file($logoPfad) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPfad)) : null;
    $blattName = $ansicht === 'gericht' ? 'Gericht-Blatt' : 'Posten-Blatt';
    $ansaetze = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    // Anleitungstext: Zeilenumbrüche bleiben (pre-line, Leerzeilen zusammengezogen), Markdown-Zwischenüberschriften (## …) werden fett
    // statt als Rohzeichen gedruckt. Erst escapen, dann nur das eigene <strong> einsetzen.
    $anleitung = fn ($text) => preg_replace('/^[ \t]*#{1,6}[ \t]+(.+?)[ \t]*$/m', '<strong>$1</strong>', e(preg_replace('/\n[ \t]*\n+/', "\n", trim(str_replace("\r", '', (string) $text)))));
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Tagesplan {{ $vonC->format('d.m.') }}–{{ $bisC->format('d.m.Y') }}</title>
    <style>
        @page { size: A4 portrait; margin: {{ $pdf ? '2.15cm 1.4cm 1.5cm 1.4cm' : '1.5cm 1.3cm' }}; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: {{ $c['ink'] }}; background: {{ $pdf ? '#fff' : $c['soft'] }}; margin: 0; padding: 0; font-size: 10px; line-height: 1.3; }
        .doc { max-width: {{ $pdf ? 'none' : '960px' }}; margin: 0 auto; background: #fff; padding: {{ $pdf ? '0' : '2.15cm 1.4cm 1.5cm 1.4cm' }}; }
        .band-top {
            {{ $pdf ? 'position: fixed; top: -2.15cm; left: -1.4cm; width: 18.2cm;' : '' }}
            height: 1.25cm; background: #fff; color: {{ $c['ink3'] }}; padding: 0 1.4cm; border-bottom: 2px solid {{ $brand }};{{ $pdf ? '' : ' max-width: 960px; margin: 0 auto;' }}
        }
        .band-top .bt-label { {{ $pdf ? 'display: block; padding-top: 0.44cm;' : 'display: block; line-height: 1.25cm;' }} font-size: 9.5px; letter-spacing: .02em; text-align: right; }
        .band-top img { height: {{ $pdf ? '0.78cm' : '0.8cm' }}; vertical-align: middle; }
        .band-top .bt-logo { {{ $pdf ? 'position: absolute; top: 0.22cm; left: 1.4cm;' : 'float: left; padding-top: 0.22cm;' }} }
        .band-bottom {
            {{ $pdf ? 'position: fixed; bottom: -1.5cm; left: -1.4cm; width: 18.2cm;' : '' }}
            height: 0.95cm; border-top: 1px solid {{ $c['line'] }}; color: {{ $c['ink3'] }};{{ $pdf ? '' : ' max-width: 960px; margin: 0 auto; background: #fff;' }} font-size: 8.5px; padding: 0 1.4cm;
        }
        .band-bottom .bb-foot { display: block; line-height: 0.95cm; }
        .actions { background: {{ $c['rail'] }}; color: #fff; padding: 12px 16px; margin: -2.15cm -1.4cm 20px; }
        .actions a { display: inline-block; color: #fff; text-decoration: none; border: 1px solid rgba(182,192,210,.28); border-radius: 999px; padding: 5px 10px; margin: 2px; font-size: 11px; }
        .actions a.active { background: {{ $brand }}; border-color: {{ $brand }}; }
        .actions strong { color: {{ $c['railText'] }}; font-weight: bold; margin-right: 4px; }

        header { margin-bottom: 6px; }
        .kicker { font-size: 9.5px; letter-spacing: .02em; color: {{ $c['ink3'] }}; }
        h1 { font-size: 20px; margin: 2px 0 2px; letter-spacing: -.02em; color: {{ $c['ink'] }}; }
        .rule { height: 3px; width: 3.6cm; background: {{ $brand }}; margin: 5px 0 6px; }
        h2 { font-size: 14px; margin: 12px 0 4px; border-top: 1px solid {{ $c['line'] }}; padding-top: 6px; color: {{ $c['ink'] }}; page-break-after: avoid; }
        h3 { font-size: 10.5px; margin: 9px 0 3px; color: {{ $brand }}; letter-spacing: .01em; page-break-after: avoid; }
        .muted { color: {{ $c['ink3'] }}; font-weight: normal; }
        .warn-text { color: {{ $c['warn'] }}; font-weight: bold; }
        p { margin: 0 0 5px; }

        table { width: 100%; border-collapse: collapse; margin: 3px 0 6px; table-layout: fixed; page-break-inside: auto; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; page-break-after: auto; }
        th, td { border: 1px solid {{ $c['line'] }}; padding: 2px 5px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { background: {{ $c['soft'] }}; color: {{ $c['ink2'] }}; font-size: 8.5px; font-weight: bold; letter-spacing: .02em; }
        td.num, th.num { text-align: right; }
        .c-box { width: 5%; }
        .c-ans { width: 9%; }
        .c-zeit { width: 9%; }
        .c-auftrag { width: 26%; }
        .c-posten { width: 20%; }
        td.box-zelle { text-align: center; vertical-align: middle; }
        .box { display: inline-block; width: 10px; height: 10px; border: 1px solid {{ $c['line2'] }}; vertical-align: middle; }
        .blocked { display: inline-block; margin-top: 2px; padding: 0 4px; color: {{ $c['crit'] }}; background: {{ $c['critSoft'] }}; font-size: 8.5px; font-weight: bold; vertical-align: middle; }
        /* Anleitung hängt an der Position darüber: keine Trennlinie dazwischen, kleiner, gedämpft. */
        tr.anleitung td { border-top: 0; color: {{ $c['ink2'] }}; font-size: 8.5px; line-height: 1.3; padding-top: 0; padding-bottom: 3px; }
        tr.anleitung td.leer { border-top: 0; }
        tr.pos td { border-bottom-color: {{ $c['soft'] }}; }
        .text { white-space: pre-line; }
        .text strong { color: {{ $c['ink'] }}; }
        .schluss { margin-top: 8px; color: {{ $c['ink3'] }}; font-size: 8.5px; }

        @media print {
            .actions { display: none; }
            body { background: #fff; }
            .doc { max-width: none; margin: 0; padding: 0; }
            .band-top { margin-bottom: 12px; }
            .band-bottom { margin-top: 14px; }
        }
    </style>
</head>
<body>
<div class="band-top">
    @if($logo)<span class="bt-logo"><img src="{{ $logo }}" alt="Food.Alchemist"></span>@endif
    <span class="bt-label">Tagesplan · {{ $blattName }}</span>
</div>
<main class="doc" data-tagesplan-blatt>
    @unless($pdf)
        <div class="actions">
            <strong>Tagesplan:</strong>
            <a href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
            <a class="active" href="javascript:window.print()">Drucken</a>
        </div>
    @endunless

    <header>
        <div class="kicker">Tagesplan · Stand {{ now()->format('d.m.Y H:i') }}</div>
        <h1>{{ $blattName }} {{ $vonC->equalTo($bisC) ? $vonC->format('d.m.Y') : $vonC->format('d.m.') . ' – ' . $bisC->format('d.m.Y') }}</h1>
        <div class="rule"></div>
        <div class="muted">{{ $ansicht === 'gericht' ? 'Nach Auftrag und Gericht gruppiert' : 'Nach Tag und Posten gruppiert' }} · Kästchen abhaken, wenn erledigt</div>
    </header>

    @forelse($zeilenNachTag as $tag => $zeilen)
        @php($tagC = \Illuminate\Support\Carbon::parse($tag))
        <section data-tagesplan-blatt-tag="{{ $tag }}">
            <h2>{{ $tagC->locale('de')->isoFormat('dddd, DD.MM.YYYY') }}</h2>

            @if($ansicht === 'gericht')
                @foreach($zeilen->groupBy('order_id') as $auftragZeilen)
                    <div data-tagesplan-blatt-auftrag="{{ $auftragZeilen->first()->order_id }}">
                        <h3>{{ $auftragZeilen->first()->auftrag }} <span class="muted">· für {{ \Illuminate\Support\Carbon::parse($auftragZeilen->first()->liefertag)->format('d.m.Y') }}</span></h3>
                        <table>
                            <thead><tr><th class="c-box"></th><th>Position</th><th class="c-posten">Posten</th><th class="c-ans num">Ansätze</th><th class="c-zeit num">Zeit</th></tr></thead>
                            <tbody>
                                @foreach($auftragZeilen->sortBy(['tiefe', 'position']) as $z)
                                    <tr class="pos">
                                        <td class="box-zelle"><span class="box"></span></td>
                                        <td style="padding-left: {{ 5 + min(3, (int) $z->tiefe) * 12 }}px">{{ $z->name }}@if($z->blocked_reason)<br><span class="blocked">Blockiert: {{ $z->blocked_reason }}</span>@endif</td>
                                        <td>{{ $z->station ?: 'Nicht zugeteilt' }}</td>
                                        <td class="num">{{ $ansaetze($z->ansaetze_effektiv) }}</td>
                                        <td class="num">{{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : '—' }}</td>
                                    </tr>
                                    <tr class="anleitung"><td class="leer"></td><td colspan="4" style="padding-left: {{ 5 + min(3, (int) $z->tiefe) * 12 }}px">
                                        @if(!empty($z->schritte))
                                            @foreach($z->schritte as $s)<strong>{{ $s['nr'] ?? $loop->iteration }}.</strong> {{ $s['text'] ?? '' }}@if(!$loop->last) · @endif @endforeach
                                        @elseif(!empty($z->zubereitung))
                                            <span class="text">{!! $anleitung($z->zubereitung) !!}</span>
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
                    <div>
                        <h3>
                            {{ $b['station'] }}
                            <span class="muted">· {{ $b['geplant_min'] }}@if($b['kapazitaet_min'] !== null) / {{ $b['kapazitaet_min'] }}@endif min</span>
                            @if($b['stufe'] === 'ueberlast')<span class="warn-text">· {{ $b['prozent'] }} % Überlast</span>@endif
                        </h3>
                        <table>
                            <thead><tr><th class="c-box"></th><th>Position</th><th class="c-ans num">Ansätze</th><th class="c-zeit num">Zeit</th><th class="c-auftrag">Auftrag</th></tr></thead>
                            <tbody>
                                @foreach($blockZeilen as $z)
                                    <tr class="pos">
                                        <td class="box-zelle"><span class="box"></span></td>
                                        <td>{{ $z->name }}@if($z->blocked_reason)<br><span class="blocked">Blockiert: {{ $z->blocked_reason }}</span>@endif</td>
                                        <td class="num">{{ $ansaetze($z->ansaetze_effektiv) }}</td>
                                        <td class="num">{{ $z->arbeitszeit_min !== null ? $z->arbeitszeit_min . ' min' : '—' }}</td>
                                        <td>{{ $z->auftrag }} <span class="muted">· für {{ \Illuminate\Support\Carbon::parse($z->liefertag)->format('d.m.') }}</span></td>
                                    </tr>
                                    <tr class="anleitung"><td class="leer"></td><td colspan="4">
                                        @if(!empty($z->schritte))
                                            @foreach($z->schritte as $s)<strong>{{ $s['nr'] ?? $loop->iteration }}.</strong> {{ $s['text'] ?? '' }}@if(!$loop->last) · @endif @endforeach
                                        @elseif(!empty($z->zubereitung))
                                            <span class="text">{!! $anleitung($z->zubereitung) !!}</span>
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
        </section>
    @empty
        <p class="muted" data-tagesplan-blatt-leer>In diesem Zeitraum steht nichts an.</p>
    @endforelse

    <p class="schluss">Tagesplan-{{ $ansicht === 'gericht' ? 'Gericht' : 'Posten' }}-Blatt · versionierter Rückfallausdruck · erstellt {{ now()->format('d.m.Y H:i') }}</p>
</main>
<div class="band-bottom">
    <span class="bb-foot">Erstellt mit Food.Alchemist</span>
</div>
</body>
</html>
