{{-- D-6 §4.6: VK-Taxonomie — Speisen-HG/Klassen (Zähler); AK/Stile/Behälter: eigene Seiten (R5) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-5" data-settings-vk-taxonomie>
    <div>
        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">VK-Taxonomie</h3>
        <p class="text-[11px] text-gray-400 mt-0.5">Speisen-Hauptgruppen → Klassen (HG × Diätform) mit Rezept-Zählern. Lösch-Schutz V-06: referenzierte Einträge nur deaktivierbar.</p>
    </div>
    @if($meldung !== null)<p class="text-xs text-emerald-600 dark:text-emerald-400" data-taxo-meldung>{{ $meldung }}</p>@endif

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
                            <td class="{{ $td }} text-[11px] text-gray-400">{{ collect(['vegan' => $k->is_vegan, 'vegi' => $k->is_vegi, 'halal' => $k->is_halal, 'koscher' => $k->is_koscher])->filter()->keys()->implode(' · ') ?: '—' }}</td>
                            <td class="{{ $td }}">{{ $klassenZaehler[$k->id] ?? 0 }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <p class="text-[11px] text-gray-400" data-taxo-verweis>Aufschlagsklassen, Schreibstile und Behälter/Geräte haben jetzt eigene Seiten (R5) — siehe Navigation links.</p>
</div>
