{{-- Spec 82 §9 · Projekte (Agentur-Akten im Master-Team): Liste und Projekt anlegen. --}}
<div class="flex flex-col gap-4" data-fa-projekte>
    <x-fa::page-header title="Projekte" subtitle="Agentur-Akten zu Kunden: Zielbild, Standorte, DNA, Paketlogik, Foodbook, Übergabe. Der Kunde wird erst bei der Lizenz zum Mandanten." />

    @if($darfSchreiben)
        <x-fa::section title="Projekt anlegen" icon="heroicon-o-plus-circle" data-projekt-anlegen-box>
            @if(! $crmVerfuegbar)
                <x-fa::notice tone="warn">Die Kundenverwaltung (CRM) ist nicht erreichbar. Ohne sie lässt sich kein Projekt anlegen.</x-fa::notice>
            @else
                @if($fehler)<x-fa::notice tone="crit" data-projekt-fehler>{{ $fehler }}</x-fa::notice>@endif
                @if($firmaId === null)
                    <div class="flex flex-col gap-2 max-w-md">
                        <x-fa::field label="CRM-Firma suchen" for="projekt-firma">
                            <x-fa::input id="projekt-firma" type="search" wire:model.live.debounce.300ms="firmaSuche" placeholder="Firmenname" data-projekt-firma-suche />
                        </x-fa::field>
                        @foreach($firmen as $f)
                            <button type="button" wire:key="pf-{{ $f->id }}" wire:click="firmaWaehlen({{ $f->id }})" class="text-left px-3 py-2 rounded border border-[var(--fa-line)] hover:bg-[var(--fa-hover)]" data-projekt-firma-treffer="{{ $f->id }}">{{ $f->display_name }}</button>
                        @endforeach
                        @if($firmaSuche !== '' && $firmen->isEmpty())<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Keine Firma gefunden. Die Firma zuerst im CRM anlegen.</p>@endif
                    </div>
                @else
                    <div class="flex flex-wrap items-center gap-2"><x-fa::badge tone="accent" icon="heroicon-m-building-office-2">{{ $firmaName }}</x-fa::badge><x-fa::button size="sm" variant="ghost" wire:click="firmaLoesen">Andere Firma</x-fa::button></div>
                    <div class="grid gap-3 md:grid-cols-2 max-w-3xl">
                        <x-fa::field label="Projektname" for="projekt-name"><x-fa::input id="projekt-name" wire:model="name" data-projekt-name /></x-fa::field>
                        <x-fa::field label="Ziel in einem Satz" for="projekt-ziel" optional><x-fa::input id="projekt-ziel" wire:model="ziel" /></x-fa::field>
                    </div>
                    <div><x-fa::button variant="primary" icon="heroicon-m-check" wire:click="anlegen" data-projekt-anlegen>Projekt anlegen</x-fa::button></div>
                @endif
            @endif
        </x-fa::section>
    @endif

    <x-fa::section title="Alle Projekte" :meta="$projekte->count() > 0 ? $projekte->count().' Projekt(e)' : null">
        @if($projekte->isEmpty())
            <x-fa::empty compact icon="heroicon-o-briefcase" title="Noch kein Projekt">Ein Projekt legst du zu einer Firma aus dem CRM an.</x-fa::empty>
        @else
            <div class="overflow-x-auto -mx-4">
                <table class="fa-table fa-table--compact" data-projekte-tabelle>
                    <thead><tr><th>Projekt</th><th>Kunde (CRM)</th><th>Phase</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach($projekte as $p)
                            <tr wire:key="proj-{{ $p->id }}" data-projekt-zeile="{{ $p->id }}">
                                <td class="font-medium"><a href="{{ route('foodalchemist.projekte.show', $p->id) }}" wire:navigate class="hover:underline">{{ $p->name }}</a></td>
                                <td>{{ $firmenNamen[$p->crm_company_id] ?? '—' }}</td>
                                <td>{{ $phasen[$p->phase] ?? $p->phase }}</td>
                                <td><x-fa::badge tone="{{ $p->status === 'aktiv' ? 'ok' : ($p->status === 'pausiert' ? 'warn' : 'neutral') }}">{{ ucfirst($p->status) }}</x-fa::badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-fa::section>
</div>
