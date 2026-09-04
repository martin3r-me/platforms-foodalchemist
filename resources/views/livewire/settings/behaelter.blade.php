{{-- R5: Behälter & Geräte — 3 Container-Vokabulare (D-6 §4.6) + Koch-Equipment (D-5 §2.3), mit Anlegen.
     Spec 51: der Behälter-Katalog trägt jetzt Maße und Freigaben, damit der Bedarf gerechnet werden
     kann statt getippt — und damit neue Lager-/Regenerationsbehälter ohne Deployment dazukommen. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-6" data-settings-behaelter>
    @if($fehler !== null)<p class="text-xs text-rose-600" data-behaelter-fehler>{{ $fehler }}</p>@endif
    @if($meldung !== null)<p class="text-xs text-emerald-600" data-behaelter-meldung>{{ $meldung }}</p>@endif

    @foreach($listen as $key => $vokabular)
        <div data-vokabular="{{ $key }}">
            <p class="{{ $dt }} mb-1.5">{{ $vokabular['label'] }} ({{ $vokabular['zeilen']->count() }})</p>

            @foreach($vokabular['zeilen']->groupBy(fn ($z) => $z->group_name ?? 'sonstig') as $gruppe => $zeilen)
                <div class="flex items-start gap-2 mb-1">
                    @if($vokabular['zeilen']->pluck('group_name')->filter()->isNotEmpty())
                        <span class="shrink-0 w-28 text-[11px] text-gray-500 pt-0.5">{{ $gruppe }}</span>
                    @endif
                    <div class="flex flex-wrap gap-1">
                        @foreach($zeilen as $zeile)
                            @php($masse = $vokabular['kapazitaet'] ? \Platform\FoodAlchemist\Livewire\Settings\Behaelter::titel($zeile) : $zeile->slug)
                            <span wire:key="vk-{{ $key }}-{{ $zeile->id }}"
                                  class="{{ $pill }} {{ $variantPill['secondary'] }} group {{ $zeile->is_inactive ? 'opacity-40 line-through' : '' }}"
                                  title="{{ $masse }}">
                                {{ $zeile->name }}
                                @if($vokabular['kapazitaet'])
                                    <button type="button" wire:click="bearbeitenStart({{ $zeile->id }})"
                                            class="hidden group-hover:inline ml-0.5 text-violet-500" title="bearbeiten"
                                            data-behaelter-edit="{{ $zeile->id }}">@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5 inline-block align-middle')</button>
                                @endif
                                <button type="button" wire:click="toggleInactive('{{ $key }}', {{ $zeile->id }})"
                                        class="hidden group-hover:inline ml-0.5 {{ $zeile->is_inactive ? 'text-emerald-500' : 'text-rose-400' }}"
                                        title="{{ $zeile->is_inactive ? 'aktivieren' : 'deaktivieren' }}">@if($zeile->is_inactive)
                                            @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5 inline-block align-middle')
                                        @else
                                            @svg('heroicon-o-eye-slash', 'w-3.5 h-3.5 inline-block align-middle')
                                        @endif</button>
                                <button type="button" wire:click="delete('{{ $key }}', {{ $zeile->id }})" wire:confirm="Diesen Eintrag löschen?"
                                        class="hidden group-hover:inline ml-0.5 text-rose-500" title="löschen (nur wenn ungenutzt)">@svg('heroicon-o-trash', 'w-3.5 h-3.5 inline-block align-middle')</button>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endforeach

            @if($vokabular['kapazitaet'] && $editId !== null)
                <div class="mt-2 p-2 rounded border border-violet-300/60 bg-violet-50/40 dark:bg-violet-950/10" data-behaelter-editform>
                    <p class="text-[11px] text-gray-500 mb-1.5">Bearbeiten — der Slug bleibt, Rezepte hängen daran.</p>
                    @include('foodalchemist::livewire.settings.partials.behaelter-felder', ['praefix' => 'edit', 'f' => $edit])
                    <div class="flex gap-2 mt-1.5">
                        <button type="button" wire:click="bearbeitenSpeichern" class="{{ $btnGhostXs }} text-emerald-600" data-behaelter-edit-speichern>Speichern</button>
                        <button type="button" wire:click="bearbeitenAbbrechen" class="{{ $btnGhostXs }} text-gray-500">Abbrechen</button>
                    </div>
                </div>
            @endif

            <div class="mt-1.5" data-vokabular-anlegen="{{ $key }}">
                @if($vokabular['kapazitaet'])
                    @include('foodalchemist::livewire.settings.partials.behaelter-felder', ['praefix' => "neu.{$key}", 'f' => $neu[$key]])
                    <button type="button" wire:click="create('{{ $key }}')" class="{{ $btnGhostXs }} text-violet-600 mt-1.5" data-vokabular-neu="{{ $key }}">+ Anlegen</button>
                @else
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" wire:model="neu.{{ $key }}.name" placeholder="Neu: Name" class="{{ $input }} !py-1 w-44" />
                        <input type="text" wire:model="neu.{{ $key }}.group_name" placeholder="Gruppe (optional)" class="{{ $input }} !py-1 w-36" />
                        <button type="button" wire:click="create('{{ $key }}')" class="{{ $btnGhostXs }} text-violet-600" data-vokabular-neu="{{ $key }}">+ Anlegen</button>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>
