{{-- Spec 65 · Bearbeitungssperre je Einstellungs-Bereich (Ziel settings.<bereich> je Team).
     Parameter: $sperr (sperrZustand), $sofort (bool: Bearbeiten/Fertig statt Bearbeiten/Abbrechen), $hint (optional).
     - lesen/fremd: klebende Leiste oben mit „Bearbeiten" bzw. „wird von … bearbeitet" (der Speichern-Knopf ist ausgeblendet)
     - bearbeiten:  „Abbrechen" bzw. „Fertig" — der Speichern-Knopf der Sektion (save-bar / Abschnitte) bleibt, wo er ist
     - aus/neu:     nichts (Schalter aus = Verhalten wie vor Spec 65) --}}
@php
    $sperrModus = $sperr['modus'] ?? 'aus';
    $sperrSofort = (bool) ($sofort ?? false);
@endphp
@if(in_array($sperrModus, ['lesen', 'fremd'], true))
    <div class="sticky top-0 z-20 -mx-1 px-1 py-2 bg-[var(--fa-surface)] border-b border-[var(--fa-line)] flex flex-wrap items-center gap-3" data-settings-sperrleiste>
        <x-foodalchemist::bearbeiten-leiste :zustand="$sperr" :sofort="$sperrSofort" />
        @if(! empty($hint))
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] truncate">{{ $hint }}</p>
        @endif
    </div>
@elseif($sperrModus === 'bearbeiten')
    <div class="flex flex-wrap items-center justify-end gap-2" data-settings-sperrleiste>
        <x-foodalchemist::bearbeiten-leiste :zustand="$sperr" :sofort="$sperrSofort" />
    </div>
@endif
