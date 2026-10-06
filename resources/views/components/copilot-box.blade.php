{{--
    Spec 03 L6b: Copilot-Befunde als Karten — eine Fläche für BEIDE Editoren
    (RecipeModal + VkModal). Der Host reicht nur `prefix` (Marker-Präfix) und
    `zeilenWort` (Zutat vs. Komponente) durch; alles andere kommt aus dem
    `RecipeReviewService`.

    Die Karte zeigt bewusst BEIDE Sorten: den anwendbaren Befund (mit Knopf) und
    den nicht anwendbaren (mit dem WARUM aus dem Service). Ein weggeblendeter
    Hinweis wäre für den Koch die interessantere Hälfte — und ein `fehlt` ohne
    Bestandstreffer ist gerade KEIN Fehler, sondern der Hard-Stop: erst anlegen.

    fa-pass (2026-10-05): Tokens (hell und Werkbank-Modus). Knöpfe tragen berechnete
    Marker-Namen (data-<prefix>…) und bleiben darum echte <button> in x-fa::button-Optik.
--}}
@props([
    'copilot',
    'status' => null,
    'prefix' => '',
    'zeilenWort' => 'Zutat',
])
@php
    $artTon = [
        'menge' => 'warn',
        'einheit' => 'warn',
        'entfernen' => 'crit',
        'fehlt' => 'accent',
        // Kohärenz-Prüfung: fachlich unpassende, verknüpfte Zutat → Übernahme löst die Verknüpfung.
        'fremdkoerper' => 'crit',
        'hinweis' => 'neutral',
        // S5b-2: Bauart-Befund — eigener Pass, Auflösung über die Struktur statt die Zeile.
        'bauart' => 'info',
    ];
    $artRand = [
        'warn' => 'border-l-[var(--fa-warn)]', 'crit' => 'border-l-[var(--fa-crit)]', 'accent' => 'border-l-[var(--fa-accent)]',
        'info' => 'border-l-[var(--fa-info)]', 'neutral' => 'border-l-[var(--fa-line-strong)]',
    ];
    $artWort = [
        'menge' => 'Menge', 'einheit' => 'Einheit', 'entfernen' => 'Entfernen',
        'fehlt' => 'Fehlt', 'fremdkoerper' => 'Passt nicht', 'hinweis' => 'Hinweis', 'bauart' => 'Aufbau',
    ];
    // Das WARUM kommt aus dem Service (`status`) — hier wird es nur übersetzt.
    $warum = [
        'kein_ziel' => 'Keine passende Zeile im Rezept gefunden, nicht anwendbar.',
        'ohne_wert' => 'Kein verwertbarer Wert (Menge oder Einheit), nicht anwendbar.',
        'schon_drin' => 'Steht bereits im Rezept, nichts zu tun.',
        'letzte_zutat' => 'Letzte Zeile: ein Rezept ohne ' . $zeilenWort . 'en wird nicht gespeichert.',
        'nur_hinweis' => 'Hinweis ohne Änderung, zur Kenntnis.',
        'schon_offen' => 'Zeile ist bereits offen, es gibt keine Verknüpfung zu lösen.',
        // Bewusst kein Knopf: die Umstellung kippt is_sales_recipe samt Taxonomie,
        // Verkaufs-Facetten und Darreichungen. Das entscheidet ein Mensch im Editor.
        'strukturentscheidung' => 'Grundsatzentscheidung: die Einordnung als Gericht oder Komponente von Hand umstellen. '
            . 'Daran hängen Kategorie, Verkaufsangaben und Darreichungen.',
    ];
    $befunde = $copilot['befunde'] ?? [];
    $anwendbar = collect($befunde)->where('auto_applicable', true)->count();
    $menge = fn ($wert) => rtrim(rtrim(number_format((float) $wert, 2, ',', '.'), '0'), ',');

    $knopfBasis = 'inline-flex items-center justify-center gap-1.5 whitespace-nowrap font-medium rounded-[var(--fa-radius-control)] transition-colors duration-150 disabled:opacity-50 disabled:pointer-events-none';
    $knopfKi = $knopfBasis . ' h-9 px-3.5 text-[length:var(--fa-text-md)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] border border-[var(--fa-accent-line)] hover:bg-[var(--fa-accent-soft-hover)]';
    $knopf = $knopfBasis . ' h-7 px-2.5 text-[length:var(--fa-text-sm)] bg-[var(--fa-surface)] text-[var(--fa-ink)] border border-[var(--fa-line-strong)] hover:bg-[var(--fa-hover)]';
    $knopfLeise = $knopfBasis . ' h-7 px-2.5 text-[length:var(--fa-text-sm)] bg-transparent text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]';
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
@endphp

