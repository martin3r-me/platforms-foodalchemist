{{-- M12-02 / Doc 15 §M12: Kalkulations-Übersicht — HK1/HK2/VK/Vollkosten-DB --}}
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
            <p class="text-[11px] text-gray-400">HK1 = Wareneinsatz (verlustkorr.) · HK2 = HK1 + {{ number_format($zuschlag, 1, ',', '.') }} % Gemeinkosten + Nebenkosten · DB = VK − HK2. Zuschlag in den Einstellungen.</p>
        </div>

        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            <div class="overflow-x-auto">
                <table class="{{ $table }}">
                    <thead><tr class="text-left">
                        @foreach([['Name', 'w-full'], ['HK1', 'text-right'], ['HK2', 'text-right'], ['VK', 'text-right'], ['DB €', 'text-right'], ['DB %', 'text-right']] as [$h, $a])
                            <th class="{{ $th }} {{ $a }}">{{ $h }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @forelse($zeilen as $z)
                            <tr wire:key="kalk-{{ $tab }}-{{ $z['id'] }}" class="{{ $tr }}">
                                <td class="{{ $td }} font-medium w-full max-w-0 min-w-44 truncate text-gray-900 dark:text-gray-100">{{ $z['name'] }} <span class="text-[10px] text-gray-400">{{ $z['einheit'] }}</span></td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-500 whitespace-nowrap">{{ number_format((float) $z['hk']['hk1'], 2, ',', '.') }} €</td>
                                <td class="{{ $td }} text-right tabular-nums text-gray-900 dark:text-gray-100 whitespace-nowrap">{{ number_format((float) $z['hk']['hk2'], 2, ',', '.') }} €</td>
                                <td class="{{ $td }} text-right tabular-nums whitespace-nowrap">{{ $z['hk']['vk'] !== null ? number_format((float) $z['hk']['vk'], 2, ',', '.') . ' €' : '—' }}</td>
                                <td class="{{ $td }} text-right tabular-nums whitespace-nowrap {{ ($z['hk']['db_eur'] ?? 0) < 0 ? 'text-red-500' : 'text-emerald-600 dark:text-emerald-400' }}">{{ $z['hk']['db_eur'] !== null ? number_format((float) $z['hk']['db_eur'], 2, ',', '.') . ' €' : '—' }}</td>
                                <td class="{{ $td }} text-right tabular-nums whitespace-nowrap">
                                    @if($z['hk']['db_pct'] !== null)
                                        <span class="{{ $pill }} {{ $z['hk']['db_pct'] < 0 ? $variantPill['danger'] : ($z['hk']['db_pct'] < 30 ? $variantPill['warning'] : $variantPill['success']) }}">{{ number_format((float) $z['hk']['db_pct'], 1, ',', '.') }} %</span>
                                    @else — @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-10 text-center text-gray-400">Keine {{ $tab === 'concepts' ? 'Concepts' : 'Gerichte' }} gefunden.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-3 border-t border-black/5 dark:border-white/10">{{ $page->links() }}</div>
        </div>
    </x-ui-page-container>
</x-ui-page>
