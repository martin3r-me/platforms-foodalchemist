{{-- M10R-2/3 / Doc 15 §10.4: kontext-adaptives Detail-Panel (Concept ODER Paket) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($konfPill = ['high' => $variantPill['success'], 'medium' => $variantPill['warning'], 'low' => $variantPill['danger'], 'unknown' => $variantPill['secondary']])

<div class="p-4 space-y-4 min-h-full bg-gray-500/[0.04] dark:bg-white/[0.02]">
    @if($concept === null && $paket === null)
        <div class="py-16 text-center text-sm text-gray-400">
            <div class="text-2xl mb-2">🍽️</div>
            {{ $type === 'pakete' ? 'Paket auswählen.' : 'Concept auswählen.' }}
        </div>
    @else
        @php($item = $concept ?? $paket)
        <div>
            <div class="flex items-start justify-between gap-2">
                <h3 class="text-[15px] font-semibold text-gray-900 dark:text-gray-100">{{ $item->name }}</h3>
                @if($concept && $concept->is_vorlage)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">Vorlage</span>@endif
            </div>
            @if($item->konsumenten_name)<p class="text-[11px] italic text-gray-400">„{{ $item->konsumenten_name }}"</p>@endif
        </div>

        {{-- Stamm-Pills --}}
        <div class="flex flex-wrap items-center gap-1.5">
            @if($item->klasse)<span class="{{ $pill }} {{ $variantPill['primary'] }}">{{ $item->klasse }}</span>@endif
            @if($item->niveau)<span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $item->niveau }}</span>@endif
            @if($concept)
                @if($concept->anlass)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $concept->anlass }}</span>@endif
                <span class="{{ $pill }} {{ ['draft' => $variantPill['secondary'], 'aktiv' => $variantPill['success'], 'archiviert' => $variantPill['warning']][$concept->status] ?? $variantPill['secondary'] }}">{{ ['draft' => 'Entwurf', 'aktiv' => 'Aktiv', 'archiviert' => 'Archiv'][$concept->status] ?? $concept->status }}</span>
            @elseif($paket)
                @if($paket->rolle)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $paket->rolle }}</span>@endif
                <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $paket->preis_modus === 'auto' ? 'Auto-Preis' : 'Manueller Preis' }}</span>
            @endif
        </div>

        {{-- Aktionen (Bearbeiten navigiert in M10R-2 noch zum bestehenden Editor) --}}
        <div class="flex flex-wrap items-center gap-1.5">
            @if($concept)
                <button type="button" wire:click="$dispatch('concepter-editor.oeffnen', { type: 'concepts', id: {{ $concept->id }} })" class="{{ $btnGhostXs }}">✎ Bearbeiten</button>
                @unless($concept->is_vorlage)
                    <button type="button" wire:click="alsVorlage" class="{{ $btnGhostXs }}">Als Vorlage</button>
                @endunless
                <button type="button" wire:click="loeschen" wire:confirm="Concept löschen?" class="{{ $btnGhostXs }} text-red-600 dark:text-red-400">Löschen</button>
            @else
                <button type="button" wire:click="$dispatch('concepter-editor.oeffnen', { type: 'pakete', id: {{ $paket->id }} })" class="{{ $btnGhostXs }}">✎ Bearbeiten</button>
                <button type="button" wire:click="loeschen" wire:confirm="Paket löschen?" class="{{ $btnGhostXs }} text-red-600 dark:text-red-400">Löschen</button>
            @endif
        </div>

        {{-- KPI-Karten --}}
        <div class="grid grid-cols-2 gap-2">
            @if($concept && $cockpit)
                <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2">
                    <span class="text-[10px] font-medium uppercase tracking-wider text-violet-600 dark:text-violet-400">€/Person</span>
                    <p class="text-base font-bold text-violet-700 dark:text-violet-300 tabular-nums">{{ number_format($cockpit['preis_pro_person'], 2, ',', '.') }} €</p>
                </div>
            @elseif($paket)
                <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2">
                    <span class="text-[10px] font-medium uppercase tracking-wider text-violet-600 dark:text-violet-400">€/Person</span>
                    <p class="text-base font-bold text-violet-700 dark:text-violet-300 tabular-nums">{{ $paket->preis_pro_person !== null ? number_format((float) $paket->preis_pro_person, 2, ',', '.') . ' €' : '—' }}</p>
                </div>
            @endif
            <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                <span class="{{ $dt }}">EK/Person</span>
                <p class="text-xs font-semibold tabular-nums">{{ $aggregat !== null ? number_format((float) $aggregat['ek_pro_person'], 2, ',', '.') . ' €' : '—' }}</p>
            </div>
            <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                <span class="{{ $dt }}">Arbeitszeit</span>
                <p class="text-xs font-semibold tabular-nums">{{ $aggregat !== null ? $aggregat['arbeitszeit_min'] . ' min' : '—' }}</p>
            </div>
            @php(
                $gerichteSuffix = $concept
                    ? ' · ' . ($aggregat['n_slots'] ?? 0) . ' Slots'
                    : (($paket && $paket->wareneinsatz_prozent !== null)
                        ? ' · ' . number_format((float) $paket->wareneinsatz_prozent, 1, ',', '.') . ' % W'
                        : '')
            )
            <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                <span class="{{ $dt }}">Gerichte</span>
                <p class="text-xs font-semibold tabular-nums">{{ ($aggregat['n_gerichte'] ?? 0) . $gerichteSuffix }}</p>
            </div>
        </div>

        @if($aggregat !== null && $aggregat['n_gerichte'] > 0)
            {{-- Allergen-/Diät-Rollup --}}
            <div class="flex flex-wrap gap-1 pt-2 border-t border-black/5 dark:border-white/10">
                @if($aggregat['allergene']['is_vegan'])<span class="{{ $pill }} {{ $variantPill['success'] }}">vegan</span>
                @elseif($aggregat['allergene']['is_vegetarian'])<span class="{{ $pill }} {{ $variantPill['success'] }}">vegetarisch</span>@endif
                @if($aggregat['allergene']['is_gluten_free'])<span class="{{ $pill }} {{ $variantPill['info'] }}">glutenfrei</span>@endif
                @if($aggregat['allergene']['is_lactose_free'])<span class="{{ $pill }} {{ $variantPill['info'] }}">laktosefrei</span>@endif
                @if($aggregat['allergene']['is_halal'])<span class="{{ $pill }} {{ $variantPill['info'] }}">halal</span>@endif
                @if($aggregat['allergene']['contains_pork'])<span class="{{ $pill }} {{ $variantPill['warning'] }}">enthält Schwein</span>@endif
                @if($aggregat['allergene']['contains_beef'])<span class="{{ $pill }} {{ $variantPill['warning'] }}">enthält Rind</span>@endif
                <span class="{{ $pill }} {{ $konfPill[$aggregat['allergene']['konfidenz']] ?? $variantPill['secondary'] }}" title="Allergen-Konfidenz (schwächstes Gericht)">Konf. {{ $aggregat['allergene']['konfidenz'] }}</span>
            </div>

            {{-- Nährwerte/Person (ehrliche Degradation) --}}
            <div class="space-y-1 pt-2 border-t border-black/5 dark:border-white/10">
                <div class="flex items-center justify-between">
                    <span class="{{ $label }}">Nährwerte / Person</span>
                    <span class="{{ $pill }} {{ $konfPill[$aggregat['naehrwerte']['konfidenz']] ?? $variantPill['secondary'] }}">{{ $aggregat['naehrwerte']['konfidenz'] }}</span>
                </div>
                @if($aggregat['naehrwerte']['kcal'] !== null)
                    <div class="grid grid-cols-5 gap-1 text-center">
                        @foreach(['kcal' => 'kcal', 'protein_g' => 'Eiweiß', 'fett_g' => 'Fett', 'kh_g' => 'KH', 'salz_g' => 'Salz'] as $k => $lbl)
                            <div class="rounded-md bg-black/[0.03] dark:bg-white/5 py-1">
                                <p class="text-xs font-semibold tabular-nums">{{ $aggregat['naehrwerte'][$k] !== null ? rtrim(rtrim(number_format((float) $aggregat['naehrwerte'][$k], $k === 'kcal' ? 0 : 1, ',', '.'), '0'), ',') : '—' }}</p>
                                <p class="text-[9px] text-gray-400 uppercase">{{ $lbl }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
                @unless($aggregat['naehrwerte']['vollstaendig'])
                    <p class="text-[10px] text-amber-600 dark:text-amber-400">⚠ {{ $aggregat['naehrwerte']['n_mit_naehrwerten'] }}/{{ $aggregat['naehrwerte']['n_gerichte'] }} Gerichten mit Nährwert + Portionsgramm — Rest fehlt noch.</p>
                @endunless
            </div>
        @endif

        {{-- Deterministische Menü-Bewertung (§10.8) --}}
        @if($concept && $bewertung)
            @php($statusIcon = ['ok' => '✓', 'warn' => '!', 'fail' => '✕', 'info' => 'ℹ'])
            @php($statusColor = ['ok' => 'text-emerald-600 dark:text-emerald-400', 'warn' => 'text-amber-600 dark:text-amber-400', 'fail' => 'text-red-600 dark:text-red-400', 'info' => 'text-gray-400'])
            @php($scorePill = $bewertung['score'] >= 80 ? $variantPill['success'] : ($bewertung['score'] >= 50 ? $variantPill['warning'] : $variantPill['danger']))
            <div class="space-y-1 pt-2 border-t border-black/5 dark:border-white/10">
                <div class="flex items-center justify-between">
                    <span class="{{ $label }}">Menü-Bewertung</span>
                    <span class="{{ $pill }} {{ $scorePill }}" title="Anteil bestandener Checks">Score {{ $bewertung['score'] }}</span>
                </div>
                @foreach($bewertung['checks'] as $c)
                    <div class="flex items-start gap-2 text-[11px] py-0.5">
                        <span class="{{ $statusColor[$c['status']] ?? '' }} font-bold w-3 shrink-0 text-center">{{ $statusIcon[$c['status']] ?? '·' }}</span>
                        <span class="text-gray-600 dark:text-gray-300"><span class="font-medium">{{ $c['label'] }}:</span> {{ $c['detail'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Slot-/Gericht-Aufbau (kompakt) --}}
        @if($concept && $cockpit)
            <div class="space-y-1 pt-2 border-t border-black/5 dark:border-white/10">
                <span class="{{ $label }}">Aufbau</span>
                @foreach($cockpit['zeilen'] as $z)
                    <div class="flex items-center justify-between gap-2 text-xs py-1">
                        <span class="min-w-0 truncate">
                            <span class="text-[10px] text-gray-400 uppercase mr-1">{{ $z['rolle'] ?? '—' }}</span>{{ $z['label'] }}
                            @if($z['typ'] === 'paket')<span class="{{ $pill }} {{ $variantPill['info'] }} ml-1">Paket</span>@elseif($z['typ'] === 'leer')<span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1">leer</span>@endif
                        </span>
                        <span class="shrink-0 tabular-nums {{ $z['preis'] === null ? 'text-gray-300' : '' }}">{{ $z['preis'] !== null ? number_format($z['preis'], 2, ',', '.') . ' €' : '—' }}</span>
                    </div>
                @endforeach
            </div>
        @elseif($paket)
            <div class="space-y-1 pt-2 border-t border-black/5 dark:border-white/10">
                <span class="{{ $label }}">Gerichte im Paket</span>
                @forelse($paket->gerichte as $pg)
                    <div class="flex items-center justify-between gap-2 text-xs py-1">
                        <span class="min-w-0 truncate">{{ $pg->gericht?->name ?? '—' }}</span>
                        <span class="shrink-0 tabular-nums text-gray-400">{{ $pg->gericht?->vk_netto !== null ? number_format((float) $pg->gericht->vk_netto, 2, ',', '.') . ' €' : '' }}</span>
                    </div>
                @empty
                    <p class="text-[11px] text-gray-400 py-1">Noch keine Gerichte im Paket.</p>
                @endforelse
            </div>
        @endif
    @endif
</div>
