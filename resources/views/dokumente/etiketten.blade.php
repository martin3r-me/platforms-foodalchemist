@php
    /* Spec 70 · Etiketten. Feste Maße in mm, Tabellen statt inline-block (DomPDF), feste Farben
       (DomPDF kann keine CSS-Variablen). Bögen: randlos auf A4, Startplatz für angebrochene Bögen.
       Rolle/Dymo: ein Etikett je Seite. */
    $pdf = $istPdf ?? false;
    $vorschau = $istVorschau ?? false;
    $f = $format;
    $o = $optik;
    $d = $daten;
    $v = $vorlage;
    $basis = ['s' => 6.4, 'm' => 7.4, 'l' => 8.6][$v->schriftgroesse] ?? 7.4;
    $skala = $f['bogen'] ? ($f['h'] < 32 ? 0.86 : 1.0) : ($f['h'] < 30 ? 0.82 : 1.05);
    $pt = fn ($faktor) => round($basis * $skala * $faktor, 1) . 'pt';
    $pad = $f['h'] < 30 ? 1.6 : 2.4;   // mm Innenabstand
    $ink = '#1a1712';
    $muted = '#5f5850';
    $datum = fn ($c) => $c?->format('d.m.Y');
    $linie = '<span class="linie">&nbsp;</span>';
    $allergenText = function () use ($d, $v) {
        if ($d['allergene'] === [] && $d['spuren'] === []) {
            return $d['allergene_unbekannt'] ? null : 'keine';
        }
        $fmt = fn ($a) => match ($v->allergen_darstellung) {
            'kuerzel' => $a['code'],
            'klartext' => $a['label'],
            default => $a['label'] . ' (' . $a['code'] . ')',
        };

        return implode(', ', array_map($fmt, $d['allergene']));
    };
    $spurenText = fn () => $d['spuren'] === [] ? null : implode(', ', array_map(fn ($a) => $v->allergen_darstellung === 'kuerzel' ? $a['code'] : $a['label'], $d['spuren']));
    $istStellplatz = $d['quelle'] === 'stellplatz';
    $gesamt = $anzahl;
    $proSeite = $f['bogen'] ? $f['spalten'] * $f['zeilen'] : 1;
    $leer = $f['bogen'] ? $startplatz - 1 : 0;
    $zellen = $leer + $gesamt;
    $seiten = (int) ceil($zellen / $proSeite);
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Etiketten · {{ $d['bezeichnung'] }}</title>
<style>
    @page { size: {{ $f['bogen'] ? 'A4 portrait' : $f['b'] . 'mm ' . $f['h'] . 'mm' }}; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "DejaVu Sans", Arial, sans-serif; color: {{ $ink }}; background: {{ $pdf ? '#ffffff' : '#e9ebef' }}; }
    .actions { background: #0d1424; padding: 10px 14px; }
    .actions a { display: inline-block; color: #fff; text-decoration: none; border: 1px solid rgba(182,192,210,.28); border-radius: 999px; padding: 5px 10px; margin: 2px; font-size: 11px; font-family: Arial, sans-serif; }
    .seite { background: #ffffff; margin: {{ $pdf ? '0' : '16px auto' }}; width: {{ $f['bogen'] ? '210mm' : $f['b'] . 'mm' }}; {{ $pdf ? '' : 'box-shadow: 0 1px 4px rgba(0,0,0,.15);' }} }
    .umbruch { page-break-after: always; }
    table.raster { border-collapse: collapse; table-layout: fixed; width: {{ $f['bogen'] ? $f['spalten'] * $f['b'] : $f['b'] }}mm; }
    table.raster td { width: {{ $f['b'] }}mm; height: {{ $f['h'] - 0.4 }}mm; padding: 0; vertical-align: top; overflow: hidden; {{ $pdf ? '' : 'outline: 1px dashed #d8dce3;' }} }
    /* DomPDF ignoriert box-sizing: Padding zählt zur Höhe → Innenhöhe = Etikett − 2 × Padding − Reserve */
    .etikett { height: {{ round($f['h'] - 2 * $pad - ($f['bogen'] ? 0.6 : 1.4), 2) }}mm; padding: {{ $pad }}mm; overflow: hidden; position: relative; }
    .kopf { border-bottom: 0.6mm solid {{ $o['akzent'] }}; padding-bottom: 0.5mm; margin-bottom: 0.7mm; }
    .titel { font-family: {!! $o['schrift_kopf'] !!}; font-weight: bold; font-size: {{ $pt(1.55) }}; line-height: 1.1; color: {{ $o['akzent'] }}; }
    .zusatz { font-size: {{ $pt(0.95) }}; color: {{ $muted }}; }
    .logo { float: right; height: {{ $f['h'] < 30 ? 3.2 : 4.6 }}mm; margin-left: 1mm; }
    .z { font-size: {{ $pt(1) }}; line-height: 1.22; }
    .k { color: {{ $muted }}; }
    .bis { font-weight: bold; font-size: {{ $v->datum_gross ? $pt(1.35) : $pt(1) }}; {{ $v->datum_gross ? 'border: 0.3mm solid ' . $ink . '; padding: 0.2mm 0.8mm; display: block; margin: 0.4mm 0;' : '' }} }
    .klein { font-size: {{ $pt(0.82) }}; line-height: 1.18; }
    .warn { color: #a5122a; }
    .linie { display: inline-block; border-bottom: 0.25mm solid {{ $ink }}; width: 18mm; }
    .fuss { position: absolute; left: {{ $pad }}mm; right: {{ $pad }}mm; bottom: {{ $pad * 0.6 }}mm; font-size: {{ $pt(0.75) }}; color: {{ $muted }}; }
    @media print { .actions { display: none; } body { background: #fff; } .seite { margin: 0; box-shadow: none; } table.raster td { outline: none; } }
</style>
</head>
<body>
@unless($pdf || $vorschau)
    <div class="actions">
        <a href="javascript:window.print()">Drucken</a>
        <a href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
    </div>
@endunless
@for($s = 0; $s < $seiten; $s++)
    <div class="seite {{ $s < $seiten - 1 ? 'umbruch' : '' }}">
        @if($f['bogen'])
            <table class="raster">
                @for($r = 0; $r < $f['zeilen']; $r++)
                    <tr>
                        @for($c = 0; $c < $f['spalten']; $c++)
                            @php $index = $s * $proSeite + $r * $f['spalten'] + $c; $belegt = $index >= $leer && $index < $zellen; @endphp
                            <td>
                                @if($belegt)
                                    @include('foodalchemist::dokumente.partials.etikett-inhalt')
                                @endif
                            </td>
                        @endfor
                    </tr>
                @endfor
            </table>
        @else
            {{-- Rolle/Dymo: ein Etikett je Seite, ohne Tabelle (DomPDF schiebt zu hohe Tabellenzeilen auf Folgeseiten) --}}
            @include('foodalchemist::dokumente.partials.etikett-inhalt')
        @endif
    </div>
@endfor
</body>
</html>
