{{-- Spec 85 F5 — Preisänderungen seit Veröffentlichung. Der Kunde sieht im Link die beim Veröffentlichen eingefrorenen Preise;
     hier steht, wo die Kalkulation inzwischen davon abweicht. Abhilfe ist neu veröffentlichen, eine eigene Freigabe gibt es nicht. --}}
<div class="flex flex-col gap-4" data-ctrl-link-preise>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[75ch]">
            Ein veröffentlichter Link zeigt die Preise vom Zeitpunkt der Veröffentlichung. Hier stehen die Links, deren Preise
            inzwischen von der Kalkulation abweichen. Neu veröffentlichen mit „Preise aktualisieren“ übernimmt die aktuellen Preise.
            @if($schwelle !== null)
                Gezeigt wird eine Abweichung ab {{ number_format((float) $schwelle, 1, ',', '.') }} %.
            @endif
        </p>
        <x-fa::button variant="ghost" size="sm" icon="heroicon-o-arrow-path" wire:click="jetztPruefen" wire:loading.attr="disabled" data-ctrl-link-preise-pruefen>
            Jetzt prüfen
        </x-fa::button>
    </div>

    @if($hinweis)<x-fa::signal tone="ok" data-ctrl-link-preise-hinweis>{{ $hinweis }}</x-fa::signal>@endif

    @if(count($links) === 0)
        <x-fa::signal tone="ok">Alle veröffentlichten Links tragen aktuelle Preise.</x-fa::signal>
    @else
        @foreach($links as $l)
            <x-fa::section variant="plain" wire:key="link-preise-{{ $l['id'] }}" :title="$l['art'] . ($l['betrieb'] ? ' · ' . $l['betrieb'] : ' · Team-Link')"
                           icon="heroicon-o-link" :meta="$l['anzahl'] . ($l['anzahl'] === 1 ? ' Preis' : ' Preise') . ' · bis ' . number_format($l['max_delta_pct'], 1, ',', '.') . ' %'"
                           :description="$l['titel']" data-ctrl-link-preise-link="{{ $l['id'] }}">
                <div class="overflow-x-auto">
                    <table class="fa-table">
                        <thead>
                            <tr>
                                <th>Position</th>
                                <th class="num">Im Link</th>
                                <th class="num">Aktuell</th>
                                <th class="num">Abweichung</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($l['zeilen'] as $z)
                                <tr>
                                    <td>{{ $z['label'] ?? '–' }}</td>
                                    <td class="num text-[var(--fa-ink-2)]"><x-fa::money :value="$z['frozen'] ?? null" /></td>
                                    <td class="num font-semibold"><x-fa::money :value="$z['live'] ?? null" /></td>
                                    <td class="num">
                                        <x-fa::badge :tone="($z['richtung'] ?? '') === 'erhoehen' ? 'warn' : 'info'"
                                                     :icon="($z['richtung'] ?? '') === 'erhoehen' ? 'heroicon-m-arrow-up' : 'heroicon-m-arrow-down'">
                                            {{ ($z['richtung'] ?? '') === 'erhoehen' ? '+' : '−' }}{{ number_format((float) ($z['delta_pct'] ?? 0), 1, ',', '.') }} %
                                        </x-fa::badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($l['url'])
                    <div class="mt-2">
                        <x-fa::button variant="secondary" size="sm" icon="heroicon-o-arrow-top-right-on-square" :href="$l['url']" data-ctrl-link-preise-editor>
                            Zum Veröffentlichen
                        </x-fa::button>
                    </div>
                @endif
            </x-fa::section>
        @endforeach
    @endif
</div>
