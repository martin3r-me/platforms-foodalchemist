{{-- Spec 17/S2 — Bestellungen: Bestellschienen je Lieferant (Liste + Detail) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($statusLabels = ['draft' => 'Entwurf', 'sent' => 'versendet', 'confirmed' => 'bestätigt', 'delivered' => 'geliefert', 'cancelled' => 'storniert'])

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Bestellungen" icon="heroicon-o-shopping-cart" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Bestellungen'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">

        {{-- Status-Filter --}}
        <div class="flex items-center gap-2">
            <span class="{{ $label }}">Status</span>
            <div class="inline-flex rounded-lg bg-black/[0.03] p-0.5 text-xs">
                <button wire:click="$set('statusFilter','')" class="px-3 py-1 rounded-md {{ $statusFilter === '' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">alle</button>
                @foreach(['draft','sent','confirmed','delivered','cancelled'] as $s)
                    <button wire:click="$set('statusFilter','{{ $s }}')" class="px-3 py-1 rounded-md {{ $statusFilter === $s ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">{{ $statusLabels[$s] }}</button>
                @endforeach
            </div>
        </div>

        @if($hinweis)<div class="{{ $sectionCard }} !bg-emerald-500/[0.06] !border-emerald-500/20 text-[12px] text-emerald-700">✓ {{ $hinweis }}</div>@endif
        @if($fehler)<div class="{{ $sectionCard }} !bg-rose-500/[0.06] !border-rose-500/20 text-[12px] text-rose-700">{{ $fehler }}</div>@endif

        <div class="grid lg:grid-cols-3 gap-4">
            {{-- ── Liste ── --}}
            <div class="{{ $sectionCard }} lg:col-span-1">
                <h3 class="font-medium tracking-tight text-gray-900 mb-2">Schienen &amp; Bestellungen</h3>
                @forelse($liste as $o)
                    <button wire:click="select({{ $o['id'] }})" wire:key="ord-{{ $o['id'] }}"
                        class="block w-full text-left px-3 py-2 rounded-lg mb-1 border {{ $selectedId === $o['id'] ? 'border-violet-500/40 bg-violet-500/5' : 'border-black/5 hover:bg-black/[0.02]' }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[13px] font-medium text-gray-900">{{ $o['supplier'] }}</span>
                            <span class="{{ $pill }} {{ $variantPill[$o['status']->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $o['status']->label() }}</span>
                        </div>
                        <div class="text-[11px] text-gray-500 mt-0.5">{{ number_format($o['total_net'], 2, ',', '.') }} € netto @if($o['reference'])· {{ $o['reference'] }}@endif</div>
                    </button>
                @empty
                    <p class="text-[12px] text-gray-500 py-6 text-center">Keine Bestellungen. Bedarf im Planungs-Blatt übernehmen.</p>
                @endforelse
            </div>

            {{-- ── Detail ── --}}
            <div class="{{ $sectionCard }} lg:col-span-2">
                @if($detail === null)
                    <p class="text-[12px] text-gray-500 py-10 text-center">Eine Bestellschiene links wählen.</p>
                @else
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <div>
                            <h3 class="font-medium tracking-tight text-gray-900">{{ $detail['supplier'] }}</h3>
                            <p class="text-[11px] text-gray-500">{{ $detail['status_label'] }} · {{ number_format($detail['total_net'], 2, ',', '.') }} € netto</p>
                        </div>
                        <div class="flex flex-wrap gap-1.5 justify-end">
                            @foreach($erlaubteStatus as $z)
                                <button wire:click="setStatus('{{ $z->value }}')"
                                    class="{{ $z->value === 'sent' ? $btnPrimary : $btnGhost }}"
                                    @if($z->value === 'cancelled') onclick="return confirm('Bestellung stornieren?')" @endif
                                    data-status-{{ $z->value }}>{{ $z === \Platform\FoodAlchemist\Enums\OrderStatus::Sent ? 'Absenden' : $z->label() }}</button>
                            @endforeach
                        </div>
                    </div>

                    {{-- S3: Export/Versand --}}
                    <div class="flex flex-wrap items-center gap-2 mb-3">
                        <a href="{{ route('foodalchemist.orders.dokument', ['order' => $detail['id']]) }}" target="_blank" class="{{ $btnGhost }}">🖨 Dokument</a>
                        <a href="{{ route('foodalchemist.orders.dokument', ['order' => $detail['id'], 'pdf' => 1]) }}" class="{{ $btnGhost }}">PDF</a>
                        <a href="{{ route('foodalchemist.orders.dokument', ['order' => $detail['id'], 'csv' => 1]) }}" class="{{ $btnGhost }}">CSV</a>
                        @if($mailto)
                            <a href="{{ $mailto }}" class="{{ $btnGhost }}">✉ E-Mail an Lieferant</a>
                        @else
                            <span class="text-[11px] text-gray-400">✉ keine Bestell-Mail hinterlegt (Lieferant → email_order)</span>
                        @endif
                    </div>

                    {{-- MOQ-Ampel --}}
                    @php($moq = $detail['moq'])
                    <div class="flex flex-wrap gap-2 mb-3 text-[11px]">
                        @if($moq['unter_mindestbestellwert'])
                            <span class="px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-700">Unter Mindestbestellwert — es fehlen {{ number_format($moq['fehlt_bis_min'], 2, ',', '.') }} €</span>
                        @elseif($moq['min_order_value'] !== null)
                            <span class="px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-700">Mindestbestellwert erreicht</span>
                        @endif
                        @if($moq['frei_haus'])
                            <span class="px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-700">frei Haus</span>
                        @elseif($moq['free_shipping_threshold'] !== null)
                            <span class="px-2 py-0.5 rounded-md bg-black/5 text-gray-600">{{ number_format($moq['fehlt_bis_frei_haus'], 2, ',', '.') }} € bis frei Haus</span>
                        @endif
                    </div>

                    <table class="{{ $table }}">
                        <thead><tr>
                            <th class="{{ $th }} text-left">Artikel</th>
                            <th class="{{ $th }} text-right">Bedarf</th>
                            <th class="{{ $th }} text-right">Bestellen</th>
                            <th class="{{ $th }} text-right">Preis/Geb.</th>
                            <th class="{{ $th }} text-right">Summe</th>
                            <th class="{{ $th }}"></th>
                        </tr></thead>
                        <tbody>
                            @foreach($detail['zeilen'] as $z)
                                <tr class="border-t border-black/5" wire:key="line-{{ $z['id'] }}">
                                    <td class="{{ $td }} text-gray-800">
                                        {{ $z['designation'] ?: '—' }}
                                        @if($z['article_number'])<br><span class="text-[10px] text-gray-400">Art. {{ $z['article_number'] }}@if($z['packaging_unit']) · {{ $z['packaging_unit'] }}@endif</span>@endif
                                        @unless($z['bestellbar'])<br><span class="text-[10px] text-amber-600">nicht in Gebinde bestellbar (Preis/Gebinde fehlt)</span>@endunless
                                    </td>
                                    <td class="{{ $td }} text-right whitespace-nowrap text-gray-500">{{ number_format($z['needed_base_g'] / 1000, 3, ',', '.') }} {{ $z['unit_code'] === 'Stk' ? 'kg' : ($z['unit_code'] ?: 'kg') }}</td>
                                    <td class="{{ $td }} text-right whitespace-nowrap">
                                        @if($detail['editierbar'])
                                            <input type="number" min="0" step="1" value="{{ (float) $z['qty_packs'] }}"
                                                wire:change="updateLineQty({{ $z['id'] }}, $event.target.value)"
                                                class="w-16 text-right {{ $input }} {{ $z['is_manual_qty'] ? '!border-amber-400' : '' }}" />
                                            @if($z['is_manual_qty'])<button wire:click="resetLineQty({{ $z['id'] }})" title="Auto-Menge" class="text-[10px] text-violet-600 ml-1">auto</button>@endif
                                        @else
                                            {{ (float) $z['qty_packs'] }}
                                        @endif
                                        @if($z['packaging_unit'])<span class="text-[10px] text-gray-400"> {{ $z['packaging_unit'] }}</span>@endif
                                    </td>
                                    <td class="{{ $td }} text-right whitespace-nowrap text-gray-700">{{ $z['pack_price'] !== null ? number_format($z['pack_price'], 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="{{ $td }} text-right whitespace-nowrap font-medium text-gray-900">{{ number_format($z['line_total'], 2, ',', '.') }} €</td>
                                    <td class="{{ $td }} text-right">
                                        @if($detail['editierbar'])<button wire:click="removeLine({{ $z['id'] }})" title="Entfernen" class="text-[11px] text-rose-500">✕</button>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-black/10">
                                <td class="{{ $td }} font-medium text-gray-900" colspan="4">Wareneinsatz gesamt (netto)</td>
                                <td class="{{ $td }} text-right font-semibold text-gray-900">{{ number_format($detail['total_net'], 2, ',', '.') }} €</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                    @unless($detail['editierbar'])
                        <p class="text-[11px] text-gray-400 mt-2">Versendeter Beleg — eingefroren, nicht mehr editierbar.</p>
                    @endunless
                @endif
            </div>
        </div>

    </x-ui-page-container>
</x-ui-page>
