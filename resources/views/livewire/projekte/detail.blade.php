{{-- Spec 82 §9 · Projekt-Detail (Agentur-Akte im Master-Team): Kopf, Phasen-Reiter, Seitenleiste „Verknüpft“. --}}
<div class="flex flex-col gap-4" data-fa-projekt="{{ $p->id }}">
    <nav class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" aria-label="Brotkrumen" data-projekt-brotkrumen>
        <a href="{{ route('foodalchemist.projekte.index') }}" wire:navigate class="hover:underline">Projekte</a>
        <span aria-hidden="true">/</span> <span>{{ $firma?->display_name ?? 'Kunde' }}</span>
        <span aria-hidden="true">/</span> <span class="text-[var(--fa-ink)]">{{ $p->name }}</span>
    </nav>

    @if($fehler)<x-fa::notice tone="crit" data-projekt-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($meldung)<x-fa::notice tone="ok" data-projekt-meldung>{{ $meldung }}</x-fa::notice>@endif

    <x-fa::section title="Projektkopf" data-projekt-kopf>
        <div class="flex flex-wrap items-center gap-2 mb-3">
            <x-fa::badge tone="accent" icon="heroicon-m-building-office-2" data-projekt-firma>{{ $firma?->display_name ?? 'CRM-Firma nicht erreichbar' }}</x-fa::badge>
            <x-fa::badge tone="neutral" data-projekt-phase>Phase: {{ $phasen[$p->phase] ?? $p->phase }}</x-fa::badge>
            @php $pilot = $p->standorte->firstWhere('ist_pilot', true); @endphp
            <x-fa::badge tone="neutral" icon="heroicon-m-map-pin" data-projekt-pilot>Pilot-Standort: {{ $pilot?->name ?? 'noch keiner' }}</x-fa::badge>
            @if(Route::has('crm.companies.show'))
                <x-fa::button size="sm" variant="ghost" icon="heroicon-o-arrow-top-right-on-square" :href="route('crm.companies.show', $p->crm_company_id)" data-projekt-crm-link>Im CRM öffnen</x-fa::button>
            @endif
            @if($p->planner_projekt_id && Route::has('planner.projects.show'))
                <x-fa::button size="sm" variant="ghost" icon="heroicon-o-arrow-top-right-on-square" :href="route('planner.projects.show', $p->planner_projekt_id)" data-projekt-planner-link>Im Planner öffnen</x-fa::button>
            @endif
        </div>
        <fieldset @disabled(! $darfSchreiben) class="contents">
            <div class="grid gap-3 md:grid-cols-2 max-w-3xl">
                <x-fa::field label="Projektname" for="pk-name"><x-fa::input id="pk-name" wire:model="kopf.name" /></x-fa::field>
                <x-fa::field label="Ziel in einem Satz" for="pk-ziel" optional><x-fa::input id="pk-ziel" wire:model="kopf.ziel" /></x-fa::field>
                <x-fa::field label="Status" for="pk-status">
                    <select id="pk-status" wire:model="kopf.status" class="fa-select">
                        @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistProjekt::STATUS as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach
                    </select>
                </x-fa::field>
                <x-fa::field label="Planner-Projekt (Nummer)" for="pk-planner" optional><x-fa::input id="pk-planner" wire:model="kopf.planner_projekt_id" inputmode="numeric" /></x-fa::field>
            </div>
            @if($darfSchreiben)<div class="mt-3"><x-fa::button variant="primary" wire:click="kopfSpeichern" data-projekt-kopf-speichern>Kopf speichern</x-fa::button></div>@endif
        </fieldset>
    </x-fa::section>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
        <div class="flex flex-col gap-4 min-w-0">
            <div class="flex flex-wrap gap-1" role="tablist" data-projekt-phasen>
                @foreach($phasen as $key => $label)
                    <button type="button" role="tab" wire:key="ph-{{ $key }}" wire:click="phaseSetzen('{{ $key }}')" aria-selected="{{ $phase === $key ? 'true' : 'false' }}"
                            class="px-3 py-1.5 rounded-full text-[length:var(--fa-text-sm)] border {{ $phase === $key ? 'bg-[var(--fa-accent)] text-white border-transparent' : 'border-[var(--fa-line)] hover:bg-[var(--fa-hover)]' }}"
                            data-projekt-phase-reiter="{{ $key }}">{{ $loop->iteration }} · {{ $label }}{{ $p->phase === $key ? ' ●' : '' }}</button>
                @endforeach
            </div>

            @if($phase === 'standort')
                <x-fa::section title="Kunden-Standorte" description="Standorte des Kunden in diesem Projekt. Das Profil mit Geräten, Flächen und Gästen parallel pflegst du im Menüpunkt Standortprofil." data-projekt-standorte>
                    @forelse($p->standorte as $s)
                        <div wire:key="st-{{ $s->id }}" class="flex items-center gap-2 py-1" data-projekt-standort="{{ $s->id }}">
                            <span class="font-medium">{{ $s->name }}</span>
                            @if($s->ist_pilot)<x-fa::badge tone="accent">Pilot</x-fa::badge>
                            @elseif($darfSchreiben)<x-fa::button size="sm" variant="ghost" wire:click="pilotSetzen({{ $s->id }})" data-projekt-pilot-setzen="{{ $s->id }}">Als Pilot</x-fa::button>@endif
                            @if($darfSchreiben)<x-fa::button size="sm" variant="ghost" icon="heroicon-o-clipboard-document-list" :href="route('foodalchemist.standortprofil.index', ['standort' => $s->id])" data-projekt-standort-profil="{{ $s->id }}">Profil</x-fa::button>
                            <x-fa::button size="sm" variant="ghost" wire:click="standortLoeschen({{ $s->id }})" wire:confirm="Standort entfernen?">Entfernen</x-fa::button>@endif
                        </div>
                    @empty
                        <p class="text-[var(--fa-ink-3)]">Noch kein Standort. Der erste wird zum Pilot-Standort.</p>
                    @endforelse
                    @if($darfSchreiben)
                        <div class="flex gap-2 mt-2 max-w-md">
                            <x-fa::input wire:model="neuerStandort" placeholder="Name des Standorts" wire:keydown.enter="standortAnlegen" data-projekt-standort-neu />
                            <x-fa::button variant="primary" wire:click="standortAnlegen" data-projekt-standort-hinzu>Hinzufügen</x-fa::button>
                        </div>
                    @endif
                </x-fa::section>
            @endif

            @if($phase === 'dna')
                <livewire:foodalchemist.projekte.kunde-dna-phase :company-id="(int) $p->crm_company_id" :key="'kdna-'.$p->crm_company_id" />
            @endif

            <div data-projekt-board>
                @if($phase === 'dna')
                    {{-- Phase 3 zeigt oben die Kunden-DNA --}}
                @elseif($canvasNurGruppe !== null)
                    <fieldset @disabled(! $darfSchreiben) class="contents">@include('foodalchemist::livewire.canvas.partials.board')</fieldset>
                @else
                    <x-fa::empty compact icon="heroicon-o-clock" title="{{ $phasen[$phase] }}">Die Felder dieser Phase folgen in den nächsten Schritten.</x-fa::empty>
                @endif
            </div>

            @if($darfSchreiben && $p->phase !== $phase)
                <div><x-fa::button size="sm" variant="ghost" wire:click="phaseAlsStand" data-projekt-phase-stand>„{{ $phasen[$phase] }}“ als aktuellen Stand setzen</x-fa::button></div>
            @endif
        </div>

        <aside class="flex flex-col gap-3" data-projekt-seitenleiste>
            <x-fa::section title="Verknüpft">
                <ul class="text-[length:var(--fa-text-sm)] flex flex-col gap-1">
                    <li>CRM-Firma: {{ $firma?->display_name ?? '—' }}</li>
                    <li>Kontakte: {{ $kontakteVerknuepft }} von {{ $kontakteGesamt }} mit dem CRM verknüpft</li>
                    <li>Standorte: {{ $p->standorte->count() }}</li>
                    <li>Planner: {{ $p->planner_projekt_id ? 'Projekt '.$p->planner_projekt_id : 'nicht verknüpft' }}</li>
                </ul>
                <p class="mt-2 text-[length:var(--fa-text-xs)] text-[var(--fa-ink-3)]">Firma, Kontakte und Planner bleiben dort, wo sie geführt werden. Hier steht nur der Verweis.</p>
            </x-fa::section>
        </aside>
    </div>
</div>
