{{-- Spec 82 §6 — Teamprofil: was die Küche kann. Equipment dreiwertig (vorhanden / fehlt / unbekannt);
     Standardausstattung gilt ohne Angabe als vorhanden. Schreibt sofort, Leiste Bearbeiten → Fertig (Spec 65). --}}
<div class="flex flex-col gap-4" data-settings-teamprofil>
    @php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true, 'hint' => 'Fakten über deine Küche. Die DNA beschreibt, wie ihr kochen wollt, das Profil, was ihr könnt.'])
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    @include('foodalchemist::livewire.settings.partials.teamprofil-body')
    </fieldset>
</div>
