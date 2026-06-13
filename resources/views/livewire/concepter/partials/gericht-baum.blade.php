{{-- Gericht-Baum-Picker (geteilt: Concept-Slot + Paket-Schnüren) — VK-Hauptgruppe → Klasse
     → Geschmack-Kaskade wie der VK-Browser. Erwartet: $sucheModel (wire-Model-Name der Suche),
     $pickHauptgruppen, $pickHgCounts, $pickKlassen, $pickKlassenCounts. UI-Maps + pickHg/pickKlasse/
     pickGeschmack erbt der Include aus dem Editor-Scope. --}}
@php($pillBtn = 'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] border transition-colors')
@php($pillOn = 'bg-violet-500/15 border-violet-500/40 text-violet-700 dark:text-violet-300')
@php($pillOff = 'bg-black/[0.03] dark:bg-white/5 border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300')
<div class="space-y-1.5 rounded-lg bg-black/[0.02] dark:bg-white/[0.03] p-2">
    <input type="search" wire:model.live.debounce.300ms="{{ $sucheModel }}" placeholder="Gericht suchen — oder unten nach Gruppe browsen …" class="{{ $input }}" />

    {{-- VK-Hauptgruppen --}}
    <div class="flex flex-wrap gap-1">
        @foreach($pickHauptgruppen as $hg)
            <button type="button" wire:click="pickHgWaehle({{ $hg->id }})" title="{{ $hg->bezeichnung }}"
                    class="{{ $pillBtn }} {{ $pickHg === $hg->id ? $pillOn : $pillOff }}">
                <span class="font-medium">{{ $hg->code ?: $hg->bezeichnung }}</span>
                @if(($pickHgCounts[$hg->id] ?? 0) > 0)<span class="opacity-60 tabular-nums">{{ $pickHgCounts[$hg->id] }}</span>@endif
            </button>
        @endforeach
    </div>

    {{-- Klassen-Kaskade (nur wenn Hauptgruppe gewählt) --}}
    @if($pickHg !== null && $pickKlassen->isNotEmpty())
        <div class="flex flex-wrap gap-1 pl-1 border-l-2 border-violet-500/20">
            @foreach($pickKlassen as $kl)
                <button type="button" wire:click="pickKlasseWaehle({{ $kl->id }})"
                        class="{{ $pillBtn }} {{ $pickKlasse === $kl->id ? $pillOn : $pillOff }}">
                    {{ $kl->bezeichnung }}
                    @if(($pickKlassenCounts[$kl->id] ?? 0) > 0)<span class="opacity-60 tabular-nums">{{ $pickKlassenCounts[$kl->id] }}</span>@endif
                </button>
            @endforeach
        </div>
    @endif

    {{-- Geschmack --}}
    <div class="flex flex-wrap items-center gap-1">
        <span class="text-[10px] text-gray-400 uppercase tracking-wider mr-0.5">Geschmack</span>
        @foreach(['suess' => 'süß', 'herzhaft' => 'herzhaft', 'neutral' => 'neutral'] as $v => $l)
            <button type="button" wire:click="pickGeschmackWaehle('{{ $v }}')"
                    class="{{ $pillBtn }} {{ $pickGeschmack === $v ? $pillOn : $pillOff }}">{{ $l }}</button>
        @endforeach
    </div>
</div>
