{{-- Foodbook-Detail (rechte Seitenleiste, nur lesen). Anatomie Detail-Panels (DESIGN.md, Muster concepter/detail-panel):
     Kopf mit Logo und einer Hauptaktion · Kennzahlen · offene Punkte · Kapitel · Eckdaten · Stand.
     Ausgaben (Dokument, Bericht, Präsentation), Duplizieren und Löschen im Menü „Weitere Aktionen".
     Erwartet: $fb (detail-geladen mit chapters). Optional aus dem Seiten-Scope: $menue (Vorschau-Stand, dieselbe Quelle
     wie Kundensicht und Editor-Kennzahlen) und $kapitelBoard (Kapitel-Stand live, wie im Editor-Board). --}}
@php
    $niveauLabel = ['klassisch' => 'Klassisch', 'gehoben' => 'Gehoben', 'haute_cuisine' => 'Haute Cuisine'];
    $fortLabel = ['offen' => 'Offen', 'in_arbeit' => 'In Arbeit', 'fertig' => 'Fertig'];
    $fortTon = ['offen' => 'neutral', 'in_arbeit' => 'warn', 'fertig' => 'ok'];
    $logoUrl = $fb->logo_path ? app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($fb->logo_context_file_id, $fb->logo_path) : null;
    $phasen = \Platform\FoodAlchemist\Services\PhaseService::LABELS;
    $statusTon = ['success' => 'ok', 'warning' => 'warn', 'danger' => 'crit', 'info' => 'info', 'primary' => 'accent'][$fb->statusWert()->badgeVariant()] ?? 'neutral';
    $zeile = 'flex items-center justify-between gap-3 py-2 border-b border-[var(--fa-line)] last:border-b-0';
    $dt = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]';
    $dd = 'min-w-0 text-right text-[length:var(--fa-text-md)] text-[var(--fa-ink)] tabular-nums';
    $offenText = 'text-[length:var(--fa-text-md)] text-[var(--fa-ink-3)]';
    $euro = fn ($wert) => number_format((float) $wert, 2, ',', '.') . ' €';

    // Kennzahlen aus dem Vorschau-Stand (wie Kundensicht und Editor-Kopf). Unbekanntes nie als 0,00 €.
    $gesamt = (isset($menue) && is_array($menue)) ? ($menue['gesamt'] ?? []) : [];
    $vkPp = $gesamt['vk_pro_person'] ?? null;
    $ekPp = $gesamt['ek_per_person'] ?? null;
    $wePct = $gesamt['food_cost_percent'] ?? null;
    $standTitel = ($menueSnapshotAt ?? null) ? 'Stand der Vorschau: ' . $menueSnapshotAt->format('d.m.Y, H:i') . ' Uhr' : null;
    $kpis = [
        ($vkPp !== null && (float) $vkPp > 0)
            ? ['kpi' => 'vk', 'label' => 'VK/Person', 'value' => $euro($vkPp), 'primary' => true, 'title' => $standTitel]
            : ['kpi' => 'vk', 'label' => 'VK/Person', 'value' => 'Preis fehlt', 'tone' => 'crit', 'title' => $standTitel],
        ($ekPp !== null && (float) $ekPp > 0)
            ? ['kpi' => 'ek', 'label' => 'EK/Person', 'value' => $euro($ekPp), 'title' => $standTitel]
            : ['kpi' => 'ek', 'label' => 'EK/Person', 'value' => 'fehlt', 'tone' => 'crit', 'title' => $standTitel],
        ($wePct !== null && $ekPp !== null && (float) $ekPp > 0)
            ? ['kpi' => 'we', 'label' => 'Wareneinsatz', 'value' => number_format((float) $wePct, 1, ',', '.') . ' %', 'title' => 'Einkauf durch Verkauf über alle bepreisten Positionen']
            : ['kpi' => 'we', 'label' => 'Wareneinsatz', 'value' => 'fehlt', 'tone' => 'crit', 'title' => 'Einkauf durch Verkauf über alle bepreisten Positionen'],
    ];

    // Kapitel-Stand live (Board des Editors): Inhalt, Preis, Fortschritt je Kapitel.
    $board = collect($kapitelBoard ?? []);
    $kapitelN = $fb->chapters->count();
    $fertigN = $board->where('fortschritt', 'fertig')->count();
    $arbeitsKapitel = $board->reject(fn ($k) => ! empty($k['is_struktur']));
    $ohneInhalt = $arbeitsKapitel->reject(fn ($k) => ! empty($k['hat_inhalt']))->count();
    $ohnePreis = $arbeitsKapitel->filter(fn ($k) => ! empty($k['hat_inhalt']) && empty($k['bepreist']))->count();

    // Offene Punkte: was vor dem Versand an den Kunden noch fehlt.
    $offen = [];
    if ($kapitelN === 0) {
        $offen[] = ['crit', 'Noch keine Kapitel angelegt.'];
    }
    if ($ohneInhalt > 0) {
        $offen[] = ['warn', $ohneInhalt === 1 ? 'Ein Kapitel ist noch leer.' : $ohneInhalt . ' Kapitel sind noch leer.'];
    }
    if ($ohnePreis > 0) {
        $offen[] = ['crit', $ohnePreis === 1 ? 'Einem befüllten Kapitel fehlt der Preis.' : $ohnePreis . ' befüllten Kapiteln fehlt der Preis.'];
    }
    if (! $fb->crmCompany?->display_name) {
        $offen[] = ['warn', 'Kein Kunde zugeordnet.'];
    }
