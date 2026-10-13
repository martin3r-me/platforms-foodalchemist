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
                @if($phase === 'dna' || $phase === 'foodbook')
                    {{-- Phase 3 zeigt oben die Kunden-DNA, Phase 5 das Foodbook-Fenster --}}
                @elseif($canvasNurGruppe !== null)
                    <fieldset @disabled(! $darfSchreiben) class="contents">@include('foodalchemist::livewire.canvas.partials.board')</fieldset>
                @else
                    <x-fa::empty compact icon="heroicon-o-clock" title="{{ $phasen[$phase] }}">Die Felder dieser Phase folgen in den nächsten Schritten.</x-fa::empty>
                @endif
            </div>

            @if($phase === 'paketlogik')
                <x-fa::section title="Gate: Struktur + Musterseite freigeben" data-projekt-gate
                    description="Erst wenn Paketstufen, Anlässe und Musterseite stehen und eine Person des Kunden sie freigibt, entsteht das Foodbook.">
                    @if($p->strukturFreigegeben())
                        <div class="flex flex-wrap items-center gap-2">
                            <x-fa::badge tone="ok" icon="heroicon-m-check-circle" data-projekt-gate-frei>Freigegeben von {{ $p->struktur_freigegeben_von_name }} am {{ $p->struktur_freigegeben_at->format('d.m.Y H:i') }}</x-fa::badge>
                            @if($darfSchreiben)<x-fa::button size="sm" variant="ghost" wire:click="strukturZuruecknehmen" wire:confirm="Freigabe zurücknehmen? Neue Foodbooks entstehen erst nach neuer Freigabe." data-projekt-gate-zurueck>Zurücknehmen</x-fa::button>@endif
                        </div>
                    @else
                        @if($gateFehlt !== [])
                            <x-fa::notice tone="warn" data-projekt-gate-fehlt>Es fehlt noch: {{ implode(', ', $gateFehlt) }}.</x-fa::notice>
                        @endif
                        @if($darfSchreiben && $gateFehlt === [])
                            <div class="flex flex-col gap-2 max-w-md">
                                <x-fa::field label="Wer gibt frei? (CRM-Kontakt suchen)" for="gate-kontakt">
                                    <x-fa::input id="gate-kontakt" type="search" wire:model.live.debounce.300ms="gateKontaktSuche" placeholder="Name oder E-Mail" data-projekt-gate-suche />
                                </x-fa::field>
                                @foreach($gateKontakte as $k)
                                    <button type="button" wire:key="gk-{{ $k->id }}" wire:click="strukturFreigeben({{ $k->id }})" wire:confirm="Struktur und Musterseite durch {{ $k->full_name }} freigeben?"
                                            class="text-left px-3 py-2 rounded border border-[var(--fa-line)] hover:bg-[var(--fa-hover)]" data-projekt-gate-kontakt="{{ $k->id }}">{{ $k->full_name }}</button>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </x-fa::section>
            @endif

            @if($phase === 'foodbook')
                <x-fa::section title="Foodbook" description="Das Foodbook entsteht im Master-Team und wird aus der Akte vorbefüllt. Der Kunde bekommt es über die öffentliche Präsentation." data-projekt-foodbook>
                    @if(! $p->strukturFreigegeben())
                        <x-fa::notice tone="warn" data-projekt-foodbook-gate>Gate: Erst in der Phase Paketlogik Struktur und Musterseite freigeben.</x-fa::notice>
                    @elseif($darfSchreiben)
                        <div><x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="foodbookAnlegen" data-projekt-foodbook-anlegen>{{ $foodbooks->isEmpty() ? 'Foodbook anlegen' : 'Weiteres Foodbook anlegen' }}</x-fa::button></div>
                    @endif
                    @foreach($foodbooks as $fb)
                        <div wire:key="fb-{{ $fb->id }}" class="py-1" data-projekt-foodbook-zeile="{{ $fb->id }}">
                            <a href="{{ route('foodalchemist.foodbooks.index', ['fb' => $fb->id]) }}" wire:navigate class="hover:underline font-medium">{{ $fb->label }}</a>
                        </div>
                    @endforeach
                </x-fa::section>
            @endif

            @if($phase === 'uebergabe')
                <x-fa::section title="Lizenz erteilt" data-projekt-lizenz
                    description="Wenn der Kunde die Lizenz hat, wird die Akte in seinen Mandanten kopiert: je Kunden-Standort ein Unter-Team mit dem Profil, die Kunden-DNA wird Team-DNA, „Bleibt“ wird verbindlich. Die Akte und das Foodbook bleiben bei uns.">
                    @if($mandantTeam)
                        <x-fa::badge tone="ok" icon="heroicon-m-check-circle" data-projekt-lizenz-fertig>Übernommen in {{ $mandantTeam->name }}</x-fa::badge>
                    @elseif(! $istPlattformAdmin)
                        <p class="text-[var(--fa-ink-3)]" data-projekt-lizenz-nur-admin>Die Übernahme führt ein Plattform-Admin aus.</p>
                    @else
                        @if($oberteam === null)
                            <div class="flex flex-col gap-2 max-w-md">
                                <x-fa::field label="Oberteam des Mandanten suchen (das Team des Kunden auf der Plattform)" for="lz-team">
                                    <x-fa::input id="lz-team" type="search" wire:model.live.debounce.300ms="oberteamSuche" placeholder="Teamname" data-projekt-lizenz-suche />
                                </x-fa::field>
                                @foreach($oberteamTreffer as $t)
                                    <button type="button" wire:key="ot-{{ $t->id }}" wire:click="oberteamWaehlen({{ $t->id }})" class="text-left px-3 py-2 rounded border border-[var(--fa-line)] hover:bg-[var(--fa-hover)]" data-projekt-lizenz-team="{{ $t->id }}">{{ $t->name }}</button>
                                @endforeach
                            </div>
                        @else
                            <div class="flex flex-wrap items-center gap-2"><x-fa::badge tone="accent" icon="heroicon-m-building-office-2">{{ $oberteam->name }}</x-fa::badge><x-fa::button size="sm" variant="ghost" wire:click="oberteamLoesen">Anderes Team</x-fa::button></div>
                            @if($vorschau)
                                <ul class="mt-2 text-[length:var(--fa-text-sm)] flex flex-col gap-1" data-projekt-lizenz-vorschau>
                                    <li>Unter-Teams: {{ $vorschau['unter_teams'] === [] ? 'keine (kein Kunden-Standort)' : implode(', ', $vorschau['unter_teams']) }}</li>
                                    <li>Kunden-DNA wird Team-DNA: {{ count($vorschau['dna_felder']) }} Felder, davon {{ $vorschau['dna_bleibt'] }} „Bleibt“ (verbindlich)</li>
                                    @if($vorschau['dna_uebersprungen'] !== [])<li>Schon gefüllt, bleibt unberührt: {{ implode(', ', $vorschau['dna_uebersprungen']) }}</li>@endif
                                    <li data-projekt-lizenz-kontingent>Kontingent Standorte: {{ $vorschau['kontingent']['max_standorte'] === null ? 'unbegrenzt' : $vorschau['kontingent']['genutzt'].' von '.$vorschau['kontingent']['max_standorte'].' belegt, '.$vorschau['kontingent']['neu'].' kämen dazu' }}</li>
                                    <li>Mandant: aktiv, kuratiert, CRM-Firma verknüpft</li>
                                </ul>
                                @foreach($vorschau['hindernisse'] as $h)<x-fa::notice tone="crit" class="mt-2" data-projekt-lizenz-hindernis>{{ $h }}</x-fa::notice>@endforeach
                                @if($vorschau['hindernisse'] === [])
                                    <div class="mt-3"><x-fa::button variant="primary" wire:click="lizenzUebernehmen" wire:confirm="Lizenz erteilen und die Akte in den Mandanten übernehmen? Das lässt sich nicht zurücknehmen." data-projekt-lizenz-uebernehmen>Lizenz erteilt: übernehmen</x-fa::button></div>
                                @endif
                            @endif
                        @endif
                    @endif
                </x-fa::section>
            @endif

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
            <x-fa::section title="Was die KI daraus liest" data-projekt-ki>
                <p class="text-[length:var(--fa-text-xs)] text-[var(--fa-ink-3)] mb-2">Reihenfolge: Team → Standort → Kunde → Projekt. „Bleibt“ des Kunden steht vorn und gilt bei Widerspruch vor dem Team.</p>
                <ul class="text-[length:var(--fa-text-sm)] flex flex-col gap-1">
                    @forelse($ki['quellen'] as $q)
                        <li wire:key="ki-{{ $q['ebene'] }}" class="flex items-center gap-2" data-projekt-ki-ebene="{{ $q['ebene'] }}">
                            <span class="flex-1">{{ $q['label'] }}</span>
                            @if($q['geschuetzt'])<x-fa::badge tone="accent" icon="heroicon-m-lock-closed">nie gekürzt</x-fa::badge>@endif
                            <span class="text-[var(--fa-ink-3)]">{{ number_format($q['zeichen'], 0, ',', '.') }} Z.</span>
                        </li>
                    @empty
                        <li class="text-[var(--fa-ink-3)]">Noch nichts, was die KI lesen könnte.</li>
                    @endforelse
                </ul>
                @if(! empty($ki['bilanz']['gekuerzt']) || ! empty($ki['bilanz']['verworfen']))
                    <x-fa::notice tone="warn" class="mt-2" data-projekt-ki-gekuerzt>Wegen des Budgets gekürzt: {{ implode(', ', array_merge(array_keys($ki['bilanz']['gekuerzt']), array_keys($ki['bilanz']['verworfen']))) }}.</x-fa::notice>
                @endif
            </x-fa::section>
        </aside>
    </div>
</div>
