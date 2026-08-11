{{-- Speisekarte-Detail-Panel (rechts, read-only Info). Logo oben · Status/Datum/Nummer · Eckdaten.
     Erwartet: $karte (detail-geladen mit sections.items + outlet). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($statusLabel = ['entwurf' => 'Entwurf', 'aktiv' => 'Aktiv', 'veroeffentlicht' => 'Veröffentlicht', 'archiviert' => 'Archiviert'])
@php($statusVariant = ['entwurf' => 'secondary', 'aktiv' => 'success', 'veroeffentlicht' => 'primary', 'archiviert' => 'secondary'])
@php($typLabel = ['alacarte' => 'À la carte', 'tageskarte' => 'Tageskarte', 'saisonkarte' => 'Saisonkarte', 'getraenkekarte' => 'Getränkekarte', 'weinkarte' => 'Weinkarte'])
@php($logoUrl = $karte->logo_path ? app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($karte->logo_context_file_id, $karte->logo_path) : null)
@php($rubrikenN = $karte->sections->count())
@php($positionenN = $karte->sections->flatMap->items->count())
@php($gueltig = ($karte->gueltig_von || $karte->gueltig_bis)
    ? trim(($karte->gueltig_von ? 'ab ' . $karte->gueltig_von->format('d.m.Y') : '') . ($karte->gueltig_bis ? ' bis ' . $karte->gueltig_bis->format('d.m.Y') : ''))
    : 'ohne Befristung')

<div class="p-4 space-y-5">
    {{-- Logo oben (falls Branding) --}}
    @if($logoUrl)
        <div class="flex justify-center pt-1">
            <img src="{{ $logoUrl }}" alt="Logo" class="max-h-16 max-w-[70%] object-contain" />
        </div>
    @endif

    {{-- INFO --}}
    <div>
        <div class="{{ $label }} mb-2">Info</div>
        <dl class="space-y-2 text-xs">
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Status</dt>
                <dd><span class="{{ $pill }} {{ $variantPill[$karte->statusWert()->badgeVariant()] }}">{{ $karte->statusWert()->label() }}</span></dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Erstellt</dt>
                <dd class="text-gray-900 tabular-nums">{{ $karte->created_at?->format('d.m.Y') ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Geändert</dt>
                <dd class="text-gray-900 tabular-nums">{{ $karte->updated_at?->format('d.m.Y') ?? '—' }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Nummer</dt>
                <dd class="text-gray-900 tabular-nums">#{{ $karte->id }}</dd>
            </div>
        </dl>
    </div>

    {{-- ECKDATEN --}}
    <div>
        <div class="{{ $label }} mb-2">Eckdaten</div>
        <dl class="space-y-2 text-xs">
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Kartentyp</dt>
                <dd class="text-gray-900">{{ $typLabel[$karte->karten_typ] ?? $karte->karten_typ }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Gültigkeit</dt>
                <dd class="text-gray-900 text-right">{{ $gueltig }}</dd>
            </div>
            @if($karte->outlet)
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-gray-500">Outlet</dt>
                    <dd class="text-gray-900 text-right truncate">{{ $karte->outlet->name }}</dd>
                </div>
            @endif
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Rubriken</dt>
                <dd class="text-gray-900 tabular-nums">{{ $rubrikenN }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Positionen</dt>
                <dd class="text-gray-900 tabular-nums">{{ $positionenN }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
                <dt class="text-gray-500">Preise</dt>
                <dd class="text-gray-900">{{ $karte->preis_anzeige_brutto ? 'brutto' : 'netto' }}</dd>
            </div>
        </dl>
    </div>
</div>
