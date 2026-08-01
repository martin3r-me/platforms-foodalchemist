{{-- Spec 18 — Produktion: Editor-Modal (Stammdaten / Ziele / Vorschau) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

@php($pzRezepte = $vorschau['rezepte'] ?? [])
@php($pzAnsaetze = collect($pzRezepte)->sum('ansaetze'))
@php($pzZeit = collect($pzRezepte)->sum(fn ($r) => (int) ($r['arbeitszeit_min'] ?? 0)))
{{-- Spec 29-Rollout: Produktion-Editor auf Editor-Page-Muster (fullscreen · dark · editor-tabs · KPI). --}}
<x-foodalchemist::modal name="produktion-editor" fullscreen dark-canvas
    :title="$orderId === null ? 'Neuer Produktionsauftrag' : 'Produktionsauftrag bearbeiten'"
    :title-name="$orderId === null ? null : ($name ?: null)">
    <x-slot:actions>
        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-produktion-speichern>Speichern</button>
        @if($fehler)<span class="text-[12px] text-rose-600 ml-2 self-center" data-produktion-fehler>{{ $fehler }}</span>@endif
    </x-slot:actions>

    {{-- KPI-Kopf: Ziele + Rezepte + Ansätze (Leitwert) + Arbeitszeit aus der Ansätze-Vorschau --}}
    <x-slot:kpiHeader>
        <x-foodalchemist::kpi-tiles marker="produktion-kpis" :tiles="[
            ['kpi' => 'ziele', 'label' => 'Ziele', 'value' => (string) count($targets)],
            ['kpi' => 'rezepte', 'label' => 'Rezepte', 'value' => (string) count($pzRezepte)],
            ['kpi' => 'ansaetze', 'label' => 'Ansätze', 'tone' => 'accent',
             'value' => $pzAnsaetze > 0 ? rtrim(rtrim(number_format((float) $pzAnsaetze, 2, ',', '.'), '0'), ',') : '—'],
            ['kpi' => 'zeit', 'label' => 'Arbeitszeit', 'value' => $pzZeit > 0 ? $pzZeit . ' min' : '—'],
        ]" />
    </x-slot:kpiHeader>

    <x-foodalchemist::editor-tabs marker="produktion" wire-key="produktion-tabs-{{ $orderId ?? 'neu' }}" :init="'stammdaten'"
        :tabs="['stammdaten' => 'Stammdaten', 'ziele' => 'Ziele', 'vorschau' => 'Vorschau', 'einkauf' => $orderId ? 'Einkauf & Status' : null]">

    <div x-show="tab === 'stammdaten'" x-cloak class="pt-4">
    <x-foodalchemist::modal-section title="Stammdaten">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="{{ $label }}">Name <span class="text-rose-500">*</span></label>
                <input type="text" wire:model="name" placeholder="z. B. Sommerfest Vormittag" class="{{ $input }}" data-produktion-name />
            </div>
            <div>
                <label class="{{ $label }}">Produktionsdatum</label>
                <input type="date" wire:model="productionDate" class="{{ $input }}" data-produktion-datum />
            </div>
        </div>
        <div class="mt-3">
            <label class="{{ $label }}">Anlass</label>
            <input type="text" wire:model="reference" placeholder="z. B. Sommer-Buffet" class="{{ $input }}" data-produktion-anlass />
        </div>
        <div class="mt-3">
            <label class="{{ $label }}">Notiz</label>
            <textarea wire:model="note" rows="2" class="{{ $input }}"></textarea>
        </div>
    </x-foodalchemist::modal-section>
    </div>{{-- /Stammdaten-Panel --}}

    <div x-show="tab === 'ziele'" x-cloak class="pt-4">
    <x-foodalchemist::modal-section title="Ziele">
        <div class="flex items-center gap-2 mb-2">
            <div class="inline-flex rounded-lg bg-black/[0.03] p-0.5 text-xs">
                <button type="button" wire:click="$set('zielTyp', 'concept')" class="px-3 py-1 rounded-md {{ $zielTyp === 'concept' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Konzept</button>
                <button type="button" wire:click="$set('zielTyp', 'recipe')" class="px-3 py-1 rounded-md {{ $zielTyp === 'recipe' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Gericht</button>
                <button type="button" wire:click="$set('zielTyp', 'basisrezept')" class="px-3 py-1 rounded-md {{ $zielTyp === 'basisrezept' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}" data-produktion-ziel-basisrezept>Basisrezept</button>
                <button type="button" wire:click="$set('zielTyp', 'kapitel')" class="px-3 py-1 rounded-md {{ $zielTyp === 'kapitel' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}" data-produktion-ziel-kapitel>Kapitel</button>
            </div>
        </div>

        @if($zielTyp === 'kapitel')
            {{-- P2: Foodbook-Kapitel als Ziel → beim Hinzufügen in eingefrorene Einzel-Ziele expandiert (V2). --}}
            <div class="space-y-2 mb-3" data-produktion-kapitel>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="{{ $label }}">Foodbook</label>
                        <select wire:model.live="auswahlFoodbookId" class="{{ $input }}" data-produktion-foodbook>
                            <option value="">— wählen —</option>
                            @foreach($foodbooks as $fb)
                                <option value="{{ $fb->id }}">{{ $fb->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $label }}">Kapitel</label>
                        <select wire:model.live="auswahlChapterId" @disabled(! $auswahlFoodbookId) class="{{ $input }}" data-produktion-kapitel-select>
                            <option value="">— wählen —</option>
                            @foreach($kapitelBaum as $k)
                                <option value="{{ $k['id'] }}">{!! str_repeat('&nbsp;&nbsp;', $k['depth']) !!}{{ $k['title'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="w-28">
                    <label class="{{ $label }}">Personen</label>
                    <input type="number" min="1" wire:model="auswahlPersonen" class="{{ $input }}" data-produktion-kapitel-personen />
                </div>
                @if(! empty($variantGroups))
                    <div class="rounded-lg border border-black/5 bg-black/[0.02] p-2 space-y-2" data-produktion-varianten>
                        <p class="{{ $label }}">Varianten-Wahl (Wahl-Gruppen im Kapitel)</p>
                        @foreach($variantGroups as $g)
                            <select wire:model="variantChoices.{{ $g['group_id'] }}" class="{{ $input }}" wire:key="vg-{{ $g['group_id'] }}" data-produktion-variante="{{ $g['group_id'] }}">
                                @foreach($g['options'] as $opt)
                                    <option value="{{ $opt['block_id'] }}">{{ $opt['label'] }}</option>
                                @endforeach
                            </select>
                        @endforeach
                    </div>
                @endif
                <button type="button" wire:click="zielHinzufuegen" class="{{ $btnGhost }}" data-produktion-ziel-hinzufuegen>+ Kapitel-Ziele hinzufügen</button>
            </div>
        @else
        {{-- Concepter-/Foodbook-Muster: Menge-Kontext + Suche + browsebare Kandidatenliste mit „+".
             „+" fügt den Kandidaten mit der aktuellen Menge als Ziel hinzu und bleibt offen. --}}
        <div class="space-y-2 mb-3" data-produktion-picker>
            <div class="flex items-end gap-2">
                <div class="w-32">
                    <label class="{{ $label }}">{{ $zielTyp === 'concept' ? 'Personen' : ($zielTyp === 'basisrezept' ? ($basisEinheit === 'kg' ? 'Kilogramm' : 'Ansätze') : 'Portionen') }}</label>
                    <input type="number" min="0" step="{{ $zielTyp === 'basisrezept' ? '0.1' : '1' }}" wire:model="auswahlMenge" class="{{ $input }}" data-produktion-menge />
                </div>
                @if($zielTyp === 'basisrezept')
                    <div class="inline-flex rounded-lg bg-black/[0.03] p-0.5 text-xs mb-0.5" data-produktion-basis-einheit>
                        <button type="button" wire:click="$set('basisEinheit', 'ansaetze')" class="px-2 py-1 rounded-md {{ $basisEinheit === 'ansaetze' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Ansätze</button>
                        <button type="button" wire:click="$set('basisEinheit', 'kg')" class="px-2 py-1 rounded-md {{ $basisEinheit === 'kg' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">kg</button>
                    </div>
                @endif
                <div class="flex-1">
                    <label class="{{ $label }}">{{ $zielTyp === 'concept' ? 'Konzept' : ($zielTyp === 'basisrezept' ? 'Basisrezept' : 'Gericht') }} suchen</label>
                    <input type="search" wire:model.live.debounce.300ms="suche" placeholder="Suchen … (aus der Liste mit + einfügen)" class="{{ $input }}" data-produktion-gericht-suche />
                </div>
            </div>
            <div class="rounded-lg border border-white/10 max-h-56 overflow-y-auto divide-y divide-white/5" data-produktion-kandidaten>
                @forelse($kandidaten as $k)
                    <button type="button" wire:key="pk-{{ $zielTyp }}-{{ $k->id }}" wire:click="zielAusListe({{ $k->id }})"
                        class="w-full flex items-center justify-between gap-2 px-2.5 py-1.5 text-[12px] text-left hover:bg-violet-500/10" data-produktion-kandidat="{{ $k->id }}">
                        <span class="truncate text-gray-900">{{ $k->name }}</span>
                        <span class="text-violet-500 font-medium shrink-0">+</span>
                    </button>
                @empty
                    <p class="text-[12px] text-gray-500 px-2.5 py-3 text-center">{{ trim((string) $suche) !== '' ? 'Kein Treffer.' : 'Nichts vorhanden.' }}</p>
                @endforelse
            </div>
        </div>
        @endif

        <div class="space-y-1">
            @forelse($targets as $t)
                <div class="flex items-center justify-between gap-2 text-[12px] px-2 py-1 rounded-lg bg-black/[0.02]" wire:key="ziel-{{ $t['source_ref'] }}">
                    <span class="text-gray-800">{{ $t['label'] ?? '—' }}</span>
                    <div class="flex items-center gap-2">
                        @unless(str_contains($t['source_ref'], ':c'))
                            <button type="button" wire:click="zielBearbeiten('{{ $t['source_ref'] }}')" class="text-gray-400 hover:text-violet-600" title="Bearbeiten" data-produktion-ziel-bearbeiten>@svg('heroicon-o-pencil', 'w-3.5 h-3.5')</button>
                        @endunless
                        <button type="button" wire:click="zielEntfernen('{{ $t['source_ref'] }}')" class="text-rose-500" title="Entfernen" data-produktion-ziel-entfernen>@svg('heroicon-o-x-mark', 'w-3.5 h-3.5')</button>
                    </div>
                </div>
            @empty
                <p class="text-[12px] text-gray-500">Noch keine Ziele — Konzept, Gericht, Basisrezept oder Foodbook-Kapitel wählen und hinzufügen.</p>
            @endforelse
        </div>
    </x-foodalchemist::modal-section>
    </div>{{-- /Ziele-Panel --}}

    <div x-show="tab === 'vorschau'" x-cloak class="pt-4">
    <x-foodalchemist::modal-section title="Vorschau">
        @if($vorschau === null)
            <p class="text-[12px] text-gray-500">Ziele hinzufügen, um die Ansätze-Vorschau zu sehen.</p>
        @else
            <table class="{{ $table }}">
                <thead><tr>
                    <th class="{{ $th }} text-left">Rezept</th>
                    <th class="{{ $th }} text-right">Ansätze</th>
                    <th class="{{ $th }} text-right">Portionen/kg</th>
                    <th class="{{ $th }} text-right">Arbeitszeit</th>
                </tr></thead>
                <tbody>
                    @foreach($vorschau['rezepte'] as $r)
                        <tr class="border-t border-black/5">
                            <td class="{{ $td }}">{{ $r['name'] }} @if($r['ist_basisrezept'])<span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1">Basisrezept</span>@endif</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ rtrim(rtrim(number_format($r['ansaetze'], 2, ',', '.'), '0'), ',') }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $r['portionen'] !== null ? $r['portionen'] . ' Port.' : ($r['produzierte_menge_kg'] !== null ? number_format($r['produzierte_menge_kg'], 2, ',', '.') . ' kg' : '—') }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $r['arbeitszeit_min'] !== null ? $r['arbeitszeit_min'] . ' min' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @foreach($vorschau['warnungen'] as $w)
                <x-foodalchemist::alert tone="warning" class="mt-2">{{ $w }}</x-foodalchemist::alert>
            @endforeach
        @endif
    </x-foodalchemist::modal-section>
    </div>{{-- /Vorschau-Panel --}}

    {{-- ═══ Tab: EINKAUF & STATUS (aus DetailPanel gemergt — nur bestehender Auftrag) ═══ --}}
    <div x-show="tab === 'einkauf'" x-cloak class="pt-4 space-y-4">
        @if($ops === null)
            <p class="text-[12px] text-gray-500">Auftrag zuerst speichern — dann erscheinen Status, Bestell-Übergabe und Deckungsgrad.</p>
        @else
            @if($hinweis)<div class="{{ $sectionCard }} !bg-emerald-500/[0.06] !border-emerald-500/20 text-[12px] text-emerald-700" data-produktion-hinweis>✓ {{ $hinweis }}</div>@endif

            @if($ops['is_owned'] && count($erlaubteStatus) > 0)
                <x-foodalchemist::modal-section title="Status">
                    @php($statusAktion = ['in_progress' => 'Produktion starten', 'done' => 'Fertig melden', 'cancelled' => 'Stornieren'])
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="{{ $pill }} font-medium {{ $variantPill[\Platform\FoodAlchemist\Enums\ProductionOrderStatus::from($ops['status'])->badgeVariant()] ?? $variantPill['secondary'] }} mr-1">{{ $ops['status_label'] }}</span>
                        @foreach($erlaubteStatus as $z)
                            <button type="button" wire:click="setStatus('{{ $z->value }}')"
                                class="{{ in_array($z->value, ['in_progress', 'done'], true) ? $btnPrimary : $btnGhost }}"
                                @if($z->value === 'cancelled') onclick="return confirm('Produktion stornieren?')" @endif
                                data-produktion-status="{{ $z->value }}">{{ $statusAktion[$z->value] ?? $z->label() }}</button>
                        @endforeach
                    </div>
                </x-foodalchemist::modal-section>
            @endif

            @php($zieleCount = count($ops['targets']))
            @php($uebergebenCount = collect($ops['targets'])->filter(fn ($t) => ! empty($zielUebergaben[$t['source_ref'] ?? '']))->count())
            <x-foodalchemist::modal-section title="Einkauf">
                <x-slot:actions>
                    @if($ops['is_owned'] && in_array($ops['status'], ['planned', 'in_progress'], true))
                        <button type="button" wire:click="anBestellungUebergeben" class="{{ $btnGhostXs }}" data-produktion-uebergeben>→ An Bestellung übergeben</button>
                    @endif
                    @if(\Illuminate\Support\Facades\Route::has('foodalchemist.produktion.auftraege.dokument'))
                        <a href="{{ route('foodalchemist.produktion.auftraege.dokument', ['order' => $ops['id']]) }}" target="_blank" class="{{ $btnGhostXs }}" title="Produktionsschein + Einkauf">@svg('heroicon-o-printer', 'w-3.5 h-3.5') Doku</a>
                    @endif
                </x-slot:actions>
                @if(! empty($ops['einkauf_veraltet']))
                    <div class="mb-2" data-einkauf-veraltet="1"><x-foodalchemist::alert tone="warning">Bestellung veraltet — Ziele seit der letzten Übergabe geändert. Erneut übergeben.</x-foodalchemist::alert></div>
                @endif
                @if($zieleCount > 0)
                    <div class="flex items-center justify-between gap-2 text-[12px] mb-2" data-einkauf-deckung="{{ $uebergebenCount }}/{{ $zieleCount }}">
                        <span class="text-gray-500">Deckungsgrad</span>
                        <span class="{{ $pill }} font-medium {{ $uebergebenCount === 0 ? $variantPill['secondary'] : ($uebergebenCount >= $zieleCount ? $variantPill['success'] : $variantPill['warning']) }}">{{ $uebergebenCount }}/{{ $zieleCount }} Ziele übergeben</span>
                    </div>
                @endif
                @if($verknuepfteOrders->isNotEmpty())
                    <div class="space-y-1">
                        @foreach($verknuepfteOrders as $o)
                            <a href="{{ route('foodalchemist.orders.index', ['o' => $o->id]) }}" class="flex items-center justify-between gap-2 text-[13px] px-2 py-1.5 rounded-lg bg-black/[0.04] hover:bg-black/[0.08]" data-produktion-bestellung-link="{{ $o->id }}">
                                <span class="text-gray-900">{{ $o->supplier?->name ?? '—' }}</span>
                                <span class="flex items-center gap-2"><span class="text-gray-500 tabular-nums">{{ number_format((float) $o->total_net, 2, ',', '.') }} €</span><span class="{{ $pill }} font-medium {{ $variantPill[$o->status->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $o->status->label() }}</span></span>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="text-[12px] text-gray-500">Noch keine Bestellung — „→ An Bestellung übergeben" oben.</p>
                @endif
            </x-foodalchemist::modal-section>

            @if(! empty($ops['zeilen']))
                <x-foodalchemist::modal-section title="Küchen-Notizen">
                    <div class="space-y-2">
                        @foreach($ops['zeilen'] as $z)
                            <div class="flex items-center gap-2" wire:key="opsnote-{{ $z['id'] }}">
                                <span class="text-[12px] text-gray-900 flex-1 truncate">{{ $z['name'] }}</span>
                                <input type="text" value="{{ $z['note'] }}" placeholder="Küchen-Notiz …" wire:change="updateLineNote({{ $z['id'] }}, $event.target.value)" class="{{ $input }} !py-1 w-64" data-produktion-notiz="{{ $z['id'] }}" />
                            </div>
                        @endforeach
                    </div>
                </x-foodalchemist::modal-section>
            @endif
        @endif
    </div>{{-- /Einkauf-Panel --}}
    </x-foodalchemist::editor-tabs>
</x-foodalchemist::modal>
