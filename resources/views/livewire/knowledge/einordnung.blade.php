{{-- Einordnung eines Wissensdokuments: Kategorie, Wissensart, Geltung, Datenwerte.
     Eingebunden im rechten Panel (gewähltes Dokument) und beim Anlegen in der Mitte.
     fa-pass 2026-10-05: auf Bausteine umgestellt; Felder, wire:model und data-Marker unverändert. --}}
<x-fa::field label="Kategorie" for="wissen-kategorie-{{ $selectedId ?? 'neu' }}" hint="Sagt, worum es geht.">
    <x-fa::select id="wissen-kategorie-{{ $selectedId ?? 'neu' }}" wire:model="form.category" data-wissen-kategorie>
        @foreach($kategorien as $kat)
            <option value="{{ $kat->slug }}">{{ $kat->label }}</option>
        @endforeach
    </x-fa::select>
</x-fa::field>

{{-- Spec 52/H1: die Kategorie sagt WORUM, die Art sagt WIE benutzt werden darf. --}}
<x-fa::field label="Wissensart" for="wissen-art-{{ $selectedId ?? 'neu' }}"
    hint="Sagt, wie die KI es nutzen darf. Datenwerte werden über Bedingungen gefunden, nicht gesucht. Abläufe gehen an Assistenten und nie in eine Rezept-Anfrage.">
    <x-fa::select id="wissen-art-{{ $selectedId ?? 'neu' }}" wire:model.live="form.art" data-wissen-art>
        <option value="">Noch nicht eingeordnet</option>
        @foreach(\Platform\FoodAlchemist\Services\Knowledge\Wissensart::LABELS as $wert => $artLabel)
            <option value="{{ $wert }}">{{ $artLabel }}</option>
        @endforeach
    </x-fa::select>
</x-fa::field>

<fieldset class="flex flex-col gap-2 min-w-0" data-wissen-geltung>
    <legend class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Gilt unter diesen Bedingungen</legend>
    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Leer heißt: keine Einschränkung. Mehrere Werte mit Komma trennen. Alle ausgefüllten Bedingungen müssen passen.</p>
    <div class="grid gap-2 grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))]">
        @foreach(\Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::ACHSEN as $axis => $axisLabel)
            <x-fa::field :label="$axisLabel" for="wissen-geltung-{{ $selectedId ?? 'neu' }}-{{ $axis }}">
                <x-fa::input id="wissen-geltung-{{ $selectedId ?? 'neu' }}-{{ $axis }}" size="sm" wire:model="form.geltung.{{ $axis }}" placeholder="Nicht eingeschränkt" />
            </x-fa::field>
        @endforeach
    </div>
</fieldset>

@if(($form['art'] ?? '') === 'datenwerk')
    <fieldset class="flex flex-col gap-3 min-w-0" data-wissen-datenwerte>
        <legend class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Strukturierte Datenwerte</legend>
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Einzelwert: Minimum und Maximum gleich setzen. Bezugsgröße genau angeben, etwa „Rohgewicht pro Portion“. Ohne passende Werte bleibt eine sichtbare Datenlücke.</p>
        @foreach(($form['datenwerte'] ?? []) as $index => $row)
            <div wire:key="datenwert-{{ $selectedId }}-{{ $index }}" class="fa-surface p-3 flex flex-col gap-2">
                <div class="grid gap-2 grid-cols-[repeat(auto-fit,minmax(min(100%,8rem),1fr))]">
                    @foreach(['kennzahl' => 'Kennzahl', 'min' => 'Minimum', 'max' => 'Maximum', 'einheit' => 'Einheit', 'bezug' => 'Bezugsgröße', 'quelle' => 'Quelle / Fundstelle'] as $field => $fieldLabel)
                        <x-fa::field :label="$fieldLabel" for="wissen-dw-{{ $selectedId ?? 'neu' }}-{{ $index }}-{{ $field }}">
                            <x-fa::input id="wissen-dw-{{ $selectedId ?? 'neu' }}-{{ $index }}-{{ $field }}" size="sm" wire:model="form.datenwerte.{{ $index }}.{{ $field }}" :numeric="in_array($field, ['min', 'max'], true)" />
                        </x-fa::field>
                    @endforeach
                </div>
                <details>
                    <summary class="cursor-pointer select-none text-[length:var(--fa-text-md)] text-[var(--fa-accent)]">Zusätzliche Bedingungen für diesen Wert</summary>
                    <div class="mt-2 grid gap-2 grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))]">
                        @foreach(\Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::ACHSEN as $axis => $axisLabel)
                            <x-fa::field :label="$axisLabel" for="wissen-dw-{{ $selectedId ?? 'neu' }}-{{ $index }}-g-{{ $axis }}">
                                <x-fa::input id="wissen-dw-{{ $selectedId ?? 'neu' }}-{{ $index }}-g-{{ $axis }}" size="sm" wire:model="form.datenwerte.{{ $index }}.geltung.{{ $axis }}" />
                            </x-fa::field>
                        @endforeach
                    </div>
                </details>
                <div class="flex justify-end">
                    <x-fa::button size="sm" variant="danger" icon="heroicon-o-trash" wire:click="removeDatenwert({{ $index }})">Wert entfernen</x-fa::button>
                </div>
            </div>
        @endforeach
        <div>
            <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="addDatenwert">Datenwert hinzufügen</x-fa::button>
        </div>
    </fieldset>
@endif
