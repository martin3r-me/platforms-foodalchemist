{{-- Lieferanten-Link (Stufe 2a): persönlicher Zugang ohne Login — erzeugen, kopieren (einmalig), widerrufen, Protokoll. Nur Haupt-Team + Kuratieren. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $ton = ['aktiv' => 'ok', 'abgelaufen' => 'warn', 'widerrufen' => 'neutral'];
    $text = ['aktiv' => 'aktiv', 'abgelaufen' => 'abgelaufen', 'widerrufen' => 'widerrufen'];
    $artText = ['erzeugt' => 'Link erzeugt', 'geoeffnet' => 'Erstmals geöffnet', 'upload' => 'Upload', 'abgelehnt' => 'Abgelehnt', 'widerrufen' => 'Widerrufen'];
@endphp
<div data-lieferanten-link>
    @if($erlaubt)
        <x-fa::section title="Lieferanten-Link" icon="heroicon-o-link" :meta="$links->where('revoked_at', null)->count() ?: null"
            description="Persönlicher Link ohne Login: Der Lieferant kann darüber Preislisten, Stammdaten und Datenblätter einreichen. Alles landet in der Prüfliste, nie direkt im Bestand.">
            @if($fehler)<x-fa::notice tone="crit" data-lieferanten-link-fehler>{{ $fehler }}</x-fa::notice>@endif

            @if($neueUrl)
                <div class="mb-3 rounded-[var(--fa-radius-control)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] p-3" data-lieferanten-link-neu x-data="{ kopiert: false }">
                    <p class="font-medium text-[var(--fa-ink)]">Link erzeugt — er wird nur jetzt angezeigt.</p>
                    <div class="mt-2 flex flex-col gap-2 md:flex-row md:items-center">
                        <input type="text" readonly value="{{ $neueUrl }}" class="fa-control h-9 w-full text-[length:var(--fa-text-md)]" x-ref="url" data-lieferanten-link-url />
                        <x-fa::button size="sm" variant="primary" icon="heroicon-o-clipboard" x-on:click="navigator.clipboard.writeText($refs.url.value); kopiert = true" data-lieferanten-link-kopieren>Kopieren</x-fa::button>
                    </div>
                    <p class="{{ $leise }} pt-1" x-show="kopiert" x-cloak>Kopiert.</p>
                    <p class="{{ $leise }} pt-1">Gib den Link nur an die Person weiter, für die er gedacht ist. Du kannst ihn jederzeit widerrufen.</p>
                </div>
            @endif

            <div class="grid gap-3 grid-cols-1 md:grid-cols-2" data-lieferanten-link-form>
                <x-fa::field label="Kontakt (Name, optional)" for="ll-name"><x-fa::input id="ll-name" wire:model="kontaktName" /></x-fa::field>
                <x-fa::field label="Kontakt (E-Mail, optional)" for="ll-mail"><x-fa::input id="ll-mail" wire:model="kontaktEmail" /></x-fa::field>
                <x-fa::field label="Gültig (Tage, höchstens {{ $maxTage }})" for="ll-tage"><x-fa::input id="ll-tage" numeric wire:model="tage" data-lieferanten-link-tage /></x-fa::field>
                <div>
                    <p class="{{ $leise }} mb-1">Freigegebene Bereiche</p>
                    @foreach($alleBereiche as $key => $label)
                        <label class="flex items-center gap-2 text-[length:var(--fa-text-md)]"><input type="checkbox" value="{{ $key }}" wire:model="bereiche" /> {{ $label }}</label>
                    @endforeach
                </div>
            </div>
            <div class="pt-3"><x-fa::button variant="primary" icon="heroicon-o-plus" wire:click="erzeugen" wire:loading.attr="disabled" data-lieferanten-link-erzeugen>Link erzeugen</x-fa::button></div>

            @if($links->isNotEmpty())
                <div class="mt-4 flex flex-col gap-2" data-lieferanten-link-liste>
                    @foreach($links as $l)
                        @php $z = $l->zustand(); @endphp
                        <div wire:key="ll-{{ $l->id }}" class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] p-3" data-lieferanten-link-zeile="{{ $l->id }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-fa::badge :tone="$ton[$z]">{{ $text[$z] }}</x-fa::badge>
                                <span class="font-medium text-[var(--fa-ink)]">{{ $l->kontakt_name ?: 'Link ' . $l->token_hinweis . '…' }}</span>
                                <span class="{{ $leise }}">gültig bis {{ $l->expires_at->timezone('Europe/Berlin')->format('d.m.Y') }} · {{ $l->upload_count }} Uploads · zuletzt {{ $l->last_used_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') ?? 'nie' }}</span>
                            </div>
                            <div class="flex flex-wrap gap-2 pt-2">
                                <x-fa::button size="sm" variant="ghost" wire:click="protokoll({{ $l->id }})" data-lieferanten-link-protokoll>Protokoll</x-fa::button>
                                @if($z !== 'widerrufen')
                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-o-no-symbol" wire:click="widerrufen({{ $l->id }})" wire:confirm="Link widerrufen? Der Lieferant kommt damit sofort nicht mehr hinein." data-lieferanten-link-widerrufen>Widerrufen</x-fa::button>
                                @endif
                            </div>
                            @if($protokollLinkId === $l->id)
                                <ul class="{{ $leise }} pt-2" data-lieferanten-link-ereignisse>
                                    @forelse($protokoll as $e)<li>{{ $e->created_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ $artText[$e->art] ?? $e->art }}</li>@empty<li>Noch keine Ereignisse.</li>@endforelse
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </x-fa::section>
    @elseif($darf)
        <x-fa::notice tone="info" data-lieferanten-link-nicht-moeglich>Für diesen Lieferanten kann dein Team keinen Link erzeugen (geerbter Katalog-Lieferant). Globale Lieferanten verwaltet das Master-Team.</x-fa::notice>
    @endif
</div>
