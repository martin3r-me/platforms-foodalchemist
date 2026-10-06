{{-- D-5: Rezept aus Vorlage anlegen — Variante → Vorschläge → Platzhalter zuordnen → anlegen.
     fa-pass (2026-10-05): Bausteine + Tokens, Zuordnungsstand je Platzhalter als Signal. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $menge = fn ($wert) => rtrim(rtrim(number_format((float) $wert, 2, ',', '.'), '0'), ',');
@endphp

<x-foodalchemist::modal name="template-instanziieren" title="Rezept aus Vorlage anlegen" size="max-w-2xl">
    @if($fehler !== null)
        <x-fa::notice tone="crit" data-template-fehler>{{ $fehler }}</x-fa::notice>
    @endif

    @if($templateId === null)
        <x-fa::empty icon="heroicon-o-document-duplicate" title="Keine Vorlage gewählt">Im Basisrezepte-Browser ein Rezept als Vorlage markieren und von dort aus anlegen.</x-fa::empty>
    @else
        <x-foodalchemist::modal-section title="Vorlage">
            <p class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]" data-template-name>{{ $templateName }}</p>
            <p class="mt-0.5 {{ $leise }}">{{ $slotAnzahl }} Platzhalter. Bindemittel-Verhältnis und Zubereitung bleiben wie in der Vorlage.</p>
        </x-foodalchemist::modal-section>

        {{-- Variante + Vorschläge --}}
        <x-foodalchemist::modal-section title="Variante">
            <x-fa::field for="template-variante" hint="Die Variante wird zur Hauptzutat, die übrigen Platzhalter bekommen die üblichen Zutaten.">
                <div class="flex items-center gap-2">
                    <x-fa::input id="template-variante" wire:model="variant" wire:keydown.enter.prevent="vorschlaege"
                        placeholder="z. B. Brombeere, Salbei, Kürbis" class="flex-1" data-template-variant />
                    <x-fa::button icon="heroicon-o-light-bulb" class="shrink-0" wire:click="vorschlaege" wire:loading.attr="disabled" data-template-vorschlaege>
                        <span wire:loading.remove wire:target="vorschlaege">Vorschläge holen</span>
                        <span wire:loading wire:target="vorschlaege">Wird gesucht …</span>
                    </x-fa::button>
                </div>
            </x-fa::field>
        </x-foodalchemist::modal-section>

        {{-- Name des neuen Rezepts --}}
        <x-foodalchemist::modal-section title="Name des neuen Rezepts">
            <x-fa::input wire:model="name" placeholder="z. B. Gelee: Brombeere" aria-label="Name des neuen Rezepts" data-template-instanz-name />
        </x-foodalchemist::modal-section>

        {{-- Platzhalter zuordnen --}}
        <x-foodalchemist::modal-section title="Platzhalter zuordnen ({{ $gebundenAnzahl }} von {{ $slotAnzahl }})">
            <div class="flex flex-col gap-2" data-template-slots>
                @foreach($slotListe as $rid => $slot)
                    @php
                        $b = $bindings[$rid] ?? ['query' => '', 'target' => 'none', 'id' => null, 'name' => null, 'score' => 0.0];
                    @endphp
                    <div class="flex flex-col gap-1.5 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-3 py-2.5" wire:key="slot-{{ $rid }}" data-template-slot="{{ $rid }}">
                        <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">
                            <span class="font-semibold text-[var(--fa-ink)]">{{ $slot['placeholder_name'] }}</span>
                            · <span class="tabular-nums">{{ $menge($slot['quantity']) }} {{ $slot['unit'] }}</span>
                            @if($slot['raw_text'] !== '')<span class="italic text-[var(--fa-ink-3)]"> · „{{ $slot['raw_text'] }}“</span>@endif
                        </p>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-fa::input size="sm" wire:model="bindings.{{ $rid }}.query" wire:change="matchSlot({{ $rid }})"
                                placeholder="Konkrete Zutat suchen" aria-label="Zutat für {{ $slot['placeholder_name'] }}" class="flex-1 min-w-[12rem]" />
                            <div class="min-w-0 sm:w-56 shrink-0" data-template-slot-status="{{ $rid }}">
                                @if($b['id'] !== null)
                                    @php
                                        $ton = $b['score'] >= 0.85 ? 'ok' : 'warn';
                                    @endphp
                                    <x-fa::signal :tone="$ton" icon="heroicon-m-link" title="{{ round(($b['score'] ?? 0) * 100) }} % Übereinstimmung">
                                        <span class="min-w-0">{{ $b['name'] }}{{ $b['target'] === 'sub_recipe' ? ' (Unterrezept)' : '' }} · {{ round(($b['score'] ?? 0) * 100) }} %</span>
                                    </x-fa::signal>
                                @elseif(trim($b['query']) !== '')
                                    <x-fa::signal tone="warn">Kein Treffer, bleibt Platzhalter</x-fa::signal>
                                @else
                                    <span class="{{ $leise }}">Noch nicht zugeordnet</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if($gebundenAnzahl < $slotAnzahl)
                <x-fa::signal tone="warn" class="mt-2" data-template-warnung>
                    {{ $slotAnzahl - $gebundenAnzahl }} {{ ($slotAnzahl - $gebundenAnzahl) === 1 ? 'Platzhalter bleibt' : 'Platzhalter bleiben' }} offen. Das neue Rezept startet dann als Entwurf und lässt sich später ergänzen.
                </x-fa::signal>
            @endif
        </x-foodalchemist::modal-section>
    @endif

    <x-slot:footer>
        <x-fa::button variant="ghost" wire:click="$dispatch('modal.close', { name: 'template-instanziieren' })">Abbrechen</x-fa::button>
        <x-fa::button variant="primary" icon="heroicon-o-plus" wire:click="instanziieren" wire:loading.attr="disabled"
            :disabled="$templateId === null || trim($name) === ''" data-template-instanziieren>
            <span wire:loading.remove wire:target="instanziieren">Rezept anlegen</span>
            <span wire:loading wire:target="instanziieren">Wird angelegt …</span>
        </x-fa::button>
    </x-slot:footer>
</x-foodalchemist::modal>
