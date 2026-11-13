{{-- Leitstelle, Reiter Gericht: Hinweis, wenn die Planung ein Ziel-Gericht hat (Trait PlanRezepturFuerGericht).
     Dann entsteht kein neues Gericht: der Bau schreibt Komponenten und Basisrezepte in das vorhandene. --}}
@php
    $rezepturZiel = $this->rezepturZiel();
    $rezepturOffen = $rezepturZiel !== null && $this->rezepturOffen();
@endphp
@if($rezepturZiel !== null)
    <div class="max-w-7xl mx-auto mb-4" data-planung-rezeptur-ziel>
        @if($rezepturOffen)
            <x-fa::notice tone="info" title="Rezeptur für ein vorhandenes Gericht">
                Diese Planung baut die Rezeptur für „{{ $rezepturZiel->name }}“. Es entsteht kein neues Gericht: Komponenten und Basisrezepte kommen in das vorhandene. Name, Beschreibung und Einordnung bleiben stehen.
            </x-fa::notice>
        @else
            <x-fa::notice tone="ok" title="Rezeptur steht">
                „{{ $rezepturZiel->name }}“ hat inzwischen Komponenten. Ein weiterer Lauf in dieser Planung würde ein neues Gericht anlegen.
            </x-fa::notice>
        @endif
    </div>
@endif
