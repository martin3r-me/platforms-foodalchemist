{{-- Spec 03 · L2b: Vorschau-Fläche des KI-Kundentexts, geteilt von Buch-Einleitung und
     Kapitel-Hinführung. Der Vorschlag ist noch NIRGENDS geschrieben — „Ersetzen" statt
     „Übernehmen", wenn im Ziel-Feld schon Text steht: überschreiben soll man sehen, nicht
     bemerken. fa-pass: Tokens und x-fa-Bausteine (hell und Werkbank).
     $ziel      = welche Fläche rendert; muss zu $kiTextZiel passen, sonst zeigte die
                  Buch-Fläche einen Kapitel-Vorschlag (der Zustand ist geteilt).
     $vorhanden = trägt das Ziel-Feld heute schon Text? --}}
@if($kiTextZiel === $ziel)
    @if($kiTextVorschau !== null)
        <div class="mt-2 flex flex-col gap-2 rounded-[var(--fa-radius-surface)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] p-3" data-fb-ki-vorschau>
            {{-- @if NIE direkt an ein Wortzeichen kleben: Blade lässt die Direktive dann
                 uncompiliert stehen, das @endif aber nicht → verwaistes endif im Kompilat. --}}
            <p class="flex flex-wrap items-center gap-2 text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-accent)]">
                @svg('heroicon-m-sparkles', 'w-4 h-4 shrink-0')
                <span>Vorschlag der KI, noch nicht übernommen</span>
                @if($kiTextConfidence !== null)
                    <span class="font-normal text-[var(--fa-ink-2)] tabular-nums">Sicherheit {{ number_format($kiTextConfidence * 100, 0) }} %</span>
                @endif
            </p>
            <p class="text-[length:var(--fa-text-md)] leading-relaxed text-[var(--fa-ink)] whitespace-pre-line">{{ $kiTextVorschau }}</p>
            @if($vorhanden)
                <x-fa::signal tone="warn">Im Feld steht schon ein Text. Ersetzen überschreibt ihn, endgültig erst beim Speichern.</x-fa::signal>
            @endif
            <div class="flex flex-wrap gap-2">
                <x-fa::button variant="primary" size="sm" icon="heroicon-m-check" wire:click="kiTextUebernehmen">{{ $vorhanden ? 'Text ersetzen' : 'Text übernehmen' }}</x-fa::button>
                <x-fa::button variant="ghost" size="sm" wire:click="kiTextVerwerfen">Vorschlag verwerfen</x-fa::button>
            </div>
        </div>
    @endif
    @if($kiTextHinweis !== null)
        <x-fa::signal tone="warn" class="mt-1" data-fb-ki-hinweis>{{ $kiTextHinweis }}</x-fa::signal>
    @endif
@endif
