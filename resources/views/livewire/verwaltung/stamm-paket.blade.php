{{-- Spec 82 §13 B1 — Stamm-Paket buchen / abbestellen (Plattform-Admin). Eingebettet in /verwaltung → Freigaben. --}}
<div class="flex flex-col gap-3" data-fa-stamm-paket>
    @if(! $admin)
        <x-fa::notice tone="info">Das Stamm-Paket buchen oder abbestellen dürfen nur Plattform-Admins.</x-fa::notice>
    @else
        @if($fehler)<x-fa::notice tone="crit" data-stamm-paket-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($meldung)<x-fa::notice tone="ok" data-stamm-paket-meldung>{{ $meldung }}</x-fa::notice>@endif

        <x-fa::section variant="plain">
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[65ch]">Grundprodukte, Lieferanten, Artikel und ihre Verknüpfung, ohne Preise. Das Paket gehört dem Mandanten (Haupt-Team), Unter-Teams erben es. Das Master-Team hat es immer. Ein Abo setzt ein gebuchtes Paket voraus.</p>
            @if($mandanten === [])
                <x-fa::empty compact icon="heroicon-o-archive-box" title="Keine Mandanten">Es gibt noch kein Haupt-Team neben dem Master-Team.</x-fa::empty>
            @else
                {{-- schmale Karte im Host: die Tabelle scrollt waagerecht, die Aktionsspalte wird nie abgeschnitten --}}
                <div class="overflow-x-auto -mx-4">
                <table class="fa-table fa-table--compact" data-stamm-paket-tabelle>
                    <thead><tr><th>Mandant</th><th>Stamm-Paket</th><th class="text-right">Abos am Paket</th><th></th></tr></thead>
                    <tbody>
                        @foreach($mandanten as $m)
                            <tr wire:key="sp-{{ $m['team_id'] }}" data-stamm-paket-zeile="{{ $m['team_id'] }}">
                                <td class="font-medium">{{ $m['name'] }}</td>
                                <td class="whitespace-nowrap">
                                    @if($m['gebucht'])<x-fa::badge tone="ok">Gebucht @if($m["seit"]) · seit {{ $m["seit"] }} @endif </x-fa::badge>
                                    @else<x-fa::badge tone="neutral">Nicht gebucht</x-fa::badge>@endif
                                </td>
                                <td class="text-right tabular-nums">{{ $m['abos'] }}</td>
                                <td class="text-right whitespace-nowrap" data-stamm-paket-aktion>
                                    @if($m['gebucht'])
                                        <x-fa::button size="sm" variant="ghost" icon="heroicon-o-truck" wire:click="lieferantenOeffnen({{ $m['team_id'] }})" data-stamm-paket-lieferanten>Lieferanten</x-fa::button>
                                        <x-fa::button size="sm" variant="ghost" icon="heroicon-o-x-circle" wire:click="vorschau({{ $m['team_id'] }})" data-stamm-paket-abbestellen>Abbestellen …</x-fa::button>
                                    @else
                                        <x-fa::button size="sm" variant="primary" icon="heroicon-m-plus" wire:click="buchen({{ $m['team_id'] }})" data-stamm-paket-buchen>Buchen</x-fa::button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </x-fa::section>

        @if($lieferantenPanel)
            @php($ld = $lieferantenPanel['daten'])
            <x-fa::section title="Lieferanten: {{ $lieferantenPanel['team']->name }} ({{ $ld['freigegeben'] }} von {{ $ld['gesamt'] }})" icon="heroicon-o-truck" id="lieferanten" data-stamm-paket-lieferanten-panel>
                @if(! $ld['gebucht'])
                    <x-fa::notice tone="info">Erst das Stamm-Paket buchen, dann Lieferanten freischalten.</x-fa::notice>
                @else
                    <div class="max-w-3xl" data-stamm-paket-lieferanten-wrap>
                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[65ch] pb-3">Nur freigeschaltete Lieferanten (und ihre Artikel, auch künftige) sehen Mandant und Unter-Teams. Neue Lieferanten erscheinen hier automatisch, unfreigeschaltet. Grundprodukte bleiben für alle sichtbar.</p>
                    <div class="flex flex-wrap items-center gap-2 pb-2">
                        <div class="relative flex-1 min-w-[12rem]">
                            <label for="lieferanten-suche" class="sr-only">Lieferant suchen</label>
                            @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                            <x-fa::input id="lieferanten-suche" type="search" size="sm" wire:model.live.debounce.300ms="lieferantenSuche" placeholder="Lieferant suchen" class="pl-8" data-stamm-paket-lieferanten-suche />
                        </div>
                        <x-fa::button size="sm" variant="ghost" wire:click="alleWaehlen" data-stamm-paket-lieferanten-alle>Alle</x-fa::button>
                        <x-fa::button size="sm" variant="ghost" wire:click="keineWaehlen" data-stamm-paket-lieferanten-keine>Keine</x-fa::button>
                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums whitespace-nowrap" data-stamm-paket-lieferanten-zaehler>{{ count($auswahl) }} von {{ $ld['gesamt'] }} gewählt</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 pb-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model.live="nurGewaehlte" class="rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] focus:ring-[var(--fa-accent)]" data-stamm-paket-nur-gewaehlte> Nur gewählte
                        </label>
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model.live="nachArtikel" class="rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] focus:ring-[var(--fa-accent)]" data-stamm-paket-sort-artikel> Meiste Artikel zuerst
                        </label>
                    </div>
                    @if($ld['lieferanten'] === [])
                        <x-fa::empty compact icon="heroicon-o-truck" title="Keine Lieferanten in dieser Ansicht">Suche oder Filter anpassen.</x-fa::empty>
                    @else
                    <ul class="max-h-[24rem] overflow-y-auto grid md:grid-cols-2 gap-x-3 border border-[var(--fa-line)] rounded-[var(--fa-radius-control)] p-1" data-stamm-paket-lieferanten-liste>
                        @foreach($ld['lieferanten'] as $l)
                            <li wire:key="spl-{{ $l['id'] }}">
                                <label class="flex items-center gap-2 px-2 py-1 rounded-[var(--fa-radius-control)] cursor-pointer text-[length:var(--fa-text-sm)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]">
                                    <input type="checkbox" value="{{ $l['id'] }}" wire:model.live="auswahl" class="rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] focus:ring-[var(--fa-accent)]" data-stamm-paket-lieferant="{{ $l['id'] }}">
                                    <span class="truncate">{{ $l['name'] }}</span>
                                    <span class="ml-auto pl-2 text-[var(--fa-ink-3)] tabular-nums whitespace-nowrap">{{ $l['artikel'] }} Artikel</span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                    @endif
                    </div>
                @endif
                <div class="flex gap-2 pt-3">
                    <x-fa::button variant="ghost" wire:click="lieferantenSchliessen">Schließen</x-fa::button>
                    @if($ld['gebucht'])<x-fa::button variant="primary" icon="heroicon-o-check" wire:click="lieferantenSpeichern" data-stamm-paket-lieferanten-speichern>Speichern</x-fa::button>@endif
                </div>
            </x-fa::section>
        @endif

        @if($vorschau)
            <x-fa::section title="Abbestellen: {{ $vorschau['team']->name }}" icon="heroicon-o-exclamation-triangle" data-stamm-paket-vorschau>
                @if($vorschau['daten']['abos'] === [])
                    <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">Keine Abos hängen am Paket. Das Abbestellen entzieht dem Team Grundprodukte, Lieferanten und Artikel aus dem Stamm.</p>
                @else
                    <x-fa::notice tone="warn">Diese {{ count($vorschau['daten']['abos']) }} Abo(s) würden ungültig:</x-fa::notice>
                    <ul class="mt-2 list-disc pl-5 text-[length:var(--fa-text-md)]">
                        @foreach($vorschau['daten']['abos'] as $a)<li>{{ $a['sammlung'] ?? ('Abo #'.$a['id']) }}</li>@endforeach
                    </ul>
                @endif
                <div class="flex gap-2 pt-3">
                    <x-fa::button variant="ghost" wire:click="abbrechen">Abbrechen</x-fa::button>
                    <x-fa::button variant="primary" icon="heroicon-o-x-circle" wire:click="abbestellen({{ $vorschau['team']->id }})" data-stamm-paket-bestaetigen>
                        {{ $vorschau['daten']['abos'] === [] ? 'Abbestellen' : 'Trotzdem abbestellen' }}
                    </x-fa::button>
                </div>
            </x-fa::section>
        @endif
    @endif
</div>
