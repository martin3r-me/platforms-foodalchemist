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
                       placeholder="{{ $semantic ? 'Semantisch suchen (Bedeutung · Synonyme) …' : 'Suche (Titel · Slug · Inhalt) …' }}"
                       class="{{ $input }}" />

                <label class="flex items-center gap-1.5 text-[11px] text-gray-600 cursor-pointer"
                       title="Findet auch bedeutungsähnliche Dokumente ohne wörtliche Übereinstimmung">
                    <input type="checkbox" wire:model.live="semantic" data-wissen-semantik /> semantisch suchen
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
                    {{ $docs->count() }} Dokument(e)@if($semanticAktiv) · nach Relevanz @endif
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
            @if($selected)
                <div class="p-4 space-y-4">
                    <div class="{{ $card }} p-4 space-y-3" data-wissen-einordnung>
                        <p class="{{ $dt }}">Einordnung</p>
                        <div>
                            <label class="{{ $label }}">Kategorie</label>
                            <select wire:model="form.category" class="{{ $input }} w-full" data-wissen-kategorie>
                                @foreach($kategorien as $kat)
                                    <option value="{{ $kat->slug }}">{{ $kat->label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <label class="flex items-center gap-1.5 text-xs text-gray-600">
                            <input type="checkbox" wire:model="form.active" /> aktiv
                        </label>
                        <p class="text-[10px] font-mono text-gray-500">{{ $selected->slug }} · v{{ $selected->version }}</p>
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
                    {{-- #469 Chip-Wahrheit: cross_cutting lädt zur Laufzeit NUR die 7 Kern-Files --}}
                    <span class="text-[11px] text-amber-600" data-wissen-auto-warnung>
                    @svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5 inline-block align-middle') Die Laufzeit lädt automatisch nur die 7 Kern-cross_cutting-Files
                    (Substitutionen, Saisonkalender, Synonyme, Sauce-Mutterstrukturen, Mengen-Defaults, Techniken, Brühen&nbsp;/&nbsp;Fonds).
                    Dieses Doc gehört <strong>nicht</strong> dazu → es wirkt erst, wenn du es unten an einen Einsatzort bindest.
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
                    <p class="text-[11px] font-medium text-gray-600 mb-1">Feine Ebene — an Einsatzorte gebunden (direkt einbinden)</p>
                    <div class="flex flex-wrap gap-1.5 mb-2">
                    @forelse($bindings as $b)
                    <span class="inline-flex items-center gap-1 text-[11px] {{ $pill }}" wire:key="bd-{{ $b->id }}">
                    {{ $layerLabels[$b->target_key] ?? $b->target_key }}@if($b->mode) <span class="text-gray-500">· {{ $b->mode }}</span>@endif
                    <button type="button" wire:click="removeBinding({{ $b->id }})" class="text-gray-500 hover:text-red-500" title="Bindung lösen">&times;</button>
                    </span>
                    @empty
                    <span class="text-[11px] text-gray-500">Noch keine Bindungen.</span>
                    @endforelse
                    </div>
                    {{-- Bindung hinzufügen: Bereich (grob) oder Einzel-Prompt (fein) --}}
                    <div class="flex flex-wrap items-center gap-2 rounded-lg bg-black/[0.03] px-2.5 py-2">
                    <select wire:model="newBinding.target_key" class="{{ $input }} !py-1 text-xs w-64" data-bind-target>
                    <option value="">— Einsatzort wählen —</option>
                    <optgroup label="Bereiche (grob)">
                    @foreach($layers->where('kind', 'bereich') as $l)<option value="{{ $l->slug }}">{{ $l->label }}</option>@endforeach
                    </optgroup>
                    <optgroup label="Einzel-Prompts (fein)">
                    @foreach($layers->where('kind', 'prompt') as $l)<option value="{{ $l->slug }}">{{ $l->slug }}</option>@endforeach
                    </optgroup>
                    </select>
                    <select wire:model="newBinding.mode" class="{{ $input }} !py-1 text-xs w-32" title="Injektions-Modus">
                    @foreach(['always','discovery','grounding','reference'] as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach
                    </select>
                    <button type="button" wire:click="addBinding" class="{{ $btnGhostXs }}" data-bind-add>+ einbinden</button>
                    </div>
                    </div>
                    </div>

                    <div class="{{ $card }} p-4 space-y-2" data-wissen-trace>
                    <p class="{{ $dt }}">Rückwärts nachvollziehen <span class="text-[10px] text-gray-500">— was hängt an einem KI-Layer / einer Warengruppe?</span></p>
                    <div class="flex flex-wrap items-center gap-2">
                    <select wire:model.live="traceTarget" class="{{ $input }} !py-1 text-xs w-64">
                    <option value="">— Einsatzort wählen —</option>
                    <optgroup label="Bereiche">
                    @foreach($layers->where('kind', 'bereich') as $l)<option value="{{ $l->slug }}">{{ $l->label }}</option>@endforeach
                    </optgroup>
                    <optgroup label="Einzel-Prompts">
                    @foreach($layers->where('kind', 'prompt') as $l)<option value="{{ $l->slug }}">{{ $l->slug }}</option>@endforeach
                    </optgroup>
                    </select>
                    </div>
                    @if($traceTarget !== '')
                    <div class="space-y-0.5">
                    @forelse($traceResults as $t)
                    <button type="button" wire:click="select({{ $t->id }})" class="block w-full text-left text-[11px] px-2 py-1 rounded hover:bg-black/[0.03]" wire:key="tr-{{ $t->id }}">
                    {{ $t->title }} <span class="text-gray-500">· {{ $t->category }}@if($t->mode) · {{ $t->mode }}@endif</span>
                    </button>
                    @empty
                    <p class="text-[11px] text-gray-500">Nichts an diesem Ziel gebunden.</p>
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
                        <label class="{{ $label }}">Titel</label>
                        <input type="text" wire:model="form.title" class="{{ $input }} w-full" data-wissen-titel />
                    </div>
                    <div class="flex items-end gap-1 pb-0.5">
                        <button type="button" wire:click="$set('vorschau', false)"
                                class="{{ $pill }} {{ ! $vorschau ? $variantPill['primary'] : $variantPill['secondary'] }}"
                                data-wissen-modus="bearbeiten">Bearbeiten</button>
                        <button type="button" wire:click="$set('vorschau', true)"
                                class="{{ $pill }} {{ $vorschau ? $variantPill['primary'] : $variantPill['secondary'] }}"
                                data-wissen-modus="vorschau">Vorschau</button>
                    </div>
                    <div class="flex items-end pb-0.5">
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
