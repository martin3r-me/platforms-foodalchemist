{{-- Speiseplan-Detail-Panel (rechts, read-only Info). Kein Branding → Plan-Name oben. Erwartet: $plan. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($statusLabel = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'aktiv' => 'Aktiv', 'archiviert' => 'Archiviert'])
@php($statusVariant = ['draft' => 'secondary', 'active' => 'success', 'aktiv' => 'success', 'archiviert' => 'secondary'])

<div class="p-4 space-y-5">
    <div class="text-center pt-1">
        <div class="{{ $label }}">Speiseplan</div>
        <div class="text-sm font-semibold text-gray-900 mt-0.5">{{ $plan->name }}</div>
    </div>

    <div>
        <div class="{{ $label }} mb-2">Info</div>
        <dl class="space-y-2 text-xs">
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Status</dt>
                <dd><span class="{{ $pill }} {{ $variantPill[$plan->statusWert()->badgeVariant()] }}">{{ $plan->statusWert()->label() }}</span></dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Erstellt</dt>
                <dd class="text-gray-900 tabular-nums">{{ $plan->created_at?->format('d.m.Y') ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Geändert</dt>
                <dd class="text-gray-900 tabular-nums">{{ $plan->updated_at?->format('d.m.Y') ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Nummer</dt>
                <dd class="text-gray-900 tabular-nums">#{{ $plan->id }}</dd>
            </div>
        </dl>
    </div>

    <div>
        <div class="{{ $label }} mb-2">Eckdaten</div>
        <dl class="space-y-2 text-xs">
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Zyklus</dt>
                <dd class="text-gray-900">{{ $plan->cycle_weeks }} {{ $plan->cycle_weeks == 1 ? 'Woche' : 'Wochen' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Einträge</dt>
                <dd class="text-gray-900 tabular-nums">{{ $plan->entries->count() }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Startdatum</dt>
                <dd class="text-gray-900 tabular-nums">{{ $plan->start_date?->format('d.m.Y') ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Wiederholung</dt>
                <dd class="text-gray-900">{{ ($plan->min_abstand_tage ?? 0) > 0 ? 'min. ' . $plan->min_abstand_tage . ' Tage' : 'aus' }}</dd>
            </div>
            @if(($plan->default_pax ?? null) !== null)
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-gray-500">Pax</dt>
                    <dd class="text-gray-900 tabular-nums">{{ $plan->default_pax }}</dd>
                </div>
            @endif
            @if(($plan->budget_wareneinsatz ?? null) !== null)
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-gray-500">Budget</dt>
                    <dd class="text-gray-900 tabular-nums">{{ number_format((float) $plan->budget_wareneinsatz, 2, ',', '.') }} €</dd>
                </div>
            @endif
        </dl>
    </div>
</div>
