{{-- Buffetschilder: ein Zeltkärtchen je Gericht (+ je Unterrezept) des gewählten Tages.
     A4 hoch, 2 × 3 Kärtchen, Zuschnitt 105 × 99 mm, Falz in der Mitte → steht ca. 10 × 5 cm.
     Obere Hälfte gedreht, damit beide Seiten nach dem Falzen richtig herum stehen.
     Daten aus SpeiseplanService::buffetKarten — Allergene ausgeschrieben, nie geraten. --}}
@php
    $optik = ($optik ?? []) + ['logo' => null, 'footer' => null, 'akzent' => '#6d28d9', 'band' => '#6d28d9', 'text' => '#1a1712', 'muted' => '#5f5850', 'serif' => true, 'schrift_kopf' => 'Georgia, serif', 'schrift_text' => '"DejaVu Sans", Arial, sans-serif'];
    $diaetText = ['vegan' => 'vegan', 'vegetarisch' => 'vegetarisch', 'schwein' => 'mit Schwein', 'rind' => 'mit Rind', 'fisch' => 'mit Fisch'];
    $seiten = array_chunk($karten, 6);
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Buffetschilder — {{ $tagLabel }}</title>
    @include('foodalchemist::dokumente.partials.speiseplan-druck-schrift')
    <style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { font-family: {!! $optik['schrift_text'] !!}; color: {{ $optik['text'] }}; margin: 0; padding: {{ ($istPdf ?? false) ? '0' : '24px' }}; }
        .actions { margin: 0 auto 16px; width: 210mm; }
        .btn { display: inline-block; padding: 6px 12px; background: {{ $optik['akzent'] }}; color: #fff; text-decoration: none; border-radius: 6px; margin-right: 6px; font-size: 12px; }
        .btn.ghost { background: #fff; color: #374151; }
        .hinweis { font-size: 12px; color: #4b5563; margin-top: 8px; }
        @media screen { body { background: #e5e7eb; } .blatt { margin: 0 auto 24px; box-shadow: 0 2px 12px rgba(0,0,0,.15); } }
        @media print { .actions { display: none; } body { padding: 0; background: #fff; } .blatt { margin: 0; box-shadow: none; } }
        .blatt { position: relative; width: 210mm; height: 297mm; overflow: hidden; background: #fff; page-break-after: always; }
        .blatt.letzte { page-break-after: auto; }
        .karte { position: absolute; width: 105mm; height: 99mm; }
        .schnitt { position: absolute; border: 0 dotted #c4c4c4; }
        .karte .falz { position: absolute; left: 0; top: 49.5mm; width: 105mm; border-top: 0.25mm dashed #bdbdbd; }
        /* Die gedrehte Box trägt KEIN Padding: DomPDF dreht sonst um eine verschobene Mitte.
           Abstände sitzen im inneren .innen bzw. als absolute Offsets. */
        .seite { position: absolute; left: 0; width: 105mm; height: 49.5mm; }
        .seite .innen { padding: 4.2mm 5mm 0; }
        .seite.oben { top: 0; transform: rotate(180deg); }
        .seite.unten { top: 49.5mm; }
        .band { position: absolute; left: 0; top: 0; width: 105mm; height: 1.4mm; background: {{ $optik['band'] }}; }
        .kicker { font-size: 6pt; letter-spacing: .14em; text-transform: uppercase; color: {{ $optik['muted'] }}; height: 3.2mm; overflow: hidden; }
        .titel { font-family: {!! $optik['schrift_kopf'] !!}; font-weight: bold; line-height: 1.12; color: {{ $optik['text'] }}; margin-top: 1mm; }
        .wording { font-size: 7pt; color: {{ $optik['muted'] }}; line-height: 1.25; margin-top: 0.8mm; }
        .unterkante { position: absolute; left: 5mm; right: 5mm; bottom: 3.2mm; }
        .diaet { font-size: 7pt; font-weight: bold; color: {{ $optik['akzent'] }}; text-transform: uppercase; letter-spacing: .08em; }
        .allergene { font-size: 6.6pt; line-height: 1.3; color: {{ $optik['text'] }}; margin-top: 0.6mm; }
        .allergene .l { font-weight: bold; }
        .offen { font-size: 6pt; color: #9a3412; margin-top: 0.4mm; }
        .logo { position: absolute; right: 5mm; top: 3.6mm; }
        .logo img { max-height: 6.5mm; max-width: 24mm; }
    </style>
</head>
<body>
@unless($istPdf ?? false)
    <div class="actions">
        <a class="btn" href="?{{ http_build_query(array_merge(request()->query(), ['pdf' => 1])) }}">PDF herunterladen</a>
        <a class="btn ghost" href="javascript:window.print()">Drucken</a>
        @if($mitUnterrezepten)
            <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['unterrezepte' => 0, 'pdf' => null]) }}">→ ohne Unterrezepte</a>
        @else
            <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['unterrezepte' => 1, 'pdf' => null]) }}">→ mit Unterrezepten</a>
        @endif
        <div class="hinweis">{{ $tagLabel }} · {{ $mahlzeitLabel }} · {{ count($karten) }} Kärtchen auf {{ max(1, count($seiten)) }} Blatt · an der gepunkteten Linie schneiden, an der gestrichelten falzen. Ohne Ränder drucken (100 %).</div>
    </div>
@endunless

@forelse($seiten as $seite)
    <div class="blatt {{ $loop->last ? 'letzte' : '' }}">
        <div class="schnitt" style="left: 105mm; top: 0; height: 297mm; border-left-width: 0.25mm;"></div>
        <div class="schnitt" style="left: 0; top: 99mm; width: 210mm; border-top-width: 0.25mm;"></div>
        <div class="schnitt" style="left: 0; top: 198mm; width: 210mm; border-top-width: 0.25mm;"></div>
        @foreach($seite as $i => $k)
            @php
                $links = ($i % 2) * 105;
                $oben = intdiv($i, 2) * 99;
                $lang = mb_strlen($k['titel']);
                $gr = match (true) { $lang <= 18 => 15, $lang <= 30 => 12.5, $lang <= 45 => 10.5, default => 9 };
                $kicker = $k['zu'] !== null ? 'Komponente zu ' . $k['zu'] : ($k['linie'] ?? '');
            @endphp
            <div class="karte" style="left: {{ $links }}mm; top: {{ $oben }}mm;">
                @foreach(['oben', 'unten'] as $haelfte)
                    <div class="seite {{ $haelfte }}">
                        <div class="band"></div>
                        @if($optik['logo'])<div class="logo"><img src="{{ $optik['logo'] }}" alt=""></div>@endif
                        <div class="innen">
                            <div class="kicker" @if($optik['logo']) style="margin-right: 26mm" @endif>{{ $kicker }}</div>
                            <div class="titel" style="font-size: {{ $gr }}pt">{{ $k['titel'] }}</div>
                            @if($k['wording'] && $lang <= 30)<div class="wording">{{ $k['wording'] }}</div>@endif
                        </div>
                        <div class="unterkante">
                            @if($k['diaet'] !== [])<div class="diaet">{{ collect($k['diaet'])->map(fn ($d) => $diaetText[$d] ?? $d)->implode(' · ') }}</div>@endif
                            <div class="allergene">
                                @if($k['allergene'] !== [] || $k['zusatzstoffe'] !== [])
                                    <span class="l">Enthält:</span>
                                    {{ collect($k['allergene'])->map(fn ($a) => $a['label'] . ($a['spuren'] ? ' (Spuren)' : ''))->merge(collect($k['zusatzstoffe'])->pluck('label'))->implode(', ') }}
                                @elseif(! $k['unvollstaendig'])
                                    <span class="l">Ohne deklarationspflichtige Allergene</span>
                                @endif
                            </div>
                            @if($k['unvollstaendig'])<div class="offen">Allergene nicht vollständig bewertet — bitte beim Personal nachfragen.</div>@endif
                        </div>
                    </div>
                @endforeach
                <div class="falz"></div>
            </div>
        @endforeach
    </div>
@empty
    <div class="blatt letzte"><div style="padding: 30mm; text-align: center; color: #6b7280;">Für {{ $tagLabel }} ist nichts geplant.</div></div>
@endforelse
</body>
</html>
