{{-- Spec 82 §13 C1 — Mandanten-Stammblatt (lesend, nur Verwaltungsspalten; §13 G: nie Inhalte). --}}
@php
    $statusTon = ['projekt' => 'warn', 'aktiv' => 'ok', 'beendet' => 'neutral'];
    $euro = fn ($v) => number_format((float) $v, 2, ',', '.').' €';
    $grenze = fn ($ist, $max) => $max === null ? $ist.' (unbegrenzt)' : $ist.' von '.$max;
@endphp
<div class="flex flex-col gap-3" data-settings-mandanten>
    @if($fehler)
        <x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>
    @else
        <x-fa::notice tone="info">Verwaltungsdaten der Mandanten. Rezepte, Preise, Foodbooks, DNA und Teamprofile zeigt diese Seite nie.</x-fa::notice>
        <x-fa::section title="Mandanten" icon="heroicon-o-building-office-2" data-mandanten-liste
            description="Kunden-Hauptteams neben dem Master-Team. Modus, Status, CRM-Firma und Notiz pflegt der Plattform-Admin per MCP (mandanten.PUT).">
            @if($blaetter === [])
                <x-fa::empty compact icon="heroicon-o-building-office-2" title="Keine Mandanten">Es gibt noch kein Haupt-Team neben dem Master-Team.</x-fa::empty>
            @else
                <div class="overflow-x-auto -mx-4">
                <table class="fa-table fa-table--compact" data-mandanten-tabelle>
                    <thead><tr><th>Mandant</th><th>Status</th><th>Modus</th><th>CRM-Firma</th><th>Stamm-Paket</th><th class="text-right">Abos</th><th class="text-right">Unter-Teams</th><th class="text-right">Gebühren Monat</th><th class="text-right">KI Monat</th><th></th></tr></thead>
                    <tbody>
                        @foreach($blaetter as $b)
                            <tr wire:key="mb-{{ $b['team_id'] }}" data-mandant-zeile="{{ $b['team_id'] }}">
                                <td class="font-medium whitespace-nowrap">{{ $b['name'] }}</td>
                                <td><x-fa::badge tone="{{ $statusTon[$b['status']] ?? 'neutral' }}">{{ ucfirst($b['status']) }}</x-fa::badge></td>
                                <td><x-fa::badge tone="{{ $b['modus'] === 'kuratiert' ? 'accent' : 'neutral' }}">{{ ucfirst($b['modus']) }}</x-fa::badge></td>
                                <td>{{ $b['crm_firma'] ?? '–' }}</td>
                                <td>{{ $b['stamm_paket']['gebucht'] ? 'ja' : 'nein' }}</td>
                                <td class="text-right tabular-nums">{{ $b['abos'] }}</td>
                                <td class="text-right tabular-nums">{{ count($b['unter_teams']) }}</td>
                                <td class="text-right tabular-nums">{{ $euro($b['gebuehren']['summe_vorschau']) }}</td>
                                <td class="text-right tabular-nums whitespace-nowrap" data-mandant-ki="{{ $b['team_id'] }}">{{ $euro($b['ki_verbrauch_eur_monat']) }}<span class="text-[var(--fa-ink-3)]"> / {{ $b['ki_deckel_eur'] === null ? 'ohne Deckel' : $euro($b['ki_deckel_eur']) }}</span></td>
                                <td class="text-right whitespace-nowrap"><x-fa::button size="sm" variant="ghost" wire:click="auf({{ $b['team_id'] }})" data-mandant-stammblatt>{{ $offen === $b['team_id'] ? 'Zuklappen' : 'Stammblatt' }}</x-fa::button></td>
                            </tr>
                            @if($offen === $b['team_id'])
                                <tr wire:key="mbd-{{ $b['team_id'] }}" data-mandant-blatt="{{ $b['team_id'] }}"><td colspan="10">
                                    <dl class="grid sm:grid-cols-2 gap-x-6 gap-y-2 py-2 text-[length:var(--fa-text-md)]">
                                        <div><dt class="text-[var(--fa-ink-3)]">Notiz</dt><dd>{{ $b['notiz'] ?: '–' }}</dd></div>
                                        <div><dt class="text-[var(--fa-ink-3)]">Stamm-Paket</dt><dd>{{ $b['stamm_paket']['gebucht'] ? 'gebucht seit '.\Carbon\Carbon::parse($b['stamm_paket']['seit'])->format('d.m.Y') : 'nicht gebucht' }}</dd></div>
                                        <div><dt class="text-[var(--fa-ink-3)]">Unter-Teams</dt><dd>{{ $b['unter_teams'] === [] ? '–' : implode(', ', $b['unter_teams']) }}</dd></div>
                                        <div><dt class="text-[var(--fa-ink-3)]">Gebuchte Bereiche</dt><dd>{{ $b['bereiche_gebucht'] }} von {{ $b['bereiche_gesamt'] }}: {{ implode(', ', array_column($b['bereiche'], 'label')) }}</dd></div>
                                        <div><dt class="text-[var(--fa-ink-3)]">Kontingent Standorte</dt><dd>{{ $grenze($b['kontingente']['standorte'], $b['kontingente']['max_standorte']) }}</dd></div>
                                        <div><dt class="text-[var(--fa-ink-3)]">Kontingent User</dt><dd>{{ $grenze($b['kontingente']['user'], $b['kontingente']['max_user']) }}</dd></div>
                                        <div><dt class="text-[var(--fa-ink-3)]">KI-Verbrauch Monat</dt><dd>{{ $euro($b['ki_verbrauch_eur_monat']) }} · {{ match($b['ki_deckel_quelle']) {
                                            'unbegrenzt' => 'unbegrenzt (vom Master gesetzt)',
                                            'kontingent' => 'Deckel '.$euro($b['ki_deckel_eur']).' (vom Master gesetzt)',
                                            'projekt_standard' => 'Deckel '.$euro($b['ki_deckel_eur']).' (Standard Projektphase)',
                                            default => 'ohne Deckel (Abrechnung nach Verbrauch)',
                                        } }}</dd></div>
                                        <div><dt class="text-[var(--fa-ink-3)]">Gebühren {{ $b['gebuehren']['monat'] }}</dt><dd>gebucht {{ $euro($b['gebuehren']['summe_gebucht']) }}, Vorschau {{ $euro($b['gebuehren']['summe_vorschau']) }}</dd></div>
                                        <div><dt class="text-[var(--fa-ink-3)]">Modus zuletzt geändert</dt><dd>{{ $b['modus_geaendert_am'] ? \Carbon\Carbon::parse($b['modus_geaendert_am'])->format('d.m.Y') : 'nie' }}</dd></div>
                                    </dl>
                                    <div class="flex flex-wrap items-end gap-3 py-2 border-t border-[var(--fa-line)]" data-mandant-ki-deckel="{{ $b['team_id'] }}">
                                        <label class="flex flex-col gap-1 text-[length:var(--fa-text-sm)]">
                                            <span class="text-[var(--fa-ink-3)]">KI-Deckel € / Monat</span>
                                            <x-fa::input numeric size="sm" inputmode="decimal" class="w-32" placeholder="Standard" wire:model="kiDeckel.{{ $b['team_id'] }}" :disabled="$kiUnbegrenzt[$b['team_id']] ?? false" data-mandant-ki-deckel-eur />
                                        </label>
                                        <label class="flex items-center gap-2 text-[length:var(--fa-text-sm)]">
                                            <input type="checkbox" class="w-4 h-4 rounded accent-[var(--fa-accent)]" wire:model.live="kiUnbegrenzt.{{ $b['team_id'] }}" data-mandant-ki-unbegrenzt> unbegrenzt
                                        </label>
                                        <x-fa::button size="sm" variant="secondary" wire:click="kiDeckelSpeichern({{ $b['team_id'] }})" data-mandant-ki-speichern>Speichern</x-fa::button>
                                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[60ch]">Leer = Standard: Projektphase und Teams ohne Mandanten-Status {{ $euro(config('foodalchemist.ai.projekt_deckel_eur', 20)) }}, aktive Mandanten ohne Deckel. Darüber ist KI gesperrt bis zum Monatsersten oder zur Lizenz.</span>
                                    </div>
                                    @if($kiFehler)<x-fa::notice tone="crit">{{ $kiFehler }}</x-fa::notice>@endif
                                    @if($hinweis)<x-fa::notice tone="ok">{{ $hinweis }}</x-fa::notice>@endif
                                </td></tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </x-fa::section>
    @endif
</div>
