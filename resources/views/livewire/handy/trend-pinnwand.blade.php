{{-- #1003 Teil 8 · Pinnwand am Handy (in handy/trends): Fundstücke als Karten mit Bild, Quelle, Notiz — wie am Computer, nur
     ohne Pflege (kein Zuordnen, Zusammenführen, Löschen). „Fundstück ablegen“ ist bedienbar (Ausnahme vom Lesemodus) und
     nutzt GENAU den vorhandenen Weg: fund.* + fundDatei → fundstueckAblegen. Foto: Kamera oder Galerie (accept image/*, kein capture). --}}
@php
    use Platform\FoodAlchemist\Support\TrendVokabular as V;

    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
@endphp
<div class="space-y-3" data-fa-handy-pinnwand>
    @if($meldung)<x-fa::notice tone="ok" data-fa-handy-ablegen-ok>{{ $meldung }}</x-fa::notice>@endif

    @if(! $handyAblegenOffen)
        <x-fa::button variant="primary" icon="heroicon-m-camera" class="w-full min-h-12 justify-center" wire:click="$set('handyAblegenOffen', true)" data-fa-handy-ablegen-oeffnen>Fundstück ablegen</x-fa::button>
    @else
        <section class="space-y-3 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-4" data-fa-handy-ablegen>
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)]">Fundstück ablegen</h2>
                <button type="button" wire:click="$set('handyAblegenOffen', false)" class="min-h-11 px-2 {{ $leise }} underline">Schließen</button>
            </div>
            @if($fehler)<x-fa::notice tone="crit" data-fa-handy-ablegen-fehler>{{ $fehler }}</x-fa::notice>@endif
            @error('fundDatei')<x-fa::notice tone="crit">{{ $message }}</x-fa::notice>@enderror

            <label class="flex flex-col gap-1.5">
                <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">Foto (Kamera oder Galerie)</span>
                <input type="file" wire:model="fundDatei" accept="image/*" class="block w-full min-h-11 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]" data-fa-handy-ablegen-foto>
                <span wire:loading wire:target="fundDatei" class="{{ $leise }}">lädt hoch …</span>
            </label>
            @if($eigeneInspirationen !== [])
                <label class="flex flex-col gap-1.5">
                    <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">Zu einer Inspiration</span>
                    <select wire:model="fund.inspiration_id" class="fa-select h-11">
                        <option value="">Neue Inspiration</option>
                        @foreach($eigeneInspirationen as $iid => $ititel)<option value="{{ $iid }}">{{ $ititel }}</option>@endforeach
                    </select>
                </label>
            @endif
            <input type="text" wire:model="fund.titel" placeholder="Titel (optional)" aria-label="Titel" class="fa-control h-11" data-fa-handy-ablegen-titel>
            <input type="url" inputmode="url" wire:model="fund.url" placeholder="Link, z. B. https://www.instagram.com/p/…" aria-label="Link" class="fa-control h-11" data-fa-handy-ablegen-link>
            <textarea wire:model="fund.notiz" rows="3" placeholder="Notiz: Was fällt auf? Für welchen Anlass?" aria-label="Notiz" class="fa-control" data-fa-handy-ablegen-notiz></textarea>
            <div class="flex gap-2">
                <select wire:model="fund.quelle" aria-label="Quelle" class="fa-select h-11 flex-1 min-w-0">
                    @foreach(V::QUELLEN as $qk => $ql)<option value="{{ $qk }}">{{ $ql }}</option>@endforeach
                </select>
                <input type="text" wire:model="fund.schlagworte" placeholder="Schlagworte" aria-label="Schlagworte" class="fa-control h-11 flex-1 min-w-0">
            </div>
            <x-fa::button variant="primary" icon="heroicon-m-check" class="w-full min-h-12 justify-center" wire:click="fundstueckAblegen" wire:loading.attr="disabled" data-fa-handy-ablegen-speichern>Ablegen</x-fa::button>
        </section>
    @endif

    <div class="flex flex-wrap gap-1.5">
        @foreach(['offen' => 'Offen', 'zugeordnet' => 'Zugeordnet', 'alle' => 'Alle'] as $wert => $label)
            <button type="button" wire:click="$set('pinnAnsicht', @js($wert))" wire:key="hpa-{{ $wert }}"
                    class="inline-flex items-center gap-1 min-h-11 px-3.5 rounded-full border text-[length:var(--fa-text-md)] font-medium {{ $pinnAnsicht === $wert ? 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)]' : 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)]' }}">{{ $label }}</button>
        @endforeach
        @if($pinnSchlagwort !== '')
            <button type="button" class="inline-flex items-center gap-1 min-h-11 px-3.5 rounded-full border text-[length:var(--fa-text-md)] font-medium bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)]" wire:click="$set('pinnSchlagwort', '')" aria-label="Filter #{{ $pinnSchlagwort }} entfernen">#{{ $pinnSchlagwort }} @svg('heroicon-m-x-mark', 'w-4 h-4')</button>
        @endif
    </div>

    <div class="flex flex-col gap-3">
        @forelse($inspirationen as $eintrag)
            @php $insp = $eintrag['i']; @endphp
            <article class="fa-surface overflow-hidden flex flex-col min-w-0" wire:key="hins-{{ $insp->id }}" data-fa-handy-inspiration="{{ $insp->id }}">
                @if($eintrag['bild'])
                    <a href="{{ $eintrag['bild'] }}" target="_blank" rel="noopener" class="block bg-[var(--fa-neutral-soft)]">
                        <img src="{{ $eintrag['bild'] }}" alt="{{ $insp->titel }}" class="w-full h-52 object-cover" loading="lazy" />
                    </a>
                @endif
                <div class="p-3 flex flex-col gap-2 min-w-0">
                    <p class="font-medium text-[var(--fa-ink)] wrap-anywhere">{{ $insp->titel }}</p>
                    @if($insp->schlagworte)
                        <span class="flex flex-wrap gap-x-2">
                            @foreach($insp->schlagworte as $w)
                                <button type="button" class="min-h-11 {{ $leise }} underline" wire:click="$set('pinnSchlagwort', @js(mb_strtolower($w)))">#{{ $w }}</button>
                            @endforeach
                        </span>
                    @endif
                    <ul class="flex flex-col gap-2 border-t border-[var(--fa-line)] pt-2">
                        @foreach($eintrag['quellen'] as $q)
                            @php $f = $q['b']; @endphp
                            <li class="min-w-0" wire:key="hiq-{{ $f->id }}">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <x-fa::badge>{{ V::QUELLEN[$f->quelle] ?? $f->quelle }}</x-fa::badge>
                                    @if($f->beobachtet_am)<span class="{{ $leise }}">{{ $f->beobachtet_am->format('d.m.Y') }}</span>@endif
                                </div>
                                @if($f->titel && $f->titel !== $insp->titel)<p class="mt-0.5 text-[var(--fa-ink)] wrap-anywhere">{{ $f->titel }}</p>@endif
                                @if($f->notiz)<p class="mt-0.5 {{ $leise }} wrap-anywhere">{{ $f->notiz }}</p>@endif
                                <p class="flex flex-wrap gap-x-4 {{ $leise }}">
                                    @if($f->fundort)<span class="py-1">Gesehen: {{ $f->fundort }}</span>@endif
                                    @if($f->url)<a href="{{ $f->url }}" target="_blank" rel="noopener" class="inline-flex items-center min-h-11 text-[var(--fa-accent)] underline">Link öffnen</a>@endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                    @if($insp->trend)
                        <p class="text-[length:var(--fa-text-sm)]">Gehört zu <button type="button" class="min-h-11 text-[var(--fa-accent)] underline" wire:click="select({{ $insp->trend->id }})">{{ $insp->trend->name }}</button></p>
                    @endif
                </div>
            </article>
        @empty
            <x-fa::empty compact icon="heroicon-o-light-bulb" title="Die Pinnwand ist leer">Mit „Fundstück ablegen“ ein Foto oder einen Link sammeln.</x-fa::empty>
        @endforelse
    </div>
</div>
