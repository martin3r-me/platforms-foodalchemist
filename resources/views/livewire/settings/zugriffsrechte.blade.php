{{-- Spec 61 · Zugriffsrechte: FA-Rolle je Mitglied. Ändern nur als FA-Admin (Service prüft). --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $quelleText = ['plattform' => 'Plattform-Admin', 'team_admin' => 'Inhaber/Admin des Teams', 'eigen' => 'in diesem Team vergeben', 'geerbt' => 'aus dem Haupt-Team', 'standard' => 'Standard'];
@endphp
<div class="flex flex-col gap-4" data-settings-zugriffsrechte>
    @if($fehler)<x-fa::notice tone="crit" data-rechte-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($meldung)<x-fa::notice tone="ok" data-rechte-meldung>{{ $meldung }}</x-fa::notice>@endif

    <x-fa::section title="Rollen im Food Alchemist" description="Jede Rolle enthält die vorige. Neue Mitglieder starten mit Lesen. Inhaber und Admins des Teams sind immer FA-Admin. Eine Rolle im Haupt-Team gilt in allen Unter-Teams mindestens.">
        <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2">
            @foreach($rollen as $r)
                <div><dt class="font-semibold">{{ $r->label() }}</dt><dd class="{{ $leise }}">{{ $r->beschreibung() }}</dd></div>
            @endforeach
        </dl>
    </x-fa::section>

    <x-fa::section title="Mitglieder" :meta="count($mitglieder)">
        @unless($istAdmin)
            <x-fa::notice tone="info">Deine Rolle: „{{ $meineRolle->label() }}“. Rollen vergeben nur FA-Admins.</x-fa::notice>
        @endunless
        <div class="overflow-x-auto -mx-4">
            <table class="fa-table">
                <thead><tr><th>Name</th><th>E-Mail</th><th>FA-Rolle</th><th>Woher</th></tr></thead>
                <tbody>
                    @foreach($mitglieder as $m)
                        <tr wire:key="recht-{{ $m['user_id'] }}" data-recht-mitglied="{{ $m['user_id'] }}">
                            <td class="font-medium">{{ $m['name'] }}@if($m['ki']) <x-fa::badge tone="info">KI</x-fa::badge>@endif</td>
                            <td class="{{ $leise }}">{{ $m['email'] }}</td>
                            <td>
                                @if($istAdmin && $m['aenderbar'])
                                    <x-fa::select size="sm" wire:change="rolleSetzen({{ $m['user_id'] }}, $event.target.value)" aria-label="FA-Rolle von {{ $m['name'] }}" data-recht-select="{{ $m['user_id'] }}">
                                        @foreach($rollen as $r)
                                            @continue($m['ki'] && $r->rang() > \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren->rang())
                                            <option value="{{ $r->value }}" @selected($m['rolle'] === $r->value)>{{ $r->label() }}</option>
                                        @endforeach
                                    </x-fa::select>
                                @else
                                    <x-fa::badge :tone="$m['rolle'] === 'admin' ? 'accent' : 'neutral'">{{ $m['rolle_label'] }}</x-fa::badge>
                                @endif
                            </td>
                            <td class="{{ $leise }}">{{ $quelleText[$m['quelle']] ?? $m['quelle'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-fa::section>
</div>
