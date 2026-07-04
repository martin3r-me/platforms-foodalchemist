{{-- D-5: 📐 Aus Vorlage instanziieren — Variante → Seed-Vorschläge → Slot-Review --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="template-instanziieren" title="📐 Aus Vorlage instanziieren" size="max-w-2xl">
    @if($fehler !== null)
        <p class="text-xs text-rose-600 dark:text-rose-400 mb-3" data-template-fehler>{{ $fehler }}</p>
    @endif

    @if($templateId === null)
        <p class="text-xs text-gray-400">Kein Template gewählt.</p>
    @else
        <x-foodalchemist::modal-section title="Vorlage">
            <p class="text-xs text-gray-900 dark:text-gray-100 font-medium" data-template-name>{{ $templateName }}</p>
            <p class="text-[11px] text-gray-400 mt-0.5">{{ $slotAnzahl }} Platzhalter · Bindemittel-Verhältnis & Zubereitung bleiben fix</p>
        </x-foodalchemist::modal-section>

        {{-- Variante + Vorschläge --}}
        <x-foodalchemist::modal-section title="Variante / Geschmack">
            <div class="flex items-end gap-2">
                <div class="flex-1">
                    <input type="text" wire:model="variant" wire:keydown.enter.prevent="vorschlaege"
                           placeholder="z. B. Brombeere, Salbei, Kürbis …" class="{{ $input }}" data-template-variant />
                </div>
                <button type="button" wire:click="vorschlaege" wire:loading.attr="disabled"
                        class="{{ $btnGhost }} shrink-0" data-template-vorschlaege>
                    <span wire:loading.remove wire:target="vorschlaege">Vorschläge holen</span>
                    <span wire:loading wire:target="vorschlaege">sucht …</span>
                </button>
            </div>
            <p class="text-[11px] text-gray-400 mt-1">
                Deterministisch (Body = Variante, Träger = Default). KI-Vorschläge folgen mit der LLM-Anbindung.
            </p>
        </x-foodalchemist::modal-section>

        {{-- Name der Instanz --}}
        <x-foodalchemist::modal-section title="Name der Instanz">
            <input type="text" wire:model="name" placeholder="z. B. Gelee: Brombeere" class="{{ $input }}" data-template-instanz-name />
        </x-foodalchemist::modal-section>

        {{-- Platzhalter-Slots --}}
        <x-foodalchemist::modal-section title="Platzhalter binden ({{ $gebundenAnzahl }}/{{ $slotAnzahl }})">
            <div class="space-y-2" data-template-slots>
                @foreach($slotListe as $rid => $slot)
                    @php($b = $bindings[$rid] ?? ['query' => '', 'target' => 'none', 'id' => null, 'name' => null, 'score' => 0.0])
                    <div class="rounded-lg border border-black/5 dark:border-white/10 px-3 py-2" wire:key="slot-{{ $rid }}" data-template-slot="{{ $rid }}">
                        <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-1">
                            <strong class="text-gray-700 dark:text-gray-200">{{ $slot['placeholder_name'] }}</strong>
                            · {{ rtrim(rtrim(number_format($slot['quantity'], 2, ',', '.'), '0'), ',') }} {{ $slot['unit'] }}
                            @if($slot['raw_text'] !== '')<span class="italic">· „{{ $slot['raw_text'] }}"</span>@endif
                        </p>
                        <div class="flex items-center gap-2">
                            <input type="text" wire:model="bindings.{{ $rid }}.query" wire:change="matchSlot({{ $rid }})"
                                   placeholder="konkreter Artikel (Suchtext)" class="{{ $input }} flex-1" />
                            <div class="w-48 shrink-0 text-[11px]" data-template-slot-status="{{ $rid }}">
                                @if($b['id'] !== null)
                                    @php($farbe = $b['score'] >= 0.85 ? 'text-emerald-600 dark:text-emerald-400' : ($b['score'] >= 0.7 ? 'text-amber-600 dark:text-amber-400' : 'text-orange-600 dark:text-orange-400'))
                                    <span class="{{ $farbe }}">
                                        → {{ $b['name'] }}{{ $b['target'] === 'sub_recipe' ? ' ⟨Sub⟩' : '' }} ({{ round(($b['score'] ?? 0) * 100) }} %)
                                    </span>
                                @elseif(trim($b['query']) !== '')
                                    <span class="text-orange-600 dark:text-orange-400">kein Treffer → bleibt Platzhalter</span>
                                @else
                                    <span class="text-gray-400">ungebunden</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if($gebundenAnzahl < $slotAnzahl)
                <p class="text-[11px] text-amber-600 dark:text-amber-400 mt-2" data-template-warnung>
                    ⚠ {{ $slotAnzahl - $gebundenAnzahl }} Platzhalter ungebunden — die Instanz bleibt insoweit neutral (status draft, später nachpflegbar).
                </p>
            @endif
        </x-foodalchemist::modal-section>
    @endif

    <x-slot:footer>
        <button type="button" wire:click="$dispatch('modal.close', { name: 'template-instanziieren' })" class="{{ $btnGhost }}">Abbrechen</button>
        <button type="button" wire:click="instanziieren" wire:loading.attr="disabled"
                @disabled($templateId === null || trim($name) === '') class="{{ $btnPrimary }}" data-template-instanziieren>
            <span wire:loading.remove wire:target="instanziieren">Instanziieren</span>
            <span wire:loading wire:target="instanziieren">Instanziiere …</span>
        </button>
    </x-slot:footer>
</x-foodalchemist::modal>
