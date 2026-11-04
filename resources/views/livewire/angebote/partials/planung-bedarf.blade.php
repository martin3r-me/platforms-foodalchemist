{{-- Spec 94b §3 · Lese-Ansicht des Planungs-Bedarfs aus einer Konfigurator-Anfrage (eingefroren in konfiguration_json.planung). Schlicht:
     je Leistung Anzahl (je Tageszeit), Stunden, Komponenten mit Bedarf, Attribute, interne Notiz. Keine Bearbeitung, keine Summen über
     Leistungen, kein Bestandsabgleich, kein Druck — geplant wird später in OLIVIA. Erwartet $planung. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $zahl = fn ($w) => $w === null ? '–' : rtrim(rtrim(number_format((float) $w, 2, ',', '.'), '0'), ',');
    $zeit = ['lunch' => 'Lunch', 'dinner' => 'Dinner', 'day_night' => 'Day-into-Night'];
    $je = ['einheit' => 'je Einheit', 'gast' => 'je Gast', 'stunde' => 'je Stunde'];
    $kopf = $planung['kopf'] ?? [];
@endphp
<x-fa::section title="Bedarf für die Planung" icon="heroicon-o-clipboard-document-check" :meta="count($planung['leistungen'] ?? [])"
    description="Aus der Konfigurator-Anfrage eingefroren ({{ $kopf['gaeste'] ?? '–' }} Gäste, {{ $zeit[$kopf['tageszeit'] ?? ''] ?? '–' }}{{ ! empty($kopf['paket']) ? ', ' . $kopf['paket'] : '' }}). Nur zum Lesen, geplant wird in OLIVIA."
    data-angebot-planung>
    @forelse($planung['leistungen'] ?? [] as $l)
        <div class="flex flex-col gap-1 py-2 border-b border-[var(--fa-line)] last:border-0" data-planung-leistung="{{ $l['uuid'] ?? '' }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="font-medium">{{ $l['bezeichnung'] }} <span class="{{ $leise }}">· {{ $l['kategorie'] }}{{ ($l['abrechnung'] ?? '') === 'aufwand' ? ' · nach Aufwand' : '' }}</span></span>
                <span>{{ $zahl($l['anzahl']) }}@if($l['stunden'] !== null) <span class="{{ $leise }}">· {{ $zahl($l['stunden']) }} Std.</span>@endif</span>
            </div>
            @if(count($l['einsaetze'] ?? []) > 1)
                <span class="{{ $leise }}">@foreach($l['einsaetze'] as $e){{ $zeit[$e['tageszeit']] ?? $e['tageszeit'] }} {{ $zahl($e['anzahl']) }}@if($e['stunden'] !== null) × {{ $zahl($e['stunden']) }} Std.@endif @if(! $loop->last)· @endif @endforeach</span>
            @endif
            @if(! empty($l['planung']['komponenten']))
                <ul class="pl-4 list-disc {{ $leise }}" data-planung-komponenten>
                    @foreach($l['planung']['komponenten'] as $c)
                        <li>{{ $c['bezeichnung'] }}: {{ $zahl($c['bedarf'] ?? null) }} <span>({{ $zahl($c['menge']) }} {{ $je[$c['je'] ?? 'einheit'] ?? '' }})</span></li>
                    @endforeach
                </ul>
            @endif
            @if(! empty($l['planung']['attribute']))
                <span class="{{ $leise }}">@foreach($l['planung']['attribute'] as $a){{ $a['schluessel'] }}: {{ $a['wert'] }}{{ ! empty($a['einheit']) ? ' ' . $a['einheit'] : '' }}@if(! $loop->last) · @endif @endforeach</span>
            @endif
            @if(! empty($l['planung']['notiz_intern']))<span class="{{ $leise }}">Notiz: {{ $l['planung']['notiz_intern'] }}</span>@endif
        </div>
    @empty
        <p class="{{ $leise }}">Keine Leistungen in dieser Auswahl.</p>
    @endforelse
</x-fa::section>
