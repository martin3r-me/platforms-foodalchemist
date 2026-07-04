{{-- M6-03: VK-DetailPanel — VERKAUFT-ALS-Box, Marge-KPI-Karten, Formel-Klartext (13_REFERENZ N2) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="p-4 space-y-4 min-h-full bg-gray-500/[0.04] dark:bg-white/[0.02]" data-vk-panel>
    @if($rezept === null)
        <div class="text-center text-xs text-gray-400 py-12">
            <div class="text-2xl mb-2">€</div>
            Gericht in der Tabelle anklicken —<br>Marge-Cockpit erscheint hier.
        </div>
    @else
        <div>
            {{-- R12 (Dominique): Name braucht die volle Breite — Aktionen als eigene Zeile DARUNTER --}}
            <h3 class="text-[15px] font-semibold tracking-tight text-gray-900 dark:text-gray-100 leading-snug">{{ $rezept->name }}</h3>
            @if($rezept->sales_wording_standard !== null)
                <p class="text-[11px] italic text-gray-400 mt-0.5">{{ $rezept->sales_wording_standard }}</p>
            @endif
            <div class="flex flex-wrap items-center gap-1.5 mt-2" data-vk-aktionen>
                <button type="button" wire:click="$dispatch('vk-modal.oeffnen', { id: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" data-vk-bearbeiten>Bearbeiten</button>
                <button type="button" wire:click="$dispatch('zutaten-editor.oeffnen', { id: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" data-vk-komponenten>Komponenten</button>
                <button type="button" wire:click="ai_klassifizieren" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="ai_classify_speisen_klasse (GL-07)" data-vk-klassifizieren>✨ Klassifizieren</button>
                <button type="button" wire:click="ai_rollen" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="ai_verteile_rollen — Gesamt-Gericht-Sicht (V-21)" data-vk-rollen>🎭 Rollen</button>
            </div>
            <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                <span class="{{ $pill }} font-medium {{ $statusPill[$rezept->status->value] ?? $variantPill['secondary'] }}">{{ $rezept->status->label() }}</span>
                @if($rezept->speisenKlasse !== null)
                    <span class="{{ $pill }} {{ $variantPill['info'] }}" title="{{ $rezept->speisenKlasse->label }}">{{ $rezept->speisenKlasse->hauptgruppe?->code ?? 'HG?' }} · {{ $rezept->speisenKlasse->label }}</span>
                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $rezept->speisenKlasse->diaetform }}</span>
                @else
                    <span class="{{ $pill }} {{ $variantPill['warning'] }}" title="V-22-Seed-Gate: Klassifikation fehlt">ohne Speisen-Klasse</span>
                @endif
            </div>
        </div>

        {{-- M6-05: GL-07-Vorschlags-Boxen (editierbar vor Übernahme: Verwerfen + neu) --}}
        @if($kiFehler !== null)<p class="text-[11px] text-rose-500" data-ki-fehler>{{ $kiFehler }}</p>@endif
        @if($klasseVorschlag !== null)
            <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 text-xs" data-klasse-vorschlag>
                <p class="text-gray-900 dark:text-gray-100">
                    ✨ Speisen-Klasse: <span class="font-medium">{{ $klasseVorschlag['klasse_name'] ?? 'kein sicherer Treffer' }}</span>
                    <span class="text-[11px] text-gray-400">· {{ round($klasseVorschlag['confidence'] * 100) }} %</span>
                </p>
                @if($klasseVorschlag['reasoning'] !== null)<p class="text-[11px] text-gray-400 mt-0.5">{{ $klasseVorschlag['reasoning'] }}</p>@endif
                <div class="flex gap-1.5 mt-1.5">
                    @if($klasseVorschlag['klasse_id'] !== null)
                        <button type="button" wire:click="accept_klasse" class="{{ $btnGhostXs }} text-emerald-600" data-klasse-accept>Übernehmen</button>
                    @endif
                    <button type="button" wire:click="reject_klasse" class="{{ $btnGhostXs }}" data-klasse-reject>Verwerfen</button>
                </div>
            </div>
        @endif
        @if($rollenVorschlag !== null)
            <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 text-xs" data-rollen-vorschlag>
                <p class="text-gray-900 dark:text-gray-100">🎭 Rollen-Verteilung <span class="text-[11px] text-gray-400">· {{ round($rollenVorschlag['confidence'] * 100) }} %</span></p>
                @if($rollenVorschlag['rollen'] === [])
                    <p class="text-[11px] text-gray-400 mt-0.5">Kein gültiger Vorschlag (Rollen-Vokabular: aroma_treiber · komponente · beilage · garnitur).</p>
                @else
                    <div class="mt-1 space-y-0.5">
                        @foreach($rollenVorschlag['rollen'] as $zeileId => $role)
                            @php($zeile = $rezept->ingredients->firstWhere('id', $zeileId))
                            <p class="text-[11px] text-gray-600 dark:text-gray-300" wire:key="rv-{{ $zeileId }}">
                                {{ $zeile?->referencedRecipe?->name ?? $zeile?->gp?->name ?? $zeile?->display_name ?? "Zeile {$zeileId}" }}
                                → <span class="font-medium">{{ $role }}</span>
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
            <div class="rounded-lg bg-orange-500/10 border border-orange-500/30 px-3 py-2 text-xs text-orange-900 dark:text-orange-200" data-verkauft-als>
                <span class="text-[10px] font-medium uppercase tracking-wider text-orange-600 dark:text-orange-400 block">Verkauft als</span>
                {{ $cockpit['verkauft_als']['anzahl'] !== null ? number_format((float) $cockpit['verkauft_als']['anzahl'], 1, ',', '.') : '?' }} {{ $cockpit['verkauft_als']['unit'] }}
                @if($cockpit['verkauft_als']['g_pro_einheit'] !== null) · ≈ {{ number_format($cockpit['verkauft_als']['g_pro_einheit'], 0, ',', '.') }} g pro {{ $cockpit['verkauft_als']['unit'] }}@endif
                @if($cockpit['verkauft_als']['yield_kg'] !== null) · Yield {{ number_format($cockpit['verkauft_als']['yield_kg'], 2, ',', '.') }} kg @endif
            </div>
        @endif

        {{-- KPI-Karten: EK · VK netto (Quelle) · VK BRUTTO (Highlight) · Wareneinsatz % --}}
        <div class="grid grid-cols-2 gap-2" data-vk-kpis>
            <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                <span class="{{ $dt }}">EK gesamt</span>
                <p class="text-xs font-semibold text-gray-900 dark:text-gray-100">{{ $rezept->ek_total_eur !== null ? number_format((float) $rezept->ek_total_eur, 2, ',', '.') . ' €' : '—' }}</p>
            </div>
            <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                <span class="{{ $dt }}">VK netto {{ $cockpit['vk']['source'] === 'manuell' ? '(manuell)' : ($cockpit['vk']['source'] === 'class' ? '(aus Klasse)' : '') }}</span>
                <p class="text-xs font-semibold text-gray-900 dark:text-gray-100" data-vk-netto>{{ $cockpit['vk']['sales_net'] !== null ? number_format($cockpit['vk']['sales_net'], 2, ',', '.') . ' €' : '—' }}</p>
            </div>
            <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2" data-vk-brutto>
                <span class="text-[10px] font-medium uppercase tracking-wider text-violet-600 dark:text-violet-400">VK brutto</span>
                <p class="text-base font-bold text-violet-700 dark:text-violet-300">{{ $cockpit['sales_gross'] !== null ? number_format($cockpit['sales_gross'], 2, ',', '.') . ' €' : '—' }}</p>
            </div>
            <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                <span class="{{ $dt }}">Wareneinsatz</span>
                <p class="text-xs font-semibold {{ ($cockpit['marge']['wareneinsatz_pct'] ?? 0) > 35 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-gray-100' }}" data-wareneinsatz>
                    {{ $cockpit['marge'] !== null ? number_format($cockpit['marge']['wareneinsatz_pct'], 1, ',', '.') . ' %' : '—' }}
                </p>
            </div>
            @if($cockpit['pro_einheit'] !== null)
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                    <span class="{{ $dt }}">VK netto/Einheit</span>
                    <p class="text-xs font-semibold text-gray-900 dark:text-gray-100">{{ number_format($cockpit['pro_einheit']['vk_netto_pro_einheit'], 2, ',', '.') }} €</p>
                </div>
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                    <span class="{{ $dt }}">VK brutto/Einheit</span>
                    <p class="text-xs font-semibold text-gray-900 dark:text-gray-100">{{ number_format($cockpit['pro_einheit']['vk_brutto_pro_einheit'], 2, ',', '.') }} €</p>
                </div>
            @endif
        </div>

        {{-- Formel-Klartext bzw. Leer-/W-1-Hinweis --}}
        @if($cockpit['formel_fehlt'])
            <p class="text-[11px] text-amber-600 dark:text-amber-400" data-formel-fehlt>⚠ Aufschlagsklasse {{ $rezept->aufschlagsklasse?->code }}: Formel »deckungsbeitrag« nicht definiert (W-1) — Entscheid ausstehend, VK nur manuell.</p>
        @elseif($rezept->aufschlagsklasse !== null && $cockpit['vk']['vorschlag'] !== null)
            <p class="text-[11px] text-gray-400" data-formel-klartext>{{ $rezept->aufschlagsklasse->code }} · {{ $rezept->aufschlagsklasse->label }} · {{ $cockpit['vk']['vorschlag']['formel'] }}</p>
        @elseif($rezept->ek_total_eur === null)
            <p class="text-[11px] text-gray-400" data-cockpit-leer>Kein EK berechnet — Zutaten ergänzen oder Lead-LAs setzen.</p>
        @elseif($rezept->aufschlagsklasse === null)
            <p class="text-[11px] text-gray-400" data-cockpit-leer>Keine Aufschlagsklasse gesetzt — VK-Vorschlag erst nach Klassifikation (M6-04).</p>
        @endif

        @if($rezept->description !== null)
            <div>
                <p class="{{ $dt }} mb-1">Beschreibung</p>
                <p class="text-xs text-gray-600 dark:text-gray-300 leading-relaxed">{{ $rezept->description }}</p>
            </div>
        @endif
        {{-- Marketing-Text entfällt hier (UX-Umbau 2026-07-03): kundenspezifischer
             Marketing-/Beschreibungstext wird am Foodbook-Block gepflegt, nicht am Gericht. --}}

        {{-- M9-01k: Sektor-/Niveau-Eignung — Chips mit ✕, +manuell-Select, ✨ Eignung --}}
        <div data-vk-eignung>
            <p class="{{ $dt }} mb-1 flex items-center gap-2">Eignung
                <button type="button" wire:click="kiEignung" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 ml-auto normal-case" title="recipe.sektor + recipe.level — nur «geeignet»-Urteile werden Vorschlag" data-ki-eignung>✨ Eignung</button>
            </p>
            @if($eignungVorschlag !== null)
                <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 text-xs mb-1" data-eignung-vorschlag>
                    <p class="text-[11px] text-gray-600 dark:text-gray-300">✨ geeignet für:
                        @foreach($eignungVorschlag['slugs'] as $slug => $typ)<span class="{{ $pill }} {{ $variantPill['info'] }} ml-1">{{ $typ }}: {{ $slug }}</span>@endforeach
                    </p>
                    <div class="flex gap-1.5 mt-1.5">
                        <button type="button" wire:click="eignungUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-eignung-uebernehmen>Übernehmen ({{ round($eignungVorschlag['confidence'] * 100) }} %)</button>
                        <button type="button" wire:click="eignungVerwerfen" class="{{ $btnGhostXs }}">Verwerfen</button>
                    </div>
                </div>
            @endif
            @foreach(['sektor' => ['Sektor', $sektorEignungen, 'sector_slug'], 'level' => ['Niveau', $niveauEignungen, 'level_slug']] as $typ => [$lbl, $eignungen, $slugSpalte])
                <div class="flex items-center gap-1.5 flex-wrap py-0.5" data-eignung-zeile="{{ $typ }}">
                    <span class="text-[11px] text-gray-400 w-12 shrink-0">{{ $lbl }}</span>
                    @forelse($eignungen as $e)
                        <span wire:key="eig-{{ $typ }}-{{ $e->id }}" class="{{ $pill }} {{ $variantPill[$typ === 'sektor' ? 'secondary' : 'info'] }} group"
                              title="{{ $e->source }}{{ $e->ai_confidence !== null ? ' · ' . round($e->ai_confidence * 100) . ' %' : '' }}">
                            {{ $e->{$slugSpalte} }}
                            <button type="button" wire:click="eignungEntfernen('{{ $typ }}', '{{ $e->{$slugSpalte} }}')" class="hidden group-hover:inline text-rose-400 ml-0.5" title="entfernen">✕</button>
                        </span>
                    @empty
                        <span class="text-[11px] text-gray-400 italic">— keine —</span>
                    @endforelse
                    <select wire:change="eignungSetzen('{{ $typ }}', $event.target.value)"
                            class="{{ $input }} !py-0.5 !w-32 !text-[11px]" data-eignung-select="{{ $typ }}">
                        <option value="">+ manuell…</option>
                        @foreach($eignungVokabular[$typ]['slugs'] as $slug)
                            @if(!$eignungen->contains($slugSpalte, $slug))<option value="{{ $slug }}">{{ $slug }}</option>@endif
                        @endforeach
                    </select>
                </div>
            @endforeach
        </div>

        {{-- D-6 §5.x (MVP): Kern-Anker VOR Pairing (Kern = Identität, dann Partner) --}}
        <div data-vk-kern-anker>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="toggleSektion('anker')"
                        class="flex-1 flex items-center justify-between py-1 text-[11px] font-medium uppercase tracking-wider text-gray-400 hover:text-violet-500 transition-colors">
                    <span>Kern-Anker ({{ $kernAnker->count() }}/5)</span>
                    <span>{{ ($offen['anker'] ?? false) ? '▾' : '▸' }}</span>
                </button>
                <button type="button" wire:click="$dispatch('aroma-netz.oeffnen', { recipeId: {{ $rezept->id }} })"
                        class="{{ $btnGhostXs }} shrink-0" title="Komponenten-Netz (D-6 §5.x)" data-vk-aroma-netz>🕸 Netz</button>
            </div>
            <div class="flex flex-wrap gap-1 mt-1">
                @foreach($kernAnker as $anker)
                    <span wire:key="vka-{{ $anker->id }}" class="{{ $pill }} {{ $variantPill['primary'] }} group" title="{{ $anker->source }}{{ $anker->ai_confidence !== null ? ' ' . round($anker->ai_confidence * 100) . '%' : '' }}">
                        ★ {{ $anker->display_de }}
                        <button type="button" wire:click="ankerLoesen({{ $anker->id }})" class="hidden group-hover:inline text-rose-400 ml-0.5" title="lösen">✕</button>
                    </span>
                @endforeach
            </div>
            @if($offen['anker'] ?? false)
                @if($fehlerAnker !== null)<p class="text-[11px] text-rose-500 mt-1" data-vk-anker-fehler>{{ $fehlerAnker }}</p>@endif
                <div class="relative mt-1.5">
                    <input type="search" wire:model.live.debounce.300ms="ankerSuche" placeholder="Anker verknüpfen …" class="{{ $input }} !py-1" data-vk-anker-suche />
                    @foreach($ankerKandidaten as $kandidat)
                        <button type="button" wire:key="vkak-{{ $kandidat->id }}" wire:click="ankerVerknuepfen({{ $kandidat->id }})"
                                class="block w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 dark:text-gray-200 hover:bg-violet-500/10">{{ $kandidat->display_de }} <span class="text-gray-400">{{ $kandidat->slug }}</span></button>
                    @endforeach
                </div>
                @if($kohaesion !== null)
                    <div class="mt-2 rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2 text-[11px] space-y-0.5" data-vk-kohaesion>
                        <p class="text-gray-900 dark:text-gray-100">Aroma-Kohäsion: <span class="font-medium">{{ $kohaesion['score'] }}</span>
                            · min {{ $kohaesion['min_score'] }} · Coverage {{ $kohaesion['coverage_pct'] }} % ({{ $kohaesion['rated_pairs'] }}/{{ $kohaesion['total_pairs'] }})
                            @if($kohaesion['coverage_pct'] < 30)<span class="text-amber-500">· dünne Datenlage</span>@endif
                        </p>
                        @if($kohaesion['weakest_pair'] !== null)
                            <p class="text-gray-400">Schwächstes Glied: {{ $kohaesion['weakest_pair']['a'] }} ↔ {{ $kohaesion['weakest_pair']['b'] }} ({{ $kohaesion['weakest_pair']['score'] }}, {{ $kohaesion['weakest_pair']['type'] }})</p>
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
                    class="w-full flex items-center justify-between py-1 text-[11px] font-medium uppercase tracking-wider text-gray-400 hover:text-violet-500 transition-colors">
                <span>Pairings</span>
                <span>{{ ($offen['pairing'] ?? false) ? '▾' : '▸' }}</span>
            </button>
            @if($pairings !== null)
                <div class="flex flex-wrap gap-1 mt-1" data-vk-pairing-chips>
                    @foreach($pairings as $p)
                        <span wire:key="vkpp-{{ $loop->index }}" class="{{ $pill }} {{ ['klassisch' => $variantPill['success'], 'verbund' => $variantPill['info'], 'trinitas' => $variantPill['primary'], 'kontrast' => $variantPill['warning']][$p->type] ?? $variantPill['secondary'] }}"
                              title="{{ $p->type }} · {{ $p->confidence }}">{{ $p->display_de }}</span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- D-6 §5.x / GL-10 Achse 2: Kohärenz-Judge (gecacht, nie mit Aroma-Score verrechnet) --}}
        <div data-vk-kohaerenz>
            <button type="button" wire:click="toggleSektion('kohaerenz')"
                    class="w-full flex items-center justify-between py-1 text-[11px] font-medium uppercase tracking-wider text-gray-400 hover:text-violet-500 transition-colors">
                <span>Kulinarische Kohärenz <span class="normal-case tracking-normal">· KI-Urteil</span></span>
                <span>{{ ($offen['kohaerenz'] ?? false) ? '▾' : '▸' }}</span>
            </button>
            @if(($offen['kohaerenz'] ?? false) && $kohaerenzStatus !== null)
                @php($urteil = $kohaerenzStatus['cache'])
                @if($kiFehler !== null)<p class="text-[11px] text-rose-500 mb-1" data-vk-ki-fehler>{{ $kiFehler }}</p>@endif
                @if($urteil?->score !== null)
                    <div class="mt-1 space-y-1.5" data-vk-kohaerenz-urteil>
                        <div class="flex items-center gap-3">
                            <span class="text-2xl font-semibold {{ $urteil->score >= 80 ? 'text-green-600 dark:text-green-400' : ($urteil->score >= 50 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400') }}">{{ $urteil->score }} %</span>
                            @if($urteil->label !== null)
                                <span class="px-2.5 py-0.5 rounded-full border text-[10px] font-semibold uppercase tracking-wider {{ $urteil->score >= 80 ? 'border-green-600 text-green-700 dark:border-green-400 dark:text-green-300' : 'border-amber-500 text-amber-700 dark:text-amber-300' }}">{{ $urteil->label }}</span>
                            @endif
                        </div>
                        @if($urteil->reasoning !== null)
                            <p class="text-xs text-gray-600 dark:text-gray-300 leading-relaxed">{{ $urteil->reasoning }}</p>
                        @endif
                        @if($urteil->schwachstelle !== null)
                            <p class="text-[11px] text-amber-600 dark:text-amber-400">Schwachstelle: {{ $urteil->schwachstelle }}</p>
                        @endif
                        <p class="text-[11px] text-gray-400">{{ $urteil->judged_at?->format('Y-m-d') }} · {{ $urteil->judge_model }}
                            @if($kohaerenzStatus['stale'])<span class="text-amber-500" data-vk-kohaerenz-stale> · Zutaten geändert — Urteil veraltet</span>@endif
                        </p>
                    </div>
                @else
                    <p class="text-[11px] text-gray-400 mt-1">Noch kein Urteil — der Judge beurteilt die Stimmigkeit des Tellers (zweite Achse neben der Aroma-Kohäsion).</p>
                @endif
                <button type="button" wire:click="pruefeKohaerenz" class="{{ $btnGhostXs }} mt-1.5" data-vk-kohaerenz-pruefen>
                    {{ $urteil?->score !== null ? 'Erneut prüfen' : '✨ Prüfen' }}
                </button>
            @endif
        </div>

        {{-- D-6 §5.x: Was hebt den Teller? (vk.teller_heber, gecacht in derselben Zeile) --}}
        <div data-vk-heber>
            <button type="button" wire:click="toggleSektion('heber')"
                    class="w-full flex items-center justify-between py-1 text-[11px] font-medium uppercase tracking-wider text-gray-400 hover:text-violet-500 transition-colors">
                <span>Was hebt den Teller? <span class="normal-case tracking-normal">· KI-Vorschlag</span></span>
                <span>{{ ($offen['heber'] ?? false) ? '▾' : '▸' }}</span>
            </button>
            @if(($offen['heber'] ?? false) && $kohaerenzStatus !== null)
                @php($heber = $kohaerenzStatus['cache']?->heber_json)
                @if($kiFehler !== null)<p class="text-[11px] text-rose-500 mb-1" data-vk-ki-fehler>{{ $kiFehler }}</p>@endif
                @if($heber !== null && ($heber['vorschlaege'] ?? []) !== [])
                    @php($typen = collect($heber['vorschlaege'])->countBy('type'))
                    <div class="mt-1 space-y-2" x-data="{ typ: '{{ collect($heber['vorschlaege'])->first()['type'] }}' }" data-vk-heber-vorschlaege>
                        @if(($heber['einschaetzung'] ?? null) !== null)
                            <p class="text-xs font-medium text-gray-900 dark:text-gray-100 leading-relaxed">{{ $heber['einschaetzung'] }}</p>
                        @endif
                        <div class="flex flex-wrap gap-1.5">
                            @foreach(['kontrast' => 'Kontrast', 'ergaenzung' => 'Ergänzung', 'veredelung' => 'Veredelung'] as $t => $lbl)
                                @if(($typen[$t] ?? 0) > 0)
                                    <button type="button" @click="typ = '{{ $t }}'"
                                            class="px-2.5 py-0.5 rounded-full border text-[11px] transition-colors"
                                            :class="typ === '{{ $t }}' ? 'border-amber-500 text-amber-700 dark:text-amber-300 font-semibold' : 'border-black/10 dark:border-white/15 text-gray-400'">{{ $lbl }} {{ $typen[$t] }}</button>
                                @endif
                            @endforeach
                        </div>
                        @foreach($heber['vorschlaege'] as $v)
                            <div x-show="typ === '{{ $v['type'] }}'" class="flex gap-2 text-xs" wire:key="vkh-{{ $loop->index }}">
                                @if($v['confidence'] !== null)<span class="font-semibold text-green-600 dark:text-green-400 shrink-0">{{ round($v['confidence'] * 100) }} %</span>@endif
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $v['zutat'] }}
                                        @if($v['category'] !== null)<span class="text-[11px] font-normal text-gray-400 ml-1">{{ $v['category'] }}</span>@endif
                                    </p>
                                    @if($v['reasoning'] !== null)<p class="text-[11px] text-gray-500 dark:text-gray-400 leading-relaxed">{{ $v['reasoning'] }}</p>@endif
                                </div>
                            </div>
                        @endforeach
                        <p class="text-[11px] text-gray-400">{{ $kohaerenzStatus['cache']->heber_at?->format('Y-m-d') }} · {{ $kohaerenzStatus['cache']->heber_model }}</p>
                    </div>
                @else
                    <p class="text-[11px] text-gray-400 mt-1">Noch keine Vorschläge — die KI nennt 1–3 machbare Hebel (Kontrast / Ergänzung / Veredelung).</p>
                @endif
                <button type="button" wire:click="schlageHeberVor" class="{{ $btnGhostXs }} mt-1.5" data-vk-heber-vorschlagen>
                    {{ ($heber['vorschlaege'] ?? []) !== [] ? 'Erneut vorschlagen' : '✨ Vorschlagen' }}
                </button>
            @endif
        </div>

        {{-- D-6 §5.x / GL-10 §3.3: Aroma-Nachbarn (deterministisch — Discovery, kein Teller-Urteil) --}}
        <div data-vk-nachbarn>
            <button type="button" wire:click="toggleSektion('nachbarn')"
                    class="w-full flex items-center justify-between py-1 text-[11px] font-medium uppercase tracking-wider text-gray-400 hover:text-violet-500 transition-colors">
                <span>Aroma-Nachbarn <span class="normal-case tracking-normal">· geteilte Aromastoffe</span></span>
                <span>{{ ($offen['nachbarn'] ?? false) ? '▾' : '▸' }}</span>
            </button>
            @if(($offen['nachbarn'] ?? false) && $nachbarn !== null)
                <p class="text-[11px] text-gray-400 mt-1">Aromaverwandte Zutaten aus dem Geschmacks-Netz — Inspiration, kein Teller-Urteil.</p>
                @if($nachbarn['klassiker'] === [])
                    <p class="text-[11px] text-gray-400 mt-1" data-vk-nachbarn-leer>Zu wenige aufgelöste Anker (mind. 2 nötig) — Kern-Anker setzen hilft.</p>
                @else
                    <div class="mt-1.5 space-y-2" x-data="{ modus: 'klassiker' }" data-vk-nachbarn-liste>
                        <div class="flex items-center gap-1.5">
                            <button type="button" @click="modus = 'klassiker'" class="{{ $pill }} transition-colors"
                                    :class="modus === 'klassiker' ? '{{ $variantPill['primary'] }}' : '{{ $variantPill['secondary'] }}'">Klassiker</button>
                            <button type="button" @click="modus = 'signature'" class="{{ $pill }} transition-colors"
                                    :class="modus === 'signature' ? '{{ $variantPill['primary'] }}' : '{{ $variantPill['secondary'] }}'">Signature</button>
                            <span class="text-[10px] text-gray-400" x-text="modus === 'klassiker' ? 'trifft viele Komponenten (sicher)' : 'spezifisch statt promiskuitiv (eigen)'"></span>
                        </div>
                        @foreach(['klassiker', 'signature'] as $modus)
                            <div x-show="modus === '{{ $modus }}'" class="space-y-1.5">
                                @foreach($nachbarn[$modus] as $n)
                                    <div class="flex gap-2 text-xs" wire:key="vkn-{{ $modus }}-{{ $n['anchor_id'] }}">
                                        <span class="font-semibold shrink-0 {{ $n['mean_w'] >= 80 ? 'text-green-600 dark:text-green-400' : 'text-blue-600 dark:text-blue-400' }}">{{ $n['mean_w'] }} %</span>
                                        <div class="min-w-0">
                                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $n['slug'] }}
                                                @if($n['allrounder'])<span class="text-[11px] font-normal text-gray-400 ml-1">Allrounder</span>@endif
                                            </p>
                                            <p class="text-[11px] text-gray-500 dark:text-gray-400 truncate">verbindet {{ $n['cover'] }}/{{ $n['dish_n'] }}: {{ implode(', ', $n['trifft']) }}</p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        {{-- R6 (Dominique, Bild-5): Diät · Allergene · Zusatzstoffe wie im Basis-Panel --}}
        @include('foodalchemist::livewire.recipes.partials.deklaration')

        {{-- Zutaten-Kurzliste — R12 Jarvis-Format: Menge+Einheit grau vorangestellt, voller Name, text-xs --}}
        <div>
            <p class="{{ $dt }} mb-1">Komponenten ({{ $rezept->ingredients->count() }})</p>
            <div class="space-y-1" data-vk-zutaten>
                @foreach($rezept->ingredients as $z)
                    <p class="text-xs text-gray-900 dark:text-gray-100 leading-snug" wire:key="vkz-{{ $z->id }}">
                        <span class="text-gray-400 tabular-nums">{{ $z->quantity !== null ? rtrim(rtrim(number_format((float) $z->quantity, 2, ',', '.'), '0'), ',') . ' ' . ($z->unit?->slug ?? '') : '' }}</span>
                        {{ $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name }}
                    </p>
                @endforeach
            </div>
        </div>
    @endif
</div>
