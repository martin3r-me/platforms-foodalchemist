{{-- Speiseplan-Detail (rechte Seitenleiste, nur lesen). Anatomie Detail-Panels (DESIGN.md, Muster concepter/detail-panel):
     Kopf mit einer Hauptaktion · Kennzahlen · offene Punkte · Linien · Eckdaten · Stand.
     Aushang drucken und Duplizieren im Menü „Weitere Aktionen". Erwartet: $plan (mit lines + entries geladen). --}}
@php
    $statusTon = ['success' => 'ok', 'warning' => 'warn', 'danger' => 'crit', 'info' => 'info'];
    $rollen = \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanLinie::ROLLEN;
    $zeile = 'flex items-center justify-between gap-3 py-2 border-b border-[var(--fa-line)] last:border-b-0';
    $dt = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]';
    $dd = 'text-[length:var(--fa-text-md)] text-[var(--fa-ink)] tabular-nums text-right';
    $eintraegeN = $plan->entries->count();
    $linienN = $plan->lines->count();
    $jeLinie = $plan->entries->groupBy(fn ($e) => (int) ($e->line_id ?? 0));
    $ohneLinie = $plan->entries->whereNull('line_id')->count();
    $budget = $plan->budget_wareneinsatz ?? null;

    $kpis = [
        ['kpi' => 'eintraege', 'label' => 'Einträge', 'value' => number_format($eintraegeN, 0, ',', '.'), 'primary' => true],
        ['kpi' => 'linien', 'label' => 'Linien', 'value' => (string) $linienN],
        $budget !== null
            ? ['kpi' => 'budget', 'label' => 'Budget EK/Person', 'value' => number_format((float) $budget, 2, ',', '.') . ' €']
            : ['kpi' => 'budget', 'label' => 'Budget EK/Person', 'value' => 'nicht gesetzt', 'title' => 'Kein Budget für den Wareneinsatz hinterlegt'],
    ];

    // Offene Punkte: was vor dem Aushang noch fehlt.
    $offen = [];
    if ($eintraegeN === 0) {
        $offen[] = ['crit', 'Noch keine Gerichte eingeplant.'];
    }
    if ($linienN === 0) {
        $offen[] = ['warn', 'Noch keine Linien angelegt.'];
    }
@endphp

<div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]" data-sp-detail>
    {{-- Kopf: Name, Kunde, eine Hauptaktion, Weiteres im Menü --}}
    <x-fa::detail-kopf :title="$plan->name" :subtitle="$plan->crmCompany?->display_name">
        <x-slot:badges>
            <x-fa::badge :tone="$statusTon[$plan->statusWert()->badgeVariant()] ?? 'neutral'">{{ $plan->statusWert()->label() }}</x-fa::badge>
            @if($plan->is_template)<x-fa::badge tone="info" icon="heroicon-m-square-2-stack">Vorlage</x-fa::badge>@endif
            <x-fa::badge title="Zyklus">{{ $plan->cycle_weeks }} {{ $plan->cycle_weeks == 1 ? 'Woche' : 'Wochen' }}</x-fa::badge>
        </x-slot:badges>
        <x-slot:aktion>
            <x-fa::button variant="primary" size="sm" icon="heroicon-m-pencil-square" wire:click="bearbeiten" data-sp-panel-bearbeiten>Im Editor öffnen</x-fa::button>
        </x-slot:aktion>
        <x-slot:menue>
            <x-fa::menu-item icon="heroicon-m-printer" :href="route('foodalchemist.speiseplan.dokument', $plan->id) . '?mahlzeit=' . ($vorschauMahlzeit ?? 'mittag')" target="_blank"
                title="Aushang der gewählten Mahlzeit zum Drucken oder als PDF">Aushang drucken</x-fa::menu-item>
            <x-fa::menu-item icon="heroicon-m-document-duplicate" wire:click="duplizieren"
                wire:confirm="Diesen Speiseplan mit allen Linien und Zellen als Kopie (Entwurf) anlegen?" data-sp-panel-duplizieren>Plan duplizieren</x-fa::menu-item>
        </x-slot:menue>
    </x-fa::detail-kopf>

    {{-- Kennzahlen: Anzahl Einträge ist die Hauptzahl --}}
    <div class="flex flex-col gap-3" data-sp-detail-kpis>
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
        {{-- Inhalt: Linien mit Rolle und Anzahl Einträge --}}
        <x-fa::section variant="plain" title="Linien" icon="heroicon-o-list-bullet" :meta="$linienN">
            @if($linienN === 0 && $ohneLinie === 0)
                <x-fa::empty compact icon="heroicon-o-list-bullet" title="Noch keine Linien">Im Editor Linien anlegen, z. B. Menü 1, Vegetarisch, Dessert.</x-fa::empty>
            @else
                <ul class="flex flex-col">
                    @foreach($plan->lines as $linie)
                        @php
                            $n = ($jeLinie[(int) $linie->id] ?? collect())->count();
                        @endphp
                        <li wire:key="sp-detail-linie-{{ $linie->id }}" class="flex items-center justify-between gap-3 py-1.5 border-b border-[var(--fa-line)] last:border-0">
                            <span class="min-w-0">
                                <span class="block text-[length:var(--fa-text-md)] text-[var(--fa-ink)] break-words">{{ $linie->name }}</span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                    @if($linie->role)<x-fa::badge title="Rolle an der Ausgabe">{{ $rollen[$linie->role] ?? $linie->role }}</x-fa::badge>@endif
                                    @if($linie->is_vegetarian)<x-fa::badge tone="ok" icon="heroicon-m-check">vegetarisch</x-fa::badge>@endif
                                </span>
                            </span>
                            <span class="shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ number_format($n, 0, ',', '.') }} {{ $n === 1 ? 'Eintrag' : 'Einträge' }}</span>
                        </li>
                    @endforeach
                    @if($ohneLinie > 0)
                        <li class="flex items-center justify-between gap-3 py-1.5 border-b border-[var(--fa-line)] last:border-0">
                            <span class="min-w-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink-3)]">Ohne Linie</span>
                            <span class="shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ number_format($ohneLinie, 0, ',', '.') }} {{ $ohneLinie === 1 ? 'Eintrag' : 'Einträge' }}</span>
                        </li>
                    @endif
                </ul>
            @endif
        </x-fa::section>

        {{-- Eckdaten --}}
        <x-fa::section variant="plain" title="Eckdaten" icon="heroicon-o-clipboard-document-list">
            <dl>
                <div class="{{ $zeile }}"><dt class="{{ $dt }}">Zyklus</dt><dd class="{{ $dd }}">{{ $plan->cycle_weeks }} {{ $plan->cycle_weeks == 1 ? 'Woche' : 'Wochen' }}</dd></div>
                <div class="{{ $zeile }}"><dt class="{{ $dt }}">Einträge</dt><dd class="{{ $dd }}">{{ number_format($eintraegeN, 0, ',', '.') }}</dd></div>
                <div class="{{ $zeile }}"><dt class="{{ $dt }}">Start</dt><dd class="{{ $dd }}">{{ $plan->start_date?->format('d.m.Y') ?? 'nicht gesetzt' }}</dd></div>
                <div class="{{ $zeile }}"><dt class="{{ $dt }}">Wiederholung</dt><dd class="{{ $dd }}">{{ ($plan->min_abstand_tage ?? 0) > 0 ? 'frühestens nach ' . $plan->min_abstand_tage . ' Tagen' : 'keine Regel' }}</dd></div>
                @if(($plan->default_pax ?? null) !== null)
                    <div class="{{ $zeile }}"><dt class="{{ $dt }}">Teilnehmer</dt><dd class="{{ $dd }}">{{ number_format((float) $plan->default_pax, 0, ',', '.') }}</dd></div>
                @endif
                <div class="{{ $zeile }}"><dt class="{{ $dt }}">Budget EK je Person</dt><dd class="{{ $dd }}">@if($budget !== null)<x-fa::money :value="$budget" />@else<span class="text-[var(--fa-ink-3)]">nicht gesetzt</span>@endif</dd></div>
            </dl>
        </x-fa::section>

        {{-- Stand (Status steht im Kopf) --}}
        <x-fa::section variant="plain" title="Stand" icon="heroicon-o-clock">
            <dl>
                <div class="{{ $zeile }}"><dt class="{{ $dt }}">Erstellt</dt><dd class="{{ $dd }}">{{ $plan->created_at?->format('d.m.Y') ?? 'unbekannt' }}</dd></div>
                <div class="{{ $zeile }}"><dt class="{{ $dt }}">Geändert</dt><dd class="{{ $dd }}">{{ $plan->updated_at?->format('d.m.Y') ?? 'unbekannt' }}</dd></div>
                <div class="{{ $zeile }}"><dt class="{{ $dt }}">Nummer</dt><dd class="{{ $dd }}">#{{ $plan->id }}</dd></div>
            </dl>
        </x-fa::section>
    </div>
</div>
