{{-- R5: Aufschlagsklassen — eigene Seite, editierbar (GL-02 §3.6 / GT-8; W-1-Kennzeichnung) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-5" data-settings-aufschlagsklassen>
    <div>
        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Aufschlagsklassen</h3>
        <p class="text-[11px] text-gray-400 mt-0.5">Rohaufschlag · Bedienung · Profit · MwSt fließen direkt in die Marge-Rechnung (GT-8). Formel «deckungsbeitrag» bleibt W-1-gesperrt, bis die Formel entschieden ist.</p>
    </div>
    @if($fehler !== null)<p class="text-xs text-rose-600 dark:text-rose-400" data-ak-fehler>{{ $fehler }}</p>@endif

    <table class="{{ $table }}" data-ak-tabelle>
        <thead><tr class="text-left">@foreach(['Code', 'Bezeichnung', 'Rohaufschlag %', 'Bedienung %', 'Profit %', 'MwSt %', 'Formel', 'Rezepte', ''] as $h)<th class="{{ $th }} !px-2">{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
            @foreach($klassen as $ak)
                <tr class="{{ $tr }} {{ $ak->is_inactive ? 'opacity-50' : '' }}" wire:key="ak-{{ $ak->id }}">
                    @if($editId === $ak->id)
                        <td class="{{ $td }} !px-2 font-mono text-[11px]">{{ $ak->code }}</td>
                        <td class="{{ $td }} !px-2"><input type="text" wire:model="form.bezeichnung" class="{{ $input }} !py-1" /></td>
                        @foreach(['rohaufschlag_pct', 'bedienung_pct', 'profit_pct', 'mwst_satz'] as $feld)
                            <td class="{{ $td }} !px-2"><input type="text" wire:model="form.{{ $feld }}" class="{{ $input }} !py-1 !w-16 text-right" /></td>
                        @endforeach
                        <td class="{{ $td }} !px-2">
                            <select wire:model="form.formel_typ" class="{{ $input }} !py-1">
                                <option value="aufschlag">aufschlag</option>
                                <option value="deckungsbeitrag">deckungsbeitrag (W-1)</option>
                            </select>
                        </td>
                        <td class="{{ $td }} !px-2">{{ $zaehler[$ak->id] ?? 0 }}</td>
                        <td class="{{ $td }} !px-2 whitespace-nowrap">
                            <button type="button" wire:click="save" class="{{ $btnPrimary }}" data-ak-save>Speichern</button>
                            <button type="button" wire:click="cancel" class="{{ $btnGhostXs }}">Abbrechen</button>
                        </td>
                    @else
                        <td class="{{ $td }} !px-2 font-mono text-[11px]">{{ $ak->code }}</td>
                        <td class="{{ $td }} !px-2">{{ $ak->bezeichnung }}</td>
                        @foreach(['rohaufschlag_pct', 'bedienung_pct', 'profit_pct', 'mwst_satz'] as $feld)
                            <td class="{{ $td }} !px-2 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $ak->{$feld}, 2, ',', '.'), '0'), ',') }}</td>
                        @endforeach
                        <td class="{{ $td }} !px-2">
                            @if($ak->formel_typ === 'deckungsbeitrag')
                                <span class="{{ $pill }} {{ $variantPill['warning'] }}" title="W-1: Formel nicht definiert — Entscheid bei Dominique">deckungsbeitrag ⚠</span>
                            @else
                                <span class="{{ $pill }} {{ $variantPill['secondary'] }}">aufschlag</span>
                            @endif
                        </td>
                        <td class="{{ $td }} !px-2">{{ $zaehler[$ak->id] ?? 0 }}</td>
                        <td class="{{ $td }} !px-2 whitespace-nowrap">
                            <button type="button" wire:click="edit({{ $ak->id }})" class="{{ $btnGhostXs }}" data-ak-edit>Bearbeiten</button>
                            <button type="button" wire:click="toggleInactive({{ $ak->id }})" class="{{ $btnGhostXs }}">{{ $ak->is_inactive ? 'aktivieren' : 'deaktivieren' }}</button>
                            <button type="button" wire:click="delete({{ $ak->id }})" wire:confirm="Diese Aufschlagsklasse löschen?" @disabled(($zaehler[$ak->id] ?? 0) > 0)
                                    class="{{ $btnGhostXs }} {{ ($zaehler[$ak->id] ?? 0) > 0 ? 'opacity-40 cursor-not-allowed' : 'text-red-500' }}"
                                    title="{{ ($zaehler[$ak->id] ?? 0) > 0 ? 'Wird von Rezepten genutzt — erst umhängen/deaktivieren' : 'löschen' }}">Löschen</button>
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Anlegen --}}
    <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2 space-y-1.5" data-ak-anlegen>
        <p class="{{ $dt }}">Neue Aufschlagsklasse</p>
        <div class="flex flex-wrap items-center gap-2">
            <input type="text" wire:model="neu.code" placeholder="Code (z. B. AK_NEU)" class="{{ $input }} !py-1 w-32 font-mono" data-ak-neu-code />
            <input type="text" wire:model="neu.bezeichnung" placeholder="Bezeichnung" class="{{ $input }} !py-1 w-44" />
            <input type="text" wire:model="neu.rohaufschlag_pct" placeholder="Rohaufschlag %" class="{{ $input }} !py-1 w-28 text-right" />
            <input type="text" wire:model="neu.bedienung_pct" placeholder="Bedienung %" class="{{ $input }} !py-1 w-24 text-right" />
            <input type="text" wire:model="neu.profit_pct" placeholder="Profit %" class="{{ $input }} !py-1 w-20 text-right" />
            <input type="text" wire:model="neu.mwst_satz" placeholder="MwSt %" class="{{ $input }} !py-1 w-20 text-right" />
            <button type="button" wire:click="create" class="{{ $btnPrimary }}" data-ak-neu-anlegen>+ Anlegen</button>
        </div>
    </div>
</div>
