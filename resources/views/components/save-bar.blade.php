{{--
    Klebende Speicher-Leiste für Formular-Sektionen (Einstellungen).

    Warum oben und nicht unten: die Formular-Sektionen sind lang (Herstellkosten ~2.000 px). Der
    Knopf stand ganz unten — wer oben ein Feld ändert, muss erst durch die halbe Seite scrollen,
    um zu speichern. Oben klebend ist er aus jeder Scroll-Position erreichbar.

    Zwei Dinge beim Ändern beachten:
      1. `sticky` braucht einen begrenzten Scroll-Vorfahren. Hier scrollt der Seiten-Container —
         gerät irgendwo dazwischen ein `overflow: hidden`, klebt die Leiste stumm nicht mehr.
      2. Der Hintergrund MUSS deckend genug sein: unter der Leiste scrollt Inhalt durch.
--}}
@props(['action' => 'speichern', 'label' => 'Speichern', 'meldung' => null, 'hint' => null])
@php($saveUi = \Platform\FoodAlchemist\Support\Ui::maps())

<div {{ $attributes->merge(['class' => 'sticky top-0 z-20 -mx-1 px-1 py-2 bg-white/90 backdrop-blur-sm flex items-center gap-3']) }} data-save-bar>
    <button type="button" wire:click="{{ $action }}" class="{{ $saveUi['btnPrimary'] }} shrink-0" data-save-bar-button>
        {{ $label }}
    </button>

    @if($meldung)
        <p class="text-xs text-emerald-600 truncate" data-save-bar-meldung>{{ $meldung }}</p>
    @elseif($hint)
        <p class="text-[11px] text-gray-500 truncate">{{ $hint }}</p>
    @endif
</div>
