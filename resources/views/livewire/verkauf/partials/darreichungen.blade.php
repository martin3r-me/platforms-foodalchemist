<x-fa::section title="Darreichungen" icon="heroicon-o-squares-2x2"
    description="Je Darreichung: Mengen, Preise und Arbeitszeiten. Der Standard bestimmt die Kennzahlen oben."
    data-vk-darreichungen>
    @php $belegte = $darreichungen->pluck('serving_form_id')->all(); @endphp
    <div class="grid gap-5 lg:grid-cols-[13rem_minmax(0,1fr)]">
        <aside class="flex flex-col gap-2" aria-label="Darreichung auswählen">
            @foreach($darreichungen as $variante)
                <button type="button" wire:key="dar-auswahl-{{ $variante->id }}" wire:click="$set('darAktiv', {{ $variante->id }})"
                    aria-pressed="{{ $darAktiv === $variante->id ? 'true' : 'false' }}"
                    class="w-full text-left p-3 rounded-[var(--fa-radius-control)] border {{ $darAktiv === $variante->id ? 'border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)]' : 'border-[var(--fa-line)] hover:bg-[var(--fa-hover)]' }}">
                    <span class="block font-medium">{{ $variante->name ?: $variante->servingForm?->label }}</span>
                    <span class="block {{ $hinweis }}">@if($variante->name && $variante->name !== $variante->servingForm?->label){{ $variante->servingForm?->label }} · @endif{{ $variante->quantity_per_unit_g !== null ? number_format($variante->quantity_per_unit_g, 0, ',', '.').(($variante->unit_count ?? 1) > 1 ? ' g je Einheit (Altbestand)' : ' g je Portion') : 'Menge fehlt' }}</span>
                    @if($variante->is_standard)<x-fa::badge tone="info" class="mt-2">Standard · Kennzahlen</x-fa::badge>@endif
                </button>
            @endforeach
            <div class="flex flex-col gap-2 mt-2" data-dar-anlegen>
                <x-fa::field label="Weitere Darreichung" for="dar-neue-form">
                    <x-fa::select id="dar-neue-form" wire:model="darNeueForm" aria-label="Servierform für die neue Darreichung">
                        <option value="">Servierform wählen …</option>
                        @foreach($servierformenAlle as $sf)
                            @continue($sf->is_inactive)
                            <option value="{{ $sf->id }}">{{ $sf->label }}</option>
                        @endforeach
                    </x-fa::select>
                </x-fa::field>
                <x-fa::button icon="heroicon-m-plus" wire:click="darreichungNeu">Darreichung hinzufügen</x-fa::button>
            </div>
        </aside>
        <div class="min-w-0">
            @php $d = $darreichungen->firstWhere('id', $darAktiv) ?? $darreichungen->first(); @endphp
            @if($d)
                @php
                    $hatDeltas = $d->deltas->isNotEmpty();
                    $standardmenge = $d->menge_vom_standard && ! $hatDeltas;
                    $deltaMap = $d->deltas->keyBy('recipe_ingredient_id');
                    $fixiert = in_array(($darForm[$d->id]['price_mode'] ?? 'auto'), ['fixed', 'manuell'], true);
                    $darFestFehlt = $fixiert ? \Platform\FoodAlchemist\Livewire\Verkauf\VkModal::darFestpreisFehlt($darForm[$d->id] ?? []) : null;
                    $av = $anrichtenVorschau[$d->id] ?? [];
                    $darMarge = app(\Platform\FoodAlchemist\Services\Preis\MargeService::class)->marge($d->sales_net, $d->ek_portion);
                @endphp
                <x-fa::card wire:key="dar-{{ $d->id }}" class="overflow-hidden flex flex-col" data-dar-editor="{{ $d->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div><h3 class="font-semibold text-[length:var(--fa-text-lg)]">{{ $d->name ?: $d->servingForm?->label }}</h3>@if($d->name && $d->name !== $d->servingForm?->label)<span class="{{ $hinweis }}">{{ $d->servingForm?->label }}</span>@endif</div>
                        @if($d->is_standard)
                            <x-fa::badge tone="info">Standard-Darreichung</x-fa::badge>
                        @else
                            <x-fa::button size="sm" variant="secondary" wire:click="darreichungStandard({{ $d->id }})">Als Standard festlegen</x-fa::button>
                        @endif
                    </div>
                    <section class="border-t border-[var(--fa-line)] p-4 flex flex-col gap-3" aria-label="Portion und Verkauf">
                    <h4 class="font-semibold text-[length:var(--fa-text-lg)]">Portion &amp; Verkauf</h4>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <x-fa::field label="Bezeichnung" for="dar-name-{{ $d->id }}">
                            <x-fa::input id="dar-name-{{ $d->id }}" wire:model.blur="darForm.{{ $d->id }}.name" wire:change="darreichungSpeichern({{ $d->id }})" maxlength="120" placeholder="{{ $d->servingForm?->label }}" />
                        </x-fa::field>
                        <x-fa::field label="Servierform" for="dar-form-{{ $d->id }}">
                            <x-fa::select id="dar-form-{{ $d->id }}" wire:change="darreichungForm({{ $d->id }}, $event.target.value)" data-dar-form="{{ $d->id }}">
                                @foreach($servierformenAlle as $sf)
                                    <option value="{{ $sf->id }}" @selected($sf->id === $d->serving_form_id)>{{ $sf->label }}</option>
                                @endforeach
                            </x-fa::select>
                            @if($d->servingForm?->code === 'unbestimmt')<span class="text-[var(--fa-warn)] {{ $hinweis }}" data-dar-form-waehlen>Servierform wählen</span>@endif
                        </x-fa::field>
                        <x-fa::field label="Gefäß" for="dar-gefaess-{{ $d->id }}">
                            <x-fa::select id="dar-gefaess-{{ $d->id }}" wire:model="darForm.{{ $d->id }}.vessel_gp_id" wire:change="darreichungSpeichern({{ $d->id }})" data-dar-gefaess>
                                <option value="">kein Gefäß</option>
                                @php $typId = $darGefaess[$d->id]['typ_id'] ?? null; $passend = $typId ? $gefaessGps->where('serving_vehicle_vocab_id', $typId) : collect(); @endphp
                                @if($passend->isNotEmpty())<optgroup label="passend zum Typ {{ $darGefaess[$d->id]['typ_name'] ?? '' }}" data-dar-gefaess-passend>@foreach($passend as $gg)<option value="{{ $gg->id }}">{{ $gg->name }}</option>@endforeach</optgroup><optgroup label="weitere">@endif
                                @foreach($gefaessGps->whereNotIn('id', $passend->pluck('id')) as $gg)<option value="{{ $gg->id }}">{{ $gg->name }}</option>@endforeach
                                @if($passend->isNotEmpty())</optgroup>@endif
                                @if(($darForm[$d->id]['vessel_gp_id'] ?? null) && ! $gefaessGps->contains('id', $darForm[$d->id]['vessel_gp_id']))
                                    <option value="{{ $darForm[$d->id]['vessel_gp_id'] }}">{{ $darGefaess[$d->id]['gefaess_name'] ?? 'Gefäß' }}</option>
                                @endif
                            </x-fa::select>
                            @include('foodalchemist::livewire.verkauf.partials.darreichung-gefaess-preis')
                        </x-fa::field>
                        {{-- Geschirr-Empfehlung (19.11.2026, Dominique): neben dem Gefäß, kostet nichts. Gefäß = Ware mit Lieferant und Preis (EK),
                             Empfehlung = Typ aus Einstellungen › Behälter › Servier-Vehikel. Leer = Typ des Gefäßes, sonst keiner. --}}
                        @php $gi = $darGefaess[$d->id] ?? null; @endphp
                        <x-fa::field label="Geschirr-Empfehlung" for="dar-typ-{{ $d->id }}" hint="Kostet nichts, nur Empfehlung">
                            <x-fa::select id="dar-typ-{{ $d->id }}" wire:model="darForm.{{ $d->id }}.serving_vehicle_vocab_id" wire:change="darreichungSpeichern({{ $d->id }})" aria-label="Geschirr-Empfehlung" data-dar-typ :data-dar-typ-aus-gefaess="($gi && ! $gi['uebersteuert'] && $gi['typ_name'] && $gi['gefaess_id']) ? true : null">
                                <option value="">{{ ($gi['gefaess_typ_name'] ?? null) ? 'wie Gefäß: '.$gi['gefaess_typ_name'] : 'keine' }}</option>
                                @foreach($vehikel as $v)<option value="{{ $v->id }}" @if($v->is_inactive && ($darForm[$d->id]['serving_vehicle_vocab_id'] ?? null) != $v->id) hidden @endif>{{ $v->name }}</option>@endforeach
                            </x-fa::select>
                            @if($vehikel->isEmpty())<span class="{{ $hinweis }}" data-dar-typ-leer>Keine Liste: Einstellungen › Behälter › Servier-Vehikel</span>@endif
                        </x-fa::field>
                        {{-- Plan 98 B: die Grammatur ist nur lesend und nennt ihre Herkunft (Modell 23.11.: Gericht = eine Portion) --}}
                        <x-fa::field label="Portionsmenge" :hint="$standardmenge ? ($d->is_standard ? 'Summe der Bestandteile' : 'Aus der Standard-Darreichung') : 'Summe der Komponenten unten'">
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 min-h-9" data-dar-portionsmenge>
                                <strong class="tabular-nums">{{ $d->quantity_per_unit_g !== null ? number_format($d->quantity_per_unit_g * ($d->unit_count ?: 1), 1, ',', '.') . ' g' : 'Menge fehlt' }}</strong>
                                <x-fa::checkbox class="inline-flex items-center gap-2 text-[length:var(--fa-text-sm)]" :checked="$standardmenge" wire:change="darStandardmenge({{ $d->id }}, $event.target.checked)" data-dar-standardmenge>Standardmenge verwenden</x-fa::checkbox>
                                @unless($d->is_standard)
                                    {{-- Plan 98 B, Schritt 5: alle Mengen auf einen Anteil der Standard-Darreichung (je Komponente ein Delta) --}}
                                    <span class="inline-flex items-center gap-2" data-dar-anteil>
                                        <x-fa::input size="sm" numeric class="w-20" inputmode="decimal" wire:model="darAnteilForm.{{ $d->id }}" wire:keydown.enter="darAnteil({{ $d->id }})" aria-label="Anteil der Standard-Darreichung in Prozent" placeholder="%" />
                                        <x-fa::button size="sm" variant="secondary" wire:click="darAnteil({{ $d->id }})">Alle Mengen auf % vom Standard</x-fa::button>
                                    </span>
                                @endunless
                            </div>
                        </x-fa::field>
                        <x-fa::field label="Preisklasse" for="dar-klasse-{{ $d->id }}">
                            <x-fa::select id="dar-klasse-{{ $d->id }}" wire:model="darForm.{{ $d->id }}.markup_class_id" wire:change="darreichungSpeichern({{ $d->id }})">
                                <option value="">keine</option>
                                @foreach($aufschlagsklassen as $ak)<option value="{{ $ak->id }}">{{ $ak->code }}</option>@endforeach
                            </x-fa::select>
                        </x-fa::field>
                        <x-fa::field label="Preis">
                            <div class="flex flex-wrap gap-2">
                                <x-fa::button size="sm" :variant="!$fixiert ? 'primary' : 'secondary'" wire:click="darreichungPreisModusGeaendert({{ $d->id }}, 'auto')" aria-pressed="{{ $fixiert ? 'false' : 'true' }}">automatisch</x-fa::button>
                                <x-fa::button size="sm" :variant="$fixiert ? 'primary' : 'secondary'" wire:click="darreichungPreisModusGeaendert({{ $d->id }}, 'fixed')" aria-pressed="{{ $fixiert ? 'true' : 'false' }}">fest</x-fa::button>
                            </div>
                        </x-fa::field>
                        <x-fa::field label="MwSt" for="dar-mwst-{{ $d->id }}">
                            <x-fa::select id="dar-mwst-{{ $d->id }}" wire:model="darForm.{{ $d->id }}.vat_profile_key" wire:change="darreichungSpeichern({{ $d->id }})">
                                <option value="">aus Preisklasse{{ isset($darMwstVorgabe[$d->id]) ? ' (' . (['regulaer' => 'regulär', 'ermaessigt' => 'ermäßigt'][$darMwstVorgabe[$d->id]] ?? $darMwstVorgabe[$d->id]) . ')' : '' }}</option><option value="ermaessigt">ermäßigt</option><option value="regulaer">regulär</option>
                            </x-fa::select>
                        </x-fa::field>
                        @if($fixiert)
                            <x-fa::field label="Fester VK netto" for="dar-festpreis-{{ $d->id }}">
                                <x-fa::input id="dar-festpreis-{{ $d->id }}" inputmode="decimal" wire:model.blur="darForm.{{ $d->id }}.sales_net" wire:change="darreichungFestpreisFeld({{ $d->id }})" numeric />
                            </x-fa::field>
                            <x-fa::field label="Begründung" for="dar-grund-{{ $d->id }}" class="sm:col-span-2">
                                <x-fa::input id="dar-grund-{{ $d->id }}" wire:model.blur="darForm.{{ $d->id }}.price_override_reason" wire:change="darreichungFestpreisFeld({{ $d->id }})" />
                                @if($darFestFehlt)<span class="text-[var(--fa-warn)]" data-dar-fest-fehlt>{{ $darFestFehlt }}</span>@endif
                            </x-fa::field>
                        @endif
                    </div>
                    @if(isset($darGewichtHinweis[$d->id]))<x-fa::notice tone="warn" data-dar-ohne-gewicht>{{ $darGewichtHinweis[$d->id] }}</x-fa::notice>@endif
                    @if($darChargeHinweis)<x-fa::notice tone="warn" data-dar-charge>{{ $darChargeHinweis }}: Die Portion liegt über 3000 g, deshalb gibt es keinen Auto-VK. Mengen je Portion korrigieren oder den VK bewusst festsetzen.</x-fa::notice>@endif
                    @if(($d->unit_count ?? 1) > 1)<x-fa::notice tone="warn">Altbestand mit abweichender Zählweise. Mengen und Zeiten bleiben bis zu einer geprüften Umstellung erhalten.</x-fa::notice>@endif
                    @include('foodalchemist::livewire.verkauf.partials.darreichung-gefaess')
                    {{-- 20.11.2026 (Dominique): die Kennzahlen der Standard-Darreichung stehen schon oben im Kopf — hier nur bei einer anderen
                         Darreichung, deren Werte davon abweichen, und kompakt in einer Zeile --}}
                    @unless($d->is_standard)
                        <p class="flex flex-wrap gap-x-4 gap-y-1 border-y border-[var(--fa-line)] py-2 fa-t-muted tabular-nums" aria-label="Kennzahlen dieser Darreichung" data-dar-kennzahlen>
                            <span>Diese Darreichung: EK <x-fa::money :value="$d->ek_portion" /></span>
                            <span>VK netto <x-fa::money :value="$d->sales_net" /></span>
                            <span>Wareneinsatz {{ isset($darMarge['wareneinsatz_pct']) ? number_format($darMarge['wareneinsatz_pct'], 1, ',', '.') . ' %' : 'fehlt' }}</span>
                        </p>
                    @endunless
                    </section>
                    @include('foodalchemist::livewire.verkauf.partials.darreichung-komponenten')
                    @include('foodalchemist::livewire.verkauf.partials.darreichung-zeiten')
                    <div class="px-5 py-4">
                    @include('foodalchemist::livewire.verkauf.partials.darreichung-zubehoer')
                    </div>
                    @unless($d->is_standard)
                        <div class="border-t border-[var(--fa-line)] p-5"><x-fa::button size="sm" variant="danger" icon="heroicon-o-trash" wire:click="darreichungLoeschen({{ $d->id }})" wire:confirm="Diese Darreichung löschen?">Darreichung löschen</x-fa::button></div>
                    @endunless
                </x-fa::card>
            @else
                <x-fa::empty compact title="Noch keine Darreichung">Beim Speichern entsteht automatisch die Standard-Form.</x-fa::empty>
            @endif
        </div>
    </div>
</x-fa::section>
