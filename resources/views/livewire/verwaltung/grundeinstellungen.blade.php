{{-- Spec 82 §13 I — Paket „Grundeinstellungen" buchen (Plattform-Admin). Eingebettet in /verwaltung → Freigaben, neben dem Stamm-Paket. --}}
<div class="flex flex-col gap-3" data-fa-grundeinstellungen>
    @if(! $admin)
        <x-fa::notice tone="info">Das Paket „Grundeinstellungen“ buchen dürfen nur Plattform-Admins.</x-fa::notice>
    @else
        @if($fehler)<x-fa::notice tone="crit" data-grundeinstellungen-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($meldung)<x-fa::notice tone="ok" data-grundeinstellungen-meldung>{{ $meldung }}</x-fa::notice>@endif

        <x-fa::section title="Paket „Grundeinstellungen“ je Mandant" icon="heroicon-o-clipboard-document-list"
            description="Startbestand für reine Team-Arbeit: Konzept-Kategorien, Zielgruppen, Schreibstile, Rollen, Posten, Speiseplan-Chips, Präsentations-Designs, Trend-Startbestand. Wird als Kopie ins Haupt-Team gelegt, Unter-Teams erben. Danach gehört es dem Team. Mehrfach buchen kopiert nichts doppelt.">
            @if($mandanten === [])
                <x-fa::empty compact icon="heroicon-o-clipboard-document-list" title="Keine Mandanten">Es gibt noch kein Haupt-Team neben dem Master-Team.</x-fa::empty>
            @else
                <div class="overflow-x-auto -mx-4">
                <table class="fa-table fa-table--compact" data-grundeinstellungen-tabelle>
                    <thead><tr><th>Mandant</th><th>Paket</th><th></th></tr></thead>
                    <tbody>
                        @foreach($mandanten as $m)
                            <tr wire:key="ge-{{ $m['team_id'] }}" data-grundeinstellungen-zeile="{{ $m['team_id'] }}">
                                <td class="font-medium">{{ $m['name'] }}</td>
                                <td class="whitespace-nowrap">
                                    @if($m['gebucht'])<x-fa::badge tone="ok">Gebucht @if($m['seit']) · seit {{ $m['seit'] }} @endif</x-fa::badge>
                                    @else<x-fa::badge tone="neutral">Nicht gebucht</x-fa::badge>@endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <x-fa::button size="sm" variant="{{ $m['gebucht'] ? 'ghost' : 'primary' }}" icon="heroicon-m-eye" wire:click="vorschau({{ $m['team_id'] }})" data-grundeinstellungen-vorschau>
                                        {{ $m['gebucht'] ? 'Nachziehen …' : 'Vorschau & Buchen …' }}
                                    </x-fa::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </x-fa::section>

        @if($vorschau)
            <x-fa::section title="Vorschau: {{ $vorschau['team']->name }}" icon="heroicon-o-clipboard-document-check" data-grundeinstellungen-vorschau-box>
                @if($vorschau['daten']['quelle_team_id'] === null)
                    <x-fa::notice tone="warn">Es ist kein Master-Team festgelegt — ohne Master gibt es keine Vorlage zum Kopieren.</x-fa::notice>
                @else
                    <table class="fa-table fa-table--compact">
                        <thead><tr><th>Bereich</th><th class="text-right">Vorlagen</th><th class="text-right">schon zugeordnet</th><th class="text-right">neu</th></tr></thead>
                        <tbody>
                            @foreach($vorschau['daten']['tabellen'] as $z)
                                <tr><td>{{ $z['label'] }}</td><td class="text-right tabular-nums">{{ $z['quelle'] }}</td><td class="text-right tabular-nums">{{ $z['bereits'] }}</td><td class="text-right tabular-nums">{{ $z['neu'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="pt-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Gleichnamige Zeilen des Teams werden nicht überschrieben. Rollen kommen ohne Stundensätze.</p>
                @endif
                <div class="flex gap-2 pt-3">
                    <x-fa::button variant="ghost" wire:click="abbrechen">Abbrechen</x-fa::button>
                    @if($vorschau['daten']['quelle_team_id'] !== null)
                        <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="buchen({{ $vorschau['team']->id }})" data-grundeinstellungen-buchen>Buchen</x-fa::button>
                    @endif
                </div>
            </x-fa::section>
        @endif
    @endif
</div>
