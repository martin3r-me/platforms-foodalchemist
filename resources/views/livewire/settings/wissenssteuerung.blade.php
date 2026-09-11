{{-- Spec 52 (Grundsatz E): Wissens-Steuerung sichtbar + einstellbar — vorher hatte nur die ALTE Bindungs-Ebene eine UI --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-6" data-settings-wissenssteuerung>
    @if($fehler !== null)<p class="text-xs text-rose-600" data-ws-fehler>{{ $fehler }}</p>@endif
    @if($hinweis !== null)<p class="text-xs text-emerald-600" data-ws-hinweis>{{ $hinweis }}</p>@endif

    {{-- ── Überblick ── --}}
    <div class="flex flex-wrap items-center gap-4" data-ws-zaehler>
        @foreach([
            ['gesteuert', $bericht['gesteuert'], 'text-emerald-600'],
            ['bewusst leer', $bericht['bewusst_leer'], 'text-gray-500'],
            ['ungesteuert', $bericht['ungesteuert'], 'text-amber-600'],
            ['fehlerhaft', $bericht['fehlerhaft'], 'text-rose-600'],
        ] as [$label, $wert, $farbe])
            <div>
                <p class="text-lg font-semibold {{ $farbe }}">{{ $wert }}</p>
                <p class="text-[11px] text-gray-500">{{ $label }}</p>
            </div>
        @endforeach
        <p class="text-[11px] text-gray-500 ml-auto max-w-md">
            <span class="font-medium">ungesteuert</span> heisst: aus dem Wissens-Korpus erreicht diesen Schritt
            nichts. Regeln im Prompt-Text selbst sind davon unberührt.
            <span class="font-medium">fehlerhaft</span> heisst: es ist etwas hinterlegt, es löst nur nicht auf.
        </p>
    </div>

    {{-- ── Filter ── --}}
    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="bereich" class="{{ $input }} !py-1 w-48" data-ws-bereich>
            <option value="">alle Bereiche ({{ $bericht['keys'] }})</option>
            @foreach($bereiche as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
        </select>
        <label class="flex items-center gap-1.5 text-[11px] text-gray-600">
            <input type="checkbox" wire:model.live="nurBefunde" data-ws-nur-befunde /> nur mit Befund
        </label>
    </div>

    {{-- ── Profile ── --}}
    <table class="{{ $table }}" data-ws-profile>
        <thead><tr class="text-left">@foreach(['Prompt-Key', 'Zustand', 'Pflicht', 'Suche', 'Σ Pflicht / Budget', 'Fingerabdruck'] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse($profile as $p)
                @php($blockiert = collect($p['befunde'])->contains(fn ($b) => $b['schwere'] === 'blockiert'))
                <tr class="{{ $tr }} cursor-pointer" wire:key="p-{{ $p['prompt_key'] }}" wire:click="toggleOffen('{{ $p['prompt_key'] }}')">
                    <td class="{{ $td }} font-mono text-[11px] text-gray-900">
                        {{ $p['prompt_key'] }}
                        @if($p['alt_schluessel'])
                            <span class="text-[10px] text-amber-600" title="Routing läuft unter einem Alt-Schlüssel — ein Call, zwei Identitäten">→ {{ $p['routing_key'] }}</span>
                        @endif
                    </td>
                    <td class="{{ $td }} text-[11px] {{ match($p['zustand']) { 'gesteuert' => 'text-emerald-600', 'fehlerhaft' => 'text-rose-600 font-medium', 'ungesteuert' => 'text-amber-600', default => 'text-gray-500' } }}">
                        {{ $p['zustand'] }}
                    </td>
                    <td class="{{ $td }} text-[11px] text-gray-600">{{ count($p['pflicht']) ?: '–' }}</td>
                    <td class="{{ $td }} text-[11px] text-gray-600">{{ count(array_filter($p['routing'], fn ($r) => $r['mode'] !== 'none')) ?: '–' }}</td>
                    {{-- Das Budget war hier immer nur ABLESBAR — eine Diagnose ohne Therapie.
                         Jetzt ist die rechte Zahl der Knopf: er ist der Hebel fuer Kosten gegen
                         Qualitaet, und er gehoert dem Betreiber, nicht dem Release-Zyklus. --}}
                    <td class="{{ $td }} text-[11px] text-gray-600">
                        @if($budgetKey === $p['prompt_key'])
                            <div class="flex items-center gap-1">
                                <span>{{ number_format($p['pflicht_zeichen'], 0, ',', '.') }} /</span>
                                <input type="number" min="1" step="100" wire:model="budgetWert"
                                       class="{{ $input }} !py-0.5 !px-1 text-[11px] w-24" wire:keydown.enter="saveBudget">
                                <button type="button" wire:click="saveBudget" class="text-[10px] underline">sichern</button>
                                <button type="button" wire:click="resetBudget('{{ $p['prompt_key'] }}')"
                                        class="text-[10px] underline text-gray-400" title="zurueck auf den ausgelieferten Standard">Standard</button>
                                <button type="button" wire:click="$set('budgetKey', null)" class="text-[10px] text-gray-400">×</button>
                            </div>
                        @else
                            <button type="button" wire:click="editBudget('{{ $p['prompt_key'] }}')"
                                    class="hover:underline" title="Wissensbudget aendern">
                                {{ number_format($p['pflicht_zeichen'], 0, ',', '.') }} / <span class="font-medium">{{ number_format($p['budget_total'], 0, ',', '.') }}</span>
                                @if(($eingestellteBudgets[$p['prompt_key']] ?? null) !== null)
                                    <span class="text-[9px] text-[var(--ui-primary)]" title="abweichend vom ausgelieferten Standard">◆</span>
                                @endif
                            </button>
                        @endif
                    </td>
                    <td class="{{ $td }} font-mono text-[10px] text-gray-400">{{ $p['fingerabdruck'] }}</td>
                </tr>

                @if($p['befunde'] !== [])
                    <tr wire:key="b-{{ $p['prompt_key'] }}">
                        <td colspan="6" class="{{ $td }} !pt-0">
                            @foreach($p['befunde'] as $b)
                                <p class="text-[11px] {{ $b['schwere'] === 'blockiert' ? 'text-rose-600' : 'text-amber-600' }}">
                                    {{ $b['schwere'] === 'blockiert' ? '✗' : '·' }}
                                    <span class="font-mono text-[10px]">[{{ $b['code'] }}]</span>
                                    @if($b['slug'] !== null)<span class="font-mono">{{ $b['slug'] }}</span> — @endif{{ $b['text'] }}
                                </p>
                            @endforeach
                        </td>
                    </tr>
                @endif

                @if($offen === $p['prompt_key'])
                    <tr wire:key="d-{{ $p['prompt_key'] }}">
                        <td colspan="6" class="{{ $td }} !pt-0">
                            <div class="rounded-lg bg-black/[0.03] px-3 py-2 space-y-2">
                                {{--
                                    KANON-EDITOR (Spec 52 · Paket 3). Bis hier war diese Seite nur
                                    ein Bericht: sie zeigte, was verbindlich ist, und zum Ändern
                                    musste man nach MCP. Seit F2 ist der Kanon die EINZIGE Quelle
                                    für „muss in diesen Prompt" — ohne Editor hätte die
                                    abgeschaffte Bindungs-Ebene weiterhin die einzige UI gehabt.
                                --}}
                                <div>
                                    <div class="flex items-center justify-between">
                                        <p class="{{ $dt }}">Pflicht (kommt immer vollständig, wird nie gekappt)</p>
                                        @if($darfSchreiben)
                                            <button type="button" wire:click="kanonEdit('{{ $p['prompt_key'] }}')" class="{{ $btnGhostXs }}">
                                                {{ $kanonKey === $p['prompt_key'] ? 'fertig' : 'Kanon bearbeiten' }}
                                            </button>
                                        @endif
                                    </div>
                                    @forelse($p['pflicht'] as $d)
                                        <p class="text-[11px] font-mono text-gray-600" wire:key="kp-{{ $p['prompt_key'] }}-{{ $d['slug'] }}">
                                            {{ $d['slug'] }} <span class="text-gray-400">@v{{ $d['version'] }} · {{ number_format($d['zeichen'], 0, ',', '.') }} Z.</span>
                                            @if($darfSchreiben && $kanonKey === $p['prompt_key'])
                                                <button type="button" wire:click="kanonRemove('{{ $p['prompt_key'] }}', '{{ $d['slug'] }}')"
                                                        class="text-gray-400 hover:text-red-500" title="aus dem Kanon nehmen">&times;</button>
                                            @endif
                                        </p>
                                    @empty
                                        <p class="text-[11px] text-gray-400">—</p>
                                    @endforelse
                                </div>
                                @if($p['wenn_platz'] !== [])
                                    <div>
                                        <p class="{{ $dt }}">wenn Platz (fällt bei Budgetmangel ganz weg, nie angeschnitten)</p>
                                        @foreach($p['wenn_platz'] as $d)
                                            <p class="text-[11px] font-mono text-gray-600" wire:key="kw-{{ $p['prompt_key'] }}-{{ $d['slug'] }}">
                                                {{ $d['slug'] }} <span class="text-gray-400">@v{{ $d['version'] }}</span>
                                                @if($darfSchreiben && $kanonKey === $p['prompt_key'])
                                                    <button type="button" wire:click="kanonRemove('{{ $p['prompt_key'] }}', '{{ $d['slug'] }}')"
                                                            class="text-gray-400 hover:text-red-500" title="aus dem Kanon nehmen">&times;</button>
                                                @endif
                                            </p>
                                        @endforeach
                                    </div>
                                @endif

                                @if($darfSchreiben && $kanonKey === $p['prompt_key'])
                                    <div class="rounded-lg border border-black/10 bg-white px-2.5 py-2 space-y-2" data-kanon-editor>
                                        <p class="{{ $dt }}">Dossier verbindlich machen</p>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <input type="text" wire:model.live.debounce.300ms="kanonSuche" placeholder="Dossier suchen (Slug oder Titel, ab 2 Zeichen)…"
                                                   class="{{ $input }} !py-1 text-xs w-72" data-kanon-suche />
                                            <select wire:model="kanonForm.mode" class="{{ $input }} !py-1 text-xs w-36" title="pflicht = immer · wenn_platz = nur im Budget">
                                                <option value="pflicht">pflicht</option>
                                                <option value="wenn_platz">wenn_platz</option>
                                            </select>
                                            <input type="number" wire:model="kanonForm.ord" placeholder="Reihenfolge" class="{{ $input }} !py-1 text-xs w-28" />
                                            <button type="button" wire:click="kanonAdd" class="{{ $btnGhostXs }}" data-kanon-add
                                                    @disabled($kanonForm['slug'] === '')>+ aufnehmen</button>
                                        </div>

                                        @if($kanonForm['slug'] !== '')
                                            <p class="text-[11px] text-gray-600">gewählt: <span class="font-mono">{{ $kanonForm['slug'] }}</span></p>
                                        @endif

                                        @foreach($kanonTreffer as $t)
                                            <button type="button" wire:key="kt-{{ $t->slug }}" wire:click="$set('kanonForm.slug', '{{ $t->slug }}')"
                                                    class="block w-full text-left text-[11px] px-1.5 py-1 rounded hover:bg-black/[0.04]">
                                                <span class="font-mono">{{ $t->slug }}</span>
                                                <span class="text-gray-400">· {{ $t->category }} · {{ number_format($t->char_count, 0, ',', '.') }} Z.</span>
                                                @unless($t->active)<span class="text-amber-600">· inaktiv</span>@endunless
                                            </button>
                                        @endforeach

                                        <p class="text-[10px] text-gray-400">
                                            <strong>pflicht</strong> kommt vollständig. Ist das Gesamtbudget dafür zu klein, wird der Aufruf gestoppt.
                                            <strong>wenn_platz</strong> fällt bei Budgetmangel als GANZES Dossier weg, nie als Anschnitt.
                                            Ein inaktives Dossier lässt sich aufnehmen (Vorbereitung), liefert aber erst nach dem Aktivieren.
                                        </p>
                                    </div>
                                @endif
                                <div>
                                    <p class="{{ $dt }}">Suche — Routing auf «{{ $p['routing_key'] }}» (gemeinsames Wissensbudget {{ number_format($p['budget_total'], 0, ',', '.') }} Z.)</p>
                                    @forelse($p['routing'] as $r)
                                        <p class="text-[11px] text-gray-600">
                                            <span class="font-mono">{{ $r['art'] ?? $r['category'] }}</span> → {{ $r['mode'] }}
                                            @if($r['max_docs'])<span class="text-gray-400">(max. {{ $r['max_docs'] }} Quellen)</span>@endif
                                        </p>
                                    @empty
                                        <p class="text-[11px] text-gray-400">—</p>
                                    @endforelse
                                </div>
                            </div>
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="6" class="{{ $td }} text-[11px] text-gray-400">Keine Zeile.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- ── Achsen-Bindungen (Spec 52/H2) ── --}}
    <div class="space-y-2" data-ws-achsen>
        <div>
            <p class="{{ $dt }}">Achsen — Wissen, das AUFGELÖST wird statt gesucht</p>
            <p class="text-[11px] text-gray-500">
                Trägt eine Leitplanke den Wert, kommt das Dossier mit — unabhängig von jedem
                Suchrang. Technisch Kanon-Zeilen mit <code>scope=achse</code>,
                <code>scope_key=&lt;achse&gt;:&lt;wert&gt;</code>. Pflege bis zum Kanon-Editor über
                <code>knowledge_canon.PUT</code> (braucht einen Dossier-Wähler).
            </p>
        </div>

        @php($achsenNamen = collect(array_keys($achsen))->merge(array_keys($achsenConfig))->unique()->sort()->values())
        @forelse($achsenNamen as $achse)
            @php($werteGepflegt = $achsen[$achse] ?? [])
            @php($werteConfig = $achsenConfig[$achse] ?? [])
            <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                <p class="text-[11px] font-medium text-gray-700 font-mono">{{ $achse }}</p>
                @foreach(collect(array_keys($werteGepflegt))->merge(array_keys($werteConfig))->unique()->sort() as $wert)
                    @php($gepflegt = $werteGepflegt[$wert] ?? null)
                    <p class="text-[11px] text-gray-600">
                        <span class="font-mono">{{ $wert }}</span> →
                        @if($gepflegt !== null)
                            <span class="font-mono">{{ implode(', ', $gepflegt) }}</span>
                            <span class="text-[10px] text-emerald-600">gepflegt</span>
                        @elseif(($werteConfig[$wert] ?? []) !== [])
                            <span class="font-mono text-gray-500">{{ implode(', ', $werteConfig[$wert]) }}</span>
                            <span class="text-[10px] text-gray-400">aus der Config</span>
                        @else
                            <span class="text-[10px] text-amber-600">kein Dossier — dieser Wert bringt kein Wissen mit</span>
                        @endif
                    </p>
                @endforeach
            </div>
        @empty
            <p class="text-[11px] text-gray-400">Keine Achse verdrahtet.</p>
        @endforelse

        @if($datenwerkOhneAchse !== [])
            <div class="rounded-lg bg-amber-50 px-3 py-2">
                <p class="text-[11px] font-medium text-amber-800">
                    Als <span class="font-mono">datenwerk</span> deklariert, aber an keiner Achse —
                    liegt damit im Suchtopf statt aufgelöst zu werden:
                </p>
                @foreach($datenwerkOhneAchse as $slug)
                    <p class="text-[11px] font-mono text-amber-700">{{ $slug }}</p>
                @endforeach
            </div>
        @endif
    </div>

    <div class="space-y-3 border rounded p-4" data-ws-arten>
        <p class="{{ $dt }}">Arten — welches Wissen darf ein Arbeitsschritt benutzen?</p>
        <p class="text-xs text-gray-500">Regeln kommen aus dem Kanon. Datenwerke werden über Bedingungen aufgelöst. Fachwissen und Referenzen werden gesucht. Mit dem ersten Arten-Routing gilt die Kategorie-Steuerung dieses Schritts nur noch für nicht eingeordnete Dossiers.</p>
        @foreach($routings->filter(fn ($r) => ! empty($r->art)) as $r)
            <p class="text-sm">{{ $r->feature }} · {{ $r->art }} · {{ $r->mode }}
                @if($r->mode === 'discovery') · {{ $r->max_docs ?? 'Standard' }} Suchtreffer @endif
                @if($darfSchreiben)
                    <button type="button" wire:click="editArt({{ $r->id }})" class="{{ $btnGhostXs }}">Bearbeiten</button>
                    <button type="button" wire:click="delete({{ $r->id }})" wire:confirm="Arten-Routing entfernen? Ohne verbleibende Arten-Routings gilt wieder die Kategorie-Steuerung." class="{{ $btnGhostXs }}">Entfernen</button>
                @endif
            </p>
        @endforeach
        @if($darfSchreiben)
            <label class="block text-xs">Arbeitsschritt<input wire:model="artForm.feature" class="{{ $input }}" placeholder="recipe.generator" /></label>
            <label class="block text-xs">Art<select wire:model="artForm.art" class="{{ $input }}"><option value="fachwissen">Fachwissen</option><option value="referenz">Referenz</option><option value="datenwerk">Datenwerk</option></select></label>
            <label class="block text-xs">Verwendung<select wire:model="artForm.mode" class="{{ $input }}"><option value="discovery">Suchen</option><option value="resolve">Werte auflösen</option><option value="none">Bewusst nicht verwenden</option></select></label>
            <label class="block text-xs">Maximale Suchtreffer<input wire:model="artForm.max_docs" type="number" min="1" class="{{ $input }}" /></label>
            <label class="block text-xs">Alter Einzeldeckel (ohne Wirkung)<input disabled wire:model="artForm.max_chars_per_doc" type="number" min="1" class="{{ $input }}" /></label>
            <button type="button" wire:click="saveArt" class="{{ $btnGhostXs }}">Arten-Routing setzen</button>
        @endif
    </div>

    {{-- ── Routing-Editor ── --}}
    <div class="space-y-2" data-ws-routings>
        <div>
            <p class="{{ $dt }}">Routing — welches Feature sucht in welcher Kategorie</p>
            <p class="text-[11px] text-gray-500">
                <span class="font-medium">discovery</span> für wachsende Kategorien (gedeckelt) ·
                <span class="font-medium">always</span> nur für kleine feste Mengen ·
                <span class="font-medium">none</span> = bewusst leer · keine Zeile = search-only.
                Routings sind <span class="font-medium">global</span> und gelten für alle Teams.
            </p>
        </div>

        <table class="{{ $table }}">
            <thead><tr class="text-left">@foreach(['Feature', 'Kategorie', 'Modus', 'max Docs', 'Alter Deckel (inaktiv)', ''] as $h)<th class="{{ $th }}">{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach($routings->filter(fn ($r) => empty($r->art)) as $r)
                    <tr class="{{ $tr }}" wire:key="r-{{ $r->id }}">
                        @if($editId === $r->id)
                            <td class="{{ $td }} font-mono text-[11px] text-gray-500">{{ $r->feature }}</td>
                            <td class="{{ $td }} font-mono text-[11px] text-gray-500">{{ $r->category }}</td>
                            <td class="{{ $td }}">
                                <select wire:model="form.mode" class="{{ $input }} !py-1 w-32" data-ws-mode>
                                    @foreach(['always', 'discovery', 'grounding', 'none'] as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach
                                </select>
                            </td>
                            <td class="{{ $td }}"><input type="text" wire:model="form.max_docs" class="{{ $input }} !py-1 !w-16 text-right" placeholder="—" /></td>
                            <td class="{{ $td }}"><input type="text" disabled wire:model="form.max_chars_per_doc" class="{{ $input }} !py-1 !w-20 text-right" placeholder="—" /></td>
                            <td class="{{ $td }} whitespace-nowrap">
                                <button type="button" wire:click="save" class="{{ $btnPrimary }}" data-ws-save>Speichern</button>
                                <button type="button" wire:click="cancel" class="{{ $btnGhostXs }}">Abbrechen</button>
                            </td>
                        @else
                            <td class="{{ $td }} font-mono text-[11px] text-gray-900">
                                {{ $r->feature }}
                                @if(in_array($r->feature, $alias, true))
                                    <span class="text-[10px] text-amber-600" title="Alt-Schlüssel: dieses Feature versorgt Prompt-Keys mit anderem Namen">alt</span>
                                @endif
                            </td>
                            <td class="{{ $td }} font-mono text-[11px] text-gray-600">
                                {{ $r->category }}
                                @if(! in_array($r->category, $kategorien, true))
                                    <span class="text-[10px] text-rose-600" title="Diese Kategorie steht nicht im Vokabular — die Zeile steuert nichts">unbekannt</span>
                                @endif
                            </td>
                            <td class="{{ $td }} text-[11px] {{ $r->mode === 'none' ? 'text-gray-400' : 'text-gray-700' }}">{{ $r->mode }}</td>
                            <td class="{{ $td }} text-[11px] text-gray-600 text-right">{{ $r->max_docs ?? '—' }}</td>
                            <td class="{{ $td }} text-[11px] text-gray-600 text-right">{{ $r->max_chars_per_doc ? number_format($r->max_chars_per_doc, 0, ',', '.') : '—' }}</td>
                            <td class="{{ $td }} whitespace-nowrap">
                                @if($darfSchreiben)
                                    <button type="button" wire:click="edit({{ $r->id }})" class="{{ $btnGhostXs }}" data-ws-edit>Bearbeiten</button>
                                    <button type="button" wire:click="delete({{ $r->id }})" wire:confirm="Routing {{ $r->feature }} × {{ $r->category }} entfernen? Die Kategorie ist danach search-only." class="{{ $btnGhostXs }} text-red-500">Entfernen</button>
                                @else
                                    <span class="text-[10px] text-gray-400">global — nur Master</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($darfSchreiben)
            <div class="rounded-lg bg-black/[0.03] px-3 py-2 space-y-1.5" data-ws-neu>
                <p class="{{ $dt }}">Neues Routing</p>
                <div class="flex flex-wrap items-start gap-2">
                    <input type="text" wire:model="form.feature" placeholder="Feature (z. B. recipe.geschmack)" class="{{ $input }} !py-1 w-56" data-ws-neu-feature />
                    <select wire:model="form.category" class="{{ $input }} !py-1 w-48" data-ws-neu-kategorie>
                        <option value="">Kategorie …</option>
                        @foreach($kategorien as $k)<option value="{{ $k }}">{{ $k }}</option>@endforeach
                    </select>
                    <select wire:model="form.mode" class="{{ $input }} !py-1 w-32">
                        @foreach(['always', 'discovery', 'grounding', 'none'] as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach
                    </select>
                    <input type="text" wire:model="form.max_docs" placeholder="Docs" class="{{ $input }} !py-1 !w-16 text-right" />
                    <input type="text" disabled wire:model="form.max_chars_per_doc" placeholder="Zeichen" class="{{ $input }} !py-1 !w-24 text-right" />
                    <button type="button" wire:click="save" class="{{ $btnPrimary }}" data-ws-neu-anlegen>+ Setzen</button>
                </div>
            </div>
        @endif
    </div>
</div>
