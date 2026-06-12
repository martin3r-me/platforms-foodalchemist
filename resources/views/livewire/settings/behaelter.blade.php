{{-- R5: Behälter & Geräte — 3 Container-Vokabulare (D-6 §4.6) + Koch-Equipment (D-5 §2.3), mit Anlegen --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-6" data-settings-behaelter>
    <div>
        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Behälter & Geräte</h3>
        <p class="text-[11px] text-gray-400 mt-0.5">Behälter, Regenerations-Geräte und Servier-Vehikel (VK-Rezepte) plus Koch-Equipment (Basisrezepte). V-06: nur deaktivieren, nie löschen.</p>
    </div>
    @if($fehler !== null)<p class="text-xs text-rose-600 dark:text-rose-400" data-behaelter-fehler>{{ $fehler }}</p>@endif
    @if($meldung !== null)<p class="text-xs text-emerald-600 dark:text-emerald-400" data-behaelter-meldung>{{ $meldung }}</p>@endif

    @foreach($listen as $key => $vokabular)
        <div data-vokabular="{{ $key }}">
            <p class="{{ $dt }} mb-1.5">{{ $vokabular['label'] }} ({{ $vokabular['zeilen']->count() }})</p>

            @foreach($vokabular['zeilen']->groupBy(fn ($z) => $z->gruppe ?? 'sonstig') as $gruppe => $zeilen)
                <div class="flex items-start gap-2 mb-1">
                    @if($vokabular['zeilen']->pluck('gruppe')->filter()->isNotEmpty())
                        <span class="shrink-0 w-28 text-[11px] text-gray-400 pt-0.5">{{ $gruppe }}</span>
                    @endif
                    <div class="flex flex-wrap gap-1">
                        @foreach($zeilen as $zeile)
                            <span wire:key="vk-{{ $key }}-{{ $zeile->id }}"
                                  class="{{ $pill }} {{ $variantPill['secondary'] }} group {{ $zeile->is_inactive ? 'opacity-40 line-through' : '' }}"
                                  title="{{ $zeile->slug }}{{ isset($zeile->kapazitaet_kg) && $zeile->kapazitaet_kg !== null ? ' · ' . rtrim(rtrim(number_format((float) $zeile->kapazitaet_kg, 3, ',', '.'), '0'), ',') . ' kg' : '' }}">
                                {{ $zeile->name }}
                                <button type="button" wire:click="toggleInactive('{{ $key }}', {{ $zeile->id }})"
                                        class="hidden group-hover:inline ml-0.5 {{ $zeile->is_inactive ? 'text-emerald-500' : 'text-rose-400' }}"
                                        title="{{ $zeile->is_inactive ? 'aktivieren' : 'deaktivieren (V-06)' }}">{{ $zeile->is_inactive ? '↻' : '✕' }}</button>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div class="flex flex-wrap items-center gap-2 mt-1.5" data-vokabular-anlegen="{{ $key }}">
                <input type="text" wire:model="neu.{{ $key }}.name" placeholder="Neu: Name" class="{{ $input }} !py-1 w-44" />
                <input type="text" wire:model="neu.{{ $key }}.gruppe" placeholder="Gruppe (optional)" class="{{ $input }} !py-1 w-36" />
                @if($vokabular['kapazitaet'])
                    <input type="text" wire:model="neu.{{ $key }}.kapazitaet_kg" placeholder="kg (optional)" class="{{ $input }} !py-1 w-24 text-right" />
                @endif
                <button type="button" wire:click="create('{{ $key }}')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-vokabular-neu="{{ $key }}">+ Anlegen</button>
            </div>
        </div>
    @endforeach
</div>
