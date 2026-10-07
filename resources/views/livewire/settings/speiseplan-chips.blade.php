{{-- Spec 59 — Speiseplan-Chips: zentraler Katalog für Plan-Vorgaben (mind./höchstens je Woche). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div data-settings-speiseplan-chips>
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei --}}
    {{-- Spec 65: rohes PHP statt Blade-Block — die Inline-Anweisung in Zeile 2 würde sonst einen Block verschlucken --}}
    <?php $sperrLesen = in_array($sperr['modus'], ['lesen', 'fremd'], true); ?>
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    @if($fehler)<x-foodalchemist::alert tone="danger" class="mb-2" data-chips-fehler>{{ $fehler }}</x-foodalchemist::alert>@endif
    @if($meldung)<x-foodalchemist::alert tone="success" class="mb-2" data-chips-meldung>{{ $meldung }}</x-foodalchemist::alert>@endif

    <p class="text-[11px] text-gray-500 mb-3">
        Ein Chip ist ein <strong>Prüf-Kriterium</strong> für Speiseplan-Vorgaben — z. B. „Vegan“, „Schwein“ oder
        „Suppe“ (Hauptgruppe). Mehrere Kriterien an einem Chip gelten <strong>ODER</strong>: „Fleischlos“ = Vegan oder
        Vegetarisch. Gezählt wird <strong>je Gericht und Woche</strong>; Gerichte ohne Diät-Pflege zählen nie als Fleisch.
        Die Vorgabe selbst (mind./höchstens, Mahlzeit) legst du je Plan im Speiseplan-Editor unter <em>Stammdaten</em> fest —
        die Werte hier sind nur Vorschläge beim Hinzufügen.
    </p>

    @if(! $hatEigene)
        <div class="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-black/10 p-3" data-chips-standard>
            <span class="text-[11px] text-gray-600">Noch keine eigenen Chips. Startsatz: Vegan, Vegetarisch, Fleisch, Fisch, Schwein.</span>
            <button type="button" wire:click="standardAnlegen" class="{{ $btnGhostXs }}" data-chips-standard-anlegen>Standard-Chips anlegen</button>
        </div>
    @endif

    <table class="{{ $table }}">
        <thead>
            <tr>
                <th class="{{ $th }} w-full">Chip</th>
                <th class="{{ $th }}">Kriterien (ODER)</th>
                <th class="{{ $th }} text-right whitespace-nowrap" title="Vorschlag beim Hinzufügen einer Vorgabe">mind.</th>
                <th class="{{ $th }} text-right whitespace-nowrap" title="Vorschlag beim Hinzufügen einer Vorgabe">höchstens</th>
                <th class="{{ $th }} text-right whitespace-nowrap">Sort.</th>
                <th class="{{ $th }} w-px"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($chips as $c)
                @php($eigen = (int) $c->team_id === (int) $eigenesTeamId)
                @php($tokens = $chipTokens[$c->id] ?? [])
                <tr class="{{ $tr }} align-top {{ $c->is_active ? '' : 'opacity-50' }}" wire:key="chip-{{ $c->id }}" data-chip="{{ $c->id }}">
                    <td class="{{ $td }}">
                        @if($eigen)
                            <input type="text" value="{{ $c->label }}" wire:change="feldSetzen({{ $c->id }}, 'label', $event.target.value)"
                                   class="{{ $input }} !py-0.5" data-chip-label />
                        @else
                            {{ $c->label }} <span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1" title="Aus dem Eltern-Team — in Plänen nutzbar, bearbeiten nur dort">geerbt</span>
                        @endif
                    </td>
                    <td class="{{ $td }}" x-data="{ offen: false }">
                        <div class="flex flex-wrap items-center gap-1">
                            @forelse($tokens as $t)
                                <span class="{{ $pill }} {{ str_starts_with($t, 'diaet:') ? $variantPill['success'] : $variantPill['info'] }} whitespace-nowrap">{{ $tokenLabels[$t] ?? $t }}</span>
                            @empty
                                <span class="text-[11px] text-amber-600">kein Kriterium</span>
                            @endforelse
                            @if($eigen)
                                <button type="button" x-on:click="offen = ! offen" class="{{ $btnGhostXs }}" data-chip-kriterien-bearbeiten>
                                    <span x-text="offen ? 'fertig' : 'ändern'">ändern</span>
                                </button>
                            @endif
                        </div>
                        @if($eigen)
                            <div x-show="offen" x-cloak class="mt-1.5 space-y-1" data-chip-kriterien-auswahl>
                                <div class="flex flex-wrap gap-1">
                                    @foreach($diaeten as $key => $dl)
                                        @php($tok = 'diaet:' . $key)
                                        <button type="button" wire:click="kriteriumUmschalten({{ $c->id }}, '{{ $tok }}')" aria-pressed="{{ in_array($tok, $tokens, true) ? 'true' : 'false' }}"
                                                class="{{ $pill }} {{ in_array($tok, $tokens, true) ? $variantPill['success'] : $variantPill['secondary'] }}">{{ $dl }}</button>
                                    @endforeach
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    @foreach($hauptgruppen as $hg)
                                        @php($tok = 'hauptgruppe:' . $hg->id)
                                        <button type="button" wire:click="kriteriumUmschalten({{ $c->id }}, '{{ $tok }}')" aria-pressed="{{ in_array($tok, $tokens, true) ? 'true' : 'false' }}"
                                                class="{{ $pill }} {{ in_array($tok, $tokens, true) ? $variantPill['info'] : $variantPill['secondary'] }}">{{ $hg->label }}</button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </td>
                    @foreach(['default_min' => 'mind.', 'default_max' => 'höchstens', 'sort_order' => 'Sortierung'] as $feld => $fl)
                        <td class="{{ $td }} text-right">
                            @if($eigen)
                                <input type="text" inputmode="numeric" value="{{ $c->{$feld} }}" aria-label="{{ $fl }}"
                                       wire:change="feldSetzen({{ $c->id }}, '{{ $feld }}', $event.target.value)"
                                       class="{{ $input }} !py-0.5 !w-16 text-right tabular-nums" placeholder="—" data-chip-{{ str_replace('_', '-', $feld) }} />
                            @else
                                <span class="tabular-nums text-[11px] text-gray-500">{{ $c->{$feld} ?? '—' }}</span>
                            @endif
                        </td>
                    @endforeach
                    <td class="{{ $td }} whitespace-nowrap">
                        @if($eigen)
                            <button type="button" wire:click="aktivToggle({{ $c->id }})" class="{{ $btnGhostXs }}"
                                    title="Lösch-Schutz: Chips werden stillgelegt, nicht gelöscht — Pläne verweisen auf sie"
                                    data-chip-toggle>{{ $c->is_active ? 'stilllegen' : 'reaktivieren' }}</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="{{ $td }} text-[12px] text-gray-500">Noch keine Chips. Unten anlegen oder den Startsatz übernehmen.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-3 pt-3 border-t border-black/5 space-y-2" data-chips-neu>
        <div class="flex flex-wrap items-end gap-2">
            <label class="block">
                <span class="{{ $label }}">Chip</span>
                <input type="text" wire:model="neu.label" class="{{ $input }} !py-1 w-56" placeholder="z. B. Suppe" data-neu-label />
            </label>
            <label class="block">
                <span class="{{ $label }}">mind. (optional)</span>
                <input type="text" inputmode="numeric" wire:model="neu.default_min" class="{{ $input }} !py-1 w-24 text-right" placeholder="1" data-neu-min />
            </label>
            <label class="block">
                <span class="{{ $label }}">höchstens (optional)</span>
                <input type="text" inputmode="numeric" wire:model="neu.default_max" class="{{ $input }} !py-1 w-24 text-right" placeholder="—" data-neu-max />
            </label>
            <button type="button" wire:click="create" class="{{ $btnPrimary }}" data-chips-anlegen>Anlegen</button>
        </div>
        <div>
            <span class="{{ $label }}">Kriterien (mind. eins, ODER-verknüpft)</span>
            <div class="flex flex-wrap gap-1.5 mt-1" data-neu-kriterien>
                @foreach($diaeten as $key => $dl)
                    <label class="inline-flex items-center gap-1 text-[11px] text-gray-700">
                        <input type="checkbox" value="diaet:{{ $key }}" wire:model="neu.kriterien" /> {{ $dl }}
                    </label>
                @endforeach
            </div>
            @if($hauptgruppen->isNotEmpty())
                <div class="flex flex-wrap gap-1.5 mt-1.5">
                    @foreach($hauptgruppen as $hg)
                        <label class="inline-flex items-center gap-1 text-[11px] text-gray-500">
                            <input type="checkbox" value="hauptgruppe:{{ $hg->id }}" wire:model="neu.kriterien" /> {{ $hg->label }}
                        </label>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    </fieldset>
</div>
