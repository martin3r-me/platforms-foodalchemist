{{-- Concepter-DetailPanel. Redesign v3 2026-07-21 (Dominique): Menü-Ökonomie-Linse,
     nicht ausklappbar, größere Typo. Cockpit (€/Person + Menü-Score), section-Köpfe.
     Kontext-adaptiv Concept ODER Paket. Kein embedded-Modus. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($konfPill = ['high' => $variantPill['success'], 'medium' => $variantPill['warning'], 'low' => $variantPill['danger'], 'unknown' => $variantPill['secondary']])

<div class="p-4 space-y-4 min-h-full bg-gray-500/[0.04]" data-concepter-panel>
    @if($concept === null && $paket === null)
        <div class="py-16 text-center text-sm text-gray-500">
            <div class="text-2xl mb-2">🍽️</div>
            {{ $type === 'pakete' ? 'Paket auswählen.' : 'Concept auswählen.' }}
        </div>
    @else
        @php($item = $concept ?? $paket)
        {{-- Kopf --}}
        <div>
            <div class="flex items-start justify-between gap-2">
                <h3 class="text-base font-semibold tracking-tight text-gray-900 leading-snug">{{ $item->name }}</h3>
                @if($concept && $concept->is_template)<span class="{{ $pill }} {{ $variantPill['secondary'] }} shrink-0">Vorlage</span>@endif
            </div>
            @if($item->consumer_name)<p class="text-xs italic text-gray-500 mt-0.5">„{{ $item->consumer_name }}"</p>@endif
            <div class="flex flex-wrap items-center gap-1.5 mt-2">
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
            <div class="flex flex-wrap items-center gap-1.5 mt-2">
                @if($concept)
                    <button type="button" wire:click="$dispatch('concepter-editor.oeffnen', { type: 'concepts', id: {{ $concept->id }} })" class="{{ $btnGhostXs }}">@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5') Bearbeiten</button>
                    @if($concept->is_template)
                        <button type="button" wire:click="ausVorlage" class="{{ $btnGhostXs }} text-violet-600">↧ Als Concept nutzen</button>
                    @else
                        <button type="button" wire:click="alsVorlage" class="{{ $btnGhostXs }}">Als Vorlage</button>
                    @endif
                    <button type="button" wire:click="dupliziere" class="{{ $btnGhostXs }}">⎘ Duplizieren</button>
                    <button type="button" wire:click="loeschen" wire:confirm="Concept löschen?" class="{{ $btnGhostXs }} text-red-600">Löschen</button>
                @else
                    <button type="button" wire:click="$dispatch('concepter-editor.oeffnen', { type: 'pakete', id: {{ $paket->id }} })" class="{{ $btnGhostXs }}">@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5') Bearbeiten</button>
                    <button type="button" wire:click="dupliziere" class="{{ $btnGhostXs }}">⎘ Duplizieren</button>
                    <button type="button" wire:click="loeschen" wire:confirm="Paket löschen?" class="{{ $btnGhostXs }} text-red-600">Löschen</button>
                @endif
            </div>
        </div>

        {{-- Cockpit (Menü-Ökonomie): €/Person + Menü-Score + EK/Person·Arbeitszeit·Gerichte --}}
        @php($proPerson = $concept ? ($cockpit['price_per_person'] ?? null) : ($paket?->price_per_person !== null ? (float) $paket->price_per_person : null))
        @php($score = $bewertung['score'] ?? null)
        @php($scoreTone = $score === null ? 'neutral' : ($score >= 80 ? 'success' : ($score >= 50 ? 'warning' : 'danger')))
        @php($scoreBadge = [
            'neutral' => ['bg-black/5', 'text-gray-500', 'bg-gray-400'],
            'success' => ['bg-emerald-500/15', 'text-emerald-700', 'bg-emerald-500'],
            'warning' => ['bg-amber-500/15', 'text-amber-700', 'bg-amber-500'],
            'danger' => ['bg-rose-500/15', 'text-rose-700', 'bg-rose-500'],
        ][$scoreTone])
        @php($gerichteSuffix = $concept ? ' · ' . ($aggregat['n_slots'] ?? 0) . ' Slots' : (($paket && $paket->food_cost_percent !== null) ? ' · ' . number_format((float) $paket->food_cost_percent, 1, ',', '.') . ' % W' : ''))
        <div class="relative overflow-hidden {{ $card }} px-3.5 py-2.5" data-concepter-cockpit>
            <div class="{{ $cardAccent }}"></div>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <span class="text-[10px] font-medium uppercase tracking-wider text-violet-600">€/Person</span>
                    <p class="text-2xl font-bold text-violet-700 leading-none mt-1 tabular-nums">{{ $proPerson !== null ? number_format($proPerson, 2, ',', '.') . ' €' : '—' }}</p>
                </div>
                @if($concept && $score !== null)
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-0.5 rounded-full {{ $scoreBadge[0] }} {{ $scoreBadge[1] }}" title="Menü-Bewertung (Anteil bestandener Checks)">
                        <span class="w-1.5 h-1.5 rounded-full {{ $scoreBadge[2] }}"></span>Score {{ $score }}
                    </span>
                @elseif($aggregat !== null)
                    <span class="{{ $pill }} {{ $konfPill[$aggregat['allergene']['confidence']] ?? $variantPill['secondary'] }}" title="Allergen-Konfidenz">Konf. {{ $aggregat['allergene']['confidence'] }}</span>
                @endif
            </div>
            @if($concept && $score !== null)
                <div class="mt-3"><x-foodalchemist::meter :value="$score" :max="100" :tone="$scoreTone" :ticks="[50, 80]" /></div>
            @endif
            <div class="flex flex-wrap gap-x-5 gap-y-1 mt-3 pt-2.5 border-t border-black/5 text-xs">
                <span class="text-gray-500">EK/Person <span class="text-gray-900 font-medium tabular-nums">{{ $aggregat !== null ? number_format((float) $aggregat['ek_per_person'], 2, ',', '.') . ' €' : '—' }}</span></span>
                <span class="text-gray-500">Arbeitszeit <span class="text-gray-900 font-medium tabular-nums">{{ $aggregat !== null ? $aggregat['work_time_min'] . ' min' : '—' }}</span></span>
                <span class="text-gray-500">Gerichte <span class="text-gray-900 font-medium tabular-nums">{{ ($aggregat['n_gerichte'] ?? 0) . $gerichteSuffix }}</span></span>
            </div>
        </div>

        @if($aggregat !== null && $aggregat['n_gerichte'] > 0)
            {{-- Allergen-/Diät-Rollup --}}
            <div class="flex flex-wrap gap-1" data-concepter-rollup>
                @if($aggregat['allergene']['is_vegan'])<span class="{{ $pill }} {{ $variantPill['success'] }}">vegan</span>
                @elseif($aggregat['allergene']['is_vegetarian'])<span class="{{ $pill }} {{ $variantPill['success'] }}">vegetarisch</span>@endif
                @if($aggregat['allergene']['is_gluten_free'])<span class="{{ $pill }} {{ $variantPill['info'] }}">glutenfrei</span>@endif
                @if($aggregat['allergene']['is_lactose_free'])<span class="{{ $pill }} {{ $variantPill['info'] }}">laktosefrei</span>@endif
                @if($aggregat['allergene']['is_halal'])<span class="{{ $pill }} {{ $variantPill['info'] }}">halal</span>@endif
                @if($aggregat['allergene']['contains_pork'])<span class="{{ $pill }} {{ $variantPill['warning'] }}">enthält Schwein</span>@endif
                @if($aggregat['allergene']['contains_beef'])<span class="{{ $pill }} {{ $variantPill['warning'] }}">enthält Rind</span>@endif
                <span class="{{ $pill }} {{ $konfPill[$aggregat['allergene']['confidence']] ?? $variantPill['secondary'] }}" title="Allergen-Konfidenz (schwächstes Gericht)">Konf. {{ $aggregat['allergene']['confidence'] }}</span>
            </div>
        @endif

        {{-- Aufbau — Menü-Struktur (Essenz) --}}
        @if($concept && $cockpit)
            <x-foodalchemist::section title="Aufbau" icon="heroicon-o-list-bullet" :meta="count($cockpit['zeilen'])">
                @foreach($cockpit['zeilen'] as $z)
                    <div class="flex items-center justify-between gap-2 text-[13px] py-1 border-b border-black/5 last:border-0">
                        <span class="min-w-0 truncate"><span class="text-[10px] text-gray-500 uppercase mr-1">{{ $z['role'] ?? '—' }}</span>{{ $z['label'] }}
                            @if($z['type'] === 'paket')<span class="{{ $pill }} {{ $variantPill['info'] }} ml-1">Paket</span>@elseif($z['type'] === 'leer')<span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1">leer</span>@endif
                        </span>
                        <span class="shrink-0 tabular-nums {{ $z['price'] === null ? 'text-gray-300' : 'text-gray-900' }}">{{ $z['price'] !== null ? number_format($z['price'], 2, ',', '.') . ' €' : '—' }}</span>
                    </div>
                @endforeach
            </x-foodalchemist::section>
        @elseif($paket)
            <x-foodalchemist::section title="Gerichte im Paket" icon="heroicon-o-list-bullet" :meta="$paket->dishes->count()">
                @forelse($paket->dishes as $pg)
                    <div class="flex items-center justify-between gap-2 text-[13px] py-1 border-b border-black/5 last:border-0">
                        <span class="min-w-0 truncate text-gray-900">{{ $pg->dish?->name ?? '—' }}</span>
                        <span class="shrink-0 tabular-nums text-gray-500">{{ $pg->dish?->sales_net !== null ? number_format((float) $pg->dish->sales_net, 2, ',', '.') . ' €' : '' }}</span>
                    </div>
                @empty
                    <p class="text-[13px] text-gray-500 py-1">Noch keine Gerichte im Paket.</p>
                @endforelse
            </x-foodalchemist::section>
        @endif

        {{-- Menü-Bewertung (deterministisch §10.8) --}}
        @if($concept && $bewertung)
            @php($statusIcon = ['ok' => '✓', 'warn' => '!', 'fail' => '✕', 'info' => 'ℹ'])
            @php($statusColor = ['ok' => 'text-emerald-600', 'warn' => 'text-amber-600', 'fail' => 'text-red-600', 'info' => 'text-gray-500'])
            @php($scorePillCls = $bewertung['score'] >= 80 ? $variantPill['success'] : ($bewertung['score'] >= 50 ? $variantPill['warning'] : $variantPill['danger']))
            <x-foodalchemist::section title="Menü-Bewertung" icon="heroicon-o-clipboard-document-check">
                <x-slot:actions>
                    <span class="{{ $pill }} {{ $scorePillCls }}" title="Anteil bestandener Checks">Score {{ $bewertung['score'] }}</span>
                </x-slot:actions>
                @foreach($bewertung['checks'] as $c)
                    <div class="flex items-start gap-2 text-[12px] py-0.5">
                        <span class="{{ $statusColor[$c['status']] ?? '' }} font-bold w-3 shrink-0 text-center">{{ $statusIcon[$c['status']] ?? '·' }}</span>
                        <span class="text-gray-600"><span class="font-medium">{{ $c['label'] }}:</span> {{ $c['detail'] }}</span>
                    </div>
                @endforeach
            </x-foodalchemist::section>
        @endif

        {{-- Nährwerte / Person --}}
        @if($aggregat !== null && $aggregat['n_gerichte'] > 0)
            <x-foodalchemist::section title="Nährwerte / Person" icon="heroicon-o-chart-bar">
                <x-slot:actions>
                    <span class="{{ $pill }} {{ $konfPill[$aggregat['naehrwerte']['confidence']] ?? $variantPill['secondary'] }}">{{ $aggregat['naehrwerte']['confidence'] }}</span>
                </x-slot:actions>
                @if($aggregat['naehrwerte']['kcal'] !== null)
                    <div class="grid grid-cols-7 gap-1 text-center">
                        @foreach(['kcal' => 'kcal', 'protein_g' => 'Eiweiß', 'fett_g' => 'Fett', 'gesfett_g' => 'dav. ges.', 'kh_g' => 'KH', 'zucker_g' => 'dav. Zucker', 'salz_g' => 'Salz'] as $k => $lbl)
                            <div class="rounded-md bg-black/[0.03] py-1.5">
                                <p class="text-[13px] font-semibold tabular-nums">{{ $aggregat['naehrwerte'][$k] !== null ? rtrim(rtrim(number_format((float) $aggregat['naehrwerte'][$k], $k === 'kcal' ? 0 : 1, ',', '.'), '0'), ',') : '—' }}</p>
                                <p class="text-[9px] text-gray-500 uppercase">{{ $lbl }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
                @unless($aggregat['naehrwerte']['vollstaendig'])
                    <p class="text-[11px] text-amber-600 mt-1.5">⚠ {{ $aggregat['naehrwerte']['n_mit_naehrwerten'] }}/{{ $aggregat['naehrwerte']['n_gerichte'] }} Gerichten mit Nährwert + Portionsgramm — Rest fehlt noch.</p>
                @endunless
            </x-foodalchemist::section>
        @endif

        {{-- Menü-Karte (Konsumenten-Sicht · C-10) --}}
        @if($concept)
            <x-foodalchemist::section title="Menü-Karte" icon="heroicon-o-document-text" meta="Konsumenten-Sicht">
                <div class="rounded-lg border border-black/5 px-3 py-2 bg-white/40">
                    <p class="text-sm font-semibold text-gray-900">{{ $concept->consumer_name ?: $concept->name }}</p>
                    @if($concept->additional_text)<p class="text-[11px] italic text-gray-600 mb-1">{{ $concept->additional_text }}</p>@endif
                    @forelse($concept->slots as $slot)
                        <div class="py-0.5">
                            <span class="text-[9px] uppercase tracking-wider text-gray-500">{{ $slot->role ?: '—' }}{{ $slot->is_pflicht ? '' : ' · optional' }}</span>
                            <p class="text-[13px] text-gray-800">{{ $slot->title ?: ($slot->package?->name ?? $slot->dish?->name ?? '(leer)') }}</p>
                        </div>
                    @empty
                        <p class="text-[11px] text-gray-500">Noch keine Positionen.</p>
                    @endforelse
                </div>
            </x-foodalchemist::section>
        @endif

        {{-- Wo verwendet? --}}
        <x-foodalchemist::section title="Wo verwendet?" icon="heroicon-o-link" :meta="$verwendung->count()">
            @forelse($verwendung as $v)
                <div class="flex items-center justify-between gap-2 text-[13px] py-0.5">
                    <span class="min-w-0 truncate">{{ $concept ? ($v->label ?? '—') : $v->name }}</span>
                    <span class="shrink-0 text-[10px] text-gray-500">{{ $concept ? ('Foodbook' . ($v->jahr ? ' ' . $v->jahr : '') . ($v->customer ? ' · ' . $v->customer : '')) : 'Concept' }}</span>
                </div>
            @empty
                <p class="text-[11px] text-gray-500 py-0.5">{{ $concept ? 'In keinem Foodbook referenziert.' : 'In keinem Concept verwendet.' }}</p>
            @endforelse
        </x-foodalchemist::section>
    @endif
</div>
