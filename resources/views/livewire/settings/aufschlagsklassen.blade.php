{{-- Preisklassen: relative Faktoren auf den dynamischen Unternehmens-Basissatz.
     VK netto = Wareneinsatz je Portion × Basissatz × Klassenfaktor (CatalogPricingService::catalogPrice), danach Rundung. --}}
@php
    $rundungsLabels = ['kaufmaennisch' => 'kaufmännisch', 'auf' => 'immer auf', 'ab' => 'immer ab', 'next_050' => 'auf x,50', 'next_090' => 'auf x,90'];
    $rundungsWahl = ['' => 'Wie Team', 'kaufmaennisch' => 'Kaufmännisch', 'auf' => 'Immer auf', 'ab' => 'Immer ab', 'next_050' => 'Auf x,50', 'next_090' => 'Auf x,90'];
    $mwstLabels = ['ermaessigt' => 'ermäßigt', 'regulaer' => 'regulär'];
    $mwstWahl = ['' => 'Wie Team', 'ermaessigt' => 'Ermäßigt', 'regulaer' => 'Regulär'];
    $basisFaktor = $base['factor'] ?? null;
    $basisQuelle = $base['source'] ?? null;
@endphp

<div class="flex flex-col gap-4" data-settings-aufschlagsklassen>
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei --}}
    @php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    @if($fehler !== null)
        <x-fa::notice tone="crit" data-ak-fehler>{{ $fehler }}</x-fa::notice>
    @endif

    {{-- Ebene 2: Basissatz-Vorschau je Betrieb (lokal, verändert nicht die globale Betriebsbrille) --}}
    @if(count($betriebeOptionen) > 0)
        <div class="fa-surface p-3 flex flex-wrap items-end gap-x-4 gap-y-2">
            <x-fa::field label="Basissatz anzeigen für" for="ak-betrieb" class="w-72 max-w-full">
                <x-fa::select id="ak-betrieb" wire:model.live="outletId">
                    <option value="">Team-Standard</option>
                    @foreach($betriebeOptionen as $o)
                        <option value="{{ $o['id'] }}">Betrieb: {{ $o['name'] }}</option>
                    @endforeach
                </x-fa::select>
            </x-fa::field>
            <p class="flex-1 min-w-[16rem] pb-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                @if($scopeOutletName)
                    Basissatz und Gesamtfaktor gelten für {{ $scopeOutletName }}. Klassenfaktoren, Steuer und Rundung gelten teamweit.
                @else
                    Betrieb wählen, um den Basissatz aus dessen Fixkosten und Marge zu sehen.
                @endif
            </p>
        </div>
    @endif

    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    {{-- Basissatz + Formel --}}
    <x-fa::section title="Unternehmens-Basissatz" icon="heroicon-o-scale" :meta="$scopeOutletName"
        description="Errechnet aus Monatsbasen, Gemeinkosten und Marge unter „Herstellkosten & Zuschläge“. Preisklassen verändern diesen Satz nur relativ.">
        @if($basisFaktor === null)
            <x-fa::notice tone="warn" title="Basissatz fehlt">
                Ohne Basissatz rechnet der Food.Alchemist keinen Verkaufspreis. Trage unter „Herstellkosten &amp; Zuschläge“ die Monatsbasen oder eine Ziel-Wareneinsatzquote ein.
            </x-fa::notice>
        @else
            <x-fa::kpis :items="[
                ['label' => 'Basissatz', 'value' => number_format($basisFaktor, 3, ',', '.'), 'primary' => true, 'kpi' => 'basissatz'],
                ['label' => 'Grundlage', 'value' => $basisQuelle === 'kostenstruktur' ? 'Kostenstruktur' : 'Ziel-Wareneinsatz', 'tone' => $basisQuelle === 'kostenstruktur' ? 'ok' : 'warn', 'title' => $basisQuelle === 'kostenstruktur' ? 'Aus Monatsbasen, Gemeinkosten und Marge.' : 'Monatsbasen unvollständig: Basissatz aus der Ziel-Wareneinsatzquote abgeleitet.'],
            ]" />
        @endif
        <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] border border-[var(--fa-line)] px-3 py-2.5 flex flex-col gap-1">
            <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">So entsteht der Verkaufspreis</p>
            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">VK netto = Wareneinsatz je Portion × Basissatz × Klassenfaktor</p>
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                Danach wird gerundet und die Mehrwertsteuer aufgeschlagen. Der Gesamtfaktor in der Tabelle ist Basissatz × Klassenfaktor.
                @if($basisFaktor !== null)
                    Beispiel Klassenfaktor 100 %: 3,00 € Wareneinsatz ergeben {{ number_format(3 * $basisFaktor, 2, ',', '.') }} € netto vor Rundung.
                @endif
            </p>
        </div>
    </x-fa::section>

    {{-- Preisklassen-Tabelle --}}
    <x-fa::section title="Preisklassen" icon="heroicon-o-tag"
        description="Die Standard-Preisklasse gilt für jedes Rezept ohne eigene Preisklasse. Klassen mit Rezepten lassen sich nur deaktivieren, nicht löschen.">
        <div class="overflow-x-auto -mx-4 px-4">
            <table class="fa-table min-w-[860px]" data-ak-tabelle>
                <thead><tr>
                    <th class="w-16 text-center">Standard</th>
                    <th>Code</th>
                    <th>Preisklasse</th>
                    <th class="num">Klassenfaktor</th>
                    <th class="num">Gesamtfaktor</th>
                    <th>MwSt</th>
                    <th>Rundung</th>
                    <th class="num">Rezepte</th>
                    <th><span class="sr-only">Aktionen</span></th>
                </tr></thead>
                <tbody>
                    @foreach($klassen as $ak)
                        @php
                            $anzahl = (int) ($zaehler[$ak->id] ?? 0);
                            $factor = (float) ($ak->class_factor_pct ?? 100);
                            $eigen = \Platform\FoodAlchemist\Support\TeamScope::owns($ak->team_id, $team);
                        @endphp
                        @if($editId === $ak->id)
                            <tr wire:key="ak-{{ $ak->id }}" aria-selected="true">
                                <td class="text-center"><input type="radio" name="standard-preisklasse" @checked($standardId === $ak->id) wire:click="setDefault({{ $ak->id }})" title="Als Standard-Preisklasse verwenden" aria-label="Als Standard-Preisklasse verwenden" class="accent-[var(--fa-accent)]" data-standard-preisklasse="{{ $ak->id }}" /></td>
                                <td class="font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $ak->code }}</td>
                                <td colspan="7">
                                    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,14rem),1fr))] gap-3 py-1">
                                        <x-fa::field label="Bezeichnung" for="ak-form-label">
                                            <x-fa::input id="ak-form-label" wire:model="form.label" />
                                        </x-fa::field>
                                        <x-fa::field label="Klassenfaktor" for="ak-form-faktor" hint="100 % = Basissatz unverändert.">
                                            <div class="flex items-center gap-1.5">
                                                <x-fa::input id="ak-form-faktor" wire:model="form.class_factor_pct" numeric class="w-24" />
                                                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">%</span>
                                            </div>
                                        </x-fa::field>
                                        <x-fa::field label="Nachkommastellen" for="ak-form-stellen" hint="Leer lassen = wie Team.">
                                            <x-fa::input id="ak-form-stellen" type="number" min="0" max="4" wire:model="form.rounding_decimals" placeholder="wie Team" numeric class="w-28" />
                                        </x-fa::field>
                                    </div>
                                    <div class="flex flex-wrap gap-x-6 gap-y-3 py-2">
                                        <x-fa::choice name="form.vat_profile_key" :live="false" label="Mehrwertsteuer" :options="$mwstWahl" />
                                        <x-fa::choice name="form.rounding_mode" :live="false" label="Art der Rundung" :options="$rundungsWahl" />
                                    </div>
                                    <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Gesamtfaktor wird nach dem Speichern berechnet. {{ $anzahl }} {{ $anzahl === 1 ? 'Rezept nutzt' : 'Rezepte nutzen' }} diese Klasse.</span>
                                        <div class="flex items-center gap-2">
                                            <x-fa::button variant="ghost" size="sm" wire:click="cancel">Abbrechen</x-fa::button>
                                            <x-fa::button variant="primary" size="sm" icon="heroicon-m-check" wire:click="save">Preisklasse speichern</x-fa::button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @else
                            <tr wire:key="ak-{{ $ak->id }}" class="{{ $ak->is_inactive ? 'opacity-60' : '' }}">
                                <td class="text-center"><input type="radio" name="standard-preisklasse" @checked($standardId === $ak->id) @disabled($ak->is_inactive) wire:click="setDefault({{ $ak->id }})" title="Als Standard-Preisklasse verwenden" aria-label="{{ $ak->label }} als Standard-Preisklasse verwenden" class="accent-[var(--fa-accent)]" data-standard-preisklasse="{{ $ak->id }}" /></td>
                                <td class="font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $ak->code }}</td>
                                <td>
                                    <span class="font-medium">{{ $ak->label }}</span>
                                    @if($standardId === $ak->id)<x-fa::badge tone="accent" class="ml-1.5">Standard</x-fa::badge>@endif
                                    @if($ak->is_inactive)<x-fa::badge class="ml-1.5">Inaktiv</x-fa::badge>@endif
                                </td>
                                <td class="num">{{ number_format($factor, 1, ',', '.') }}&nbsp;%</td>
                                <td class="num font-medium">
                                    @if($basisFaktor !== null)
                                        {{ number_format($basisFaktor * $factor / 100, 3, ',', '.') }}
                                    @else
                                        <x-fa::signal tone="warn">Basissatz fehlt</x-fa::signal>
                                    @endif
                                </td>
                                <td class="text-[var(--fa-ink-2)]">{{ $ak->vat_profile_key ? ($mwstLabels[$ak->vat_profile_key] ?? $ak->vat_profile_key) : 'wie Team' }}</td>
                                <td class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] whitespace-nowrap">
                                    @if($ak->rounding_decimals === null && ! $ak->rounding_mode)
                                        wie Team
                                    @else
                                        {{ $ak->rounding_decimals !== null ? $ak->rounding_decimals . ' Stellen' : 'Stellen wie Team' }}{{ $ak->rounding_mode ? ', ' . ($rundungsLabels[$ak->rounding_mode] ?? $ak->rounding_mode) : '' }}
                                    @endif
                                </td>
                                <td class="num text-[var(--fa-ink-2)]">{{ $anzahl }}</td>
                                <td class="whitespace-nowrap text-right">
                                    @if($eigen)
                                        <div class="inline-flex items-center gap-1">
                                            <x-fa::button variant="ghost" size="sm" icon="heroicon-m-pencil-square" wire:click="edit({{ $ak->id }})">Bearbeiten</x-fa::button>
                                            <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                                <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" size="sm" label="Weitere Aktionen für {{ $ak->label }}" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                                <div class="hidden w-52 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="toggleInactive({{ $ak->id }})"
                                                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]">
                                                        @svg($ak->is_inactive ? 'heroicon-o-play' : 'heroicon-o-pause', 'w-4 h-4 text-[var(--fa-ink-3)]'){{ $ak->is_inactive ? 'Preisklasse aktivieren' : 'Preisklasse deaktivieren' }}
                                                    </button>
                                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="delete({{ $ak->id }})" wire:confirm="Diese Preisklasse löschen?" @disabled($anzahl > 0)
                                                        title="{{ $anzahl > 0 ? 'Wird von Rezepten genutzt, nur deaktivieren möglich.' : '' }}"
                                                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)] disabled:opacity-40 disabled:pointer-events-none">
                                                        @svg('heroicon-o-trash', 'w-4 h-4')Preisklasse löschen
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <x-fa::badge>{{ $ak->team_id === null ? 'Vorgabe' : 'Geerbt' }}</x-fa::badge>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($klassen->isEmpty())
            <x-fa::empty icon="heroicon-o-tag" title="Noch keine Preisklassen" compact>
                Ohne Preisklasse rechnet jedes Rezept mit dem Basissatz unverändert (100 %). Lege unten die erste Klasse an.
            </x-fa::empty>
        @endif
    </x-fa::section>

    {{-- Neue Preisklasse --}}
    <x-fa::section title="Neue Preisklasse" icon="heroicon-o-plus-circle" data-ak-anlegen>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))] gap-3 items-start">
            <x-fa::field label="Code" for="ak-neu-code" required hint="Kurz und eindeutig, z. B. PREMIUM.">
                <x-fa::input id="ak-neu-code" wire:model="neu.code" class="font-mono" />
            </x-fa::field>
            <x-fa::field label="Bezeichnung" for="ak-neu-label" required>
                <x-fa::input id="ak-neu-label" wire:model="neu.label" placeholder="z. B. Premium-Bankett" />
            </x-fa::field>
            <x-fa::field label="Klassenfaktor" for="ak-neu-faktor" hint="100 % = Basissatz unverändert, 120 % = 20 % teurer.">
                <div class="flex items-center gap-1.5">
                    <x-fa::input id="ak-neu-faktor" wire:model="neu.class_factor_pct" numeric class="w-24" />
                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">%</span>
                </div>
            </x-fa::field>
        </div>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <x-fa::choice name="neu.vat_profile_key" :live="false" label="Mehrwertsteuer" :options="$mwstWahl" />
            <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="create">Preisklasse anlegen</x-fa::button>
        </div>
    </x-fa::section>
    </fieldset>
</div>
