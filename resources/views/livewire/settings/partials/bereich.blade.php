{{-- Ein Unter-Reiter im Einstellungs-Reiter: EINE ruhige Karte (Symbol, Name, ein Satz Zweck, Inhalt).
     E-3 (Dominique 23.10.): die Abschnitts-Karten der Komponente werden in der Bereichs-Karte flach (kein Rahmen im Rahmen), getrennt
     nur durch eine Linie. E-4: es gibt nur noch EINE Karte je Reiter (der aktive Unter-Reiter); `eintrag.abschnitt` zeigt nur diesen
     Teil der Sektion. imReiter = die Bearbeitung steuert die Leiste des Reiters. Der key enthält den Unter-Reiter, damit Livewire beim
     Wechsel zwischen zwei Abschnitten derselben Sektion neu mountet.
     Abnahme E-4 (Dominique 24.10.): steht im Unter-Reiter nur EIN Abschnitt, dessen Titel die Karten-Überschrift wiederholt
     (Index::EINZELTITEL), entfällt der innere Titel (h3) der obersten Abschnitts-Ebene; Beschreibung und Knöpfe bleiben. --}}
<section id="bereich-{{ $key }}" class="min-w-0 fa-surface p-5 flex flex-col gap-4" data-settings-sektion="{{ $eintrag['sektion'] }}" data-settings-bereich="{{ $key }}">
    <div class="min-w-0 flex items-start gap-3" data-settings-kopf="{{ $key }}">
        <span class="shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-[var(--fa-radius-control)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]">@svg($icon, 'w-[18px] h-[18px]')</span>
        <div class="min-w-0">
            <h2 class="text-[length:var(--fa-text-xl)] font-semibold tracking-tight leading-tight text-[var(--fa-ink)]">{{ $eintrag['label'] }}</h2>
            <p class="mt-0.5 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] max-w-[75ch]">{{ $eintrag['hint'] }}</p>
        </div>
    </div>
    <div class="min-w-0 [&_section.fa-surface]:border-0 [&_section.fa-surface]:shadow-none [&_section.fa-surface]:bg-transparent [&_section.fa-surface]:p-0 [&_section.fa-surface]:rounded-none [&_section.fa-surface+section.fa-surface]:border-t [&_section.fa-surface+section.fa-surface]:border-[var(--fa-line)] [&_section.fa-surface+section.fa-surface]:pt-4 {{ in_array($key, \Platform\FoodAlchemist\Livewire\Settings\Index::EINZELTITEL, true) ? '[&_section.fa-surface:not(section_section_section)>header_h3]:hidden' : '' }}"
         data-settings-inhalt @if(in_array($key, \Platform\FoodAlchemist\Livewire\Settings\Index::EINZELTITEL, true)) data-settings-einzeltitel @endif>
        @livewire('foodalchemist.settings.' . $eintrag['sektion'], array_filter(['abschnitt' => $eintrag['abschnitt'], 'imReiter' => $imReiter ? true : null]), key('bereich-' . $key))
    </div>
</section>
