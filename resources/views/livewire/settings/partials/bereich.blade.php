{{-- Ein Bereich im Einstellungs-Reiter: Kopf (Name + Zweck) mit Sprungmarke, darunter die eigenständige Livewire-Komponente.
     lazy = lädt erst beim Hineinscrollen; ziel = Sprungziel aus der URL (bereich=…), wird sofort geladen und angesprungen. --}}
<section id="bereich-{{ $key }}" class="scroll-mt-24 min-w-0 flex flex-col gap-4" data-settings-sektion="{{ $key }}"
         @if($ziel) x-data x-init="$nextTick(() => $el.scrollIntoView({ block: 'start' }))" @endif>
    <div class="min-w-0 border-b border-[var(--fa-line)] pb-2" data-settings-kopf="{{ $key }}">
        <h2 class="text-[length:var(--fa-text-xl)] font-semibold tracking-tight leading-tight text-[var(--fa-ink)]">{{ $meta['label'] }}</h2>
        <p class="mt-1 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] max-w-[75ch]">{{ $meta['hint'] }}</p>
    </div>
    @livewire('foodalchemist.settings.' . $key, $lazy ? ['lazy' => true] : [], key('sektion-' . $key))
</section>
