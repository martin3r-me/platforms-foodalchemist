{{-- Speisekarte-Detail (rechte Seitenleiste, nur lesen). Anatomie Detail-Panels (DESIGN.md, Muster concepter/detail-panel):
     Kopf mit Logo und einer Hauptaktion · Kennzahlen · offene Punkte · Rubriken · Eckdaten · Stand.
     Ausgaben (Karte drucken, digitale Karte, Bericht), Duplizieren und Löschen im Menü „Weitere Aktionen".
     Erwartet: $karte (mit sections.items + outlet geladen). Optional aus dem Seiten-Scope: $kennzahlen
     (dieselben Werte wie im Editor-Reiter „Aufbau"). --}}
@php
    $typLabel = ['alacarte' => 'À la carte', 'tageskarte' => 'Tageskarte', 'saisonkarte' => 'Saisonkarte', 'getraenkekarte' => 'Getränkekarte', 'weinkarte' => 'Weinkarte'];
    $statusTon = ['success' => 'ok', 'warning' => 'warn', 'danger' => 'crit', 'primary' => 'accent', 'info' => 'info'];
    $logoUrl = $karte->logo_path ? app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($karte->logo_context_file_id, $karte->logo_path) : null;
    $rubrikenN = $karte->sections->count();
    $positionenN = $karte->sections->flatMap->items->count();
    $gueltig = ($karte->gueltig_von || $karte->gueltig_bis)
        ? trim(($karte->gueltig_von ? 'ab ' . $karte->gueltig_von->format('d.m.Y') : '') . ($karte->gueltig_bis ? ' bis ' . $karte->gueltig_bis->format('d.m.Y') : ''))
        : 'ohne Befristung';
    $zeile = 'flex items-start justify-between gap-3 py-2 border-b border-[var(--fa-line)] last:border-b-0';
    $schluessel = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] shrink-0';
    $wert = 'text-[length:var(--fa-text-md)] text-[var(--fa-ink)] text-right min-w-0 break-words';

    // Kennzahlen aus der Seite (Editor-Reiter „Aufbau"), nach Schlüssel. Fehlt der Wareneinsatz: „fehlt", nie 0 %.
    $kz = collect($kennzahlen ?? [])->keyBy('kpi');
    $verkaufsN = (int) ($kz['positionen']['value'] ?? $karte->sections->flatMap->items->whereIn('type', ['gericht_ref', 'menue_ref'])->count());
    $ohnePreis = isset($kz['ohne-preis']) ? (int) $kz['ohne-preis']['value'] : null;
    $we = $kz['wareneinsatz'] ?? null;
    $weBekannt = $we !== null && ($we['value'] ?? 'offen') !== 'offen';
    $kpis = [
        $weBekannt
            ? ['kpi' => 'wareneinsatz', 'label' => 'Wareneinsatz', 'value' => $we['value'], 'primary' => true, 'tone' => $we['tone'] ?? null, 'title' => $we['title'] ?? null]
            : ['kpi' => 'wareneinsatz', 'label' => 'Wareneinsatz', 'value' => 'fehlt', 'tone' => 'crit', 'title' => 'Einkauf zu Verkauf über alle Positionen mit beiden Preisen'],
        ['kpi' => 'positionen', 'label' => 'Positionen', 'value' => (string) $verkaufsN, 'title' => 'Gerichte und Menüs auf der Karte'],
        ['kpi' => 'rubriken', 'label' => 'Rubriken', 'value' => (string) $karte->sections->whereNull('parent_id')->count()],
    ];

    // Offene Punkte: was vor dem Aushang noch fehlt.
    $leereRubriken = $karte->sections->filter(fn ($r) => $r->items->isEmpty() && $karte->sections->where('parent_id', $r->id)->isEmpty())->count();
    $offen = [];
    if ($rubrikenN === 0) {
        $offen[] = ['crit', 'Noch keine Rubriken angelegt.'];
    }
    if ($ohnePreis !== null && $ohnePreis > 0) {
        $offen[] = ['crit', $ohnePreis === 1 ? 'Einer Position fehlt der Preis.' : $ohnePreis . ' Positionen fehlt der Preis.'];
    }
    if ($leereRubriken > 0) {
        $offen[] = ['warn', $leereRubriken === 1 ? 'Eine Rubrik ist noch leer.' : $leereRubriken . ' Rubriken sind noch leer.'];
    }
    if ($karte->gueltig_bis && $karte->gueltig_bis->isPast() && ! $karte->gueltig_bis->isToday()) {
        $offen[] = ['warn', 'Gültigkeit abgelaufen am ' . $karte->gueltig_bis->format('d.m.Y') . '.'];
    }

    // Rubriken in Baum-Reihenfolge (Unterrubrik direkt unter ihrer Rubrik).
    $rubrikBaum = [];
    $nachEltern = $karte->sections->groupBy(fn ($r) => (int) ($r->parent_id ?? 0));
    $gehe = function (int $elternId, int $tiefe) use (&$gehe, &$rubrikBaum, $nachEltern) {
        foreach ($nachEltern[$elternId] ?? [] as $r) {
            if (isset($rubrikBaum[$r->id])) {
                continue;
            }
            $rubrikBaum[$r->id] = [$r, $tiefe];
            $gehe((int) $r->id, $tiefe + 1);
        }
    };
    $gehe(0, 0);
    foreach ($karte->sections as $r) {
        $rubrikBaum[$r->id] ??= [$r, 0];   // verwaiste Rubrik trotzdem zeigen
    }
@endphp

<div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]" data-sk-detail>
    {{-- Kopf: Name, Kartentyp, Logo, eine Hauptaktion, Weiteres im Menü --}}
    <x-fa::detail-kopf :title="$karte->name" :subtitle="$karte->crmCompany?->display_name">
        @if($logoUrl)
            <div class="mt-2 inline-flex rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-2">
                <img src="{{ $logoUrl }}" alt="Logo" class="max-h-10 max-w-[10rem] object-contain" />
            </div>
        @endif
        <x-slot:badges>
            <x-fa::badge :tone="$statusTon[$karte->statusWert()->badgeVariant()] ?? 'neutral'">{{ $karte->statusWert()->label() }}</x-fa::badge>
            <x-fa::badge title="Kartentyp">{{ $typLabel[$karte->karten_typ] ?? $karte->karten_typ }}</x-fa::badge>
            @if($karte->outlet)<x-fa::badge icon="heroicon-m-building-storefront" title="Betrieb">{{ $karte->outlet->name }}</x-fa::badge>@endif
        </x-slot:badges>
        <x-slot:aktion>
            <x-fa::button variant="primary" size="sm" icon="heroicon-m-pencil-square"
                x-on:click="$dispatch('modal.open', { name: 'speisekarte-editor' })" data-sk-panel-bearbeiten>Im Editor öffnen</x-fa::button>
        </x-slot:aktion>
        <x-slot:menue>
            <x-fa::menu-item icon="heroicon-m-printer" :href="route('foodalchemist.speisekarte.dokument', $karte->id)" target="_blank"
                title="Karte für den Gast, zum Drucken oder als PDF">Karte drucken</x-fa::menu-item>
            <x-fa::menu-item icon="heroicon-m-device-phone-mobile" :href="route('foodalchemist.speisekarte.praesentation', $karte->id)" target="_blank"
                title="Digitale Karte so ansehen, wie der Gast sie sieht">Digitale Karte ansehen</x-fa::menu-item>
            <x-fa::menu-item icon="heroicon-m-document-text" :href="route('foodalchemist.speisekarte.report', $karte->id)" target="_blank"
                title="Ausführlicher Bericht mit Preisen, Lieferanten, Deklaration und Nährwerten">Bericht öffnen</x-fa::menu-item>
            <x-fa::menu-item icon="heroicon-m-document-duplicate" wire:click="duplizieren" data-sk-panel-duplizieren>Karte duplizieren</x-fa::menu-item>
            <x-fa::menu-item danger icon="heroicon-m-trash" wire:click="loeschen" wire:confirm="Diese Speisekarte wirklich löschen?" data-sk-panel-loeschen>Karte löschen</x-fa::menu-item>
        </x-slot:menue>
    </x-fa::detail-kopf>

    {{-- Kennzahlen: Wareneinsatz ist die Hauptzahl --}}
    <div class="flex flex-col gap-3" data-sk-detail-kpis>
        <x-fa::kpis :items="$kpis" />
        @if($offen !== [])
            <div class="flex flex-col gap-1">
                @foreach($offen as [$ton, $text])
                    <x-fa::signal :tone="$ton">{{ $text }}</x-fa::signal>
                @endforeach
            </div>
        @endif
    </div>

    <div class="flex flex-col">
        {{-- Inhalt: Rubriken mit Anzahl Positionen --}}
        <x-fa::section variant="plain" title="Rubriken" icon="heroicon-o-list-bullet" :meta="$rubrikenN">
            @if($rubrikenN === 0)
                <x-fa::empty compact icon="heroicon-o-list-bullet" title="Noch keine Rubriken">Im Editor Rubriken anlegen und Gerichte oder Menüs einsetzen.</x-fa::empty>
            @else
                <ul class="flex flex-col">
                    @foreach($rubrikBaum as [$r, $tiefe])
                        @php
                            $rVerkauf = $r->items->whereIn('type', ['gericht_ref', 'menue_ref'])->count();
                        @endphp
                        <li wire:key="sk-detail-rubrik-{{ $r->id }}" class="flex items-center justify-between gap-3 py-1.5 border-b border-[var(--fa-line)] last:border-0" style="padding-left: {{ $tiefe * 12 }}px">
                            <span class="min-w-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink)] break-words">{{ $r->title ?: 'Ohne Titel' }}</span>
                            @if($r->items->isEmpty() && $karte->sections->where('parent_id', $r->id)->isEmpty())
                                <x-fa::badge tone="warn" class="shrink-0">leer</x-fa::badge>
                            @else
                                <span class="shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ $rVerkauf }} {{ $rVerkauf === 1 ? 'Position' : 'Positionen' }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-fa::section>

        {{-- Eckdaten --}}
        <x-fa::section variant="plain" title="Eckdaten" icon="heroicon-o-clipboard-document-list">
            <dl>
                <div class="{{ $zeile }}">
                    <dt class="{{ $schluessel }}">Kartentyp</dt>
                    <dd class="{{ $wert }}">{{ $typLabel[$karte->karten_typ] ?? $karte->karten_typ }}</dd>
                </div>
                <div class="{{ $zeile }}">
                    <dt class="{{ $schluessel }}">Gültigkeit</dt>
                    <dd class="{{ $wert }}">{{ $gueltig }}</dd>
                </div>
                @if($karte->outlet)
                    <div class="{{ $zeile }}">
                        <dt class="{{ $schluessel }}">Betrieb</dt>
                        <dd class="{{ $wert }}">{{ $karte->outlet->name }}</dd>
                    </div>
                @endif
                <div class="{{ $zeile }}">
                    <dt class="{{ $schluessel }}">Rubriken</dt>
                    <dd class="{{ $wert }} tabular-nums">{{ $rubrikenN }}</dd>
                </div>
                <div class="{{ $zeile }}">
                    <dt class="{{ $schluessel }}">Zeilen gesamt</dt>
                    <dd class="{{ $wert }} tabular-nums" title="Alle Zeilen der Karte, auch Überschriften, Texte und Abstände">{{ $positionenN }}</dd>
                </div>
                <div class="{{ $zeile }}">
                    <dt class="{{ $schluessel }}">Preise auf der Karte</dt>
                    <dd class="{{ $wert }}">{{ $karte->preis_anzeige_brutto ? 'brutto' : 'netto' }}</dd>
                </div>
            </dl>
        </x-fa::section>

        {{-- Stand (Status steht im Kopf) --}}
        <x-fa::section variant="plain" title="Stand" icon="heroicon-o-clock">
            <dl>
                <div class="{{ $zeile }}">
                    <dt class="{{ $schluessel }}">Erstellt</dt>
                    <dd class="{{ $wert }} tabular-nums">{{ $karte->created_at?->format('d.m.Y') ?? 'unbekannt' }}</dd>
                </div>
                <div class="{{ $zeile }}">
                    <dt class="{{ $schluessel }}">Geändert</dt>
                    <dd class="{{ $wert }} tabular-nums">{{ $karte->updated_at?->format('d.m.Y') ?? 'unbekannt' }}</dd>
                </div>
                <div class="{{ $zeile }}">
                    <dt class="{{ $schluessel }}">Nummer</dt>
                    <dd class="{{ $wert }} tabular-nums">#{{ $karte->id }}</dd>
                </div>
            </dl>
        </x-fa::section>
    </div>
</div>
