{{-- Spec 77c · Team-Brille: nur im Oberteam mit Unter-Teams (Standorten). --}}
<div>
    @if($standorte !== [])
        <div class="flex items-center gap-2" data-team-brille>
            <label for="fa-team-brille" class="text-[12px] font-medium text-gray-600 whitespace-nowrap">Standort</label>
            <select id="fa-team-brille" wire:model.live="sicht"
                    class="h-8 min-w-[160px] max-w-[240px] text-[13px] rounded-md border border-gray-300 bg-[var(--fa-surface)] text-gray-900 py-0 pl-2.5 pr-8 focus:border-violet-600 focus:ring-violet-600/20">
                <option value="eigen">{{ $teamName }} (eigen)</option>
                <option value="alle">Alle Standorte</option>
                @foreach($standorte as $s)
                    <option value="team:{{ $s['id'] }}">{{ $s['name'] }}</option>
                @endforeach
            </select>
        </div>
    @endif
</div>
