{{-- Spec 75b · Reiter Rechnungen: erfassen (aus Lieferscheinen vorbelegt), prüfen (Triple Match je Position),
     begründen, freigeben (Rolle Freigeben), bezahlt. Rechte prüft der Service. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $zahl = fn ($v, $d = 2) => $v === null ? '–' : (rtrim(rtrim(number_format((float) $v, $d, ',', '.'), '0'), ',') ?: '0');
    $datum = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d.m.Y') : '–';
    $statusTon = ['erfasst' => 'warn', 'freigegeben' => 'info', 'bezahlt' => 'ok', 'storniert' => 'crit'];
    $befundTon = ['ok' => 'ok', 'offen' => 'neutral', 'menge' => 'crit', 'preis' => 'warn', 'nicht_geliefert' => 'crit', 'nicht_berechnet' => 'warn', 'zu_wenig' => 'warn', 'zu_viel' => 'info'];
@endphp
<div class="flex flex-col gap-4" data-we-rechnungen>
    @if($fehler)<x-fa::notice tone="crit" data-re-fehler>{{ $fehler }}</x-fa::notice>@endif
    @if($hinweis)<x-fa::notice tone="ok" data-re-hinweis>{{ $hinweis }}</x-fa::notice>@endif

    @if($formOffen)
        <x-fa::section :title="$formId ? 'Rechnung bearbeiten' : 'Rechnung erfassen'" icon="heroicon-o-document-text" data-re-form>
            <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                <x-fa::field label="Lieferant" for="re-lieferant" required>
                    <x-fa::select id="re-lieferant" wire:change="lieferantWaehlen($event.target.value)" :disabled="$formId !== null" placeholder="Lieferant wählen …">
                        @foreach($lieferanten as $l)<option value="{{ $l->id }}" @selected((int) $form['supplier_id'] === (int) $l->id)>{{ $l->name }}</option>@endforeach
                    </x-fa::select>
                </x-fa::field>
                <x-fa::field label="Rechnungs-Nr." for="re-nummer"><x-fa::input id="re-nummer" wire:model.blur="form.nummer" /></x-fa::field>
                <x-fa::field label="Rechnungsdatum" for="re-datum"><x-fa::input id="re-datum" type="date" wire:model.blur="form.datum" /></x-fa::field>
                <x-fa::field label="Summe netto laut Beleg" for="re-total" hint="zur Kontrolle der Erfassung"><x-fa::input id="re-total" numeric wire:model.live.blur="form.total" placeholder="0,00" /></x-fa::field>
                <x-fa::field label="Beleg (Foto oder PDF)" for="re-anhang" hint="max. 15 MB">
                    <input id="re-anhang" type="file" wire:model="anhang" accept="image/*,application/pdf" class="block w-full text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" />
                </x-fa::field>
            </div>

            @if($form['supplier_id'] !== null)
                @if($zeilen === [])
                    <x-fa::notice tone="info">Keine gebuchten, noch nicht abgerechneten Lieferscheine bei diesem Lieferanten. Erst Lieferschein buchen, dann Rechnung erfassen.</x-fa::notice>
                @else
                    <div class="overflow-x-auto">
                        <table class="fa-table fa-table--compact min-w-[760px]">
                            <thead><tr><th class="w-8"><span class="sr-only">auf Rechnung</span></th><th>Lieferschein</th><th>Artikel</th><th class="text-right">Menge</th><th class="text-right">Preis Bestellung</th><th class="text-right">Preis Rechnung</th><th class="text-right">Betrag</th></tr></thead>
                            <tbody>
                                @foreach($zeilen as $i => $z)
                                    <tr wire:key="re-z-{{ $i }}-{{ $z['delivery_note_line_id'] ?? 'x' }}" class="{{ empty($z['an']) ? 'opacity-50' : '' }}" data-re-zeile>
                                        <td><input type="checkbox" wire:model.live="zeilen.{{ $i }}.an" aria-label="auf Rechnung"></td>
                                        <td>{{ $z['lieferschein'] ?? '–' }}<span class="{{ $leise }}">{{ $z['nummer'] ? ' · ' . $z['nummer'] : ' · ohne Bestellung' }}</span></td>
                                        <td class="font-medium">{{ $z['designation'] }}</td>
                                        <td class="text-right"><x-fa::input size="sm" numeric class="w-20" wire:model.live.blur="zeilen.{{ $i }}.qty" aria-label="Menge" /></td>
                                        <td class="text-right tabular-nums">{{ $z['preis_bestellt'] !== null ? number_format((float) $z['preis_bestellt'], 2, ',', '.') . ' €' : '–' }}</td>
                                        <td class="text-right"><x-fa::input size="sm" numeric class="w-24" wire:model.live.blur="zeilen.{{ $i }}.preis" aria-label="Preis laut Rechnung" data-re-preis="{{ $i }}" /></td>
                                        <td class="text-right tabular-nums">@php($b = (float) str_replace(',', '.', (string) $z['qty']) * (float) str_replace(',', '.', (string) $z['preis']))<x-fa::money :value="$b" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="flex flex-col gap-2" data-re-neben>
                    <p class="font-semibold">Nebenkosten</p>
                    @foreach($neben as $i => $n)
                        <div class="flex flex-wrap items-center gap-2" wire:key="re-n-{{ $i }}">
                            <x-fa::select size="sm" wire:model.live="neben.{{ $i }}.art" :options="$arten" aria-label="Art" />
                            <x-fa::input size="sm" class="w-56" wire:model.blur="neben.{{ $i }}.designation" placeholder="Bezeichnung" aria-label="Bezeichnung" />
                            <x-fa::input size="sm" numeric class="w-24" wire:model.live.blur="neben.{{ $i }}.betrag" placeholder="€" aria-label="Betrag" />
                            <x-fa::icon-button icon="heroicon-o-x-mark" label="Entfernen" wire:click="nebenEntfernen({{ $i }})" />
                        </div>
                    @endforeach
                    <div><x-fa::button size="sm" icon="heroicon-o-plus" wire:click="nebenHinzu">Fracht, Pfand, Zuschlag oder Rabatt</x-fa::button></div>
                </div>

                @php($total = $form['total'] !== '' ? (float) str_replace(',', '.', $form['total']) : null)
                <div class="flex flex-wrap items-center gap-3">
                    <span class="font-semibold">Summe Positionen <x-fa::money :value="$summe" /></span>
                    @if($total !== null)
                        @if(abs($summe - $total) < 0.01)
                            <x-fa::badge tone="ok" data-re-summe-ok>stimmt mit Beleg</x-fa::badge>
                        @else
                            <x-fa::badge tone="crit" data-re-summe-diff>Differenz {{ number_format($summe - $total, 2, ',', '.') }} € zum Beleg</x-fa::badge>
                        @endif
                    @endif
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-2 pt-2">
                <x-fa::button variant="primary" icon="heroicon-o-check" wire:click="speichern" :disabled="$form['supplier_id'] === null" data-re-speichern>Speichern und prüfen</x-fa::button>
                <x-fa::button variant="ghost" wire:click="abbrechen">Abbrechen</x-fa::button>
            </div>
        </x-fa::section>
    @endif

    <x-fa::section title="Rechnungen" icon="heroicon-o-document-text" data-re-liste>
        <x-slot:actions>
            @if($darfErfassen && ! $formOffen)<x-fa::button variant="primary" size="sm" icon="heroicon-o-plus" wire:click="neu" data-re-neu>Rechnung erfassen</x-fa::button>@endif
        </x-slot:actions>
        <div class="flex flex-wrap items-end gap-3">
            <x-fa::field label="Lieferant" for="re-f-l" class="w-56">
                <x-fa::select id="re-f-l" wire:model.live="fLieferant" placeholder="Alle">@foreach($lieferanten as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</x-fa::select>
            </x-fa::field>
            <x-fa::field label="Status" for="re-f-s" class="w-40"><x-fa::select id="re-f-s" wire:model.live="fStatus" placeholder="Alle" :options="$statusLabels" /></x-fa::field>
            <x-fa::field label="Suche" for="re-f-q" class="w-56"><x-fa::input id="re-f-q" wire:model.live.debounce.300ms="fSuche" placeholder="Rechnungs-Nr." /></x-fa::field>
        </div>
        @if($liste === [])
            <x-fa::empty compact icon="heroicon-o-document-text" title="Keine Rechnungen">Rechnungen werden aus gebuchten Lieferscheinen erfasst und gegen Bestellung und Lieferung geprüft.</x-fa::empty>
        @else
            <div class="overflow-x-auto">
                <table class="fa-table fa-table--compact min-w-[760px]">
                    <thead><tr><th>Datum</th><th>Nummer</th><th>Lieferant</th><th class="text-right">Summe</th><th>fällig</th><th>Status</th><th><span class="sr-only">Aktionen</span></th></tr></thead>
                    <tbody>
                        @foreach($liste as $r)
                            <tr wire:key="re-l-{{ $r['id'] }}" class="cursor-pointer" wire:click="umschalten({{ $r['id'] }})" data-re-rechnung="{{ $r['id'] }}">
                                <td>{{ $datum($r['invoice_date']) }}</td>
                                <td class="font-medium">{{ $r['invoice_number'] ?? 'ohne Nr.' }}@if($r['source'] === 'editor') <x-fa::badge>aus Bestell-Editor</x-fa::badge>@elseif($r['source'] === 'altbestand') <x-fa::badge>Altbestand</x-fa::badge>@endif @if($r['anhang']) @svg('heroicon-o-paper-clip', 'inline w-3.5 h-3.5 text-[var(--fa-ink-3)]')@endif</td>
                                <td>{{ $r['lieferant'] }}</td>
                                <td class="text-right"><x-fa::money :value="$r['summe_positionen']" /></td>
                                <td class="{{ $r['ueberfaellig'] ? 'text-[var(--fa-warn)] font-medium' : '' }}">{{ $datum($r['due_date']) }}</td>
                                <td>
                                    <x-fa::badge :tone="$statusTon[$r['status']] ?? 'neutral'">{{ $r['status_label'] }}</x-fa::badge>
                                    @if($r['strittig'])<x-fa::badge tone="crit">strittig</x-fa::badge>@endif
                                </td>
                                <td class="text-right whitespace-nowrap" onclick="event.stopPropagation()">
                                    @if($r['status'] === 'erfasst' && $darfErfassen)
                                        <x-fa::button size="sm" wire:click="bearbeiten({{ $r['id'] }})">Bearbeiten</x-fa::button>
                                    @endif
                                </td>
                            </tr>
                            @if($detail !== null && $detail['id'] === $r['id'])
                                <tr wire:key="re-d-{{ $r['id'] }}" data-re-detail="{{ $r['id'] }}">
                                    <td colspan="7" class="bg-[var(--fa-ground)]">
                                        <div class="flex flex-col gap-3 py-2">
                                            <div class="flex flex-wrap items-center gap-3 {{ $leise }}">
                                                <span>Summe Positionen <strong class="text-[var(--fa-ink)]">{{ number_format($detail['summe_positionen'], 2, ',', '.') }} €</strong>@if($detail['total_net'] !== null) · laut Beleg {{ number_format($detail['total_net'], 2, ',', '.') }} €@endif</span>
                                                @unless($detail['summen_passen'])<x-fa::badge tone="crit">Summe ≠ Beleg</x-fa::badge>@endunless
                                                <span>Toleranz Preis ±{{ $zahl($detail['toleranz']['pct']) }} % oder ±{{ number_format($detail['toleranz']['eur'], 2, ',', '.') }} € je Gebinde</span>
                                                @if($detail['anhang_url'])<a href="{{ $detail['anhang_url'] }}" target="_blank" rel="noopener" class="underline">Beleg öffnen</a>@endif
                                            </div>
                                            <table class="fa-table fa-table--compact">
                                                <thead><tr><th>Position</th><th>Bestellung / LS</th><th class="text-right">bestellt</th><th class="text-right">geliefert</th><th class="text-right">berechnet</th><th class="text-right">Preis Best.</th><th class="text-right">Preis RE</th><th class="text-right">Betrag</th><th>Befund</th><th>Begründung</th></tr></thead>
                                                <tbody>
                                                    @foreach($detail['zeilen'] as $z)
                                                        <tr wire:key="re-dz-{{ $z['id'] }}" data-re-befund="{{ $z['befund']['re'] ?? $z['art'] }}">
                                                            <td class="font-medium">{{ $z['designation'] }}@if($z['art'] !== 'ware') <x-fa::badge>{{ $z['art_label'] }}</x-fa::badge>@endif</td>
                                                            <td>{{ $z['nummer'] ?? '–' }}<span class="{{ $leise }}">{{ $z['lieferschein'] ? ' · LS ' . $z['lieferschein'] : '' }}</span></td>
                                                            <td class="text-right tabular-nums">{{ $zahl($z['bestellt']) }}</td>
                                                            <td class="text-right tabular-nums">{{ $zahl($z['geliefert']) }}</td>
                                                            <td class="text-right tabular-nums">{{ $zahl($z['qty_packs']) }}</td>
                                                            <td class="text-right tabular-nums">{{ $z['preis_bestellt'] !== null ? number_format($z['preis_bestellt'], 2, ',', '.') : '–' }}</td>
                                                            <td class="text-right tabular-nums">{{ $z['pack_price'] !== null ? number_format($z['pack_price'], 2, ',', '.') : '–' }}</td>
                                                            <td class="text-right"><x-fa::money :value="$z['line_net']" /></td>
                                                            <td>
                                                                @if($z['befund'])
                                                                    <x-fa::badge :tone="$befundTon[$z['befund']['re']] ?? 'neutral'">{{ \Platform\FoodAlchemist\Services\TripleMatchService::LABELS[$z['befund']['re']] ?? $z['befund']['re'] }}</x-fa::badge>
                                                                    @if(abs($z['befund']['delta_eur']) >= 0.01)<span class="{{ $leise }}">{{ $z['befund']['delta_eur'] > 0 ? '+' : '' }}{{ number_format($z['befund']['delta_eur'], 2, ',', '.') }} €</span>@endif
                                                                @else
                                                                    <span class="{{ $leise }}">–</span>
                                                                @endif
                                                            </td>
                                                            <td>
                                                                @if($z['abweichung'] && $detail['status'] === 'erfasst' && $darfErfassen)
                                                                    <div class="flex items-center gap-1">
                                                                        <x-fa::input size="sm" class="w-48" wire:model="begruendung.{{ $z['id'] }}" placeholder="z. B. Preiserhöhung lt. Mail" aria-label="Begründung" />
                                                                        <x-fa::button size="sm" wire:click="begruenden({{ $z['id'] }})">OK</x-fa::button>
                                                                    </div>
                                                                @else
                                                                    <span class="{{ $leise }}">{{ $z['begruendung'] ?? '' }}</span>
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                            <div class="flex flex-wrap items-center gap-2">
                                                @if($detail['status'] === 'erfasst')
                                                    @if($darfFreigeben)
                                                        <x-fa::button variant="primary" size="sm" icon="heroicon-o-check-badge" wire:click="freigeben({{ $detail['id'] }})" :disabled="! $detail['freigebbar']" data-re-freigeben>Freigeben</x-fa::button>
                                                    @else
                                                        <span class="{{ $leise }}">Freigeben dürfen Inhaber, Admins und Mitglieder mit Freigabe-Häkchen.</span>
                                                    @endif
                                                    @unless($detail['freigebbar'])<span class="{{ $leise }}">{{ $detail['unbegruendet'] > 0 ? $detail['unbegruendet'] . ' Abweichung(en) begründen oder reklamieren (Reiter Abgleich).' : 'Summe der Positionen stimmt nicht mit dem Beleg.' }}</span>@endunless
                                                @endif
                                                @if($detail['status'] === 'freigegeben' && $darfFreigeben)
                                                    <x-fa::button size="sm" icon="heroicon-o-banknotes" wire:click="bezahlt({{ $detail['id'] }})" data-re-bezahlt>Als bezahlt markieren</x-fa::button>
                                                @endif
                                                @if(in_array($detail['status'], ['erfasst', 'freigegeben'], true) && $darfErfassen)
                                                    <x-fa::button size="sm" variant="ghost" wire:click="strittig({{ $detail['id'] }}, {{ $detail['strittig'] ? 'false' : 'true' }})">{{ $detail['strittig'] ? 'nicht mehr strittig' : 'als strittig markieren' }}</x-fa::button>
                                                @endif
                                                @if($detail['status'] === 'erfasst' && $darfErfassen)
                                                    <x-fa::button size="sm" variant="ghost" wire:click="stornieren({{ $detail['id'] }})" wire:confirm="Rechnung löschen?">Löschen</x-fa::button>
                                                @elseif($detail['status'] === 'freigegeben' && $darfFreigeben)
                                                    <x-fa::button size="sm" variant="ghost" wire:click="stornieren({{ $detail['id'] }})" wire:confirm="Freigegebene Rechnung stornieren? Die Prüfwerte an den Bestellungen werden zurückgerechnet.">Stornieren</x-fa::button>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-fa::section>
</div>