<div class="mb-3 flex flex-col gap-2 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-3.5 py-3" data-{{ $prefix }}copilot-box>
    <div class="flex flex-wrap items-center gap-2">
        <button type="button" wire:click="copilotPruefen" wire:loading.attr="disabled" class="{{ $knopfKi }}" data-{{ $prefix }}copilot-start>
            <span wire:loading.remove wire:target="copilotPruefen" class="inline-flex items-center gap-1.5">@svg('heroicon-o-clipboard-document-check', 'w-4 h-4') Rezept prüfen</span>
            <span wire:loading wire:target="copilotPruefen" class="inline-flex items-center gap-1.5">@svg('heroicon-m-arrow-path', 'w-4 h-4 animate-spin') Wird geprüft …</span>
        </button>
        @if($copilot !== null)
            <span class="{{ $leise }}">{{ count($befunde) }} {{ count($befunde) === 1 ? 'Befund' : 'Befunde' }} · {{ $anwendbar }} direkt übernehmbar · {{ round(($copilot['confidence'] ?? 0) * 100) }} % sicher</span>
            <span class="ml-auto flex items-center gap-1.5">
                <button type="button" wire:click="copilotVerwerfen" class="{{ $knopfLeise }}" data-{{ $prefix }}copilot-verwerfen>Schließen</button>
                @if($anwendbar > 0)
                    <button type="button" wire:click="copilotAlleUebernehmen" class="{{ $knopf }}" data-{{ $prefix }}copilot-alle>@svg('heroicon-m-check', 'w-3.5 h-3.5') Alle übernehmen ({{ $anwendbar }})</button>
                @endif
            </span>
        @endif
    </div>

    @if(is_string($copilot['gesamturteil'] ?? null) && trim($copilot['gesamturteil']) !== '')
        <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]" data-{{ $prefix }}copilot-urteil>{{ $copilot['gesamturteil'] }}</p>
    @endif

    @if($befunde !== [])
        <div class="flex flex-col gap-1.5 max-h-72 overflow-y-auto" data-{{ $prefix }}copilot-befunde>
            @foreach($befunde as $i => $b)
                @php
                    $ton = $artTon[$b['art']] ?? 'neutral';
                @endphp
                <div class="flex flex-col gap-1 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] border-l-[3px] {{ $artRand[$ton] }} bg-[var(--fa-ground)] px-2.5 py-2" wire:key="{{ $prefix }}cp-{{ $i }}-{{ $b['art'] }}" data-{{ $prefix }}copilot-befund>
                    <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[length:var(--fa-text-md)]">
                        <x-fa::badge :tone="$ton">{{ $artWort[$b['art']] ?? ucfirst((string) $b['art']) }}</x-fa::badge>
                        <span class="font-medium text-[var(--fa-ink)]">{{ $b['zutat_text'] !== '' ? $b['zutat_text'] : 'ohne Zeile' }}</span>
                        @if($b['art'] === 'menge' && $b['quantity'] !== null)
                            <span class="inline-flex items-center gap-1 text-[var(--fa-ink-2)]">@svg('heroicon-m-arrow-right', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]') <span class="tabular-nums">{{ $menge($b['quantity']) }}</span></span>
                        @endif
                        @if($b['art'] === 'einheit' && $b['einheit_slug'] !== null)
                            <span class="inline-flex items-center gap-1 text-[var(--fa-ink-2)]">@svg('heroicon-m-arrow-right', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]') {{ $b['einheit_slug'] }}</span>
                        @endif
                        <span class="{{ $leise }} tabular-nums">· {{ round(($b['konfidenz'] ?? 0) * 100) }} %</span>
                    </div>
                    @if($b['begruendung'] !== '')
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $b['begruendung'] }}</p>
                    @endif

                    <div class="flex flex-wrap items-center gap-1.5">
                        @if($b['auto_applicable'])
                            <button type="button" wire:click="copilotUebernehmen({{ $i }})" class="{{ $knopf }}" data-{{ $prefix }}copilot-apply>@svg('heroicon-m-check', 'w-3.5 h-3.5') {{ $b['art'] === 'fremdkoerper' ? 'Verknüpfung lösen' : 'Übernehmen' }}</button>
                            @if($b['art'] === 'fehlt' && $b['ziel'] !== null)
                                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ok)]">{{ $b['kind'] === 'gp' ? 'Grundprodukt' : 'Rezept' }}: {{ $b['ziel'] }}</span>
                            @endif
                        @elseif($b['status'] === 'kein_treffer')
                            {{-- Hard-Stop-Doktrin (#508): ohne Bestandstreffer wird NICHT geraten. --}}
                            <p class="flex items-start gap-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-warn)]" data-{{ $prefix }}copilot-hardstop>
                                @svg('heroicon-m-exclamation-triangle', 'w-4 h-4 mt-px shrink-0')
                                <span>Nicht im Bestand. Erst {{ $b['primaer'] === 'basisrezept_anlegen' ? 'ein Basisrezept' : 'ein Grundprodukt' }} anlegen, dann erneut prüfen.</span>
                            </p>
                        @else
                            <p class="{{ $leise }}">{{ $warum[$b['status']] ?? 'Nicht anwendbar.' }}</p>
                        @endif
                        {{-- S5b: „Lass das so" gibt es NUR für abgelegte Befunde (finding_id) — nur die lassen sich
                             dauerhaft ruhigstellen. Bewusst auch am nicht anwendbaren Befund. --}}
                        @if(($b['finding_id'] ?? null) !== null)
                            <button type="button" wire:click="copilotBefundVerwerfen({{ $i }})"
                                    class="{{ $knopfLeise }} ml-auto" data-{{ $prefix }}copilot-dismiss
                                    title="Befund bewusst akzeptieren, er wird nicht wieder gemeldet">Lass das so</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if($status !== null)
        <p class="{{ $leise }}" data-{{ $prefix }}copilot-status>{{ $status }}</p>
    @endif
    <p class="{{ $leise }}">Prüfen ändert nichts. Übernehmen schreibt genau diesen einen Befund ins Rezept und rechnet Kosten und Allergene neu.</p>
</div>
