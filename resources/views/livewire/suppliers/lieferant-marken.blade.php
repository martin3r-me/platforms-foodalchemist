{{-- GP-Regelwerk v3.7.1 §5 Ausnahme 3: Rolle, Kurzname und Marken-Kürzel des Lieferanten. Pflege nur Plattform-Admin/Master-Admin, sonst lesend. --}}
<div data-lieferant-marken>
    <x-fa::section title="Marke & Rolle" icon="heroicon-o-tag"
        description="Bei Rezepturware steht die Marke im GP-Namen als „/ (…)“: Marke aus dem Artikel, sonst der Kurzname, wenn der Lieferant Hersteller ist. Händlernamen nie.">
        @if($fehler)<x-fa::notice tone="crit" data-marken-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($meldung)<x-fa::notice tone="ok" data-marken-meldung>{{ $meldung }}</x-fa::notice>@endif

        @if($darf)
            <div class="grid grid-cols-2 gap-3" data-marken-rolle-edit>
                <x-fa::field label="Rolle" for="lm-rolle">
                    <x-fa::select id="lm-rolle" wire:model.live="rolle" :options="$rollen" data-lm-rolle />
                </x-fa::field>
                <x-fa::field label="Kurzname für die Klammer" for="lm-kurz" :optional="$rolle !== 'hersteller'">
                    <x-fa::input id="lm-kurz" wire:model="kurzname" :disabled="$rolle !== 'hersteller'" placeholder="z. B. Heisters" data-lm-kurzname />
                </x-fa::field>
                <div class="col-span-2 flex justify-end">
                    <x-fa::button variant="primary" wire:click="rolleSpeichern" data-lm-rolle-speichern>Rolle speichern</x-fa::button>
                </div>
            </div>
        @else
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3" data-marken-rolle-lesen>
                <div><dt class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Rolle</dt>
                    <dd class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $rollen[$rolle] ?? $rolle }}</dd></div>
                <div><dt class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Kurzname</dt>
                    <dd class="text-[length:var(--fa-text-md)] {{ $kurzname !== '' ? 'text-[var(--fa-ink)]' : 'text-[var(--fa-ink-3)]' }}">{{ $kurzname !== '' ? $kurzname : 'nicht hinterlegt' }}</dd></div>
            </dl>
        @endif

        <div class="flex flex-col gap-2 mt-2">
            <h4 class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Marken-Kürzel in Bezeichnungen</h4>
            @forelse($marken as $m)
                <div class="flex items-center gap-3 py-1.5 border-b border-[var(--fa-line)] last:border-b-0" wire:key="lm-marke-{{ $m->id }}" data-lm-marke="{{ $m->kuerzel }}">
                    <span class="w-24 font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $m->kuerzel }}</span>
                    <span class="flex-1 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $m->marke }}</span>
                    <x-fa::badge :tone="$m->typ === 'eigenmarke' ? 'info' : 'neutral'">{{ $m->typ === 'eigenmarke' ? 'Eigenmarke' : 'Herstellermarke' }}</x-fa::badge>
                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $m->position === 'ende' ? 'am Ende' : 'am Anfang' }}@if($m->vorrang > 0) · Vorrang {{ $m->vorrang }}@endif</span>
                    @if($darf)
                        <x-fa::button size="sm" variant="ghost" icon="heroicon-m-trash" wire:click="markeLoeschen(@js($m->kuerzel))"
                            wire:confirm="Kürzel „{{ $m->kuerzel }}“ entfernen?" aria-label="Kürzel {{ $m->kuerzel }} entfernen" data-lm-marke-loeschen />
                    @endif
                </div>
            @empty
                <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-3)]" data-lm-keine-marken>Keine Kürzel für diesen Lieferanten. Globale Kürzel (z. B. Kn. → Knorr) gelten trotzdem.</p>
            @endforelse
            @if($darf)
                <div class="grid grid-cols-[8rem_1fr_10rem_9rem_auto] gap-2 items-end mt-1" data-lm-neu>
                    <x-fa::field label="Kürzel" for="lm-neu-k"><x-fa::input id="lm-neu-k" wire:model="neu.kuerzel" placeholder="BC" data-lm-neu-kuerzel /></x-fa::field>
                    <x-fa::field label="Marke" for="lm-neu-m"><x-fa::input id="lm-neu-m" wire:model="neu.marke" placeholder="Base Culinar" data-lm-neu-marke /></x-fa::field>
                    <x-fa::field label="Typ" for="lm-neu-t">
                        <x-fa::select id="lm-neu-t" wire:model="neu.typ" :options="['herstellermarke' => 'Herstellermarke', 'eigenmarke' => 'Eigenmarke']" />
                    </x-fa::field>
                    <x-fa::field label="Steht" for="lm-neu-p">
                        <x-fa::select id="lm-neu-p" wire:model="neu.position" :options="['anfang' => 'am Anfang', 'ende' => 'am Ende']" />
                    </x-fa::field>
                    <x-fa::button icon="heroicon-m-plus" wire:click="markeAnlegen" data-lm-neu-anlegen>Hinzufügen</x-fa::button>
                </div>
            @endif
        </div>
    </x-fa::section>
</div>
