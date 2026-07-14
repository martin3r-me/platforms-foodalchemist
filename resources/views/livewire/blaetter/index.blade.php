{{-- R7.1 — Operative Planungs-Blätter: Ziel + Skalierung → Produktion + Bestellung (read-only) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Planungs-Blätter" icon="heroicon-o-clipboard-document-list" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Planungs-Blätter'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">

        {{-- ── Ziel + Skalierung ────────────────────────────────────────── --}}
        <div class="relative overflow-hidden {{ $card }} px-4 py-3">
            <div class="{{ $cardAccent }}"></div>
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="{{ $label }} block mb-1">Ziel</label>
                    <div class="inline-flex rounded-lg bg-black/[0.03] p-0.5">
                        <button wire:click="$set('zielTyp','concept')" class="px-3 py-1 text-xs rounded-md {{ $zielTyp === 'concept' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Konzept</button>
                        <button wire:click="$set('zielTyp','recipe')" class="px-3 py-1 text-xs rounded-md {{ $zielTyp === 'recipe' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Gericht</button>
                    </div>
                </div>

                @if($zielTyp === 'concept')
                    <div class="min-w-[16rem]">
                        <label class="{{ $label }} block mb-1">Konzept</label>
                        <select wire:model.live="conceptId" class="{{ $input }}">
                            <option value="">— wählen —</option>
                            @foreach($konzepte as $k)
                                <option value="{{ $k->id }}">{{ $k->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <div class="min-w-[16rem]">
                        <label class="{{ $label }} block mb-1">Gericht suchen</label>
                        <input type="text" wire:model.live.debounce.300ms="suche" placeholder="Name…" class="{{ $input }}">
                        @if($gewaehltesGericht)
                            <p class="text-[11px] text-gray-600 mt-1">Gewählt: <span class="font-medium text-gray-900">{{ $gewaehltesGericht->name }}</span></p>
                        @endif
                        @if($treffer->isNotEmpty())
                            <div class="mt-1 max-h-40 overflow-auto rounded-lg border border-black/5 bg-white/70">
                                @foreach($treffer as $t)
                                    <button wire:click="waehleGericht({{ $t->id }})" class="block w-full text-left px-3 py-1 text-xs hover:bg-violet-500/5 {{ $recipeId === $t->id ? 'text-violet-600 font-medium' : 'text-gray-700' }}">{{ $t->name }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                <div class="w-28">
                    <label class="{{ $label }} block mb-1">{{ $mengeLabel }}</label>
                    <input type="number" min="1" wire:model.live.debounce.400ms="menge" class="{{ $input }}">
                </div>

                <div>
                    <label class="{{ $label }} block mb-1">Blätter</label>
                    <div class="flex items-center gap-3 text-xs text-gray-700 h-[30px]">
                        <label class="inline-flex items-center gap-1"><input type="checkbox" wire:model.live="blaetter" value="produktion"> Produktion</label>
                        <label class="inline-flex items-center gap-1"><input type="checkbox" wire:model.live="blaetter" value="bestellung"> Bestellung</label>
                        <label class="inline-flex items-center gap-1"><input type="checkbox" wire:model.live="blaetter" value="einkauf"> Einkauf</label>
                    </div>
                </div>

                @if(!empty($dokUrlParams))
                    <div class="flex items-center gap-2 ml-auto">
                        @if(in_array('produktion', $blaetter, true))<a href="{{ route('foodalchemist.blaetter.dokument', array_merge(['typ' => 'produktion'], $dokUrlParams)) }}" target="_blank" class="{{ $btnGhost }}">🖨 Produktion</a>@endif
                        @if(in_array('bestellung', $blaetter, true))<a href="{{ route('foodalchemist.blaetter.dokument', array_merge(['typ' => 'bestellung'], $dokUrlParams)) }}" target="_blank" class="{{ $btnGhost }}">🖨 Bestellung</a>@endif
                        @if(in_array('einkauf', $blaetter, true))<a href="{{ route('foodalchemist.blaetter.dokument', array_merge(['typ' => 'einkauf'], $dokUrlParams)) }}" target="_blank" class="{{ $btnGhost }}">🖨 Einkauf</a>@endif
                    </div>
                @endif
            </div>
        </div>

        @php($alleWarnungen = array_unique(array_merge($produktion['warnungen'] ?? [], $bestellung['warnungen'] ?? [], $einkauf['warnungen'] ?? [])))
        @if($produktion === null && $bestellung === null && $einkauf === null)
            <div class="{{ $sectionCard }} text-center text-xs text-gray-500 py-8">Ziel + Menge wählen und mindestens ein Blatt ankreuzen.</div>
        @else
            @if($alleWarnungen)
                <div class="{{ $sectionCard }} !bg-amber-500/[0.06] !border-amber-500/20">
                    <p class="{{ $label }} !text-amber-700 mb-1">Hinweise ({{ count($alleWarnungen) }})</p>
                    <ul class="text-[11px] text-amber-800 space-y-0.5 list-disc pl-4">
                        @foreach($alleWarnungen as $w)<li>{{ $w }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <div class="grid lg:grid-cols-2 gap-4">
                {{-- ── Produktionsblatt ────────────────────────────────── --}}
                @if($produktion)
                <div class="{{ $sectionCard }}">
                    <h3 class="font-medium tracking-tight text-gray-900 mb-0.5">Produktionsblatt</h3>
                    <p class="text-[11px] text-gray-500 mb-3">Rezepturen zum Anlegen — Basisrezepte in ganzen Ansätzen.</p>
                    @foreach($produktion['rezepte'] as $r)
                        <div class="mb-4" wire:key="prod-{{ $r['recipe_id'] }}">
                            <div class="flex items-center justify-between gap-2">
                                <p class="font-medium text-gray-900 text-[13px]">
                                    {{ $r['name'] }}
                                    @if($r['ist_basisrezept'])<span class="{{ $pill }} {{ $variantPill['secondary'] }}">Basis</span>@endif
                                </p>
                                <p class="text-[11px] text-gray-600 text-right shrink-0">
                                    @if($r['ist_basisrezept'])
                                        <span class="font-medium text-gray-900">{{ $r['ansaetze'] }} Ansatz/Ansätze</span>
                                        @if($r['basis_yield_kg'])· à {{ number_format($r['basis_yield_kg'], 3, ',', '.') }} kg @endif
                                        <br><span class="text-gray-400">Bedarf: {{ number_format($r['benoetigt_ansaetze'], 2, ',', '.') }} Ansätze</span>
                                    @else
                                        <span class="font-medium text-gray-900">{{ number_format($r['ansaetze'], 2, ',', '.') }}× Rezept</span>
                                    @endif
                                </p>
                            </div>
                            <table class="{{ $table }} mt-1">
                                <tbody>
                                    @foreach($r['zutaten'] as $z)
                                        <tr class="border-t border-black/5">
                                            <td class="{{ $td }} {{ $z['optional'] ? 'text-gray-400' : 'text-gray-800' }}">
                                                {{ $z['typ'] === 'sub' ? '↳ ' : '' }}{{ $z['name'] }}
                                                @if($z['typ'] === 'sub')<span class="{{ $pill }} {{ $variantPill['info'] }}">Sub</span>@endif
                                                @if($z['typ'] === 'ungemappt')<span class="{{ $pill }} {{ $variantPill['warning'] }}">ungemappt</span>@endif
                                                @if($z['optional'])<span class="text-[10px]">(n.B.)</span>@endif
                                            </td>
                                            <td class="{{ $td }} text-right whitespace-nowrap text-gray-900">{{ number_format($z['menge'], 1, ',', '.') }} {{ $z['einheit'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </div>
                @endif

                {{-- ── Bestellvorschlag + Einkaufsliste (Lieferanten-Ansicht) ── --}}
                @foreach(array_filter(['Bestellvorschlag' => $bestellung, 'Einkaufsliste' => $einkauf]) as $titel => $blatt)
                    <div class="{{ $sectionCard }}" wire:key="sblatt-{{ $titel }}">
                        <h3 class="font-medium tracking-tight text-gray-900 mb-0.5">{{ $titel }}</h3>
                        <p class="text-[11px] text-gray-500 mb-3">{{ $titel === 'Einkaufsliste' ? 'GP-Bedarf über die Ziele zusammengeführt (bei einem Ziel = Bestellvorschlag).' : 'GP-Bedarf je Lead-Lieferant + EK (netto).' }}</p>
                        @foreach($blatt['lieferanten'] as $g)
                            <div class="mb-4" wire:key="lief-{{ $titel }}-{{ $g['supplier_id'] ?? 'none' }}">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="font-medium text-gray-900 text-[13px]">{{ $g['lieferant'] }}</p>
                                    <p class="text-[11px] text-right shrink-0">
                                        <span class="font-medium text-gray-900">{{ number_format($g['ek_summe'], 2, ',', '.') }} €</span>
                                        @unless($g['ek_vollstaendig'])<span class="{{ $pill }} {{ $variantPill['warning'] }}">EK unvollst.</span>@endunless
                                    </p>
                                </div>
                                <table class="{{ $table }} mt-1">
                                    <thead><tr>
                                        <th class="{{ $th }} text-left">GP</th>
                                        <th class="{{ $th }} text-right">Menge</th>
                                        <th class="{{ $th }} text-right">EK</th>
                                    </tr></thead>
                                    <tbody>
                                        @foreach($g['positionen'] as $p)
                                            <tr class="border-t border-black/5">
                                                <td class="{{ $td }} text-gray-800">
                                                    {{ $p['gp'] }}
                                                    @if($p['lead_artikel'])<br><span class="text-[10px] text-gray-400">{{ $p['lead_artikel'] }}</span>@endif
                                                    @if($p['ausweich'])<br><span class="text-[10px] text-sky-600">Ausweich: {{ $p['ausweich']['artikel'] }} ({{ $p['ausweich']['lieferant'] }})</span>@endif
                                                </td>
                                                <td class="{{ $td }} text-right whitespace-nowrap text-gray-900">{{ number_format($p['menge_kg'], 3, ',', '.') }} kg</td>
                                                <td class="{{ $td }} text-right whitespace-nowrap {{ $p['ek_bekannt'] ? 'text-gray-900' : 'text-amber-600' }}">{{ $p['ek_bekannt'] ? number_format($p['ek_eur'], 2, ',', '.') . ' €' : '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

    </x-ui-page-container>
</x-ui-page>
