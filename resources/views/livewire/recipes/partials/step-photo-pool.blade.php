{{--
    Spec 27 Phase 2 — Media-Pool eines Rezepts (es gab vorher keinen im FA-UI).
    Klick auf ein Foto verlinkt/löst es am Schritt $stepId (M:N — dasselbe Foto darf
    an mehreren Schritten hängen, keine Nummer wird getippt). $stepId = 0 heißt
    „Pool ohne Schritt-Bezug" (nur Upload/Löschen allgemeiner Rezept-Fotos).

    Erwartet (via @include): $stepId, $pool (Collection), $verlinkteIds (list<int>).
    fa-pass: nur Tokens (hell + Werkbank-Modus), keine Klassen aus einem Eltern-style-Block.
--}}
@php
    $vorschau = 'w-14 h-10 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]';
    $rundKnopf = 'absolute w-5 h-5 items-center justify-center rounded-full';
@endphp

<div class="mt-1 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] border border-[var(--fa-line)] px-3 py-2.5 flex flex-col gap-2.5" wire:key="pool-{{ $stepId }}" data-foto-pool>
    <div class="flex items-center gap-2">
        <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">
            {{ $stepId === 0 ? 'Rezept-Fotos' : 'Foto für diesen Schritt wählen' }}
        </p>
        <x-fa::button size="sm" variant="ghost" class="ml-auto" wire:click="poolOeffnen({{ $stepId }})">Schließen</x-fa::button>
    </div>

    @if($pool->isEmpty())
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Noch keine Fotos. Unten hochladen.</p>
    @else
        @if($stepId !== 0)
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Klick auf ein Foto hängt es an diesen Schritt oder löst es wieder.</p>
        @endif
        <div class="flex flex-wrap gap-2.5">
            @foreach($pool as $foto)
                @php $istVerlinkt = in_array($foto->id, $verlinkteIds, true); @endphp
                <span class="relative group" wire:key="poolf-{{ $stepId }}-{{ $foto->id }}">
                    @if($stepId === 0)
                        <img src="{{ $foto->url() }}" alt="{{ $foto->caption ?? '' }}" title="{{ $foto->caption ?? '' }}"
                             class="{{ $vorschau }}" loading="lazy" />
                    @else
                        <button type="button" wire:click="fotoUmschalten({{ $stepId }}, {{ $foto->id }})"
                                title="{{ $istVerlinkt ? 'Vom Schritt lösen' : 'An diesen Schritt hängen' }}{{ $foto->caption ? ': ' . $foto->caption : '' }}"
                                class="block rounded-[var(--fa-radius-control)]" data-foto-umschalten>
                            <img src="{{ $foto->url() }}" alt="{{ $foto->caption ?? '' }}"
                                 class="{{ $vorschau }} {{ $istVerlinkt ? 'ring-2 ring-[var(--fa-accent)] ring-offset-1 ring-offset-[var(--fa-ground)]' : '' }}" loading="lazy" />
                        </button>
                        @if($istVerlinkt)
                            <span class="{{ $rundKnopf }} flex -top-2 -left-2 bg-[var(--fa-accent)] text-[var(--fa-on-accent)]" title="Hängt an diesem Schritt">@svg('heroicon-m-check', 'w-3.5 h-3.5')</span>
                        @endif
                    @endif
                    <button type="button" wire:click="fotoLoeschen({{ $foto->id }})" wire:confirm="Foto endgültig löschen (aus allen Schritten)?"
                            class="{{ $rundKnopf }} hidden group-hover:flex focus-visible:flex -top-2 -right-2 bg-[var(--fa-crit)] text-[var(--fa-surface)]"
                            title="Foto endgültig löschen" aria-label="Foto endgültig löschen" data-foto-loeschen>@svg('heroicon-m-trash', 'w-3 h-3')</button>
                    {{-- Endprodukt-Bild: „so soll es fertig aussehen" (max. 1 je Rezept) --}}
                    <button type="button" wire:click="endproduktUmschalten({{ $foto->id }})"
                            title="{{ $foto->is_result ? 'Ist das Bild vom fertigen Produkt, Klick hebt das auf' : 'Als Bild vom fertigen Produkt markieren' }}"
                            aria-label="{{ $foto->is_result ? 'Markierung als Endprodukt aufheben' : 'Als Endprodukt markieren' }}"
                            class="{{ $rundKnopf }} {{ $foto->is_result ? 'flex bg-[var(--fa-warn)] text-[var(--fa-surface)]' : 'hidden group-hover:flex focus-visible:flex bg-[var(--fa-ink)] text-[var(--fa-surface)]' }} -bottom-2 -right-2"
                            data-endprodukt-toggle>@svg($foto->is_result ? 'heroicon-s-star' : 'heroicon-o-star', 'w-3 h-3')</button>
                </span>
            @endforeach
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2" data-foto-upload>
        <input type="file" wire:model="fotoUpload" accept="image/*" data-foto-datei aria-label="Foto auswählen"
               class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] file:mr-2 file:h-7 file:px-2.5 file:rounded-[var(--fa-radius-control)] file:border file:border-[var(--fa-line-strong)] file:bg-[var(--fa-surface)] file:text-[var(--fa-ink)] file:cursor-pointer" />
        <x-fa::input size="sm" wire:model="fotoCaption" placeholder="Bildunterschrift (optional)" aria-label="Bildunterschrift" class="w-56 max-w-full" />
        <x-fa::button size="sm" icon="heroicon-m-arrow-up-tray" wire:click="fotoHochladen" wire:loading.attr="disabled" wire:target="fotoUpload, fotoHochladen" data-foto-hochladen>
            <span wire:loading.remove wire:target="fotoUpload, fotoHochladen">{{ $stepId === 0 ? 'Foto hochladen' : 'Hochladen und zuordnen' }}</span>
            <span wire:loading wire:target="fotoUpload, fotoHochladen">Lädt …</span>
        </x-fa::button>
        @error('fotoUpload')<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]">{{ $message }}</span>@enderror
    </div>
</div>
