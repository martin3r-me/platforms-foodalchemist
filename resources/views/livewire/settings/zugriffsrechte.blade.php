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

    {{-- Spec 77b: gebuchte Bereiche (Plattform-Admin schaltet), Kontingente --}}
    <x-fa::section title="Freigeschaltete Bereiche" description="Was dieses Team gebucht hat. Freischalten ist Sache des Plattform-Admins; ein Unter-Team hat höchstens, was sein Haupt-Team hat." data-rechte-bereiche>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
            @foreach($katalog as $b => $label)
                <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-sm)]" wire:key="tb-{{ $b }}">
                    <input type="checkbox" @checked($teamBereiche[$b] ?? true) @disabled(! $istPlattformAdmin)
                        wire:change="teamBereichSetzen('{{ $b }}', $event.target.checked)" data-team-bereich="{{ $b }}">
                    <span class="{{ ($teamBereiche[$b] ?? true) ? '' : 'line-through text-[var(--fa-ink-3)]' }}">{{ $label }}</span>
                </label>
            @endforeach
        </div>
    </x-fa::section>

    <x-fa::section title="Kontingente" description="Gilt für das Haupt-Team mit allen Unter-Teams. Leer = unbegrenzt. Pflege durch den Plattform-Admin." data-rechte-kontingente>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            @foreach(['max_standorte' => ['Standorte (Unter-Teams)', $nutzung['standorte']], 'max_user' => ['Benutzer', $nutzung['user']], 'ki_budget_eur_monat' => ['KI-Budget € je Monat', number_format($nutzung['ki_eur_monat'], 2, ',', '.').' €']] as $f => [$label, $ist])
                <x-fa::field :label="$label" :for="'k-'.$f" :hint="'genutzt: '.$ist">
                    @if($istPlattformAdmin && $istHauptTeam)
                        <x-fa::input :id="'k-'.$f" size="sm" numeric wire:model="kontingentForm.{{ $f }}" placeholder="unbegrenzt" />
                    @else
                        <p class="tabular-nums">{{ $kontingente[$f] === null ? 'unbegrenzt' : str_replace('.', ',', (string) $kontingente[$f]) }}</p>
                    @endif
                </x-fa::field>
            @endforeach
        </div>
        @if($istPlattformAdmin && $istHauptTeam)
            <div><x-fa::button size="sm" wire:click="kontingenteSpeichern" data-kontingente-speichern>Kontingente speichern</x-fa::button></div>
        @endif
    </x-fa::section>

    <x-fa::section title="Mitglieder" :meta="count($mitglieder)" description="Rechnungen freigeben dürfen Inhaber und Admins immer. Für Mitglieder setzt du hier das Häkchen — z. B. für das Büro, ohne es zum Admin zu machen.">
        @unless($istAdmin)
            <x-fa::notice tone="info">Deine Rolle: „{{ $meineRolle->label() }}“. Das Freigaberecht vergeben nur Inhaber und Admins.</x-fa::notice>
        @endunless
        <div class="overflow-x-auto -mx-4">
            <table class="fa-table">
                <thead><tr><th>Name</th><th>E-Mail</th><th>Rolle (Plattform)</th><th>im Food Alchemist</th><th>darf Rechnungen freigeben</th><th>Bereiche</th></tr></thead>
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
                            <td>
                                @if($istAdmin && in_array($m['plattform_rolle'], ['member', 'viewer'], true))
                                    <x-fa::button size="sm" variant="ghost" wire:click="bereicheUmschalten({{ $m['user_id'] }})" data-recht-bereiche-knopf="{{ $m['user_id'] }}">einschränken</x-fa::button>
                                @else
                                    <span class="{{ $leise }}">alle gebuchten</span>
                                @endif
                            </td>
                        </tr>
                        @if($bereicheFuer === $m['user_id'])
                            <tr wire:key="recht-b-{{ $m['user_id'] }}" data-recht-bereiche="{{ $m['user_id'] }}">
                                <td colspan="6" class="bg-[var(--fa-ground)]">
                                    <p class="{{ $leise }} pb-2">Häkchen weg = {{ $m['name'] }} sieht diesen Bereich nicht. Nur gebuchte Bereiche sind wählbar.</p>
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2 pb-2">
                                        @foreach($katalog as $b => $label)
                                            @continue(! ($teamBereiche[$b] ?? true))
                                            <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-sm)]">
                                                <input type="checkbox" @checked(! in_array($b, $userSperren, true))
                                                    wire:change="userBereichSetzen({{ $m['user_id'] }}, '{{ $b }}', $event.target.checked)" data-user-bereich="{{ $b }}">
                                                {{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-fa::section>
</div>
