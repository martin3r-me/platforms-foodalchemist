{{-- Wiederverwendbares Canvas-Board (Trait ManagesCanvas). Rendert das feste Template je canvas_type. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($canvasTpl = $this->canvasTemplateData())

<div class="space-y-3" data-canvas-board="{{ $canvasType }}">
    @if($canvasGespeichert)
        <div class="rounded-lg bg-emerald-500/10 border border-emerald-500/30 px-3 py-1.5 text-[11px] text-emerald-700" data-canvas-gespeichert>
            ✓ Gespeichert — fließt als Kontext in die KI-Generierung.
        </div>
    @endif

    @foreach($canvasTpl['gruppen'] as $gruppe => $felder)
        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            <div class="px-4 py-3 space-y-2.5">
                <p class="text-[11px] uppercase tracking-wider text-gray-500">{{ $gruppe }}</p>

                @foreach($felder as $f)
                    @if(($f['type'] ?? 'text') === 'repeatable')
                        {{-- Repeatable (Geschmackswelten): Liste + Hinzufügen --}}
                        <div>
                            <label class="block {{ $label }} mb-1">{{ $f['label'] }}</label>
                            <div class="space-y-1.5 mb-2">
                                @forelse($canvasWelten as $w)
                                    <div wire:key="welt-{{ $w['id'] }}" class="flex items-start gap-2 rounded-lg border border-black/5 px-2.5 py-1.5">
                                        <div class="flex-1 min-w-0">
                                            <span class="text-xs font-medium text-gray-900">{{ $w['value'] }}</span>
                                            @if($w['claim'])<span class="text-[11px] text-gray-500"> · {{ $w['claim'] }}</span>@endif
                                            @if($w['description'])<div class="text-[11px] text-gray-600 truncate">{{ $w['description'] }}</div>@endif
                                        </div>
                                        <button type="button" wire:click="weltLoeschen({{ $w['id'] }})" class="{{ $btnGhostXs }} text-rose-500 shrink-0">✕</button>
                                    </div>
                                @empty
                                    <p class="text-[11px] text-gray-500">Noch keine — unten hinzufügen.</p>
                                @endforelse
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <input type="text" wire:model="canvasNeuWelt.value" placeholder="Name (z. B. Italien)" class="{{ $input }}" />
                                <input type="text" wire:model="canvasNeuWelt.claim" placeholder="Claim" class="{{ $input }}" />
                                <div class="flex gap-2">
                                    <input type="text" wire:model="canvasNeuWelt.description" placeholder="Beschreibung" class="{{ $input }}" />
                                    <button type="button" wire:click="weltHinzu" class="{{ $btnGhostXs }} text-violet-600 shrink-0">+ </button>
                                </div>
                            </div>
                        </div>
                    @elseif(($f['type'] ?? '') === 'ref_schreibstil')
                        @php($schreibstile = \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::visibleToTeam(auth()->user()->currentTeamRelation)->where('is_inactive', false)->orderBy('name')->get(['id', 'name']))
                        <div>
                            <label class="block {{ $label }} mb-1">{{ $f['label'] }}</label>
                            <select wire:model="canvasForm.{{ $f['key'] }}" class="{{ $input }}">
                                <option value="">— neutral —</option>
                                @foreach($schreibstile as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                            </select>
                        </div>
                    @elseif(($f['type'] ?? '') === 'text')
                        <div>
                            <label class="block {{ $label }} mb-1">{{ $f['label'] }}</label>
                            <input type="text" wire:model="canvasForm.{{ $f['key'] }}" class="{{ $input }}" />
                        </div>
                    @else
                        <div>
                            <label class="block {{ $label }} mb-1">{{ $f['label'] }}</label>
                            <textarea wire:model="canvasForm.{{ $f['key'] }}" rows="2" class="{{ $input }}"></textarea>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="flex items-center gap-3">
        <button type="button" wire:click="canvasSpeichern" class="{{ $btnPrimary }}" data-canvas-speichern>Speichern</button>
        <span wire:loading wire:target="canvasSpeichern" class="text-[11px] text-gray-500">speichere …</span>
    </div>
</div>
