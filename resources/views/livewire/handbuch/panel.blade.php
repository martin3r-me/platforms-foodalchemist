@php $hinweisKlasse = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]'; @endphp
<div class="flex flex-col gap-3 pt-3 border-t border-[var(--fa-line)]" data-handbuch-panel>
    <div>
        <h4 class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">Handbuch (Standards zum Nachschlagen)</h4>
        <p class="{{ $hinweisKlasse }}">Eigener Link mit Zubereitung, Anrichten, Regeneration, Bestandteilen und Allergenen je Gericht, für Tablet und Handy. Eingefroren, ohne Preise. Der Kundenlink bleibt davon unberührt.</p>
    </div>

    @if($fehler)<x-fa::signal tone="warn">{{ $fehler }}</x-fa::signal>@endif
    @if($hinweis)<x-fa::signal tone="ok">{{ $hinweis }}</x-fa::signal>@endif

    <div class="flex flex-wrap items-end gap-3">
        <x-fa::field label="Gültig bis" for="hb-gueltig-{{ $type }}-{{ $quelleId }}">
            <x-fa::input type="date" id="hb-gueltig-{{ $type }}-{{ $quelleId }}" wire:model="gueltigBis" />
        </x-fa::field>
        @if($status['live'] ?? false)
            <x-fa::button variant="danger" wire:click="zurueckziehen" wire:confirm="Handbuch zurückziehen? Der Link ist dann nicht mehr erreichbar." data-handbuch-withdraw>Zurückziehen</x-fa::button>
        @endif
        <x-fa::button variant="primary" icon="heroicon-m-book-open" wire:click="veroeffentlichen"
            wire:confirm="Diesen Stand als Handbuch veröffentlichen? Der Stand wird eingefroren." data-handbuch-publish :disabled="! $gueltigBis">
            {{ ($status['veroeffentlicht'] ?? false) ? 'Neu veröffentlichen' : 'Veröffentlichen' }}
        </x-fa::button>
    </div>
    @unless($gueltigBis)
        <x-fa::signal tone="warn">Zum Veröffentlichen ein Datum bei „Gültig bis“ setzen.</x-fa::signal>
    @endunless

    @if($status['url'] ?? null)
        <div class="flex flex-col gap-1.5 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-ground)] p-3" x-data>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex-1 min-w-[12rem] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-2.5 py-1.5 font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink)] break-all select-all" data-handbuch-link>{{ $status['url'] }}</div>
                <x-fa::button size="sm" icon="heroicon-m-link" href="#" x-on:click.prevent="navigator.clipboard.writeText('{{ $status['url'] }}'); $el.textContent='Kopiert'">Link kopieren</x-fa::button>
            </div>
            <p class="{{ $hinweisKlasse }} flex flex-wrap items-center gap-x-2 gap-y-1">
                <span>Gültig bis {{ \Illuminate\Support\Carbon::parse($status['expires_at'])->format('d.m.Y') }}</span>
                @if($status['live'])<x-fa::badge tone="ok">Erreichbar</x-fa::badge>@else<x-fa::badge tone="warn">Nicht erreichbar oder abgelaufen</x-fa::badge>@endif
            </p>
        </div>
    @endif

    <div class="flex flex-col gap-1" data-handbuch-luecken>
        <div>
            <x-fa::button size="sm" variant="ghost" wire:click="pruefen" data-handbuch-pruefen>Lücken prüfen</x-fa::button>
        </div>
        @if($luecken !== null && $luecken === [])
            <p class="{{ $hinweisKlasse }}">Alle Gerichte haben Zubereitung, Anrichten und Regeneration.</p>
        @elseif(! empty($luecken))
            <p class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Lücken im aktuellen Stand ({{ count($luecken) }} Gerichte)</p>
            <p class="{{ $hinweisKlasse }}">Diese Abschnitte erscheinen im Handbuch nicht, weil sie am Gericht nicht gepflegt sind.</p>
            <ul class="{{ $hinweisKlasse }} list-disc pl-5">
                @foreach($luecken as $l)
                    <li>{{ $l['gericht'] }}: {{ implode(', ', $l['fehlt']) }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
