{{-- Spec 03 · L2b: Vorschau-Fläche des KI-Kundentexts, geteilt von Buch-Einleitung und
     Kapitel-Hinführung. Der Vorschlag ist noch NIRGENDS geschrieben — „Ersetzen" statt
     „Übernehmen", wenn im Ziel-Feld schon Text steht: überschreiben soll man sehen, nicht
     bemerken.
     $ziel      = welche Fläche rendert; muss zu $kiTextZiel passen, sonst zeigte die
                  Buch-Fläche einen Kapitel-Vorschlag (der Zustand ist geteilt).
     $vorhanden = trägt das Ziel-Feld heute schon Text? --}}
@if($kiTextZiel === $ziel)
    @if($kiTextVorschau !== null)
        <div class="mt-2 rounded-xl border border-violet-300/60 bg-violet-500/5 p-3 space-y-2" data-fb-ki-vorschau>
            {{-- @if NIE direkt an ein Wortzeichen kleben: Blade lässt die Direktive dann
                 uncompiliert stehen, das @endif aber nicht → verwaistes endif im Kompilat. --}}
            <p class="{{ $label }} !mb-0">KI-Vorschlag — noch nicht übernommen
                @if($kiTextConfidence !== null) · Konfidenz {{ number_format($kiTextConfidence * 100, 0) }} %@endif
            </p>
            <p class="text-xs text-gray-700 whitespace-pre-line">{{ $kiTextVorschau }}</p>
            @if($vorhanden)
                <p class="text-[11px] text-amber-600">Im Feld steht schon ein Text — „Ersetzen" schreibt ihn über (endgültig erst beim Speichern).</p>
            @endif
            <div class="flex gap-2">
                <button type="button" wire:click="kiTextUebernehmen" class="{{ $btnPrimary }}">{{ $vorhanden ? 'Ersetzen' : 'Übernehmen' }}</button>
                <button type="button" wire:click="kiTextVerwerfen" class="{{ $btnGhost }}">Verwerfen</button>
            </div>
        </div>
    @endif
    @if($kiTextHinweis !== null)
        <p class="text-[11px] text-amber-600 mt-1" data-fb-ki-hinweis>{{ $kiTextHinweis }}</p>
    @endif
@endif
