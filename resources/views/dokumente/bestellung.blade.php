@php
    $pdf = $istPdf ?? false;
    /* fa-pass Druck-Muster (2026-10-05, wie dokumente/report): feste Werte statt CSS-Variablen
       (DomPDF kann keine), abgeleitet aus den --fa-*-Tokens. Geht ggf. an den Lieferanten raus:
       Lieferantendaten, Artikelnummern und Summe vollständig und sachlich. */
    $brand = '#0a3dd6';
    $c = [
        'ink' => '#131a26', 'ink2' => '#4a5466', 'ink3' => '#657084',
        'line' => '#dde2ea', 'line2' => '#c5ccd8', 'soft' => '#f3f5f8', 'rail' => '#0d1424', 'railText' => '#b6c0d2',
        'warn' => '#8a5200', 'warnSoft' => '#fbefd9', 'warnLine' => '#f0d49a',
        'ok' => '#1c6e46', 'okSoft' => '#e2f2e9',
    ];
    $logoPfad = dirname((new \ReflectionClass(\Platform\FoodAlchemist\FoodAlchemistServiceProvider::class))->getFileName(), 2) . '/resources/brand/fa-wordmark-900.png';
    $logo = is_file($logoPfad) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPfad)) : null;
    $geld = fn ($v) => number_format((float) $v, 2, ',', '.') . ' €';
    $zahl = fn ($v, $dec = 2) => rtrim(rtrim(number_format((float) $v, $dec, ',', '.'), '0'), ',');
    // ISO-Datum (Y-m-d, optional mit Uhrzeit) deutsch setzen; alles andere unverändert durchreichen.
    $datum = function ($v) {
        if (! is_string($v) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}:\d{2}))?/', $v, $m)) {
            return $v;
        }

        return $m[3] . '.' . $m[2] . '.' . $m[1] . (isset($m[4]) ? ' ' . $m[4] : '');
    };
    $lief = $dok['lieferant'] ?? [];
    $mitWe = ($dok['receipt']['booked'] ?? 0) > 0;
    $mitRe = ($dok['invoice']['checked'] ?? 0) > 0;
    $mitRekl = ($dok['claims']['lines'] ?? 0) > 0;
    $spalten = 6 + ($mitWe ? 1 : 0) + ($mitRe ? 1 : 0) + ($mitRekl ? 1 : 0);
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Bestellung {{ $lief['name'] ?? '' }}</title>
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
        .muted { color: {{ $c['ink3'] }}; font-weight: normal; }
        p { margin: 0 0 5px; }

        .grid { width: 100%; margin: 3px 0 6px; font-size: 0; }
        .grid > div { display: inline-block; width: {{ $pdf ? '22.4%' : '24.6%' }}; border: 1px solid {{ $c['line'] }}; padding: 2px 6px; vertical-align: top; font-size: 10px; margin-right: -1px; margin-bottom: -1px; overflow-wrap: anywhere; }
        .grid > div.wide { width: {{ $pdf ? '47.3%' : '49.8%' }}; }
        .grid > div.full { width: {{ $pdf ? '97.4%' : '100%' }}; }
        .grid span { display: block; color: {{ $c['ink3'] }}; font-size: 8.5px; letter-spacing: .02em; margin-bottom: 0; }

        table { width: 100%; border-collapse: collapse; margin: 3px 0 6px; table-layout: fixed; page-break-inside: auto; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; page-break-after: auto; }
        th, td { border: 1px solid {{ $c['line'] }}; padding: 2px 5px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        th { background: {{ $c['soft'] }}; color: {{ $c['ink2'] }}; font-size: 8.5px; font-weight: bold; letter-spacing: .02em; }
        td.num, th.num { text-align: right; }
        .c-art { width: 13%; }
        .c-geb { width: 11%; }
        .c-anz { width: 8%; }
        .c-preis { width: 12%; }
        .c-summe { width: 12%; }
        .c-zusatz { width: 11%; }
        .c-rekl { width: 15%; }
        /* Mit Wareneingang/Rechnung/Reklamation wird es eng: Zahlenspalten schmaler, Artikel behält Luft. */
        table.eng .c-art { width: 10%; }
        table.eng .c-geb { width: 8%; }
        table.eng .c-anz { width: 7%; }
        table.eng .c-preis, table.eng .c-summe, table.eng .c-zusatz { width: 9%; }
        table.eng .c-rekl { width: 12%; }
        .klein { display: block; color: {{ $c['ink3'] }}; font-size: 8.5px; }
        .klein.warn { color: {{ $c['warn'] }}; }
        .klein.ok { color: {{ $c['ok'] }}; }
        .sum-line td { border-top: 2px solid {{ $c['ink3'] }}; font-weight: bold; background: {{ $c['soft'] }}; font-size: 11px; }

        .hinweise { margin: 4px 0 6px; }
        .hinweis { display: block; padding: 3px 7px; margin: 0 0 3px; border: 1px solid {{ $c['line'] }}; color: {{ $c['ink2'] }}; }
        .hinweis.warn { color: {{ $c['warn'] }}; background: {{ $c['warnSoft'] }}; border-color: {{ $c['warnLine'] }}; }
        .hinweis.ok { color: {{ $c['ok'] }}; background: {{ $c['okSoft'] }}; border-color: {{ $c['okSoft'] }}; }
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
    <span class="bt-label">Bestellung</span>
</div>
<main class="doc">
    @unless($pdf)
        <div class="actions">
            <strong>Bestellung:</strong>
            <a class="active" href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
            <a href="{{ request()->fullUrlWithQuery(['csv' => 1]) }}">CSV</a>
            <a href="javascript:window.print()">Drucken</a>
        </div>
    @endunless

    <header>
        <div class="kicker">Bestellung · Bestell-Nr. ord-{{ $dok['id'] }}@if($dok['created_at']) · angelegt {{ $dok['created_at'] }}@endif</div>
        <h1>Bestellung an {{ $lief['name'] ?? '—' }}</h1>
        <div class="rule"></div>
        <div class="muted">Status: {{ $dok['status_label'] }}@if($dok['reference']) · Referenz: {{ $dok['reference'] }}@endif @if($dok['sent_at']) · versendet {{ $dok['sent_at'] }}@endif</div>
    </header>

    <div class="grid">
        <div class="wide">
            <span>Lieferant</span>
            <strong>{{ $lief['name'] ?? '—' }}</strong>
            @if($lief['address'] ?? null)<br>{{ $lief['address'] }}@endif
            @if(($lief['postal_code'] ?? null) || ($lief['city'] ?? null))<br>{{ trim(($lief['postal_code'] ?? '') . ' ' . ($lief['city'] ?? '')) }}@endif
            @if($lief['email_order'] ?? null)<br>{{ $lief['email_order'] }}@endif
        </div>
        @if($dok['desired_delivery_date'])<div><span>Wunsch-Liefertermin</span><strong>{{ $datum($dok['desired_delivery_date']) }}</strong></div>@endif
        @if($dok['supplier_order_number'])<div><span>AB-/Bestellnummer Lieferant</span><strong>{{ $dok['supplier_order_number'] }}</strong></div>@endif
        @if($dok['confirmed_delivery_date'])<div><span>Bestätigter Liefertag</span><strong>{{ $datum($dok['confirmed_delivery_date']) }}</strong></div>@endif
        @if($dok['invoice_number'])
            <div><span>Rechnung</span><strong>{{ $dok['invoice_number'] }}</strong>@if($dok['invoice_date']) vom {{ $datum($dok['invoice_date']) }}@endif @if($dok['invoice_due_date'])<br>fällig {{ $datum($dok['invoice_due_date']) }}@endif</div>
        @endif
        @if($dok['payment']['status'] ?? null)
            <div><span>Zahlung</span><strong>{{ $dok['payment']['label'] }}</strong>@if($dok['invoice_paid_at']) am {{ $datum($dok['invoice_paid_at']) }}@endif</div>
        @endif
        @if($dok['approval']['status'] ?? null)
            <div><span>Freigabe</span><strong>{{ $dok['approval']['label'] }}</strong>@if($dok['approved_at']) am {{ $datum($dok['approved_at']) }}@elseif($dok['approval_requested_at']) seit {{ $datum($dok['approval_requested_at']) }}@endif</div>
        @endif
        @if($dok['approval_note'])<div class="wide"><span>Freigabenotiz</span>{{ $dok['approval_note'] }}</div>@endif
    </div>

    <table class="{{ $spalten > 6 ? 'eng' : '' }}">
        <thead><tr>
            <th class="c-art">Art.-Nr.</th>
            <th>Artikel</th>
            <th class="c-geb num">Gebinde</th>
            <th class="c-anz num">Anzahl</th>
            <th class="c-preis num">{{ $spalten > 6 ? 'Preis je Gebinde' : 'Preis/Gebinde' }}</th>
            <th class="c-summe num">Summe</th>
            @if($mitWe)<th class="c-zusatz num">Geliefert</th>@endif
            @if($mitRe)<th class="c-zusatz num">Rechnung Diff.</th>@endif
            @if($mitRekl)<th class="c-rekl">Reklamation</th>@endif
        </tr></thead>
        <tbody>
            @forelse($dok['zeilen'] as $z)
                <tr>
                    <td>{{ $z['article_number'] ?: '—' }}</td>
                    <td>
                        {{ $z['designation'] ?: '—' }}
                        @unless($z['bestellbar'])<span class="klein warn">Preis/Gebinde fehlt — bitte prüfen</span>@endunless
                        @if($z['quota'])
                            <span class="klein {{ $z['quota']['exceeded'] || ! $z['quota']['is_valid_date'] ? 'warn' : 'ok' }}">
                                Kontingent: {{ $zahl($z['quota']['remaining_before_packs']) }} frei,
                                nach Bestellung {{ $zahl($z['quota']['remaining_after_packs']) }}
                                @if(($z['quota']['consumed_packs'] ?? 0) > 0) · verbraucht {{ $zahl($z['quota']['consumed_packs']) }} @endif
                                @if(!$z['quota']['is_valid_date']) · außerhalb Gültigkeit @endif
                            </span>
                        @endif
                    </td>
                    <td class="num">{{ $z['packaging_unit'] ?: '—' }}@if($z['pack_qty'])<span class="klein">{{ $zahl($z['pack_qty'], 3) }} {{ $z['unit_code'] }}</span>@endif</td>
                    <td class="num"><strong>{{ $zahl($z['qty_packs']) }}</strong></td>
                    <td class="num">{{ $z['pack_price'] !== null ? $geld($z['pack_price']) : '—' }}</td>
                    <td class="num">{{ $geld($z['line_total']) }}</td>
                    @if($mitWe)
                        <td class="num">
                            {{ $z['received_qty_packs'] !== null ? $zahl($z['received_qty_packs']) : '—' }}
                            @if($z['receipt_diff_packs'] !== null && abs((float) $z['receipt_diff_packs']) >= 0.01)<span class="klein warn">Diff. {{ (float) $z['receipt_diff_packs'] > 0 ? '+' : '' }}{{ $zahl($z['receipt_diff_packs']) }}</span>@endif
                        </td>
                    @endif
                    @if($mitRe)
                        <td class="num">
                            {{ $z['invoice_diff_net'] !== null ? $geld($z['invoice_diff_net']) : '—' }}
                            @if($z['invoice_line_total'] !== null)<span class="klein">Rechnung {{ $geld($z['invoice_line_total']) }}</span>@endif
                        </td>
                    @endif
                    @if($mitRekl)
                        <td>
                            {{ $z['claim_status_label'] ?? '—' }}
                            @if($z['claim_qty_packs'] !== null)<span class="klein">{{ $zahl($z['claim_qty_packs']) }} Gebinde</span>@endif
                            @if($z['credit_expected_net'] !== null)<span class="klein">{{ $geld($z['credit_expected_net']) }} Gutschrift</span>@endif
                            @if($z['claim_note'])<span class="klein">{{ $z['claim_note'] }}</span>@endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $spalten }}" class="muted">Keine Positionen.</td></tr>
            @endforelse
            <tr class="sum-line">
                <td colspan="4">Bestellwert netto</td>
                <td colspan="2" class="num">{{ $geld($dok['total_net']) }}</td>
                @if($spalten > 6)<td colspan="{{ $spalten - 6 }}"></td>@endif
            </tr>
        </tbody>
    </table>

    <div class="hinweise">
        @if($mitWe)
            <span class="hinweis {{ ($dok['receipt']['differences'] ?? 0) > 0 ? 'warn' : 'ok' }}">Wareneingang: {{ $dok['receipt']['booked'] }}/{{ $dok['receipt']['lines'] }} Zeilen{{ ($dok['receipt']['differences'] ?? 0) > 0 ? ' · ' . $dok['receipt']['differences'] . ' Differenz(en)' : '' }}</span>
        @endif
        @if($mitRe)
            <span class="hinweis {{ ($dok['invoice']['differences'] ?? 0) > 0 ? 'warn' : 'ok' }}">Rechnung: {{ $geld($dok['invoice']['invoice_net']) }}{{ abs((float) ($dok['invoice']['diff_net'] ?? 0)) >= 0.01 ? ' · Diff. ' . $geld($dok['invoice']['diff_net']) : '' }}</span>
        @endif
        @if($mitRekl)
            <span class="hinweis {{ (($dok['claims']['open'] ?? 0) + ($dok['claims']['credit_expected'] ?? 0)) > 0 ? 'warn' : 'ok' }}">Reklamation: {{ $dok['claims']['lines'] }} Zeile(n){{ ($dok['claims']['credit_expected_net'] ?? 0) > 0 ? ' · ' . $geld($dok['claims']['credit_expected_net']) . ' erwartet' : '' }}</span>
        @endif

        @php($moq = $dok['moq'])
        @if($moq['unter_mindestbestellwert'])
            <span class="hinweis warn">Unter Mindestbestellwert ({{ $geld($moq['min_order_value']) }}) — es fehlen {{ $geld($moq['fehlt_bis_min']) }}.</span>
        @elseif($moq['min_order_value'] !== null)
            <span class="hinweis ok">Mindestbestellwert erreicht.</span>
        @endif
        @if($moq['frei_haus'])
            <span class="hinweis ok">Frei Haus.</span>
        @elseif($moq['free_shipping_threshold'] !== null)
            <span class="hinweis">Noch {{ $geld($moq['fehlt_bis_frei_haus']) }} bis frei Haus.</span>
        @endif
    </div>

    <p class="schluss">Bestellung ord-{{ $dok['id'] }}@if($dok['sent_at']) · versendet {{ $dok['sent_at'] }}@endif · alle Preise netto</p>
</main>
<div class="band-bottom">
    <span class="bb-foot">Erstellt mit Food.Alchemist</span>
</div>
</body>
</html>
