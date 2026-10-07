{{-- R9.1/R9.2: Lieferanten-Stammblatt als Dialog mit Reitern.
     Oberfläche der Beziehungs-Engine (SupplierService/SupplierAgreementService).
     fa-pass (2026-10-05): auf Bausteine <x-fa::…> umgestellt. Funktion, wire:-Bindungen und data-Marker
     unverändert. Neu: Ansprechpartner vor Stammdaten (wer wird angerufen?), fällige Wiedervorlagen und
     Kündigungsfristen als Signal am Reiter, Status/Liefertage/Typen als Chips, Datumsangaben deutsch. --}}
@php
    $statusTon = ['aktiv' => 'ok', 'zweitquelle' => 'warn', 'gesperrt' => 'crit'];
    $statusOptionen = collect(\Platform\FoodAlchemist\Enums\SupplierStatus::cases())
        ->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->label())])->all();
    $wochentage = ['1' => 'Mo', '2' => 'Di', '3' => 'Mi', '4' => 'Do', '5' => 'Fr', '6' => 'Sa', '7' => 'So'];
    $abspracheTypen = ['absprache' => 'Absprache', 'zusage' => 'Zusage', 'konditionsvereinbarung' => 'Konditionsvereinbarung', 'sonstiges' => 'Sonstiges'];
    $dokumentArten = ['vertrag' => 'Vertrag', 'rahmenvereinbarung' => 'Rahmenvereinbarung', 'preisliste' => 'Preisliste', 'zertifikat' => 'Zertifikat', 'sonstiges' => 'Sonstiges'];
    $quelleText = [
        'flat_legacy' => 'fester Bonus',
        'keine' => 'keine Stufe gewählt',
        'inaktiv' => 'nicht angerechnet',
        'simulation' => 'Simulation',
        'manuell' => 'Stufe von Hand gewählt',
        'auto_umsatz' => 'aus dem Jahresumsatz',
    ];
    $datum = fn ($iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->format('d.m.Y') : null;

    $heuteIso = $heute->toDateString();
    $faelligeAbsprachen = $stammblatt === null ? 0 : collect($stammblatt['absprachen'])
        ->filter(fn ($a) => $a['follow_up_at'] && $a['follow_up_at'] <= $heuteIso)->count();
    $faelligeFristen = $stammblatt === null ? 0 : collect($stammblatt['dokumente'])
        ->filter(fn ($d) => $d['notice_deadline'] && $d['notice_deadline'] <= $heuteIso)->count();
    $reiter = $stammblatt === null ? [] : [
        ['stammblatt', 'Stammblatt', null, 0],
        ['konditionen', 'Konditionen', null, 0],
        ['absprachen', 'Absprachen', count($stammblatt['absprachen']), $faelligeAbsprachen],
        ['dokumente', 'Dokumente', count($stammblatt['dokumente']), $faelligeFristen],
        ['buendelung', 'Bündelung', null, 0],
    ];
    $eff = (float) ($stufenInfo['prozent'] ?? 0);
    $haken = 'rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] focus:ring-[var(--fa-accent)] disabled:opacity-50';
@endphp

<x-foodalchemist::modal name="supplier-detail" :title="$stammblatt['name'] ?? 'Lieferant'" size="max-w-4xl">
    <div wire:key="supplier-detail-{{ $supplierId }}">
        @if($stammblatt === null)
            <x-fa::empty icon="heroicon-o-truck" title="Kein Lieferant gewählt">Links in der Liste einen Lieferanten wählen und „Stammblatt öffnen“ klicken.</x-fa::empty>
        @else
            <div x-data="{ tab: 'stammblatt' }" class="flex flex-col gap-4" data-supplier-detail="{{ $stammblatt['id'] }}">
                {{-- Kopf: Status + Hinweis bei geerbtem Lieferanten --}}
                <div class="flex flex-wrap items-center gap-2">
                    <x-fa::badge :tone="$statusTon[$stammblatt['status']] ?? 'neutral'" data-status-badge>{{ $statusOptionen[$stammblatt['status']] ?? $stammblatt['status'] }}</x-fa::badge>
                    @if($stammblatt['is_inactive'])<x-fa::badge>Inaktiv</x-fa::badge>@endif
                    @if($faelligeAbsprachen > 0)<x-fa::signal tone="warn">{{ $faelligeAbsprachen }} {{ $faelligeAbsprachen === 1 ? 'Wiedervorlage' : 'Wiedervorlagen' }} fällig</x-fa::signal>@endif
                    @if($faelligeFristen > 0)<x-fa::signal tone="crit">{{ $faelligeFristen }} {{ $faelligeFristen === 1 ? 'Kündigungsfrist' : 'Kündigungsfristen' }} erreicht</x-fa::signal>@endif
                </div>
                @unless($darfEdit)
                    <x-fa::notice tone="info" data-d1-hinweis>Dieser Lieferant kommt aus einem übergeordneten Team. Pflegen kann ihn nur das Team, dem er gehört. Hier siehst du alles, kannst aber nichts ändern.</x-fa::notice>
                @endunless

                @if($fehler)<x-fa::notice tone="crit" data-fehler>{{ $fehler }}</x-fa::notice>@endif
                @if($hinweis)<x-fa::notice tone="ok" data-hinweis>{{ $hinweis }}</x-fa::notice>@endif

                {{-- Reiter --}}
                <div class="flex items-center gap-1 border-b border-[var(--fa-line)] overflow-x-auto" role="tablist">
                    @foreach($reiter as [$key, $lbl, $anzahl, $faellig])
                        <button type="button" role="tab" @click="tab = '{{ $key }}'" data-tab="{{ $key }}"
                                :aria-selected="tab === '{{ $key }}' ? 'true' : 'false'"
                                :class="tab === '{{ $key }}' ? 'border-[var(--fa-accent)] text-[var(--fa-accent)]' : 'border-transparent text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]'"
                                class="inline-flex items-center gap-1.5 h-9 px-3 text-[length:var(--fa-text-md)] font-medium border-b-2 -mb-px whitespace-nowrap transition-colors">
                            {{ $lbl }}
                            @if($anzahl !== null)<span class="text-[length:var(--fa-text-sm)] font-normal text-[var(--fa-ink-3)] tabular-nums">{{ $anzahl }}</span>@endif
                            @if($faellig > 0)<span class="w-2 h-2 rounded-full bg-[var(--fa-warn)]" aria-label="{{ $faellig }} fällig"></span>@endif
                        </button>
                    @endforeach
                </div>

                {{-- ── Reiter: Stammblatt (Status · Ansprechpartner · Stammdaten · Warengruppen) ── --}}
                <div x-show="tab === 'stammblatt'" class="flex flex-col gap-4">
                    <x-fa::section title="Beziehung" icon="heroicon-o-hand-raised">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div wire:change="statusSetzen" data-status-select>
                                @if($darfEdit)
                                    <x-fa::choice name="status" :options="$statusOptionen" :live="false" label="Status" id-prefix="lief-status" />
                                    <p class="mt-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Wird sofort gespeichert. Gesperrte Lieferanten werden nicht mehr bevorzugt.</p>
                                @else
                                    <p class="mb-1.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Status</p>
                                    <x-fa::badge :tone="$statusTon[$stammblatt['status']] ?? 'neutral'">{{ $statusOptionen[$stammblatt['status']] ?? $stammblatt['status'] }}</x-fa::badge>
                                @endif
                            </div>
                            <div>
                                <p class="mb-1.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Verwendung in Rezepten</p>
                                <p class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)] tabular-nums" data-volumen-proxy>{{ $stammblatt['volumen_proxy']['n_usages'] }} {{ (int) $stammblatt['volumen_proxy']['n_usages'] === 1 ? 'Verwendung' : 'Verwendungen' }}</p>
                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Zählt Rezept-Zutaten, deren bevorzugter Artikel von diesem Lieferanten kommt. Kein Umsatz.</p>
                            </div>
                        </div>
                    </x-fa::section>

                    {{-- Ansprechpartner --}}
                    <x-fa::section title="Ansprechpartner" icon="heroicon-o-user-group" :meta="count($stammblatt['kontakte']) ?: null">
                        <div class="flex flex-col" data-kontakt-liste>
                            @forelse($stammblatt['kontakte'] as $k)
                                <div wire:key="kontakt-{{ $k['id'] }}" class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-2 border-b border-[var(--fa-line)] last:border-0 text-[length:var(--fa-text-md)]">
                                    <span class="font-medium text-[var(--fa-ink)]">{{ $k['name'] }}</span>
                                    @if($k['role'])<span class="text-[var(--fa-ink-2)]">{{ $k['role'] }}</span>@endif
                                    @if($k['phone'])<a href="tel:{{ $k['phone'] }}" class="inline-flex items-center gap-1 text-[var(--fa-accent)] hover:underline">@svg('heroicon-m-phone', 'w-3.5 h-3.5'){{ $k['phone'] }}</a>@endif
                                    @if($k['email'])<a href="mailto:{{ $k['email'] }}" class="inline-flex items-center gap-1 text-[var(--fa-accent)] hover:underline">@svg('heroicon-m-envelope', 'w-3.5 h-3.5'){{ $k['email'] }}</a>@endif
                                </div>
                            @empty
                                <x-fa::empty compact icon="heroicon-o-user-group" title="Noch keine Ansprechpartner">
                                    @if($darfEdit) Unten Name und Kontaktweg eintragen. @else Das Besitzer-Team hat noch niemanden hinterlegt. @endif
                                </x-fa::empty>
                            @endforelse
                        </div>
                        @if($darfEdit)
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1" data-kontakt-neu>
                                <x-fa::field label="Name" for="kontakt-name" required>
                                    <x-fa::input id="kontakt-name" size="sm" wire:model="neuKontakt.name" data-kontakt-name />
                                </x-fa::field>
                                <x-fa::field label="Rolle" for="kontakt-rolle" optional>
                                    <x-fa::input id="kontakt-rolle" size="sm" wire:model="neuKontakt.role" placeholder="z. B. Außendienst" />
                                </x-fa::field>
                                <x-fa::field label="Telefon" for="kontakt-telefon" optional>
                                    <x-fa::input id="kontakt-telefon" size="sm" type="tel" wire:model="neuKontakt.phone" />
                                </x-fa::field>
                                <x-fa::field label="E-Mail" for="kontakt-mail" optional>
                                    <x-fa::input id="kontakt-mail" size="sm" type="email" wire:model="neuKontakt.email" />
                                </x-fa::field>
                            </div>
                            <div class="flex justify-end">
                                <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="kontaktAnlegen" data-kontakt-add>Ansprechpartner hinzufügen</x-fa::button>
                            </div>
                        @endif
                    </x-fa::section>

                    {{-- Stammdaten: editierbar fürs Besitzer-Team (D1), sonst nur Ansicht.
                         update() ist im Service D1-gated — bei geerbtem Lieferanten kein Formular. --}}
                    <x-fa::section title="Stammdaten" icon="heroicon-o-building-storefront">
                        @if($darfEdit)
                            <div class="grid grid-cols-2 gap-3" data-stammdaten-edit>
                                <x-fa::field label="Name" for="sd-name" required class="col-span-2">
                                    <x-fa::input id="sd-name" wire:model="stammdaten.name" data-sd-name />
                                </x-fa::field>
                                <x-fa::field label="Branche" for="sd-branche" optional>
                                    <x-fa::input id="sd-branche" wire:model="stammdaten.branch" data-sd-branch />
                                </x-fa::field>
                                <x-fa::field label="GLN" for="sd-gln" optional hint="Globale Lokationsnummer, 13 Ziffern">
                                    <x-fa::input id="sd-gln" wire:model="stammdaten.gln" data-sd-gln />
                                </x-fa::field>
                                <x-fa::field label="PLZ" for="sd-plz" optional>
                                    <x-fa::input id="sd-plz" wire:model="stammdaten.postal_code" data-sd-plz />
                                </x-fa::field>
                                <x-fa::field label="Ort" for="sd-ort" optional>
                                    <x-fa::input id="sd-ort" wire:model="stammdaten.city" data-sd-city />
                                </x-fa::field>
                                <x-fa::field label="Straße" for="sd-strasse" optional class="col-span-2">
                                    <x-fa::input id="sd-strasse" wire:model="stammdaten.address" data-sd-address />
                                </x-fa::field>
                                <x-fa::field label="Bestell-E-Mail" for="sd-mail" optional>
                                    <x-fa::input id="sd-mail" type="email" wire:model="stammdaten.email_order" data-sd-email />
                                </x-fa::field>
                                <x-fa::field label="Homepage" for="sd-web" optional>
                                    <x-fa::input id="sd-web" wire:model="stammdaten.homepage" data-sd-homepage />
                                </x-fa::field>
                                <div class="col-span-2 flex justify-end">
                                    <x-fa::button variant="primary" wire:click="stammdatenSpeichern" data-stammdaten-speichern>Stammdaten speichern</x-fa::button>
                                </div>
                            </div>
                        @else
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-3">
                                @foreach([
                                    ['Branche', $stammblatt['stammdaten']['branch']],
                                    ['GLN', $stammblatt['stammdaten']['gln']],
                                    ['Ort', trim(($stammblatt['stammdaten']['postal_code'] ?? '') . ' ' . ($stammblatt['stammdaten']['city'] ?? ''))],
                                    ['Straße', $stammblatt['stammdaten']['address']],
                                    ['Bestell-E-Mail', $stammblatt['stammdaten']['email_order']],
                                    ['Homepage', $stammblatt['stammdaten']['homepage']],
                                ] as [$lbl, $wert])
                                    <div class="min-w-0">
                                        <dt class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">{{ $lbl }}</dt>
                                        <dd class="text-[length:var(--fa-text-md)] break-words {{ $wert ? 'text-[var(--fa-ink)]' : 'text-[var(--fa-ink-3)]' }}">{{ $wert ?: 'nicht hinterlegt' }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </x-fa::section>

                    <x-fa::section title="Stamm-Lieferant für Warengruppen" icon="heroicon-o-squares-2x2"
                        description="In diesen Warengruppen wird dieser Lieferant beim Zuordnen zuerst gesucht.">
                        @if(count($stammblatt['wg_abdeckung']) > 0)
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($stammblatt['wg_abdeckung'] as $wg)<x-fa::badge tone="info">{{ $wg }}</x-fa::badge>@endforeach
                            </div>
                        @else
                            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-3)]">Für keine Warengruppe als Stamm-Lieferant gesetzt.</p>
                        @endif
                    </x-fa::section>
                </div>

                {{-- ── Reiter: Konditionen ── --}}
                <div x-show="tab === 'konditionen'" x-cloak class="flex flex-col gap-4">
                    <x-fa::section title="Zahlung und Lieferung" icon="heroicon-o-banknotes"
                        description="Der Bonus hier gilt nur, solange unten keine Rückvergütungs-Staffel hinterlegt ist.">
                        <div class="grid grid-cols-2 gap-3">
                            <x-fa::field label="Rückvergütung / Bonus in %" for="kond-bonus">
                                <x-fa::input id="kond-bonus" numeric wire:model="konditionen.rebate_pct" :disabled="! $darfEdit" data-kond-rebate />
                            </x-fa::field>
                            <x-fa::field label="Zahlungsziel in Tagen" for="kond-zahlungsziel">
                                <x-fa::input id="kond-zahlungsziel" numeric wire:model="konditionen.payment_term_days" :disabled="! $darfEdit" data-kond-payment />
                            </x-fa::field>
                            <x-fa::field label="Mindestbestellwert in €" for="kond-mindest">
                                <x-fa::input id="kond-mindest" numeric wire:model="konditionen.min_order_value" :disabled="! $darfEdit" />
                            </x-fa::field>
                            <x-fa::field label="Frei Haus ab € Bestellwert" for="kond-freihaus">
                                <x-fa::input id="kond-freihaus" numeric wire:model="konditionen.free_shipping_threshold" :disabled="! $darfEdit" />
                            </x-fa::field>
                        </div>
                        @if($darfEdit)
                            <div class="flex justify-end">
                                <x-fa::button variant="primary" wire:click="konditionenSpeichern" data-kond-save>Konditionen speichern</x-fa::button>
                            </div>
                        @endif
                    </x-fa::section>

                    {{-- ── Einkauf E1: Rückvergütungs-Staffeln (Volumen-Rabatt, team-scoped Overlay) ── --}}
                    <x-fa::section title="Rückvergütungs-Staffel" icon="heroicon-o-chart-bar-square"
                        description="Rückwirkender Jahresbonus. Ergibt den effektiven Nettopreis zum Vergleichen, nicht den Bestellpreis."
                        data-staffel-editor>
                        <x-slot:actions>
                            <x-fa::badge :tone="$eff > 0 ? 'ok' : 'neutral'" data-staffel-effektiv>
                                <span class="tabular-nums">effektiv {{ number_format($eff, 2, ',', '.') }} %</span>
                                <span class="font-normal opacity-80">· {{ $quelleText[$stufenInfo['quelle'] ?? ''] ?? 'nicht festgelegt' }}</span>
                            </x-fa::badge>
                        </x-slot:actions>

                        @if($stufenInfo['geerbt'] ?? false)
                            {{-- Ohne diesen Hinweis sieht ein leeres Formular aus wie „keine Kondition",
                                 obwohl gerechnet wird — mit der Staffel des Eltern-Teams. --}}
                            <x-fa::notice tone="info" data-staffel-geerbt>
                                Die Staffel kommt vom übergeordneten Team
                                ({{ count($stufenInfo['tiers'] ?? []) }} Stufen, effektiv
                                {{ number_format((float) ($stufenInfo['prozent'] ?? 0), 2, ',', '.') }} %).
                                Eine eigene Staffel ersetzt sie vollständig.
                            </x-fa::notice>
                        @endif

                        <div class="flex flex-col gap-1.5" data-staffel-zeilen>
                            @if(count($staffel) > 0)
                                <div class="grid grid-cols-[1fr_1fr_2.25rem] gap-2 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">
                                    <span>Ab Jahresumsatz in €</span><span>Rabatt in %</span><span></span>
                                </div>
                            @endif
                            @forelse($staffel as $i => $z)
                                <div wire:key="staffel-{{ $i }}" class="grid grid-cols-[1fr_1fr_2.25rem] gap-2 items-center">
                                    <x-fa::input numeric size="sm" wire:model="staffel.{{ $i }}.threshold_eur" :disabled="! $darfEdit" aria-label="Stufe {{ $i + 1 }}: ab Jahresumsatz in €" />
                                    <x-fa::input numeric size="sm" wire:model="staffel.{{ $i }}.percent" :disabled="! $darfEdit" aria-label="Stufe {{ $i + 1 }}: Rabatt in %" />
                                    @if($darfEdit)
                                        <x-fa::icon-button icon="heroicon-o-trash" label="Stufe entfernen" size="sm" tone="danger" wire:click="staffelZeileEntfernen({{ $i }})" />
                                    @else<span></span>@endif
                                </div>
                            @empty
                                <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-3)]">Noch keine Staffel. Bis dahin gilt der Bonus oben.</p>
                            @endforelse
                        </div>

                        @if($darfEdit)
                            <div>
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-plus" wire:click="staffelZeileHinzufuegen" data-staffel-add>Stufe hinzufügen</x-fa::button>
                            </div>

                            <div class="grid grid-cols-2 gap-3 pt-3 border-t border-[var(--fa-line)]">
                                <label class="col-span-2 inline-flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                                    <input type="checkbox" wire:model="rebateConfig.active" class="{{ $haken }}" /> Rückvergütung im Preis anrechnen
                                </label>
                                <x-fa::field label="Erwarteter Jahresumsatz in €" for="staffel-umsatz" hint="Daraus ergibt sich die Stufe von selbst.">
                                    <x-fa::input id="staffel-umsatz" numeric wire:model="rebateConfig.assumed_annual_revenue" data-staffel-revenue />
                                </x-fa::field>
                                <x-fa::field label="Stufe von Hand wählen" for="staffel-stufe" hint="Geht vor dem Jahresumsatz.">
                                    <x-fa::select id="staffel-stufe" wire:model="rebateConfig.selected_threshold" data-staffel-selected>
                                        <option value="">Aus dem Jahresumsatz ableiten</option>
                                        @foreach($staffel as $z)
                                            @if(($z['threshold_eur'] ?? '') !== '' && ($z['percent'] ?? '') !== '')
                                                <option value="{{ (float) $z['threshold_eur'] }}">ab {{ number_format((float) $z['threshold_eur'], 0, ',', '.') }} €: {{ number_format((float) $z['percent'], 2, ',', '.') }} %</option>
                                            @endif
                                        @endforeach
                                    </x-fa::select>
                                </x-fa::field>
                                <fieldset class="col-span-2 flex flex-col gap-2">
                                    <legend class="mb-1.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Gilt für</legend>
                                    <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                                        <input type="checkbox" wire:model.live="rebateConfig.applies_to_all" class="{{ $haken }}" data-staffel-vollsortiment /> Alle Warengruppen
                                    </label>
                                    @unless($rebateConfig['applies_to_all'] ?? true)
                                        <div class="grid grid-cols-2 gap-x-4 gap-y-1.5 pl-6" data-staffel-wg>
                                            @foreach($warengruppen as $wg)
                                                <label class="inline-flex items-center gap-2 min-w-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                                                    <input type="checkbox" wire:model="rebateConfig.commodity_groups" value="{{ $wg->code }}" class="{{ $haken }}" />
                                                    <span class="min-w-0">{{ $wg->name }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                        <p class="pl-6 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Nur in den gewählten Warengruppen wird die Rückvergütung angerechnet, sonst gilt der Originalpreis.</p>
                                    @endunless
                                </fieldset>
                            </div>
                            <div class="flex justify-end">
                                <x-fa::button variant="primary" wire:click="rueckverguetungSpeichern" data-staffel-save>Rückvergütung speichern</x-fa::button>
                            </div>
                        @endif
                    </x-fa::section>

                    {{-- Spec 17/S1 — Bestell-Logistik: Liefertage + Bestellschluss/Vorlaufzeit --}}
                    <x-fa::section title="Bestellung und Anlieferung" icon="heroicon-o-truck"
                        description="Liefertage und Bestellschluss steuern die Bestell-Ampel in der Produktion.">
                        @if($darfEdit)
                            <x-fa::choice name="liefertage" :options="$wochentage" multiple :live="false" label="Liefertage" id-prefix="lief-tage" />
                        @else
                            <div>
                                <p class="mb-1.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Liefertage</p>
                                @php
                                    $tageText = collect($liefertage)->map(fn ($t) => $wochentage[(string) $t] ?? null)->filter()->implode(', ');
                                @endphp
                                <p class="text-[length:var(--fa-text-md)] {{ $tageText !== '' ? 'text-[var(--fa-ink)]' : 'text-[var(--fa-ink-3)]' }}">{{ $tageText !== '' ? $tageText : 'nicht hinterlegt' }}</p>
                            </div>
                        @endif
                        <div class="grid grid-cols-2 gap-3">
                            <x-fa::field label="Bestellschluss (Uhrzeit)" for="bestell-schluss">
                                <x-fa::input id="bestell-schluss" type="time" wire:model="bestellung.order_cutoff_time" :disabled="! $darfEdit" />
                            </x-fa::field>
                            <x-fa::field label="Vorlaufzeit in Tagen" for="bestell-vorlauf">
                                <x-fa::input id="bestell-vorlauf" type="number" min="0" numeric wire:model="bestellung.order_lead_days" :disabled="! $darfEdit" />
                            </x-fa::field>
                        </div>
                        @if($darfEdit)
                            <div class="flex justify-end">
                                <x-fa::button variant="primary" wire:click="bestellungSpeichern" data-bestell-save>Bestellzeiten speichern</x-fa::button>
                            </div>
                        @endif
                    </x-fa::section>
                </div>

                {{-- ── Reiter: Absprachen ── --}}
                <div x-show="tab === 'absprachen'" x-cloak class="flex flex-col gap-4">
                    <x-fa::section title="Absprachen und Zusagen" icon="heroicon-o-chat-bubble-left-right" :meta="count($stammblatt['absprachen']) ?: null">
                        <div class="flex flex-col" data-absprache-liste>
                            @forelse($stammblatt['absprachen'] as $a)
                                @php
                                    $wvFaellig = $a['follow_up_at'] && $a['follow_up_at'] <= $heuteIso;
                                @endphp
                                <div wire:key="absprache-{{ $a['id'] }}" class="flex flex-col gap-1 py-2.5 border-b border-[var(--fa-line)] last:border-0">
                                    <div class="flex items-start gap-2">
                                        <x-fa::badge class="shrink-0">{{ $abspracheTypen[$a['type']] ?? ucfirst((string) $a['type']) }}</x-fa::badge>
                                        <span class="flex-1 min-w-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $a['note'] }}</span>
                                    </div>
                                    @if($a['valid_from'] || $a['valid_to'] || $a['follow_up_at'])
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">
                                            @if($a['valid_from'] || $a['valid_to'])
                                                <span>Gültig{{ $a['valid_from'] ? ' ab ' . $datum($a['valid_from']) : '' }}{{ $a['valid_to'] ? ' bis ' . $datum($a['valid_to']) : '' }}</span>
                                            @endif
                                            @if($a['follow_up_at'])
                                                @if($wvFaellig)
                                                    <x-fa::signal tone="warn" icon="heroicon-m-bell-alert" data-wiedervorlage>Wiedervorlage {{ $datum($a['follow_up_at']) }}</x-fa::signal>
                                                @else
                                                    <span class="inline-flex items-center gap-1" data-wiedervorlage>@svg('heroicon-m-bell', 'w-3.5 h-3.5') Wiedervorlage {{ $datum($a['follow_up_at']) }}</span>
                                                @endif
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <x-fa::empty compact icon="heroicon-o-chat-bubble-left-right" title="Noch keine Absprachen">
                                    @if($darfEdit) Unten festhalten, was mit dem Lieferanten vereinbart ist, z. B. eine reservierte Palette oder ein Sonderpreis. @else Das Besitzer-Team hat noch nichts festgehalten. @endif
                                </x-fa::empty>
                            @endforelse
                        </div>
                    </x-fa::section>
                    @if($darfEdit)
                        <x-fa::section title="Absprache festhalten" icon="heroicon-o-plus-circle" data-absprache-neu>
                            <x-fa::choice name="neueAbsprache.type" :options="$abspracheTypen" :live="false" label="Art" id-prefix="lief-absprache" />
                            <x-fa::field label="Was wurde vereinbart?" for="absprache-text" required>
                                <x-fa::input id="absprache-text" wire:model="neueAbsprache.note" placeholder="z. B. Palette Rapsöl bis Monatsende reserviert" data-absprache-note />
                            </x-fa::field>
                            <div class="grid grid-cols-3 gap-3">
                                <x-fa::field label="Gültig ab" for="absprache-ab" optional>
                                    <x-fa::input id="absprache-ab" type="date" wire:model="neueAbsprache.valid_from" />
                                </x-fa::field>
                                <x-fa::field label="Gültig bis" for="absprache-bis" optional>
                                    <x-fa::input id="absprache-bis" type="date" wire:model="neueAbsprache.valid_to" />
                                </x-fa::field>
                                <x-fa::field label="Wiedervorlage" for="absprache-wv" optional>
                                    <x-fa::input id="absprache-wv" type="date" wire:model="neueAbsprache.follow_up_at" />
                                </x-fa::field>
                            </div>
                            <div class="flex justify-end">
                                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="abspracheAnlegen" data-absprache-add>Absprache speichern</x-fa::button>
                            </div>
                        </x-fa::section>
                    @endif
                </div>

                {{-- ── Reiter: Dokumente ── --}}
                <div x-show="tab === 'dokumente'" x-cloak class="flex flex-col gap-4">
                    <x-fa::section title="Verträge und Dokumente" icon="heroicon-o-document-text" :meta="count($stammblatt['dokumente']) ?: null">
                        <div class="flex flex-col" data-dokument-liste>
                            @forelse($stammblatt['dokumente'] as $d)
                                @php
                                    $fristErreicht = $d['notice_deadline'] && $d['notice_deadline'] <= $heuteIso;
                                @endphp
                                <div wire:key="dokument-{{ $d['id'] }}" class="flex flex-col gap-1 py-2.5 border-b border-[var(--fa-line)] last:border-0">
                                    <div class="flex items-start gap-2">
                                        <x-fa::badge class="shrink-0">{{ $dokumentArten[$d['kind']] ?? ucfirst((string) $d['kind']) }}</x-fa::badge>
                                        <span class="flex-1 min-w-0 text-[length:var(--fa-text-md)] {{ $d['title'] ? 'text-[var(--fa-ink)]' : 'text-[var(--fa-ink-3)]' }}">{{ $d['title'] ?: 'ohne Titel' }}</span>
                                    </div>
                                    @if($d['file_ref'])
                                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] break-all">@svg('heroicon-m-paper-clip', 'w-3.5 h-3.5 inline-block align-text-bottom text-[var(--fa-ink-3)]') {{ $d['file_ref'] }}</p>
                                    @endif
                                    @if($d['term_start'] || $d['term_end'] || $d['notice_period_days'] !== null || $d['notice_deadline'])
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">
                                            @if($d['term_start'] || $d['term_end'])
                                                <span>Laufzeit{{ $d['term_start'] ? ' ab ' . $datum($d['term_start']) : '' }}{{ $d['term_end'] ? ' bis ' . $datum($d['term_end']) : '' }}</span>
                                            @endif
                                            @if($d['notice_period_days'] !== null)<span>Kündigungsfrist {{ $d['notice_period_days'] }} Tage</span>@endif
                                            @if($d['notice_deadline'])
                                                @if($fristErreicht)
                                                    <x-fa::signal tone="crit" data-notice-deadline>Kündigen bis {{ $datum($d['notice_deadline']) }}</x-fa::signal>
                                                @else
                                                    <span data-notice-deadline>Kündigen bis {{ $datum($d['notice_deadline']) }}</span>
                                                @endif
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <x-fa::empty compact icon="heroicon-o-document-text" title="Noch keine Dokumente">
                                    @if($darfEdit) Unten Verträge, Preislisten oder Zertifikate mit Laufzeit erfassen. @else Das Besitzer-Team hat noch nichts hinterlegt. @endif
                                </x-fa::empty>
                            @endforelse
                        </div>
                    </x-fa::section>
                    @if($darfEdit)
                        <x-fa::section title="Dokument erfassen" icon="heroicon-o-plus-circle"
                            description="Aus Laufzeit und Kündigungsfrist errechnet sich, bis wann gekündigt werden muss." data-dokument-neu>
                            <x-fa::choice name="neuesDokument.kind" :options="$dokumentArten" :live="false" label="Art" id-prefix="lief-dokument" />
                            <div class="grid grid-cols-2 gap-3">
                                <x-fa::field label="Titel" for="dokument-titel" optional>
                                    <x-fa::input id="dokument-titel" wire:model="neuesDokument.title" data-dokument-title />
                                </x-fa::field>
                                <x-fa::field label="Ablageort" for="dokument-ablage" optional hint="Pfad oder Link zur Datei">
                                    <x-fa::input id="dokument-ablage" wire:model="neuesDokument.file_ref" />
                                </x-fa::field>
                                <x-fa::field label="Laufzeit ab" for="dokument-ab" optional>
                                    <x-fa::input id="dokument-ab" type="date" wire:model="neuesDokument.term_start" />
                                </x-fa::field>
                                <x-fa::field label="Laufzeit bis" for="dokument-bis" optional>
                                    <x-fa::input id="dokument-bis" type="date" wire:model="neuesDokument.term_end" />
                                </x-fa::field>
                                <x-fa::field label="Kündigungsfrist in Tagen" for="dokument-frist" optional>
                                    <x-fa::input id="dokument-frist" type="number" numeric wire:model="neuesDokument.notice_period_days" data-dokument-notice />
                                </x-fa::field>
                            </div>
                            <div class="flex justify-end">
                                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="dokumentAnlegen" data-dokument-add>Dokument speichern</x-fa::button>
                            </div>
                        </x-fa::section>
                    @endif
                </div>

                {{-- ── Reiter: Bündelung (R9.2 E6 — Volumen-Proxy × Konditionen) ── --}}
                <div x-show="tab === 'buendelung'" x-cloak>
                    <x-fa::section title="Wo lohnt Bündeln oder Nachverhandeln?" icon="heroicon-o-arrows-pointing-in"
                        description="Zählt, wie oft Rezepte über ihren bevorzugten Artikel bei einem Lieferanten einkaufen, und stellt es den Konditionen gegenüber. Das ist kein echter Umsatz.">
                        @if(count($buendelung) > 0)
                            <div class="-mx-4 -mb-4 border-t border-[var(--fa-line)] overflow-x-auto">
                                <table class="fa-table" data-buendelung-tabelle>
                                    <thead>
                                        <tr>
                                            <th>Lieferant</th>
                                            <th class="num">Verwendungen</th>
                                            <th class="num">Rückvergütung</th>
                                            <th class="num">Zahlungsziel</th>
                                            <th class="w-full">Einschätzung</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($buendelung as $b)
                                            @php
                                                $istDieser = $b['supplier_id'] === $stammblatt['id'];
                                                $hinweisTon = str_contains((string) $b['bundling_hint'], 'Nachverhandlung') ? 'warn' : (str_contains((string) $b['bundling_hint'], 'hohes Volumen') ? 'info' : 'neutral');
                                            @endphp
                                            <tr wire:key="buendel-{{ $b['supplier_id'] }}" aria-selected="{{ $istDieser ? 'true' : 'false' }}">
                                                <td class="whitespace-nowrap font-medium text-[var(--fa-ink)]">{{ $b['name'] }}</td>
                                                <td class="num">{{ $b['n_usages'] }}</td>
                                                <td class="num">
                                                    @if($b['rebate_pct'] !== null){{ number_format($b['rebate_pct'], 1, ',', '.') }} %
                                                    @else<span class="text-[var(--fa-ink-3)]">nicht hinterlegt</span>@endif
                                                </td>
                                                <td class="num">
                                                    @if($b['payment_term_days'] !== null){{ $b['payment_term_days'] }} Tage
                                                    @else<span class="text-[var(--fa-ink-3)]">nicht hinterlegt</span>@endif
                                                </td>
                                                <td>
                                                    @if($hinweisTon === 'neutral')
                                                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ ucfirst((string) $b['bundling_hint']) }}</span>
                                                    @else
                                                        <x-fa::signal :tone="$hinweisTon">{{ ucfirst((string) $b['bundling_hint']) }}</x-fa::signal>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <x-fa::empty compact icon="heroicon-o-arrows-pointing-in" title="Noch keine Verwendung erfasst">
                                Erscheint, sobald Rezepte Grundprodukte nutzen, deren bevorzugter Artikel von einem Lieferanten kommt.
                            </x-fa::empty>
                        @endif
                    </x-fa::section>
                </div>
            </div>
        @endif
    </div>

    <x-slot:footer>
        <x-fa::button x-on:click="$dispatch('modal.close', { name: 'supplier-detail' })">Schließen</x-fa::button>
    </x-slot:footer>
</x-foodalchemist::modal>
