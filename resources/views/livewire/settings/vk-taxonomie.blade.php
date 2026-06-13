{{-- D-6 §4.6: VK-Taxonomie — Master-Detail (Speisen-HG links, Klassen-Tabelle rechts); read-only Referenzdaten --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($katAktiv = 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300')
@php($katHover = 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5')

<div class="space-y-4" data-settings-vk-taxonomie>
    @if($meldung !== null)<div class="{{ $card }} p-3 border-emerald-500/20"><p class="text-xs text-emerald-600 dark:text-emerald-400" data-taxo-meldung>{{ $meldung }}</p></div>@endif

    <div class="flex gap-4 items-start">
        {{-- Speisen-Hauptgruppen links --}}
        <div class="w-80 shrink-0 {{ $card }} p-3 space-y-0.5" data-taxo-hgs>
            <div class="{{ $label }} px-2 pb-2">Speisen-Hauptgruppen ({{ $hauptgruppen->count() }})</div>
            @foreach($hauptgruppen as $hg)
                <button type="button" wire:key="thg-{{ $hg->id }}" wire:click="waehleHg({{ $hg->id }})"
                        class="w-full flex items-center gap-1.5 px-2 py-1.5 rounded-lg text-xs transition-all duration-150 {{ $hauptgruppeId === $hg->id ? $katAktiv : $katHover }} {{ $hg->is_inactive ? 'opacity-50' : '' }}">
                    <span class="font-mono text-[10px] text-gray-400">{{ $hg->code }}</span>
                    <span class="min-w-0 truncate text-left flex-1">{{ $hg->bezeichnung }}</span>
                    <span class="text-[11px] text-gray-400 shrink-0">{{ $klassenJeHg[$hg->id] ?? 0 }}</span>
                </button>
            @endforeach
            <p class="text-[10px] text-gray-400 px-2 pt-2 leading-snug">Klassen = HG × Diätform (Referenzdaten, read-only). Aufschlagsklassen, Schreibstile, Behälter: eigene Seiten (R5).</p>
        </div>

        {{-- Klassen der gewählten HG rechts --}}
        <div class="flex-1 min-w-0">
            <div class="relative overflow-hidden {{ $card }}" data-taxo-klassen>
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Klassen</h3>
                    <span class="{{ $label }}">{{ optional($hauptgruppen->firstWhere('id', $hauptgruppeId))->bezeichnung ?? 'Hauptgruppe wählen' }}</span>
                </div>
                @if($klassen->isNotEmpty())
                    <table class="{{ $table }}">
                        <thead><tr class="text-left">@foreach(['Klasse', 'Diätform', 'Diät-Flags', 'Rezepte'] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach($klassen as $k)
                                <tr class="{{ $tr }}" wire:key="tk-{{ $k->id }}">
                                    <td class="{{ $td }} text-gray-900 dark:text-gray-100">{{ $k->bezeichnung }} <span class="text-[10px] font-mono text-gray-400">{{ $k->code }}</span></td>
                                    <td class="{{ $td }}"><span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $k->diaetform }}</span></td>
                                    <td class="{{ $td }} text-[11px] text-gray-400">{{ collect(['vegan' => $k->is_vegan, 'vegi' => $k->is_vegi, 'halal' => $k->is_halal, 'koscher' => $k->is_koscher])->filter()->keys()->implode(' · ') ?: '—' }}</td>
                                    <td class="{{ $td }} text-gray-500">{{ $klassenZaehler[$k->id] ?? 0 }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="px-5 pb-5 text-xs text-gray-400">Links eine Speisen-Hauptgruppe wählen, um ihre Klassen zu sehen.</div>
                @endif
            </div>
        </div>
    </div>
</div>
