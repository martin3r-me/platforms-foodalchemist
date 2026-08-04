{{-- Foodbook-Detail-Panel (rechts, read-only Info). Logo oben · Status/Datum/Nummer · Eckdaten.
     Erwartet: $fb (detail-geladen mit chapters). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($statusLabel = ['draft' => 'Entwurf', 'aktiv' => 'Aktiv', 'versendet' => 'Versendet', 'archiviert' => 'Archiviert'])
@php($statusVariant = ['draft' => 'secondary', 'aktiv' => 'success', 'versendet' => 'info', 'archiviert' => 'secondary'])
@php($niveauLabel = ['klassisch' => 'Klassisch', 'gehoben' => 'Gehoben', 'haute_cuisine' => 'Haute Cuisine'])
@php($logoUrl = $fb->logo_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($fb->logo_path) : null)
@php($phasen = \Platform\FoodAlchemist\Services\PhaseService::LABELS)

<div class="p-4 space-y-5">
    @if($logoUrl)
        <div class="flex justify-center pt-1">
            <img src="{{ $logoUrl }}" alt="Logo" class="max-h-16 max-w-[70%] object-contain" />
        </div>
    @endif

    <div>
        <div class="{{ $label }} mb-2">Info</div>
        <dl class="space-y-2 text-xs">
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Status</dt>
                <dd><span class="{{ $pill }} {{ $variantPill[$fb->statusWert()->badgeVariant()] }}">{{ $fb->statusWert()->label() }}</span></dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Erstellt</dt>
                <dd class="text-gray-900 tabular-nums">{{ $fb->created_at?->format('d.m.Y') ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Geändert</dt>
                <dd class="text-gray-900 tabular-nums">{{ $fb->updated_at?->format('d.m.Y') ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Nummer</dt>
                <dd class="text-gray-900 tabular-nums">{{ $fb->code ?: '#' . $fb->id }}</dd>
            </div>
        </dl>
    </div>

    <div>
        <div class="{{ $label }} mb-2">Eckdaten</div>
        <dl class="space-y-2 text-xs">
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Kunde</dt>
                <dd class="text-gray-900 text-right truncate">{{ $fb->crmCompany?->display_name ?? 'ohne Kunde' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Jahr</dt>
                <dd class="text-gray-900 tabular-nums">{{ $fb->jahr ?: '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Pax</dt>
                <dd class="text-gray-900 tabular-nums">{{ $fb->personen ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Kapitel</dt>
                <dd class="text-gray-900 tabular-nums">{{ $fb->chapters->count() }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Phase</dt>
                <dd class="text-gray-900 text-right">{{ $phasen[$fb->phase] ?? $fb->phase }}</dd>
            </div>
            @if($fb->default_niveau)
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-gray-500">Niveau</dt>
                    <dd class="text-gray-900 text-right">{{ $niveauLabel[$fb->default_niveau] ?? $fb->default_niveau }}</dd>
                </div>
            @endif
        </dl>
    </div>
</div>
