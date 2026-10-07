{{-- M-Wissen: Wissens-Browser. Liste links, Text in der Mitte, „wofür wird dieses Wissen genutzt"
     im rechten Panel (Spec 28 / E15).
     fa-pass 2026-10-05: auf Bausteine <x-fa::…> umgestellt. Grundanordnung unverändert (Liste links,
     Text Mitte, Verwendung rechts). Neu: Aktivieren als eigener, sichtbarer Knopf am Dokument
     (Aktivieren ist Kuration), Löschen im Menü „Weitere Aktionen", Status-Filter als Chips,
     Arbeitsschritte und Kategorien als lesbare Namen statt Schlüssel, Markdown-Typografie über
     Tokens statt eigenem Style-Block. Funktion, wire:-Bindungen und data-Marker unverändert. --}}
@php
    $katLabel = collect($kategorien)->pluck('label', 'slug')->all();

    // Arbeitsschritt-Schlüssel (z. B. recipe.generator) → lesbarer Name. Nur Anzeige; der Wert bleibt der Schlüssel.
    $bereichLabel = [
        'recipe' => 'Rezept', 'gp' => 'Grundprodukt', 'vk' => 'Gericht', 'concept' => 'Konzept',
        'foodbook' => 'Foodbook', 'format' => 'Format', 'planung' => 'Planung', 'planning' => 'Planung',
        'signal' => 'Signale', 'trend' => 'Trends', 'conformance' => 'Rezeptprüfung', 'price' => 'Preis',
        'chat' => 'Assistent', 'demo' => 'Test', 'component' => 'Komponente', 'praesentation' => 'Präsentation',
    ];
    $schrittLabel = [
        'generator' => 'erstellen', 'description' => 'Beschreibung', 'category' => 'Kategorie', 'steps' => 'Arbeitsschritte',
        'wording' => 'Texte', 'review' => 'prüfen', 'ueberarbeiten' => 'überarbeiten', 'check' => 'prüfen', 'plan' => 'planen',
        'marketing' => 'Werbetext', 'plating' => 'Anrichten', 'pairing' => 'Pairing', 'allergene' => 'Allergene',
        'naehrwerte' => 'Nährwerte', 'product_photo' => 'Produktfoto', 'step_photos' => 'Schrittfotos',
        'titel_vorschlag' => 'Titelvorschlag', 'regeneration' => 'Regenerieren', 'sensorik' => 'Sensorik',
        'geschmack' => 'Geschmack', 'leitplanken' => 'Leitplanken', 'suggest' => 'vorschlagen', 'condition' => 'Zustand',
        'garverlust' => 'Garverlust', 'name_putzen' => 'Name bereinigen', 'kundentext' => 'Kundentext',
        'grundgeruest' => 'Grundgerüst', 'brief_geruest' => 'Briefing-Gerüst', 'kapitel_ideen' => 'Kapitelideen',
        'message' => 'Nachricht', 'plausi' => 'Plausibilität', 'cluster_label' => 'Gruppen benennen',
        'equipment' => 'Geräte', 'extract' => 'einlesen', 'anker' => 'Aroma-Anker', 'tags' => 'Merkmale',
    ];
    $featureLabel = [
        'ai_generate_recipe' => 'Rezept erstellen', 'ai_suggest_pairings' => 'Pairing vorschlagen',
        'ai_infer_ankers' => 'Aroma-Anker ableiten', 'ai_extract_recipe' => 'Rezept einlesen', 'ai_plan_dishes' => 'Gerichte planen',
    ];
    $lesbar = fn (string $s): string => ucfirst(str_replace('_', ' ', $s));
    $arbeitsschritt = function (string $key) use ($bereichLabel, $schrittLabel, $featureLabel, $lesbar): string {
        if (isset($featureLabel[$key])) {
            return $featureLabel[$key];
        }
        if (! str_contains($key, '.')) {
            return $lesbar($key);
        }
        [$bereich, $schritt] = explode('.', $key, 2);

        return ($bereichLabel[$bereich] ?? $lesbar($bereich)) . ': ' . ($schrittLabel[$schritt] ?? str_replace('_', ' ', $schritt));
    };
    $kanonModusLabel = ['pflicht' => 'immer mitgeben', 'wenn_platz' => 'wenn Platz ist'];
    $routingModusLabel = ['always' => 'fest geladen', 'discovery' => 'bei passendem Thema'];

    $docZahl = $docs->count();
    $untertitel = number_format($docZahl, 0, ',', '.') . ' ' . ($docZahl === 1 ? 'Dokument' : 'Dokumente')
        . (trim($search) !== '' ? ' · nach Relevanz sortiert' : '');

    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $titelKlein = 'text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]';
    $chip = 'inline-flex items-center gap-1 min-h-[24px] pl-2 pr-1 py-0.5 rounded-full bg-[var(--fa-neutral-soft)] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] break-all';

    // Markdown-Typografie über Tokens (das Typography-Plugin ist nicht eingebunden; rohe <h1>/<ul>
    // sehen im Tailwind-Reset sonst aus wie Fließtext). Ersetzt den früheren Style-Block.
    $prose = 'text-[length:var(--fa-text-base)] leading-relaxed text-[var(--fa-ink)]'
        . ' [&_h1]:text-[length:var(--fa-text-2xl)] [&_h1]:font-semibold [&_h1]:mt-5 [&_h1]:mb-2'
        . ' [&_h2]:text-[length:var(--fa-text-lg)] [&_h2]:font-semibold [&_h2]:mt-5 [&_h2]:mb-1.5'
        . ' [&_h3]:text-[length:var(--fa-text-base)] [&_h3]:font-semibold [&_h3]:mt-4 [&_h3]:mb-1 [&_h3]:text-[var(--fa-ink-2)]'
        . ' [&_p]:my-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:my-2 [&_ol]:list-decimal [&_ol]:pl-6 [&_ol]:my-2 [&_li]:my-0.5'
        . ' [&_code]:font-mono [&_code]:text-[length:var(--fa-text-sm)] [&_code]:bg-[var(--fa-neutral-soft)] [&_code]:px-1 [&_code]:rounded'
        . ' [&_pre]:bg-[var(--fa-neutral-soft)] [&_pre]:p-3 [&_pre]:rounded-[var(--fa-radius-control)] [&_pre]:overflow-x-auto [&_pre]:my-3'
        . ' [&_blockquote]:border-l-[3px] [&_blockquote]:border-[var(--fa-accent-line)] [&_blockquote]:pl-3 [&_blockquote]:text-[var(--fa-ink-2)] [&_blockquote]:my-3'
        . ' [&_table]:w-full [&_table]:my-3 [&_table]:text-[length:var(--fa-text-md)] [&_th]:text-left [&_th]:font-semibold [&_th]:px-2 [&_th]:py-1 [&_th]:border-b [&_th]:border-[var(--fa-line-strong)]'
        . ' [&_td]:px-2 [&_td]:py-1 [&_td]:align-top [&_td]:border-t [&_td]:border-[var(--fa-line)]'
        . ' [&_a]:text-[var(--fa-accent)] [&_a]:underline [&_hr]:my-5 [&_hr]:border-[var(--fa-line)]';
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Wissen" icon="heroicon-o-academic-cap" />
    </x-slot:navbar>

    {{-- LINKS: Suche, Filter, Dokumentliste --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Dokumente" width="w-80">
            <div class="p-3 flex flex-col gap-3" data-wissen-liste>
                {{-- Suche: der Platzhalter sagt, WAS gesucht wird; er schaltet mit „Bedeutung einbeziehen" mit. --}}
                <div class="relative">
                    <label for="wissen-suche" class="sr-only">Wissen durchsuchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="wissen-suche" type="search" wire:model.live.debounce.300ms="search" class="pl-8" data-wissen-suche
                        placeholder="{{ $semantic ? 'Text, Bedeutung oder Begriff' : 'Titel oder Suchbegriff' }}" />
                </div>

                <label class="flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] cursor-pointer"
                       title="Findet auch bedeutungsähnliche Dokumente ohne wörtliche Übereinstimmung">
                    <input type="checkbox" wire:model.live="semantic" class="accent-[var(--fa-accent)]" data-wissen-semantik /> Bedeutung einbeziehen
                </label>

                @if($semanticNote !== null)
                    <x-fa::signal tone="warn" data-wissen-semantik-hinweis>{{ $semanticNote }}</x-fa::signal>
                @endif

                <x-fa::field label="Kategorie" for="wissen-kategorie">
                    <x-fa::select id="wissen-kategorie" wire:model.live="filterCategory" size="sm" data-wissen-filter-kategorie>
                        <option value="">Alle Kategorien</option>
                        @foreach($kategorien as $kat)
                            <option value="{{ $kat->slug }}">{{ $kat->label }}</option>
                        @endforeach
                    </x-fa::select>
                </x-fa::field>

                <x-fa::choice name="filterStatus" label="Status" idPrefix="wissen"
                    :options="['all' => 'Alle', 'active' => 'Aktiv', 'inactive' => 'Inaktiv']" data-wissen-filter-status />

                <p class="pt-2 border-t border-[var(--fa-line)] {{ $leise }} tabular-nums" data-wissen-anzahl>{{ $untertitel }}</p>

                <div class="flex flex-col gap-0.5 max-h-[58vh] overflow-y-auto -mx-1 px-1">
                    @forelse($docs as $doc)
                        @php
                            $aktivDoc = $selected && $selected->id === $doc->id;
                        @endphp
                        <button type="button" wire:click="select({{ $doc->id }})" wire:key="doc-{{ $doc->id }}"
                                @if($aktivDoc) aria-current="true" @endif
                                class="w-full text-left px-2.5 py-2 rounded-[var(--fa-radius-control)] transition-colors duration-150 {{ $aktivDoc ? 'bg-[var(--fa-accent-soft)]' : 'hover:bg-[var(--fa-hover)]' }}"
                                data-wissen-doc="{{ $doc->id }}">
                            <span class="block text-[length:var(--fa-text-md)] font-medium break-words {{ $aktivDoc ? 'text-[var(--fa-accent)]' : ($doc->active ? 'text-[var(--fa-ink)]' : 'text-[var(--fa-ink-2)]') }}">{{ $doc->title }}</span>
                            <span class="mt-1 flex flex-wrap items-center gap-1.5">
                                <span class="{{ $leise }}">{{ $katLabel[$doc->category] ?? $lesbar((string) $doc->category) }}</span>
                                <span class="{{ $leise }} tabular-nums">· {{ number_format($doc->char_count, 0, ',', '.') }} Zeichen</span>
                                @unless($doc->active)<x-fa::badge tone="warn">Inaktiv</x-fa::badge>@endunless
                            </span>
                        </button>
                    @empty
                        <x-fa::empty compact icon="heroicon-o-magnifying-glass" title="Keine Treffer">Suche oder Filter lockern.</x-fa::empty>
                    @endforelse
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: wofür wird dieses Wissen genutzt --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-96" :maxWidth="760" scope="activity_knowledge" side="right">
            {{-- Anatomie Detail-Panels (DESIGN.md): Kopf, Kennzahlen, offene Punkte, Einordnung, Suchbegriffe,
                 Wo es wirkt; danach die beiden Werkzeuge (Rückwärts nachsehen, Wissensauswahl testen). --}}
            @php
                if ($selected) {
                    $artWert = (string) ($form['art'] ?? '');
                    $artVoll = \Platform\FoodAlchemist\Services\Knowledge\Wissensart::LABELS[$artWert] ?? null;
                    $kanonZahl = count($kanonZeilen);
                    $wissenKpis = [
                        ['label' => 'Umfang', 'value' => number_format($selected->char_count, 0, ',', '.') . ' Zeichen', 'primary' => true, 'kpi' => 'zeichen'],
                        ['label' => 'Version', 'value' => (string) $selected->version, 'title' => 'Kennung: ' . $selected->slug, 'kpi' => 'version'],
                        $kanonZahl > 0
                            ? ['label' => 'Verbindlich in', 'value' => $kanonZahl . ' ' . ($kanonZahl === 1 ? 'Schritt' : 'Schritten'), 'kpi' => 'kanon']
                            : ['label' => 'Verbindlich in', 'value' => 'keinem', 'tone' => 'warn', 'title' => 'In keinem Kanon, nur über die Suche erreichbar', 'kpi' => 'kanon'],
                    ];
                }
            @endphp
            <div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]" data-wissen-panel>
                @if($selected)
                    {{-- Kopf: Titel, Kategorie, Zustand. Hauptaktion speichert Einordnung und Aktiv-Häkchen
                         (beides wirkt erst mit „Speichern“, wie der Knopf über dem Text). --}}
                    <x-fa::detail-kopf :title="$selected->title" :subtitle="$katLabel[$selected->category] ?? $lesbar((string) $selected->category)">
                        <x-slot:badges>
                            @if($selected->active)
                                <x-fa::badge tone="ok" icon="heroicon-m-check-circle">Aktiv</x-fa::badge>
                            @else
                                <x-fa::badge tone="warn" icon="heroicon-m-pause-circle">Inaktiv</x-fa::badge>
                            @endif
                            @if($artVoll)
                                <x-fa::badge tone="info" title="Wissensart: {{ $artVoll }}">{{ \Illuminate\Support\Str::before($artVoll, ' (') }}</x-fa::badge>
                            @endif
                        </x-slot:badges>
                        <x-slot:aktion>
                            <x-fa::button variant="primary" size="sm" icon="heroicon-m-check" wire:click="save">Einordnung speichern</x-fa::button>
                        </x-slot:aktion>
                        {{-- Aktivieren und Löschen nur in der Mitte am Dokument, nicht doppelt im Panel (Dominique 2026-10-05). --}}
                    </x-fa::detail-kopf>

                    <x-fa::kpis :items="$wissenKpis" />

                    {{-- Offene Punkte: was vor der Nutzung durch die KI fehlt --}}
                    @if(! $selected->active || $artWert === '' || $dossierHinweis)
                        <div class="flex flex-col gap-1">
                            @unless($selected->active)
                                <x-fa::signal tone="warn">Inaktiv: die KI nutzt dieses Wissen nicht. Freigeben über das Häkchen „Aktiv“ oder den Knopf „Aktivieren“.</x-fa::signal>
                            @endunless
                            @if($artWert === '')
                                <x-fa::signal tone="warn">Wissensart fehlt: es ist noch nicht festgelegt, wie die KI es nutzen darf.</x-fa::signal>
                            @endif
                            {{-- Spec 50 Strang III: Deckel erinnert, blockiert nicht. Ein Thema pro Dossier. --}}
                            @if($dossierHinweis)
                                <x-fa::signal tone="warn" data-wissen-deckel>{{ $dossierHinweis }}</x-fa::signal>
                            @endif
                        </div>
                    @endif

                    <div class="flex flex-col">
                        <x-fa::section variant="plain" title="Einordnung" icon="heroicon-o-tag" data-wissen-einordnung>
                            <div class="flex flex-col gap-3">
                                @include('foodalchemist::livewire.knowledge.einordnung')
                            </div>
                            <label class="flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)] font-medium cursor-pointer">
                                <input type="checkbox" wire:model="form.active" class="accent-[var(--fa-accent)]" /> Aktiv (wird mit „Speichern“ übernommen)
                            </label>
                            <p class="{{ $leise }} break-all">Kennung <span class="font-mono text-[var(--fa-ink-2)]">{{ $selected->slug }}</span></p>
                        </x-fa::section>

                        <x-fa::section variant="plain" title="Suchbegriffe" icon="heroicon-o-magnifying-glass" :meta="$aliases->count()" description="Begriffe, unter denen die KI dieses Wissen findet." data-wissen-aliases>
                            <div class="flex flex-wrap gap-1.5">
                                @forelse($aliases as $a)
                                    <span class="{{ $chip }}" wire:key="alias-{{ $a->id }}">
                                        {{ $a->alias_slug }}
                                        <button type="button" wire:click="removeAlias({{ $a->id }})" class="inline-flex items-center justify-center w-5 h-5 rounded-full text-[var(--fa-ink-3)] hover:text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]"
                                                aria-label="Suchbegriff {{ $a->alias_slug }} entfernen" title="Entfernen">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                                    </span>
                                @empty
                                    <span class="{{ $leise }}">Noch keine Suchbegriffe.</span>
                                @endforelse
                            </div>
                            <div class="flex gap-2">
                                <label for="wissen-neuer-alias" class="sr-only">Neuer Suchbegriff</label>
                                <x-fa::input id="wissen-neuer-alias" size="sm" wire:model="newAlias" wire:keydown.enter="addAlias" placeholder="Neuer Suchbegriff" data-wissen-neu-alias />
                                <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="addAlias">Begriff hinzufügen</x-fa::button>
                            </div>
                        </x-fa::section>

                        {{-- Verwendung: wo dieses Wissen wirkt --}}
                        <x-fa::section variant="plain" title="Wo dieses Wissen wirkt" icon="heroicon-o-arrows-pointing-out" data-wissen-verdrahtung>
                            <div class="flex flex-col gap-1.5">
                                <p class="{{ $titelKlein }}">Über die Kategorie „{{ $katLabel[$selected->category] ?? $lesbar((string) $selected->category) }}“</p>
                                @if($selected->category === 'cross_cutting' && $autoGeladen === false)
                                    {{-- Spec 52/A4: der Hinweis sagt die Wahrheit. Er erscheint nur, wenn ausschliesslich
                                         feste Routen greifen und dieses Dossier in keiner davon aufgelösten Liste steht. --}}
                                    <x-fa::notice tone="warn" data-wissen-auto-warnung>
                                        Die Kategorie wird nur fest geladen von:
                                        <strong>{{ collect($ccAlwaysFeatures)->map($arbeitsschritt)->implode(', ') }}</strong>.
                                        Dieses Dossier gehört dort nicht dazu und kommt deshalb nicht mit.
                                        Wirksam wird es über eine <strong>Kanon-Zeile</strong> für den betreffenden Arbeitsschritt
                                        oder über eine Suche nach Thema auf die Kategorie.
                                    </x-fa::notice>
                                @else
                                    <div class="flex flex-wrap gap-1.5">
                                        @forelse($routings as $r)
                                            <x-fa::badge wire:key="rt-{{ $r->id }}">{{ $arbeitsschritt((string) $r->feature) }} · {{ $routingModusLabel[$r->mode] ?? $lesbar((string) $r->mode) }}</x-fa::badge>
                                        @empty
                                            <span class="{{ $leise }}">Kein Arbeitsschritt lädt diese Kategorie.</span>
                                        @endforelse
                                    </div>
                                    @if(in_array($selected->category, ['domain', 'pairing'], true) && $routings->isNotEmpty())
                                        <p class="{{ $leise }}">Kommt nur mit, wenn das Rezept thematisch passt. Nicht garantiert.</p>
                                    @endif
                                @endif
                            </div>

                            {{-- Spec 52 · F7: der KANON am Dossier. Die Gegenfrage zur Wissenssteuerung:
                                 „in welchen Arbeitsschritten ist DAS hier verbindlich?" und der Weg dorthin. --}}
                            <div class="flex flex-col gap-2">
                                <p class="{{ $titelKlein }}">Verbindlich in (Kanon)</p>
                                <div class="flex flex-wrap gap-1.5" data-wissen-kanon>
                                    @forelse($kanonZeilen as $k)
                                        <span class="{{ $chip }}" wire:key="kn-{{ $k['scope_key'] }}" title="{{ $k['scope_key'] }}">
                                            {{ $arbeitsschritt((string) $k['scope_key']) }}
                                            <span class="text-[var(--fa-ink-3)]">· {{ $kanonModusLabel[$k['mode']] ?? $lesbar((string) $k['mode']) }}@if(! $k['active']) · ruht @endif</span>
                                            @if($darfKanon)
                                                <button type="button" wire:click="kanonRemove('{{ $k['scope_key'] }}')" class="inline-flex items-center justify-center w-5 h-5 rounded-full text-[var(--fa-ink-3)] hover:text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]"
                                                        aria-label="Aus dem Kanon nehmen" title="Aus dem Kanon nehmen">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                                            @endif
                                        </span>
                                    @empty
                                        <span class="{{ $leise }}">In keinem Kanon. Dieses Dossier ist nur über die Suche erreichbar.</span>
                                    @endforelse
                                </div>
                                @if($darfKanon)
                                    <div class="flex flex-col gap-2 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)] p-2.5">
                                        <x-fa::field label="Arbeitsschritt" for="wissen-kanon-schritt">
                                            <x-fa::select id="wissen-kanon-schritt" size="sm" wire:model="kanonPromptKey" data-kanon-key>
                                                <option value="">Arbeitsschritt wählen</option>
                                                @foreach($promptKeys as $pk)<option value="{{ $pk }}">{{ $arbeitsschritt($pk) }}</option>@endforeach
                                            </x-fa::select>
                                        </x-fa::field>
                                        <x-fa::field label="Mitgeben" for="wissen-kanon-modus" hint="Immer: steht stets in der Anfrage. Wenn Platz ist: nur, solange das Wissensbudget reicht.">
                                            <x-fa::select id="wissen-kanon-modus" size="sm" wire:model="kanonMode">
                                                <option value="pflicht">Immer mitgeben</option>
                                                <option value="wenn_platz">Wenn Platz ist</option>
                                            </x-fa::select>
                                        </x-fa::field>
                                        <div class="flex justify-end">
                                            <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="kanonAdd" data-kanon-add>Verbindlich machen</x-fa::button>
                                        </div>
                                    </div>
                                @endif
                                @if($hinweis)
                                    <x-fa::signal tone="info">{{ $hinweis }}</x-fa::signal>
                                @endif
                            </div>

                            {{-- Spec 52/F2+F3: Alt-Bindungen wirken nicht mehr; nur noch lösbar, damit Alt-Zeilen wegkönnen.
                                 Ohne Alt-Bindungen nur ein kurzer Satz. --}}
                            @if($bindings->isEmpty())
                                <p class="{{ $leise }}">Alte Bindungen: keine. So soll es sein.</p>
                            @else
                                <div class="flex flex-col gap-2">
                                    <p class="{{ $titelKlein }}">Alte Bindungen</p>
                                    <x-fa::notice tone="warn">
                                        Diese Bindungen <strong>wirken nicht mehr</strong>. Verbindlich wird ein Dossier über den
                                        <strong>Kanon</strong>, gesucht wird eine Kategorie in der Wissenssteuerung. Hier nur noch lösen.
                                    </x-fa::notice>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($bindings as $b)
                                            <span class="{{ $chip }}" wire:key="bd-{{ $b->id }}">
                                                {{ $arbeitsschritt((string) $b->target_key) }}@if($b->mode) <span class="text-[var(--fa-ink-3)]">· {{ $routingModusLabel[$b->mode] ?? $lesbar((string) $b->mode) }}</span>@endif
                                                <button type="button" wire:click="removeBinding({{ $b->id }})" class="inline-flex items-center justify-center w-5 h-5 rounded-full text-[var(--fa-ink-3)] hover:text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]"
                                                        aria-label="Bindung lösen" title="Bindung lösen">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </x-fa::section>
                    </div>
                @else
                    <x-fa::empty icon="heroicon-o-cursor-arrow-rays" title="Kein Dokument gewählt">Links ein Dokument wählen. Dann erscheinen hier Kategorie, Suchbegriffe und wo es wirkt.</x-fa::empty>
                @endif

                {{-- Werkzeuge, unabhängig vom gewählten Dokument --}}
                <div class="flex flex-col">
                    {{-- Spec 52: fragt den KANON, nicht die abgeschafften Bindungen. Nur mit gewähltem Dokument (wie bisher). --}}
                    @if($selected)
                        <x-fa::section variant="plain" title="Rückwärts nachsehen" icon="heroicon-o-arrow-uturn-left" description="Was bekommt ein Arbeitsschritt verbindlich?" data-wissen-trace>
                            <label for="wissen-trace" class="sr-only">Arbeitsschritt</label>
                            <x-fa::select id="wissen-trace" size="sm" wire:model.live="traceTarget">
                                <option value="">Arbeitsschritt wählen</option>
                                @foreach($traceKeys as $k)<option value="{{ $k }}">{{ $arbeitsschritt((string) $k) }}</option>@endforeach
                            </x-fa::select>
                            @if($traceTarget !== '')
                                <div class="flex flex-col gap-0.5">
                                    @forelse($traceResults as $t)
                                        <button type="button" wire:click="select({{ $t->id }})" wire:key="tr-{{ $t->id }}"
                                                class="w-full text-left px-2 py-1.5 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]">
                                            {{ $t->title }}
                                            <span class="{{ $leise }}">· {{ $katLabel[$t->category] ?? $lesbar((string) $t->category) }}@if($t->mode) · {{ $kanonModusLabel[$t->mode] ?? $lesbar((string) $t->mode) }}@endif</span>
                                        </button>
                                    @empty
                                        <p class="{{ $leise }}">Dieser Schritt hat keinen Kanon. Er bekommt nichts Verbindliches.</p>
                                    @endforelse
                                </div>
                            @endif
                        </x-fa::section>
                    @endif

                    <x-fa::section variant="plain" title="Wissensauswahl testen" icon="heroicon-o-beaker">
                        <details class="fa-surface group" data-wissen-vorschau>
                            <summary class="flex items-center justify-between gap-2 px-3.5 py-2.5 cursor-pointer select-none text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">
                                <span class="flex items-center gap-2">@svg('heroicon-o-beaker', 'w-4 h-4 text-[var(--fa-ink-3)]') Auftrag eingeben und prüfen</span>
                                @svg('heroicon-m-chevron-down', 'w-4 h-4 text-[var(--fa-ink-3)] transition-transform group-open:rotate-180')
                            </summary>
                            <div class="px-3.5 pb-3.5 pt-1 flex flex-col gap-3">
                                <p class="{{ $leise }}">Zeigt, welches Wissen ein Arbeitsschritt für diesen Auftrag bekäme. Es wird nichts erstellt.</p>
                                <x-fa::field label="Arbeitsschritt" for="wissen-vorschau-schritt">
                                    <x-fa::select id="wissen-vorschau-schritt" wire:model="previewPromptKey">
                                        @foreach($promptKeys as $key)<option value="{{ $key }}">{{ $arbeitsschritt($key) }}</option>@endforeach
                                    </x-fa::select>
                                </x-fa::field>
                                <x-fa::field label="Auftrag" for="wissen-vorschau-auftrag">
                                    <x-fa::textarea id="wissen-vorschau-auftrag" wire:model="previewQuery" rows="3" placeholder="Zum Beispiel: Cremige Linsensuppe für ein Winterbuffet" />
                                </x-fa::field>
                                <x-fa::field label="Niveau" for="wissen-vorschau-niveau">
                                    <x-fa::select id="wissen-vorschau-niveau" wire:model="previewLevel">
                                        <option value="">Nicht vorgegeben</option><option value="klassisch">Klassisch</option>
                                        <option value="gehoben">Gehoben</option><option value="haute_cuisine">Haute Cuisine</option>
                                    </x-fa::select>
                                </x-fa::field>
                                <x-fa::field label="Anlass" for="wissen-vorschau-anlass">
                                    <x-fa::select id="wissen-vorschau-anlass" wire:model="previewOccasion">
                                        <option value="">Nicht vorgegeben</option><option value="fruehstueck">Frühstück</option>
                                        <option value="lunch">Lunch</option><option value="konferenz">Konferenz</option>
                                        <option value="empfang">Empfang</option><option value="dinner">Dinner</option><option value="late_night">Late Night</option>
                                    </x-fa::select>
                                </x-fa::field>
                                <x-fa::field label="Verpflegungskontext" for="wissen-vorschau-sektor">
                                    <x-fa::select id="wissen-vorschau-sektor" wire:model="previewSector">
                                        <option value="">Nicht vorgegeben</option><option value="betriebsgastronomie">Betriebsgastronomie</option>
                                        <option value="catering">Catering</option><option value="care">Care</option>
                                        <option value="schule_kita">Schule / Kita</option><option value="restaurant">Restaurant</option>
                                    </x-fa::select>
                                </x-fa::field>
                                <details class="group/weitere">
                                    <summary class="cursor-pointer select-none text-[length:var(--fa-text-md)] text-[var(--fa-accent)]">Weitere Bedingungen</summary>
                                    <div class="mt-2 flex flex-col gap-2">
                                        @foreach(\Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::ACHSEN as $axis => $axisLabel)
                                            @if(! in_array($axis, ['niveau', 'occasion', 'sektor']))
                                                <x-fa::field :label="$axisLabel" for="wissen-vorschau-achse-{{ $axis }}">
                                                    <x-fa::input id="wissen-vorschau-achse-{{ $axis }}" size="sm" wire:model="previewAxes.{{ $axis }}" />
                                                </x-fa::field>
                                            @endif
                                        @endforeach
                                    </div>
                                </details>
                                <div class="flex justify-end">
                                    <x-fa::button variant="primary" icon="heroicon-o-beaker" wire:click="previewKnowledge" wire:loading.attr="disabled" wire:target="previewKnowledge">Wissensauswahl prüfen</x-fa::button>
                                </div>
                                @if($previewError)<x-fa::notice tone="crit">{{ $previewError }}</x-fa::notice>@endif
                                @if($knowledgePreview !== null)
                                    <div class="flex flex-col gap-3 pt-3 border-t border-[var(--fa-line)] text-[length:var(--fa-text-md)]" data-wissen-vorschau-ergebnis>
                                        <p class="text-[var(--fa-ink-2)]">Verbindliches Wissen aus: <span class="text-[var(--fa-ink)]">{{ $arbeitsschritt((string) $knowledgePreview['canon_prompt_key']) }}</span>@if($knowledgePreview['prompt_key'] === 'conformance.check') · Basisrezept-Prüfung @endif</p>
                                        <p class="tabular-nums text-[var(--fa-ink-2)]">
                                            <span class="font-medium text-[var(--fa-ink)]">{{ number_format($knowledgePreview['total_chars'], 0, ',', '.') }}</span> von {{ number_format($knowledgePreview['budget_total'], 0, ',', '.') }} Zeichen Wissen
                                            @if($knowledgePreview['dropped_chars'] > 0)<span class="block text-[var(--fa-warn)]">{{ number_format($knowledgePreview['dropped_chars'], 0, ',', '.') }} Zeichen ausgelassen</span>@endif
                                        </p>
                                        @if(($knowledgePreview['datenwerk'] ?? null) !== null)
                                            <div class="flex flex-col gap-1">
                                                @foreach($knowledgePreview['datenwerk']['ergebnisse'] as $result)
                                                    @if($result['status'] === 'widerspruch')
                                                        <x-fa::signal tone="crit">{{ $result['kennzahl'] }}: Widerspruch, keine automatische Auswahl</x-fa::signal>
                                                    @else
                                                        <p class="text-[var(--fa-ink)]">{{ $result['kennzahl'] }}: <span class="tabular-nums">{{ $result['werte']['min'] }} bis {{ $result['werte']['max'] }} {{ $result['werte']['einheit'] }}</span> <span class="text-[var(--fa-ink-3)]">je {{ $result['werte']['bezug'] }}</span></p>
                                                    @endif
                                                @endforeach
                                                @foreach($knowledgePreview['datenwerk']['luecken'] as $gap)<x-fa::signal tone="warn">Datenlücke: {{ $gap['grund'] }}</x-fa::signal>@endforeach
                                            </div>
                                        @endif
                                        @foreach(['kanon' => 'Verbindliches Wissen', 'retrieval' => 'Ausgewähltes Fachwissen', 'dropped' => 'Aus Platzgründen ausgelassen'] as $group => $labelText)
                                            <div class="flex flex-col gap-1">
                                                <p class="{{ $titelKlein }}">{{ $labelText }}</p>
                                                @forelse($knowledgePreview[$group] as $file)
                                                    <p class="break-words text-[var(--fa-ink-2)]">{{ $file }}</p>
                                                @empty<p class="{{ $leise }}">Keine Quellen</p>@endforelse
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </details>
                    </x-fa::section>
                </div>
            </div>
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Wissen" :subtitle="$untertitel">
            <x-slot:actions>
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="neu" data-wissen-neu>Neues Wissen</x-fa::button>
            </x-slot:actions>
        </x-fa::page-header>

        @if($fehler !== null)
            <x-fa::notice tone="crit" data-wissen-fehler>{{ $fehler }}</x-fa::notice>
        @endif

        @if($selected || $creating)
            {{-- Der Text bekommt die ganze Mitte. Ansicht/Bearbeiten ist ein Livewire-Umschalter,
                 weil das Textfeld aufgeschoben bindet: der Inhalt reist mit dem Klick mit. --}}
            <div class="fa-surface min-w-0" data-wissen-editor>
                <div class="px-5 pt-4 pb-3 flex flex-wrap items-start gap-3 border-b border-[var(--fa-line)]">
                    <div class="flex-1 min-w-[16rem]">
                        @if($vorschau)
                            {{-- Lese-Ansicht: der Titel ist Überschrift, kein Eingabefeld. --}}
                            <h2 class="text-[length:var(--fa-text-2xl)] font-semibold tracking-tight leading-tight text-[var(--fa-ink)] break-words" data-wissen-titel-lesen>
                                {{ ($form['title'] ?? '') ?: 'Ohne Titel' }}
                            </h2>
                            @if($selected && ! $creating)
                                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                    @if($selected->active)
                                        <x-fa::badge tone="ok" icon="heroicon-m-check-circle" data-wissen-aktiv-status="aktiv">Aktiv</x-fa::badge>
                                    @else
                                        <x-fa::badge tone="warn" icon="heroicon-m-pause-circle" data-wissen-aktiv-status="inaktiv">Inaktiv, wird nicht genutzt</x-fa::badge>
                                    @endif
                                    <span class="{{ $leise }}">{{ $katLabel[$selected->category] ?? $lesbar((string) $selected->category) }}</span>
                                </div>
                            @endif
                        @else
                            <div class="flex flex-col gap-3">
                                <x-fa::field label="Titel" for="wissen-titel">
                                    <x-fa::input id="wissen-titel" wire:model="form.title" data-wissen-titel />
                                </x-fa::field>
                                @if($creating)
                                    <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,18rem),1fr))]">
                                        @include('foodalchemist::livewire.knowledge.einordnung')
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        {{-- Ansicht zuerst: sie ist der Normalfall, Bearbeiten die Ausnahme. --}}
                        <div role="group" aria-label="Darstellung" class="flex p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]">
                            <button type="button" wire:click="$set('vorschau', true)" aria-pressed="{{ $vorschau ? 'true' : 'false' }}"
                                    class="h-8 px-3 rounded-[5px] text-[length:var(--fa-text-md)] font-medium transition-colors {{ $vorschau ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}"
                                    data-wissen-modus="vorschau">Ansicht</button>
                            <button type="button" wire:click="$set('vorschau', false)" aria-pressed="{{ ! $vorschau ? 'true' : 'false' }}"
                                    class="h-8 px-3 rounded-[5px] text-[length:var(--fa-text-md)] font-medium transition-colors {{ ! $vorschau ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}"
                                    data-wissen-modus="bearbeiten">Bearbeiten</button>
                        </div>

                        {{-- Aktivieren ist Kuration: eigener, sichtbarer Knopf, wirkt sofort (toggleActive prüft das Schreibrecht). --}}
                        @if($editable && ! $creating && $selected)
                            @if($selected->active)
                                <x-fa::button icon="heroicon-o-pause-circle" wire:click="toggleActive({{ $selected->id }})"
                                    title="Dokument stilllegen: die KI nutzt es nicht mehr" data-wissen-aktivieren>Deaktivieren</x-fa::button>
                            @else
                                <x-fa::button icon="heroicon-o-check-circle" wire:click="toggleActive({{ $selected->id }})"
                                    title="Dokument freigeben: die KI darf es nutzen" data-wissen-aktivieren>Aktivieren</x-fa::button>
                            @endif
                        @endif

                        {{-- Löschen (endgültig) nur bei eigenem, gespeichertem Dokument; abgesetzt im Menü. --}}
                        @if($editable && ! $creating)
                            <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                <div class="hidden w-56 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="delete({{ $selected->id }})"
                                            wire:confirm="Wissensdokument „{{ $selected->title }}“ endgültig löschen? Das kann nicht rückgängig gemacht werden."
                                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]"
                                            data-wissen-delete>
                                        @svg('heroicon-o-trash', 'w-4 h-4') Dokument löschen
                                    </button>
                                </div>
                            </div>
                        @endif

                        {{-- Speichern bleibt auch in der Ansicht sichtbar: der Umschalter schickt ungespeicherte Änderungen mit. --}}
                        <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="save" data-wissen-save>Speichern</x-fa::button>
                    </div>
                </div>

                @if($vorschau)
                    @if($frontmatter !== [])
                        {{-- Kopf-Felder kompakt statt als Fließtext --}}
                        <dl class="mx-5 mt-4 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)] px-3 py-2 flex flex-wrap gap-x-5 gap-y-1" data-wissen-frontmatter>
                            @foreach($frontmatter as $fk => $fv)
                                <div class="flex gap-1.5 min-w-0 text-[length:var(--fa-text-sm)]">
                                    <dt class="font-medium text-[var(--fa-ink-2)]">{{ $lesbar((string) $fk) }}</dt>
                                    <dd class="text-[var(--fa-ink)] break-words">{{ \Illuminate\Support\Str::limit($fv, 90) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                    <div class="px-5 py-4 max-h-[68vh] overflow-y-auto max-w-[80ch] {{ $prose }}" data-wissen-vorschau>
                        @if(trim((string) ($inhaltHtml ?? '')) === '')
                            <x-fa::empty icon="heroicon-o-document-text" title="Noch kein Inhalt">Mit „Bearbeiten“ den Text schreiben.</x-fa::empty>
                        @else
                            {!! $inhaltHtml !!}
                        @endif
                    </div>
                @else
                    <div class="px-5 py-4">
                        <label for="wissen-inhalt" class="sr-only">Inhalt</label>
                        <x-fa::textarea id="wissen-inhalt" wire:model="form.content_md" class="font-mono h-[68vh] resize-none"
                            data-wissen-inhalt placeholder="Text in Markdown: # Überschrift, - Aufzählung, **fett**" />
                    </div>
                @endif
            </div>
        @else
            <div class="fa-surface" data-wissen-empty>
                <x-fa::empty icon="heroicon-o-academic-cap" title="Kein Dokument geöffnet">
                    Links ein Dokument wählen oder ein neues anlegen.
                    <x-slot:action>
                        <x-fa::button icon="heroicon-m-plus" wire:click="neu">Neues Wissen</x-fa::button>
                    </x-slot:action>
                </x-fa::empty>
            </div>
        @endif
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
