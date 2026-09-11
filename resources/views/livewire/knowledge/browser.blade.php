{{-- M-Wissen: Wissens-Browser — Liste in der Plattform-Sidebar, Text in der Mitte,
     „wofür wird dieses Wissen genutzt" im rechten Panel (Spec 28 / E15).
     Vorher stapelten sich Editor UND drei Einstellungs-Karten in EINER Spalte neben einer
     384px-Liste — der Markdown-Text hatte damit am wenigsten Platz, obwohl er der Inhalt ist. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Wissen" icon="heroicon-o-academic-cap" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Wissen'],
        ]" />
    </x-slot>

    {{-- LINKS: Suche, Filter, Dokumentliste --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Dokumente" width="w-80">
            <div class="p-3 space-y-3" data-wissen-liste>
                {{-- Suche: der Platzhalter sagt, WAS gesucht wird — das schaltet mit dem
                     semantischen Schalter mit. --}}
                <input type="text" wire:model.live.debounce.300ms="search" data-wissen-suche
                       placeholder="{{ $semantic ? 'Suche (Text · Bedeutung · Synonyme) …' : 'Suche (Titel · Slug · Aliase) …' }}"
                       class="{{ $input }}" />

                <label class="flex items-center gap-1.5 text-[11px] text-gray-600 cursor-pointer"
                       title="Findet auch bedeutungsähnliche Dokumente ohne wörtliche Übereinstimmung">
                    <input type="checkbox" wire:model.live="semantic" data-wissen-semantik /> Bedeutung einbeziehen
                </label>

                @if($semanticNote !== null)
                    <p class="text-[11px] text-amber-600" data-wissen-semantik-hinweis>{{ $semanticNote }}</p>
                @endif

                {{-- Zwei Auswahlfelder nebeneinander gehen im 320px-Panel nicht auf: `$input` bringt
                     `w-full` mit und schlägt eine daneben gesetzte Breite — die Kategorie schrumpfte
                     dadurch auf 24px, also auf den blossen Pfeil. Deshalb untereinander. --}}
                <div class="space-y-2">
                    <select wire:model.live="filterCategory" class="{{ $input }} !py-1 text-xs" data-wissen-filter-kategorie>
                        <option value="">Alle Kategorien</option>
                        @foreach($kategorien as $kat)
                            <option value="{{ $kat->slug }}">{{ $kat->label }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="filterStatus" class="{{ $input }} !py-1 text-xs" data-wissen-filter-status>
                        <option value="all">Alle Status</option>
                        <option value="active">Nur aktive</option>
                        <option value="inactive">Nur inaktive</option>
                    </select>
                </div>

                <p class="text-[11px] text-gray-500" data-wissen-anzahl>
                    {{ $docs->count() }} Dokument(e)@if(trim($search) !== '') · nach Relevanz @endif
                </p>

                <div class="space-y-0.5 max-h-[58vh] overflow-y-auto -mx-1 px-1">
                    @forelse($docs as $doc)
                        {{-- Auswahl wie in den Filter-Bäumen: Balken + Füllung, inaktive gedämpft --}}
                        <button type="button" wire:click="select({{ $doc->id }})" wire:key="doc-{{ $doc->id }}"
                                class="w-full text-left px-2.5 py-1.5 rounded-lg border-l-2 transition-colors {{ $selected && $selected->id === $doc->id
                                    ? 'border-violet-500 bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700'
                                    : 'border-transparent hover:bg-black/[0.03]' }} {{ $doc->active ? '' : 'opacity-50' }}"
                                data-wissen-doc="{{ $doc->id }}">
                            <span class="block text-xs font-medium text-gray-900 break-words">{{ $doc->title }}</span>
                            <span class="flex items-center gap-1.5 mt-0.5">
                                <span class="text-[10px] {{ $pill }} {{ $variantPill['secondary'] }}">{{ $doc->category }}</span>
                                <span class="text-[10px] text-gray-500 tabular-nums">{{ number_format($doc->char_count, 0, ',', '.') }} Z.</span>
                                @unless($doc->active)<span class="text-[10px] text-amber-600">inaktiv</span>@endunless
                            </span>
                        </button>
                    @empty
                        <p class="text-xs text-gray-500 px-2 py-4">Keine Treffer.</p>
                    @endforelse
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- RECHTS: wofür wird dieses Wissen genutzt --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Verwendung" width="w-96" :maxWidth="760" scope="activity_knowledge" side="right">
            <details class="m-4 {{ $card }} p-4" data-wissen-vorschau>
                <summary class="text-sm font-medium cursor-pointer">Wissens-Vorschau für einen Auftrag</summary>
                <div class="mt-3 space-y-3">
                    <label class="block {{ $label }}">Arbeitsschritt
                        <select wire:model="previewPromptKey" class="{{ $input }} mt-1">
                            @foreach($promptKeys as $key)<option value="{{ $key }}">{{ $key }}</option>@endforeach
                        </select>
                    </label>
                    <label class="block {{ $label }}">Auftrag
                        <textarea wire:model="previewQuery" rows="3" class="{{ $input }} mt-1" placeholder="Zum Beispiel: Cremige Linsensuppe für ein Winterbuffet"></textarea>
                    </label>
                    <label class="block {{ $label }}">Niveau
                        <select wire:model="previewLevel" class="{{ $input }} mt-1">
                            <option value="">Nicht vorgegeben</option><option value="klassisch">Klassisch</option>
                            <option value="gehoben">Gehoben</option><option value="haute_cuisine">Haute Cuisine</option>
                        </select>
                    </label>
                    <label class="block {{ $label }}">Anlass
                        <select wire:model="previewOccasion" class="{{ $input }} mt-1">
                            <option value="">Nicht vorgegeben</option><option value="fruehstueck">Frühstück</option>
                            <option value="lunch">Lunch</option><option value="konferenz">Konferenz</option>
                            <option value="empfang">Empfang</option><option value="dinner">Dinner</option><option value="late_night">Late Night</option>
                        </select>
                    </label>
                    <label class="block {{ $label }}">Verpflegungskontext
                        <select wire:model="previewSector" class="{{ $input }} mt-1">
                            <option value="">Nicht vorgegeben</option><option value="betriebsgastronomie">Betriebsgastronomie</option>
                            <option value="catering">Catering</option><option value="care">Care</option>
                            <option value="schule_kita">Schule / Kita</option><option value="restaurant">Restaurant</option>
                        </select>
                    </label>
                    <details><summary class="text-xs cursor-pointer">Weitere Geltungsbedingungen</summary>
                        @foreach(\Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::ACHSEN as $axis => $axisLabel)
                            @if(! in_array($axis, ['niveau', 'occasion', 'sektor']))
                                <label class="block text-xs">{{ $axisLabel }}<input wire:model="previewAxes.{{ $axis }}" class="{{ $input }} w-full" /></label>
                            @endif
                        @endforeach
                    </details>
                    <button type="button" wire:click="previewKnowledge" wire:loading.attr="disabled" wire:target="previewKnowledge"
                            class="px-3 py-2 rounded-lg bg-violet-600 text-white text-xs">Wissensauswahl prüfen</button>
                    <p class="text-[11px] text-gray-500">Zeigt die Wissensauswahl für diese Angaben. Es wird kein Rezept erstellt.</p>
                    @if($previewError)<p class="text-xs text-red-700" role="alert">{{ $previewError }}</p>@endif
                    @if($knowledgePreview !== null)
                        <div class="text-xs space-y-2" data-wissen-vorschau-ergebnis>
                            <p>Kanonquelle: {{ $knowledgePreview['canon_prompt_key'] }} @if($knowledgePreview['prompt_key'] === 'conformance.check') · Basisrezept-Prüfung @endif</p>
                            <p>{{ number_format($knowledgePreview['total_chars'], 0, ',', '.') }} / {{ number_format($knowledgePreview['budget_total'], 0, ',', '.') }} Zeichen Wissen ·
                                {{ number_format($knowledgePreview['dropped_chars'], 0, ',', '.') }} Zeichen ausgelassen</p>
                            @if(($knowledgePreview['datenwerk'] ?? null) !== null)
                                @foreach($knowledgePreview['datenwerk']['ergebnisse'] as $result)
                                    <p class="{{ $result['status'] === 'widerspruch' ? 'text-red-700' : 'text-gray-700' }}">{{ $result['kennzahl'] }}: {{ $result['status'] === 'widerspruch' ? 'Widerspruch — keine automatische Auswahl' : $result['werte']['min'].'–'.$result['werte']['max'].' '.$result['werte']['einheit'].' · '.$result['werte']['bezug'] }}</p>
                                @endforeach
                                @foreach($knowledgePreview['datenwerk']['luecken'] as $gap)<p class="text-amber-700">Datenlücke: {{ $gap['grund'] }}</p>@endforeach
                            @endif
                            @foreach(['kanon' => 'Verbindliches Wissen', 'retrieval' => 'Ausgewähltes Fachwissen', 'dropped' => 'Wegen Budget ausgelassen'] as $group => $labelText)
                                <div><p class="font-medium">{{ $labelText }}</p>
                                    @forelse($knowledgePreview[$group] as $file)
                                        <p class="break-words text-gray-600">{{ $file }}</p>
                                    @empty<p class="text-gray-500">Keine Quellen</p>@endforelse
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </details>
            @if($selected)
                <div class="p-4 space-y-4">
                    <div class="{{ $card }} p-4 space-y-3" data-wissen-einordnung>
                        <p class="{{ $dt }}">Einordnung</p>
                        <div>
                            @include('foodalchemist::livewire.knowledge.einordnung')
                        </div>
                        <label class="flex items-center gap-1.5 text-xs text-gray-600">
                            <input type="checkbox" wire:model="form.active" /> aktiv
                        </label>
                        <p class="text-[10px] font-mono text-gray-500">{{ $selected->slug }} · v{{ $selected->version }} · {{ number_format($selected->char_count, 0, ',', '.') }} Z.</p>
                        {{-- Spec 50 Strang III: Deckel erinnert, blockiert nicht — ein Thema pro Dossier. --}}
                        @if($dossierHinweis)
                            <p class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-2 py-1" data-wissen-deckel>
                                ⚠ {{ $dossierHinweis }}
                            </p>
                        @endif
                    </div>
                    <div class="{{ $card }} p-4 space-y-2" data-wissen-aliases>
                    <p class="{{ $dt }}">Aliase <span class="text-[10px] text-gray-500">— Begriffe, unter denen die KI dieses Wissen findet</span></p>
                    <div class="flex flex-wrap gap-1.5">
                    @forelse($aliases as $a)
                    <span class="inline-flex items-center gap-1 text-[11px] {{ $pill }}" wire:key="alias-{{ $a->id }}">
                    {{ $a->alias_slug }}
                    <button type="button" wire:click="removeAlias({{ $a->id }})" class="text-gray-500 hover:text-red-500" title="entfernen">&times;</button>
                    </span>
                    @empty
                    <span class="text-[11px] text-gray-500">Noch keine Aliase.</span>
                    @endforelse
                    </div>
                    <div class="flex gap-2">
                    <input type="text" wire:model="newAlias" wire:keydown.enter="addAlias" placeholder="neuer Alias…" class="{{ $input }} !py-1 w-52" data-wissen-neu-alias />
                    <button type="button" wire:click="addAlias" class="{{ $btnGhostXs }}">+ hinzufügen</button>
                    </div>
                    </div>

                    <div class="{{ $card }} p-4 space-y-3" data-wissen-verdrahtung>
                    <p class="{{ $dt }}">Verdrahtung <span class="text-[10px] text-gray-500">— wo dieses Wissen wirkt</span></p>

                    <div>
                    <p class="text-[11px] font-medium text-gray-600 mb-1">Grobe Ebene — automatisch via Kategorie «{{ $selected->category }}»</p>
                    @if($selected->category === 'cross_cutting' && $autoGeladen === false)
                    {{--
                        Spec 52/A4 — der Hinweis sagt jetzt die Wahrheit. Vorher stand hier bei
                        158 von 165 cross_cutting-Dossiers, die Laufzeit lade „nur die 7
                        Kern-Files" — dreifach falsch: die 7 Originale sind seit Welle 2
                        DEAKTIVIERT, die Generatoren ziehen die Kategorie per `discovery` über
                        den ganzen Korpus, und der Rat „binde es an einen Einsatzort" führte in
                        die Alt-Struktur, die bei Prompt-Keys mit Kanon stumm ist.
                        Er erscheint nur noch, wenn wirklich ausschliesslich `always`-Routen
                        greifen und dieses Dossier in keiner davon aufgelösten Slug-Liste steht.
                    --}}
                    <span class="text-[11px] text-amber-600" data-wissen-auto-warnung>
                    @svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5 inline-block align-middle')
                    Die Kategorie wird nur fest geladen (<code>always</code>) von:
                    <strong>{{ implode(', ', $ccAlwaysFeatures) }}</strong> — und deren aufgelöste
                    Slug-Liste enthält dieses Dossier nicht. Es kommt dort also nicht mit.
                    Wirksam wird es über eine <strong>Kanon-Zeile</strong> für den betreffenden
                    Prompt-Key oder über eine <code>discovery</code>-Route auf die Kategorie.
                    </span>
                    @else
                    @forelse($routings as $r)
                    <span class="inline-flex items-center gap-1 text-[11px] {{ $pill }} mr-1.5" wire:key="rt-{{ $r->id }}">
                    {{ $r->feature }} <span class="text-gray-500">· {{ $r->mode }}</span>
                    </span>
                    @empty
                    <span class="text-[11px] text-gray-500">Keine Feature-Routings für diese Kategorie.</span>
                    @endforelse
                    @if(in_array($selected->category, ['domain', 'pairing'], true) && $routings->isNotEmpty())
                    <span class="block text-[10px] text-gray-400 mt-1">Nur geladen, wenn die Rezept-Beschreibung thematisch matcht (Discovery), nicht garantiert.</span>
                    @endif
                    @endif
                    </div>

                    <div>
                    {{--
                        Spec 52 · F7 — der KANON am Dossier.
                        Die Wissens-Steuerung geht vom PROMPT-KEY aus („was gehört in
                        recipe.generator?"). Hier steht die Gegenfrage, die ein Kurator vor
                        einem Dossier hat: „in welchen Prompts ist DAS hier verbindlich?" —
                        und der Weg, es dorthin zu bekommen. Sie fehlte, weil ich den
                        wirkungslosen „+ einbinden"-Knopf entfernt und nichts an seine Stelle
                        gesetzt hatte (Dominique, 2026-09-08: „wo kann man das denn dem Kanon
                        einstellen?").
                    --}}
                    <p class="text-[11px] font-medium text-gray-600 mb-1">Kanon — <span class="font-normal">wo dieses Wissen VERBINDLICH ist</span></p>
                    <div class="flex flex-wrap gap-1.5 mb-2" data-wissen-kanon>
                    @forelse($kanonZeilen as $k)
                    <span class="inline-flex items-center gap-1 text-[11px] {{ $pill }}" wire:key="kn-{{ $k['scope_key'] }}">
                    <code>{{ $k['scope_key'] }}</code>
                    <span class="text-gray-500">· {{ $k['mode'] }}@if(! $k['active']) · inaktiv @endif</span>
                    @if($darfKanon)
                    <button type="button" wire:click="kanonRemove('{{ $k['scope_key'] }}')" class="text-gray-500 hover:text-red-500" title="aus dem Kanon nehmen">&times;</button>
                    @endif
                    </span>
                    @empty
                    <span class="text-[11px] text-gray-500">In keinem Kanon — dieses Dossier ist nur über die Suche erreichbar.</span>
                    @endforelse
                    </div>
                    @if($darfKanon)
                    <div class="flex flex-wrap items-center gap-2 rounded-lg bg-black/[0.03] px-2.5 py-2 mb-3">
                    <select wire:model="kanonPromptKey" class="{{ $input }} !py-1 text-xs w-64" data-kanon-key>
                    <option value="">— Prompt-Key wählen —</option>
                    @foreach($promptKeys as $pk)<option value="{{ $pk }}">{{ $pk }}</option>@endforeach
                    </select>
                    <select wire:model="kanonMode" class="{{ $input }} !py-1 text-xs w-40" title="pflicht = immer im Prompt · wenn_platz = nur wenn das Budget reicht">
                    <option value="pflicht">pflicht</option>
                    <option value="wenn_platz">wenn_platz</option>
                    </select>
                    <button type="button" wire:click="kanonAdd" class="{{ $btnGhostXs }}" data-kanon-add>+ verbindlich machen</button>
                    </div>
                    @endif
                    @if($hinweis)
                    <p class="text-[11px] text-amber-600 mb-2">@svg('heroicon-o-information-circle', 'w-3.5 h-3.5 inline-block align-middle') {{ $hinweis }}</p>
                    @endif

                    <p class="text-[11px] font-medium text-gray-600 mb-1">Alt-Bindungen <span class="font-normal text-gray-500">— abgeschafft, nur noch lösbar</span></p>
                    {{--
                        Spec 52/F2+F3 (2026-09-08): der Gateway liest `knowledge_bindings` nicht
                        mehr. Hier stand ein „+ einbinden"-Knopf samt Warnung, dass er an den
                        Generatoren nichts bewirkt — Befund J: die Oberfläche wies den Kurator
                        aktiv in die tote Struktur. Ein Knopf, der nichts tut, ist schlimmer als
                        kein Knopf. Lösen bleibt, damit Alt-Zeilen wegkönnen.
                    --}}
                    @if($bindings->isNotEmpty())
                    <p class="text-[11px] text-amber-600 mb-2">
                    @svg('heroicon-o-information-circle', 'w-3.5 h-3.5 inline-block align-middle')
                    Diese Bindungen <strong>wirken nicht mehr</strong>. Verbindlich wird ein Dossier
                    über den <strong>Kanon</strong>, gesucht wird eine Kategorie über das
                    <strong>Routing</strong> — beides in der Wissenssteuerung. Hier nur noch lösen.
                    </p>
                    @endif
                    <div class="flex flex-wrap gap-1.5 mb-2">
                    @forelse($bindings as $b)
                    <span class="inline-flex items-center gap-1 text-[11px] {{ $pill }}" wire:key="bd-{{ $b->id }}">
                    {{ $b->target_key }}@if($b->mode) <span class="text-gray-500">· {{ $b->mode }}</span>@endif
                    <button type="button" wire:click="removeBinding({{ $b->id }})" class="text-gray-500 hover:text-red-500" title="Bindung lösen">&times;</button>
                    </span>
                    @empty
                    <span class="text-[11px] text-gray-500">Keine Alt-Bindungen — so soll es sein.</span>
                    @endforelse
                    </div>
                    </div>
                    </div>

                    <div class="{{ $card }} p-4 space-y-2" data-wissen-trace>
                    {{-- Spec 52: fragt den KANON, nicht die abgeschafften Bindungen. --}}
                    <p class="{{ $dt }}">Rückwärts nachvollziehen <span class="text-[10px] text-gray-500">— was bekommt dieser Arbeitsschritt verbindlich?</span></p>
                    <div class="flex flex-wrap items-center gap-2">
                    <select wire:model.live="traceTarget" class="{{ $input }} !py-1 text-xs w-64">
                    <option value="">— Arbeitsschritt wählen —</option>
                    @foreach($traceKeys as $k)<option value="{{ $k }}">{{ $k }}</option>@endforeach
                    </select>
                    </div>
                    @if($traceTarget !== '')
                    <div class="space-y-0.5">
                    @forelse($traceResults as $t)
                    <button type="button" wire:click="select({{ $t->id }})" class="block w-full text-left text-[11px] px-2 py-1 rounded hover:bg-black/[0.03]" wire:key="tr-{{ $t->id }}">
                    {{ $t->title }} <span class="text-gray-500">· {{ $t->category }}@if($t->mode) · {{ $t->mode }}@endif</span>
                    </button>
                    @empty
                    <p class="text-[11px] text-gray-500">Dieser Schritt hat keinen Kanon — er bekommt nichts Verbindliches.</p>
                    @endforelse
                    </div>
                    @endif
                </div>
            @else
                <p class="p-6 text-xs text-gray-500 text-center">Dokument links wählen — Kategorie, Aliase und Verdrahtung erscheinen hier.</p>
            @endif
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        <div class="flex items-center justify-between gap-3">
            @if($fehler !== null)
                <p class="text-xs text-rose-600" data-wissen-fehler>{{ $fehler }}</p>
            @else
                <span></span>
            @endif
            <button type="button" wire:click="neu" class="{{ $btnPrimary }}" data-wissen-neu>+ Neues Wissen</button>
        </div>

        @if($selected || $creating)
            {{-- Der Text bekommt die ganze Mitte. Vorschau/Bearbeiten ist ein Livewire-Umschalter,
                 weil das Textfeld aufgeschoben bindet: der Inhalt reist mit dem Klick mit. --}}
            <div class="relative overflow-hidden {{ $card }}" data-wissen-editor>
                <div class="{{ $cardAccent }}"></div>
                <div class="px-5 pt-4 pb-3 flex items-center gap-3">
                    <div class="flex-1 min-w-0">
                        @if($vorschau)
                            {{-- Lese-Ansicht: der Titel ist Überschrift, kein Eingabefeld. Ein Formularfeld
                                 über einem Lesetext lädt zum versehentlichen Tippen ein. --}}
                            <h2 class="text-base font-semibold tracking-tight text-gray-900 truncate" data-wissen-titel-lesen>
                                {{ ($form['title'] ?? '') ?: 'Ohne Titel' }}
                            </h2>
                        @else
                            <label class="{{ $label }}">Titel</label>
                            @if($creating) @include('foodalchemist::livewire.knowledge.einordnung') @endif
                            <input type="text" wire:model="form.title" class="{{ $input }} w-full" data-wissen-titel />
                        @endif
                    </div>
                    <div class="flex items-end gap-1 pb-0.5">
                        {{-- Ansicht zuerst: sie ist der Normalfall, Bearbeiten die Ausnahme. --}}
                        <button type="button" wire:click="$set('vorschau', true)"
                                class="{{ $pill }} {{ $vorschau ? $variantPill['primary'] : $variantPill['secondary'] }}"
                                data-wissen-modus="vorschau">Ansicht</button>
                        <button type="button" wire:click="$set('vorschau', false)"
                                class="{{ $pill }} {{ ! $vorschau ? $variantPill['primary'] : $variantPill['secondary'] }}"
                                data-wissen-modus="bearbeiten">Bearbeiten</button>
                    </div>
                    <div class="flex items-end gap-2 pb-0.5">
                        {{-- Löschen (Hard-Delete, endgültig): nur bei bereits gespeichertem, EIGENEM
                             Dokument. Geerbtes/Master-Wissen ist read-only → Button ausgeblendet.
                             Bestätigung per wire:confirm wie in den Einstellungen. --}}
                        @if($editable && ! $creating)
                            <button type="button" wire:click="delete({{ $selected->id }})"
                                    wire:confirm="Wissensdokument „{{ $selected->title }}" endgültig löschen? Das kann nicht rückgängig gemacht werden."
                                    class="inline-flex items-center whitespace-nowrap gap-1.5 px-3.5 py-2 text-[13px] font-medium text-red-600 bg-white/60 border border-red-500/20 rounded-lg hover:bg-red-500/10 transition-all duration-150"
                                    data-wissen-delete>
                                @svg('heroicon-o-trash', 'w-4 h-4') Löschen
                            </button>
                        @endif
                        {{-- Speichern bleibt auch in der Ansicht sichtbar: der Umschalter schickt
                             ungespeicherte Änderungen mit, sie dürfen hier nicht in eine Sackgasse laufen. --}}
                        <button type="button" wire:click="save" class="{{ $btnPrimary }}" data-wissen-save>Speichern</button>
                    </div>
                </div>

                @if($vorschau)
                    {{-- Gerendertes Markdown. Die Grundtypografie steht hier als gescoptes CSS:
                         das Typography-Plugin ist nicht eingebunden, und rohe <h1>/<ul> ohne Regeln
                         sehen im Tailwind-Reset aus wie Fließtext. --}}
                    <style>
                        [data-wissen-vorschau] h1{ font-size:1.25rem; font-weight:600; margin:1.1em 0 .5em; color:#111827; }
                        [data-wissen-vorschau] h2{ font-size:1.05rem; font-weight:600; margin:1em 0 .4em; color:#111827; }
                        [data-wissen-vorschau] h3{ font-size:.95rem; font-weight:600; margin:.9em 0 .3em; color:#374151; }
                        [data-wissen-vorschau] p{ margin:.55em 0; line-height:1.65; }
                        [data-wissen-vorschau] ul{ list-style:disc; padding-left:1.3em; margin:.55em 0; }
                        [data-wissen-vorschau] ol{ list-style:decimal; padding-left:1.4em; margin:.55em 0; }
                        [data-wissen-vorschau] li{ margin:.2em 0; }
                        [data-wissen-vorschau] code{ font-family:ui-monospace,monospace; font-size:.85em; background:rgba(0,0,0,.05); padding:.1em .35em; border-radius:.25rem; }
                        [data-wissen-vorschau] pre{ background:rgba(0,0,0,.04); padding:.75rem 1rem; border-radius:.5rem; overflow-x:auto; margin:.7em 0; }
                        [data-wissen-vorschau] pre code{ background:none; padding:0; }
                        [data-wissen-vorschau] blockquote{ border-left:3px solid rgba(139,92,246,.4); padding-left:.9em; color:#4b5563; margin:.7em 0; }
                        [data-wissen-vorschau] table{ width:100%; font-size:.85em; margin:.7em 0; }
                        [data-wissen-vorschau] th{ text-align:left; font-weight:600; border-bottom:1px solid rgba(0,0,0,.1); padding:.3em .5em; }
                        [data-wissen-vorschau] td{ border-top:1px solid rgba(0,0,0,.05); padding:.3em .5em; vertical-align:top; }
                        [data-wissen-vorschau] a{ color:#6d28d9; text-decoration:underline; }
                        [data-wissen-vorschau] hr{ border-top:1px solid rgba(0,0,0,.08); margin:1.2em 0; }
                    </style>
                    @if($frontmatter !== [])
                        {{-- Kopf-Felder kompakt statt als Fließtext (typ, zweck, verwendbar_in_skills …) --}}
                        <div class="mx-5 mb-3 rounded-lg bg-black/[0.03] px-3 py-2 flex flex-wrap gap-x-4 gap-y-1" data-wissen-frontmatter>
                            @foreach($frontmatter as $fk => $fv)
                                <span class="text-[11px] text-gray-600"><span class="{{ $dt }}">{{ $fk }}</span> {{ \Illuminate\Support\Str::limit($fv, 90) }}</span>
                            @endforeach
                        </div>
                    @endif
                    <div class="px-5 pb-5 text-sm text-gray-700 max-h-[68vh] overflow-y-auto" data-wissen-vorschau>
                        @if(trim((string) ($inhaltHtml ?? '')) === '')
                            <p class="text-xs text-gray-500 py-8 text-center">Noch kein Inhalt.</p>
                        @else
                            {!! $inhaltHtml !!}
                        @endif
                    </div>
                @else
                    <div class="px-5 pb-5">
                        <textarea wire:model="form.content_md" class="{{ $input }} w-full font-mono text-xs leading-relaxed h-[68vh] resize-none"
                                  data-wissen-inhalt placeholder="Markdown …"></textarea>
                    </div>
                @endif
            </div>
        @else
            <div class="{{ $card }} p-10 text-center text-sm text-gray-500" data-wissen-empty>
                Links ein Dokument wählen oder ein neues anlegen.
            </div>
        @endif
    </x-ui-page-container>
</x-ui-page>
