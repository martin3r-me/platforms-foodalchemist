{{-- Format-Modul (Phase B): Detail-Panel — Identität + Editionen + Preis-Range + Hero --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($statusLabel = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiv'])
@php($originLabel = ['eigen' => 'Eigen', 'gruppe' => 'Gruppe', 'kunde' => 'Kunde'])

<div class="p-3">
    @if($format === null)
        <p class="text-sm text-gray-500 py-10 text-center">Kein Format gewählt.</p>
    @else
        <div class="space-y-4">
            {{-- Hero --}}
            @if($format->heroImage)
                <img src="{{ $format->heroImage->url() }}" alt="{{ $format->name }}"
                     class="w-full h-40 object-cover rounded-xl border border-black/5" />
            @endif

            {{-- Kopf --}}
            <div>
                <div class="flex items-start justify-between gap-2">
                    <h3 class="text-base font-semibold text-gray-900">{{ $format->name }}</h3>
                    <span class="{{ $pill }} {{ ['draft' => $variantPill['secondary'], 'active' => $variantPill['success'], 'archiviert' => $variantPill['warning']][$format->status] ?? $variantPill['secondary'] }}">
                        {{ $statusLabel[$format->status] ?? $format->status }}
                    </span>
                </div>
                @if($format->consumer_name)<p class="text-sm text-gray-600 mt-0.5">{{ $format->consumer_name }}</p>@endif
                @if($format->claim)<p class="text-xs italic text-violet-600 mt-0.5">„{{ $format->claim }}“</p>@endif
                <div class="flex flex-wrap gap-1 mt-2">
                    @if($format->origin === 'kunde')
                        <span class="{{ $pill }} {{ $variantPill['warning'] }}" title="Kunden-IP — nicht für andere adaptieren">Kunde 🔒</span>
                    @elseif($format->origin)
                        <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $originLabel[$format->origin] ?? $format->origin }}</span>
                    @endif
                    @if($range['min'] !== null)
                        <span class="{{ $pill }} {{ $variantPill['secondary'] }} tabular-nums">
                            {{ $range['min'] === $range['max']
                                ? number_format($range['min'], 2, ',', '.') . ' €'
                                : number_format($range['min'], 2, ',', '.') . '–' . number_format($range['max'], 2, ',', '.') . ' € p. P.' }}
                        </span>
                    @endif
                </div>
            </div>

            @if($format->story)
                <div>
                    <span class="{{ $label }}">Story</span>
                    <p class="text-sm text-gray-700 whitespace-pre-line mt-0.5">{{ \Illuminate\Support\Str::limit($format->story, 320) }}</p>
                </div>
            @endif

            {{-- Editionen (F2: referenzierte Concepts der type=concept-Slots) --}}
            @php($conceptSlots = $format->slots->where('type', 'concept'))
            <div>
                <span class="{{ $label }}">Editionen ({{ $conceptSlots->count() }})</span>
                <div class="mt-1 space-y-1">
                    @forelse($conceptSlots as $s)
                        @php($e = $s->concept)
                        @continue($e === null)
                        <div wire:key="fed-{{ $s->id }}" class="flex items-center justify-between gap-2 text-sm px-2 py-1 rounded-lg bg-black/[0.03]">
                            <span class="truncate text-gray-800">{{ $e->consumer_name ?: $e->name }}</span>
                            <span class="tabular-nums text-gray-500 shrink-0">{{ $e->price_per_person_cache !== null ? number_format((float) $e->price_per_person_cache, 2, ',', '.') . ' €' : '—' }}</span>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400">Noch keine Editionen zugeordnet.</p>
                    @endforelse
                </div>
            </div>

            {{-- Aktionen --}}
            <div class="flex flex-wrap gap-2 pt-2 border-t border-black/5">
                <button type="button" wire:click="bearbeiten" class="{{ $btnPrimary }}">Bearbeiten</button>
                <button type="button" wire:click="loeschen" wire:confirm="Format wirklich löschen? Die Editionen werden wieder freistehend."
                        class="{{ $btnGhost }} text-rose-600">Löschen</button>
            </div>
        </div>
    @endif
</div>
