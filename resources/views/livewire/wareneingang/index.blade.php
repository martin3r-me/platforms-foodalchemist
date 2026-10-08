{{-- Spec 75a · Einkauf → Wareneingang. Erwartet (offene Lieferungen nach Liefertag) + Lieferscheine.
     Erfassen belegt alle offenen Positionen des Lieferanten vor, gruppiert je Bestellung (n:m).
     Rechte (Spec 61) prüft der Service; hier werden Schreib-Knöpfe nur zusätzlich ausgeblendet. --}}
@php
    $segment = 'h-8 px-3 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-md)] font-medium transition-colors duration-150';
    $segmentAn = 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm';
    $segmentAus = 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]';
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $zahl = fn ($v) => $v === null ? '–' : (rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') ?: '0');
    $datum = fn ($d) => $d ? \Carbon\Carbon::parse($d)->locale('de')->isoFormat('dd, D.M.') : 'ohne Liefertag';
    $statusTon = ['entwurf' => 'neutral', 'gebucht' => 'ok', 'storniert' => 'crit'];
    $abwTon = ['ok' => 'ok', 'zu_wenig' => 'warn', 'zu_viel' => 'info', 'ohne_bestellung' => 'accent'];
    $abwText = ['ok' => 'passt', 'zu_wenig' => 'zu wenig', 'zu_viel' => 'zu viel', 'ohne_bestellung' => 'ohne Bestellung'];
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-foodalchemist::shell.page-navbar title="Wareneingang" icon="heroicon-o-truck" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Wareneingang" subtitle="Lieferscheine erfassen und buchen. Ein Lieferschein darf mehrere Bestellungen desselben Lieferanten bedienen; Ware ohne Bestellung geht direkt ins Lager." />

        <x-fa::kpis :items="$kpis" data-we-kpis />

        <div class="flex flex-wrap items-center gap-3">
            <div class="inline-flex items-center gap-0.5 p-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)]" role="tablist" data-we-reiter>
                @foreach(['erwartet' => 'Erwartet', 'lieferscheine' => 'Lieferscheine'] as $k => $l)
                    <button type="button" wire:click="reiterSetzen('{{ $k }}')" class="{{ $segment }} {{ $reiter === $k ? $segmentAn : $segmentAus }}" role="tab" aria-selected="{{ $reiter === $k ? 'true' : 'false' }}">{{ $l }}</button>
                @endforeach
            </div>
            <span class="flex-1"></span>
            @if($darf && ! $formOffen)
                <x-fa::button variant="primary" icon="heroicon-o-plus" wire:click="neu" data-we-neu>Lieferschein erfassen</x-fa::button>
            @endif
        </div>

        @unless($darf)
            <x-fa::notice tone="info" data-we-leserecht>Du hast die Rolle „{{ $meineRolle->label() }}“: Lieferscheine ansehen ja, erfassen und buchen nein. Das Recht „Kuratieren“ vergibt ein Team-Admin unter Einstellungen → Zugriffsrechte.</x-fa::notice>
        @endunless
        @if($fehler)<x-fa::notice tone="crit" data-we-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($hinweis)<x-fa::notice tone="ok" data-we-hinweis>{{ $hinweis }}</x-fa::notice>@endif

        {{-- ── Erfassen ─────────────────────────────────────────────────── --}}
        @if($formOffen)
            <x-fa::section :title="$formId ? 'Lieferschein bearbeiten (Entwurf)' : 'Lieferschein erfassen'" icon="heroicon-o-clipboard-document-check" data-we-form>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <x-fa::field label="Lieferant" for="we-lieferant" required>
                        <x-fa::select id="we-lieferant" wire:change="lieferantWaehlen($event.target.value)" :disabled="$formId !== null" placeholder="Lieferant wählen …">
                            @foreach($lieferanten as $l)
                                <option value="{{ $l->id }}" @selected((int) $form['supplier_id'] === (int) $l->id)>{{ $l->name }}</option>
                            @endforeach
                        </x-fa::select>
                    </x-fa::field>
                    <x-fa::field label="Lieferschein-Nr." for="we-nummer">
                        <x-fa::input id="we-nummer" wire:model.blur="form.nummer" placeholder="vom Beleg" />
                    </x-fa::field>
                    <x-fa::field label="Geliefert am" for="we-datum">
                        <x-fa::input id="we-datum" type="date" wire:model.blur="form.datum" />
                    </x-fa::field>
                    <x-fa::field label="Beleg (Foto oder PDF)" for="we-anhang" hint="max. 15 MB">
                        <input id="we-anhang" type="file" wire:model="anhang" accept="image/*,application/pdf" capture="environment"
                            class="block w-full text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" data-we-anhang />
                        @if($anhang)<span class="{{ $leise }}">{{ $anhang->getClientOriginalName() }} · <button type="button" class="underline" wire:click="anhangVerwerfen">verwerfen</button></span>@endif
                        <div wire:loading wire:target="anhang" class="{{ $leise }}">lädt hoch …</div>
                    </x-fa::field>
                </div>
                <x-fa::field label="Notiz" for="we-notiz">
                    <x-fa::input id="we-notiz" wire:model.blur="form.notiz" placeholder="z. B. Fahrer, Zustand der Ware" />
                </x-fa::field>

                @if($form['supplier_id'] === null)
                    <x-fa::empty compact icon="heroicon-o-truck" title="Lieferant wählen">Dann stehen alle offenen Positionen dieses Lieferanten hier, über alle gesendeten und bestätigten Bestellungen.</x-fa::empty>
                @else
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="{{ $leise }} flex-1">Geliefert in Gebinden. Häkchen weg = Position steht nicht auf diesem Lieferschein.</p>
                        @if(count($zeilen) > 0)
                            <x-fa::button size="sm" icon="heroicon-o-check" wire:click="allesWieBestellt" data-we-alles>Alles wie bestellt</x-fa::button>
                        @endif
                    </div>
                    @if(count($zeilen) === 0)
                        <x-fa::notice tone="info">Keine offenen Bestellpositionen bei diesem Lieferanten. Ware ohne Bestellung kannst du unten erfassen.</x-fa::notice>
                    @endif
                    @foreach($gruppen as $orderId => $rows)
                        @php($erste = $rows->first())
                        <div class="flex flex-col gap-2" wire:key="we-gruppe-{{ $orderId }}" data-we-bestellung="{{ $orderId }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold">{{ $erste['nummer'] }}</span>
                                @if($erste['reference'])<span class="{{ $leise }}">{{ $erste['reference'] }}</span>@endif
                                <span class="flex-1"></span>
                                <label class="inline-flex items-center gap-2 text-[length:var(--fa-text-sm)]">
                                    <input type="checkbox" wire:model.live="abschliessen.{{ $orderId }}" data-we-abschliessen="{{ $orderId }}">
                                    Bestellung damit abgeschlossen
                                </label>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="fa-table fa-table--compact min-w-[760px]">
                                    <thead><tr><th class="w-8"><span class="sr-only">auf Lieferschein</span></th><th>Artikel</th><th class="text-right">bestellt</th><th class="text-right">bisher</th><th class="text-right">offen</th><th class="text-right">geliefert</th><th>Abweichung</th><th>Notiz</th></tr></thead>
                                    <tbody>
                                        @foreach($rows as $z)
                                            @php($id = $z['order_line_id'])
                                            <tr wire:key="we-z-{{ $id }}" class="{{ empty($z['an']) ? 'opacity-50' : '' }}" data-we-zeile="{{ $id }}">
                                                <td><input type="checkbox" wire:model.live="zeilen.{{ $id }}.an" aria-label="auf Lieferschein"></td>
                                                <td>
                                                    <span class="font-medium">{{ $z['designation'] }}</span>
                                                    <span class="{{ $leise }}">{{ trim(($z['article_number'] ? 'Art. ' . $z['article_number'] : '') . ($z['packaging_unit'] ? ' · ' . $z['packaging_unit'] : ''), ' ·') }}</span>
                                                </td>
                                                <td class="text-right tabular-nums">{{ $zahl($z['bestellt']) }}</td>
                                                <td class="text-right tabular-nums">{{ $zahl($z['bisher']) }}</td>
                                                <td class="text-right tabular-nums">{{ $zahl($z['offen']) }}</td>
                                                <td class="text-right"><x-fa::input size="sm" numeric class="w-20" wire:model.live.blur="zeilen.{{ $id }}.qty" aria-label="geliefert" data-we-menge="{{ $id }}" /></td>
                                                <td>
                                                    <x-fa::select size="sm" wire:model.live="zeilen.{{ $id }}.grund" placeholder="–" aria-label="Abweichungsgrund">
                                                        @foreach($gruende as $gk => $gl)<option value="{{ $gk }}">{{ $gl }}</option>@endforeach
                                                    </x-fa::select>
                                                </td>
                                                <td><x-fa::input size="sm" class="w-40" wire:model.blur="zeilen.{{ $id }}.note" aria-label="Notiz" /></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach

                    {{-- Ware ohne Bestellung --}}
                    <div class="flex flex-col gap-2 pt-3 border-t border-[var(--fa-line)]" data-we-ohne>
                        <p class="font-semibold">Ware ohne Bestellung</p>
                        @foreach($ohne as $i => $o)
                            <div class="flex flex-wrap items-center gap-2" wire:key="we-ohne-{{ $i }}-{{ $o['gp_id'] }}">
                                <span class="font-medium min-w-48">{{ $o['name'] }}</span>
                                <x-fa::input size="sm" numeric class="w-24" wire:model.blur="ohne.{{ $i }}.menge" placeholder="kg / l / Stk" aria-label="Menge" />
                                <x-fa::input size="sm" class="w-56" wire:model.blur="ohne.{{ $i }}.note" placeholder="Notiz" aria-label="Notiz" />
                                <x-fa::icon-button icon="heroicon-o-x-mark" label="Entfernen" wire:click="ohneEntfernen({{ $i }})" />
                            </div>
                        @endforeach
                        <div class="flex flex-wrap items-start gap-2">
                            <div class="relative w-72 max-w-full">
                                <x-fa::input size="sm" wire:model.live.debounce.300ms="gpSuche" placeholder="Grundprodukt suchen …" aria-label="Grundprodukt suchen" data-we-gp-suche />
                                @if($gpTreffer->isNotEmpty())
                                    <div class="absolute z-10 mt-1 w-full fa-surface p-1 flex flex-col">
                                        @foreach($gpTreffer as $g)
                                            <button type="button" class="text-left px-2 py-1 rounded hover:bg-[var(--fa-neutral-soft)]" wire:click="ohneHinzu({{ $g->id }})" wire:key="we-gp-{{ $g->id }}">{{ $g->name }}</button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            @if(count($ohne) > 0 && $orte->count() > 1)
                                <x-fa::select size="sm" wire:model.live="form.location_id" placeholder="Standardlager" aria-label="Lagerort für Ware ohne Bestellung">
                                    @foreach($orte as $ort)<option value="{{ $ort->id }}">{{ $ort->name }}</option>@endforeach
                                </x-fa::select>
                            @endif
                        </div>
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-2 pt-2">
                    <x-fa::button variant="primary" icon="heroicon-o-check-circle" wire:click="buchen" :disabled="$form['supplier_id'] === null" data-we-buchen>Buchen</x-fa::button>
                    <x-fa::button wire:click="speichern" :disabled="$form['supplier_id'] === null" data-we-speichern>Als Entwurf speichern</x-fa::button>
                    <x-fa::button variant="ghost" wire:click="abbrechen">Abbrechen</x-fa::button>
                    <span class="{{ $leise }}">Buchen schreibt den Wareneingang an die Bestellungen und bucht ins Lager.</span>
                </div>
            </x-fa::section>
        @endif

        {{-- ── Erwartet ─────────────────────────────────────────────────── --}}
        @if($reiter === 'erwartet')
            <x-fa::section title="Erwartete Lieferungen" icon="heroicon-o-calendar-days" data-we-erwartet>
                @if($erwartet->isEmpty())
                    <x-fa::empty compact icon="heroicon-o-truck" title="Keine offenen Lieferungen">Gesendete und bestätigte Bestellungen erscheinen hier, bis ihr Wareneingang gebucht ist.</x-fa::empty>
                @else
                    <div class="overflow-x-auto">
                        <table class="fa-table fa-table--compact min-w-[720px]">
                            <thead><tr><th>Liefertag</th><th>Lieferant</th><th>Bestellung</th><th class="text-right">Positionen offen</th><th class="text-right">Netto</th><th>Status</th><th><span class="sr-only">Aktionen</span></th></tr></thead>
                            <tbody>
                                @foreach($erwartet as $tag => $rows)
                                    @foreach($rows as $r)
                                        <tr wire:key="we-e-{{ $r['order_id'] }}" data-we-erwartet-zeile="{{ $r['order_id'] }}">
                                            <td class="{{ $r['ueberfaellig'] ? 'text-[var(--fa-warn)] font-medium' : '' }}">
                                                {{ $datum($r['liefertag']) }}
                                                @if($r['heute'])<x-fa::badge tone="accent">heute</x-fa::badge>@endif
                                                @if($r['ueberfaellig'])<x-fa::badge tone="warn">überfällig</x-fa::badge>@endif
                                            </td>
                                            <td class="font-medium">{{ $r['lieferant'] }}</td>
                                            <td>{{ $r['nummer'] }}@if($r['reference'])<span class="{{ $leise }}"> · {{ $r['reference'] }}</span>@endif</td>
                                            <td class="text-right tabular-nums">{{ $r['offene_positionen'] }} / {{ $r['positionen'] }}</td>
                                            <td class="text-right"><x-fa::money :value="$r['total_net']" /></td>
                                            <td>
                                                <x-fa::badge>{{ $r['status_label'] }}</x-fa::badge>
                                                @if($r['teilweise_geliefert'])<x-fa::badge tone="warn">teilweise geliefert</x-fa::badge>@endif
                                            </td>
                                            <td class="text-right whitespace-nowrap">
                                                @if($darf)
                                                    <x-fa::button size="sm" variant="primary" wire:click="erfassen({{ $r['supplier_id'] }})">Lieferschein erfassen</x-fa::button>
                                                    @if($r['teilweise_geliefert'])
                                                        <x-fa::button size="sm" wire:click="nachlieferung({{ $r['order_id'] }})" wire:confirm="Bestellung {{ $r['nummer'] }} abschließen und die Fehlmenge als Nachlieferung anlegen?">Rest als Nachlieferung</x-fa::button>
                                                    @endif
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-fa::section>
        @endif

        {{-- ── Lieferscheine ────────────────────────────────────────────── --}}
        @if($reiter === 'lieferscheine')
            <x-fa::section title="Lieferscheine" icon="heroicon-o-clipboard-document-list" data-we-liste>
                <div class="flex flex-wrap items-end gap-3">
                    <x-fa::field label="Lieferant" for="we-f-lieferant" class="w-56">
                        <x-fa::select id="we-f-lieferant" wire:model.live="fLieferant" placeholder="Alle">
                            @foreach($lieferanten as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                    <x-fa::field label="Status" for="we-f-status" class="w-40">
                        <x-fa::select id="we-f-status" wire:model.live="fStatus" placeholder="Alle" :options="$statusLabels" />
                    </x-fa::field>
                    <x-fa::field label="Suche" for="we-f-suche" class="w-56">
                        <x-fa::input id="we-f-suche" wire:model.live.debounce.300ms="fSuche" placeholder="Nummer oder Artikel" />
                    </x-fa::field>
                    <label class="inline-flex items-center gap-2 pb-2 text-[length:var(--fa-text-sm)]"><input type="checkbox" wire:model.live="fAbweichung"> nur mit Abweichung</label>
                </div>
                @if($liste === [])
                    <x-fa::empty compact icon="heroicon-o-clipboard-document-list" title="Keine Lieferscheine">Erfasste Lieferscheine stehen hier — mit Abweichungen, Beleg und Status.</x-fa::empty>
                @else
                    <div class="overflow-x-auto">
                        <table class="fa-table fa-table--compact min-w-[760px]">
                            <thead><tr><th>Geliefert</th><th>Nummer</th><th>Lieferant</th><th class="text-right">Positionen</th><th class="text-right">Abweichungen</th><th class="text-right">Wert</th><th>Status</th><th><span class="sr-only">Aktionen</span></th></tr></thead>
                            <tbody>
                                @foreach($liste as $n)
                                    <tr wire:key="we-l-{{ $n['id'] }}" class="cursor-pointer" wire:click="umschalten({{ $n['id'] }})" data-we-lieferschein="{{ $n['id'] }}">
                                        <td>{{ $datum($n['delivered_on']) }}</td>
                                        <td class="font-medium">{{ $n['delivery_note_number'] ?? 'ohne Nr.' }}@if($n['anhang']) @svg('heroicon-o-paper-clip', 'inline w-3.5 h-3.5 text-[var(--fa-ink-3)]')@endif</td>
                                        <td>{{ $n['lieferant'] }}</td>
                                        <td class="text-right tabular-nums">{{ $n['positionen'] }}@if($n['ohne_bestellung'] > 0)<span class="{{ $leise }}"> ({{ $n['ohne_bestellung'] }} ohne Best.)</span>@endif</td>
                                        <td class="text-right">@if($n['abweichungen'] > 0)<x-fa::badge tone="warn">{{ $n['abweichungen'] }}</x-fa::badge>@else<span class="{{ $leise }}">–</span>@endif</td>
                                        <td class="text-right"><x-fa::money :value="$n['wert_net']" /></td>
                                        <td><x-fa::badge :tone="$statusTon[$n['status']] ?? 'neutral'">{{ $n['status_label'] }}</x-fa::badge></td>
                                        <td class="text-right whitespace-nowrap" onclick="event.stopPropagation()">
                                            @if($darf && $n['status'] === 'entwurf')
                                                <x-fa::button size="sm" wire:click="bearbeiten({{ $n['id'] }})">Bearbeiten</x-fa::button>
                                                <x-fa::button size="sm" variant="ghost" wire:click="loeschen({{ $n['id'] }})" wire:confirm="Entwurf löschen?">Löschen</x-fa::button>
                                            @elseif($darf && $n['status'] === 'gebucht')
                                                <x-fa::button size="sm" variant="ghost" wire:click="stornieren({{ $n['id'] }})" wire:confirm="Lieferschein stornieren? Wareneingang und Lager werden zurückgebucht.">Stornieren</x-fa::button>
                                            @endif
                                        </td>
                                    </tr>
                                    @if($detail !== null && $detail['id'] === $n['id'])
                                        <tr wire:key="we-d-{{ $n['id'] }}" data-we-detail="{{ $n['id'] }}">
                                            <td colspan="8" class="bg-[var(--fa-ground)]">
                                                <div class="flex flex-col gap-2 py-2">
                                                    <div class="flex flex-wrap items-center gap-3 {{ $leise }}">
                                                        @if($detail['bestellungen'] !== [])<span>Bestellungen: {{ collect($detail['bestellungen'])->map(fn ($b) => $b['nummer'] . ' (' . $b['status_label'] . ')')->implode(', ') }}</span>@endif
                                                        @if($detail['booked_at'])<span>gebucht {{ \Carbon\Carbon::parse($detail['booked_at'])->format('d.m.Y H:i') }}</span>@endif
                                                        @if($detail['note'])<span>{{ $detail['note'] }}</span>@endif
                                                        @if($detail['anhang_url'])
                                                            <a href="{{ $detail['anhang_url'] }}" target="_blank" rel="noopener" class="underline" data-we-beleg>Beleg öffnen ({{ $detail['anhang'] }})</a>
                                                            @if($darf)<button type="button" class="underline" wire:click="anhangEntfernen({{ $n['id'] }})" wire:confirm="Beleg entfernen?">entfernen</button>@endif
                                                        @endif
                                                    </div>
                                                    <table class="fa-table fa-table--compact">
                                                        <thead><tr><th>Artikel</th><th>Bestellung</th><th class="text-right">bestellt</th><th class="text-right">erwartet</th><th class="text-right">geliefert</th><th>Abweichung</th><th>Notiz</th></tr></thead>
                                                        <tbody>
                                                            @foreach($detail['zeilen'] as $z)
                                                                <tr wire:key="we-dz-{{ $z['id'] }}">
                                                                    <td class="font-medium">{{ $z['designation'] }}</td>
                                                                    <td>{{ $z['nummer'] ?? '–' }}</td>
                                                                    <td class="text-right tabular-nums">{{ $zahl($z['bestellt']) }}</td>
                                                                    <td class="text-right tabular-nums">{{ $zahl($z['erwartet']) }}</td>
                                                                    <td class="text-right tabular-nums">{{ $z['qty_packs'] !== null ? $zahl($z['qty_packs']) . ' Geb.' : $zahl($z['menge']) . ' kg/l/Stk' }}</td>
                                                                    <td>
                                                                        <x-fa::badge :tone="$abwTon[$z['abweichung']] ?? 'neutral'">{{ $abwText[$z['abweichung']] ?? $z['abweichung'] }}</x-fa::badge>
                                                                        @if($z['abweichung_grund_label'])<span class="{{ $leise }}">{{ $z['abweichung_grund_label'] }}</span>@endif
                                                                    </td>
                                                                    <td class="{{ $leise }}">{{ $z['note'] }}</td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
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
        @endif
    </x-ui-page-container>
</x-ui-page>
