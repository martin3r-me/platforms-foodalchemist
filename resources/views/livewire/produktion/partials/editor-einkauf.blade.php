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

                        {{-- Spec 30 E7: Löschen gab es bisher nirgends. Nur geplant/storniert —
                             ein laufender Auftrag wird storniert, ein fertiger ist Protokoll. --}}
                        @if(in_array($ops['status'], ['planned', 'cancelled'], true))
                            <button type="button" wire:click="auftragLoeschen" wire:confirm="Produktionsauftrag samt Zeilen löschen?"
                                    class="{{ $btnGhost }} text-rose-500 ml-auto" data-produktion-loeschen>Löschen</button>
                        @endif
                    </div>
                </x-foodalchemist::modal-section>
            @endif

            @php($zieleCount = count($ops['targets']))
            @php($uebergebenCount = collect($ops['targets'])->filter(fn ($t) => ! empty($zielUebergaben[$t['source_ref'] ?? '']))->count())
            <x-foodalchemist::modal-section title="Einkauf & Deckung">
                <x-slot:actions>
                    @if($ops['is_owned'] && in_array($ops['status'], ['planned', 'in_progress'], true))
                        <button type="button" wire:click="anBestellungUebergeben" class="{{ $btnGhostXs }}" data-produktion-uebergeben>→ An Bestellung übergeben</button>
                    @endif
                    <a href="{{ route('foodalchemist.orders.index', ['p' => $ops['id']]) }}" class="{{ $btnGhostXs }}" data-produktion-bestellungen-kontext>Bestellungen öffnen</a>
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
                    <div class="grid sm:grid-cols-2 gap-1.5">
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
        @endif
    </div>{{-- /Einkauf-Panel --}}
