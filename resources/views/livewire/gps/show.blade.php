{{-- GP-Detail — Content custom nach DESIGN.md (Linear/Raycast), Shell-Komponenten bleiben x-ui --}}
{{-- M0-12: alle Dichte-/Klassen-Maps zentral aus Ui::maps() (keine Insellösungen) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="{{ $gp->name }}" icon="heroicon-o-cube" />
    </x-slot:navbar>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-5">

        {{-- Breadcrumb (custom) --}}
        <nav class="text-sm text-gray-400">
            <a href="{{ route('foodalchemist.gps.index') }}"
               class="hover:text-violet-600 dark:hover:text-violet-400 transition-colors duration-150">Grundprodukte</a>
            <span class="mx-1.5">/</span>
            <span class="text-gray-900 dark:text-gray-100 font-medium">{{ $gp->name }}</span>
        </nav>

        @if($gp->status === \Platform\FoodAlchemist\Enums\GpStatus::Merged && $gp->mergedInto)
            <div class="{{ $card }} p-4 border-amber-500/20">
                <p class="text-sm text-amber-600 dark:text-amber-400">
                    <span class="font-medium">Zusammengeführt</span> — dieses GP wurde gemerged in:
                    <a class="underline hover:text-amber-500" href="{{ route('foodalchemist.gps.show', $gp->mergedInto) }}">{{ $gp->mergedInto->name }}</a>
                </p>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

            {{-- Stammdaten --}}
            <div class="{{ $card }} p-5">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-3">Stammdaten</h3>
                <dl class="text-sm divide-y divide-black/5 dark:divide-white/10">
                    <div class="{{ $row }}"><dt class="{{ $dt }}">Status</dt>
                        <dd><span class="{{ $pill }} font-medium {{ $statusPill[$gp->status->value] }}">{{ $gp->status->label() }}</span></dd></div>
                    <div class="{{ $row }}"><dt class="{{ $dt }}">gp_key</dt>
                        <dd class="{{ $dd }} font-mono text-xs">{{ $gp->gp_key }}</dd></div>
                    <div class="{{ $row }}"><dt class="{{ $dt }}">Hauptzutat</dt>
                        <dd class="{{ $dd }}">{{ $gp->hauptzutat_display ?? $gp->hauptzutat_slug ?? '—' }}</dd></div>
                    <div class="{{ $row }}"><dt class="{{ $dt }}">Warengruppe</dt>
                        <dd class="{{ $dd }}">{{ $gp->warengruppe?->name ?? $gp->warengruppe_code ?? '—' }}</dd></div>
                    <div class="{{ $row }}"><dt class="{{ $dt }}">Sub-Kategorie</dt>
                        <dd class="{{ $dd }}">{{ $gp->sub_kategorie ?? '—' }}</dd></div>
                    <div class="{{ $row }}"><dt class="{{ $dt }}">Zustand / Bio</dt>
                        <dd class="{{ $dd }}">{{ $gp->zustand ?? '—' }}@if($gp->bio) · {{ $gp->bio }}@endif</dd></div>
                    <div class="{{ $row }}"><dt class="{{ $dt }}">Verarbeitung / Form</dt>
                        <dd class="{{ $dd }}">{{ $gp->verarbeitung ?? '—' }} / {{ $gp->form ?? '—' }}</dd></div>
                    @if($gp->is_derivat && $gp->derivatVon)
                        <div class="{{ $row }}"><dt class="{{ $dt }}">Derivat von</dt>
                            <dd class="{{ $dd }}">
                                <a class="text-violet-600 dark:text-violet-400 hover:underline" href="{{ route('foodalchemist.gps.show', $gp->derivatVon) }}">{{ $gp->derivatVon->name }}</a>
                                <span class="block text-xs text-gray-400">LIVE-Allergen-Vererbung (GL-01)</span>
                            </dd></div>
                    @endif
                </dl>
            </div>

            {{-- Lieferanten & Kalkulation --}}
            <div class="relative overflow-hidden {{ $card }} p-5">
                <div class="{{ $cardAccent }}"></div>
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-3">Lieferanten &amp; Kalkulation</h3>
                <dl class="text-sm divide-y divide-black/5 dark:divide-white/10">
                    <div class="{{ $row }}"><dt class="{{ $dt }}">Verknüpfte LAs</dt>
                        <dd class="{{ $dd }}">
                            @if($gp->n_las_total > 0) {{ $gp->n_las_total }}
                            @elseif(!$gp->requires_la) bewusst LA-frei
                            @else <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $variantPill['warning'] }}">0 — EK-/Allergen-Lücke</span>
                            @endif
                        </dd></div>
                    <div class="{{ $row }}"><dt class="{{ $dt }}">Lead-LA</dt>
                        <dd class="{{ $dd }}">
                            @if($gp->leadLa)
                                {{ $gp->leadLa->designation }}
                                <span class="block text-xs text-gray-400">{{ $gp->leadLa->supplier?->name }}</span>
                            @else
                                — <span class="text-xs text-gray-400">kein Lead (GL-03)</span>
                            @endif
                        </dd></div>
                    <div class="{{ $row }}"><dt class="{{ $dt }}">Garverlust-Default</dt>
                        <dd class="{{ $dd }}">{{ $gp->garverlust_default_pct !== null ? $gp->garverlust_default_pct.' %' : '—' }}</dd></div>
                    <div class="{{ $row }}"><dt class="{{ $dt }}">Stück-Gewicht</dt>
                        <dd class="{{ $dd }}">
                            {{ $gp->stk_default_g !== null ? $gp->stk_default_g.' g' : '—' }}
                            @if($gp->preferredCountUnit) / {{ $gp->preferredCountUnit->display_de }}@endif
                            @if($gp->stk_default_g_quelle)
                                <span class="ml-1 inline-flex px-1.5 py-0.5 rounded-full text-xs {{ $variantPill['secondary'] }}">{{ $gp->stk_default_g_quelle }}</span>
                            @endif
                        </dd></div>
                </dl>
            </div>

            {{-- Allergene (Override-Layer, GL-01) --}}
            <div class="{{ $card }} p-5">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Allergene <span class="text-gray-400 font-normal">· Override-Layer</span></h3>
                <p class="text-xs text-gray-400 mb-3">Wahrheit für Rezepte = COALESCE(Override, LA-Aggregation) — GL-01</p>
                @php $overrides = $gp->allergenOverrides(); @endphp
                @if(count($overrides) === 0)
                    <p class="text-sm text-gray-500 dark:text-gray-400">Kein Override gesetzt — Allergene kommen vollständig aus der LA-Aggregation (V-08).</p>
                @else
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($overrides as $feld => $wert)
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $variantPill[$wert->badgeVariant()] }}">
                                {{ str_replace('_', ' ', $feld) }}: {{ $wert->label() }}
                            </span>
                        @endforeach
                    </div>
                    @if($gp->allergene_quelle)
                        <p class="mt-3 text-xs text-gray-400">
                            Quelle: {{ $gp->allergene_quelle }}@if($gp->allergene_ai_confidence) · Konfidenz {{ $gp->allergene_ai_confidence }}@endif
                        </p>
                    @endif
                @endif
            </div>

            {{-- Eigenschaften --}}
            <div class="{{ $card }} p-5">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-3">Eigenschaften</h3>
                @php $tags = $gp->setTags(); @endphp
                @if(count($tags) === 0)
                    <p class="text-sm text-gray-500 dark:text-gray-400">Noch keine Tags bewertet.</p>
                @else
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($tags as $tag => $value)
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $value ? $variantPill['success'] : $variantPill['secondary'] }}">
                                {{ str_replace(['is_', 'contains_', '_'], ['', 'enthält ', ' '], $tag) }}: {{ $value ? 'ja' : 'nein' }}
                            </span>
                        @endforeach
                    </div>
                    @if($gp->tag_quelle)
                        <p class="mt-3 text-xs text-gray-400">Quelle: {{ $gp->tag_quelle }}@if($gp->tag_ai_begruendung) — {{ $gp->tag_ai_begruendung }}@endif</p>
                    @endif
                @endif
            </div>

        </div>

        {{-- Lieferantenartikel (GL-05-Sicht) --}}
        <div class="relative overflow-hidden {{ $card }}">
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Lieferantenartikel</h3>
                <span class="{{ $label }}">{{ $las->count() }} verknüpft · Lead = kalkulationsführend</span>
            </div>
            @if($las->isEmpty())
                <p class="px-5 pb-5 text-sm text-gray-500 dark:text-gray-400">
                    @if(!$gp->requires_la) Bewusst LA-frei (requires_la = 0, z.B. Derivat §11.2).
                    @else Kein Lieferantenartikel verknüpft — EK-/Allergen-Lücke (Review-Queue, V-10).
                    @endif
                </p>
            @else
                <table class="{{ $table }}">
                    <thead>
                        <tr class="text-left">
                            @foreach(['Lieferant', 'Artikel', 'Gebinde', 'Preis (aktiv)', 'Flags'] as $head)
                                <th class="{{ $th }}">{{ $head }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($las as $la)
                            <tr wire:key="la-{{ $la->structure->id }}" class="{{ $tr }}">
                                <td class="{{ $td }} text-gray-900 dark:text-gray-100">{{ $la->supplier?->name ?? '—' }}</td>
                                <td class="{{ $td }}">
                                    <span class="text-gray-900 dark:text-gray-100">{{ $la->item?->designation ?? '—' }}</span>
                                    <span class="text-xs text-gray-400 ml-1">{{ $la->item?->article_number }}</span>
                                    @if($gp->lead_la_supplier_item_id && $la->item?->id === $gp->lead_la_supplier_item_id)
                                        <span class="ml-1.5 inline-flex px-2 py-0.5 rounded-full text-xs font-medium text-white bg-gradient-to-r from-violet-500 to-indigo-500 shadow-sm shadow-violet-500/25">Lead</span>
                                    @endif
                                </td>
                                <td class="{{ $td }} text-gray-500 dark:text-gray-400">
                                    {{ $la->item?->qty !== null ? rtrim(rtrim((string) $la->item->qty, '0'), '.') : '—' }} {{ $la->item?->ordering_unit }}
                                    @if($la->item?->qty === null)
                                        <span class="ml-1 inline-flex px-1.5 py-0.5 rounded-full text-xs font-medium {{ $variantPill['warning'] }}"
                                              title="Gebinde-Menge fehlt — kein €/Einheit-Vergleich (GL-03 A-2)">qty?</span>
                                    @endif
                                </td>
                                <td class="{{ $td }} text-gray-900 dark:text-gray-100">
                                    @if($la->price?->price !== null)
                                        {{ number_format((float) $la->price->price, 2, ',', '.') }} €
                                        @if($la->price->status === '2')
                                            <span class="ml-1 inline-flex px-1.5 py-0.5 rounded-full text-xs {{ $variantPill['info'] }}">Aktion</span>
                                        @endif
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="{{ $td }} space-x-1">
                                    @if($la->structure->needs_review)
                                        <span class="inline-flex px-1.5 py-0.5 rounded-full text-xs {{ $variantPill['warning'] }}" title="{{ $la->structure->review_grund }}">Review</span>
                                    @endif
                                    @if($la->structure->ist_bio)
                                        <span class="inline-flex px-1.5 py-0.5 rounded-full text-xs {{ $variantPill['success'] }}">Bio</span>
                                    @endif
                                    @if($la->item?->is_discontinued)
                                        <span class="inline-flex px-1.5 py-0.5 rounded-full text-xs {{ $variantPill['secondary'] }}">ausgelistet</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Kuratierung & Lineage (GL-07) --}}
        <div class="{{ $card }} p-5">
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100 mb-3">Kuratierung &amp; Lineage <span class="text-gray-400 font-normal">· GL-07</span></h3>
            <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div><dt class="{{ $label }}">KI-Konfidenz</dt><dd class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $gp->ai_confidence ?? '—' }}</dd></div>
                <div><dt class="{{ $label }}">Erstmals gesehen</dt><dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $gp->first_seen_at?->format('d.m.Y') ?? '—' }}</dd></div>
                <div><dt class="{{ $label }}">Letztes Review</dt><dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $gp->last_review_at?->format('d.m.Y') ?? '—' }}</dd></div>
                <div><dt class="{{ $label }}">Scope</dt><dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $gp->team_id === null ? 'global (BHG)' : 'Team #'.$gp->team_id }}</dd></div>
            </dl>
            @if($gp->reviewer_note)
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400 border-l-2 border-violet-500/30 pl-3">{{ $gp->reviewer_note }}</p>
            @endif
        </div>

    </x-ui-page-container>
</x-ui-page>
