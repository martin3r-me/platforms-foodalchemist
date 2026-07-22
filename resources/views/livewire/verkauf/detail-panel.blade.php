{{-- M6-03: VK-DetailPanel — Verkaufs-/Marge-Linse. Redesign v3 2026-07-21 (Dominique):
     NICHT ausklappbar (alles direkt sichtbar), größere Typo, neu angeordnet;
     Glance = Cockpit (Preis/Marge) + Sicherheit; Pairing-Netz als Inline-Graph;
     analytische KI-Blöcke kompakt am Fuß. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="p-4 space-y-4 min-h-full bg-gray-500/[0.04]" data-vk-panel>
    @if($rezept === null)
        <div class="text-center text-xs text-gray-500 py-12">
            <div class="text-2xl mb-2">€</div>
            Gericht in der Tabelle anklicken —<br>Marge-Cockpit erscheint hier.
        </div>
    @else
        {{-- Kopf: Name + Aktionen + Status/Klasse/Diät --}}
        <div>
            <h3 class="text-base font-semibold tracking-tight text-gray-900 leading-snug">{{ $rezept->name }}</h3>
            @if($rezept->sales_wording_standard !== null)
                <p class="text-xs italic text-gray-500 mt-0.5">{{ $rezept->sales_wording_standard }}</p>
            @endif
            <div class="flex flex-wrap items-center gap-1.5 mt-2.5" data-vk-aktionen>
                <button type="button" wire:click="$dispatch('vk-modal.oeffnen', { id: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" data-vk-bearbeiten>@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5') Bearbeiten</button>
                <button type="button" wire:click="$dispatch('zutaten-editor.oeffnen', { id: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" data-vk-komponenten>@svg('heroicon-o-squares-2x2', 'w-3.5 h-3.5') Komponenten</button>
                <button type="button" wire:click="ai_klassifizieren" class="{{ $btnGhostXs }} text-violet-600" title="ai_classify_speisen_klasse (GL-07)" data-vk-klassifizieren>✨ Klassifizieren</button>
                <button type="button" wire:click="ai_rollen" class="{{ $btnGhostXs }} text-violet-600" title="ai_verteile_rollen (V-21)" data-vk-rollen>🎭 Rollen</button>
            </div>
            <div class="flex flex-wrap items-center gap-1.5 mt-2">
                <span class="{{ $pill }} font-medium {{ $statusPill[$rezept->status->value] ?? $variantPill['secondary'] }}">{{ $rezept->status->label() }}</span>
                @if($rezept->dishClass !== null)
                    <span class="{{ $pill }} {{ $variantPill['info'] }}" title="{{ $rezept->dishClass->label }}">{{ $rezept->dishClass->mainGroup?->code ?? 'HG?' }} · {{ $rezept->dishClass->label }}</span>
                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $rezept->dishClass->diet_form }}</span>
                @else
                    <span class="{{ $pill }} {{ $variantPill['warning'] }}" title="V-22-Seed-Gate: Klassifikation fehlt">ohne Speisen-Klasse</span>
                @endif
            </div>
        </div>

        {{-- M6-05: GL-07-Vorschlags-Boxen --}}
        @if($kiFehler !== null)<p class="text-[11px] text-rose-500" data-ki-fehler>{{ $kiFehler }}</p>@endif
        @if($klasseVorschlag !== null)
            <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 text-xs" data-klasse-vorschlag>
                <p class="text-gray-900">✨ Speisen-Klasse: <span class="font-medium">{{ $klasseVorschlag['klasse_name'] ?? 'kein sicherer Treffer' }}</span>
                    <span class="text-[11px] text-gray-500">· {{ round($klasseVorschlag['confidence'] * 100) }} %</span></p>
                @if($klasseVorschlag['reasoning'] !== null)<p class="text-[11px] text-gray-500 mt-0.5">{{ $klasseVorschlag['reasoning'] }}</p>@endif
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
                <p class="text-gray-900">🎭 Rollen-Verteilung <span class="text-[11px] text-gray-500">· {{ round($rollenVorschlag['confidence'] * 100) }} %</span></p>
                @if($rollenVorschlag['rollen'] === [])
                    <p class="text-[11px] text-gray-500 mt-0.5">Kein gültiger Vorschlag (aroma_treiber · komponente · beilage · garnitur).</p>
                @else
                    <div class="mt-1 space-y-0.5">
                        @foreach($rollenVorschlag['rollen'] as $zeileId => $role)
                            @php($zeile = $rezept->ingredients->firstWhere('id', $zeileId))
                            <p class="text-[11px] text-gray-600" wire:key="rv-{{ $zeileId }}">{{ $zeile?->referencedRecipe?->name ?? $zeile?->gp?->name ?? $zeile?->display_name ?? "Zeile {$zeileId}" }} → <span class="font-medium">{{ $role }}</span></p>
                        @endforeach
                    </div>
                @endif
                <div class="flex gap-1.5 mt-1.5">
                    @if($rollenVorschlag['rollen'] !== [])
                        <button type="button" wire:click="accept_rollen" class="{{ $btnGhostXs }} text-emerald-600" data-rollen-accept>Übernehmen</button>
                    @endif
                    <button type="button" wire:click="reject_rollen" class="{{ $btnGhostXs }}" data-rollen-reject>Verwerfen</button>
                </div>
            </div>
        @endif

        @if($cockpit['verkauft_als'] !== null)
            <div class="rounded-lg bg-orange-500/10 border border-orange-500/30 px-3 py-2 text-xs text-orange-900" data-verkauft-als>
                <span class="text-[10px] font-medium uppercase tracking-wider text-orange-600 block">Verkauft als</span>
                {{ $cockpit['verkauft_als']['anzahl'] !== null ? number_format((float) $cockpit['verkauft_als']['anzahl'], 1, ',', '.') : '?' }} {{ $cockpit['verkauft_als']['unit'] }}
                @if($cockpit['verkauft_als']['g_pro_einheit'] !== null) · ≈ {{ number_format($cockpit['verkauft_als']['g_pro_einheit'], 0, ',', '.') }} g pro {{ $cockpit['verkauft_als']['unit'] }}@endif
                @if($cockpit['verkauft_als']['yield_kg'] !== null) · Yield {{ number_format($cockpit['verkauft_als']['yield_kg'], 2, ',', '.') }} kg @endif
            </div>
        @endif

        {{-- GLANCE 1 — Preis & Marge: Cockpit-Card --}}
        @php($we = $cockpit['marge']['wareneinsatz_pct'] ?? null)
        @php($weTone = $we === null ? 'neutral' : ($we > 35 ? 'danger' : ($we > 30 ? 'warning' : 'success')))
        @php($weBadge = [
            'neutral' => ['bg-black/5', 'text-gray-500', 'bg-gray-400', 'kein Preis'],
            'success' => ['bg-emerald-500/15', 'text-emerald-700', 'bg-emerald-500', 'gesund'],
            'warning' => ['bg-amber-500/15', 'text-amber-700', 'bg-amber-500', 'knapp'],
            'danger' => ['bg-rose-500/10', 'text-rose-700', 'bg-rose-500', 'kritisch'],
        ][$weTone])
        @php($weValueText = ['neutral' => 'text-gray-500', 'success' => 'text-emerald-700', 'warning' => 'text-amber-700', 'danger' => 'text-rose-700'][$weTone])
        <div class="relative overflow-hidden {{ $card }} px-3.5 py-2.5" data-vk-kpis>
            <div class="{{ $cardAccent }}"></div>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <span class="text-[10px] font-medium uppercase tracking-wider text-violet-600">VK brutto</span>
                    <p class="text-2xl font-bold text-violet-700 leading-none mt-1" data-vk-brutto>{{ $cockpit['sales_gross'] !== null ? number_format($cockpit['sales_gross'], 2, ',', '.') . ' €' : '—' }}</p>
                </div>
                <span class="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-0.5 rounded-full {{ $weBadge[0] }} {{ $weBadge[1] }}">
                    <span class="w-1.5 h-1.5 rounded-full {{ $weBadge[2] }}"></span>{{ $weBadge[3] }}
                </span>
            </div>
            <div class="mt-3" data-wareneinsatz>
                <div class="flex items-center justify-between text-xs mb-1">
                    <span class="text-gray-500">Wareneinsatz</span>
                    <span class="{{ $weValueText }} font-medium tabular-nums">{{ $cockpit['marge'] !== null ? number_format($cockpit['marge']['wareneinsatz_pct'], 1, ',', '.') . ' %' : '—' }}</span>
                </div>
                <x-foodalchemist::meter :value="$we ?? 0" :max="50" :tone="$weTone" :ticks="[30, 35]" />
            </div>
            <div class="flex gap-5 mt-3 pt-2.5 border-t border-black/5 text-xs">
                <span class="text-gray-500">EK <span class="text-gray-900 font-medium tabular-nums">{{ $rezept->ek_total_eur !== null ? number_format((float) $rezept->ek_total_eur, 2, ',', '.') . ' €' : '—' }}</span></span>
                <span class="text-gray-500">VK netto{{ $cockpit['vk']['source'] === 'manuell' ? ' (man.)' : ($cockpit['vk']['source'] === 'class' ? ' (Klasse)' : '') }} <span class="text-gray-900 font-medium tabular-nums" data-vk-netto>{{ $cockpit['vk']['sales_net'] !== null ? number_format($cockpit['vk']['sales_net'], 2, ',', '.') . ' €' : '—' }}</span></span>
                @if($cockpit['pro_einheit'] !== null)
                    <span class="text-gray-500">/Einh. <span class="text-gray-900 tabular-nums">{{ number_format($cockpit['pro_einheit']['vk_brutto_pro_einheit'], 2, ',', '.') }} €</span></span>
                @endif
            </div>
        </div>

        {{-- Formel-Klartext (wie der VK zustande kommt) + Blocker-Streifen --}}
        @if($rezept->markupClass !== null && ! $cockpit['formel_fehlt'] && $cockpit['vk']['vorschlag'] !== null)
            <p class="text-xs text-gray-400" data-formel-klartext>{{ $rezept->markupClass->code }} · {{ $cockpit['vk']['vorschlag']['formel'] }}</p>
        @endif
        @if($cockpit['formel_fehlt'])
            <x-foodalchemist::alert tone="warning" data-formel-fehlt>⚠ Aufschlagsklasse {{ $rezept->markupClass?->code }}: Formel »deckungsbeitrag« nicht definiert (W-1) — Entscheid ausstehend, VK nur manuell.</x-foodalchemist::alert>
        @elseif($rezept->ek_total_eur === null)
            <x-foodalchemist::alert tone="warning" data-cockpit-leer>Kein EK berechnet — Zutaten ergänzen oder Lead-LAs setzen.</x-foodalchemist::alert>
        @elseif($rezept->markupClass === null)
            <x-foodalchemist::alert tone="warning" data-cockpit-leer>Keine Aufschlagsklasse gesetzt — VK-Vorschlag erst nach Klassifikation (M6-04).</x-foodalchemist::alert>
        @endif

        @if($rezept->description !== null)
            <p class="text-[13px] text-gray-600 leading-relaxed" data-vk-beschreibung>{{ $rezept->description }}</p>
        @endif

        {{-- Pairing-Netz — Inline-Graph (Detail) + Anker-Pflege --}}
        <x-foodalchemist::section title="Pairing-Netz" icon="heroicon-o-share"
            :meta="$kohaesion !== null ? 'Kohäsion ' . $kohaesion['score'] . ' · Coverage ' . $kohaesion['coverage_pct'] . ' %' : null" data-vk-kern-anker>
            <x-slot:actions>
                <button type="button" wire:click="$dispatch('pairing-netz.oeffnen', { recipeId: {{ $rezept->id }} })"
                        class="{{ $btnGhostXs }}" title="Voller Graph: verwandte Rezepte + Vorschläge" data-vk-pairing-netz>Netz öffnen @svg('heroicon-o-arrow-up-right', 'w-3.5 h-3.5')</button>
            </x-slot:actions>
            <x-foodalchemist::pairing-netz :recipe-id="$rezept->id" />
            <div class="flex flex-wrap gap-1 mt-2">
                @foreach($kernAnker as $anker)
                    <span wire:key="vka-{{ $anker->id }}" class="{{ $pill }} {{ $variantPill['primary'] }} group" title="{{ $anker->source }}{{ $anker->ai_confidence !== null ? ' ' . round($anker->ai_confidence * 100) . '%' : '' }}">
                        ★ {{ $anker->display_de }}
                        <button type="button" wire:click="ankerLoesen({{ $anker->id }})" class="hidden group-hover:inline text-rose-400 ml-0.5" title="lösen">✕</button>
                    </span>
                @endforeach
            </div>
            @if($fehlerAnker !== null)<p class="text-[11px] text-rose-500 mt-1" data-vk-anker-fehler>{{ $fehlerAnker }}</p>@endif
            <div class="relative mt-1.5">
                <input type="search" wire:model.live.debounce.300ms="ankerSuche" placeholder="Anker verknüpfen …" class="{{ $input }} !py-1" data-vk-anker-suche />
                @foreach($ankerKandidaten as $kandidat)
                    <button type="button" wire:key="vkak-{{ $kandidat->id }}" wire:click="ankerVerknuepfen({{ $kandidat->id }})"
                            class="block w-full text-left px-2 py-1 rounded text-xs text-gray-700 hover:bg-violet-500/10">{{ $kandidat->display_de }} <span class="text-gray-500">{{ $kandidat->slug }}</span></button>
                @endforeach
            </div>
        </x-foodalchemist::section>

        {{-- Komponenten — volle Liste --}}
        <x-foodalchemist::section title="Komponenten" icon="heroicon-o-list-bullet" :meta="$rezept->ingredients->count()" data-vk-zutaten>
            <div class="space-y-0.5">
                @foreach($rezept->ingredients as $z)
                    <div class="flex items-baseline gap-2 text-[13px] py-1 border-b border-black/5 last:border-0" wire:key="vkz-{{ $z->id }}">
                        <span class="text-gray-500 tabular-nums shrink-0 w-16 text-right">{{ $z->quantity !== null ? rtrim(rtrim(number_format((float) $z->quantity, 2, ',', '.'), '0'), ',') . ' ' . ($z->unit?->slug ?? '') : '' }}</span>
                        <span class="text-gray-900">{{ $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name }}</span>
                    </div>
                @endforeach
            </div>
        </x-foodalchemist::section>

        {{-- KI-Analyse — kompakt gesammelt, direkt unter Komponenten (Details via Prüfen / im Pairing-Netz) --}}
        <x-foodalchemist::section title="KI-Analyse" icon="heroicon-o-sparkles" data-vk-ki-analyse>
            @php($urteil = $kohaerenzStatus['cache'] ?? null)
            @php($heberJson = $urteil?->heber_json)
            <div class="space-y-2 text-[13px]">
                <div class="flex items-center gap-2 flex-wrap" data-vk-kohaerenz>
                    <span class="text-gray-500">Kulinarische Kohärenz</span>
                    @if($urteil?->score !== null)
                        <span class="font-semibold {{ $urteil->score >= 80 ? 'text-emerald-700' : ($urteil->score >= 50 ? 'text-amber-700' : 'text-rose-700') }}">{{ $urteil->score }} %</span>
                        @if($urteil->label !== null)<span class="text-gray-500">{{ $urteil->label }}</span>@endif
                        @if($kohaerenzStatus['stale'] ?? false)<span class="text-[11px] text-amber-600" data-vk-kohaerenz-stale>· veraltet</span>@endif
                    @else
                        <span class="text-gray-400">noch kein Urteil</span>
                    @endif
                    <button type="button" wire:click="pruefeKohaerenz" class="{{ $btnGhostXs }} ml-auto" data-vk-kohaerenz-pruefen>{{ $urteil?->score !== null ? 'Erneut prüfen' : '✨ Prüfen' }}</button>
                </div>
                @if($urteil?->reasoning !== null)<p class="text-xs text-gray-500 leading-relaxed">{{ $urteil->reasoning }}</p>@endif
                @if($urteil?->score !== null)<p class="text-[11px] text-gray-400">{{ $urteil->judged_at?->format('Y-m-d') }} · {{ $urteil->judge_model }}</p>@endif
                <div class="flex items-center gap-2 border-t border-black/5 pt-2" data-vk-heber>
                    <span class="text-gray-500">Was hebt den Teller?</span>
                    @if(($heberJson['vorschlaege'] ?? []) !== [])
                        <span class="text-gray-700">{{ count($heberJson['vorschlaege']) }} Ideen</span>
                    @else
                        <span class="text-gray-400">noch keine</span>
                    @endif
                    <button type="button" wire:click="schlageHeberVor" class="{{ $btnGhostXs }} ml-auto" data-vk-heber-vorschlagen>{{ ($heberJson['vorschlaege'] ?? []) !== [] ? 'Erneut' : '✨ Vorschlagen' }}</button>
                </div>
                @if(($heberJson['einschaetzung'] ?? null) !== null)<p class="text-xs text-gray-500 leading-relaxed">{{ $heberJson['einschaetzung'] }}</p>@endif
                <p class="text-gray-500 border-t border-black/5 pt-2" data-vk-nachbarn>Aroma-Nachbarn <span class="text-gray-400">— aromaverwandte Zutaten sind im Pairing-Netz oben sichtbar.</span></p>
            </div>
        </x-foodalchemist::section>

        {{-- Eignung — Sektor/Niveau pflegen --}}
        <x-foodalchemist::section title="Eignung" icon="heroicon-o-user-group"
            :meta="$sektorEignungen->count() + $niveauEignungen->count()" data-vk-eignung>
            <x-slot:actions>
                <button type="button" wire:click="kiEignung" class="{{ $btnGhostXs }} text-violet-600" title="recipe.sektor + recipe.level — nur «geeignet»-Urteile" data-ki-eignung>✨ Eignung</button>
            </x-slot:actions>
            @if($eignungVorschlag !== null)
                <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 text-xs mb-1.5" data-eignung-vorschlag>
                    <p class="text-[11px] text-gray-600">✨ geeignet für:
                        @foreach($eignungVorschlag['slugs'] as $slug => $typ)<span class="{{ $pill }} {{ $variantPill['info'] }} ml-1">{{ $typ }}: {{ $slug }}</span>@endforeach
                    </p>
                    <div class="flex gap-1.5 mt-1.5">
                        <button type="button" wire:click="eignungUebernehmen" class="{{ $btnGhostXs }} text-emerald-600" data-eignung-uebernehmen>Übernehmen ({{ round($eignungVorschlag['confidence'] * 100) }} %)</button>
                        <button type="button" wire:click="eignungVerwerfen" class="{{ $btnGhostXs }}">Verwerfen</button>
                    </div>
                </div>
            @endif
            @foreach(['sektor' => ['Sektor', $sektorEignungen, 'sector_slug'], 'level' => ['Niveau', $niveauEignungen, 'level_slug']] as $typ => [$lbl, $eignungen, $slugSpalte])
                <div class="flex items-center gap-1.5 flex-wrap py-1" data-eignung-zeile="{{ $typ }}">
                    <span class="text-xs text-gray-500 w-12 shrink-0">{{ $lbl }}</span>
                    @forelse($eignungen as $e)
                        <span wire:key="eig-{{ $typ }}-{{ $e->id }}" class="{{ $pill }} {{ $variantPill[$typ === 'sektor' ? 'secondary' : 'info'] }} group" title="{{ $e->source }}{{ $e->ai_confidence !== null ? ' · ' . round($e->ai_confidence * 100) . ' %' : '' }}">
                            {{ $e->{$slugSpalte} }}
                            <button type="button" wire:click="eignungEntfernen('{{ $typ }}', '{{ $e->{$slugSpalte} }}')" class="hidden group-hover:inline text-rose-400 ml-0.5" title="entfernen">✕</button>
                        </span>
                    @empty
                        <span class="text-xs text-gray-500 italic">— keine —</span>
                    @endforelse
                    <select wire:change="eignungSetzen('{{ $typ }}', $event.target.value)" class="{{ $input }} !py-0.5 !w-32 !text-[11px]" data-eignung-select="{{ $typ }}">
                        <option value="">+ manuell…</option>
                        @foreach($eignungVokabular[$typ]['slugs'] as $slug)
                            @if(!$eignungen->contains($slugSpalte, $slug))<option value="{{ $slug }}">{{ $slug }}</option>@endif
                        @endforeach
                    </select>
                </div>
            @endforeach
        </x-foodalchemist::section>

        {{-- Allergene & Diät — volle Deklaration (Diät · 14 Allergene · Zusatzstoffe) --}}
        <x-foodalchemist::section title="Allergene & Diät" icon="heroicon-o-beaker" :meta="'Konf. ' . strtoupper($rezept->allergens_confidence)">
            @include('foodalchemist::livewire.recipes.partials.deklaration')
        </x-foodalchemist::section>

    @endif
</div>
