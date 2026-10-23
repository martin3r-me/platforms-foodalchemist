{{-- Ein Bereich im Einstellungs-Reiter: EINE ruhige Karte (Symbol, Name, ein Satz Zweck, Inhalt) mit Sprungmarke.
     E-3 (Dominique 23.10.): die Abschnitts-Karten der Komponente werden in der Bereichs-Karte flach (kein Rahmen im Rahmen), getrennt
     nur durch eine Linie. lazy = lädt erst beim Hineinscrollen; ziel = Sprungziel aus der URL (bereich=…), sofort geladen und
     angesprungen; imReiter = die Bearbeitung steuert die Leiste des Reiters. --}}
<section id="bereich-{{ $key }}" class="scroll-mt-24 min-w-0 fa-surface p-5 flex flex-col gap-4" data-settings-sektion="{{ $key }}"
         @if($ziel) x-data x-init="$nextTick(() => $el.scrollIntoView({ block: 'start' }))" @endif>
    <div class="min-w-0 flex items-start gap-3" data-settings-kopf="{{ $key }}">
        <span class="shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-[var(--fa-radius-control)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]">@svg($icon, 'w-[18px] h-[18px]')</span>
        <div class="min-w-0">
            <h2 class="text-[length:var(--fa-text-xl)] font-semibold tracking-tight leading-tight text-[var(--fa-ink)]">{{ $meta['label'] }}</h2>
            <p class="mt-0.5 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] max-w-[75ch]">{{ $meta['hint'] }}</p>
        </div>
    </div>
    <div class="min-w-0 [&_section.fa-surface]:border-0 [&_section.fa-surface]:shadow-none [&_section.fa-surface]:bg-transparent [&_section.fa-surface]:p-0 [&_section.fa-surface]:rounded-none [&_section.fa-surface+section.fa-surface]:border-t [&_section.fa-surface+section.fa-surface]:border-[var(--fa-line)] [&_section.fa-surface+section.fa-surface]:pt-4"
         data-settings-inhalt>
        @livewire('foodalchemist.settings.' . $key, array_filter(['lazy' => $lazy ? true : null, 'imReiter' => $imReiter ? true : null]), key('sektion-' . $key))
    </div>
</section>
