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
                    <button type="button" wire:click="ai_klassifizieren" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="ai_classify_speisen_klasse (GL-07)" data-vk-klassifizieren>✨ Klassifizieren</button>
                    <button type="button" wire:click="ai_rollen" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="ai_verteile_rollen — Gesamt-Gericht-Sicht (V-21)" data-vk-rollen>🎭 Rollen</button>
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

        {{-- M6-05: GL-07-Vorschlags-Boxen (editierbar vor Übernahme: Verwerfen + neu) --}}
        @if($kiFehler !== null)<p class="text-xs text-rose-500" data-ki-fehler>{{ $kiFehler }}</p>@endif
        @if($klasseVorschlag !== null)
            <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 text-sm" data-klasse-vorschlag>
                <p class="text-gray-900 dark:text-gray-100">
                    ✨ Speisen-Klasse: <span class="font-medium">{{ $klasseVorschlag['klasse_name'] ?? 'kein sicherer Treffer' }}</span>
                    <span class="text-xs text-gray-400">· {{ round($klasseVorschlag['confidence'] * 100) }} %</span>
                </p>
                @if($klasseVorschlag['begruendung'] !== null)<p class="text-xs text-gray-400 mt-0.5">{{ $klasseVorschlag['begruendung'] }}</p>@endif
                <div class="flex gap-1.5 mt-1.5">
                    @if($klasseVorschlag['klasse_id'] !== null)
                        <button type="button" wire:click="accept_klasse" class="{{ $btnGhostXs }} text-emerald-600" data-klasse-accept>Übernehmen</button>
                    @endif
                    <button type="button" wire:click="reject_klasse" class="{{ $btnGhostXs }}" data-klasse-reject>Verwerfen</button>
                </div>
            </div>
        @endif
        @if($rollenVorschlag !== null)
            <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 text-sm" data-rollen-vorschlag>
                <p class="text-gray-900 dark:text-gray-100">🎭 Rollen-Verteilung <span class="text-xs text-gray-400">· {{ round($rollenVorschlag['confidence'] * 100) }} %</span></p>
                @if($rollenVorschlag['rollen'] === [])
                    <p class="text-xs text-gray-400 mt-0.5">Kein gültiger Vorschlag (Rollen-Vokabular: aroma_treiber · komponente · beilage · garnitur).</p>
                @else
                    <div class="mt-1 space-y-0.5">
                        @foreach($rollenVorschlag['rollen'] as $zeileId => $rolle)
                            @php($zeile = $rezept->ingredients->firstWhere('id', $zeileId))
                            <p class="text-xs text-gray-600 dark:text-gray-300" wire:key="rv-{{ $zeileId }}">
                                {{ $zeile?->referencedRecipe?->name ?? $zeile?->gp?->name ?? $zeile?->display_name ?? "Zeile {$zeileId}" }}
                                → <span class="font-medium">{{ $rolle }}</span>
                            </p>
                        @endforeach
                    </div>
                @endif
                <div class="flex gap-1.5 mt-1.5">
                    @if($rollenVorschlag['rollen'] !== [])
                        <button type="button" wire:click="accept_rollen" class="{{ $btnGhostXs }} text-emerald-600" data-rollen-accept>Übernehmen (danach pro Zeile korrigierbar)</button>
                    @endif
                    <button type="button" wire:click="reject_rollen" class="{{ $btnGhostXs }}" data-rollen-reject>Verwerfen</button>
                </div>
            </div>
        @endif

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

        {{-- D-6 §5.x (MVP): Kern-Anker VOR Pairing (Kern = Identität, dann Partner) --}}
        <div data-vk-kern-anker>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="toggleSektion('anker')"
                        class="flex-1 flex items-center justify-between py-1 text-xs font-medium uppercase tracking-wider text-gray-400 hover:text-violet-500 transition-colors">
                    <span>Kern-Anker ({{ $kernAnker->count() }}/5)</span>
                    <span>{{ ($offen['anker'] ?? false) ? '▾' : '▸' }}</span>
                </button>
                <button type="button" wire:click="$dispatch('aroma-netz.oeffnen', { recipeId: {{ $rezept->id }} })"
                        class="{{ $btnGhostXs }} shrink-0" title="Komponenten-Netz (D-6 §5.x)" data-vk-aroma-netz>🕸 Netz</button>
            </div>
            <div class="flex flex-wrap gap-1 mt-1">
                @foreach($kernAnker as $anker)
                    <span wire:key="vka-{{ $anker->id }}" class="{{ $pill }} {{ $variantPill['primary'] }} group" title="{{ $anker->quelle }}{{ $anker->ai_confidence !== null ? ' ' . round($anker->ai_confidence * 100) . '%' : '' }}">
                        ★ {{ $anker->display_de }}
                        <button type="button" wire:click="ankerLoesen({{ $anker->id }})" class="hidden group-hover:inline text-rose-400 ml-0.5" title="lösen">✕</button>
                    </span>
                @endforeach
            </div>
            @if($offen['anker'] ?? false)
                @if($fehlerAnker !== null)<p class="text-xs text-rose-500 mt-1" data-vk-anker-fehler>{{ $fehlerAnker }}</p>@endif
                <div class="relative mt-1.5">
                    <input type="search" wire:model.live.debounce.300ms="ankerSuche" placeholder="Anker verknüpfen …" class="{{ $input }} !py-1" data-vk-anker-suche />
                    @foreach($ankerKandidaten as $kandidat)
                        <button type="button" wire:key="vkak-{{ $kandidat->id }}" wire:click="ankerVerknuepfen({{ $kandidat->id }})"
                                class="block w-full text-left px-2 py-1 rounded text-xs text-gray-700 dark:text-gray-200 hover:bg-violet-500/10">{{ $kandidat->display_de }} <span class="text-gray-400">{{ $kandidat->slug }}</span></button>
                    @endforeach
                </div>
                @if($kohaesion !== null)
                    <div class="mt-2 rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2 text-xs space-y-0.5" data-vk-kohaesion>
                        <p class="text-gray-900 dark:text-gray-100">Aroma-Kohäsion: <span class="font-medium">{{ $kohaesion['score'] }}</span>
                            · min {{ $kohaesion['min_score'] }} · Coverage {{ $kohaesion['coverage_pct'] }} % ({{ $kohaesion['rated_pairs'] }}/{{ $kohaesion['total_pairs'] }})
                            @if($kohaesion['coverage_pct'] < 30)<span class="text-amber-500">· dünne Datenlage</span>@endif
                        </p>
                        @if($kohaesion['weakest_pair'] !== null)
                            <p class="text-gray-400">Schwächstes Glied: {{ $kohaesion['weakest_pair']['a'] }} ↔ {{ $kohaesion['weakest_pair']['b'] }} ({{ $kohaesion['weakest_pair']['score'] }}, {{ $kohaesion['weakest_pair']['typ'] }})</p>
                        @endif
                        @php($orphans = collect($kohaesion['komponenten'])->filter(fn ($k) => $k['is_orphan']))
                        @if($orphans->isNotEmpty())
                            <p class="text-amber-600 dark:text-amber-400">Ausreißer: {{ $orphans->pluck('label')->implode(', ') }}</p>
                        @endif
                    </div>
                @endif
            @endif
        </div>

        {{-- D-6 §5.x: Pairing-Section (✨-Vorschläge folgen mit echtem Provider) --}}
        <div data-vk-pairing-sektion>
            <button type="button" wire:click="toggleSektion('pairing')"
                    class="w-full flex items-center justify-between py-1 text-xs font-medium uppercase tracking-wider text-gray-400 hover:text-violet-500 transition-colors">
                <span>Pairings</span>
                <span>{{ ($offen['pairing'] ?? false) ? '▾' : '▸' }}</span>
            </button>
            @if($pairings !== null)
                <div class="flex flex-wrap gap-1 mt-1" data-vk-pairing-chips>
                    @foreach($pairings as $p)
                        <span wire:key="vkpp-{{ $loop->index }}" class="{{ $pill }} {{ ['klassisch' => $variantPill['success'], 'verbund' => $variantPill['info'], 'trinitas' => $variantPill['primary'], 'kontrast' => $variantPill['warning']][$p->typ] ?? $variantPill['secondary'] }}"
                              title="{{ $p->typ }} · {{ $p->konfidenz }}">{{ $p->display_de }}</span>
                    @endforeach
                </div>
            @endif
        </div>

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
