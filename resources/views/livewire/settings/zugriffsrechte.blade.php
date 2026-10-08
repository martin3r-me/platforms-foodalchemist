{{-- Spec 61/75 · Zugriffsrechte: Rollen kommen aus den Team-Einstellungen der Plattform; das FA pflegt
     nur das Häkchen „darf Rechnungen freigeben" für Mitglieder (nur FA-Admin, Service prüft). --}}
@php($leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]')
<div class="flex flex-col gap-4" data-settings-zugriffsrechte>
    @if($fehler)<x-fa::notice tone="crit" data-rechte-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($meldung)<x-fa::notice tone="ok" data-rechte-meldung>{{ $meldung }}</x-fa::notice>@endif

    <x-fa::section title="Was die Rollen im Food Alchemist dürfen" description="Die Rolle eines Mitglieds (Inhaber, Admin, Mitglied, Betrachter) pflegt ein Team-Admin in den Team-Einstellungen der Plattform. Eine Rolle im Haupt-Team gilt in allen Unter-Teams mindestens.">
        <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2">
            @foreach($rollen as $r)
                <div><dt class="font-semibold">{{ $r->label() }}</dt><dd class="{{ $leise }}">{{ $r->beschreibung() }}</dd></div>
            @endforeach
        </dl>
    </x-fa::section>

    <x-fa::section title="Mitglieder" :meta="count($mitglieder)" description="Rechnungen freigeben dürfen Inhaber und Admins immer. Für Mitglieder setzt du hier das Häkchen — z. B. für das Büro, ohne es zum Admin zu machen.">
        @unless($istAdmin)
            <x-fa::notice tone="info">Deine Rolle: „{{ $meineRolle->label() }}“. Das Freigaberecht vergeben nur Inhaber und Admins.</x-fa::notice>
        @endunless
        <div class="overflow-x-auto -mx-4">
            <table class="fa-table">
                <thead><tr><th>Name</th><th>E-Mail</th><th>Rolle (Plattform)</th><th>im Food Alchemist</th><th>darf Rechnungen freigeben</th></tr></thead>
                <tbody>
                    @foreach($mitglieder as $m)
                        <tr wire:key="recht-{{ $m['user_id'] }}" data-recht-mitglied="{{ $m['user_id'] }}">
                            <td class="font-medium">{{ $m['name'] }}@if($m['ki']) <x-fa::badge tone="info">KI</x-fa::badge>@endif</td>
                            <td class="{{ $leise }}">{{ $m['email'] }}</td>
                            <td>{{ $m['plattform_rolle_label'] }}</td>
                            <td><x-fa::badge :tone="$m['rolle'] === 'admin' ? 'accent' : 'neutral'">{{ $m['rolle_label'] }}</x-fa::badge></td>
                            <td>
                                @if($m['haken_moeglich'] && $istAdmin)
                                    <input type="checkbox" @checked($m['darf_freigeben']) wire:change="freigabeSetzen({{ $m['user_id'] }}, $event.target.checked)"
                                        aria-label="{{ $m['name'] }} darf Rechnungen freigeben" data-recht-haken="{{ $m['user_id'] }}">
                                @else
                                    <span class="{{ $leise }}">{{ $m['darf_freigeben'] ? 'ja' : 'nein' }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-fa::section>
</div>
