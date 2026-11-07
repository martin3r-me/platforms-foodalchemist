@php use Platform\FoodAlchemist\Services\ObservatoriumBriefingService as BP; use Platform\FoodAlchemist\Support\ObservatoriumInhalt as O; @endphp
{{-- „Mein Briefing“: gespeicherte Einstellung je Person (Dominique 07.11.2026). Ohne Profil = Kurzfassung der aktuellen Filter. --}}
<div class="fa-obs-briefing fa-obs-abstand" data-briefing-profil>
    @if($profilOffen)
        <form wire:submit="profilSpeichern" class="flex flex-col gap-3" data-briefing-profil-form>
            <h2>Mein Briefing einstellen</h2>
            <p class="fa-obs-subtitle">Gilt nur für dich. Das Briefing zeigt dann immer diese Auswahl, egal welche Filter gerade offen sind.</p>
            <fieldset class="flex flex-col gap-1.5"><legend class="fa-obs-subtitle">Branchen (keine Auswahl = alle)</legend>
                <div class="fa-obs-filters">@foreach(O::BRANCHEN as $key => $name)<label class="fa-obs-button"><input type="checkbox" value="{{ $key }}" wire:model="profil.branchen">{{ $name }}</label>@endforeach</div>
            </fieldset>
            <div class="fa-obs-filters">
                <label class="fa-obs-field small"><select wire:model="profil.region" aria-label="Region im Briefing">@foreach(BP::REGIONEN as $key => $name)<option value="{{ $key }}">{{ $name }}</option>@endforeach</select></label>
                <label class="fa-obs-field small"><select wire:model="profil.tage" aria-label="Zeitraum im Briefing">@foreach(BP::TAGE as $key => $name)<option value="{{ $key }}">{{ $name }}</option>@endforeach</select></label>
                <label class="fa-obs-field search"><input type="text" wire:model="profil.themen" maxlength="600" aria-label="Themen im Briefing" placeholder="Themen, mit Komma getrennt (z. B. Protein, Bowl, alkoholfrei)"></label>
            </div>
            <div class="fa-obs-actions">
                <button type="submit" class="fa-obs-button primary">Speichern</button>
                <button type="button" class="fa-obs-button" wire:click="profilAbbrechen">Abbrechen</button>
                @if($briefingProfil)<button type="button" class="fa-obs-filter-reset" wire:click="profilLoeschen" wire:confirm="Einstellung löschen? Das Briefing zeigt dann wieder die aktuellen Filter." data-briefing-profil-loeschen>Einstellung löschen</button>@endif
            </div>
        </form>
    @elseif($briefingProfil)
        <span class="fa-obs-pill">Deine Einstellung</span>
        <p class="fa-obs-subtitle fa-obs-abstand" data-briefing-profil-text>{{ BP::zusammenfassung($briefingProfil) }}</p>
        <button type="button" class="fa-obs-link" wire:click="profilBearbeiten" data-briefing-profil-bearbeiten>Einstellung ändern @svg('heroicon-o-adjustments-horizontal')</button>
    @else
        <p class="fa-obs-subtitle">Gerade zeigt das Briefing die Kurzfassung deiner aktuellen Filter. Stell es einmal ein, dann siehst du hier immer deine Branchen, Region und Themen.</p>
        <button type="button" class="fa-obs-link" wire:click="profilBearbeiten" data-briefing-profil-bearbeiten>Briefing einstellen @svg('heroicon-o-adjustments-horizontal')</button>
    @endif
</div>
