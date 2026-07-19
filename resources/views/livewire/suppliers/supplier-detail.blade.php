{{-- R9.1/R9.2 UI-Slice: Lieferanten-Stammblatt als getabtes Modal.
     Oberfläche der Beziehungs-Engine (SupplierService/SupplierAgreementService). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($statusPill = ['aktiv' => $variantPill['success'], 'zweitquelle' => $variantPill['warning'], 'gesperrt' => $variantPill['danger']])

<x-foodalchemist::modal name="supplier-detail" :title="$stammblatt['name'] ?? 'Lieferant'" size="max-w-4xl">
    <div wire:key="supplier-detail-{{ $supplierId }}">
        @if($stammblatt === null)
            <p class="text-xs text-gray-500 py-8 text-center">Kein Lieferant gewählt.</p>
        @else
            <div x-data="{ tab: 'stammblatt' }" data-supplier-detail="{{ $stammblatt['id'] }}">
                {{-- Kopf: Status-Badge + D1-Hinweis --}}
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="{{ $pill }} font-medium {{ $statusPill[$stammblatt['status']] ?? $variantPill['secondary'] }}" data-status-badge>{{ $stammblatt['status'] }}</span>
                    @if($stammblatt['is_inactive'])<span class="{{ $pill }} {{ $variantPill['secondary'] }}">inaktiv</span>@endif
                    @unless($darfEdit)
                        <span class="text-[11px] text-amber-700" data-d1-hinweis>Geerbter Lieferant — Beziehungs-Pflege nur durchs Besitzer-Team (D1), hier nur Ansicht.</span>
                    @endunless
                </div>

                @if($fehler)<p class="mt-2 text-xs text-rose-600" data-fehler>{{ $fehler }}</p>@endif
                @if($hinweis)<p class="mt-2 text-xs text-emerald-600" data-hinweis>{{ $hinweis }}</p>@endif

                {{-- Tab-Leiste --}}
                <div class="mt-3 flex items-center gap-1 border-b border-black/10 -mx-1 px-1 overflow-x-auto" role="tablist">
                    @foreach([
                        ['stammblatt', 'Stammblatt'],
                        ['konditionen', 'Konditionen'],
                        ['absprachen', 'Absprachen · ' . count($stammblatt['absprachen'])],
                        ['dokumente', 'Dokumente · ' . count($stammblatt['dokumente'])],
                        ['buendelung', 'Bündelung'],
                    ] as [$key, $lbl])
                        <button type="button" @click="tab = '{{ $key }}'" data-tab="{{ $key }}"
                                :class="tab === '{{ $key }}' ? 'border-violet-500 text-violet-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
                                class="px-3 py-2 text-xs font-medium border-b-2 -mb-px whitespace-nowrap transition-colors">{{ $lbl }}</button>
                    @endforeach
                </div>

                {{-- ── Tab: Stammblatt (Status · Stammdaten · WG-Abdeckung · Kontakte · Volumen) ── --}}
                <div x-show="tab === 'stammblatt'" class="pt-4 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block {{ $label }} mb-1">Beziehungs-Status</label>
                            <select wire:model="status" wire:change="statusSetzen" @disabled(! $darfEdit) class="{{ $input }}" data-status-select>
                                @foreach(\Platform\FoodAlchemist\Enums\SupplierStatus::cases() as $s)
                                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <p class="{{ $label }} mb-1">Volumen (Nutzungs-Proxy)</p>
                            <p class="text-sm text-gray-900" data-volumen-proxy>{{ $stammblatt['volumen_proxy']['n_usages'] }} Verwendung(en)</p>
                            <p class="text-[11px] text-gray-500">{{ $stammblatt['volumen_proxy']['basis'] }}</p>
                        </div>
                    </div>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2">
                        @foreach([
                            ['Branche', $stammblatt['stammdaten']['branch']],
                            ['GLN', $stammblatt['stammdaten']['gln']],
                            ['Ort', trim(($stammblatt['stammdaten']['postal_code'] ?? '') . ' ' . ($stammblatt['stammdaten']['city'] ?? ''))],
                            ['Straße', $stammblatt['stammdaten']['address']],
                            ['Bestell-E-Mail', $stammblatt['stammdaten']['email_order']],
                            ['Homepage', $stammblatt['stammdaten']['homepage']],
                        ] as [$lbl, $wert])
                            <div class="min-w-0">
                                <dt class="{{ $dt }}">{{ $lbl }}</dt>
                                <dd class="text-xs text-gray-900 truncate" title="{{ $wert }}">{{ $wert ?: '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <div>
                        <p class="{{ $label }} mb-1">WG-Abdeckung (Stamm-Lieferant)</p>
                        @forelse($stammblatt['wg_abdeckung'] as $wg)
                            <span class="{{ $pill }} {{ $variantPill['info'] }} mr-1 mb-1 inline-block">{{ $wg }}</span>
                        @empty
                            <span class="text-xs text-gray-500">Für keine Warengruppe als Stamm gesetzt.</span>
                        @endforelse
                    </div>

                    {{-- Kontakte --}}
                    <div>
                        <p class="{{ $label }} mb-1">Ansprechpartner</p>
                        <div class="space-y-1" data-kontakt-liste>
                            @forelse($stammblatt['kontakte'] as $k)
                                <div wire:key="kontakt-{{ $k['id'] }}" class="flex items-baseline gap-2 text-xs py-1 border-b border-black/5 last:border-0">
                                    <span class="font-medium text-gray-900">{{ $k['name'] }}</span>
                                    @if($k['role'])<span class="text-gray-500">· {{ $k['role'] }}</span>@endif
                                    @if($k['phone'])<span class="text-gray-500">· {{ $k['phone'] }}</span>@endif
                                    @if($k['email'])<span class="text-gray-500">· {{ $k['email'] }}</span>@endif
                                </div>
                            @empty
                                <p class="text-xs text-gray-500">Noch keine Kontakte.</p>
                            @endforelse
                        </div>
                        @if($darfEdit)
                            <div class="mt-2 grid grid-cols-4 gap-2" data-kontakt-neu>
                                <input type="text" wire:model="neuKontakt.name" placeholder="Name *" class="{{ $input }}" data-kontakt-name />
                                <input type="text" wire:model="neuKontakt.role" placeholder="Rolle" class="{{ $input }}" />
                                <input type="text" wire:model="neuKontakt.phone" placeholder="Telefon" class="{{ $input }}" />
                                <input type="text" wire:model="neuKontakt.email" placeholder="E-Mail" class="{{ $input }}" />
                            </div>
                            <button type="button" wire:click="kontaktAnlegen" class="{{ $btnGhostXs }} text-violet-600 mt-2" data-kontakt-add>+ Kontakt</button>
                        @endif
                    </div>
                </div>

                {{-- ── Tab: Konditionen ── --}}
                <div x-show="tab === 'konditionen'" x-cloak class="pt-4 space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="block {{ $label }} mb-1">Rückvergütung / Bonus %</label>
                            <input type="text" wire:model="konditionen.rebate_pct" @disabled(! $darfEdit) class="{{ $input }}" data-kond-rebate /></div>
                        <div><label class="block {{ $label }} mb-1">Zahlungsziel (Tage)</label>
                            <input type="text" wire:model="konditionen.payment_term_days" @disabled(! $darfEdit) class="{{ $input }}" data-kond-payment /></div>
                        <div><label class="block {{ $label }} mb-1">Mindestbestellwert €</label>
                            <input type="text" wire:model="konditionen.min_order_value" @disabled(! $darfEdit) class="{{ $input }}" /></div>
                        <div><label class="block {{ $label }} mb-1">Frei-Haus-Grenze €</label>
                            <input type="text" wire:model="konditionen.free_shipping_threshold" @disabled(! $darfEdit) class="{{ $input }}" /></div>
                    </div>
                    @if($darfEdit)
                        <button type="button" wire:click="konditionenSpeichern" class="{{ $btnPrimary }}" data-kond-save>Konditionen speichern</button>
                    @endif
                    <p class="text-[11px] text-gray-500">Rückvergütung ≠ Listen-EK — fließt langfristig in die echte Marge (R2).</p>
                </div>

                {{-- ── Tab: Absprachen ── --}}
                <div x-show="tab === 'absprachen'" x-cloak class="pt-4 space-y-3">
                    <div class="space-y-1" data-absprache-liste>
                        @forelse($stammblatt['absprachen'] as $a)
                            <div wire:key="absprache-{{ $a['id'] }}" class="text-xs py-1.5 border-b border-black/5 last:border-0">
                                <div class="flex items-baseline gap-2">
                                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $a['type'] }}</span>
                                    <span class="text-gray-900 flex-1">{{ $a['note'] }}</span>
                                </div>
                                <div class="text-[11px] text-gray-500 mt-0.5">
                                    @if($a['valid_from'])gilt ab {{ $a['valid_from'] }} @endif
                                    @if($a['valid_to'])bis {{ $a['valid_to'] }} @endif
                                    @if($a['follow_up_at'])
                                        · <span class="{{ $a['follow_up_at'] <= $heute->toDateString() ? 'text-amber-700 font-medium' : 'text-gray-500' }}" data-wiedervorlage>Wiedervorlage {{ $a['follow_up_at'] }}</span>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-gray-500">Noch keine Absprachen.</p>
                        @endforelse
                    </div>
                    @if($darfEdit)
                        <div class="grid grid-cols-2 gap-2" data-absprache-neu>
                            <select wire:model="neueAbsprache.type" class="{{ $input }}">
                                @foreach(['absprache', 'zusage', 'konditionsvereinbarung', 'sonstiges'] as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach
                            </select>
                            <input type="date" wire:model="neueAbsprache.follow_up_at" title="Wiedervorlage" class="{{ $input }}" />
                            <input type="text" wire:model="neueAbsprache.note" placeholder="Absprache / Zusage *" class="{{ $input }} col-span-2" data-absprache-note />
                            <input type="date" wire:model="neueAbsprache.valid_from" title="gilt ab" class="{{ $input }}" />
                            <input type="date" wire:model="neueAbsprache.valid_to" title="gilt bis" class="{{ $input }}" />
                        </div>
                        <button type="button" wire:click="abspracheAnlegen" class="{{ $btnGhostXs }} text-violet-600" data-absprache-add>+ Absprache</button>
                    @endif
                </div>

                {{-- ── Tab: Dokumente ── --}}
                <div x-show="tab === 'dokumente'" x-cloak class="pt-4 space-y-3">
                    <div class="space-y-1" data-dokument-liste>
                        @forelse($stammblatt['dokumente'] as $d)
                            <div wire:key="dokument-{{ $d['id'] }}" class="text-xs py-1.5 border-b border-black/5 last:border-0">
                                <div class="flex items-baseline gap-2">
                                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $d['kind'] }}</span>
                                    <span class="text-gray-900 flex-1">{{ $d['title'] ?: '—' }}</span>
                                    @if($d['file_ref'])<span class="text-[11px] text-violet-600 truncate max-w-[12rem]" title="{{ $d['file_ref'] }}">{{ $d['file_ref'] }}</span>@endif
                                </div>
                                <div class="text-[11px] text-gray-500 mt-0.5">
                                    @if($d['term_start'])Laufzeit {{ $d['term_start'] }} @endif
                                    @if($d['term_end'])– {{ $d['term_end'] }} @endif
                                    @if($d['notice_period_days'] !== null)· Frist {{ $d['notice_period_days'] }} T @endif
                                    @if($d['notice_deadline'])
                                        · <span class="{{ $d['notice_deadline'] <= $heute->toDateString() ? 'text-amber-700 font-medium' : 'text-gray-500' }}" data-notice-deadline>Kündigen bis {{ $d['notice_deadline'] }}</span>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-gray-500">Noch keine Dokumente.</p>
                        @endforelse
                    </div>
                    @if($darfEdit)
                        <div class="grid grid-cols-2 gap-2" data-dokument-neu>
                            <select wire:model="neuesDokument.kind" class="{{ $input }}">
                                @foreach(['vertrag', 'rahmenvereinbarung', 'preisliste', 'zertifikat', 'sonstiges'] as $k)<option value="{{ $k }}">{{ $k }}</option>@endforeach
                            </select>
                            <input type="text" wire:model="neuesDokument.title" placeholder="Titel" class="{{ $input }}" data-dokument-title />
                            <input type="text" wire:model="neuesDokument.file_ref" placeholder="Datei-Referenz (Pfad/Link)" class="{{ $input }} col-span-2" />
                            <input type="date" wire:model="neuesDokument.term_start" title="Laufzeit ab" class="{{ $input }}" />
                            <input type="date" wire:model="neuesDokument.term_end" title="Laufzeit bis" class="{{ $input }}" />
                            <input type="number" wire:model="neuesDokument.notice_period_days" placeholder="Kündigungsfrist (Tage)" class="{{ $input }} col-span-2" data-dokument-notice />
                        </div>
                        <button type="button" wire:click="dokumentAnlegen" class="{{ $btnGhostXs }} text-violet-600" data-dokument-add>+ Dokument</button>
                        <p class="text-[11px] text-gray-500">Laufzeit + Kündigungsfrist speisen das Vertragsfrist-Signal (E7).</p>
                    @endif
                </div>

                {{-- ── Tab: Bündelung (R9.2 E6 — Volumen-Proxy × Konditionen) ── --}}
                <div x-show="tab === 'buendelung'" x-cloak class="pt-4">
                    <p class="text-[11px] text-gray-500 mb-2">Nutzungs-Proxy (Rezept-Zutaten via Lead-LA) × hinterlegte Konditionen — <strong>kein echtes Spend/Umsatz</strong> (kommt mit Q2). Zeigt, wo Bündelung/Nachverhandlung lohnt.</p>
                    <div class="overflow-x-auto">
                        <table class="{{ $table }}" data-buendelung-tabelle>
                            <thead><tr class="text-left">@foreach(['Lieferant', 'Verwendungen', 'Rückverg. %', 'Zahlungsziel', 'Hinweis'] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
                            <tbody>
                                @forelse($buendelung as $b)
                                    <tr wire:key="buendel-{{ $b['supplier_id'] }}" class="{{ $tr }} {{ $b['supplier_id'] === $stammblatt['id'] ? 'bg-violet-500/5' : '' }}">
                                        <td class="{{ $td }} font-medium text-gray-900">{{ $b['name'] }}</td>
                                        <td class="{{ $td }} tabular-nums">{{ $b['n_usages'] }}</td>
                                        <td class="{{ $td }} tabular-nums">{{ $b['rebate_pct'] !== null ? number_format($b['rebate_pct'], 1, ',', '.') . ' %' : '—' }}</td>
                                        <td class="{{ $td }} tabular-nums">{{ $b['payment_term_days'] !== null ? $b['payment_term_days'] . ' T' : '—' }}</td>
                                        <td class="{{ $td }} text-gray-600 text-[11px]">{{ $b['bundling_hint'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">Noch keine Nutzung erfasst — Proxy braucht Rezepte mit Lead-LAs dieser Lieferanten.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <x-slot:footer>
        <button type="button" @click="$dispatch('modal.close', { name: 'supplier-detail' })" class="{{ $btnGhost }}">Schließen</button>
    </x-slot:footer>
</x-foodalchemist::modal>
