{{-- Produktion: rechte Detail-Spalte eines Auftrags.
     fa-pass (2026-10-05): Anatomie Detail-Panels (DESIGN.md, Muster concepter/detail-panel).
     Kopf (Name, Liefertag, Status; Hauptaktion „Auftrag bearbeiten", sonst der nächste Status-Schritt; alles Weitere
     im Menü, Stornieren als letzter Eintrag) · Meldungen · Kennzahlen mit Fortschritt · offene Punkte · Rezepte
     · Überblick (Ziele, Materialbedarf, Posten, Warnungen).
     Alle wire:-Bindungen, Bestätigungen und data-Marker unverändert (Status-Schritte tragen sie jetzt im Menü). --}}
@php
    $statusTon = ['secondary' => 'neutral', 'info' => 'info', 'success' => 'ok', 'danger' => 'crit', 'warning' => 'warn', 'primary' => 'accent'];
    $menge = fn ($wert) => rtrim(rtrim(number_format((float) $wert, 2, ',', '.'), '0'), ',') ?: '0';
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $zeileLabel = 'inline-flex items-center gap-1.5 text-[var(--fa-ink-2)]';
    $statusAktion = ['in_progress' => 'Produktion starten', 'done' => 'Fertig melden', 'cancelled' => 'Auftrag stornieren'];
    $statusIcon = ['in_progress' => 'heroicon-m-play', 'done' => 'heroicon-m-check', 'cancelled' => 'heroicon-m-x-circle'];
    $stornoFrage = 'Produktion stornieren? Offene Einkaufsentwürfe werden neu berechnet; bereits ausgelöste Bestellungen bleiben als Klärfall bestehen.';
@endphp

