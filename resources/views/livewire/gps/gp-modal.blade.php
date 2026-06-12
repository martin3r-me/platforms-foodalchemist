{{-- M3-09/10: GP-Modal — Naming-Builder (GL-12 AUTO-SYNC) + KI-Felder (GL-07, ki-header) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="gp-modal" :title="$neu ? 'Grundprodukt anlegen' : 'Grundprodukt bearbeiten'" size="max-w-3xl">
    @if($neu)
        <x-slot:actions>
            <div class="flex items-center gap-1.5 w-full" data-ki-naming>
                <input type="text" wire:model="kiRohtext" placeholder="Roh-Bezeichnung, z. B. Lieferanten-Text …"
                       class="{{ $input }} !w-72" />
                <button type="button" wire:click="kiVorschlagNaming"
                        class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="gp.suggest: Builder-Felder aus Roh-Bezeichnung (§6)">✨ KI-Vorschlag</button>
            </div>
        </x-slot:actions>
    @endif

    @if($fehler !== null)
        <p class="text-xs text-rose-600 dark:text-rose-400 mb-3" data-modal-fehler>{{ $fehler }}</p>
    @endif

    {{-- Naming-Builder (Neuanlage) / Name (Edit) --}}
    <x-foodalchemist::modal-section title="Benennung (§6)">
        @if($neu)
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <div class="md:col-span-1">
                    <label class="block {{ $label }} mb-1">Hauptzutat *</label>
                    <input type="text" wire:model.live.debounce.300ms="builder.hauptzutat" placeholder="z. B. Zander" class="{{ $input }}" data-builder-hauptzutat />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Zustand (§9)</label>
                    <select wire:model.live="builder.zustand" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach($zustandVocab as $z)<option value="{{ $z }}">{{ $z }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Verarbeitung</label>
                    <input type="text" wire:model.live.debounce.300ms="builder.verarbeitung" placeholder="z. B. Wuerfel 5 mm" class="{{ $input }}" />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Form</label>
                    <input type="text" wire:model.live.debounce.300ms="builder.form" placeholder="Ganz / Filet / Pueree …" class="{{ $input }}" />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Portion (§7)</label>
                    <input type="text" wire:model.live.debounce.300ms="builder.portion" placeholder="180 g" class="{{ $input }}" />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Pflichtangabe (§8)</label>
                    <input type="text" wire:model.live.debounce.300ms="builder.pflichtangabe" placeholder="3,5 % / Type 405 / 16/20" class="{{ $input }}" />
                </div>
            </div>
            <div class="flex flex-wrap gap-4 mt-2" data-zusatz-klammern>
                @foreach(['bio' => '(Bio)', 'vegan' => '(Vegan)', 'glutenfrei' => '(Glutenfrei)', 'laktosefrei' => '(Laktosefrei)'] as $flag => $klammer)
                    <label class="inline-flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                        <input type="checkbox" wire:model.live="builder.{{ $flag }}" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" />
                        {{ $klammer }}
                    </label>
                @endforeach
            </div>
        @endif

        <div class="mt-3">
            <label class="block {{ $label }} mb-1">Name {{ $neu ? '(AUTO-SYNC — Überschreiben erzeugt Drift-Warnung)' : '' }}</label>
            <input type="text" wire:model.live.debounce.300ms="manuellerName" placeholder="{{ $vorschauName }}" class="{{ $input }}" data-name-feld />
        </div>

        {{-- AUTO-SYNC-Vorschau: Name + Slug + gp_key --}}
        <div class="mt-2 rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2 space-y-0.5" data-naming-vorschau>
            <p class="text-xs text-gray-900 dark:text-gray-100 font-medium" data-vorschau-name>{{ $vorschauName !== '' ? $vorschauName : '—' }}</p>
            <p class="text-[11px] text-gray-400 font-mono">slug: {{ $vorschauSlug !== '' ? $vorschauSlug : '—' }} · gp_key: {{ $vorschauKey !== '' && $vorschauKey !== '||' ? $vorschauKey : '—' }}</p>
        </div>
        @foreach($liveFehler as $f)
            <p class="text-[11px] text-rose-600 dark:text-rose-400 mt-1" data-live-fehler>{{ $f }}</p>
        @endforeach
        @foreach($warnungen as $w)
            <p class="text-[11px] text-amber-600 dark:text-amber-400 mt-1" data-live-warnung>{{ $w }}</p>
        @endforeach
    </x-foodalchemist::modal-section>

    {{-- Klassifikation --}}
    <x-foodalchemist::modal-section title="Klassifikation">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block {{ $label }} mb-1">Warengruppe</label>
                <select wire:model.live="builder.warengruppe_code" class="{{ $input }}">
                    <option value="">—</option>
                    @foreach($warengruppen as $wg)<option value="{{ $wg->code }}">{{ $wg->code }} {{ $wg->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="block {{ $label }} mb-1">Sub-Kategorie</label>
                <input type="text" wire:model.live.debounce.300ms="builder.sub_kategorie" placeholder="z. B. 09.1 Brot & Broetchen" class="{{ $input }}" />
            </div>
        </div>
    </x-foodalchemist::modal-section>

    {{-- Derivat (§11) --}}
    <x-foodalchemist::modal-section title="Derivat (§11)">
        <label class="inline-flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
            <input type="checkbox" wire:model.live="builder.is_derivat" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" data-derivat-toggle />
            Küchen-Nebenprodukt (Schale, Saft, Parüren, Karkasse …) — <code class="text-[11px]">requires_la=0</code>, erbt Allergene LIVE vom Mutter-GP (§16)
        </label>
        @if($builder['is_derivat'])
            <div class="mt-2" data-derivat-mutter>
                <label class="block {{ $label }} mb-1">Mutter-GP</label>
                @if($builder['derivat_von_gp_id'])
                    <p class="text-xs text-gray-900 dark:text-gray-100">
                        {{ \Platform\FoodAlchemist\Models\FoodAlchemistGp::find($builder['derivat_von_gp_id'])?->name ?? '—' }}
                        <button type="button" wire:click="$set('builder.derivat_von_gp_id', null)" class="{{ $btnGhostXs }} ml-1">ändern</button>
                    </p>
                @else
                    <input type="search" wire:model.live.debounce.300ms="derivatSuche" placeholder="Mutter-GP suchen …" class="{{ $input }}" />
                    @foreach($derivatKandidaten as $kandidat)
                        <button type="button" wire:key="dk-{{ $kandidat->id }}"
                                wire:click="$set('builder.derivat_von_gp_id', {{ $kandidat->id }})"
                                class="block w-full text-left px-2 py-1 rounded text-[11px] text-gray-700 dark:text-gray-200 hover:bg-violet-500/10 transition-colors duration-150">
                            {{ $kandidat->name }}
                        </button>
                    @endforeach
                @endif
            </div>
        @endif
    </x-foodalchemist::modal-section>

    {{-- KI-Felder (M3-10, nur Edit — brauchen ein persistiertes GP) --}}
    @if(!$neu && $gp !== null)
        <x-foodalchemist::modal-section title="KI-Felder (GL-07)">
            <div class="space-y-4">
                <x-foodalchemist::ki-header label="Zustand (§9)" field="zustand"
                    :quelle="$gp->zustand_quelle" :confidence="$gp->zustand_ai_confidence !== null ? (float) $gp->zustand_ai_confidence : null"
                    :begruendung="$gp->zustand_ai_begruendung" :hasProposal="isset($kiVorschlag['zustand'])">
                    <div class="flex items-center gap-2">
                        <select wire:model.live="builder.zustand" class="{{ $input }} !w-44">
                            <option value="">—</option>
                            @foreach($zustandVocab as $z)<option value="{{ $z }}">{{ $z }}</option>@endforeach
                        </select>
                        @if(isset($kiVorschlag['zustand']))
                            <span class="{{ $pill }} {{ $variantPill['primary'] }}" data-zustand-vorschlag>
                                Vorschlag: {{ $kiVorschlag['zustand']['werte']['zustand'] ?? '—' }} ({{ round($kiVorschlag['zustand']['confidence'] * 100) }}%)
                            </span>
                        @endif
                    </div>
                </x-foodalchemist::ki-header>

                <x-foodalchemist::ki-header label="Eigenschafts-Tags" field="tags"
                    :quelle="$gp->tag_quelle" :confidence="$gp->tag_ai_confidence !== null ? (float) $gp->tag_ai_confidence : null"
                    :begruendung="$gp->tag_ai_begruendung" :hasProposal="isset($kiVorschlag['tags'])">
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-x-3 gap-y-1.5" data-tags-grid>
                        @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistGp::TAG_FIELDS as $tag)
                            <div class="flex items-center justify-between gap-1">
                                <span class="text-[11px] text-gray-500 dark:text-gray-400 truncate">{{ str_replace(['is_', 'contains_', '_'], ['', 'enth. ', ' '], $tag) }}</span>
                                <select wire:model.live="tags.{{ $tag }}" class="bg-transparent border-0 text-[11px] text-gray-700 dark:text-gray-200 cursor-pointer focus:ring-0 py-0">
                                    <option value="">unbewertet</option>
                                    <option value="1">ja</option>
                                    <option value="0">nein</option>
                                </select>
                            </div>
                        @endforeach
                    </div>
                </x-foodalchemist::ki-header>
            </div>
        </x-foodalchemist::modal-section>
    @endif

    <x-slot:footer>
        <div class="flex items-center justify-between gap-3 w-full">
            <label class="inline-flex items-center gap-1.5 text-[11px] text-gray-400" title="GT-12-10: HARD_STOP bei vorhandenem gp_key/Jaccard ≥ 0.92 — force legt bewusst trotzdem an">
                @if($neu)<input type="checkbox" wire:model.live="force" class="rounded border-gray-300 text-rose-500 focus:ring-rose-400" data-force-flag /> bewusst trotzdem anlegen (force)@endif
            </label>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="$dispatch('modal.close', { name: 'gp-modal' })" class="{{ $btnGhost }}">Abbrechen</button>
                <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-gp-speichern>
                    {{ $neu ? 'Anlegen' : 'Speichern' }}
                </button>
            </div>
        </div>
    </x-slot:footer>
</x-foodalchemist::modal>
