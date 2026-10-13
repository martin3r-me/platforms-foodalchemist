{{-- Food-DNA als Einstellungen-Sektion (Ebene 1 der DNA-Kette) — geteiltes Canvas-Board
     fa-pass 2026-10-05: Erklärung als Hinweis über dem Board, Board unverändert eingebunden. --}}
<div class="flex flex-col gap-4">
    <x-fa::notice tone="info" title="Die Identität deines Hauses">
        Leitbild, Stil, Aromatik und No-Gos gelten für jede KI-Erzeugung im Team. Darauf bauen die Kunden-DNA und das einzelne Foodbook auf.
    </x-fa::notice>
    {{-- Spec 65: erst „Bearbeiten" (sperrt den Bereich für das Team), dann Felder pflegen, „Fertig" gibt frei --}}
    @php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
        @include('foodalchemist::livewire.canvas.partials.board')
    </fieldset>
</div>
