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
                @if($concept && $concept->is_template)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">Vorlage</span>@endif
            </div>
            @if($item->consumer_name)<p class="text-[11px] italic text-gray-400">„{{ $item->consumer_name }}"</p>@endif
        </div>

        {{-- Stamm-Pills --}}
        <div class="flex flex-wrap items-center gap-1.5">
            @if($item->class)<span class="{{ $pill }} {{ $variantPill['primary'] }}">{{ $item->class }}</span>@endif
            @if($item->level)<span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $item->level }}</span>@endif
            @if($concept)
                @if($concept->occasion)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $concept->occasion }}</span>@endif
                <span class="{{ $pill }} {{ ['draft' => $variantPill['secondary'], 'active' => $variantPill['success'], 'archiviert' => $variantPill['warning']][$concept->status] ?? $variantPill['secondary'] }}">{{ ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiv'][$concept->status] ?? $concept->status }}</span>
            @elseif($paket)
                @if($paket->role)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $paket->role }}</span>@endif
                <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $paket->price_mode === 'auto' ? 'Auto-Preis' : 'Manueller Preis' }}</span>
            @endif
        </div>

        {{-- Aktionen (Bearbeiten navigiert in M10R-2 noch zum bestehenden Editor) --}}
        <div class="flex flex-wrap items-center gap-1.5">
            @if($concept)
                <button type="button" wire:click="$dispatch('concepter-editor.oeffnen', { type: 'concepts', id: {{ $concept->id }} })" class="{{ $btnGhostXs }}">✎ Bearbeiten</button>
                @if($concept->is_template)
                    <button type="button" wire:click="ausVorlage" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400">↧ Als Concept nutzen</button>
                @else
                    <button type="button" wire:click="alsVorlage" class="{{ $btnGhostXs }}">Als Vorlage</button>
                @endif
                <button type="button" wire:click="dupliziere" class="{{ $btnGhostXs }}">⎘ Duplizieren</button>
                <button type="button" wire:click="loeschen" wire:confirm="Concept löschen?" class="{{ $btnGhostXs }} text-red-600 dark:text-red-400">Löschen</button>
            @else
                <button type="button" wire:click="$dispatch('concepter-editor.oeffnen', { type: 'pakete', id: {{ $paket->id }} })" class="{{ $btnGhostXs }}">✎ Bearbeiten</button>
                <button type="button" wire:click="dupliziere" class="{{ $btnGhostXs }}">⎘ Duplizieren</button>
                <button type="button" wire:click="loeschen" wire:confirm="Paket löschen?" class="{{ $btnGhostXs }} text-red-600 dark:text-red-400">Löschen</button>
            @endif
        </div>

        {{-- KPI-Karten --}}
        <div class="grid grid-cols-2 gap-2">
            @if($concept && $cockpit)
                <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2">
                    <span class="text-[10px] font-medium uppercase tracking-wider text-violet-600 dark:text-violet-400">€/Person</span>
                    <p class="text-base font-bold text-violet-700 dark:text-violet-300 tabular-nums">{{ number_format($cockpit['price_per_person'], 2, ',', '.') }} €</p>
                </div>
            @elseif($paket)
                <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2">
                    <span class="text-[10px] font-medium uppercase tracking-wider text-violet-600 dark:text-violet-400">€/Person</span>
                    <p class="text-base font-bold text-violet-700 dark:text-violet-300 tabular-nums">{{ $paket->price_per_person !== null ? number_format((float) $paket->price_per_person, 2, ',', '.') . ' €' : '—' }}</p>
                </div>
            @endif
            <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                <span class="{{ $dt }}">EK/Person</span>
                <p class="text-xs font-semibold tabular-nums">{{ $aggregat !== null ? number_format((float) $aggregat['ek_per_person'], 2, ',', '.') . ' €' : '—' }}</p>
            </div>
            <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                <span class="{{ $dt }}">Arbeitszeit</span>
                <p class="text-xs font-semibold tabular-nums">{{ $aggregat !== null ? $aggregat['work_time_min'] . ' min' : '—' }}</p>
            </div>
            @php(
                $gerichteSuffix = $concept
                    ? ' · ' . ($aggregat['n_slots'] ?? 0) . ' Slots'
                    : (($paket && $paket->food_cost_percent !== null)
                        ? ' · ' . number_format((float) $paket->food_cost_percent, 1, ',', '.') . ' % W'
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
                <span class="{{ $pill }} {{ $konfPill[$aggregat['allergene']['confidence']] ?? $variantPill['secondary'] }}" title="Allergen-Konfidenz (schwächstes Gericht)">Konf. {{ $aggregat['allergene']['confidence'] }}</span>
            </div>

            {{-- Nährwerte/Person (ehrliche Degradation) --}}
            <div class="space-y-1 pt-2 border-t border-black/5 dark:border-white/10">
                <div class="flex items-center justify-between">
                    <span class="{{ $label }}">Nährwerte / Person</span>
                    <span class="{{ $pill }} {{ $konfPill[$aggregat['naehrwerte']['confidence']] ?? $variantPill['secondary'] }}">{{ $aggregat['naehrwerte']['confidence'] }}</span>
                </div>
                @if($aggregat['naehrwerte']['kcal'] !== null)
                    <div class="grid grid-cols-7 gap-1 text-center">
                        @foreach(['kcal' => 'kcal', 'protein_g' => 'Eiweiß', 'fett_g' => 'Fett', 'gesfett_g' => 'dav. ges.', 'kh_g' => 'KH', 'zucker_g' => 'dav. Zucker', 'salz_g' => 'Salz'] as $k => $lbl)
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
                            <span class="text-[10px] text-gray-400 uppercase mr-1">{{ $z['role'] ?? '—' }}</span>{{ $z['label'] }}
                            @if($z['type'] === 'paket')<span class="{{ $pill }} {{ $variantPill['info'] }} ml-1">Paket</span>@elseif($z['type'] === 'leer')<span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1">leer</span>@endif
                        </span>
                        <span class="shrink-0 tabular-nums {{ $z['price'] === null ? 'text-gray-300' : '' }}">{{ $z['price'] !== null ? number_format($z['price'], 2, ',', '.') . ' €' : '—' }}</span>
                    </div>
                @endforeach
            </div>
        @elseif($paket)
            <div class="space-y-1 pt-2 border-t border-black/5 dark:border-white/10">
                <span class="{{ $label }}">Gerichte im Paket</span>
                @forelse($paket->gerichte as $pg)
                    <div class="flex items-center justify-between gap-2 text-xs py-1">
                        <span class="min-w-0 truncate">{{ $pg->gericht?->name ?? '—' }}</span>
                        <span class="shrink-0 tabular-nums text-gray-400">{{ $pg->gericht?->sales_net !== null ? number_format((float) $pg->gericht->sales_net, 2, ',', '.') . ' €' : '' }}</span>
                    </div>
                @empty
                    <p class="text-[11px] text-gray-400 py-1">Noch keine Gerichte im Paket.</p>
                @endforelse
            </div>
        @endif

        {{-- Menü-Karte (Konsumenten-Sicht · C-10) --}}
        @if($concept)
            <div class="space-y-1 pt-2 border-t border-black/5 dark:border-white/10">
                <span class="{{ $label }}">Menü-Karte (Konsumenten-Sicht)</span>
                <div class="rounded-lg border border-black/5 dark:border-white/10 px-3 py-2 bg-white/40 dark:bg-white/[0.03]">
                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $concept->consumer_name ?: $concept->name }}</p>
                    @if($concept->additional_text)<p class="text-[11px] italic text-gray-500 mb-1">{{ $concept->additional_text }}</p>@endif
                    @forelse($concept->slots as $slot)
                        <div class="py-0.5">
                            <span class="text-[9px] uppercase tracking-wider text-gray-400">{{ $slot->role ?: '—' }}{{ $slot->is_pflicht ? '' : ' · optional' }}</span>
                            <p class="text-xs text-gray-800 dark:text-gray-200">{{ $slot->title ?: ($slot->paket?->name ?? $slot->gericht?->name ?? '(leer)') }}</p>
                        </div>
                    @empty
                        <p class="text-[11px] text-gray-400">Noch keine Positionen.</p>
                    @endforelse
                </div>
            </div>
        @endif

        {{-- Wo verwendet? (Verwendungsnachweis · Politur B-09/F-11) --}}
        <div class="space-y-1 pt-2 border-t border-black/5 dark:border-white/10">
            <span class="{{ $label }}">Wo verwendet? ({{ $verwendung->count() }})</span>
            @forelse($verwendung as $v)
                <div class="flex items-center justify-between gap-2 text-xs py-0.5">
                    <span class="min-w-0 truncate">{{ $concept ? ($v->label ?? '—') : $v->name }}</span>
                    <span class="shrink-0 text-[10px] text-gray-400">{{ $concept ? ('Foodbook' . ($v->jahr ? ' ' . $v->jahr : '') . ($v->customer ? ' · ' . $v->customer : '')) : 'Concept' }}</span>
                </div>
            @empty
                <p class="text-[11px] text-gray-400 py-0.5">{{ $concept ? 'In keinem Foodbook referenziert.' : 'In keinem Concept verwendet.' }}</p>
            @endforelse
        </div>
    @endif
</div>
