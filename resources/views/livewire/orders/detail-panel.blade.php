{{-- Bestellung, Detailspalte. fa-pass (2026-10-05): Anatomie Detail-Panels (DESIGN.md, Muster concepter/detail-panel).
     Kopf (Lieferant, Status, „Bestellung bearbeiten") · Kennzahlen · offene Punkte · Positionen · Angaben.
     wire:click="bearbeiten", data-orders-detail-panel und data-status unverändert. --}}
@php
    $statusTon = ['secondary' => 'neutral', 'info' => 'info', 'success' => 'ok', 'danger' => 'crit', 'warning' => 'warn', 'primary' => 'accent'];
    $status = $detail !== null ? \Platform\FoodAlchemist\Enums\OrderStatus::from($detail['status']) : null;
    $strategie = $detail !== null && ($detail['sourcing_strategy'] ?? null)
        ? (\Platform\FoodAlchemist\Enums\LeadLaStrategie::tryFrom($detail['sourcing_strategy'])?->label() ?? $detail['sourcing_strategy'])
        : 'Team-Standard';

    if ($detail !== null) {
        $zeilen = $detail['zeilen'];
        $anzahl = count($zeilen);
        $ohnePreis = collect($zeilen)->filter(fn ($z) => $z['pack_price'] === null)->count();
        $liefertag = $detail['desired_delivery_date'] ? \Carbon\Carbon::parse($detail['desired_delivery_date'])->format('d.m.Y') : null;

        $kpis = [
            $anzahl === 0
                ? ['label' => 'Netto', 'value' => 'Keine Positionen', 'tone' => 'warn', 'kpi' => 'netto']
                : ['label' => 'Netto', 'value' => number_format((float) $detail['total_net'], 2, ',', '.') . ' €', 'primary' => true, 'kpi' => 'netto',
                    'hint' => $ohnePreis > 0 ? 'unvollständig' : null, 'hint_title' => $ohnePreis > 0 ? 'Mindestens einer Position fehlt der Preis' : null],
            ['label' => 'Positionen', 'value' => (string) $anzahl, 'tone' => $anzahl === 0 ? 'warn' : null, 'kpi' => 'positionen'],
            ['label' => 'Liefertag', 'value' => $liefertag ?? 'fehlt', 'tone' => $liefertag === null ? 'crit' : null, 'kpi' => 'liefertag'],
        ];
    }
@endphp

<div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]" data-orders-detail-panel>
    @if($detail === null)
        <x-fa::empty icon="heroicon-o-cursor-arrow-rays" title="Keine Bestellung gewählt">Bestellung in der Liste anklicken, dann erscheinen hier Summe, Liefertag, Positionen und offene Punkte.</x-fa::empty>
    @else
        {{-- Kopf: Lieferant, Beleg, Status, eine Hauptaktion --}}
        <x-fa::detail-kopf :title="$detail['supplier'] ?: 'Ohne Lieferant'" :subtitle="'Bestellung ord-' . $detail['id'] . ($liefertag ? ' · Liefertag ' . $liefertag : '')">
            <x-slot:badges>
                <x-fa::badge :tone="$statusTon[$status->badgeVariant()] ?? 'neutral'" data-status="{{ $detail['status'] }}">{{ ucfirst($detail['status_label']) }}</x-fa::badge>
                @if($detail['reference'])<x-fa::badge title="Anlass">{{ $detail['reference'] }}</x-fa::badge>@endif
            </x-slot:badges>
            <x-slot:aktion>
                <x-fa::button variant="primary" size="sm" icon="heroicon-m-pencil-square" wire:click="bearbeiten">Bestellung bearbeiten</x-fa::button>
            </x-slot:aktion>
        </x-fa::detail-kopf>

        {{-- Kennzahlen: Netto ist die Hauptzahl --}}
        <x-fa::kpis :items="$kpis" />

        {{-- Offene Punkte: was vor dem Absenden zu klären ist --}}
        @if(! empty($detail['warnings']) || $ohnePreis > 0)
            <div class="flex flex-col gap-1" data-orders-detail-offen>
                @if($ohnePreis > 0)
                    <x-fa::signal tone="crit">{{ $ohnePreis }} {{ $ohnePreis === 1 ? 'Position' : 'Positionen' }} ohne Preis.</x-fa::signal>
                @endif
                @foreach($detail['warnings'] ?? [] as $warning)
                    <x-fa::signal tone="warn">{{ $warning }}</x-fa::signal>
                @endforeach
            </div>
        @endif

        <div class="flex flex-col">
            {{-- Inhalt: Positionen --}}
            <x-fa::section variant="plain" title="Positionen" icon="heroicon-o-list-bullet" :meta="$anzahl > 0 ? $anzahl : null">
                @if($anzahl === 0)
                    <x-fa::empty compact icon="heroicon-o-list-bullet" title="Noch keine Positionen">Im Editor Artikel oder Bedarfe hinzufügen.</x-fa::empty>
                @else
                    <ul class="flex flex-col">
                        @foreach($zeilen as $z)
                            <li class="flex items-start justify-between gap-3 py-1.5 border-b border-[var(--fa-line)] last:border-0" wire:key="orders-detail-zeile-{{ $z['id'] }}">
                                <span class="min-w-0">
                                    <span class="block text-[length:var(--fa-text-md)] text-[var(--fa-ink)] break-words">{{ $z['designation'] ?: 'Ohne Bezeichnung' }}</span>
                                    <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">
                                        <x-fa::menge :value="$z['qty_packs']" :decimals="2" />{{ $z['packaging_unit'] ? ' × ' . $z['packaging_unit'] : ' Gebinde' }}@if($z['article_number']) · {{ $z['article_number'] }}@endif
                                    </span>
                                </span>
                                <x-fa::money :value="$z['pack_price'] !== null ? $z['line_total'] : null" class="shrink-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-fa::section>

            {{-- Fachabschnitt: Angaben zur Bestellung --}}
            <x-fa::section variant="plain" title="Angaben" icon="heroicon-o-information-circle">
                <dl class="flex flex-col text-[length:var(--fa-text-md)]">
                    <div class="flex justify-between gap-3 py-1.5 border-b border-[var(--fa-line)]"><dt class="text-[var(--fa-ink-2)]">Anlass</dt><dd class="text-right text-[var(--fa-ink)]">{{ $detail['reference'] ?: '–' }}</dd></div>
                    <div class="flex justify-between gap-3 py-1.5 border-b border-[var(--fa-line)]"><dt class="text-[var(--fa-ink-2)]">Liefertag</dt><dd class="text-right tabular-nums text-[var(--fa-ink)]">{{ $liefertag ?? 'nicht festgelegt' }}</dd></div>
                    <div class="flex justify-between gap-3 py-1.5"><dt class="text-[var(--fa-ink-2)]">Einkaufsstrategie</dt><dd class="text-right text-[var(--fa-ink)]">{{ $strategie }}</dd></div>
                </dl>
            </x-fa::section>
        </div>
    @endif
</div>
