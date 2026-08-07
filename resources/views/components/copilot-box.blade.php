{{--
    Spec 03 L6b: Copilot-Befunde als Karten — eine Fläche für BEIDE Editoren
    (RecipeModal + VkModal). Der Host reicht nur `prefix` (Marker-Präfix) und
    `zeilenWort` (Zutat vs. Komponente) durch; alles andere kommt aus dem
    `RecipeReviewService`.

    Die Karte zeigt bewusst BEIDE Sorten: den anwendbaren Befund (mit Knopf) und
    den nicht anwendbaren (mit dem WARUM aus dem Service). Ein weggeblendeter
    Hinweis wäre für den Koch die interessantere Hälfte — und ein `fehlt` ohne
    Bestandstreffer ist gerade KEIN Fehler, sondern der Hard-Stop: erst anlegen.
--}}
@props([
    'copilot',
    'status' => null,
    'prefix' => '',
    'zeilenWort' => 'Zutat',
])
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
    $artFarbe = [
        'menge' => 'text-amber-700 bg-amber-500/10 border-amber-500/30',
        'einheit' => 'text-amber-700 bg-amber-500/10 border-amber-500/30',
        'entfernen' => 'text-rose-700 bg-rose-500/10 border-rose-500/30',
        'fehlt' => 'text-violet-700 bg-violet-500/10 border-violet-500/30',
        // Kohärenz-Gate: fachlich unpassende, verdrahtete Zutat → Übernahme löst die Verknüpfung.
        'fremdkoerper' => 'text-orange-700 bg-orange-500/10 border-orange-500/30',
        'hinweis' => 'text-gray-600 bg-black/[0.03] border-black/10',
        // S5b-2: Bauart-Befund — andere Herkunft (eigener Pass), andere Auflösung
        // (Struktur statt Zeile). Eigene Farbe, damit er in der Liste nicht als
        // beliebiger Hinweis untergeht.
        'bauart' => 'text-sky-700 bg-sky-500/10 border-sky-500/30',
    ];
    $artWort = [
        'menge' => 'Menge', 'einheit' => 'Einheit', 'entfernen' => 'Entfernen',
        'fehlt' => 'Fehlt', 'fremdkoerper' => 'Fremdkörper', 'hinweis' => 'Hinweis', 'bauart' => 'Bauart',
    ];
    // Das WARUM kommt aus dem Service (`status`) — hier wird es nur übersetzt.
    $warum = [
        'kein_ziel' => 'Keine passende Zeile im Rezept gefunden — nicht anwendbar.',
        'ohne_wert' => 'Kein verwertbarer Wert (Menge/Einheit) — nicht anwendbar.',
        'schon_drin' => 'Steht bereits im Rezept — nichts zu tun.',
        'letzte_zutat' => 'Letzte Zeile: ein Rezept ohne ' . $zeilenWort . 'en wird nicht gespeichert.',
        'nur_hinweis' => 'Hinweis ohne Schreibziel — zur Kenntnis.',
        'schon_offen' => 'Zeile ist bereits offen — es gibt keine Verknüpfung zu lösen.',
        // Bewusst kein Knopf: die Umstellung kippt is_sales_recipe samt Taxonomie,
        // Verkaufs-Facetten und Darreichungen. Das entscheidet ein Mensch im Editor.
        'strukturentscheidung' => 'Struktur-Entscheidung: Einordnung als Gericht bzw. Komponente von Hand umstellen — '
            . 'daran hängen Taxonomie, Verkaufs-Facetten und Darreichungen.',
    ];
    $befunde = $copilot['befunde'] ?? [];
    $anwendbar = collect($befunde)->where('auto_applicable', true)->count();
@endphp

