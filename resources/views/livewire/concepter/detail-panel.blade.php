{{-- Concepter-Detail: Menü-Ökonomie-Linse für ein Concept ODER ein Paket (Kaskade 2026-08-24: Paket = kind=paket-Concept,
     ein Ladepfad, nur die Anzeige unterscheidet sich → $istPaket: Paketpreis statt Einzel-VK, keine Menü-Bewertung).
     fa-pass Welle 2 (2026-10-05): auf Bausteine <x-fa::…> umgestellt. Funktion, wire:-Bindungen, Events und data-Marker
     unverändert. Neu: Hauptaktion „Im Editor öffnen" oben, offene Punkte (leere Positionen, veralteter Paketpreis,
     fehlender Einkauf) direkt unter dem Preis, Löschen getrennt am Ende. --}}
@php
    $statusLabel = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiv'];
    $statusTon = ['draft' => 'neutral', 'active' => 'ok', 'archiviert' => 'neutral'];
    $niveauLabel = ['klassisch' => 'Klassisch', 'gehoben' => 'Gehoben', 'haute' => 'Haute Cuisine'];
    $konfTon = ['high' => 'ok', 'medium' => 'warn', 'low' => 'crit', 'unknown' => 'neutral'];
    $konfSatz = [
        'high' => 'Allergene vollständig belegt',
        'medium' => 'Allergene teilweise belegt, stichprobenartig prüfen',
        'low' => 'Allergene unsicher, vor Ausgabe prüfen',
        'unknown' => 'Allergene noch nicht bewertet',
    ];
    $wort = $istPaket ? 'Paket' : 'Concept';

    if ($concept !== null) {
        $proPerson = $cockpit['price_per_person'] ?? null;
        $score = $bewertung['score'] ?? null;
        $scoreTone = $score === null ? 'neutral' : ($score >= 80 ? 'success' : ($score >= 50 ? 'warning' : 'danger'));
        $scoreBadgeTon = ['neutral' => 'neutral', 'success' => 'ok', 'warning' => 'warn', 'danger' => 'crit'][$scoreTone];
        $hatGerichte = $aggregat !== null && ($aggregat['n_gerichte'] ?? 0) > 0;
        $konfidenz = $aggregat['allergene']['confidence'] ?? 'unknown';

        $kpis = [
            $proPerson !== null
                ? ['label' => $istPaket ? 'Paketpreis / Person' : '€/Person', 'value' => number_format((float) $proPerson, 2, ',', '.') . ' €', 'primary' => true, 'kpi' => 'preis']
                : ['label' => $istPaket ? 'Paketpreis / Person' : '€/Person', 'value' => 'Preis fehlt', 'tone' => 'crit', 'kpi' => 'preis'],
            ['label' => 'EK/Person', 'value' => $aggregat !== null ? number_format((float) $aggregat['ek_per_person'], 2, ',', '.') . ' €' : '–', 'kpi' => 'ek'],
            ['label' => 'Arbeitszeit', 'value' => $aggregat !== null ? $aggregat['work_time_min'] . ' min' : '–', 'kpi' => 'arbeitszeit'],
        ];

        // Offene Punkte zuerst: was vor dem Angebot noch fehlt.
        $offen = [];
        if (! empty($cockpit['hat_leer'])) {
            $offen[] = ['crit', 'Mindestens eine Position ist noch leer.'];
        }
        if (! empty($cockpit['hat_stale'])) {
            $offen[] = ['warn', 'Ein Paketpreis ist veraltet, im Editor neu berechnen.'];
        }
        if (! empty($cockpit['hat_ek_luecke'])) {
            $offen[] = ['warn', 'Einkauf unvollständig: einem Gericht fehlt das Portionsgewicht.'];
        }

        $checkIcon = ['ok' => 'heroicon-m-check-circle', 'warn' => 'heroicon-m-exclamation-triangle', 'fail' => 'heroicon-m-x-circle', 'info' => 'heroicon-m-information-circle'];
        $checkFarbe = ['ok' => 'text-[var(--fa-ok)]', 'warn' => 'text-[var(--fa-warn)]', 'fail' => 'text-[var(--fa-crit)]', 'info' => 'text-[var(--fa-ink-3)]'];
        $naehrwertFelder = ['kcal' => 'kcal', 'protein_g' => 'Eiweiß', 'fett_g' => 'Fett', 'gesfett_g' => 'davon gesättigt', 'kh_g' => 'Kohlenhydrate', 'zucker_g' => 'davon Zucker', 'salz_g' => 'Salz'];
    }
@endphp

