{{-- KI-Assistent am Gericht: „Rezeptur erstellen" für ein Gericht im Entwurf ohne Komponenten. Öffnet die Planung
     (Reiter Gericht) mit Name und Beschreibung als Briefing; gebaut wird dort nach Freigabe des Bauplans, in dieses Gericht. --}}
<div data-vk-rezeptur-erstellen>
    @if($angeboten)
        <button type="button" role="menuitem" wire:click="planen" wire:loading.attr="disabled"
                class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]"
                title="Öffnet die Planung mit Name und Beschreibung dieses Gerichts als Briefing. Dort entsteht zuerst ein Bauplan zur Freigabe, dann Komponenten und Basisrezepte in diesem Gericht. Name, Beschreibung und Einordnung bleiben stehen."
                data-vk-rezeptur-knopf>
            @svg('heroicon-o-beaker', 'w-4 h-4 text-[var(--fa-ink-3)]') Rezeptur erstellen
        </button>
        <p class="px-3 pb-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Dieses Gericht hat noch keine Komponenten. Die Planung baut sie aus Name und Beschreibung.</p>
        @if($fehler !== null)
            <div class="px-3 pb-2"><x-fa::notice tone="crit" data-vk-rezeptur-fehler>{{ $fehler }}</x-fa::notice></div>
        @endif
        <div class="my-1 border-t border-[var(--fa-line)]"></div>
    @endif
</div>
