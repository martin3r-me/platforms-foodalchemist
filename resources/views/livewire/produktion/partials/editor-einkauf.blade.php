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
                                @if($z->value === 'cancelled') onclick="return confirm('Produktion stornieren? Offene Einkaufsentwürfe werden neu berechnet; bereits ausgelöste Bestellungen bleiben als Klärfall bestehen.')" @endif
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

            <x-foodalchemist::modal-section title="Materialbedarf">
                <x-slot:actions>
                    @if($ops['is_owned'] && in_array($ops['status'], ['planned', 'in_progress'], true))
                        <button type="button" wire:click="materialbedarfFreigeben" class="{{ $btnGhostXs }}" data-materialbedarf-freigeben>{{ $ops['procurement_released_at'] ? 'Erneut freigeben' : 'Freigeben' }}</button>
                    @endif
                    @if($ops['procurement_released_at'])
                        <a href="{{ route('foodalchemist.orders.index', ['sicht' => 'bedarfe', 'p' => $ops['id']]) }}" class="{{ $btnGhostXs }}">Im Einkauf öffnen</a>
                    @endif
                    @if(\Illuminate\Support\Facades\Route::has('foodalchemist.produktion.auftraege.dokument'))
                        <a href="{{ route('foodalchemist.produktion.auftraege.dokument', ['order' => $ops['id']]) }}" target="_blank" class="{{ $btnGhostXs }}" title="Produktionsschein + Einkauf">@svg('heroicon-o-printer', 'w-3.5 h-3.5') Doku</a>
                    @endif
                </x-slot:actions>
                @if(! empty($ops['procurement_stale']))
                    <x-foodalchemist::alert tone="warning">Ziele wurden seit der Freigabe geändert. Der Einkauf verwendet den alten Stand, bis der Bedarf erneut freigegeben wird.</x-foodalchemist::alert>
                @elseif(! empty($ops['procurement_released_at']))
                    <x-foodalchemist::alert tone="success">Materialbedarf ist für das Bestellwesen freigegeben.</x-foodalchemist::alert>
                @else
                    <p class="text-[12px] text-gray-500">Die Produktion plant Mengen und Termine. Lieferanten, Gebinde und Bestellungen werden erst nach der Freigabe im Bestellwesen festgelegt.</p>
                @endif
                @if(! empty($ops['procurement_cancel_warning']))
                    <x-foodalchemist::alert tone="warning">Mindestens eine Bestellung wurde bereits ausgelöst. Bitte die Lieferanten informieren und die Belege anschließend als storniert bestätigen.</x-foodalchemist::alert>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach(collect($ops['verknuepfte_orders'])->whereIn('status', ['sent', 'confirmed']) as $linkedOrder)
                            @if($linkedOrder['cancellation_mailto'])
                                <a href="{{ $linkedOrder['cancellation_mailto'] }}" class="{{ $btnGhostXs }} text-rose-500">@svg('heroicon-o-envelope', 'w-3.5 h-3.5') {{ $linkedOrder['cancellation_kind'] === 'partial' ? 'Änderung' : 'Storno' }} an {{ $linkedOrder['supplier'] }}</a>
                            @else
                                <span class="{{ $btnGhostXs }} opacity-40 cursor-not-allowed" title="Beim Lieferanten fehlt die Bestell-E-Mail">@svg('heroicon-o-envelope', 'w-3.5 h-3.5') {{ $linkedOrder['supplier'] }}</span>
                            @endif
                        @endforeach
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
                    @if($ops['procurement_released_at'])<p class="text-[12px] text-gray-500 mt-2">Im Bestellwesen noch nicht verplant.</p>@endif
                @endif
            </x-foodalchemist::modal-section>
        @endif
    </div>{{-- /Einkauf-Panel --}}
