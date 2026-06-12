{{-- M6-03: VK-DetailPanel — VERKAUFT-ALS-Box, Marge-KPI-Karten, Formel-Klartext (13_REFERENZ N2) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="p-4 space-y-4" data-vk-panel>
    @if($rezept === null)
        <div class="text-center text-sm text-gray-400 py-12">
            <div class="text-2xl mb-2">€</div>
            Verkaufsrezept in der Tabelle anklicken —<br>Marge-Cockpit erscheint hier.
        </div>
    @else
        <div>
            <div class="flex items-start justify-between gap-2">
                <h3 class="font-semibold tracking-tight text-gray-900 dark:text-gray-100 leading-snug">{{ $rezept->name }}</h3>
                <div class="flex items-center gap-1.5 shrink-0">
                    <button type="button" wire:click="$dispatch('vk-modal.oeffnen', { id: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" data-vk-bearbeiten>Bearbeiten</button>
                    <button type="button" wire:click="$dispatch('zutaten-editor.oeffnen', { id: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" data-vk-komponenten>Komponenten</button>
                </div>
            </div>
            @if($rezept->vk_wording_standard !== null)
                <p class="text-xs italic text-gray-400 mt-0.5">{{ $rezept->vk_wording_standard }}</p>
            @endif
            <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                <span class="{{ $pill }} font-medium {{ $statusPill[$rezept->status->value] ?? $variantPill['secondary'] }}">{{ $rezept->status->label() }}</span>
                @if($rezept->speisenKlasse !== null)
                    <span class="{{ $pill }} {{ $variantPill['info'] }}" title="{{ $rezept->speisenKlasse->bezeichnung }}">{{ $rezept->speisenKlasse->hauptgruppe?->code ?? 'HG?' }} · {{ $rezept->speisenKlasse->bezeichnung }}</span>
                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $rezept->speisenKlasse->diaetform }}</span>
                @else
                    <span class="{{ $pill }} {{ $variantPill['warning'] }}" title="V-22-Seed-Gate: Klassifikation fehlt">ohne Speisen-Klasse</span>
                @endif
            </div>
        </div>

        {{-- VERKAUFT-ALS-Box (Orange, 13_REFERENZ) --}}
        @if($cockpit['verkauft_als'] !== null)
            <div class="rounded-lg bg-orange-500/10 border border-orange-500/30 px-3 py-2 text-sm text-orange-900 dark:text-orange-200" data-verkauft-als>
                <span class="text-[10px] font-medium uppercase tracking-wider text-orange-600 dark:text-orange-400 block">Verkauft als</span>
                {{ $cockpit['verkauft_als']['anzahl'] !== null ? number_format((float) $cockpit['verkauft_als']['anzahl'], 1, ',', '.') : '?' }} {{ $cockpit['verkauft_als']['einheit'] }}
                @if($cockpit['verkauft_als']['g_pro_einheit'] !== null) · ≈ {{ number_format($cockpit['verkauft_als']['g_pro_einheit'], 0, ',', '.') }} g pro {{ $cockpit['verkauft_als']['einheit'] }}@endif
                @if($cockpit['verkauft_als']['yield_kg'] !== null) · Yield {{ number_format($cockpit['verkauft_als']['yield_kg'], 2, ',', '.') }} kg @endif
            </div>
        @endif

        {{-- KPI-Karten: EK · VK netto (Quelle) · VK BRUTTO (Highlight) · Wareneinsatz % --}}
        <div class="grid grid-cols-2 gap-2" data-vk-kpis>
            <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                <span class="{{ $dt }}">EK gesamt</span>
                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $rezept->ek_total_eur !== null ? number_format((float) $rezept->ek_total_eur, 2, ',', '.') . ' €' : '—' }}</p>
            </div>
            <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                <span class="{{ $dt }}">VK netto {{ $cockpit['vk']['quelle'] === 'manuell' ? '(manuell)' : ($cockpit['vk']['quelle'] === 'klasse' ? '(aus Klasse)' : '') }}</span>
                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100" data-vk-netto>{{ $cockpit['vk']['vk_netto'] !== null ? number_format($cockpit['vk']['vk_netto'], 2, ',', '.') . ' €' : '—' }}</p>
            </div>
            <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2" data-vk-brutto>
                <span class="text-[10px] font-medium uppercase tracking-wider text-violet-600 dark:text-violet-400">VK brutto</span>
                <p class="text-base font-bold text-violet-700 dark:text-violet-300">{{ $cockpit['vk_brutto'] !== null ? number_format($cockpit['vk_brutto'], 2, ',', '.') . ' €' : '—' }}</p>
            </div>
            <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                <span class="{{ $dt }}">Wareneinsatz</span>
                <p class="text-sm font-semibold {{ ($cockpit['marge']['wareneinsatz_pct'] ?? 0) > 35 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-gray-100' }}" data-wareneinsatz>
                    {{ $cockpit['marge'] !== null ? number_format($cockpit['marge']['wareneinsatz_pct'], 1, ',', '.') . ' %' : '—' }}
                </p>
            </div>
            @if($cockpit['pro_einheit'] !== null)
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                    <span class="{{ $dt }}">VK netto/Einheit</span>
                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ number_format($cockpit['pro_einheit']['vk_netto_pro_einheit'], 2, ',', '.') }} €</p>
                </div>
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                    <span class="{{ $dt }}">VK brutto/Einheit</span>
                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ number_format($cockpit['pro_einheit']['vk_brutto_pro_einheit'], 2, ',', '.') }} €</p>
                </div>
            @endif
        </div>

        {{-- Formel-Klartext bzw. Leer-/W-1-Hinweis --}}
        @if($cockpit['formel_fehlt'])
            <p class="text-xs text-amber-600 dark:text-amber-400" data-formel-fehlt>⚠ Aufschlagsklasse {{ $rezept->aufschlagsklasse?->code }}: Formel »deckungsbeitrag« nicht definiert (W-1) — Entscheid ausstehend, VK nur manuell.</p>
        @elseif($rezept->aufschlagsklasse !== null && $cockpit['vk']['vorschlag'] !== null)
            <p class="text-xs text-gray-400" data-formel-klartext>{{ $rezept->aufschlagsklasse->code }} · {{ $rezept->aufschlagsklasse->bezeichnung }} · {{ $cockpit['vk']['vorschlag']['formel'] }}</p>
        @elseif($rezept->ek_total_eur === null)
            <p class="text-xs text-gray-400" data-cockpit-leer>Kein EK berechnet — Zutaten ergänzen oder Lead-LAs setzen.</p>
        @elseif($rezept->aufschlagsklasse === null)
            <p class="text-xs text-gray-400" data-cockpit-leer>Keine Aufschlagsklasse gesetzt — VK-Vorschlag erst nach Klassifikation (M6-04).</p>
        @endif

        @if($rezept->beschreibung !== null)
            <div>
                <p class="{{ $dt }} mb-1">Beschreibung</p>
                <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed">{{ $rezept->beschreibung }}</p>
            </div>
        @endif
        @if($rezept->marketing_text !== null)
            <div>
                <p class="{{ $dt }} mb-1">Marketing</p>
                <p class="text-sm italic text-gray-600 dark:text-gray-300 leading-relaxed">{{ $rezept->marketing_text }}</p>
            </div>
        @endif

        {{-- Zutaten-Kurzliste (Komponenten: GPs und/oder Basisrezepte) --}}
        <div>
            <p class="{{ $dt }} mb-1">Komponenten ({{ $rezept->ingredients->count() }})</p>
            <div class="space-y-0.5" data-vk-zutaten>
                @foreach($rezept->ingredients as $z)
                    <p class="text-xs text-gray-600 dark:text-gray-300 truncate" wire:key="vkz-{{ $z->id }}">
                        <span class="text-gray-400">{{ $z->menge !== null ? rtrim(rtrim(number_format((float) $z->menge, 2, ',', '.'), '0'), ',') : '' }}</span>
                        {{ $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name }}
                        {{ $z->referenced_recipe_id !== null ? '↗' : '' }}
                    </p>
                @endforeach
            </div>
        </div>
    @endif
</div>
