{{-- M12-02 / Doc 16: Kalkulations-Übersicht — HK1/HK2/VK/DB + aufklappbarer HK2-Wasserfall je Zeile --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Kalkulation (HK2)" icon="heroicon-o-calculator" />
    </x-slot:navbar>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="flex items-center justify-between flex-wrap gap-2 pt-1">
            <div class="flex items-center gap-1.5">
                <button type="button" wire:click="setTab('gerichte')" class="{{ $pill }} {{ $tab === 'gerichte' ? $variantPill['primary'] : $variantPill['secondary'] }}">Gerichte</button>
                <button type="button" wire:click="setTab('concepts')" class="{{ $pill }} {{ $tab === 'concepts' ? $variantPill['primary'] : $variantPill['secondary'] }}">Concepts</button>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="suchen …" class="{{ $input }} w-48 ml-2" />
            </div>
            <p class="text-[11px] text-gray-400">Zeile anklicken → <strong>HK2-Aufschlüsselung</strong> (Wareneinsatz → +Lohn → +Gemeinkosten → HK2 → VK-Vorschlag). Blöcke pflegst du in Einstellungen → Kalkulation.</p>
        </div>

        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            <div class="overflow-x-auto">
                <table class="{{ $table }}">
                    <thead><tr class="text-left">
                        @foreach([['Name', 'w-full'], ['HK1', 'text-right'], ['+Zuschläge', 'text-right'], ['HK2', 'text-right'], ['VK', 'text-right'], ['VK-Vorschl.', 'text-right'], ['DB €', 'text-right'], ['DB %', 'text-right']] as [$h, $a])
                            <th class="{{ $th }} {{ $a }}">{{ $h }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @forelse($zeilen as $z)
                            @php($istSel = $selectedId === $z['id'])
                            @php($zuschlaege = (float) $z['hk']['hk2'] - (float) $z['hk']['hk1'])
                            <tr wire:key="kalk-{{ $tab }}-{{ $z['id'] }}" wire:click="waehle({{ $z['id'] }})"
                                class="{{ $tr }} cursor-pointer {{ $istSel ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10' : '' }}">
                                <td class="{{ $td }} font-medium w-full max-w-0 min-w-44 truncate text-gray-900 dark:text-gray-100">
                                    <span class="text-gray-400 mr-1">{{ $istSel ? '▾' : '▸' }}</span>{{ $z['name'] }} <span class="text-[10px] text-gray-400">{{ $z['einheit'] }}</span>
                                </td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500 whitespace-nowrap">{{ number_format((float) $z['hk']['hk1'], 2, ',', '.') }} €</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500 whitespace-nowrap">+ {{ number_format($zuschlaege, 2, ',', '.') }} €</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-900 dark:text-gray-100 whitespace-nowrap font-medium">{{ number_format((float) $z['hk']['hk2'], 2, ',', '.') }} €</td>
                                <td class="{{ $td }} text-right tabular-nums whitespace-nowrap">{{ $z['hk']['vk'] !== null ? number_format((float) $z['hk']['vk'], 2, ',', '.') . ' €' : '—' }}</td>
                                <td class="{{ $td }} text-right tabular-nums text-violet-700 dark:text-violet-300 whitespace-nowrap">{{ $z['hk']['vk_vorschlag'] !== null ? number_format((float) $z['hk']['vk_vorschlag'], 2, ',', '.') . ' €' : '—' }}</td>
                                <td class="{{ $td }} text-right tabular-nums whitespace-nowrap {{ ($z['hk']['db_eur'] ?? 0) < 0 ? 'text-red-500' : 'text-emerald-600 dark:text-emerald-400' }}">{{ $z['hk']['db_eur'] !== null ? number_format((float) $z['hk']['db_eur'], 2, ',', '.') . ' €' : '—' }}</td>
                                <td class="{{ $td }} text-right tabular-nums whitespace-nowrap">
                                    @if($z['hk']['db_pct'] !== null)
                                        <span class="{{ $pill }} {{ $z['hk']['db_pct'] < 0 ? $variantPill['danger'] : ($z['hk']['db_pct'] < 30 ? $variantPill['warning'] : $variantPill['success']) }}">{{ number_format((float) $z['hk']['db_pct'], 1, ',', '.') }} %</span>
                                    @else — @endif
                                </td>
                            </tr>

                            {{-- Aufgeklappte HK2-Aufschlüsselung (Wasserfall) direkt unter der Zeile --}}
                            @if($istSel && $detail !== null)
                                @php($hk = $detail['hk'])
                                @php($hk2 = (float) ($hk['hk2_pro_portion'] ?? $hk['hk2_pro_person'] ?? 0))
                                <tr wire:key="kalk-detail-{{ $z['id'] }}" class="bg-violet-500/[0.04]">
                                    <td colspan="8" class="px-6 py-3">
                                        <div class="max-w-md space-y-1">
                                            <p class="{{ $label }} mb-1">HK2-Aufschlüsselung {{ $detail['einheit'] }} · Marge {{ rtrim(rtrim(number_format((float) $hk['marge_pct'], 2, ',', '.'), '0'), ',') }} %</p>
                                            @foreach($hk['bloecke'] as $blk)
                                                <div class="flex items-center justify-between text-xs py-0.5 {{ $blk['key'] === 'we' ? 'font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-300' }}">
                                                    <span>{{ $blk['key'] === 'we' ? 'Wareneinsatz' : '+ ' . $blk['label'] }}</span>
                                                    <span class="tabular-nums">{{ number_format((float) $blk['betrag'], 2, ',', '.') }} €</span>
                                                </div>
                                            @endforeach
                                            <div class="flex items-center justify-between text-xs py-1 border-t border-black/10 dark:border-white/10 font-semibold text-gray-900 dark:text-gray-100">
                                                <span>= HK2 (Herstellkosten)</span><span class="tabular-nums">{{ number_format($hk2, 2, ',', '.') }} €</span>
                                            </div>
                                            <div class="flex items-center justify-between text-xs">
                                                <span class="text-gray-500">VK-Vorschlag (HK2 × Marge)</span>
                                                <span class="tabular-nums text-violet-700 dark:text-violet-300 font-medium">{{ number_format((float) $hk['vk_vorschlag'], 2, ',', '.') }} €</span>
                                            </div>
                                            @if($hk['db_eur'] !== null)
                                                <div class="flex items-center justify-between text-xs">
                                                    <span class="text-gray-500">Deckungsbeitrag (gesetzter VK − HK2)</span>
                                                    <span class="tabular-nums {{ (float) $hk['db_eur'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">{{ number_format((float) $hk['db_eur'], 2, ',', '.') }} €{{ $hk['db_pct'] !== null ? ' · ' . number_format((float) $hk['db_pct'], 1, ',', '.') . ' %' : '' }}</span>
                                                </div>
                                            @endif
                                            <p class="text-[10px] text-gray-400 pt-1">Arbeitszeit-Schätzung (nicht-linear skalierbar) folgt mit der KI.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="8" class="px-5 py-10 text-center text-gray-400">Keine {{ $tab === 'concepts' ? 'Concepts' : 'Gerichte' }} gefunden.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-3 border-t border-black/5 dark:border-white/10">{{ $page->links() }}</div>
        </div>
    </x-ui-page-container>
</x-ui-page>
