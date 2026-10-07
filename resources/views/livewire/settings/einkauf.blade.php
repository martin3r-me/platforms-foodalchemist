{{-- M1-05: Lead-LA-Strategie (V-27), M1-06: Stamm-Lieferanten-Matrix, dazu Lagerorte (WaWi light) --}}
@php
    // Sichtbare Beschreibungen ohne interne Kürzel; Fallback auf die Enum-Beschreibung.
    $strategieText = [
        'guenstigster_preis' => 'Der Artikel mit dem niedrigsten Vergleichspreis liefert den Preis.',
        'stamm_lieferant' => 'Artikel deiner Stamm-Lieferanten (je Warengruppe, siehe unten) haben Vorrang. Innerhalb derselben Stufe entscheidet der Preis.',
        'prioritaets_kette' => 'Eine feste Reihenfolge deiner Lieferanten entscheidet. Innerhalb derselben Stufe entscheidet der Preis.',
    ];
@endphp

<div class="flex flex-col gap-4">
    {{-- Die Leiste speichert die Strategie; Lagerorte und Stamm-Lieferanten speichern je Zeile. --}}
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team; mehrere Abschnitts-Speichern → Bearbeiten/Fertig --}}
    @php $sperrLesen = in_array($sperr['modus'], ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true, 'hint' => 'Strategie, Bestellversand, Lagerorte und Stamm-Lieferanten.'])
    @unless($sperrLesen)
    <x-foodalchemist::save-bar :meldung="$meldung" hint="Speichert die Strategie für den kalkulierenden Artikel." />
    @endunless
    @if($sperrLesen && $meldung)<x-fa::notice tone="ok" data-save-bar-meldung>{{ $meldung }}</x-fa::notice>@endif
    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    @if($fehler)
        <x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>
    @endif

    <x-fa::section title="Welcher Artikel kalkuliert" icon="heroicon-o-shopping-cart" data-einkauf-strategie
        description="Legt fest, welcher Lieferantenartikel je Grundprodukt den Preis für die Kalkulation liefert. Gilt nur für dein Team.">
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,16rem),1fr))] gap-2" role="radiogroup" aria-label="Strategie">
            @foreach($strategien as $s)
                @php
                    $gewaehlt = $strategie === $s->value;
                @endphp
                <label class="flex items-start gap-3 p-3 rounded-[var(--fa-radius-control)] border cursor-pointer transition-colors duration-150 {{ $gewaehlt ? 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)]' : 'border-[var(--fa-line)] hover:bg-[var(--fa-hover)]' }}">
                    <input type="radio" wire:model.live="strategie" value="{{ $s->value }}" class="mt-0.5 w-4 h-4 accent-[var(--fa-accent)]" />
                    <span class="min-w-0">
                        <span class="block text-[length:var(--fa-text-md)] font-medium {{ $gewaehlt ? 'text-[var(--fa-accent)]' : 'text-[var(--fa-ink)]' }}">{{ $s->label() }}</span>
                        <span class="block mt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $strategieText[$s->value] ?? $s->description() }}</span>
                    </span>
                </label>
            @endforeach
        </div>

        @if($strategie === 'prioritaets_kette')
            <div class="pt-3 border-t border-[var(--fa-line)] flex flex-col gap-2" data-prio-kette>
                <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Reihenfolge der Lieferanten <span class="font-normal text-[var(--fa-ink-3)]">(oben hat Vorrang)</span></p>
                @forelse($prioritaeten as $i => $supplierId)
                    <div class="flex items-center gap-2 py-1 border-b border-[var(--fa-line)]" wire:key="prio-{{ $supplierId }}">
                        <span class="w-6 text-right tabular-nums text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $i + 1 }}.</span>
                        <span class="flex-1 min-w-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $lieferantenNamen[$supplierId] ?? 'Lieferant nicht mehr verfügbar' }}</span>
                        <x-fa::icon-button icon="heroicon-m-chevron-up" size="sm" label="Nach oben" wire:click="prioHoch({{ $i }})" :disabled="$i === 0" class="disabled:opacity-40 disabled:pointer-events-none" />
                        <x-fa::icon-button icon="heroicon-m-x-mark" size="sm" tone="danger" label="Aus der Reihenfolge entfernen" wire:click="prioEntfernen({{ $i }})" />
                    </div>
                @empty
                    <x-fa::empty icon="heroicon-o-queue-list" title="Noch keine Lieferanten in der Reihenfolge" compact>Wähle unten den Lieferanten, der Vorrang haben soll.</x-fa::empty>
                @endforelse
                <div class="flex flex-wrap items-end gap-2">
                    <x-fa::field label="Lieferant hinzufügen" for="einkauf-prio-neu" class="w-72 max-w-full">
                        <x-fa::select id="einkauf-prio-neu" wire:model="neuerPrioLieferant" placeholder="Lieferant wählen">
                            @foreach($lieferanten as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                    <x-fa::button icon="heroicon-m-plus" wire:click="prioHinzu">Zur Reihenfolge hinzufügen</x-fa::button>
                </div>
            </div>
        @endif

        {{-- Phase 3: Strategie je Warengruppe (überschreibt die globale oben) --}}
        <div class="pt-3 border-t border-[var(--fa-line)] flex flex-col gap-2" data-strategie-per-wg>
            <div>
                <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Abweichend je Warengruppe <span class="font-normal text-[var(--fa-ink-3)]">(optional)</span></p>
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Ohne Auswahl gilt die Strategie oben.</p>
            </div>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,22rem),1fr))] gap-x-8">
                @foreach($warengruppen as $wg)
                    <div class="flex items-center gap-3 py-1.5 border-b border-[var(--fa-line)]" wire:key="strat-wg-{{ $wg->code }}">
                        <label for="einkauf-wg-{{ $wg->code }}" class="flex-1 min-w-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">{{ $wg->name }}</label>
                        <x-fa::select id="einkauf-wg-{{ $wg->code }}" wire:model="strategiePerWg.{{ $wg->code }}" size="sm" class="w-52 shrink-0" placeholder="Wie oben">
                            @foreach($strategien as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
                        </x-fa::select>
                    </div>
                @endforeach
            </div>
        </div>

        <label class="flex items-start gap-2 pt-3 border-t border-[var(--fa-line)] cursor-pointer">
            <input type="checkbox" wire:model="ausweichKette" class="mt-0.5 w-4 h-4 accent-[var(--fa-accent)]" />
            <span class="min-w-0">
                <span class="block text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">Ausweich-Reihenfolge anzeigen</span>
                <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Im Grundprodukt sichtbar: welcher Artikel übernimmt, wenn der aktuelle ausfällt.</span>
            </span>
        </label>
    </x-fa::section>

    {{-- Spec 63: Bestellversand per Mail --}}
    <x-fa::section id="bestellversand" title="Bestellversand" icon="heroicon-o-paper-airplane" class="scroll-mt-6" data-bestellversand
        description="Wie eine Bestellung beim Absenden zum Lieferanten kommt.">
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,16rem),1fr))] gap-2" role="radiogroup" aria-label="Versandart">
            @foreach([
                'mailprogramm' => ['Mailprogramm', 'Absenden öffnet dein E-Mail-Programm mit vorbereitetem Text. Du verschickst selbst.'],
                'server' => ['Direkt per E-Mail', 'Absenden schickt die Bestellung mit PDF direkt an den Lieferanten. Stornos ebenso. Jeder Versand wird protokolliert.'],
            ] as $wert => [$label, $text])
                @php
                    $gewaehlt = ($versand['art'] ?? 'mailprogramm') === $wert;
                @endphp
                <label class="flex items-start gap-3 p-3 rounded-[var(--fa-radius-control)] border cursor-pointer transition-colors duration-150 {{ $gewaehlt ? 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)]' : 'border-[var(--fa-line)] hover:bg-[var(--fa-hover)]' }}">
                    <input type="radio" wire:model.live="versand.art" value="{{ $wert }}" class="mt-0.5 w-4 h-4 accent-[var(--fa-accent)]" />
                    <span class="min-w-0">
                        <span class="block text-[length:var(--fa-text-md)] font-medium {{ $gewaehlt ? 'text-[var(--fa-accent)]' : 'text-[var(--fa-ink)]' }}">{{ $label }}</span>
                        <span class="block mt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $text }}</span>
                    </span>
                </label>
            @endforeach
        </div>

        @if(($versand['art'] ?? '') === 'server')
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <x-fa::field label="Absender-Name" for="versand-absender" optional>
                    <x-fa::input id="versand-absender" wire:model="versand.absender_name" placeholder="{{ $team->name }} Einkauf" />
                </x-fa::field>
                <x-fa::field label="Antwort an" for="versand-antwort" optional>
                    <x-fa::input id="versand-antwort" type="email" wire:model="versand.antwort_an" placeholder="leer = E-Mail der Person, die absendet" />
                </x-fa::field>
                <x-fa::field label="Kopie an (BCC)" for="versand-kopie" optional class="sm:col-span-2">
                    <x-fa::input id="versand-kopie" wire:model="versand.kopie_an" placeholder="einkauf@betrieb.de, kueche@betrieb.de" />
                </x-fa::field>
                <x-fa::field label="Signatur" for="versand-signatur" optional class="sm:col-span-2">
                    <x-fa::textarea id="versand-signatur" wire:model="versand.signatur" placeholder="Mit freundlichen Grüßen" />
                </x-fa::field>
            </div>
            <p class="mt-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Die Bestell-E-Mail steht beim Lieferanten. Fehlt sie, lässt sich die Bestellung nicht absenden.</p>

            <div class="mt-5 grid gap-4 lg:grid-cols-2" data-bestellversand-vorlagen>
                @foreach(['bestellung' => 'Vorlage Bestellung', 'storno' => 'Vorlage Storno'] as $typ => $titel)
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[length:var(--fa-text-md)] font-medium">{{ $titel }}</span>
                            <x-fa::button size="sm" variant="ghost" wire:click="vorlageStandardEinsetzen('{{ $typ }}')">Standardtext einsetzen</x-fa::button>
                        </div>
                        <x-fa::input wire:model="versand.betreff_{{ $typ }}" placeholder="Betreff — leer = Standard" aria-label="{{ $titel }}: Betreff" />
                        <x-fa::textarea rows="8" wire:model="versand.text_{{ $typ }}" placeholder="Text — leer = Standard" aria-label="{{ $titel }}: Text" class="font-mono" />
                    </div>
                @endforeach
                <p class="lg:col-span-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                    Platzhalter: @foreach(\Platform\FoodAlchemist\Services\OrderMailService::PLATZHALTER as $ph)<code class="font-mono">{{ $ph }}</code>@if(! $loop->last) · @endif @endforeach.
                    Die Signatur wird angehängt.
                </p>
            </div>
        @endif

        <div class="mt-4">
            <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="bestellversandSpeichern">Bestellversand speichern</x-fa::button>
        </div>
    </x-fa::section>

    <x-fa::section id="lagerorte" title="Lagerorte" icon="heroicon-o-archive-box" class="scroll-mt-6" data-lagerorte
        description="Wareneingänge buchen auf das Standardlager. Weitere Lagerorte sind die Grundlage für Umlagerung, Inventur und Produktion.">
        <div class="flex flex-wrap items-end gap-3">
            <x-fa::field label="Name" for="lager-neu-name" required class="flex-1 min-w-[12rem]">
                <x-fa::input id="lager-neu-name" wire:model="lagerNeu.name" placeholder="z. B. Hauptlager" />
            </x-fa::field>
            <x-fa::field label="Kürzel" for="lager-neu-code" class="w-32">
                <x-fa::input id="lager-neu-code" wire:model="lagerNeu.code" placeholder="MAIN" class="font-mono" />
            </x-fa::field>
            <x-fa::field label="Art" for="lager-neu-typ" class="w-44">
                <x-fa::select id="lager-neu-typ" wire:model="lagerNeu.type" :options="$lagerTypen" />
            </x-fa::field>
            <x-fa::field label="Notiz" for="lager-neu-notiz" optional class="flex-1 min-w-[12rem]">
                <x-fa::input id="lager-neu-notiz" wire:model="lagerNeu.note" />
            </x-fa::field>
            <x-fa::button icon="heroicon-m-plus" wire:click="lagerAnlegen">Lager anlegen</x-fa::button>
        </div>

        @if($lagerorte->isEmpty())
            <x-fa::empty icon="heroicon-o-archive-box" title="Noch keine Lagerorte angelegt" compact>
                Beim ersten Wareneingang entsteht automatisch ein Hauptlager. Du kannst es auch oben selbst anlegen.
            </x-fa::empty>
        @else
            <div class="overflow-x-auto -mx-4 px-4">
                <table class="fa-table min-w-[900px]">
                    <thead><tr>
                        <th>Lager</th>
                        <th>Kürzel</th>
                        <th>Art</th>
                        <th>Notiz</th>
                        <th class="text-center">Aktiv</th>
                        <th class="num">Bestandsposten</th>
                        <th><span class="sr-only">Aktionen</span></th>
                    </tr></thead>
                    <tbody>
                        @foreach($lagerorte as $lager)
                            <tr wire:key="lagerort-{{ $lager->id }}">
                                <td class="min-w-48">
                                    <div class="flex items-center gap-2">
                                        <x-fa::input wire:model="lagerEdit.{{ $lager->id }}.name" size="sm" aria-label="Name" />
                                        @if($lager->is_default)<x-fa::badge tone="ok">Standard</x-fa::badge>@endif
                                    </div>
                                </td>
                                <td class="min-w-28"><x-fa::input wire:model="lagerEdit.{{ $lager->id }}.code" size="sm" aria-label="Kürzel" class="font-mono" /></td>
                                <td class="min-w-36"><x-fa::select wire:model="lagerEdit.{{ $lager->id }}.type" size="sm" aria-label="Art" :options="$lagerTypen" /></td>
                                <td class="min-w-56"><x-fa::input wire:model="lagerEdit.{{ $lager->id }}.note" size="sm" aria-label="Notiz" /></td>
                                <td class="text-center"><input type="checkbox" wire:model="lagerEdit.{{ $lager->id }}.is_active" aria-label="Aktiv" class="w-4 h-4 accent-[var(--fa-accent)]" /></td>
                                <td class="num text-[var(--fa-ink-2)]">{{ $lager->stocks_count }}</td>
                                <td class="whitespace-nowrap text-right">
                                    <div class="inline-flex items-center gap-1">
                                        <x-fa::button size="sm" wire:click="lagerSpeichern({{ $lager->id }})">Speichern</x-fa::button>
                                        <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                            <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" size="sm" label="Weitere Aktionen für {{ $lager->name }}" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                            <div class="hidden w-56 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                                @unless($lager->is_default)
                                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="lagerStandardSetzen({{ $lager->id }})"
                                                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]">
                                                        @svg('heroicon-o-star', 'w-4 h-4 text-[var(--fa-ink-3)]')Als Standardlager setzen
                                                    </button>
                                                @endunless
                                                <button type="button" role="menuitem" x-on:click="offen = false" wire:click="lagerEntfernen({{ $lager->id }})" wire:confirm="Lagerort entfernen? Mit Bestand wird er nur deaktiviert."
                                                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]">
                                                    @svg('heroicon-o-trash', 'w-4 h-4')Lagerort entfernen
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-fa::section>

    {{-- M1-06: Stamm-Lieferanten-Matrix (Lieferant × Warengruppe) --}}
    <x-fa::section title="Stamm-Lieferanten" icon="heroicon-o-truck" data-stamm-matrix
        description="Je Warengruppe oder für alle. Wirkt bei der Strategie „Stamm-Lieferant zuerst“. Vom übergeordneten Team geerbte Einträge sind fest und hier nicht änderbar. Änderungen wirken auf neue Wahlen; bestehende Leads bleiben, bis du sie über „Leads neu wählen“ übernimmst.">
        <div class="flex flex-col">
            @foreach(collect([['', 'Alle Warengruppen']])->concat($warengruppen->map(fn ($wg) => [$wg->code, $wg->name])) as [$code, $titel])
                <div wire:key="stamm-zeile-{{ $code ?: 'global' }}" class="flex flex-wrap items-center gap-x-3 gap-y-1.5 py-2 border-t border-[var(--fa-line)] first:border-t-0">
                    <span class="w-64 max-w-full shrink-0 text-[length:var(--fa-text-md)] {{ $code === '' ? 'font-medium text-[var(--fa-ink)]' : 'text-[var(--fa-ink-2)]' }}">
                        @if($code !== '')<span class="mr-1 tabular-nums text-[var(--fa-ink-3)]">{{ $code }}</span>@endif{{ $titel }}
                    </span>
                    <div class="flex-1 min-w-0 flex flex-wrap items-center gap-1.5">
                        @foreach($matrix->get($code, collect()) as $eintrag)
                            @php
                                $eigen = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $eintrag);
                            @endphp
                            <span wire:key="stamm-{{ $eintrag->id }}" class="inline-flex items-center gap-1 h-[26px] pl-2.5 {{ $eigen ? 'pr-1' : 'pr-2.5' }} rounded-full text-[length:var(--fa-text-sm)] font-medium bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]"
                                  @unless($eigen) title="Vom übergeordneten Team geerbt" @endunless>
                                @unless($eigen)@svg('heroicon-m-lock-closed', 'w-3.5 h-3.5 shrink-0')@endunless
                                {{ $eintrag->supplier?->name ?? 'Lieferant nicht mehr verfügbar' }}
                                @if($eigen)
                                    <button type="button" wire:click="stammEntfernen({{ $eintrag->supplier_id }}, '{{ $code }}')"
                                            aria-label="{{ $eintrag->supplier?->name }} entfernen" title="Entfernen"
                                            class="w-5 h-5 inline-flex items-center justify-center rounded-full hover:text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)] transition-colors duration-150">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                                @endif
                            </span>
                        @endforeach
                        <x-fa::select wire:model="stammNeu.{{ $code ?: '' }}" wire:change="stammSetzen('{{ $code }}')" size="sm" class="w-52" aria-label="Stamm-Lieferant hinzufügen für {{ $titel }}">
                            <option value="">Stamm-Lieferant hinzufügen</option>
                            @foreach($lieferanten as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
                        </x-fa::select>
                    </div>
                </div>
            @endforeach
        </div>
        {{-- Lead-Neuwahl mit Vorschau (PR #164, 2026-10-05) --}}
        <div class="mt-3 pt-3 border-t border-[var(--fa-line)] flex flex-col gap-3" data-lead-repick>
            <div>
                <x-fa::button icon="heroicon-m-arrow-path" href="#" x-on:click.prevent="" wire:click="repickVorschau">Leads neu wählen …</x-fa::button>
            </div>
            @if($repick !== null)
                <div class="flex flex-col gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]" data-lead-repick-vorschau>
                    <p>
                        {{ $repick['geprueft'] }} eigene Grundprodukte geprüft ·
                        <strong class="text-[var(--fa-ink)]">{{ count($repick['wechsel']) }} würden den Lead wechseln</strong> ·
                        {{ $repick['unveraendert'] }} unverändert ·
                        {{ $repick['manuell_geschuetzt'] }} manuell gesetzt (bleiben) ·
                        {{ $repick['ohne_preis'] }} ohne bepreisten Kandidaten (bleiben)
                    </p>
                    @if($repick['wechsel'] !== [])
                        <div class="max-h-80 overflow-y-auto rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                            <table class="w-full text-[length:var(--fa-text-sm)]">
                                <thead class="bg-[var(--fa-ground)] text-left text-[var(--fa-ink-3)]">
                                    <tr><th class="px-3 py-1.5"></th><th class="px-3 py-1.5 font-medium">Grundprodukt</th><th class="px-3 py-1.5 font-medium">Bisher</th><th class="px-3 py-1.5 font-medium">Neu</th><th class="px-3 py-1.5 font-medium text-right">Rezepte</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($repick['wechsel'] as $w)
                                        <tr wire:key="repick-{{ $w['gp_id'] }}" class="border-t border-[var(--fa-line)]">
                                            <td class="px-3 py-1.5"><input type="checkbox" wire:model="repickAuswahl" value="{{ $w['gp_id'] }}" class="accent-[var(--fa-accent)]" /></td>
                                            <td class="px-3 py-1.5 text-[var(--fa-ink)]">{{ $w['gp'] }}</td>
                                            <td class="px-3 py-1.5 text-[var(--fa-ink-3)]">{{ ($w['alt_lieferant'] ?? '—') . ($w['alt_vergleichspreis'] !== null ? ' · ' . number_format((float) $w['alt_vergleichspreis'], 2, ',', '.') . ' €' : '') }}</td>
                                            <td class="px-3 py-1.5">
                                                {{ $w['neu_lieferant'] . ($w['neu_vergleichspreis'] !== null ? ' · ' . number_format((float) $w['neu_vergleichspreis'], 2, ',', '.') . ' €' : '') }}
                                                @if($w['neu_ist_stamm'])
                                                    <x-fa::badge>Stamm</x-fa::badge>
                                                @endif
                                            </td>
                                            <td class="px-3 py-1.5 text-right tabular-nums">{{ $w['rezepte'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Der Lead gilt je Grundprodukt für alle Teams, die es nutzen. Team-Pins und -Sperren bleiben.</p>
                    @endif
                    <div class="flex gap-2">
                        @if($repick['wechsel'] !== [])
                            <x-fa::button variant="primary" wire:click="repickUebernehmen">Ausgewählte übernehmen</x-fa::button>
                        @endif
                        <x-fa::button variant="ghost" wire:click="repickSchliessen">Schließen</x-fa::button>
                    </div>
                </div>
            @endif
        </div>
    </x-fa::section>
    </fieldset>
</div>
