{{-- Spec 61/75 · Zugriffsrechte: Rollen kommen aus den Team-Einstellungen der Plattform; das FA pflegt
     nur das Häkchen „darf Rechnungen freigeben" und die Bereichs-Einschränkung je Mitglied (nur FA-Admin, Service prüft).
     Spec 82 §13 A: Bereiche und Kontingente des Teams stehen hier nur lesend, gepflegt wird in /verwaltung. --}}
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

    {{-- Spec 82 §13 A: Bereiche und Kontingente pflegt NUR die Plattform-Verwaltung (eine Stelle zum Steuern). Hier nur lesend. --}}
    <x-fa::section title="Gebuchte Bereiche" description="Was dieses Team gebucht hat. Gepflegt in der Plattform-Verwaltung; ein Unter-Team hat höchstens, was sein Haupt-Team hat." data-rechte-bereiche>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
            @foreach($katalog as $b => $label)
                <p class="inline-flex items-center gap-2 text-[length:var(--fa-text-sm)]" wire:key="tb-{{ $b }}" data-team-bereich="{{ $b }}" data-aktiv="{{ ($teamBereiche[$b] ?? true) ? '1' : '0' }}">
                    <x-fa::badge :tone="($teamBereiche[$b] ?? true) ? 'ok' : 'neutral'">{{ ($teamBereiche[$b] ?? true) ? 'gebucht' : 'aus' }}</x-fa::badge>
                    <span class="{{ ($teamBereiche[$b] ?? true) ? '' : 'line-through text-[var(--fa-ink-3)]' }}">{{ $label }}</span>
                </p>
            @endforeach
        </div>
        <p class="pt-3 {{ $leise }}" data-rechte-verwaltungs-hinweis>Gepflegt in der Plattform-Verwaltung.
            @if($istPlattformAdmin)<a href="{{ url('/verwaltung') }}" class="underline hover:no-underline" data-rechte-verwaltung-link>Zur Plattform-Verwaltung →</a>@endif
        </p>
    </x-fa::section>

    <x-fa::section title="Kontingente" description="Gilt für das Haupt-Team mit allen Unter-Teams. Leer = unbegrenzt. Gepflegt in der Plattform-Verwaltung." data-rechte-kontingente>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            @foreach(['max_standorte' => ['Standorte (Unter-Teams)', $nutzung['standorte']], 'max_user' => ['Benutzer', $nutzung['user']], 'ki_budget_eur_monat' => ['KI-Budget € je Monat', number_format($nutzung['ki_eur_monat'], 2, ',', '.').' €']] as $f => [$label, $ist])
                <div data-kontingent="{{ $f }}">
                    <p class="{{ $leise }}">{{ $label }}</p>
                    <p class="tabular-nums">gebucht: {{ $kontingente[$f] === null ? 'unbegrenzt' : str_replace('.', ',', (string) $kontingente[$f]) }}</p>
                    <p class="{{ $leise }} tabular-nums">genutzt: {{ $ist }}</p>
                </div>
            @endforeach
        </div>
        @unless($istHauptTeam)<p class="pt-2 {{ $leise }}">Kontingente gelten am Haupt-Team.</p>@endunless
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

    {{-- Spec 84 §4.4 (E3): Support-Zugang auf Freigabe — ohne Freigabe sieht kein Plattform-Admin Inhalte dieses Teams --}}
    @if($supportFreigaben !== null)
        <x-fa::section title="Support-Zugang" :meta="$supportFreigaben->filter->istAktiv()->count() > 0 ? 'aktiv' : null"
                       description="Ohne deine Freigabe sieht niemand vom Plattform-Support Inhalte deines Teams. Für eine Fehlersuche kannst du den Zugang befristet erteilen und jederzeit widerrufen. Jeder Zugriff wird protokolliert." data-rechte-support>
            <div class="flex flex-wrap items-end gap-2">
                <label class="flex flex-col gap-1 {{ $leise }}">Dauer (Stunden)
                    <x-fa::input type="number" min="1" max="168" wire:model="supportStunden" class="w-24" data-support-stunden />
                </label>
                <label class="flex flex-1 flex-col gap-1 {{ $leise }}">Anlass (z. B. Ticket)
                    <x-fa::input wire:model="supportGrund" placeholder="optional" data-support-grund />
                </label>
                <x-fa::button wire:click="supportErteilen" data-support-erteilen>Zugang erteilen</x-fa::button>
            </div>
            @if($supportFreigaben->isNotEmpty())
                <ul class="mt-3 flex flex-col gap-1">
                    @foreach($supportFreigaben->take(10) as $f)
                        <li class="flex items-center justify-between gap-2 {{ $leise }}" data-support-zeile="{{ $f->id }}">
                            <span>
                                @if($f->istAktiv())
                                    <strong class="text-[var(--fa-ink)]">aktiv</strong> bis {{ $f->gueltig_bis->format('d.m.Y H:i') }}
                                @elseif($f->widerrufen_am)
                                    widerrufen am {{ $f->widerrufen_am->format('d.m.Y H:i') }}
                                @else
                                    abgelaufen am {{ $f->gueltig_bis->format('d.m.Y H:i') }}
                                @endif
                                @if($f->grund) · {{ $f->grund }}@endif
                            </span>
                            @if($f->istAktiv())
                                <x-fa::button size="sm" variant="ghost" wire:click="supportWiderrufen({{ $f->id }})" data-support-widerrufen="{{ $f->id }}">widerrufen</x-fa::button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-fa::section>
    @endif
</div>
