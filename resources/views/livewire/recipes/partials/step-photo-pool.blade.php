{{--
    Spec 27 Phase 2 — Media-Pool eines Rezepts (es gab vorher keinen im FA-UI).
    Klick auf ein Foto verlinkt/löst es am Schritt $stepId (M:N — dasselbe Foto darf
    an mehreren Schritten hängen, keine Nummer wird getippt). $stepId = 0 heißt
    „Pool ohne Schritt-Bezug" (nur Upload/Löschen allgemeiner Rezept-Fotos).

    Erwartet (via @include): $stepId, $pool (Collection), $verlinkteIds (list<int>).
--}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="fa-step-pool mt-1.5" wire:key="pool-{{ $stepId }}" data-foto-pool>
    <div class="flex items-center gap-2 mb-1.5">
        <p class="{{ $dt }}">
            {{ $stepId === 0 ? 'Rezept-Fotos' : 'Foto für Schritt ' . $stepId . ' wählen' }}
        </p>
        <button type="button" wire:click="poolOeffnen({{ $stepId }})" class="{{ $btnGhostXs }} ml-auto">schließen</button>
    </div>

    @if($pool->isEmpty())
        <p class="fa-step-hint mb-1.5">Noch keine Fotos — unten hochladen.</p>
    @else
        <div class="flex flex-wrap gap-1.5 mb-2">
            @foreach($pool as $foto)
                @php($istVerlinkt = in_array($foto->id, $verlinkteIds, true))
                <span class="relative group" wire:key="poolf-{{ $stepId }}-{{ $foto->id }}">
                    @if($stepId === 0)
                        <img src="{{ $foto->url() }}" alt="{{ $foto->caption ?? '' }}" title="{{ $foto->caption ?? '' }}"
                             class="fa-step-thumb" loading="lazy" />
                    @else
                        <button type="button" wire:click="fotoUmschalten({{ $stepId }}, {{ $foto->id }})"
                                title="{{ $istVerlinkt ? 'vom Schritt lösen' : 'an diesen Schritt hängen' }}{{ $foto->caption ? ' — ' . $foto->caption : '' }}"
                                class="block" data-foto-umschalten>
                            <img src="{{ $foto->url() }}" alt="{{ $foto->caption ?? '' }}"
                                 class="fa-step-thumb {{ $istVerlinkt ? 'fa-step-pool-on' : '' }}" loading="lazy" />
                        </button>
                        @if($istVerlinkt)
                            <span class="absolute -top-1 -left-1 w-4 h-4 flex items-center justify-center rounded-full bg-violet-600 text-white text-[9px]" title="hängt an diesem Schritt">✓</span>
                        @endif
                    @endif
                    <button type="button" wire:click="fotoLoeschen({{ $foto->id }})" wire:confirm="Foto endgültig löschen (aus allen Schritten)?"
                            class="hidden group-hover:flex absolute -top-1.5 -right-1.5 w-4 h-4 items-center justify-center rounded-full bg-rose-500 text-white text-[9px]"
                            title="Foto endgültig löschen" data-foto-loeschen>✕</button>
                </span>
            @endforeach
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2" data-foto-upload>
        <input type="file" wire:model="fotoUpload" accept="image/*" data-foto-datei
               class="text-[11px] text-gray-600 file:mr-2 file:px-2 file:py-1 file:rounded-lg file:border-0 file:bg-violet-500/10 file:text-violet-600 file:text-[11px] file:cursor-pointer" />
        <input type="text" wire:model="fotoCaption" placeholder="Bildunterschrift (optional)" class="{{ $input }} !py-1 w-56" />
        <button type="button" wire:click="fotoHochladen" wire:loading.attr="disabled" wire:target="fotoUpload, fotoHochladen"
                class="{{ $btnAi }}" data-foto-hochladen>
            <span wire:loading.remove wire:target="fotoUpload, fotoHochladen">Hochladen{{ $stepId === 0 ? '' : ' + verlinken' }}</span>
            <span wire:loading wire:target="fotoUpload, fotoHochladen">lädt …</span>
        </button>
        @error('fotoUpload')<span class="text-[11px] text-rose-500">{{ $message }}</span>@enderror
    </div>
</div>
