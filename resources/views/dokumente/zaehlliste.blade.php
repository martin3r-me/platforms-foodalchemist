@php
    $pdf = $istPdf ?? false;
    /* Spec 66 · Zählliste einer Inventur. Druck-Muster wie dokumente/bestelllauf (feste Farben,
       DomPDF kann keine CSS-Variablen). Leere Zählspalte zum Ausfüllen am Regal; ist schon
       gezählt, steht der Wert drin. */
    $brand = '#0a3dd6';
    $c = ['ink' => '#131a26', 'ink2' => '#4a5466', 'ink3' => '#657084', 'line' => '#dde2ea', 'soft' => '#f3f5f8', 'rail' => '#0d1424'];
    $logoPfad = dirname((new \ReflectionClass(\Platform\FoodAlchemist\FoodAlchemistServiceProvider::class))->getFileName(), 2) . '/resources/brand/fa-wordmark-900.png';
    $logo = is_file($logoPfad) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPfad)) : null;
    $zahl = fn ($v) => $v === null ? '' : (rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',') ?: '0');
    $gebucht = $inventur->istGebucht();
    $zeilen ??= $inventur->lines;
    $mitGebinde = $zeilen->contains(fn ($l) => $l->hatGebinde());
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Zählliste {{ $inventur->count_date->format('d.m.Y') }}</title>
    <style>
        @page { size: A4 portrait; margin: {{ $pdf ? '1.6cm 1.4cm 1.5cm 1.4cm' : '1.5cm 1.3cm' }}; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: {{ $c['ink'] }}; background: {{ $pdf ? '#fff' : $c['soft'] }}; margin: 0; font-size: 10px; line-height: 1.3; }
        .doc { max-width: {{ $pdf ? 'none' : '960px' }}; margin: 0 auto; background: #fff; padding: {{ $pdf ? '0' : '1.4cm' }}; }
        .actions { background: {{ $c['rail'] }}; padding: 12px 16px; margin: -1.4cm -1.4cm 20px; }
        .actions a { display: inline-block; color: #fff; text-decoration: none; border: 1px solid rgba(182,192,210,.28); border-radius: 999px; padding: 5px 10px; margin: 2px; font-size: 11px; }
        .kicker { font-size: 9.5px; color: {{ $c['ink3'] }}; }
        h1 { font-size: 20px; margin: 2px 0; }
        .rule { height: 3px; width: 3.6cm; background: {{ $brand }}; margin: 5px 0 8px; }
        .muted { color: {{ $c['ink3'] }}; }
        table { width: 100%; border-collapse: collapse; margin: 6px 0; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { border: 1px solid {{ $c['line'] }}; padding: 5px 6px; text-align: left; vertical-align: middle; }
        th { background: {{ $c['soft'] }}; font-size: 9px; color: {{ $c['ink2'] }}; }
        .r { text-align: right; }
        .feld { height: 18px; }
        .sig { margin-top: 18px; width: 100%; }
        .sig td { border: none; border-top: 1px solid {{ $c['ink3'] }}; padding-top: 3px; width: 33%; color: {{ $c['ink3'] }}; font-size: 9px; }
        @media print { .actions { display: none; } body { background: #fff; } .doc { padding: 0; } }
    </style>
</head>
<body>
<div class="doc">
    @unless($pdf)
        <div class="actions">
            <a href="javascript:window.print()">Drucken</a>
            <a href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
        </div>
    @endunless
    @if($logo)<img src="{{ $logo }}" alt="" style="height: 0.8cm; float: right;">@endif
    <div class="kicker">Inventur · {{ $gebucht ? 'gebucht' : 'Zählliste' }}</div>
    <h1>{{ $inventur->location?->name ?? 'Lager' }}@if(! empty($platzName)) · {{ $platzName }}@endif · Stichtag {{ $inventur->count_date->format('d.m.Y') }}</h1>
    <div class="rule"></div>
    <p class="muted">{{ $zeilen->count() }} Positionen · Gebinde zählen oder Menge in kg, l bzw. Stück · erstellt {{ $erstelltAm }}@if($inventur->note) · {{ $inventur->note }}@endif</p>

    <table>
        <thead>
            <tr>
                <th style="width: 5%">#</th>
                <th>Grundprodukt</th>
                <th style="width: 7%">Maß</th>
                <th class="r" style="width: 10%">Soll</th>
                @if($mitGebinde)
                    <th class="r" style="width: 11%">Karton</th>
                    <th class="r" style="width: 11%">Einzeln</th>
                    <th class="r" style="width: 11%">lose</th>
                @else
                    <th class="r" style="width: 18%">Gezählt</th>
                @endif
                <th style="width: 16%">Notiz</th>
            </tr>
        </thead>
        <tbody>
            @php $letzterPlatz = false; $spalten = $mitGebinde ? 8 : 6; @endphp
            @foreach($zeilen as $i => $l)
                @if($l->storage_bin_id !== $letzterPlatz)
                    @php $letzterPlatz = $l->storage_bin_id; @endphp
                    <tr><td colspan="{{ $spalten }}" style="background: {{ $c['soft'] }}; font-weight: bold;">{{ $l->bin?->name ?? 'Ohne Stellplatz' }}</td></tr>
                @endif
                <tr>
                    <td class="muted">{{ $i + 1 }}</td>
                    <td>{{ $l->gp?->name ?? $l->supplierItem?->designation ?? '—' }}
                        @if($l->hatGebinde())<br><span class="muted" style="font-size: 8.5px;">@if($l->pack_units)1 {{ $l->pack_label }} = {{ $zahl($l->pack_units) }} {{ $l->unit_label }} · @endif 1 {{ $l->unit_label }} = {{ $zahl($svc->anzeigeMenge((float) $l->unit_base, $l->base_unit)) }} {{ $svc->anzeigeEinheit($l->base_unit) }}</span>@endif
                    </td>
                    <td>{{ $svc->anzeigeEinheit($l->base_unit) }}</td>
                    <td class="r muted">{{ $zahl($svc->anzeigeMenge((float) $l->qty_expected, $l->base_unit)) }}</td>
                    @if($mitGebinde)
                        @if($l->hatGebinde())
                            <td class="r feld">@if($l->pack_units){{ $zahl($l->counted_packs) }}@else<span class="muted">—</span>@endif</td>
                            <td class="r feld">{{ $zahl($l->counted_units) }}</td>
                        @else
                            <td class="r"><span class="muted">—</span></td><td class="r"><span class="muted">—</span></td>
                        @endif
                        <td class="r feld">{{ $l->hatGebinde() && ($l->counted_packs !== null || $l->counted_units !== null) ? $zahl($l->counted_loose) : $zahl($svc->anzeigeMenge($l->qty_counted !== null ? (float) $l->qty_counted : null, $l->base_unit)) }}</td>
                    @else
                        <td class="r feld">{{ $zahl($svc->anzeigeMenge($l->qty_counted !== null ? (float) $l->qty_counted : null, $l->base_unit)) }}</td>
                    @endif
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="sig"><tr><td>Gezählt von</td><td>Geprüft von</td><td>Datum</td></tr></table>
</div>
</body>
</html>
