@php
    $pdf = $istPdf ?? false;
    $opt = $optionen ?? [
        'profil' => 'voll',
        'rezepte' => true,
        'zutaten' => true,
        'anleitung' => true,
        'bilder' => $mitFotos ?? true,
        'darreichung' => true,
        'notizen' => true,
        'posten' => '',
    ];
    $profile = [
        'kurz' => 'Kurzblatt',
        'produktion' => 'Produktion',
    ];
    $filter = [
        'rezepte' => 'Rezepte',
        'zutaten' => 'Zutaten',
        'anleitung' => 'Anleitung',
        'regeneration' => 'Regeneration',
        'anrichten' => 'Anrichten',
        'bilder' => 'Bilder',
        'darreichung' => 'Darreichung',
        'notizen' => 'Notizen',
    ];
    $profilReset = array_merge(array_fill_keys(array_keys($filter), null), ['posten' => null]);
    $posten = collect($dok['zeilen'])
        ->filter(fn ($z) => ($z['station_id'] ?? null) !== null)
        ->mapWithKeys(fn ($z) => [(string) $z['station_id'] => $z['station'] ?? ('Posten #' . $z['station_id'])])
        ->sort();
    $hatOhnePosten = collect($dok['zeilen'])->contains(fn ($z) => ($z['station_id'] ?? null) === null);
    $zeilen = collect($dok['zeilen'])
        ->when(($opt['posten'] ?? '') === 'ohne', fn ($z) => $z->whereNull('station_id'))
        ->when(($opt['posten'] ?? '') !== '' && ($opt['posten'] ?? '') !== 'ohne', fn ($z) => $z->where('station_id', (int) $opt['posten']))
        ->values();
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Produktionsschein {{ $dok['production_date'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; background: {{ $pdf ? '#fff' : '#f3f4f6' }}; font-size: 12px; line-height: 1.5; margin: 0; padding: {{ $pdf ? '32px' : '52px 32px' }}; }
        .doc { max-width: 900px; margin: 0 auto; background: #fff; padding: {{ $pdf ? '0' : '44px 56px' }}; }
        .head { border-bottom: 2px solid #6d28d9; padding-bottom: 12px; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 4px; color: #111827; }
        .sub { color: #6b7280; }
        .muted { color: #9ca3af; }
        .rezept { margin: 16px 0; padding-bottom: 12px; border-bottom: 1px solid #ececec; }
        .rezept h2 { font-size: 14px; margin: 0 0 4px; color: #111827; }
        .rezept .meta { color: #6b7280; font-size: 11px; margin-bottom: 6px; }
        .rezept .darreichung { margin-top: 6px; font-size: 11px; color: #6b7280; }
        table.zutaten { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.zutaten th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 3px 6px; border-bottom: 1px solid #ececec; }
        table.zutaten td { padding: 2px 6px; border-bottom: 1px solid #f3f4f6; }
        .right { text-align: right; white-space: nowrap; }
        .einkauf-head { margin-top: 28px; border-top: 2px solid #6d28d9; padding-top: 12px; }
        .lieferant { margin: 14px 0; }
        .lieferant h2 { font-size: 13px; margin: 0 0 4px; color: #111827; display: flex; justify-content: space-between; align-items: baseline; }
        .lieferant h2 .right-sum { font-weight: bold; }
        table.einkauf-tbl { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.einkauf-tbl th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding: 3px 6px; border-bottom: 1px solid #ececec; }
        table.einkauf-tbl td { padding: 3px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .grand { margin-top: 14px; border-top: 2px solid #6d28d9; padding-top: 8px; font-size: 15px; font-weight: bold; text-align: right; }
        .foot { margin-top: 28px; color: #9ca3af; font-size: 10px; border-top: 1px solid #ececec; padding-top: 10px; }
        .actions { margin: {{ $pdf ? '0' : '-44px -56px 28px' }}; padding: 14px 16px; background: #111827; color: #fff; }
        .action-row + .action-row { margin-top: 8px; }
        .action-label { display: inline-block; min-width: 86px; font-size: 11px; font-weight: bold; }
        .btn { display: inline-block; padding: 5px 10px; color: #e5e7eb; text-decoration: none; border: 1px solid rgba(255,255,255,.28); border-radius: 999px; margin: 2px; font-size: 11px; }
        .btn.active, .btn.primary { background: #6d28d9; border-color: #6d28d9; color: #fff; }
        @media print { .actions { display: none; } body { background: #fff; padding: 0; } .doc { padding: 0; } }
@include('foodalchemist::dokumente.partials.schritt-karten-css')
    </style>
</head>
<body>
<div class="doc">
    @unless($pdf)
        <div class="actions">
            <div class="action-row">
                <span class="action-label">Profil:</span>
                @foreach($profile as $key => $label)
                    <a class="btn {{ ($opt['profil'] ?? '') === $key ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(array_merge($profilReset, ['profil' => $key, 'pdf' => null, 'csv' => null])) }}">{{ $label }}</a>
                @endforeach
                <a class="btn primary" href="{{ request()->fullUrlWithQuery(['pdf' => 1, 'csv' => null]) }}">PDF herunterladen</a>
                <a class="btn" href="javascript:window.print()">Drucken</a>
                <a class="btn" href="{{ request()->fullUrlWithQuery(['csv' => 1, 'pdf' => null]) }}">CSV</a>
            </div>
            <div class="action-row">
                <span class="action-label">Inhalte:</span>
                @foreach($filter as $key => $label)
                    <a class="btn {{ ($opt[$key] ?? false) ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery([$key => ($opt[$key] ?? false) ? 0 : 1, 'pdf' => null, 'csv' => null]) }}">{{ $label }}</a>
                @endforeach
            </div>
            @if($posten->isNotEmpty() || $hatOhnePosten)
                <div class="action-row">
                    <span class="action-label">Posten:</span>
                    <a class="btn {{ ($opt['posten'] ?? '') === '' ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['posten' => null, 'pdf' => null, 'csv' => null]) }}">Alle</a>
                    @foreach($posten as $id => $name)
                        <a class="btn {{ (string) ($opt['posten'] ?? '') === (string) $id ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['posten' => $id, 'rezepte' => 1, 'pdf' => null, 'csv' => null]) }}">{{ $name }}</a>
                    @endforeach
                    @if($hatOhnePosten)
                        <a class="btn {{ ($opt['posten'] ?? '') === 'ohne' ? 'active' : '' }}" href="{{ request()->fullUrlWithQuery(['posten' => 'ohne', 'rezepte' => 1, 'pdf' => null, 'csv' => null]) }}">Nicht zugeteilt</a>
                    @endif
                </div>
            @endif
        </div>
    @endunless

    <div class="head">
        <h1>Produktionsschein{{ !empty($dok['name']) ? ': ' . $dok['name'] : '' }}</h1>
        <div class="sub">
            {{ \Illuminate\Support\Carbon::parse($dok['production_date'])->format('d.m.Y') }} · {{ $dok['status_label'] }}
            @if($dok['reference']) · {{ $dok['reference'] }}@endif
        </div>
        @if(count($dok['ziele']) > 0)
            <div class="muted">{{ implode(' · ', $dok['ziele']) }}</div>
        @endif
        @if(($opt['notizen'] ?? false) && $dok['note'])<div class="muted">Notiz: {{ $dok['note'] }}</div>@endif
    </div>

    @if($opt['rezepte'] ?? false)
    @forelse($zeilen as $z)
        <div class="rezept">
            <h2>{{ $z['name'] }}@if($z['ist_basisrezept']) <span class="muted">(Basisrezept)</span>@endif</h2>
            <div class="meta">
                {{ rtrim(rtrim(number_format($z['ansaetze'], 2, ',', '.'), '0'), ',') }} Ansätze
                @if($z['portionen'] !== null) · {{ $z['portionen'] }} Portionen @endif
                @if($z['produzierte_menge_kg'] !== null) · {{ number_format($z['produzierte_menge_kg'], 2, ',', '.') }} kg @endif
                @if($z['arbeitszeit_min'] !== null) · {{ $z['arbeitszeit_min'] }} min Arbeitszeit @endif
                · Posten: {{ $z['station'] ?? 'Nicht zugeteilt' }}
                {{-- Spec 51: gedruckt wird EIN Wert. Gewaehlt wird im Editor, nicht auf dem Zettel. --}}
                @php($behaelter = ($opt['darreichung'] ?? false) ? \Platform\FoodAlchemist\Services\BehaelterBedarfService::kurz($z['darreichung']['behaelter_bedarf'] ?? null) : null)
                @if($behaelter !== null) · {{ $behaelter }} @endif
            </div>

            @if(($opt['zutaten'] ?? false) && $z['zutaten'])
                <table class="zutaten">
                    <thead><tr><th>Zutat</th><th class="right">Menge</th></tr></thead>
                    <tbody>
                        @foreach($z['zutaten'] as $zu)
                            <tr><td>{{ $zu['name'] }}@if($zu['note']) <span class="muted">({{ $zu['note'] }})</span>@endif</td><td class="right">{{ rtrim(rtrim(number_format($zu['menge'], 2, ',', '.'), '0'), ',') }} {{ $zu['einheit'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            {{-- §3.2 Regeneration: das eingefrorene Programm je Komponente. Vorher stand es nur
                 als Einzeiler in `darreichung` — aus Skalaren, die kein Schreibpfad füllt. --}}
            @if(($opt['regeneration'] ?? false) && count($z['regenerationen'] ?? []))
                <table>
                    <thead><tr><th>Komponente</th><th>Gerät</th><th class="right">°C</th><th class="right">min</th><th class="right">Kern °C</th><th>Hinweis</th></tr></thead>
                    <tbody>
                    @foreach($z['regenerationen'] as $reg)
                        <tr>
                            <td>{{ $reg['komponente'] ?? '—' }}</td>
                            <td>{{ $reg['geraet'] ?? '—' }}</td>
                            <td class="right">{{ $reg['temp_c'] ?? '—' }}</td>
                            <td class="right">{{ $reg['duration_min'] ?? '—' }}</td>
                            <td class="right">{{ $reg['core_temp_c'] ?? '—' }}</td>
                            <td>{{ $reg['note'] ?? '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

            @if($opt['anleitung'] ?? false)
                @include('foodalchemist::dokumente.partials.schritt-karten', [
                    'schritte' => $z['schritte'] ?? [],
                    'zubereitung' => $z['zubereitung'] ?? null,
                    'mitFotos' => $opt['bilder'] ?? false,
                    'istPdf' => $pdf,
                ])
            @endif

            {{-- §3.3 Anrichten: eingefrorene Schritte samt Fotos (Adressat ist der Pass). --}}
            @if(($opt['anrichten'] ?? false) && count($z['anrichte_schritte'] ?? []))
                @include('foodalchemist::dokumente.partials.schritt-karten', [
                    'schritte' => $z['anrichte_schritte'],
                    'zubereitung' => null,
                    'mitFotos' => $opt['bilder'] ?? false,
                    'istPdf' => $pdf,
                ])
            @endif

            @if(($opt['darreichung'] ?? false) && $z['darreichung'])
                <div class="darreichung">
                    @foreach($z['darreichung'] as $k => $v)<span>{{ $k }}: {{ $v }} · </span>@endforeach
                </div>
            @endif
        </div>
    @empty
        <p class="muted">Keine Rezepte für den gewählten Posten.</p>
    @endforelse
    @endif

    <div class="foot">Food Alchemist · Produktionsschein · {{ $dok['id'] }}</div>
</div>
</body>
</html>
