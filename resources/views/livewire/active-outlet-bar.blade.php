{{-- Ebene 2 (D2): aktiver Betrieb — treibt die Preise auf allen FA-Seiten (ambienter Kontext).
     Design „Labor und Tageslicht": kompakt in der Kopfzeile statt Kasten in der Sidebar. --}}
<div class="flex items-center gap-2">
    @if($fest)
        {{-- Spec 77c: Standort (Unter-Team) mit festem Betrieb des Oberteams — nichts auszusuchen --}}
        <span class="text-[12px] font-medium text-gray-600 whitespace-nowrap">Betrieb</span>
        <span class="h-8 inline-flex items-center px-2.5 text-[13px] rounded-md border border-gray-200 bg-[var(--fa-ground)] text-gray-900" data-betrieb-fest title="vom Oberteam zugeordnet">{{ $fest->name }}</span>
    @else
    <label for="fa-aktiver-betrieb" class="text-[12px] font-medium text-gray-600 whitespace-nowrap">Betrieb</label>
    <select id="fa-aktiver-betrieb" wire:model.live="aktiverBetrieb"
            class="h-8 min-w-[160px] max-w-[240px] text-[13px] rounded-md border border-gray-300 bg-[var(--fa-surface)] text-gray-900 py-0 pl-2.5 pr-8 focus:border-violet-600 focus:ring-violet-600/20">
        <option value="">Team-Standard</option>
        @foreach($betriebe as $b)
            <option value="{{ $b->id }}">{{ $b->name }}</option>
        @endforeach
    </select>
    @if($betriebe->isEmpty())
        <a href="{{ route('foodalchemist.einstellungen') }}" wire:navigate class="text-[12px] text-violet-600 hover:text-violet-700 whitespace-nowrap">Betrieb anlegen</a>
    @endif
    @endif
</div>
