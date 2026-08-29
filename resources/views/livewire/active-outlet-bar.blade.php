{{-- Ebene 2 (D2): aktiver Betrieb — treibt die Preise auf allen FA-Seiten (ambienter Kontext). --}}
<div class="px-3 py-2 mb-2 rounded-md" style="background-color:#f5f3ff;border:1px solid #ddd6fe">
    <div class="text-[10px] font-semibold uppercase tracking-wide mb-1" style="color:#6d28d9">Aktiver Betrieb</div>
    <select wire:model.live="aktiverBetrieb"
            class="w-full text-xs rounded border-purple-300 focus:border-purple-500 focus:ring-purple-500 py-1">
        <option value="">— Team-Standard —</option>
        @foreach($betriebe as $b)
            <option value="{{ $b->id }}">{{ $b->name }}</option>
        @endforeach
    </select>
    @if($betriebe->isEmpty())
        <div class="text-[10px] text-gray-500 mt-1">Noch keine Betriebe — Einstellungen&nbsp;→&nbsp;Betriebe.</div>
    @endif
</div>
