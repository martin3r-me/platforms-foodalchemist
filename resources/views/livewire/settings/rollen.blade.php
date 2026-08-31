{{-- Stufe 3 P3.1 — Küchen-Rollen mit Kostensatz (Küchenchef / Koch / Hilfskoch …). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div data-settings-rollen>
    @if($fehler)<x-foodalchemist::alert tone="danger" class="mb-2" data-rollen-fehler>{{ $fehler }}</x-foodalchemist::alert>@endif
    @if($meldung)<x-foodalchemist::alert tone="success" class="mb-2" data-rollen-meldung>{{ $meldung }}</x-foodalchemist::alert>@endif

    <p class="text-[11px] text-gray-500 mb-3">
        Eine Rolle ist ein <strong>Kostenträger</strong>, kein Mensch — „Küchenchef", „Koch", „Hilfskoch".
        Der <strong>Satz (€/Std)</strong> rechnet später die Produktionskosten; die Posten-Besetzung
        (wie viele je Rolle) leitet daraus Kapazität und Kosten ab. Ohne Satz greift der flache Team-Stundensatz.
    </p>

    {{-- Ebene 2: Sätze je Betrieb überschreiben (Bestand/Namen bleiben Team-Sache). --}}
    @if(count($betriebe) > 0)
        <div class="rounded-lg border p-3 mb-3 flex flex-wrap items-center gap-2" style="border-color:#e9d5ff;background:#faf5ff;">
            <span class="inline-block w-2 h-2 rounded-full" style="background:#9333ea;"></span>
            <label class="text-xs font-semibold" style="color:#6b21a8;">€/Std-Sätze für</label>
            <select wire:model.live="outletId" class="{{ $input }} !w-64 !py-1">
                <option value="">Team-Standard (gilt für alle Betriebe)</option>
                @foreach($betriebe as $b)
                    <option value="{{ $b['id'] }}">Betrieb: {{ $b['name'] }}</option>
                @endforeach
            </select>
            <span class="text-[11px] text-purple-800">
                @if($scopeOutletName)
                    Du überschreibst die €/Std für <strong>{{ $scopeOutletName }}</strong> — leer = erbt den Team-Satz. Rollen anlegen/stilllegen bleibt teamweit.
                @else
                    Team-Sätze — Basis für jeden Betrieb ohne eigenen Satz.
                @endif
            </span>
        </div>
    @endif

    <table class="{{ $table }}">
        <thead>
            <tr>
                <th class="{{ $th }} w-full">Rolle</th>
                <th class="{{ $th }} text-right whitespace-nowrap">€/Std</th>
                <th class="{{ $th }} w-px"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($rollen as $r)
                @php($eigen = (int) $r->team_id === (int) $eigenesTeamId)
                <tr class="{{ $tr }} {{ $r->is_inactive ? 'opacity-50' : '' }}" wire:key="rolle-{{ $r->id }}" data-rolle="{{ $r->id }}">
                    <td class="{{ $td }}">
                        @if($eigen && ! $scopeOutletName)
                            <input type="text" value="{{ $r->name }}" wire:change="feldSetzen({{ $r->id }}, 'name', $event.target.value)"
                                   class="{{ $input }} !py-0.5" data-rolle-name />
                        @else
                            {{ $r->name }}
                            @unless($eigen)<span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1" title="Vorlage aus dem Eltern-Team">geerbt</span>@endunless
                        @endif
                    </td>
                    <td class="{{ $td }} text-right">
                        @if($scopeOutletName)
                            {{-- Betrieb-Scope: Override-Satz für JEDE sichtbare Rolle; leer = erbt Team-Satz. --}}
                            @php($ov = $outletRates[$r->id] ?? null)
                            <input type="text" inputmode="decimal" value="{{ $ov !== null ? rtrim(rtrim(number_format((float) $ov, 2, '.', ''), '0'), '.') : '' }}"
                                   wire:change="feldSetzen({{ $r->id }}, 'satz', $event.target.value)"
                                   class="{{ $input }} !py-0.5 !w-24 text-right tabular-nums {{ $ov !== null ? '!text-indigo-600 font-medium' : '' }}"
                                   placeholder="erbt: {{ $r->stundensatz_eur ?? '—' }}"
                                   title="leer = erbt den Team-Satz ({{ $r->stundensatz_eur ?? 'flacher Stundensatz' }})" data-rolle-satz-outlet />
                        @elseif($eigen)
                            <input type="text" inputmode="decimal" value="{{ $r->stundensatz_eur }}"
                                   wire:change="feldSetzen({{ $r->id }}, 'satz', $event.target.value)"
                                   class="{{ $input }} !py-0.5 !w-24 text-right tabular-nums" placeholder="—"
                                   title="leer = flacher Team-Stundensatz greift" data-rolle-satz />
                        @else
                            <span class="tabular-nums">{{ $r->stundensatz_eur ?? '—' }}</span>
                        @endif
                    </td>
                    <td class="{{ $td }} whitespace-nowrap">
                        @if($eigen && ! $scopeOutletName)
                            <button type="button" wire:click="aktivToggle({{ $r->id }})" class="{{ $btnGhostXs }}"
                                    title="Lösch-Schutz: Rollen werden stillgelegt, nicht gelöscht — sonst liefen bestehende Besetzungen ins Leere"
                                    data-rolle-toggle>{{ $r->is_inactive ? 'reaktivieren' : 'stilllegen' }}</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="{{ $td }} text-[12px] text-gray-500">Noch keine Rollen. Unten anlegen — z. B. „Küchenchef", „Koch", „Hilfskoch".</td></tr>
            @endforelse
        </tbody>
    </table>

    @unless($scopeOutletName)
        <div class="flex flex-wrap items-end gap-2 mt-3 pt-3 border-t border-black/5" data-rollen-neu>
            <label class="block">
                <span class="{{ $label }}">Rolle</span>
                <input type="text" wire:model="neu.name" wire:keydown.enter="create" class="{{ $input }} !py-1 w-56" placeholder="Koch" data-neu-name />
            </label>
            <label class="block">
                <span class="{{ $label }}">€/Std (optional)</span>
                <input type="text" inputmode="decimal" wire:model="neu.satz" class="{{ $input }} !py-1 w-24 text-right" placeholder="35" data-neu-satz />
            </label>
            <button type="button" wire:click="create" class="{{ $btnPrimary }}" data-rollen-anlegen>Anlegen</button>
        </div>
    @endunless
</div>
