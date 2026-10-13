{{-- Spec 82 §13 C3 — Mandant beenden: Vorschau, Bestätigung, Entsperren (Plattform-Admin). Nur Verwaltungsdaten und Zählungen. --}}
<div class="flex flex-col gap-3" data-fa-mandant-beenden>
    @if(! $admin)
        <x-fa::notice tone="info">Mandanten beenden dürfen nur Plattform-Admins.</x-fa::notice>
    @else
        @if($fehler)<x-fa::notice tone="crit" data-mandant-beenden-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($meldung)<x-fa::notice tone="ok" data-mandant-beenden-meldung>{{ $meldung }}</x-fa::notice>@endif

        <x-fa::section title="Mandant beenden" icon="heroicon-o-lock-closed"
            description="Reihenfolge: Abos kündigen, Stamm-Paket abbestellen, Datenexport, Bereiche ausschalten, Team sperren. Gesperrt heißt: kein Zugriff mehr außer dem Export-Download. Rückgängig machbar. Die Daten werden nicht gelöscht.">
            @if($mandanten === [])
                <x-fa::empty compact icon="heroicon-o-building-office" title="Keine Mandanten">Es gibt noch kein Haupt-Team neben dem Master-Team.</x-fa::empty>
            @else
                <div class="overflow-x-auto -mx-4">
                <table class="fa-table fa-table--compact" data-mandant-beenden-tabelle>
                    <thead><tr><th>Mandant</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @foreach($mandanten as $m)
                            <tr wire:key="mb-{{ $m['team_id'] }}" data-mandant-beenden-zeile="{{ $m['team_id'] }}">
                                <td class="font-medium">{{ $m['name'] }}</td>
                                <td class="whitespace-nowrap"><x-fa::badge tone="{{ $m['status'] === 'beendet' ? 'crit' : 'ok' }}">{{ $m['status'] === 'beendet' ? 'Beendet, gesperrt' : ucfirst($m['status']) }}</x-fa::badge></td>
                                <td class="text-right whitespace-nowrap">
                                    @if($m['status'] === 'beendet')
                                        <x-fa::button size="sm" variant="ghost" icon="heroicon-o-lock-open" wire:click="entsperren({{ $m['team_id'] }})" data-mandant-entsperren>Entsperren</x-fa::button>
                                    @else
                                        <x-fa::button size="sm" variant="ghost" icon="heroicon-o-lock-closed" wire:click="vorschau({{ $m['team_id'] }})" data-mandant-beenden-vorschau>Beenden …</x-fa::button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </x-fa::section>

        @if($vorschau)
            <x-fa::section title="Beenden: {{ $vorschau['name'] }}" icon="heroicon-o-exclamation-triangle" data-mandant-beenden-vorschau-box>
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $vorschau['unter_teams'] }} Unter-Team(s), {{ $vorschau['mitglieder'] }} Mitglied(er). Nur Verwaltungsdaten und Zählungen, keine Inhalte.</p>
                <ol class="flex flex-col gap-2 mt-2">
                    @foreach($vorschau['schritte'] as $s)
                        <li class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] px-3 py-2" data-mandant-beenden-schritt="{{ $s['schritt'] }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ $s['titel'] }}</span>
                                <x-fa::badge tone="{{ $s['zustand'] === 'erledigt' ? 'ok' : ($s['zustand'] === 'entfaellt' ? 'neutral' : 'warn') }}">{{ ['offen' => 'Offen', 'erledigt' => 'Erledigt', 'entfaellt' => 'Entfällt'][$s['zustand']] ?? $s['zustand'] }}</x-fa::badge>
                            </div>
                            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $s['wirkung'] }}</p>
                            @if($s['schritt'] === 'export' && ! empty($s['details']['manifest']))
                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Zählung: @foreach($s['details']['manifest'] as $k => $n){{ $k }} {{ $n }}@if(! $loop->last) · @endif @endforeach</p>
                            @endif
                        </li>
                    @endforeach
                </ol>
                <label class="mt-3 inline-flex items-center gap-2 text-[length:var(--fa-text-md)] cursor-pointer">
                    <input type="checkbox" class="w-4 h-4 rounded accent-[var(--fa-accent)]" wire:model.live="bestaetigt" data-mandant-beenden-bestaetigung />
                    Ich habe die Vorschau gelesen und will „{{ $vorschau['name'] }}“ beenden und sperren.
                </label>
                <div class="flex gap-2 pt-3">
                    <x-fa::button variant="ghost" wire:click="abbrechen">Abbrechen</x-fa::button>
                    <x-fa::button variant="primary" icon="heroicon-o-lock-closed" wire:click="beenden({{ $vorschau['team_id'] }})" :disabled="! $bestaetigt" data-mandant-beenden-ausfuehren>Beenden und sperren</x-fa::button>
                </div>
            </x-fa::section>
        @endif
    @endif
</div>
