{{-- Signal-Zeile (Cockpit) — Überblick + Signale-Reiter.
     Erwartet: $sig, $kiPanelId, $kiDraft (aus ReviewQueue).
     Spec 21 · S3a: „Ansehen" öffnet das rechte Signal-Panel (volle Liste + objekt-zentrische Sicht).
     fa-pass (2026-10-05): Tokens + Bausteine. Hinweis: review-queue rendert die Zeile inzwischen
     seitenlokal; dieses Partial bleibt für weitere Einbindungen in derselben Optik. --}}
@php
    // 22·H4b/V-033: der Knopf hängt am AUSFÜHRBAREN Plan. `navigate` ist ein Weg-Satz
    // ohne Ausführung — er wird im Detail-Panel erklärt, nicht hier als Knopf angeboten.
    // (Kommentar-Syntax: hier drin gilt PHP, kein Blade — s. BladeCompilesTest.)
    $ki = \Platform\FoodAlchemist\Support\SignalCockpit::kiPlan($sig);
    $sevMap = [
        'kritisch' => ['ton' => 'crit', 'bar' => 'bg-[var(--fa-crit)]', 'tint' => 'bg-[var(--fa-crit-soft)] text-[var(--fa-crit)]'],
        'warnung' => ['ton' => 'warn', 'bar' => 'bg-[var(--fa-warn)]', 'tint' => 'bg-[var(--fa-warn-soft)] text-[var(--fa-warn)]'],
        'info' => ['ton' => 'info', 'bar' => 'bg-[var(--fa-info)]', 'tint' => 'bg-[var(--fa-info-soft)] text-[var(--fa-info)]'],
    ];
    $sv = $sevMap[$sig->severity->value] ?? $sevMap['info'];
    $statusTon = ['warning' => 'warn', 'success' => 'ok', 'secondary' => 'neutral', 'danger' => 'crit', 'info' => 'info'];
    $pl = is_array($sig->payload) ? $sig->payload : [];
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $geld = fn ($wert) => number_format((float) $wert, 2, ',', '.') . ' €';
@endphp
<div class="group relative rounded-[var(--fa-radius-surface)] hover:bg-[var(--fa-hover)] transition-colors" wire:key="sig-{{ $sig->id }}">
    <span class="absolute left-0 top-3 bottom-3 w-[3px] rounded-full {{ $sv['bar'] }}" aria-hidden="true"></span>
    <div class="flex items-start gap-3 pl-4 pr-1 py-2.5">
        <span class="shrink-0 grid place-items-center w-9 h-9 rounded-[var(--fa-radius-control)] {{ $sv['tint'] }}" title="{{ $sig->severity->label() }}">
            @svg($sig->type->icon(), 'w-[18px] h-[18px]')
        </span>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $sig->title }}</span>
                <x-fa::badge :tone="$sv['ton']">{{ $sig->severity->label() }}</x-fa::badge>
                {{-- Ebene 2: Betrieb sichtbar machen — NULL = ganzes Team (kein Etikett), sonst der Betrieb. --}}
                @if($sig->outlet_id)
                    <x-fa::badge tone="accent" icon="heroicon-m-building-storefront" title="Signal dieses Betriebs">{{ optional($sig->outlet)->name ?? 'Betrieb' }}</x-fa::badge>
                @endif
            </div>
            <p class="mt-0.5 {{ $leise }}">
                <span class="text-[var(--fa-ink-2)]">{{ $sig->type->label() }}</span>@if($sig->description) · {{ \Illuminate\Support\Str::limit($sig->description, 130) }}@endif
            </p>

            @if($sig->type->value === 'preis_sprung_marge_impact' && $pl)
                @php
                    $md = (float) ($pl['marge_delta_eur'] ?? 0);
                    $wd = (float) ($pl['wpct_delta'] ?? 0);
                    $nGerichte = (int) ($pl['n_gerichte'] ?? 0);
                    $nKonzepte = (int) ($pl['n_concepts'] ?? 0);
                @endphp
                <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                    @isset($pl['preis_alt'], $pl['preis_neu'])
                        <span class="inline-flex items-center gap-1 tabular-nums">{{ $geld($pl['preis_alt']) }} @svg('heroicon-m-arrow-right', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]') <span class="font-medium text-[var(--fa-ink)]">{{ $geld($pl['preis_neu']) }}</span></span>
                    @endisset
                    <span>{{ $nGerichte }} {{ $nGerichte === 1 ? 'Gericht' : 'Gerichte' }} · {{ $nKonzepte }} {{ $nKonzepte === 1 ? 'Konzept' : 'Konzepte' }}</span>
                    @if($md != 0.0)
                        <span class="font-medium tabular-nums {{ $md < 0 ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ok)]' }}">Marge {{ $geld($md) }}@if($wd != 0.0) ({{ $wd > 0 ? '+' : '' }}{{ number_format($wd, 1, ',', '.') }} Prozentpunkte Wareneinsatz)@endif</span>
                    @endif
                    @if(!empty($pl['guenstigere_alternative']['label']))
                        <span class="inline-flex items-center gap-1 text-[var(--fa-info)]" title="Günstigere Alternative">@svg('heroicon-m-arrow-trending-down', 'w-3.5 h-3.5') {{ \Illuminate\Support\Str::limit($pl['guenstigere_alternative']['label'], 28) }} ({{ $pl['guenstigere_alternative']['diff_pct'] }} %)</span>
                    @endif
                </div>
                @if(!empty($pl['beispiele']))
                    <div class="mt-1 flex flex-wrap gap-x-2.5 gap-y-0.5 text-[length:var(--fa-text-sm)]">
                        @foreach(array_slice($pl['beispiele'], 0, 6) as $bsp)
                            <a href="{{ route('foodalchemist.verkauf.index', ['rezept' => $bsp['recipe_id']]) }}" wire:navigate
                               class="text-[var(--fa-accent)] hover:underline" title="Marge {{ $bsp['marge_pct_alt'] }} % auf {{ $bsp['marge_pct_neu'] }} %">
                                {{ \Illuminate\Support\Str::limit($bsp['name'], 26) }}@if(($bsp['marge_delta_eur'] ?? 0) != 0) <span class="text-[var(--fa-ink-3)] tabular-nums">({{ $geld($bsp['marge_delta_eur']) }})</span>@endif
                            </a>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        <div class="shrink-0 flex items-center gap-1 pt-0.5">
            {{-- Öffnet das Signal-Detail als Modal: `signal-selected` lädt das DetailPanel,
                 das danach selbst `modal.open` feuert (2026-08-02, s. review-queue). --}}
            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-arrow-right-circle" wire:click="$dispatch('signal-selected', { id: {{ $sig->id }} })"
                title="Betroffene Rezepte und Gerichte anzeigen" data-signal-reinschauen="{{ $sig->id }}">Ansehen</x-fa::button>
            @if($sig->status->istOffen())
                @if($ki)
                    <x-fa::button size="sm" variant="ai" icon="heroicon-o-sparkles" wire:click="toggleKiPanel({{ $sig->id }})"
                        class="{{ $kiPanelId === $sig->id ? 'ring-1 ring-[var(--fa-accent)]' : '' }}" title="{{ $ki['flavorLabel'] }}">KI erledigen lassen</x-fa::button>
                @endif
                {{-- „Erledigt"/„Ignorieren" sind Status-Setzer, keine lauten Hauptaktionen: das Signal bekommt
                     einen End-Status und fällt aus der Offen-Liste. Darum leise Symbol-Knöpfe. --}}
                <span class="mx-0.5 w-px h-4 bg-[var(--fa-line)]" aria-hidden="true"></span>
                <x-fa::icon-button size="sm" icon="heroicon-o-check-circle" label="Als erledigt markieren" wire:click="signalErledigt({{ $sig->id }})" data-rq-sig-erledigt="{{ $sig->id }}" />
                <x-fa::icon-button size="sm" icon="heroicon-o-no-symbol" label="Bewusst ignorieren" wire:click="signalIgnorieren({{ $sig->id }})" data-rq-sig-ignorieren="{{ $sig->id }}" />
            @else
                <x-fa::badge :tone="$statusTon[$sig->status->badgeVariant()] ?? 'neutral'">{{ $sig->status->label() }}</x-fa::badge>
                <x-fa::button size="sm" variant="ghost" icon="heroicon-o-arrow-uturn-left" wire:click="signalWiederOeffnen({{ $sig->id }})">Wieder öffnen</x-fa::button>
            @endif
        </div>
    </div>

    @if($ki && $sig->status->istOffen() && $kiPanelId === $sig->id)
        @php
            $istFix = $ki['kind'] === 'deterministic';
        @endphp
        <div class="mx-4 mb-3 -mt-1 flex flex-col gap-2 rounded-[var(--fa-radius-surface)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] px-4 py-3" wire:key="kpanel-{{ $sig->id }}">
            <div class="flex flex-wrap items-center gap-2">
                <x-fa::badge :tone="$istFix ? 'ok' : 'info'" :icon="$istFix ? 'heroicon-m-bolt' : 'heroicon-m-sparkles'">{{ $ki['flavorLabel'] }}</x-fa::badge>
                <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink)]">So würde die KI das angehen</span>
            </div>
            <p class="text-[length:var(--fa-text-md)] leading-relaxed text-[var(--fa-ink-2)]">{{ $ki['plan'] }}</p>

            @if(($kiDraft['signal_id'] ?? null) === $sig->id && !empty($kiDraft['draft']))
                <div class="flex flex-col gap-1 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-3 py-2" wire:key="kidraft-{{ $sig->id }}">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-info)]">Entwurf der KI</span>
                        <span class="{{ $leise }} tabular-nums">{{ round(((float) ($kiDraft['confidence'] ?? 0)) * 100) }} % sicher</span>
                    </div>
                    <textarea readonly rows="6" onclick="this.select()" aria-label="Entwurf der KI"
                              class="w-full p-0 resize-y border-0 bg-transparent text-[length:var(--fa-text-md)] leading-relaxed text-[var(--fa-ink)] focus:ring-0">{{ $kiDraft['draft'] }}</textarea>
                    <p class="{{ $leise }}">Klicken markiert den Text. Nur ein Entwurf, nichts wird automatisch versendet.</p>
                </div>
            @endif

            <div class="flex items-center justify-end gap-2">
                <x-fa::button size="sm" variant="ghost" wire:click="toggleKiPanel({{ $sig->id }})">Schließen</x-fa::button>
                <x-foodalchemist::ki-action action="kiFixAusfuehren({{ $sig->id }})" target="kiFixAusfuehren" variant="primary"
                        :icon="$istFix ? 'heroicon-o-play' : 'heroicon-o-sparkles'" :label="$istFix ? 'Automatisch beheben' : 'Entwurf erzeugen'"
                        busy="Läuft …" />
            </div>
        </div>
    @endif

</div>