<div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]" data-concepter-panel>
    @if($concept === null)
        <x-fa::empty icon="heroicon-o-square-3-stack-3d" title="{{ $istPaket || $type === 'pakete' ? 'Kein Paket gewählt' : 'Kein Concept gewählt' }}">
            Links in der Tabelle eine Zeile anklicken, dann erscheinen hier Preis, Aufbau und Bewertung.
        </x-fa::empty>
    @else
        {{-- Kopf (Anatomie Detail-Panels): Name, Einordnung, eine Hauptaktion, Weiteres im Menü --}}
        <x-fa::detail-kopf :title="$concept->name" :subtitle="$concept->consumer_name ? 'Für Gäste: ' . $concept->consumer_name : null">
            <x-slot:badges>
                <x-fa::badge :tone="$statusTon[$concept->status] ?? 'neutral'">{{ $statusLabel[$concept->status] ?? $concept->status }}</x-fa::badge>
                @if(! $istPaket && $concept->is_template)<x-fa::badge tone="info" icon="heroicon-m-square-2-stack">Vorlage</x-fa::badge>@endif
                @if($istPaket)<x-fa::badge tone="info" icon="heroicon-m-puzzle-piece">Paket</x-fa::badge>@endif
                @if($concept->class)<x-fa::badge>{{ $concept->class }}</x-fa::badge>@endif
                @if($concept->level)<x-fa::badge title="Niveau">{{ $niveauLabel[$concept->level] ?? $concept->level }}</x-fa::badge>@endif
                @if(! $istPaket && $concept->occasion)<x-fa::badge title="Anlass">{{ $concept->occasion }}</x-fa::badge>@endif
                @if($istPaket)<x-fa::badge>{{ $concept->price_mode === 'auto' ? 'Preis aus den Gerichten' : 'Preis fixiert' }}</x-fa::badge>@endif
            </x-slot:badges>
            <x-slot:aktion>
                <div class="flex flex-wrap items-center gap-2">
                    <x-foodalchemist::bearbeiten-leiste :zustand="$sperr" sofort />
                    <x-fa::button variant="ghost" size="sm" icon="heroicon-m-arrow-top-right-on-square"
                        wire:click="$dispatch('concepter-editor.oeffnen', { type: 'concepts', id: {{ $concept->id }} })">Im Editor öffnen</x-fa::button>
                </div>
            </x-slot:aktion>
            <x-slot:menue>
                {{-- #6: „Karte drucken" (schöne Kunden-Ausgabe) + „Report" (technisch). Karte gilt auch fürs Paket. --}}
                <x-fa::menu-item icon="heroicon-m-printer" :href="route('foodalchemist.concepts.karte', ['id' => $concept->id])" target="_blank"
                    title="Menü-Karte für den Kunden, zum Drucken oder als PDF" data-concepter-panel-karte>Karte drucken</x-fa::menu-item>
                <x-fa::menu-item icon="heroicon-m-document-text" :href="route('foodalchemist.concepts.dokument', ['id' => $concept->id, 'profil' => 'voll'])" target="_blank"
                    title="Technischer Report mit allen Gerichten, Basisrezepten und Produkten" data-concepter-panel-druck>Report öffnen</x-fa::menu-item>
                @unless($istPaket)
                    @if($concept->is_template)
                        <x-fa::menu-item icon="heroicon-m-document-duplicate" wire:click="ausVorlage">Concept aus Vorlage anlegen</x-fa::menu-item>
                    @else
                        <x-fa::menu-item icon="heroicon-m-square-2-stack" wire:click="alsVorlage">Als Vorlage speichern</x-fa::menu-item>
                    @endif
                @endunless
                <x-fa::menu-item icon="heroicon-m-document-duplicate" wire:click="dupliziere">{{ $wort }} duplizieren</x-fa::menu-item>
                <x-fa::menu-item danger icon="heroicon-m-trash" wire:click="loeschen" wire:confirm="{{ $istPaket ? 'Paket löschen?' : 'Concept löschen?' }}">{{ $wort }} löschen</x-fa::menu-item>
            </x-slot:menue>
        </x-fa::detail-kopf>

        {{-- Spec 65: Detailspalte — Änderungen erst nach „Bearbeiten" (gleiche Sperre wie der Concepter-Editor), „Fertig" gibt frei --}}
        <fieldset @disabled(in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true)) class="contents" data-fa-lesemodus="{{ in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true) ? '1' : '0' }}">

        {{-- Cockpit (Menü-Ökonomie): €/Person ist die Hauptzahl, Score für echte Concepts --}}
        <div class="flex flex-col gap-3" data-concepter-cockpit>
            <x-fa::kpis :items="$kpis" />
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">
                {{ $aggregat['n_gerichte'] ?? 0 }} {{ ($aggregat['n_gerichte'] ?? 0) === 1 ? 'Gericht' : 'Gerichte' }} in {{ $aggregat['n_slots'] ?? 0 }} {{ ($aggregat['n_slots'] ?? 0) === 1 ? 'Position' : 'Positionen' }}
            </p>
            @if($offen !== [])
                <div class="flex flex-col gap-1">
                    @foreach($offen as [$ton, $text])
                        <x-fa::signal :tone="$ton">{{ $text }}</x-fa::signal>
                    @endforeach
                </div>
            @endif
            @if(! $istPaket && $score !== null)
                <div class="flex items-center gap-3" title="Menü-Bewertung: Anteil bestandener Prüfungen">
                    <x-fa::badge :tone="$scoreBadgeTon">Bewertung {{ $score }}</x-fa::badge>
                    <x-foodalchemist::meter :value="$score" :max="100" :tone="$scoreTone" :ticks="[50, 80]" class="flex-1" />
                </div>
            @endif
        </div>

        @if($hatGerichte)
            {{-- Allergen-/Diät-Rollup (schwächstes Gericht bestimmt die Konfidenz) --}}
            <div class="flex flex-col gap-2" data-concepter-rollup>
                <x-fa::signal :tone="($konfTon[$konfidenz] ?? 'neutral') === 'neutral' ? 'warn' : $konfTon[$konfidenz]" title="Konfidenz: {{ \Platform\FoodAlchemist\Support\Labels::konfidenz($konfidenz) }}">{{ $konfSatz[$konfidenz] ?? $konfidenz }}</x-fa::signal>
                <div class="flex flex-wrap gap-1.5">
                    @if($aggregat['allergene']['is_vegan'])<x-fa::badge tone="ok" icon="heroicon-m-check">vegan</x-fa::badge>
                    @elseif($aggregat['allergene']['is_vegetarian'])<x-fa::badge tone="ok" icon="heroicon-m-check">vegetarisch</x-fa::badge>@endif
                    @if($aggregat['allergene']['is_gluten_free'])<x-fa::badge tone="ok" icon="heroicon-m-check">glutenfrei</x-fa::badge>@endif
                    @if($aggregat['allergene']['is_lactose_free'])<x-fa::badge tone="ok" icon="heroicon-m-check">laktosefrei</x-fa::badge>@endif
                    @if($aggregat['allergene']['is_halal'])<x-fa::badge tone="ok" icon="heroicon-m-check">halal</x-fa::badge>@endif
                    @if($aggregat['allergene']['contains_pork'])<x-fa::badge tone="warn">enthält Schwein</x-fa::badge>@endif
                    @if($aggregat['allergene']['contains_beef'])<x-fa::badge tone="warn">enthält Rind</x-fa::badge>@endif
                </div>
            </div>
        @endif

        <div class="flex flex-col">
            {{-- Aufbau: Menü-Struktur. Paket: Positionen ohne Einzel-VK (Preis liegt im Paket). --}}
            @if($cockpit)
                <x-fa::section variant="plain" title="{{ $istPaket ? 'Positionen im Paket' : 'Aufbau' }}" icon="heroicon-o-list-bullet" :meta="count($cockpit['zeilen'])">
                    @if(count($cockpit['zeilen']) === 0)
                        <x-fa::empty compact icon="heroicon-o-list-bullet" title="Noch keine Positionen">Im Editor Gänge anlegen und mit Paketen oder Gerichten füllen.</x-fa::empty>
                    @else
                        <ul class="flex flex-col">
                            @foreach($cockpit['zeilen'] as $z)
                                <li class="flex items-center justify-between gap-3 py-1.5 border-b border-[var(--fa-line)] last:border-0">
                                    <span class="min-w-0">
                                        <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $z['role'] ?: 'Ohne Rolle' }}</span>
                                        <span class="flex flex-wrap items-center gap-1.5 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                                            <span class="min-w-0 break-words">{{ $z['label'] }}</span>
                                            @if($z['type'] === 'paket')<x-fa::badge tone="info">Paket</x-fa::badge>@elseif($z['type'] === 'leer')<x-fa::badge tone="crit">leer</x-fa::badge>@endif
                                        </span>
                                    </span>
                                    @unless($istPaket || $z['type'] === 'leer')
                                        <x-fa::money :value="$z['price']" class="shrink-0 text-[length:var(--fa-text-md)]" />
                                    @endunless
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-fa::section>
            @endif

            {{-- Menü-Bewertung (deterministisch §10.8) — nur echte Concepts, nicht das Bündel --}}
            @if(! $istPaket && $bewertung)
                <x-fa::section variant="plain" title="Menü-Bewertung" icon="heroicon-o-clipboard-document-check">
                    <x-slot:actions>
                        <x-fa::badge :tone="$scoreBadgeTon" title="Anteil bestandener Prüfungen">Bewertung {{ $bewertung['score'] }}</x-fa::badge>
                    </x-slot:actions>
                    <ul class="flex flex-col gap-1.5">
                        @foreach($bewertung['checks'] as $c)
                            <li class="flex items-start gap-2 text-[length:var(--fa-text-md)]">
                                @svg($checkIcon[$c['status']] ?? 'heroicon-m-minus', 'w-4 h-4 shrink-0 mt-0.5 ' . ($checkFarbe[$c['status']] ?? 'text-[var(--fa-ink-3)]'))
                                <span class="text-[var(--fa-ink-2)]"><span class="font-medium text-[var(--fa-ink)]">{{ $c['label'] }}:</span> {{ $c['detail'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-fa::section>
            @endif

            {{-- Nährwerte / Person --}}
            @if($hatGerichte)
                <x-fa::section variant="plain" title="Nährwerte / Person" icon="heroicon-o-chart-bar">
                    <x-slot:actions>
                        <x-fa::badge :tone="$konfTon[$aggregat['naehrwerte']['confidence']] ?? 'neutral'" title="Konfidenz der Nährwerte">{{ \Platform\FoodAlchemist\Support\Labels::konfidenz($aggregat['naehrwerte']['confidence']) }}</x-fa::badge>
                    </x-slot:actions>
                    @if($aggregat['naehrwerte']['kcal'] !== null)
                        <dl class="grid grid-cols-2 gap-x-4">
                            @foreach($naehrwertFelder as $k => $lbl)
                                <div class="flex items-baseline justify-between gap-2 py-1 border-b border-[var(--fa-line)] {{ $k === 'kcal' ? 'col-span-2' : '' }}">
                                    <dt class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $lbl }}</dt>
                                    <dd class="text-[length:var(--fa-text-md)] font-semibold tabular-nums text-[var(--fa-ink)]">
                                        @if($aggregat['naehrwerte'][$k] !== null && $k === 'kcal')
                                            <span class="tabular-nums">{{ number_format((float) $aggregat['naehrwerte'][$k], 0, ',', '.') }}</span>
                                        @elseif($aggregat['naehrwerte'][$k] !== null)
                                            <x-fa::menge :value="$aggregat['naehrwerte'][$k]" :decimals="1" unit="g" />
                                        @else
                                            <span class="font-normal text-[var(--fa-ink-3)]">fehlt</span>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                    @unless($aggregat['naehrwerte']['vollstaendig'])
                        <x-fa::signal tone="warn">{{ $aggregat['naehrwerte']['n_mit_naehrwerten'] }} von {{ $aggregat['naehrwerte']['n_gerichte'] }} Gerichten mit Nährwert und Portionsgewicht, der Rest fehlt noch.</x-fa::signal>
                    @endunless
                </x-fa::section>
            @endif

            {{-- Menü-Karte (Konsumenten-Sicht · C-10) --}}
            <x-fa::section variant="plain" title="{{ $istPaket ? 'Paket-Inhalt' : 'Menü-Karte' }}" icon="heroicon-o-document-text" meta="So sieht es der Gast">
                <div class="fa-surface px-3 py-2.5 flex flex-col gap-1.5">
                    <p class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">{{ $concept->consumer_name ?: $concept->name }}</p>
                    @if($concept->additional_text)<p class="text-[length:var(--fa-text-sm)] italic text-[var(--fa-ink-2)]">{{ $concept->additional_text }}</p>@endif
                    @forelse($concept->slots as $slot)
                        <div>
                            <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $slot->role ?: 'Ohne Rolle' }}{{ $slot->is_pflicht ? '' : ' · optional' }}</span>
                            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $slot->title ?: ($slot->embeddedConcept?->name ?? $slot->package?->name ?? $slot->dish?->name ?? 'noch leer') }}</p>
                        </div>
                    @empty
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Noch keine Positionen.</p>
                    @endforelse
                </div>
            </x-fa::section>

            {{-- Wo verwendet? Concept → Foodbooks; Paket → Concepts (eingebettet) --}}
            <x-fa::section variant="plain" title="Wo verwendet?" icon="heroicon-o-link" :meta="$verwendung->count()">
                @forelse($verwendung as $v)
                    <div class="flex items-center justify-between gap-3 py-1 text-[length:var(--fa-text-md)]">
                        <span class="min-w-0 break-words text-[var(--fa-ink)]">{{ $istPaket ? $v->name : ($v->label ?? '–') }}</span>
                        <span class="shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $istPaket ? 'Concept' : ('Foodbook' . ($v->jahr ? ' ' . $v->jahr : '') . ($v->customer ? ' · ' . $v->customer : '')) }}</span>
                    </div>
                @empty
                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $istPaket ? 'In keinem Concept eingesetzt.' : 'In keinem Foodbook verwendet.' }}</p>
                @endforelse
            </x-fa::section>

        </div>
        </fieldset>
    @endif
</div>