<div class="mb-3 rounded-lg bg-violet-500/5 border border-violet-500/20 px-3 py-2 space-y-2" data-{{ $prefix }}copilot-box>
    <div class="flex flex-wrap items-center gap-2">
        <button type="button" wire:click="copilotPruefen" wire:loading.attr="disabled" class="{{ $btnPrimary }}" data-{{ $prefix }}copilot-start>
            <span wire:loading.remove wire:target="copilotPruefen">@svg('heroicon-o-clipboard-document-check', 'w-3.5 h-3.5 inline-block align-middle') Rezept prüfen</span>
            <span wire:loading wire:target="copilotPruefen">prüft …</span>
        </button>
        @if($copilot !== null)
            <span class="text-[11px] text-gray-600">{{ count($befunde) }} Befund(e) · {{ $anwendbar }} direkt übernehmbar · Urteil {{ round(($copilot['confidence'] ?? 0) * 100) }} %</span>
            @if($anwendbar > 0)
                <button type="button" wire:click="copilotAlleUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-{{ $prefix }}copilot-alle>Alle übernehmen ({{ $anwendbar }})</button>
            @endif
            <button type="button" wire:click="copilotVerwerfen" class="{{ $btnGhostXs }}" data-{{ $prefix }}copilot-verwerfen>Schließen</button>
        @endif
    </div>

    @if(is_string($copilot['gesamturteil'] ?? null) && trim($copilot['gesamturteil']) !== '')
        <p class="text-[11px] font-medium text-violet-700" data-{{ $prefix }}copilot-urteil>{{ $copilot['gesamturteil'] }}</p>
    @endif

    @if($befunde !== [])
        <div class="space-y-1.5 max-h-72 overflow-y-auto" data-{{ $prefix }}copilot-befunde>
            @foreach($befunde as $i => $b)
                <div class="rounded-lg border px-2.5 py-1.5 {{ $artFarbe[$b['art']] ?? $artFarbe['hinweis'] }}" wire:key="{{ $prefix }}cp-{{ $i }}-{{ $b['art'] }}" data-{{ $prefix }}copilot-befund>
                    <div class="flex flex-wrap items-center gap-x-1.5 text-[11px]">
                        <span class="font-semibold uppercase tracking-wider">{{ $artWort[$b['art']] ?? $b['art'] }}</span>
                        <span class="text-gray-900">{{ $b['zutat_text'] !== '' ? $b['zutat_text'] : '—' }}</span>
                        @if($b['art'] === 'menge' && $b['quantity'] !== null)
                            <span class="text-gray-600">→ {{ rtrim(rtrim(number_format((float) $b['quantity'], 2, ',', '.'), '0'), ',') }}</span>
                        @endif
                        @if($b['art'] === 'einheit' && $b['einheit_slug'] !== null)
                            <span class="text-gray-600">→ {{ $b['einheit_slug'] }}</span>
                        @endif
                        <span class="text-gray-500">· {{ round(($b['konfidenz'] ?? 0) * 100) }} %</span>
                    </div>
                    @if($b['begruendung'] !== '')
                        <p class="text-[11px] text-gray-600 mt-0.5">{{ $b['begruendung'] }}</p>
                    @endif

                    @if($b['auto_applicable'])
                        <div class="mt-1 flex items-center gap-1.5">
                            <button type="button" wire:click="copilotUebernehmen({{ $i }})" class="{{ $btnGhostXs }} text-emerald-600" data-{{ $prefix }}copilot-apply>{{ $b['art'] === 'fremdkoerper' ? 'Entdrahten' : 'Übernehmen' }}</button>
                            @if($b['art'] === 'fehlt' && $b['ziel'] !== null)
                                <span class="text-[10px] text-emerald-700">→ {{ $b['kind'] === 'gp' ? 'GP' : 'Rezept' }}: {{ $b['ziel'] }}</span>
                            @endif
                        </div>
                    @elseif($b['status'] === 'kein_treffer')
                        {{-- Hard-Stop-Doktrin (#508): ohne Bestandstreffer wird NICHT geraten. --}}
                        <p class="text-[10px] mt-0.5 text-violet-700" data-{{ $prefix }}copilot-hardstop>
                            @svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5 inline-block align-middle') Kein Bestandstreffer → erst {{ $b['primaer'] === 'basisrezept_anlegen' ? 'Basisrezept (Stub) anlegen' : 'GP anlegen' }}, dann erneut prüfen.
                        </p>
                    @else
                        <p class="text-[10px] mt-0.5 text-gray-500">{{ $warum[$b['status']] ?? 'Nicht anwendbar.' }}</p>
                    @endif
                    {{-- S5b: „Lass das so" gibt es NUR für abgelegte Befunde (finding_id) — nur die haben
                         Bestand, den man ruhigstellen könnte. Bewusst auch am nicht-anwendbaren Befund:
                         gerade der reine Hinweis ist der, den man dauerhaft loswerden will. --}}
                    @if(($b['finding_id'] ?? null) !== null)
                        <button type="button" wire:click="copilotBefundVerwerfen({{ $i }})"
                                class="{{ $btnGhostXs }} mt-0.5 text-gray-500" data-{{ $prefix }}copilot-dismiss
                                title="Befund als bewusst akzeptiert schließen — er wird nicht wieder gemeldet.">Lass das so</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if($status !== null)
        <p class="text-[10px] text-gray-500" data-{{ $prefix }}copilot-status>{{ $status }}</p>
    @endif
    <p class="text-[10px] text-gray-500">Prüfen ist read-only. Übernehmen schreibt genau den einen Befund über den Zutaten-Sync (Grounding + Neuberechnung).</p>
</div>
