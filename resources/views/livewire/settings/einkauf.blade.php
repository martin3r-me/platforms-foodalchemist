{{-- M1-05: Lead-LA-Strategie (V-27) — M1-06 ergänzt die Stamm-Lieferanten-Matrix --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-4">
    {{-- Die Leiste speichert die Lead-LA-Strategie; die Stamm-Matrix darunter speichert je Zeile. --}}
    <x-foodalchemist::save-bar :meldung="$meldung" hint="Speichert die Lead-LA-Strategie." />

    <div class="{{ $card }} p-5 space-y-4" data-einkauf-strategie>
        <div>
            <h3 class="font-medium tracking-tight text-gray-900">Lead-LA-Strategie</h3>
            <p class="text-[11px] text-gray-500 mt-0.5">Entscheidet, welcher Lieferantenartikel je GP kalkulationsführend wird (V-27, speist die GL-03-Kette ab M3-06). Gilt nur für dein Team.</p>
        </div>

        <div class="space-y-2">
            @foreach($strategien as $s)
                <label class="flex items-start gap-3 p-3 rounded-lg cursor-pointer transition-all duration-150 {{ $strategie === $s->value ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10' : 'hover:bg-black/[0.03]' }}">
                    <input type="radio" wire:model.live="strategie" value="{{ $s->value }}" class="mt-0.5" />
                    <span>
                        <span class="block text-xs font-medium text-gray-900">{{ $s->label() }}</span>
                        <span class="block text-[11px] text-gray-500 mt-0.5">{{ $s->description() }}</span>
                    </span>
                </label>
            @endforeach
        </div>

        @if($strategie === 'prioritaets_kette')
            <div class="pt-3 border-t border-black/5 space-y-2" data-prio-kette>
                <div class="{{ $label }}">Prioritäts-Kette (oben = höchste Priorität)</div>
                @forelse($prioritaeten as $i => $supplierId)
                    <div class="flex items-center gap-2" wire:key="prio-{{ $supplierId }}">
                        <span class="text-[11px] text-gray-500 w-5">{{ $i + 1 }}.</span>
                        <span class="text-xs text-gray-700 flex-1">{{ $lieferantenNamen[$supplierId] ?? "Lieferant #{$supplierId}" }}</span>
                        <button type="button" wire:click="prioHoch({{ $i }})" class="{{ $btnGhostXs }}" @if($i === 0) disabled @endif>@svg('heroicon-o-chevron-up', 'w-3.5 h-3.5 inline-block align-middle')</button>
                        <button type="button" wire:click="prioEntfernen({{ $i }})" class="{{ $btnGhostXs }} text-red-500">@svg('heroicon-o-x-mark', 'w-3.5 h-3.5 inline-block align-middle')</button>
                    </div>
                @empty
                    <p class="text-[11px] text-gray-500">Noch keine Lieferanten in der Kette.</p>
                @endforelse
                <div class="flex gap-2">
                    <select wire:model="neuerPrioLieferant" class="{{ $input }} !w-72">
                        <option value="">Lieferant wählen…</option>
                        @foreach($lieferanten as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
                    </select>
                    <button type="button" wire:click="prioHinzu" class="{{ $btnGhostXs }}">+ zur Kette</button>
                </div>
            </div>
        @endif

        {{-- Phase 3: Strategie je Warengruppe (überschreibt die globale oben) --}}
        <div class="pt-3 border-t border-black/5 space-y-2" data-strategie-per-wg>
            <div class="{{ $label }}">Strategie je Warengruppe (optional — überschreibt die globale)</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1">
                @foreach($warengruppen as $wg)
                    <div class="flex items-center gap-2 py-0.5" wire:key="strat-wg-{{ $wg->code }}">
                        <span class="flex-1 min-w-0 truncate text-xs text-gray-600">{{ $wg->name }}</span>
                        <select wire:model="strategiePerWg.{{ $wg->code }}" class="{{ $input }} !w-48 !py-0.5 !text-[11px]">
                            <option value="">— globale Strategie —</option>
                            @foreach($strategien as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
                        </select>
                    </div>
                @endforeach
            </div>
        </div>

        <label class="flex items-center gap-2 pt-3 border-t border-black/5 text-xs text-gray-700 cursor-pointer">
            <input type="checkbox" wire:model="ausweichKette" class="rounded border-gray-300" />
            Ausweich-Kette anzeigen (im GP-Detail: wer würde Lead, wenn der aktuelle ausfällt)
        </label>

    </div>

    <div id="lagerorte" class="{{ $card }} p-5 space-y-3 scroll-mt-6" data-lagerorte>
        <div>
            <h3 class="font-medium tracking-tight text-gray-900">Lagerorte</h3>
            <p class="text-[11px] text-gray-500 mt-0.5">WaWi light: Wareneingänge buchen auf das Standardlager. Weitere Lagerorte sind die Grundlage für spätere Umlagerung, Inventur und Produktion.</p>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-[1fr_auto] gap-2 items-end">
            <div class="grid grid-cols-1 md:grid-cols-[1.2fr_.6fr_.7fr_1.4fr] gap-2">
                <label class="block">
                    <span class="{{ $label }}">Name</span>
                    <input type="text" wire:model="lagerNeu.name" class="{{ $input }}" placeholder="z. B. Hauptlager" />
                </label>
                <label class="block">
                    <span class="{{ $label }}">Code</span>
                    <input type="text" wire:model="lagerNeu.code" class="{{ $input }}" placeholder="MAIN" />
                </label>
                <label class="block">
                    <span class="{{ $label }}">Typ</span>
                    <select wire:model="lagerNeu.type" class="{{ $input }}">
                        @foreach($lagerTypen as $key => $labelText)
                            <option value="{{ $key }}">{{ $labelText }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="{{ $label }}">Notiz</span>
                    <input type="text" wire:model="lagerNeu.note" class="{{ $input }}" placeholder="optional" />
                </label>
            </div>
            <button type="button" wire:click="lagerAnlegen" class="{{ $btnPrimary }}">+ Lager anlegen</button>
        </div>

        <div class="overflow-x-auto border border-black/5 rounded-lg">
            <table class="min-w-full text-xs">
                <thead class="bg-black/[0.03] text-[11px] uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-2 text-left">Lager</th>
                        <th class="px-3 py-2 text-left">Code</th>
                        <th class="px-3 py-2 text-left">Typ</th>
                        <th class="px-3 py-2 text-left">Notiz</th>
                        <th class="px-3 py-2 text-center">Aktiv</th>
                        <th class="px-3 py-2 text-right">Bestände</th>
                        <th class="px-3 py-2 text-right">Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($lagerorte as $lager)
                        <tr class="border-t border-black/5" wire:key="lagerort-{{ $lager->id }}">
                            <td class="px-3 py-2 min-w-48">
                                <input type="text" wire:model="lagerEdit.{{ $lager->id }}.name" class="{{ $input }} !py-1 !text-xs" />
                                @if($lager->is_default)
                                    <span class="inline-flex mt-1 px-2 py-0.5 rounded-full bg-emerald-500/10 text-[10px] text-emerald-700">Standard</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 min-w-28">
                                <input type="text" wire:model="lagerEdit.{{ $lager->id }}.code" class="{{ $input }} !py-1 !text-xs" />
                            </td>
                            <td class="px-3 py-2 min-w-36">
                                <select wire:model="lagerEdit.{{ $lager->id }}.type" class="{{ $input }} !py-1 !text-xs">
                                    @foreach($lagerTypen as $key => $labelText)
                                        <option value="{{ $key }}">{{ $labelText }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2 min-w-56">
                                <input type="text" wire:model="lagerEdit.{{ $lager->id }}.note" class="{{ $input }} !py-1 !text-xs" />
                            </td>
                            <td class="px-3 py-2 text-center">
                                <input type="checkbox" wire:model="lagerEdit.{{ $lager->id }}.is_active" class="rounded border-gray-300" />
                            </td>
                            <td class="px-3 py-2 text-right text-gray-500">{{ $lager->stocks_count }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap space-x-1">
                                <button type="button" wire:click="lagerSpeichern({{ $lager->id }})" class="{{ $btnGhostXs }}">Speichern</button>
                                @unless($lager->is_default)
                                    <button type="button" wire:click="lagerStandardSetzen({{ $lager->id }})" class="{{ $btnGhostXs }}">Standard</button>
                                @endunless
                                <button type="button" wire:click="lagerEntfernen({{ $lager->id }})" class="{{ $btnGhostXs }} text-red-500" onclick="return confirm('Lagerort entfernen oder deaktivieren?')">Entfernen</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-4 text-xs text-gray-500">Noch keine Lagerorte angelegt. Beim ersten Wareneingang würde automatisch ein Hauptlager entstehen.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- M1-06: Stamm-Lieferanten-Matrix (Lieferant × Warengruppe) --}}
    <div class="{{ $card }} p-5 space-y-1" data-stamm-matrix>
        <div class="mb-3">
            <h3 class="font-medium tracking-tight text-gray-900">Stamm-Lieferanten-Matrix</h3>
            <p class="text-[11px] text-gray-500 mt-0.5">Je Warengruppe (+ global) — gewinnt bei Strategie „Stamm-Lieferant zuerst" (GL-03/V-27). Geerbte Einträge des Eltern-Teams sind fixiert.</p>
        </div>
        @if($fehler)
            <p class="text-xs text-red-600 pb-2">{{ $fehler }}</p>
        @endif

        @foreach(collect([['', 'Global (alle Warengruppen)']])->concat($warengruppen->map(fn ($wg) => [$wg->code, $wg->code . ' ' . $wg->name])) as [$code, $titel])
            <div wire:key="stamm-zeile-{{ $code ?: 'global' }}" class="flex items-center gap-3 py-2 border-t border-black/5 first:border-t-0">
                <span class="w-72 shrink-0 text-xs {{ $code === '' ? 'font-medium text-gray-900' : 'text-gray-600' }}">{{ $titel }}</span>
                <div class="flex-1 min-w-0 flex flex-wrap items-center gap-1.5">
                    @foreach($matrix->get($code, collect()) as $eintrag)
                        @php($eigen = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $eintrag))
                        <span wire:key="stamm-{{ $eintrag->id }}" class="inline-flex items-center gap-1 pl-2.5 {{ $eigen ? 'pr-1' : 'pr-2.5' }} py-0.5 rounded-full text-[11px] bg-violet-500/10 text-violet-700"
                              @unless($eigen) title="Geerbt vom Eltern-Team (D1)" @endunless>
                            {{ $eintrag->supplier?->name ?? ('#' . $eintrag->supplier_id) }}
                            @if($eigen)
                                <button type="button" wire:click="stammEntfernen({{ $eintrag->supplier_id }}, '{{ $code }}')"
                                        class="w-4 h-4 inline-flex items-center justify-center rounded-full text-violet-400 hover:text-red-500 hover:bg-red-500/10 transition-colors duration-150">@svg('heroicon-o-x-mark', 'w-3 h-3')</button>
                            @endif
                        </span>
                    @endforeach
                    <select wire:model="stammNeu.{{ $code ?: '' }}" wire:change="stammSetzen('{{ $code }}')"
                            class="{{ $input }} !w-44 !py-0.5 !text-[11px]">
                        <option value="">+ Stamm…</option>
                        @foreach($lieferanten as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
                    </select>
                </div>
            </div>
        @endforeach
    </div>
</div>
