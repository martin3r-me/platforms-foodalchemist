{{-- D-6 §4.6: VK-Taxonomie — Speisen-HG/Klassen (Zähler), Aufschlagsklassen (W-1), Stile + Container-Vokabulare --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-5" data-settings-vk-taxonomie>
    <div>
        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">VK-Taxonomie</h3>
        <p class="text-xs text-gray-400 mt-0.5">Speisen-Hauptgruppen → Klassen (HG × Diätform), Aufschlagsklassen, Schreibstile, Container-Vokabulare. Lösch-Schutz V-06: referenzierte Einträge nur deaktivierbar.</p>
    </div>
    @if($meldung !== null)<p class="text-sm text-emerald-600 dark:text-emerald-400" data-taxo-meldung>{{ $meldung }}</p>@endif

    <div>
        <p class="{{ $dt }} mb-1.5">Speisen-Hauptgruppen ({{ $hauptgruppen->count() }}) → Klassen</p>
        <div class="flex flex-wrap gap-1.5" data-taxo-hgs>
            @foreach($hauptgruppen as $hg)
                <button type="button" wire:key="thg-{{ $hg->id }}" wire:click="waehleHg({{ $hg->id }})"
                        class="{{ $pill }} {{ $hauptgruppeId === $hg->id ? $variantPill['primary'] : $variantPill['secondary'] }} {{ $hg->is_inactive ? 'opacity-50' : '' }}">
                    [{{ $hg->code }}] {{ $hg->bezeichnung }} <span class="text-gray-400">{{ $klassenJeHg[$hg->id] ?? 0 }}</span>
                </button>
            @endforeach
        </div>
        @if($klassen->isNotEmpty())
            <table class="{{ $table }} mt-2" data-taxo-klassen>
                <thead><tr class="text-left">@foreach(['Klasse', 'Diätform', 'Diät-Flags', 'Rezepte'] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach($klassen as $k)
                        <tr class="{{ $tr }}" wire:key="tk-{{ $k->id }}">
                            <td class="{{ $td }}">{{ $k->bezeichnung }} <span class="text-[10px] font-mono text-gray-400">{{ $k->code }}</span></td>
                            <td class="{{ $td }}"><span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $k->diaetform }}</span></td>
                            <td class="{{ $td }} text-xs text-gray-400">{{ collect(['vegan' => $k->is_vegan, 'vegi' => $k->is_vegi, 'halal' => $k->is_halal, 'koscher' => $k->is_koscher])->filter()->keys()->implode(' · ') ?: '—' }}</td>
                            <td class="{{ $td }}">{{ $klassenZaehler[$k->id] ?? 0 }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div>
        <p class="{{ $dt }} mb-1.5">Aufschlagsklassen (GL-02 §3.6)</p>
        <table class="{{ $table }}" data-taxo-aks>
            <thead><tr class="text-left">@foreach(['Code', 'Bezeichnung', 'Rohaufschlag', 'MwSt', 'Formel', 'Rezepte', ''] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach($aufschlagsklassen as $ak)
                    <tr class="{{ $tr }} {{ $ak->is_inactive ? 'opacity-50' : '' }}" wire:key="tak-{{ $ak->id }}">
                        <td class="{{ $td }} font-mono text-xs">{{ $ak->code }}</td>
                        <td class="{{ $td }}">{{ $ak->bezeichnung }}</td>
                        <td class="{{ $td }}">{{ rtrim(rtrim(number_format((float) $ak->rohaufschlag_pct, 1, ',', '.'), '0'), ',') }} %</td>
                        <td class="{{ $td }}">{{ rtrim(rtrim(number_format((float) $ak->mwst_satz, 1, ',', '.'), '0'), ',') }} %</td>
                        <td class="{{ $td }}">
                            @if($ak->formel_typ === 'deckungsbeitrag')
                                <span class="{{ $pill }} {{ $variantPill['warning'] }}" title="W-1: Formel nicht definiert — Entscheid bei Dominique (08_ENTSCHEIDUNGEN D6)">deckungsbeitrag ⚠ W-1</span>
                            @else
                                <span class="{{ $pill }} {{ $variantPill['secondary'] }}">aufschlag</span>
                            @endif
                        </td>
                        <td class="{{ $td }}">{{ $akZaehler[$ak->id] ?? 0 }}</td>
                        <td class="{{ $td }}">
                            <button type="button" wire:click="toggleInactive('foodalchemist_markup_classes', {{ $ak->id }})" class="{{ $btnGhostXs }}">{{ $ak->is_inactive ? 'aktivieren' : 'deaktivieren' }}</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div>
        <p class="{{ $dt }} mb-1.5">Schreibstile ({{ $schreibstile->count() }} — Prompt-Material GL-06)</p>
        <div class="flex flex-wrap gap-1.5" data-taxo-stile>
            @foreach($schreibstile as $stil)
                <span class="{{ $pill }} {{ $stil->is_inactive ? $variantPill['secondary'] . ' opacity-50' : $variantPill['info'] }}" title="{{ $stil->sprach_duktus }}" wire:key="ts-{{ $stil->id }}">
                    {{ $stil->name }}
                    <button type="button" wire:click="toggleInactive('foodalchemist_writing_styles', {{ $stil->id }})" class="ml-0.5 text-gray-400 hover:text-violet-500">{{ $stil->is_inactive ? '↺' : '✕' }}</button>
                </span>
            @endforeach
        </div>
    </div>

    @foreach($vokabulare as $titel => $vokabular)
        <div>
            <p class="{{ $dt }} mb-1.5">{{ $titel }} ({{ $vokabular['zeilen']->count() }})</p>
            <div class="flex flex-wrap gap-1.5">
                @foreach($vokabular['zeilen'] as $v)
                    <span class="{{ $pill }} {{ $variantPill['secondary'] }} {{ $v->is_inactive ? 'opacity-50' : '' }}" title="{{ $v->gruppe ?? '' }}" wire:key="tv-{{ $vokabular['tabelle'] }}-{{ $v->id }}">
                        {{ $v->name }}
                        <button type="button" wire:click="toggleInactive('{{ $vokabular['tabelle'] }}', {{ $v->id }})" class="ml-0.5 text-gray-400 hover:text-violet-500">{{ $v->is_inactive ? '↺' : '✕' }}</button>
                    </span>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
