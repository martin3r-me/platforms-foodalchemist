{{-- Live-Cascade-Cockpit: eine Kaskaden-Step-Zeile (root ODER Fan-out-Kind via $indent).
     Zeigt Status, In-Context-Ansicht des Entwurfs (kein Wegspringen), Freigabe/Verwerfen und
     — zur Kontrolle (#1a) — welches Wissen der Generator für DIESEN Schritt genutzt hat
     (context_snapshot, je Step persistiert). Erwartet: $st, $stepLabel, $stepColor, $refRoute, $indent. --}}
@php
    $indent = $indent ?? false;
    $snap = is_array($st->context_snapshot) ? $st->context_snapshot : [];
    $wissenFiles = (array) ($snap['knowledge_files'] ?? []);
@endphp
<div wire:key="step-{{ $st->id }}" class="{{ $indent ? 'ml-1 pl-3 border-l border-white/10' : '' }}">
    <div class="flex items-center justify-between gap-3 text-xs">
        <span class="truncate text-gray-200">{{ $indent ? '↳ ' : '' }}{{ $st->label ?: ucfirst($st->kind) }}</span>
        <span class="shrink-0 flex items-center gap-2">
            <span class="{{ $stepColor[$st->status] ?? 'text-gray-400' }}">{{ $stepLabel[$st->status] ?? $st->status }}</span>
            @if($st->ref_id && in_array($st->status, ['done', 'freigegeben'], true))
                @if($st->kind === 'rezept')
                    <button type="button" wire:click="$dispatch('recipe-modal.oeffnen', { id: {{ (int) $st->ref_id }} })" class="text-violet-300 hover:text-violet-200 underline">ansehen</button>
                @elseif($st->kind === 'gericht')
                    <button type="button" wire:click="$dispatch('vk-modal.oeffnen', { id: {{ (int) $st->ref_id }} })" class="text-violet-300 hover:text-violet-200 underline">ansehen</button>
                @elseif(isset($refRoute[$st->kind]))
                    <a href="{{ route($refRoute[$st->kind]) }}" class="text-violet-300 hover:text-violet-200 underline">öffnen</a>
                @endif
            @endif
            @if($st->status === 'done')
                <button wire:click="gibFrei({{ $st->id }})" class="text-emerald-300 hover:text-emerald-200" title="Freigeben">@svg('heroicon-o-check', 'w-4 h-4')</button>
                <button wire:click="verwirf({{ $st->id }})" class="text-rose-300 hover:text-rose-200" title="Verwerfen">@svg('heroicon-o-trash', 'w-4 h-4')</button>
            @endif
        </span>
    </div>
    @if($st->status === 'failed' && $st->error)
        <p class="text-[10px] text-rose-400/80 pl-1">{{ \Illuminate\Support\Str::limit($st->error, 160) }}</p>
    @endif
    {{-- A: voll-inline Zutaten-Review — sehen WAS angelegt wurde + tauschen/entfernen/ergänzen VOR der Freigabe.
         On-Demand gemountet (toggleZutaten), reused IngredientEditor (:eingebettet), nur für Rezept/Gericht-Drafts. --}}
    @if($st->ref_id && in_array($st->kind, ['rezept', 'gericht'], true) && in_array($st->status, ['done', 'freigegeben'], true))
        @php $zOffen = in_array($st->id, $zutatenOffen ?? [], true); @endphp
        <div class="mt-1">
            <button type="button" wire:click="toggleZutaten({{ $st->id }})"
                    class="text-[10px] text-emerald-300/80 hover:text-emerald-200 inline-flex items-center gap-1 select-none">
                @svg($zOffen ? 'heroicon-o-chevron-down' : 'heroicon-o-adjustments-horizontal', 'w-3 h-3')
                {{ $zOffen ? 'Zutaten schließen' : 'Zutaten prüfen & ändern' }}
            </button>
            @if($zOffen)
                <div class="mt-2 rounded-lg bg-white/[0.03] p-2" wire:key="zutaten-wrap-{{ $st->id }}">
                    <p class="text-[10px] text-gray-400 mb-1.5">Entwurf — tauschen / entfernen / ergänzen, dann freigeben:</p>
                    <livewire:foodalchemist.recipes.ingredient-editor :recipe-id="(int) $st->ref_id" :eingebettet="true" wire:key="worker-zutaten-{{ $st->id }}-{{ (int) $st->ref_id }}" />
                </div>
            @endif
        </div>
    @endif
    @if($wissenFiles !== [])
        <details class="mt-0.5">
            <summary class="text-[10px] text-gray-500 cursor-pointer hover:text-gray-300">Verwendetes Wissen ({{ count($wissenFiles) }})</summary>
            <div class="mt-1 flex flex-wrap gap-1">
                @foreach(array_slice($wissenFiles, 0, 14) as $f)
                    <span class="px-1.5 py-0.5 rounded bg-white/5 text-[10px] text-gray-400">{{ \Illuminate\Support\Str::of((string) $f)->afterLast('/')->beforeLast('.') }}</span>
                @endforeach
                @if(count($wissenFiles) > 14)
                    <span class="text-[10px] text-gray-500">+{{ count($wissenFiles) - 14 }}</span>
                @endif
            </div>
        </details>
    @endif
</div>