@endphp

<div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]" data-fb-detail>
    {{-- Kopf: Name, Kunde, Logo, eine Hauptaktion, Weiteres im Menü --}}
    <x-fa::detail-kopf :title="$fb->label" :subtitle="$fb->crmCompany?->display_name ?: 'Kein Kunde zugeordnet'">
        @if($logoUrl)
            <div class="mt-2 inline-flex rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-2">
                <img src="{{ $logoUrl }}" alt="Logo" class="max-h-10 max-w-[10rem] object-contain" />
            </div>
        @endif
        <x-slot:badges>
            <x-fa::badge :tone="$statusTon">{{ $fb->statusWert()->label() }}</x-fa::badge>
            @if($fb->default_niveau)<x-fa::badge title="Niveau">{{ $niveauLabel[$fb->default_niveau] ?? $fb->default_niveau }}</x-fa::badge>@endif
        </x-slot:badges>
        <x-slot:aktion>
            <x-fa::button variant="primary" size="sm" icon="heroicon-m-pencil-square"
                x-on:click="$dispatch('modal.open', { name: 'foodbook-editor' })" data-fb-panel-bearbeiten>Im Editor öffnen</x-fa::button>
        </x-slot:aktion>
        <x-slot:menue>
            <x-fa::menu-item icon="heroicon-m-printer" :href="route('foodalchemist.foodbooks.dokument', $fb->id)" target="_blank"
                title="Kundendokument zum Drucken oder als PDF, Allergene und Zusatzstoffe zuschaltbar">Dokument öffnen</x-fa::menu-item>
            <x-fa::menu-item icon="heroicon-m-document-text" :href="route('foodalchemist.foodbooks.report', $fb->id)" target="_blank"
                title="Ausführlicher Bericht mit Preisen, Lieferanten, Deklaration und Nährwerten, nach Kapiteln filterbar">Bericht öffnen</x-fa::menu-item>
            <x-fa::menu-item icon="heroicon-m-presentation-chart-bar" :href="route('foodalchemist.foodbooks.praesentation', $fb->id)" target="_blank"
                title="Web-Seite für den Kunden mit Preisen pro Person, ohne interne Angaben">Präsentation ansehen</x-fa::menu-item>
            <x-fa::menu-item icon="heroicon-m-document-duplicate" wire:click="duplizieren"
                wire:confirm="Dieses Foodbook mit allen Kapiteln und Einträgen als Kopie (Entwurf) anlegen?" data-fb-panel-duplizieren>Foodbook duplizieren</x-fa::menu-item>
            {{-- Spec 65: Löschen nur mit eigener Bearbeitungssperre (Editor → Bearbeiten) --}}
            <fieldset @disabled($gesperrt ?? false) class="contents">
                <x-fa::menu-item danger icon="heroicon-m-trash" wire:click="loeschen({{ $fb->id }})" wire:confirm="Foodbook löschen?" data-fb-panel-loeschen>Foodbook löschen</x-fa::menu-item>
            </fieldset>
        </x-slot:menue>
    </x-fa::detail-kopf>

    {{-- Kennzahlen: VK/Person ist die Hauptzahl --}}
    <div class="flex flex-col gap-3" data-fb-detail-kpis>
        <x-fa::kpis :items="$kpis" />
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">
            {{ $kapitelN }} Kapitel, davon {{ $fertigN }} fertig
        </p>
        @if($offen !== [])
            <div class="flex flex-col gap-1">
                @foreach($offen as [$ton, $text])
                    <x-fa::signal :tone="$ton">{{ $text }}</x-fa::signal>
                @endforeach
            </div>
        @endif
    </div>

    <div class="flex flex-col">
        {{-- Inhalt: Kapitel mit Fortschritt und Preis je Person --}}
        <x-fa::section variant="plain" title="Kapitel" icon="heroicon-o-list-bullet" :meta="$kapitelN">
            @if($board->isEmpty())
                <x-fa::empty compact icon="heroicon-o-list-bullet" title="Noch keine Kapitel">Im Editor Kapitel anlegen und mit Paketen oder Gerichten füllen.</x-fa::empty>
            @else
                <ul class="flex flex-col">
                    @foreach($board as $k)
                        @php
                            $agg = $k['aggregat'] ?? [];
                            $kVk = (float) ($agg['vk_pro_person'] ?? 0);
                            $kPauschal = (float) ($agg['pauschal'] ?? 0);
                        @endphp
                        <li wire:key="fb-detail-kap-{{ $k['kapitel_id'] }}" class="flex items-center justify-between gap-3 py-1.5 border-b border-[var(--fa-line)] last:border-0" style="padding-left: {{ ($k['depth'] ?? 0) * 12 }}px">
                            <span class="min-w-0">
                                <span class="block text-[length:var(--fa-text-md)] text-[var(--fa-ink)] break-words">{{ $k['titel'] }}</span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                    <x-fa::badge :tone="$fortTon[$k['fortschritt']] ?? 'neutral'" title="Fortschritt">{{ $fortLabel[$k['fortschritt']] ?? 'Offen' }}</x-fa::badge>
                                    @if(! empty($k['is_struktur']))
                                        <x-fa::badge title="Gliederungskapitel ohne eigene Positionen">Gliederung</x-fa::badge>
                                    @elseif(empty($k['hat_inhalt']))
                                        <x-fa::badge tone="warn">leer</x-fa::badge>
                                    @else
                                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ $k['positionen_count'] }} {{ $k['positionen_count'] === 1 ? 'Position' : 'Positionen' }}</span>
                                    @endif
                                </span>
                            </span>
                            @if(empty($k['is_struktur']) && ! empty($k['hat_inhalt']))
                                @if($kVk > 0)
                                    <x-fa::money :value="$kVk" per="Person" class="shrink-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" />
                                @elseif($kPauschal > 0)
                                    <span class="shrink-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink)] tabular-nums" title="Pauschalpreis">{{ $euro($kPauschal) }} <span class="text-[var(--fa-ink-3)]">pauschal</span></span>
                                @else
                                    <x-fa::money :value="null" class="shrink-0" />
                                @endif
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
                    <dt class="{{ $dt }}">Kunde</dt>
                    <dd class="{{ $dd }} truncate">
                        @if($fb->crmCompany?->display_name){{ $fb->crmCompany->display_name }}@else<span class="{{ $offenText }}">Kein Kunde zugeordnet</span>@endif
                    </dd>
                </div>
                <div class="{{ $zeile }}">
                    <dt class="{{ $dt }}">Jahr</dt>
                    <dd class="{{ $dd }}">@if($fb->jahr){{ $fb->jahr }}@else<span class="{{ $offenText }}">Offen</span>@endif</dd>
                </div>
                <div class="{{ $zeile }}">
                    <dt class="{{ $dt }}">Gäste</dt>
                    <dd class="{{ $dd }}">@if($fb->personen !== null){{ $fb->personen }}@else<span class="{{ $offenText }}">Offen</span>@endif</dd>
                </div>
                <div class="{{ $zeile }}">
                    <dt class="{{ $dt }}">Kapitel</dt>
                    <dd class="{{ $dd }}">{{ $kapitelN }}</dd>
                </div>
                @if($fb->default_niveau)
                    <div class="{{ $zeile }}">
                        <dt class="{{ $dt }}">Niveau</dt>
                        <dd class="{{ $dd }}">{{ $niveauLabel[$fb->default_niveau] ?? $fb->default_niveau }}</dd>
                    </div>
                @endif
            </dl>
        </x-fa::section>

        {{-- Stand (Status steht im Kopf) --}}
        <x-fa::section variant="plain" title="Stand" icon="heroicon-o-clock">
            <dl>
                <div class="{{ $zeile }}">
                    <dt class="{{ $dt }}">Phase</dt>
                    <dd class="{{ $dd }}">{{ $phasen[$fb->phase] ?? $fb->phase }}</dd>
                </div>
                <div class="{{ $zeile }}">
                    <dt class="{{ $dt }}">Angelegt</dt>
                    <dd class="{{ $dd }}">{{ $fb->created_at?->format('d.m.Y') ?? '–' }}</dd>
                </div>
                <div class="{{ $zeile }}">
                    <dt class="{{ $dt }}">Zuletzt geändert</dt>
                    <dd class="{{ $dd }}">{{ $fb->updated_at?->format('d.m.Y') ?? '–' }}</dd>
                </div>
                <div class="{{ $zeile }}">
                    <dt class="{{ $dt }}">Nummer</dt>
                    <dd class="{{ $dd }}">{{ $fb->code ?: '#' . $fb->id }}</dd>
                </div>
            </dl>
        </x-fa::section>
    </div>
</div>
