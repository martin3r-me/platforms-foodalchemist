{{-- Spec 57 · Paket 6: Druck-Formate neben dem Wochenaushang.
     format = tag (Tischaufsteller, ein Tag, alle Linien) · schild (je Linie ein Schild) ·
     liste (Allergen- und Komponentenliste für den Ordner an der Ausgabe).
     Daten aus SpeiseplanService::ausgabeFormat — Kennzeichnung immer aus den Rezepten. --}}
@php($diaetText = ['vegan' => 'vegan', 'vegetarisch' => 'vegetarisch', 'schwein' => 'mit Schwein', 'rind' => 'mit Rind', 'fisch' => 'mit Fisch', 'fleisch' => 'mit Fleisch'])
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ ['tag' => 'Tischaufsteller', 'schild' => 'Linienschilder', 'liste' => 'Allergen- und Komponentenliste'][$format] ?? 'Speiseplan' }} — {{ $plan->name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1f2937; font-size: 11px; line-height: 1.45; margin: 0; padding: 24px; }
        .doc { max-width: 820px; margin: 0 auto; }
        .actions { margin-bottom: 16px; }
        .btn { display: inline-block; padding: 6px 12px; background: #6d28d9; color: #fff; text-decoration: none; border-radius: 6px; margin-right: 6px; }
        .btn.ghost { background: #eee; color: #374151; }
        @media print { .actions { display: none; } body { padding: 0; } }
        .codes { color: #6d28d9; font-size: 9px; font-weight: bold; }
        .muted { color: #6b7280; }
        .legende { margin-top: 14px; border-top: 1px solid #ececec; padding-top: 8px; font-size: 9px; color: #4b5563; }
        .legende .lg { margin-right: 10px; white-space: nowrap; line-height: 1.8; }
        .legende .c { color: #6d28d9; font-weight: bold; }
        .foot { margin-top: 14px; color: #9ca3af; font-size: 9px; }
        /* Tischaufsteller */
        .aufsteller { text-align: center; padding: 10px 0; }
        .aufsteller .kopf { font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: #6b7280; }
        .aufsteller .titel { font-size: 22px; margin: 4px 0 14px; color: #111827; }
        .aufsteller .linie { margin: 0 auto 14px; max-width: 560px; }
        .aufsteller .linie-name { font-size: 10px; letter-spacing: .1em; text-transform: uppercase; font-weight: bold; }
        .aufsteller .gericht { font-size: 16px; font-weight: bold; color: #111827; margin-top: 2px; }
        .aufsteller .wording { font-size: 11px; color: #4b5563; }
        .aufsteller .preis { font-size: 13px; font-weight: bold; margin-top: 2px; }
        /* Linienschild */
        .schild { page-break-after: always; border-top: 10px solid #6d28d9; padding: 24px 18px; text-align: center; min-height: 300px; }
        .schild:last-child { page-break-after: auto; }
        .schild .kopf { font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: #6b7280; }
        .schild .gericht { font-size: 28px; font-weight: bold; line-height: 1.15; margin: 14px 0 6px; color: #111827; }
        .schild .wording { font-size: 14px; color: #4b5563; }
        .schild .merkmale { margin-top: 12px; font-size: 11px; }
        .schild .merkmale span { display: inline-block; border: 1px solid #9ca3af; border-radius: 4px; padding: 1px 6px; margin: 0 2px 4px; }
        .schild .preis { font-size: 30px; font-weight: bold; margin-top: 14px; }
        .schild .leer { color: #9ca3af; font-size: 16px; margin-top: 40px; }
        /* Liste */
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 16px 0 6px; color: #4c1d95; border-bottom: 2px solid #6d28d9; padding-bottom: 2px; }
        table.liste { width: 100%; border-collapse: collapse; }
        table.liste th, table.liste td { border-bottom: 1px solid #e5e7eb; padding: 4px 5px; text-align: left; vertical-align: top; }
        table.liste th { color: #6b7280; font-weight: normal; font-size: 10px; }
        td.komp { font-size: 10px; color: #4b5563; }
    </style>
</head>
<body>
<div class="doc">
    @unless($istPdf ?? false)
        <div class="actions">
            <a class="btn" href="?{{ http_build_query(array_merge(request()->query(), ['pdf' => 1])) }}">PDF herunterladen</a>
            <a class="btn ghost" href="javascript:window.print()">Drucken</a>
            @if($mitPreis)
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['preise' => null, 'pdf' => null]) }}">→ ohne Preise</a>
            @else
                <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['preise' => 1, 'pdf' => null]) }}">→ mit Preisen</a>
            @endif
        </div>
    @endunless

    @if($format === 'tag')
        @foreach($bloecke as $b)
            <div class="aufsteller">
                <div class="kopf">{{ $plan->name }} · {{ $mahlzeitLabel }}</div>
                <div class="titel">{{ $b['label'] }}</div>
                @forelse($b['zeilen'] as $z)
                    <div class="linie">
                        <div class="linie-name" style="color: {{ $z['color'] ?: '#6d28d9' }}">{{ $z['linie'] }}</div>
                        @foreach($z['eintraege'] as $e)
                            <div class="gericht">{{ $e['titel'] }} <span class="codes">{{ implode(', ', $e['codes']) }}</span></div>
                            @if($e['untertitel'])<div class="wording">{{ $e['untertitel'] }}</div>@endif
                            @if($e['vk'] !== null)<div class="preis">{{ number_format((float) $e['vk'], 2, ',', '.') }} €</div>@endif
                        @endforeach
                    </div>
                @empty
                    <div class="muted">Für diesen Tag ist nichts geplant.</div>
                @endforelse
            </div>
        @endforeach
    @elseif($format === 'schild')
        @foreach($bloecke as $b)
            @foreach($b['zeilen'] as $z)
                <div class="schild" style="border-top-color: {{ $z['color'] ?: '#6d28d9' }}">
                    <div class="kopf">{{ $z['linie'] }}{{ $z['plu'] ? ' · Kasse ' . $z['plu'] : '' }} · {{ $b['label'] }}</div>
                    @forelse($z['eintraege'] as $e)
                        <div class="gericht">{{ $e['titel'] }}</div>
                        @if($e['untertitel'])<div class="wording">{{ $e['untertitel'] }}</div>@endif
                        <div class="merkmale">
                            @foreach($e['diaet'] as $d)<span>{{ $diaetText[$d] ?? $d }}</span>@endforeach
                            @foreach($e['codes'] as $c)<span>{{ $c }}</span>@endforeach
                        </div>
                        @if($e['vk'] !== null)<div class="preis">{{ number_format((float) $e['vk'], 2, ',', '.') }} €</div>@endif
                    @empty
                        <div class="leer">Heute an dieser Ausgabe nichts geplant.</div>
                    @endforelse
                </div>
            @endforeach
        @endforeach
    @else
        <h1>Allergen- und Komponentenliste — {{ $plan->name }}</h1>
        <div class="muted">{{ $kwLabel }} · {{ $mahlzeitLabel }}</div>
        @foreach($bloecke as $b)
            <h2>{{ $b['label'] }}</h2>
            @if($b['zeilen'] === [])
                <div class="muted">Nichts geplant.</div>
            @else
                <table class="liste">
                    <thead><tr><th style="width:18%">Linie</th><th style="width:34%">Gericht</th><th>Komponenten</th><th style="width:16%">Kennzeichnung</th></tr></thead>
                    <tbody>
                        @foreach($b['zeilen'] as $z)
                            @foreach($z['eintraege'] as $e)
                                <tr>
                                    <td>{{ $z['linie'] }}</td>
                                    <td><strong>{{ $e['titel'] }}</strong>@if($e['untertitel'])<br><span class="muted">{{ $e['untertitel'] }}</span>@endif</td>
                                    <td class="komp">{{ collect($e['komponenten'])->map(fn ($k) => trim($k['name'] . ' ' . ($k['menge'] ?? '')))->implode(' · ') ?: '—' }}</td>
                                    <td><span class="codes">{{ implode(', ', $e['codes']) ?: '—' }}</span></td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach
    @endif

    @if(!empty($legende['allergene']) || !empty($legende['zusatzstoffe']))
        <div class="legende">
            @foreach($legende['allergene'] ?? [] as $a)<span class="lg"><span class="c">{{ $a['code'] }}</span> = {{ $a['label'] }}</span>@endforeach
            @foreach($legende['zusatzstoffe'] ?? [] as $z)<span class="lg"><span class="c">{{ $z['code'] }}</span> = {{ $z['label'] }}</span>@endforeach
            <div class="muted" style="margin-top:4px;">* = Spuren möglich. Angaben aus den hinterlegten Rezepturen; ohne Angabe = nicht bewertet, nicht „frei“. Legende gilt für die ganze Woche.</div>
        </div>
    @endif
    <div class="foot">Erstellt mit Food Alchemist · {{ $erzeugt }}</div>
</div>
</body>
</html>
