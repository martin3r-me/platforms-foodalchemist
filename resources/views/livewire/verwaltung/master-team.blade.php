{{-- Spec 82 §13 B4 — Master-Team festlegen (Plattform-Admin). Eingebettet in /verwaltung → Freigaben. --}}
<div class="flex flex-col gap-3" data-fa-master-team>
    @if(! $admin)
        <x-fa::notice tone="info">Das Master-Team festlegen dürfen nur Plattform-Admins.</x-fa::notice>
    @else
        @if($fehler)<x-fa::notice tone="crit" data-master-team-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($meldung)<x-fa::notice tone="ok" data-master-team-meldung>{{ $meldung }}</x-fa::notice>@endif

        <x-fa::section title="Master-Team" icon="heroicon-o-star" data-master-team-sektion
            description="Der eine Kurator der Plattform: sieht das Stamm-Paket immer, pflegt den globalen Bestand und die Master-Bibliothek. Ein Wechsel ändert die Sichtbarkeit aller Abos.">
            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" data-master-team-aktuell>
                @if($master['team_id'])
                    Aktuell: <strong>{{ $master['name'] ?? ('Team #'.$master['team_id']) }}</strong>
                    <x-fa::badge tone="{{ $master['quelle'] === 'einstellung' ? 'accent' : 'neutral' }}" class="ml-1">{{ $master['quelle'] === 'einstellung' ? 'Einstellung' : 'Umgebungsvariable' }}</x-fa::badge>
                @else
                    Kein Master-Team festgelegt.
                @endif
            </p>
            <div class="flex flex-wrap items-end gap-2 pt-3">
                <x-fa::field label="Neues Master-Team" for="mt-ziel" class="w-72 max-w-full">
                    <x-fa::select id="mt-ziel" wire:model="zielTeamId" placeholder="Team wählen" data-master-team-ziel>
                        <option value="0">– Eintrag entfernen (Umgebungsvariable gilt) –</option>
                        @foreach($teams as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                    </x-fa::select>
                </x-fa::field>
                <x-fa::button variant="primary" wire:click="vorschau" data-master-team-vorschau>Wirkung prüfen</x-fa::button>
            </div>
        </x-fa::section>

        @if($vorschau)
            <x-fa::section title="Wirkung des Wechsels" icon="heroicon-o-exclamation-triangle" data-master-team-vorschau-box>
                @if(! $vorschau['aendert_sich'])
                    <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">Das Team ist schon Master-Team, es ändert sich nichts.</p>
                @elseif($vorschau['abos'] === [])
                    <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">Keine Abos betroffen. Der Wechsel gilt sofort.</p>
                @else
                    <x-fa::notice tone="warn">Der Wechsel ändert die Sichtbarkeit von {{ count($vorschau['abos']) }} Abo(s).</x-fa::notice>
                @endif
                <div class="flex gap-2 pt-3">
                    <x-fa::button variant="ghost" wire:click="abbrechen">Abbrechen</x-fa::button>
                    <x-fa::button variant="primary" wire:click="festlegen" data-master-team-bestaetigen>{{ $vorschau['abos'] === [] ? 'Festlegen' : 'Trotzdem festlegen' }}</x-fa::button>
                </div>
            </x-fa::section>
        @endif
    @endif
</div>