<div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]" data-produktion-panel>
    @if($detail === null)
        <x-fa::empty icon="heroicon-o-cursor-arrow-rays" title="Kein Auftrag gewählt">Produktionsauftrag in der Tabelle anklicken, dann erscheinen hier Mengen, Status, Rezepte und Materialbedarf.</x-fa::empty>
    @else
        @php
            $zieleCount = count($detail['targets']);
            $warnCount = count($detail['warnungen']) + count($kapazitaetsWarnungen);
            $postenBelegt = collect($postenSummen)->filter(fn ($p) => $p['station_id'] !== null)->count();
            $status = \Platform\FoodAlchemist\Enums\ProductionOrderStatus::from($detail['status']);
            $datum = \Illuminate\Support\Carbon::parse($detail['production_date']);
            $nochOffen = $detail['fortschritt']['offen'] + $detail['fortschritt']['in_arbeit'];

            // Status-Schritte: Stornieren immer ins Menü (letzter Eintrag). Hauptaktion ist der NÄCHSTE Arbeitsschritt
            // („Produktion starten", „Fertig melden" — das tut die Küche am Auftrag); „Auftrag bearbeiten" steht dann
            // als erster Menüeintrag. Gibt es keinen Vorwärts-Schritt mehr, ist Bearbeiten die Hauptaktion (2026-10-05).
            // Spec 65: Status-Schritte und Materialbedarf schreiben sofort — erst nach „Bearbeiten" (gleiche Sperre wie
            // der Editor). Im Lesemodus zeigt der Kopf „Bearbeiten" statt der Schritte, „Auftrag bearbeiten" öffnet den Editor.
            $sperrLesen = in_array($sperr['modus'], ['lesen', 'fremd'], true);
            $hatSchreibAktion = $detail['is_owned'] && ($erlaubteStatus !== [] || in_array($detail['status'], ['planned', 'in_progress'], true));
            $schritte = ($detail['is_owned'] && ! $sperrLesen) ? $erlaubteStatus : [];
            $vorwaerts = array_values(array_filter($schritte, fn ($z) => $z->value !== 'cancelled'));
            $storno = collect($schritte)->first(fn ($z) => $z->value === 'cancelled');
            $hauptSchritt = $vorwaerts !== [] ? array_shift($vorwaerts) : null;
            $bearbeitenImMenue = $detail['editierbar'] && $hauptSchritt !== null;
            $bestaetigung = fn ($z) => $z->value === 'cancelled'
                ? $stornoFrage
                : ($z->value === 'done' && $nochOffen > 0 ? $nochOffen . ' Position(en) sind noch nicht abgehakt. Trotzdem fertig melden?' : null);
            $onclick = fn ($z) => $bestaetigung($z) !== null ? 'return confirm(' . \Illuminate\Support\Js::from($bestaetigung($z)) . ')' : null;
            $doneOffen = fn ($z) => $z->value === 'done' && $nochOffen > 0 ? (string) $nochOffen : null;

            $hatMenue = $bearbeitenImMenue || $vorwaerts !== [] || $storno !== null
                || ($detail['is_owned'] && ! $sperrLesen && in_array($detail['status'], ['planned', 'in_progress'], true))
                || $detail['procurement_released_at']
                || \Illuminate\Support\Facades\Route::has('foodalchemist.produktion.auftraege.dokument');

            $kpis = [
                ['kpi' => 'ansaetze', 'label' => 'Ansätze', 'value' => $menge($detail['ansaetze_gesamt']), 'primary' => true],
                ['kpi' => 'portionen', 'label' => 'Portionen', 'value' => $detail['portionen_gesamt'] ? number_format((int) $detail['portionen_gesamt'], 0, ',', '.') : '–'],
                ['kpi' => 'zeit', 'label' => 'Arbeitszeit', 'value' => $detail['arbeitszeit_gesamt_min'] ? number_format((int) $detail['arbeitszeit_gesamt_min'], 0, ',', '.') . ' min' : 'fehlt',
                    'tone' => $detail['arbeitszeit_gesamt_min'] ? null : 'crit'],
            ];
        @endphp

        {{-- Kopf --}}
        <x-fa::detail-kopf :title="$detail['name'] ?: $datum->format('d.m.Y')"
            :subtitle="'Liefertag ' . $datum->format('d.m.Y') . ($datum->isToday() ? ' (heute)' : '') . ($detail['reference'] ? ' · ' . $detail['reference'] : '')">
            <x-slot:badges>
                <x-fa::badge :tone="$statusTon[$status->badgeVariant()] ?? 'neutral'">{{ ucfirst($detail['status_label']) }}</x-fa::badge>
                @if(! empty($detail['procurement_stale']))
                    <x-fa::badge tone="warn" icon="heroicon-m-archive-box-arrow-down">Bedarf geändert</x-fa::badge>
                @elseif(! empty($detail['procurement_released_at']))
                    <x-fa::badge tone="ok" icon="heroicon-m-archive-box-arrow-down">Bedarf freigegeben</x-fa::badge>
                @endif
            </x-slot:badges>
            @if($detail['editierbar'] || $hauptSchritt !== null || $hatSchreibAktion)
                <x-slot:aktion>
                    @if($hatSchreibAktion)
                        <x-foodalchemist::bearbeiten-leiste :zustand="$sperr" sofort />
                    @endif
                    @if($hauptSchritt === null && $detail['editierbar'])
                        <x-fa::button size="sm" :variant="$sperrLesen ? 'ghost' : 'primary'" icon="heroicon-m-pencil-square" wire:click="$dispatch('produktion-editor.bearbeiten', { id: {{ $detail['id'] }} })" data-produktion-bearbeiten>Auftrag bearbeiten</x-fa::button>
                    @elseif($hauptSchritt !== null)
                        <x-fa::button size="sm" variant="primary" :icon="$statusIcon[$hauptSchritt->value] ?? null"
                            wire:click="setStatus('{{ $hauptSchritt->value }}')" wire:key="pstatus-{{ $hauptSchritt->value }}"
                            :onclick="$onclick($hauptSchritt)" :data-produktion-done-offen="$doneOffen($hauptSchritt)"
                            data-produktion-status="{{ $hauptSchritt->value }}">{{ $statusAktion[$hauptSchritt->value] ?? ucfirst($hauptSchritt->label()) }}</x-fa::button>
                    @endif
                </x-slot:aktion>
            @endif
            @if($hatMenue)
                <x-slot:menue>
                    @if($bearbeitenImMenue)
                        <x-fa::menu-item icon="heroicon-m-pencil-square" wire:click="$dispatch('produktion-editor.bearbeiten', { id: {{ $detail['id'] }} })" data-produktion-bearbeiten>Auftrag bearbeiten</x-fa::menu-item>
                    @endif
                    @foreach($vorwaerts as $z)
                        <x-fa::menu-item :icon="$statusIcon[$z->value] ?? null" wire:click="setStatus('{{ $z->value }}')" wire:key="pstatus-{{ $z->value }}"
                            :onclick="$onclick($z)" :data-produktion-done-offen="$doneOffen($z)"
                            data-produktion-status="{{ $z->value }}">{{ $statusAktion[$z->value] ?? ucfirst($z->label()) }}</x-fa::menu-item>
                    @endforeach
                    @if($detail['is_owned'] && ! $sperrLesen && in_array($detail['status'], ['planned', 'in_progress'], true))
                        <x-fa::menu-item icon="heroicon-m-check-circle" wire:click="materialbedarfFreigeben" data-materialbedarf-freigeben>{{ $detail['procurement_released_at'] ? 'Bedarf erneut freigeben' : 'Materialbedarf freigeben' }}</x-fa::menu-item>
                    @endif
                    @if($detail['procurement_released_at'])
                        <x-fa::menu-item icon="heroicon-m-shopping-cart" :href="route('foodalchemist.orders.index', ['sicht' => 'bedarfe', 'p' => $detail['id']])">Im Einkauf öffnen</x-fa::menu-item>
                    @endif
                    @if(\Illuminate\Support\Facades\Route::has('foodalchemist.produktion.auftraege.dokument'))
                        <x-fa::menu-item icon="heroicon-m-document-text" :href="route('foodalchemist.produktion.auftraege.dokument', ['order' => $detail['id'], 'profil' => 'produktion'])" target="_blank"
                            title="Produktionsdokument zusammenstellen" data-produktion-panel-dokument>Dokument öffnen</x-fa::menu-item>
                    @endif
                    @if($storno !== null)
                        <x-fa::menu-item danger :icon="$statusIcon['cancelled']" wire:click="setStatus('cancelled')" wire:key="pstatus-cancelled"
                            :onclick="$onclick($storno)" data-produktion-status="cancelled">{{ $statusAktion['cancelled'] }}</x-fa::menu-item>
                    @endif
                </x-slot:menue>
            @endif
        </x-fa::detail-kopf>

        @if($hinweis)<x-fa::notice tone="ok">{{ $hinweis }}</x-fa::notice>@endif
        @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif

        {{-- Kennzahlen: Ansätze sind die Hauptzahl, darunter der Fortschritt --}}
        <div class="flex flex-col gap-3" data-kpi-karte>
            <x-fa::kpis :items="$kpis" />

            @if($detail['status'] !== 'planned' && $detail['fortschritt']['gesamt'] > 0)
                @php
                    $fs = $detail['fortschritt'];
                    $prozent = max(0, min(100, (float) $fs['prozent']));
                @endphp
                <div class="flex flex-col gap-1.5" data-panel-fortschritt>
                    <div class="flex items-baseline justify-between text-[length:var(--fa-text-sm)]">
                        <span class="text-[var(--fa-ink-2)]">Fortschritt</span>
                        <span class="tabular-nums text-[var(--fa-ink)]">{{ $fs['erledigt'] }} von {{ $fs['gesamt'] }} erledigt</span>
                    </div>
                    <div class="h-1.5 rounded-full bg-[var(--fa-line-strong)] overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round($prozent) }}" aria-label="Fortschritt">
                        <div class="h-full rounded-full {{ $fs['alle_erledigt'] ? 'bg-[var(--fa-ok)]' : 'bg-[var(--fa-accent)]' }}" style="width: {{ round($prozent, 1) }}%"></div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Offene Punkte: Warnungen, Kapazität, Storno-Einkauf --}}
        @if($warnCount > 0)
            <div class="flex flex-col gap-1" data-panel-warnungen>
                @foreach(array_slice($detail['warnungen'], 0, 2) as $w)
                    <x-fa::signal tone="warn">{{ $w }}</x-fa::signal>
                @endforeach
                @foreach(array_slice($kapazitaetsWarnungen, 0, 2) as $w)
                    <x-fa::signal tone="warn" data-panel-kapazitaet>{{ $w }}</x-fa::signal>
                @endforeach
                @if($warnCount > 4)<p class="{{ $leise }}">Alle Warnungen im Editor unter Positionen.</p>@endif
            </div>
        @endif

        @if(! empty($detail['procurement_cancel_warning']))
            <div class="flex flex-col gap-2" data-produktion-storno-einkauf>
                <x-fa::notice tone="warn" title="Bestellungen bereits ausgelöst">Produktion storniert, aber mindestens eine Bestellung wurde bereits ausgelöst. Bitte die Lieferanten informieren und die Belege anschließend als storniert bestätigen.</x-fa::notice>
                <div class="flex flex-wrap gap-1.5">
                    @foreach(collect($detail['verknuepfte_orders'])->whereIn('status', ['sent', 'confirmed']) as $linkedOrder)
                        @if($linkedOrder['cancellation_mailto'])
                            <x-fa::button size="sm" variant="danger" icon="heroicon-o-envelope" :href="$linkedOrder['cancellation_mailto']">{{ $linkedOrder['cancellation_kind'] === 'partial' ? 'Änderung' : 'Storno' }} an {{ $linkedOrder['supplier'] }}</x-fa::button>
                        @else
                            <span class="inline-flex items-center gap-1.5 h-7 px-2.5 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] cursor-not-allowed" title="Beim Lieferanten fehlt die Bestell-E-Mail">@svg('heroicon-o-envelope', 'w-3.5 h-3.5') {{ $linkedOrder['supplier'] }}: E-Mail fehlt</span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        <div class="flex flex-col">
            {{-- Inhalt: Rezepte des Auftrags --}}
            <x-fa::section variant="plain" title="Rezepte" icon="heroicon-o-list-bullet" :meta="count($detail['zeilen']) > 0 ? count($detail['zeilen']) : null" data-kpi="rezepte">
                @if(count($detail['zeilen']) === 0)
                    <x-fa::empty compact icon="heroicon-o-list-bullet" title="Noch keine Rezepte">Im Editor Rezepte oder Gerichte mit Menge hinzufügen.</x-fa::empty>
                @else
                    <ul class="flex flex-col">
                        @foreach($detail['zeilen'] as $z)
                            <li class="flex items-start justify-between gap-3 py-1.5 border-b border-[var(--fa-line)] last:border-0" wire:key="produktion-detail-zeile-{{ $z['id'] }}">
                                <span class="min-w-0">
                                    <span class="flex flex-wrap items-center gap-1.5 text-[length:var(--fa-text-md)] {{ $z['ist_gestrichen'] ? 'text-[var(--fa-ink-3)] line-through' : 'text-[var(--fa-ink)]' }}">
                                        <span class="min-w-0 break-words">{{ $z['name'] }}</span>
                                        @if($z['ist_gestrichen'])<x-fa::badge title="{{ $z['struck_reason'] }}">gestrichen</x-fa::badge>@endif
                                    </span>
                                    <span class="block {{ $leise }} tabular-nums">
                                        {{ $menge($z['ansaetze']) }} {{ (float) $z['ansaetze'] === 1.0 ? 'Ansatz' : 'Ansätze' }}@if($z['portionen']) · {{ number_format((int) $z['portionen'], 0, ',', '.') }} Portionen @endif
                                        · {{ $z['station'] ?: 'ohne Posten' }}
                                    </span>
                                </span>
                                <x-fa::badge class="shrink-0">{{ ucfirst($z['line_status_label']) }}</x-fa::badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-fa::section>

            {{-- Fachabschnitt: Überblick --}}
            <x-fa::section variant="plain" title="Überblick" icon="heroicon-o-clipboard-document-list">
                <dl class="flex flex-col text-[length:var(--fa-text-md)]" data-panel-glance>
                    <div class="flex items-center justify-between gap-2 py-1.5 border-b border-[var(--fa-line)]">
                        <dt class="{{ $zeileLabel }}">@svg('heroicon-o-flag', 'w-4 h-4 text-[var(--fa-ink-3)]') Ziele</dt>
                        <dd class="tabular-nums text-[var(--fa-ink)]">@if($zieleCount > 0){{ $zieleCount }}@else<x-fa::signal tone="warn">Keine</x-fa::signal>@endif</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 py-1.5 border-b border-[var(--fa-line)]">
                        <dt class="{{ $zeileLabel }}">@svg('heroicon-o-archive-box-arrow-down', 'w-4 h-4 text-[var(--fa-ink-3)]') Materialbedarf</dt>
                        <dd>
                            @if(! empty($detail['procurement_stale']))
                                <x-fa::badge tone="warn" data-materialbedarf-status="geaendert">Geändert</x-fa::badge>
                            @elseif(! empty($detail['procurement_released_at']))
                                <x-fa::badge tone="ok" data-materialbedarf-status="freigegeben">Freigegeben</x-fa::badge>
                            @else
                                <x-fa::badge data-materialbedarf-status="entwurf">Nicht freigegeben</x-fa::badge>
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 py-1.5 border-b border-[var(--fa-line)]">
                        <dt class="{{ $zeileLabel }}">@svg('heroicon-o-users', 'w-4 h-4 text-[var(--fa-ink-3)]') Posten</dt>
                        <dd class="tabular-nums text-[var(--fa-ink)]">{{ $postenBelegt }} von {{ count($postenSummen) }} belegt</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 py-1.5">
                        <dt class="{{ $zeileLabel }}">@svg('heroicon-o-exclamation-triangle', 'w-4 h-4 text-[var(--fa-ink-3)]') Warnungen</dt>
                        <dd><x-fa::badge :tone="$warnCount > 0 ? 'warn' : 'ok'">{{ $warnCount > 0 ? $warnCount : 'Keine' }}</x-fa::badge></dd>
                    </div>
                </dl>
            </x-fa::section>
        </div>
    @endif
</div>
