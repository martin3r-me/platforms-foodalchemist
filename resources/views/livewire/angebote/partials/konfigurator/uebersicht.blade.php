{{-- Spec 94 · Konfigurator-Editor, Reiter Übersicht: Grunddaten und die letzten Anfragen aus dem Link. --}}
<x-fa::section title="Grunddaten" icon="heroicon-o-clipboard-document-list">
    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,16rem),1fr))] gap-3">
        <x-fa::field label="Name (intern)" for="kf-name" required><x-fa::input id="kf-name" wire:model="form.name" data-kf-name /></x-fa::field>
        <x-fa::field label="Titel für den Kunden" for="kf-titel"><x-fa::input id="kf-titel" wire:model="form.kunden_titel" placeholder="z. B. Diva.licious" /></x-fa::field>
        <x-fa::field label="Claim" for="kf-claim" optional><x-fa::input id="kf-claim" wire:model="form.claim" /></x-fa::field>
        <x-fa::field label="Veranstaltungsort" for="kf-ort" optional><x-fa::input id="kf-ort" wire:model="form.ort" placeholder="z. B. Köln, Anleger Rheinpark" /></x-fa::field>
        <x-fa::field label="Gültig bis" for="kf-gueltig" optional hint="Läuft der Link danach ab"><x-fa::input id="kf-gueltig" type="date" wire:model="form.gueltig_bis" /></x-fa::field>
        <x-fa::field label="Anfragen gehen an" for="kf-verantw" hint="Bekommt die Mail; Signal im Team">
            <x-fa::select id="kf-verantw" wire:model="form.verantwortlich_user_id" :options="$nutzer" placeholder="Niemand" />
        </x-fa::field>
        <x-fa::field label="Startwert Gäste im Link" for="kf-pax"><x-fa::input id="kf-pax" type="number" min="1" wire:model="form.pax_vorgabe" /></x-fa::field>
        <x-fa::field label="Reihenfolge im Link" for="kf-reihe" hint="Standard: erst Zeitfenster, dann nur passende Konzepte">
            <x-fa::select id="kf-reihe" wire:model="form.reihenfolge" :options="['tageszeit_zuerst' => 'Zeitfenster zuerst', 'konzept_zuerst' => 'Konzept zuerst']" />
        </x-fa::field>
        <x-fa::field label="Status" for="kf-status"><x-fa::select id="kf-status" wire:model="form.status" :options="['entwurf' => 'Entwurf', 'live' => 'Live', 'archiv' => 'Archiv']" /></x-fa::field>
    </div>
    <x-fa::field label="Einleitung für den Kunden" for="kf-intro" optional><x-fa::textarea id="kf-intro" rows="3" wire:model="form.intro" /></x-fa::field>
</x-fa::section>

<x-fa::section title="Anfragen aus dem Link" icon="heroicon-o-inbox-arrow-down" :meta="count($anfragen ?? [])" data-kf-anfragen>
    @forelse($anfragen ?? [] as $a)
        <div wire:key="kfa-{{ $a->id }}" class="flex flex-wrap items-center justify-between gap-2 py-1.5 border-b border-[var(--fa-line)] last:border-0">
            <div>
                <span class="font-medium">{{ $a->name }}</span>
                <span class="{{ $leise }}">· {{ $a->created_at?->format('d.m.Y H:i') }} · {{ $a->personen ?? '–' }} Gäste · {{ $a->status->label() }}</span>
            </div>
            <x-fa::button size="sm" variant="ghost" icon-right="heroicon-m-arrow-right" :href="route('foodalchemist.angebote.index', ['sel' => $a->id])">Angebot öffnen</x-fa::button>
        </div>
    @empty
        <p class="{{ $leise }}">Noch keine Anfragen. Sobald der Link veröffentlicht ist, wird jede Anfrage ein Angebot mit Status Anfrage.</p>
    @endforelse
</x-fa::section>
