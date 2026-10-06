    <div x-show="tab === 'einkauf'" x-cloak class="pt-4 flex flex-col gap-4">
        @if($ops === null)
            <x-fa::empty compact icon="heroicon-o-shopping-cart" title="Noch nicht gespeichert">Auftrag zuerst speichern, dann erscheinen Status, Bestell-Übergabe und Deckungsgrad.</x-fa::empty>
        @else
            @if($hinweis)<x-fa::notice tone="ok" data-produktion-hinweis>{{ $hinweis }}</x-fa::notice>@endif

            @if($ops['is_owned'] && count($erlaubteStatus) > 0)
                @php
                    $statusAktion = ['in_progress' => 'Produktion starten', 'done' => 'Fertig melden', 'cancelled' => 'Stornieren'];
                    $statusIcon = ['in_progress' => 'heroicon-m-play', 'done' => 'heroicon-m-check', 'cancelled' => 'heroicon-m-x-circle'];
                    $aktuellerStatus = \Platform\FoodAlchemist\Enums\ProductionOrderStatus::from($ops['status']);
                @endphp
                <x-fa::section title="Status" icon="heroicon-o-arrow-path">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="{{ $leise }}">Aktuell</span>
                        <x-fa::badge :tone="$statusTon[$aktuellerStatus->badgeVariant()] ?? 'neutral'" class="mr-2">{{ ucfirst($ops['status_label']) }}</x-fa::badge>
                        @foreach($erlaubteStatus as $z)
                            <x-fa::button wire:click="setStatus('{{ $z->value }}')" wire:key="estatus-{{ $z->value }}"
                                :variant="$z->value === 'cancelled' ? 'danger' : 'secondary'" size="sm" :icon="$statusIcon[$z->value] ?? null"
                                :class="$z->value === 'cancelled' ? 'ml-auto' : ''"
                                :onclick="$z->value === 'cancelled' ? 'return confirm(\'Produktion stornieren? Offene Einkaufsentwürfe werden neu berechnet; bereits ausgelöste Bestellungen bleiben als Klärfall bestehen.\')' : null"
                                data-produktion-status="{{ $z->value }}">{{ $statusAktion[$z->value] ?? ucfirst($z->label()) }}</x-fa::button>
                        @endforeach
                    </div>
                </x-fa::section>
            @endif

            <x-fa::section title="Materialbedarf" icon="heroicon-o-archive-box-arrow-down">
                <x-slot:actions>
                    @if($ops['procurement_released_at'])
                        <x-fa::button size="sm" variant="ghost" icon="heroicon-o-shopping-cart" :href="route('foodalchemist.orders.index', ['sicht' => 'bedarfe', 'p' => $ops['id']])">Im Einkauf öffnen</x-fa::button>
                    @endif
                    @if($ops['is_owned'] && in_array($ops['status'], ['planned', 'in_progress'], true))
                        <x-fa::button size="sm" variant="secondary" icon="heroicon-o-check-circle" wire:click="materialbedarfFreigeben" data-materialbedarf-freigeben>{{ $ops['procurement_released_at'] ? 'Bedarf erneut freigeben' : 'Bedarf freigeben' }}</x-fa::button>
                    @endif
                </x-slot:actions>

                @if(! empty($ops['procurement_stale']))
                    <x-fa::notice tone="warn" title="Ziele geändert">Seit der Freigabe wurden Ziele geändert. Der Einkauf verwendet den alten Stand, bis der Bedarf erneut freigegeben wird.</x-fa::notice>
                @elseif(! empty($ops['procurement_released_at']))
                    <x-fa::notice tone="ok">Materialbedarf ist für das Bestellwesen freigegeben.</x-fa::notice>
                @else
                    <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] max-w-[70ch]">Die Produktion plant Mengen und Termine. Lieferanten, Gebinde und Bestellungen werden erst nach der Freigabe im Bestellwesen festgelegt.</p>
                @endif

                @if(! empty($ops['procurement_cancel_warning']))
                    <x-fa::notice tone="warn" title="Bestellungen bereits ausgelöst">Bitte die Lieferanten informieren und die Belege anschließend als storniert bestätigen.</x-fa::notice>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach(collect($ops['verknuepfte_orders'])->whereIn('status', ['sent', 'confirmed']) as $linkedOrder)
                            @if($linkedOrder['cancellation_mailto'])
                                <x-fa::button size="sm" variant="danger" icon="heroicon-o-envelope" :href="$linkedOrder['cancellation_mailto']">{{ $linkedOrder['cancellation_kind'] === 'partial' ? 'Änderung' : 'Storno' }} an {{ $linkedOrder['supplier'] }}</x-fa::button>
                            @else
                                <span class="inline-flex items-center gap-1.5 h-7 px-2.5 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] cursor-not-allowed" title="Beim Lieferanten fehlt die Bestell-E-Mail">@svg('heroicon-o-envelope', 'w-3.5 h-3.5') {{ $linkedOrder['supplier'] }}: E-Mail fehlt</span>
                            @endif
                        @endforeach
                    </div>
                @endif

                @if($verknuepfteOrders->isNotEmpty())
                    <div class="flex flex-col gap-1.5">
                        <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Bestellungen aus diesem Auftrag</p>
                        <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-1.5">
                            @foreach($verknuepfteOrders as $o)
                                <a href="{{ route('foodalchemist.orders.index', ['o' => $o->id]) }}" wire:key="pbest-{{ $o->id }}"
                                   class="flex items-center justify-between gap-2 px-3 py-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)] hover:bg-[var(--fa-hover)] text-[length:var(--fa-text-md)]" data-produktion-bestellung-link="{{ $o->id }}">
                                    <span class="min-w-0 truncate font-medium text-[var(--fa-ink)]" title="{{ $o->supplier?->name }}">{{ $o->supplier?->name ?? 'Lieferant unbekannt' }}</span>
                                    <span class="flex items-center gap-2 shrink-0">
                                        <x-fa::money :value="$o->total_net" class="text-[var(--fa-ink-2)]" />
                                        <x-fa::badge :tone="$statusTon[$o->status->badgeVariant()] ?? 'neutral'">{{ ucfirst($o->status->label()) }}</x-fa::badge>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @elseif($ops['procurement_released_at'])
                    <p class="{{ $leise }}">Im Bestellwesen noch nicht verplant.</p>
                @endif
            </x-fa::section>
        @endif
    </div>{{-- /Einkauf-Panel --}}
