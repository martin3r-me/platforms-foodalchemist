@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="p-4 min-h-full bg-[var(--ui-muted-5)]" data-orders-detail-panel>
    @if($detail === null)
        <div class="py-12 text-center text-[12px] text-gray-500">Bestellung in der Liste auswählen.</div>
    @else
        <div class="space-y-4">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <h3 class="text-[15px] font-semibold text-gray-900 truncate">{{ $detail['supplier'] }}</h3>
                    <p class="text-[11px] text-gray-500">Bestellung #{{ $detail['id'] }}@if($detail['desired_delivery_date']) · {{ \Carbon\Carbon::parse($detail['desired_delivery_date'])->format('d.m.Y') }}@endif</p>
                </div>
                <span class="{{ $pill }} {{ $variantPill[\Platform\FoodAlchemist\Enums\OrderStatus::from($detail['status'])->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $detail['status_label'] }}</span>
            </div>

            <div class="rounded-lg bg-[var(--ui-surface)] border border-[var(--ui-border)] divide-y divide-[var(--ui-border)] text-[12px]">
                <div class="flex justify-between gap-2 px-3 py-2"><span class="text-gray-500">Positionen</span><span class="font-medium tabular-nums">{{ count($detail['zeilen']) }}</span></div>
                <div class="flex justify-between gap-2 px-3 py-2"><span class="text-gray-500">Netto</span><span class="font-medium tabular-nums">{{ number_format($detail['total_net'], 2, ',', '.') }} €</span></div>
                <div class="flex justify-between gap-2 px-3 py-2"><span class="text-gray-500">Referenz</span><span class="text-right text-gray-900">{{ $detail['reference'] ?: '—' }}</span></div>
            </div>

            @if(!empty($detail['warnings']))
                <div class="space-y-1">
                    @foreach($detail['warnings'] as $warning)<x-foodalchemist::alert tone="warning">{{ $warning }}</x-foodalchemist::alert>@endforeach
                </div>
            @endif

            <button type="button" wire:click="bearbeiten" class="{{ $btnPrimary }} w-full">@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5') Bearbeiten</button>
        </div>
    @endif
</div>
