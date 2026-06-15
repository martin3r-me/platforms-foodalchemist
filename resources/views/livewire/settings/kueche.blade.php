{{-- M7-07: Küchen-Profil — Soft-Default des Generators (explizite Hooks gewinnen) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-4" data-settings-kueche>
    <div>
        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Küchen-Profil</h3>
        <p class="text-[11px] text-gray-400 mt-0.5">Mandanten-Profil als Soft-Default für den Rezept-Generator (Chargengrößen-, Convenience-, Technik-Tendenz). Explizite Richtungs-Parameter im Generator haben immer Vorrang.</p>
    </div>

    @if($meldung !== null)
        <p class="text-xs text-emerald-600 dark:text-emerald-400" data-kueche-meldung>{{ $meldung }}</p>
    @endif

    <div class="max-w-xl space-y-2" data-kueche-typen>
        <label class="flex items-start gap-2 text-xs text-gray-700 dark:text-gray-200 cursor-pointer">
            <input type="radio" wire:model="kuechenTyp" value="" class="mt-0.5 border-gray-300 text-violet-600 focus:ring-violet-500" />
            <span><span class="font-medium">Kein Profil</span> <span class="text-gray-400">— Generator ohne Mandanten-Tendenz</span></span>
        </label>
        @foreach($typen as $slug => $beschreibung)
            <label class="flex items-start gap-2 text-xs text-gray-700 dark:text-gray-200 cursor-pointer" wire:key="kt-{{ $slug }}">
                <input type="radio" wire:model="kuechenTyp" value="{{ $slug }}" class="mt-0.5 border-gray-300 text-violet-600 focus:ring-violet-500" />
                <span>{{ $beschreibung }}</span>
            </label>
        @endforeach
    </div>

    {{-- Phase 5: Typ-Farben — GP / Basisrezept / Gericht durchgängig im Editor + Concepter --}}
    <div class="pt-3 border-t border-black/5 dark:border-white/10" data-settings-typfarben>
        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Typ-Farben</h3>
        <p class="text-[11px] text-gray-400 mt-0.5">Farbe je Positions-Typ — wirkt überall: Seiten-Listen, Positions-Tabellen und Badges im Rezept-/Gerichte-Editor und Concepter.</p>
        <div class="flex flex-wrap gap-4 mt-3 max-w-xl">
            @foreach($farbTypen as $key => $label)
                <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-200" wire:key="tf-{{ $key }}">
                    <input type="color" wire:model="typFarben.{{ $key }}" class="h-7 w-9 rounded border border-black/10 dark:border-white/15 bg-transparent cursor-pointer p-0.5" />
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block w-3 h-3 rounded-full" style="background-color: {{ $typFarben[$key] }}"></span>
                        {{ $label }}
                    </span>
                </label>
            @endforeach
        </div>
        <button type="button" wire:click="farbenZuruecksetzen" class="{{ $btnGhostXs }} mt-2">Auf Standard zurücksetzen</button>
    </div>

    <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-kueche-speichern>Speichern</button>
</div>
