{{-- M1-02: Einheiten-Verwaltung — Stück-Default-Gewichte, Inline-Edit, Inaktiv-Lebenszyklus
     fa-pass 2026-10-05: Bausteine/Tokens. Häufigste Aufgabe = Gramm/Milliliter einer Einheit nachtragen →
     Tabelle mit Bearbeiten je Zeile; Deaktivieren und Löschen im Menü „Weitere Aktionen" (Löschen rot, abgesetzt).
     Dimension als Klartext (Gewicht · Volumen · Stück) statt Rohwert. --}}
@php
    $dimensionen = ['mass' => 'Gewicht', 'volume' => 'Volumen', 'count' => 'Stück'];
    // Nachkomma-Nullen nur kürzen, wenn es Nachkommastellen gibt (sonst würde aus 100 eine 1).
    $zahl = fn ($wert) => $wert === null ? null : str_replace('.', ',', str_contains((string) $wert, '.') ? rtrim(rtrim((string) $wert, '0'), '.') : (string) $wert);
@endphp

<div class="flex flex-col gap-4">
    @if($fehler)
        <x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>
    @endif

    <x-fa::section title="Einheiten" :meta="$einheiten->count() . ' Einheiten'"
        description="Gramm und Milliliter je Einheit machen Rezeptmengen in Gewicht und Volumen umrechenbar, zum Beispiel ein Esslöffel oder ein Stück.">
        <x-slot:actions>
            <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] cursor-pointer">
                <input type="checkbox" wire:model.live="includeInactive" class="rounded accent-[var(--fa-accent)]" />
                Inaktive zeigen
            </label>
        </x-slot:actions>

        <div class="overflow-x-auto -mx-4">
            <table class="fa-table">
                <thead>
                    <tr>
                        <th>Kürzel</th>
                        <th class="w-full">Anzeige</th>
                        <th>Art</th>
                        <th class="num">Gramm je Einheit</th>
                        <th class="num">Milliliter je Einheit</th>
                        <th class="num">Reihenfolge</th>
                        <th><span class="sr-only">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($einheiten as $unit)
                        @php($darfEdit = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $unit))
                        <tr wire:key="unit-{{ $unit->id }}" class="{{ $unit->is_inactive ? 'opacity-60' : '' }}">
                            @if($editId === $unit->id)
                                <td class="text-[var(--fa-ink-2)]">{{ $unit->slug }}</td>
                                <td><x-fa::input size="sm" wire:model="form.display_de" wire:keydown.enter="save" wire:keydown.escape="cancel" aria-label="Anzeige" class="w-full min-w-32" /></td>
                                <td>
                                    <x-fa::select size="sm" wire:model="form.dimension" aria-label="Art" class="w-32">
                                        <option value="">ohne</option>
                                        @foreach($dimensionen as $dim => $dimLabel)<option value="{{ $dim }}">{{ $dimLabel }}</option>@endforeach
                                    </x-fa::select>
                                </td>
                                <td class="num"><x-fa::input size="sm" numeric wire:model="form.default_in_g" wire:keydown.enter="save" aria-label="Gramm je Einheit" class="w-24" /></td>
                                <td class="num"><x-fa::input size="sm" numeric wire:model="form.default_in_ml" wire:keydown.enter="save" aria-label="Milliliter je Einheit" class="w-24" /></td>
                                <td class="num"><x-fa::input size="sm" type="number" numeric wire:model="form.sort_order" aria-label="Reihenfolge" class="w-20" /></td>
                                <td class="whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-fa::button size="sm" variant="ghost" wire:click="cancel">Abbrechen</x-fa::button>
                                        <x-fa::button size="sm" variant="primary" wire:click="save">Speichern</x-fa::button>
                                    </div>
                                </td>
                            @else
                                <td class="font-medium whitespace-nowrap">
                                    {{ $unit->slug }}
                                    @unless($darfEdit)<x-fa::badge class="ml-1.5" title="Aus dem Katalog des Eltern-Teams. Ändern kann nur das Team, dem er gehört.">geerbt</x-fa::badge>@endunless
                                </td>
                                <td>
                                    {{ $unit->display_de }}
                                    @if($unit->is_inactive)<x-fa::badge class="ml-1.5">inaktiv</x-fa::badge>@endif
                                </td>
                                <td class="text-[var(--fa-ink-2)]">{{ $dimensionen[$unit->dimension] ?? ($unit->dimension ?? 'ohne') }}</td>
                                <td class="num">@if($zahl($unit->default_in_g) !== null){{ $zahl($unit->default_in_g) }}&nbsp;<span class="text-[var(--fa-ink-3)]">g</span>@else<span class="text-[var(--fa-ink-3)]">leer</span>@endif</td>
                                <td class="num">@if($zahl($unit->default_in_ml) !== null){{ $zahl($unit->default_in_ml) }}&nbsp;<span class="text-[var(--fa-ink-3)]">ml</span>@else<span class="text-[var(--fa-ink-3)]">leer</span>@endif</td>
                                <td class="num text-[var(--fa-ink-2)]">{{ $unit->sort_order }}</td>
                                <td class="whitespace-nowrap" data-unit-aktionen="{{ $darfEdit ? 'edit' : 'readonly' }}">
                                    @if($darfEdit)
                                        <div class="flex items-center justify-end gap-1">
                                            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-pencil-square" wire:click="edit({{ $unit->id }})">Bearbeiten</x-fa::button>
                                            <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                                <x-fa::icon-button size="sm" icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen für {{ $unit->display_de }}"
                                                    x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                                <div class="hidden w-48 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="toggleInactive({{ $unit->id }}, {{ $unit->is_inactive ? 'false' : 'true' }})"
                                                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]">
                                                        @svg($unit->is_inactive ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-eye-slash', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                                                        {{ $unit->is_inactive ? 'Aktivieren' : 'Deaktivieren' }}
                                                    </button>
                                                    <div class="my-1 border-t border-[var(--fa-line)]"></div>
                                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="delete({{ $unit->id }})" wire:confirm="Einheit „{{ $unit->display_de }}“ wirklich löschen?"
                                                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]">
                                                        @svg('heroicon-o-trash', 'w-4 h-4 shrink-0') Einheit löschen
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-fa::section>

    {{-- Neu anlegen (eigenes Team — Kind-Teams ergänzen Eigenes, D1) --}}
    <x-fa::section title="Neue Einheit" description="Wird für dein Team angelegt." data-unit-neu>
        <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))] items-start">
            <x-fa::field label="Kürzel" for="unit-neu-slug" hint="Kleinbuchstaben, z. B. el">
                <x-fa::input id="unit-neu-slug" wire:model="neu.slug" placeholder="el" />
            </x-fa::field>
            <x-fa::field label="Anzeige" for="unit-neu-anzeige" hint="So steht es im Rezept, z. B. EL">
                <x-fa::input id="unit-neu-anzeige" wire:model="neu.display_de" placeholder="EL" />
            </x-fa::field>
            <x-fa::field label="Gramm je Einheit" for="unit-neu-g" optional>
                <x-fa::input id="unit-neu-g" numeric wire:model="neu.default_in_g" placeholder="15" />
            </x-fa::field>
            <x-fa::field label="Milliliter je Einheit" for="unit-neu-ml" optional>
                <x-fa::input id="unit-neu-ml" numeric wire:model="neu.default_in_ml" placeholder="15" />
            </x-fa::field>
        </div>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <x-fa::choice name="neu.dimension" label="Art" :live="false" :options="['' => 'ohne'] + $dimensionen" />
            <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="create">Einheit anlegen</x-fa::button>
        </div>
    </x-fa::section>
</div>
