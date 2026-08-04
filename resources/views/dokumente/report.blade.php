@php
    $pdf = $istPdf ?? false;
    $opt = $optionen ?? [];
    $profile = [
        'kurz' => 'Kurzblatt',
        'produktion' => 'Produktion',
        'kalkulation' => 'Kalkulation',
        'voll' => 'Volle Kaskade',
    ];
    $money = fn ($v, $dec = 2) => $v !== null && $v !== '' ? number_format((float) $v, $dec, ',', '.') . ' €' : '—';
@endphp
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $titel ?? 'Report' }} – {{ $name ?? '' }}</title>
    <style>
        @page { margin: {{ $pdf ? '1.6cm 1.25cm' : '0' }}; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; background: {{ $pdf ? '#fff' : '#f3f4f6' }}; margin: 0; font-size: 11px; line-height: 1.45; }
        .doc { max-width: {{ $pdf ? 'none' : '960px' }}; margin: 0 auto; background: #fff; padding: {{ $pdf ? '0' : '32px' }}; }
        .actions { background: #111827; color: #fff; padding: 12px 16px; margin: {{ $pdf ? '0' : '-32px -32px 24px' }}; }
        .actions a { display: inline-block; color: #fff; text-decoration: none; border: 1px solid rgba(255,255,255,.25); border-radius: 999px; padding: 5px 10px; margin: 2px; font-size: 11px; }
        .actions a.active { background: #7c3aed; border-color: #7c3aed; }
        .actions .secondary a { color: #e5e7eb; }
        h1 { font-size: 24px; margin: 0 0 4px; letter-spacing: -.02em; }
        h2 { font-size: 18px; margin: 24px 0 8px; border-top: 2px solid #111827; padding-top: 10px; }
        h3 { font-size: 15px; margin: 18px 0 8px; border-top: 1px solid #d1d5db; padding-top: 8px; }
        h4 { font-size: 12px; margin: 14px 0 6px; color: #374151; text-transform: uppercase; letter-spacing: .05em; }
        h5 { font-size: 11px; margin: 12px 0 6px; color: #4b5563; }
        .muted { color: #6b7280; font-weight: normal; }
        .warn { color: #b45309; background: #fffbeb; border: 1px solid #fde68a; padding: 6px 8px; border-radius: 6px; }
        .intro { margin: 12px 0 18px; color: #374151; white-space: pre-line; }
        .grid { display: table; width: 100%; border-collapse: collapse; margin: 8px 0 10px; }
        .grid > div { display: table-cell; width: 25%; border: 1px solid #e5e7eb; padding: 6px 8px; vertical-align: top; }
        .grid > div.wide { width: 50%; }
        .grid span { display: block; color: #6b7280; font-size: 9px; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0 12px; }
        th, td { border: 1px solid #e5e7eb; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background: #f9fafb; color: #374151; font-size: 9px; text-transform: uppercase; letter-spacing: .05em; }
        .copy { white-space: pre-line; color: #374151; }
        .step { margin: 4px 0; }
        .recipe-node.depth-1 { margin-left: {{ $pdf ? '0' : '12px' }}; }
        .recipe-node.depth-2, .recipe-node.depth-3, .recipe-node.depth-4 { margin-left: {{ $pdf ? '0' : '20px' }}; }
        .slot { border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px 10px; margin: 8px 0; page-break-inside: avoid; }
        .badge { display: inline-block; border-radius: 999px; background: #f3f4f6; padding: 2px 7px; font-size: 10px; color: #374151; }
        @media print { .actions { display: none; } body { background: #fff; } .doc { padding: 0; } }
    </style>
</head>
<body>
<main class="doc">
    @unless($pdf)
        <div class="actions">
            <div>
                <strong>Report-Profile:</strong>
                @foreach($profile as $key => $label)
                    <a href="{{ request()->fullUrlWithQuery(['profil' => $key, 'pdf' => null]) }}" class="{{ ($opt['profil'] ?? '') === $key ? 'active' : '' }}">{{ $label }}</a>
                @endforeach
                <a href="{{ request()->fullUrlWithQuery(['pdf' => 1]) }}">PDF herunterladen</a>
                <a href="javascript:window.print()">Drucken</a>
            </div>
            <div class="secondary" style="margin-top:6px">
                <strong>Filter:</strong>
                @foreach(['preise' => 'Preise', 'lieferanten' => 'Lieferanten', 'steps' => 'Steps', 'sensorik' => 'Sensorik', 'produktion' => 'Produktion', 'notizen' => 'Notizen', 'kaskade' => 'Kaskade'] as $key => $label)
                    <a href="{{ request()->fullUrlWithQuery([$key => ($opt[$key] ?? false) ? 0 : 1, 'pdf' => null]) }}" class="{{ ($opt[$key] ?? false) ? 'active' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
    @endunless

    <header>
        <div class="muted">{{ $titel ?? 'Report' }} · {{ now()->format('d.m.Y H:i') }}</div>
        <h1>{{ $name ?? 'Report' }}</h1>
        <div class="muted">Profil: {{ $profile[$opt['profil'] ?? 'produktion'] ?? ($opt['profil'] ?? '—') }}</div>
    </header>

    @if($concept)
        <section>
            <h2>Concept-Übersicht</h2>
            <div class="grid meta">
                <div><span>Status</span>{{ $concept['status'] ?? '—' }}</div>
                <div><span>Anlass</span>{{ $concept['occasion'] ?? '—' }}</div>
                <div><span>Niveau</span>{{ $concept['level'] ?? '—' }}</div>
                <div><span>Kategorie</span>{{ $concept['category'] ?? '—' }}</div>
                <div><span>Preis/Person</span>{{ $money($concept['price_per_person_cache'] ?? null) }}</div>
                <div><span>EK/Person</span>{{ $money($concept['ek_per_person_cache'] ?? null) }}</div>
                <div><span>Arbeitszeit</span>{{ ($concept['work_time_min_cache'] ?? null) !== null ? $concept['work_time_min_cache'] . ' min' : '—' }}</div>
                <div><span>Servierform</span>{{ $concept['serving_form'] ?? '—' }}</div>
            </div>
            @if($concept['description'] ?? null)<p class="intro">{{ $concept['description'] }}</p>@endif
            @if(count($concept['moments'] ?? []) || count($concept['seasons'] ?? []))
                <p class="muted">Einsatzmomente: {{ implode(', ', $concept['moments'] ?? []) ?: '—' }} · Saison: {{ implode(', ', $concept['seasons'] ?? []) ?: '—' }}</p>
            @endif
        </section>

        <section>
            <h2>Slots</h2>
            @forelse($concept['slots'] as $slot)
                <div class="slot">
                    <h3>{{ $slot['role'] ?: 'Slot' }} @if($slot['title'])<span class="muted">· {{ $slot['title'] }}</span>@endif <span class="badge">{{ $slot['type'] }}</span></h3>
                    @if($slot['package'])
                        <p class="muted">Paket: {{ $slot['package']['name'] }} · Preis {{ $money($slot['package']['price_per_person']) }} p. P. · EK {{ $money($slot['package']['ek_per_person'], 2) }} p. P.</p>
                    @endif
                    @forelse($slot['gerichte'] as $g)
                        @if($g['paket'] ?? null)<p class="muted">Aus Paket {{ $g['paket'] }}{{ $g['menge'] ? ' · ' . $g['menge'] : '' }}</p>@endif
                        @include('foodalchemist::dokumente.partials.report-recipe-node', ['node' => $g['recipe'], 'optionen' => $opt])
                    @empty
                        <p class="muted">Leer.</p>
                    @endforelse
                </div>
            @empty
                <p class="muted">Keine Slots.</p>
            @endforelse
        </section>
    @elseif($recipe)
        @include('foodalchemist::dokumente.partials.report-recipe-node', ['node' => $recipe, 'optionen' => $opt])
    @endif
</main>
</body>
</html>
