@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-5" data-settings-aufschlagsklassen>
    @if($fehler !== null)<p class="text-xs text-rose-600" data-ak-fehler>{{ $fehler }}</p>@endif

    <div class="{{ $card }} p-4 flex items-start justify-between gap-4 flex-wrap">
        <div>
            <h3 class="font-medium text-gray-900">Dynamischer Unternehmens-Basissatz</h3>
            <p class="text-[11px] text-gray-500 mt-0.5">Aus bestätigten Monatsbasen, Gemeinkosten und Gewinnaufschlag. Preisklassen verändern diesen Satz nur relativ.</p>
        </div>
        <div class="text-right">
            <div class="text-xl font-semibold tabular-nums">{{ $base['factor'] !== null ? number_format($base['factor'], 3, ',', '.') : '—' }}</div>
            <div class="text-[10px] {{ ($base['source'] ?? null) === 'kostenstruktur' ? 'text-emerald-600' : 'text-amber-600' }}">{{ ($base['source'] ?? null) === 'kostenstruktur' ? 'aus Kostenstruktur' : 'aus Ziel-Wareneinsatz' }}</div>
        </div>
    </div>

    <table class="{{ $table }}" data-ak-tabelle>
        <thead><tr class="text-left">
            @foreach(['Code', 'Preisklasse', 'Klassenfaktor', 'Gesamtfaktor', 'MwSt-Profil', 'Rundung', 'Rezepte', ''] as $h)<th class="{{ $th }} !px-2">{{ $h }}</th>@endforeach
        </tr></thead>
        <tbody>
            @foreach($klassen as $ak)
                <tr class="{{ $tr }} {{ $ak->is_inactive ? 'opacity-50' : '' }}" wire:key="ak-{{ $ak->id }}">
                    @if($editId === $ak->id)
                        <td class="{{ $td }} !px-2 font-mono text-[11px]">{{ $ak->code }}</td>
                        <td class="{{ $td }} !px-2"><input type="text" wire:model="form.label" class="{{ $input }} !py-1" /></td>
                        <td class="{{ $td }} !px-2"><input type="text" wire:model="form.class_factor_pct" class="{{ $input }} !py-1 !w-20 text-right" /></td>
                        <td class="{{ $td }} !px-2 text-right text-gray-500">nach Speichern</td>
                        <td class="{{ $td }} !px-2">
                            <select wire:model="form.vat_profile_key" class="{{ $input }} !py-1 !w-28">
                                <option value="">Team-Default</option><option value="ermaessigt">ermäßigt</option><option value="regulaer">regulär</option>
                            </select>
                        </td>
                        <td class="{{ $td }} !px-2 whitespace-nowrap">
                            <input type="number" min="0" max="4" wire:model="form.rounding_decimals" placeholder="Team" class="{{ $input }} !py-1 !w-16" />
                            <select wire:model="form.rounding_mode" class="{{ $input }} !py-1 !w-28"><option value="">Team</option><option value="kaufmaennisch">kaufmännisch</option><option value="auf">auf</option><option value="ab">ab</option></select>
                        </td>
                        <td class="{{ $td }} !px-2">{{ $zaehler[$ak->id] ?? 0 }}</td>
                        <td class="{{ $td }} !px-2 whitespace-nowrap"><button type="button" wire:click="save" class="{{ $btnPrimary }}">Speichern</button><button type="button" wire:click="cancel" class="{{ $btnGhostXs }}">Abbrechen</button></td>
                    @else
                        @php($factor = (float) ($ak->class_factor_pct ?? 100))
                        <td class="{{ $td }} !px-2 font-mono text-[11px]">{{ $ak->code }}</td>
                        <td class="{{ $td }} !px-2">{{ $ak->label }}</td>
                        <td class="{{ $td }} !px-2 text-right tabular-nums">{{ number_format($factor, 1, ',', '.') }} %</td>
                        <td class="{{ $td }} !px-2 text-right tabular-nums">{{ $base['factor'] !== null ? number_format($base['factor'] * $factor / 100, 3, ',', '.') : '—' }}</td>
                        <td class="{{ $td }} !px-2">{{ $ak->vat_profile_key ?: 'Team-Default' }}</td>
                        <td class="{{ $td }} !px-2 text-[11px] text-gray-500">{{ $ak->rounding_decimals !== null ? $ak->rounding_decimals . ' Stellen' : 'Team' }}{{ $ak->rounding_mode ? ' · ' . $ak->rounding_mode : '' }}</td>
                        <td class="{{ $td }} !px-2">{{ $zaehler[$ak->id] ?? 0 }}</td>
                        <td class="{{ $td }} !px-2 whitespace-nowrap">
                            @if(\Platform\FoodAlchemist\Support\TeamScope::owns($ak->team_id, $team))
                                <button type="button" wire:click="edit({{ $ak->id }})" class="{{ $btnGhostXs }}">Bearbeiten</button>
                                <button type="button" wire:click="toggleInactive({{ $ak->id }})" class="{{ $btnGhostXs }}">{{ $ak->is_inactive ? 'aktivieren' : 'deaktivieren' }}</button>
                                <button type="button" wire:click="delete({{ $ak->id }})" wire:confirm="Diese Preisklasse löschen?" @disabled(($zaehler[$ak->id] ?? 0) > 0) class="{{ $btnGhostXs }} {{ ($zaehler[$ak->id] ?? 0) > 0 ? 'opacity-40' : 'text-red-500' }}">Löschen</button>
                            @else
                                <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $ak->team_id === null ? 'global' : 'geerbt' }}</span>
                            @endif
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="rounded-lg bg-black/[0.03] px-3 py-2 space-y-1.5" data-ak-anlegen>
        <p class="{{ $dt }}">Neue Preisklasse</p>
        <div class="flex flex-wrap items-center gap-2">
            <input type="text" wire:model="neu.code" placeholder="Code" class="{{ $input }} !py-1 w-28 font-mono" />
            <input type="text" wire:model="neu.label" placeholder="Bezeichnung" class="{{ $input }} !py-1 w-44" />
            <input type="text" wire:model="neu.class_factor_pct" placeholder="Faktor %" class="{{ $input }} !py-1 w-24 text-right" />
            <select wire:model="neu.vat_profile_key" class="{{ $input }} !py-1 w-32"><option value="">Team-MwSt</option><option value="ermaessigt">ermäßigt</option><option value="regulaer">regulär</option></select>
            <button type="button" wire:click="create" class="{{ $btnPrimary }}">Anlegen</button>
        </div>
    </div>
</div>
