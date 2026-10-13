{{-- Spec 82 §7 · DNA-Zusatzfelder: das Oberteam ergänzt je Canvas-Typ eigene Felder. Kern-Felder bleiben fest. --}}
<div class="flex flex-col gap-4" data-settings-dna-felder>
    @php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true, 'hint' => 'Zusatzfelder gelten für dieses Team und seine Unter-Teams.'])
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">

    <x-fa::section title="Zusatzfelder" description="Die Kern-Felder jeder DNA bleiben fest. Hier ergänzt du eigene Felder, zum Beispiel „Nachhaltigkeit“ an der Team-DNA. Sie erscheinen im Board und fließen in die KI-Texte ein. Die Feldgruppe ist der Reiter, unter dem das Feld steht.">
        <div class="flex flex-wrap items-end gap-3">
            <x-fa::field label="Gilt für" for="dnaf-typ" class="w-56 max-w-full">
                <x-fa::select id="dnaf-typ" wire:model.live="typ" data-dnaf-typ>
                    @foreach($typen as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                </x-fa::select>
            </x-fa::field>
        </div>
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Kern-Felder ({{ count($kernFelder) }}, nicht änderbar): {{ collect($kernFelder)->pluck('label')->implode(' · ') }}</p>

        @if($fehler)<x-fa::notice tone="crit" data-dnaf-fehler>{{ $fehler }}</x-fa::notice>@endif

        <div class="grid gap-2 items-end grid-cols-[repeat(auto-fit,minmax(min(100%,11rem),1fr))]">
            <x-fa::field label="Beschriftung" for="dnaf-label"><x-fa::input id="dnaf-label" wire:model="form.label" placeholder="Nachhaltigkeit" data-dnaf-label /></x-fa::field>
            <x-fa::field label="Schlüssel" for="dnaf-key" hint="Kleinbuchstaben und Unterstrich"><x-fa::input id="dnaf-key" wire:model="form.key" placeholder="nachhaltigkeit" data-dnaf-key /></x-fa::field>
            <x-fa::field label="Feldgruppe (Reiter)" for="dnaf-gruppe" optional><x-fa::input id="dnaf-gruppe" wire:model="form.group_name" placeholder="Haltung" /></x-fa::field>
            <x-fa::field label="Art" for="dnaf-art">
                <x-fa::select id="dnaf-art" wire:model="form.type"><option value="longtext">Langer Text</option><option value="text">Kurzer Text</option></x-fa::select>
            </x-fa::field>
            <div><x-fa::button icon="heroicon-m-plus" variant="primary" wire:click="anlegen" data-dnaf-anlegen>Feld anlegen</x-fa::button></div>
        </div>

        @if($felder->isEmpty())
            <x-fa::empty compact icon="heroicon-o-rectangle-stack" title="Noch keine Zusatzfelder">Für „{{ $typen[$typ] }}“ gibt es nur die Kern-Felder.</x-fa::empty>
        @else
            <div class="overflow-x-auto -mx-4">
                <table class="fa-table">
                    <thead><tr><th class="w-full">Feld</th><th>Gruppe</th><th>Art</th><th><span class="sr-only">Aktionen</span></th></tr></thead>
                    <tbody>
                        @foreach($felder as $f)
                            <tr class="{{ $f->active ? '' : 'opacity-60' }}" wire:key="dnaf-{{ $f->id }}" data-dnaf-zeile="{{ $f->id }}">
                                <td class="font-medium">{{ $f->label }} <span class="text-[var(--fa-ink-3)] font-normal">({{ $f->field_key }})</span>@unless($f->active) <x-fa::badge>aus</x-fa::badge>@endunless</td>
                                <td>{{ $f->group_name ?: '—' }}</td>
                                <td>{{ $f->type === 'text' ? 'Kurzer Text' : ($f->type === 'repeatable' ? 'Liste' : 'Langer Text') }}</td>
                                <td class="whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-fa::button size="sm" variant="ghost" wire:click="aktivSchalten({{ $f->id }})">{{ $f->active ? 'Ausschalten' : 'Einschalten' }}</x-fa::button>
                                        <x-fa::button size="sm" variant="ghost" wire:click="loeschen({{ $f->id }})" wire:confirm="Zusatzfeld „{{ $f->label }}“ entfernen? Die eingetragenen Werte bleiben liegen, werden aber nicht mehr gelesen.">Entfernen</x-fa::button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-fa::section>
    </fieldset>
</div>
