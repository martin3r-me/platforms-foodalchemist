{{-- M3-03: GP-DetailPanel (rechte Page-Sidebar, P-1) — Stammdaten-Sektion; Allergene/Tags/LAs folgen M3-05/07 --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="p-4 space-y-4" data-gp-panel>
    @if($gp === null)
        <div class="text-center text-sm text-gray-400 py-12">
            <div class="text-2xl mb-2">⌘</div>
            Grundprodukt in der Tabelle anklicken —<br>Details erscheinen hier.
        </div>
    @else
        <div>
            <div class="flex items-start justify-between gap-2">
                <h3 class="font-semibold tracking-tight text-gray-900 dark:text-gray-100 leading-snug">{{ $gp->name }}</h3>
                <span class="{{ $pill }} font-medium shrink-0 {{ $statusPill[$gp->status->value] ?? $statusPill['merged'] }}">{{ $gp->status->label() }}</span>
            </div>
            <p class="text-xs text-gray-400 mt-0.5">{{ $gp->hauptzutat_slug ?? '—' }}</p>
        </div>

        <dl class="grid grid-cols-2 gap-x-4 gap-y-2" data-stammdaten>
            <div><dt class="{{ $dt }}">Warengruppe</dt><dd class="{{ $dd }}">{{ $gp->warengruppe?->name ?? $gp->warengruppe_code ?? '—' }}</dd></div>
            <div><dt class="{{ $dt }}">Sub-Kategorie</dt><dd class="{{ $dd }}">{{ $gp->sub_kategorie ?? '—' }}</dd></div>
            <div><dt class="{{ $dt }}">Zustand</dt><dd class="{{ $dd }}">{{ $gp->zustand ?? '—' }}</dd></div>
            <div><dt class="{{ $dt }}">Lead-LA</dt><dd class="{{ $dd }} truncate" title="{{ $gp->leadLa?->name }}">{{ $gp->leadLa?->name ?? '—' }}</dd></div>
            <div><dt class="{{ $dt }}">LAs verknüpft</dt><dd class="{{ $dd }}">{{ $gp->n_las_total }}@if(!$gp->requires_la && $gp->n_las_total === 0) <span class="text-gray-400">(bewusst LA-frei)</span>@endif</dd></div>
            <div><dt class="{{ $dt }}">Zähleinheit</dt><dd class="{{ $dd }}">{{ $gp->preferredCountUnit?->name ?? '—' }}</dd></div>
            <div><dt class="{{ $dt }}">Garverlust-Default</dt><dd class="{{ $dd }}">{{ $gp->garverlust_default_pct !== null ? number_format((float) $gp->garverlust_default_pct, 1, ',', '.') . ' %' : '—' }}</dd></div>
            <div><dt class="{{ $dt }}">Stück-Gewicht</dt><dd class="{{ $dd }}">{{ $gp->stk_default_g !== null ? number_format((float) $gp->stk_default_g, 0, ',', '.') . ' g' : '—' }}</dd></div>
        </dl>

        @if($gp->is_derivat || $gp->is_platzhalter)
            <div class="flex gap-1.5">
                @if($gp->is_derivat)<span class="{{ $pill }} {{ $variantPill['info'] }}">Derivat{{ $gp->derivatVon ? ' von ' . $gp->derivatVon->name : '' }}</span>@endif
                @if($gp->is_platzhalter)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">Platzhalter</span>@endif
            </div>
        @endif

        {{-- Override-Layer als Vorschau (GL-01) — Effektiv-Ansicht + Sektionen kommen mit M3-04/05 --}}
        @if(count($gp->allergenOverrides()) > 0)
            <div data-allergen-overrides>
                <p class="{{ $dt }} mb-1">Allergen-Overrides</p>
                <div class="flex flex-wrap gap-1">
                    @foreach($gp->allergenOverrides() as $feld => $wert)
                        <span class="{{ $pill }} {{ $wert->value === 'enthalten' ? $variantPill['danger'] : ($wert->value === 'spuren' ? $variantPill['warning'] : $variantPill['secondary']) }}"
                              title="{{ $wert->value }}">{{ ucfirst(explode('_', $feld)[0]) }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        @if(count($gp->setTags()) > 0)
            <div data-tags>
                <p class="{{ $dt }} mb-1">Eigenschaften</p>
                <div class="flex flex-wrap gap-1">
                    @foreach($gp->setTags() as $tag => $aktiv)
                        <span class="{{ $pill }} {{ $aktiv ? $variantPill['success'] : $variantPill['secondary'] }}">{{ str_replace(['is_', 'contains_', '_'], ['', 'enth. ', ' '], $tag) }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        <p class="text-[11px] text-gray-400 border-t border-black/5 dark:border-white/10 pt-2">
            UUID {{ $gp->uuid }}@if($gp->team_id === null) · global/BHG-kuratiert (D1)@endif
        </p>
    @endif
</div>
