{{-- 06·H2: Kuratierungs-Screen Favoriten-GPs (Auto-Score + Pin/Exclude). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Favoriten" icon="heroicon-o-star" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Favoriten'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="max-w-5xl space-y-4">
            <p class="text-[11px] text-gray-500">
                Deine kuratierten <strong>Lieblings-GPs</strong> — jeder Grundprodukt ist pinbar (nicht nur Convenience).
                Auto-Score (Nutzung × Lead-LA × Lieferanten-Priorität) schlägt vor, du pinnst (★) deine Favoriten.
                Gepinnte GPs fließen im Generator nur ein, wenn dort der Modus „⭐ Auf Basis meiner Favoriten bauen" aktiviert ist (Default aus).
                Das <span class="text-emerald-600">Conv</span>-Kürzel markiert Convenience-getaggte GPs.
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <input type="text" wire:model.live.debounce.300ms="q" placeholder="GP suchen…" class="{{ $input }} !py-1 w-64" />
                <label class="flex items-center gap-1.5 text-[11px] text-gray-600">
                    <input type="checkbox" wire:model.live="nurGepinnt" /> nur gepinnte (★)
                </label>
                <span class="text-[11px] text-gray-500">{{ $anzahlGepinnt }} gepinnt · {{ $items->count() }} Zeilen</span>
            </div>

            <div class="relative overflow-hidden {{ $card }}">
                <div class="overflow-x-auto">
                    <table class="{{ $table }}">
                        <thead>
                            <tr>
                                <th class="{{ $th }} text-left">GP</th>
                                <th class="{{ $th }} text-right">Nutzung</th>
                                <th class="{{ $th }} text-center">Lead-LA</th>
                                <th class="{{ $th }} text-center">Preis</th>
                                <th class="{{ $th }} text-right">Score</th>
                                <th class="{{ $th }} text-right">Rang</th>
                                <th class="{{ $th }} text-right">Aktion</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($items as $r)
                                <tr class="{{ $tr }}">
                                    <td class="{{ $td }}">
                                        @if($r['is_favorite'])<span class="text-amber-500" title="gepinnt">★</span> @endif
                                        <a href="{{ route('foodalchemist.gps.index', ['gp' => $r['gp_id'], 'edit' => 1]) }}" wire:navigate
                                            class="text-gray-800 hover:text-violet-600 hover:underline" title="Grundprodukt öffnen">{{ $r['name'] }}</a>
                                        @if($r['is_convenience'])<span class="text-[9px] uppercase tracking-wide text-emerald-600 border border-emerald-200 rounded px-1" title="Convenience-getaggt">Conv</span>@endif
                                        <span class="text-[10px] text-gray-400">#{{ $r['gp_id'] }}</span>
                                    </td>
                                    <td class="{{ $td }} text-right">{{ $r['usage'] }}</td>
                                    <td class="{{ $td }} text-center">{{ $r['has_lead_la'] ? '✓' : '—' }}</td>
                                    <td class="{{ $td }} text-center">{{ $r['has_price'] ? '✓' : '—' }}</td>
                                    <td class="{{ $td }} text-right font-medium">{{ number_format($r['score'], 2, ',', '.') }}</td>
                                    <td class="{{ $td }} text-right">
                                        @if($r['is_favorite'])
                                            <input type="number" min="0"
                                                value="{{ $r['favorite_rank'] }}"
                                                wire:change="setRank({{ $r['gp_id'] }}, $event.target.value)"
                                                class="{{ $input }} !py-0.5 w-16 text-right" />
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }} text-right">
                                        @if($r['is_favorite'])
                                            <button wire:click="exclude({{ $r['gp_id'] }})" class="{{ $btnGhostXs }}">entfernen</button>
                                        @else
                                            <button wire:click="pin({{ $r['gp_id'] }})" class="{{ $btnGhostXs }}">★ pinnen</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="{{ $td }} text-center text-gray-400 py-4">Keine GPs gefunden.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </x-ui-page-container>
</x-ui-page>
