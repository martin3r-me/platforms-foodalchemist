{{-- Spec 60 · P7 — „Passt das zusammen?": Aussagen der Kombinationslogik mit Grundlage.
     Daten: Kombinationslogik::daten(). Gericht: Bestandteile = Basisrezepte, mit Vorschlägen.
     Basisrezept: eigenes Aromenprofil + Aussagen über seine Zutaten.

     Kompakt (Dominique 2026-10-07: „sehr viel Text", Spannung immer offen):
     - offen nur, was zu tun ist (Konflikt, Es fehlt); Harmonie, Spannung, Klassiker, ohne Zuordnung
       eingeklappt mit Anzahl, „passt"/„neutral" wie bisher unter „Weitere Paare".
     - je Aussage EINE Zeile mit Kurznamen (GP ohne Zusatz nach dem Doppelpunkt, Rezept ohne Klasse
       davor), Achse als Chip; Grundlage als Punkt (● belegt/gemessen, ○ ungeprüft), voller Satz + Grundlage
       im Tooltip. Die Aussage selbst (Text, Grundlage) bleibt unverändert — MCP/Generator lesen sie weiter. --}}
@props(['daten'])
@php
    $a = $daten['aussagen'] ?? [];
    $liste = fn (string $typ) => collect($a[$typ] ?? []);

    // Kurzname je Bestandteil: GP-Zeile (z…) = Produktname vor dem Doppelpunkt, Rezept (r…) = Name nach der Klasse.
    $kurz = function (string $label, string $schluessel): string {
        $l = trim(preg_replace('/^\[[^\]]+\]\s*/u', '', $label));
        if (str_starts_with($schluessel, 'r') && str_contains($l, ': ')) {
            return trim(mb_substr($l, mb_strpos($l, ': ') + 2));
        }
        if (str_starts_with($schluessel, 'z') && str_contains($l, ':')) {
            return trim(mb_substr($l, 0, mb_strpos($l, ':')));
        }

        return $l;
    };
    $namen = collect($daten['bestandteile'] ?? [])
        ->mapWithKeys(fn ($t) => [(string) $t['schluessel'] => $kurz((string) $t['label'], (string) $t['schluessel'])]);
    $n = fn ($x, int $i) => $namen[(string) ($x['bestandteile'][$i] ?? '')] ?? null;
    $achse = fn ($x) => $x['achse'] ? \Platform\FoodAlchemist\Enums\Achse::tryFrom($x['achse'])?->label() : null;

    // Zeile: [Chip, Text]. Fehlt ein Name (ältere Daten), bleibt der volle Satz stehen.
    $zeile = function (array $x) use ($n, $achse): array {
        [$a0, $a1] = [$n($x, 0), $n($x, 1)];
        return match ($x['typ']) {
            'spannung' => $a0 && $a1 ? [$achse($x), $a1.' → '.$a0] : [null, $x['text']],
            'konflikt' => $a0 && $a1 ? [null, $a0.' ↔ '.$a1] : [null, $x['text']],
            'bedarf_offen' => [$achse($x), ($a0 ? 'für '.$a0 : $x['text']).(str_starts_with($x['text'], 'Es fehlt') ? '' : ' · könnte fehlen')],
            'unbekannt' => [null, $a0 ?? $x['text']],
            default => $a0 && $a1 ? [null, $a0.' + '.$a1] : [null, $x['text']],
        };
    };
    $belegt = ['inspire_gemessen', 'naehrwert_belegt', 'dossier_geprueft', 'regel'];

    // [Titel, offen?, Ton]
    $gruppen = [
        'konflikt' => ['Konflikt', true, 'crit'],
        'bedarf_offen' => ['Es fehlt', true, 'warn'],
        'harmoniert' => ['Harmonie (gemessen)', false, null],
        'spannung' => ['Spannung', false, null],
        'kombination' => ['Klassiker', false, null],
        'unbekannt' => ['Ohne Aroma-Zuordnung', false, null],
    ];
    $leise = $liste('passt')->concat($liste('neutral'));
    $hatUngeprueft = collect($a)->flatten(1)->contains(fn ($x) => is_array($x) && ($x['grundlage'] ?? null) === 'dossier_entwurf');
@endphp
<div class="flex flex-col gap-2.5 text-[length:var(--fa-text-md)]" data-kombination data-art="{{ $daten['art'] ?? '' }}">
    <p class="text-[var(--fa-ink-2)]" data-zusammenfassung>{{ $daten['zusammenfassung'] ?? '' }}</p>

    @if(! empty($daten['profil']['anker']))
        <div data-profil>
            <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)] mb-1">Aromenprofil · {{ number_format((float) $daten['profil']['abdeckung'], 0, ',', '.') }} % der Masse zugeordnet</p>
            <div class="flex flex-wrap gap-1">
                @foreach($daten['profil']['anker'] as $k)
                    <span class="rounded-[var(--fa-radius-control)] bg-[var(--fa-hover)] px-2 py-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $k['name'] }} {{ number_format($k['anteil'], 0, ',', '.') }} %</span>
                @endforeach
            </div>
        </div>
    @endif

    @foreach($gruppen as $typ => [$titel, $offen, $ton])
        @php $eintraege = $liste($typ); @endphp
        @if($eintraege->isNotEmpty())
            <details @if($offen) open @endif class="group rounded-[var(--fa-radius-control)] {{ $ton ? 'bg-[var(--fa-'.$ton.'-soft)] px-2.5 py-1.5' : '' }}" data-gruppe="{{ $typ }}">
                <summary class="flex cursor-pointer list-none items-center gap-1.5 text-[length:var(--fa-text-sm)] font-medium {{ $ton ? 'text-[var(--fa-'.$ton.')]' : 'text-[var(--fa-ink-3)] hover:text-[var(--fa-ink-2)]' }}">
                    @svg('heroicon-m-chevron-right', 'w-3.5 h-3.5 shrink-0 transition-transform group-open:rotate-90')
                    {{ $titel }} <span class="tabular-nums opacity-70">{{ $eintraege->count() }}</span>
                </summary>
                <ul class="mt-1 flex flex-col gap-0.5" data-aussagen="{{ $typ }}">
                    @foreach($eintraege as $x)
                        @php [$chip, $text] = $zeile($x); @endphp
                        <li class="flex items-center gap-1.5 min-w-0 {{ $typ === 'unbekannt' ? 'text-[var(--fa-ink-3)]' : 'text-[var(--fa-ink)]' }}"
                            title="{{ $x['text'] }} · {{ $x['grundlage_label'] }}">
                            @if($chip)
                                <span class="shrink-0 rounded-[var(--fa-radius-pill)] border border-[var(--fa-line-strong)] px-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $chip }}</span>
                            @endif
                            <span class="truncate">{{ $text }}</span>
                            @if(($x['grundlage'] ?? 'keine') !== 'keine')
                                <span class="ml-auto shrink-0 w-2 h-2 rounded-full {{ in_array($x['grundlage'], $belegt, true) ? 'bg-[var(--fa-ok)]' : 'border border-[var(--fa-ink-3)]' }}"
                                      aria-label="{{ $x['grundlage_label'] }}"></span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    @endforeach

    @if(! empty($daten['vorschlaege']))
        <div data-vorschlaege>
            <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)] mb-1">Was dem Teller fehlt</p>
            <ul class="flex flex-col gap-2">
                @foreach($daten['vorschlaege'] as $v)
                    <li>
                        <span class="font-medium text-[var(--fa-ink)]">{{ \Platform\FoodAlchemist\Enums\Achse::from($v['achse'])->label() }}</span>
                        @foreach($v['formwechsel'] as $f)
                            <span class="block text-[var(--fa-ink-2)]">→ {{ $f }}</span>
                        @endforeach
                        @foreach($v['basisrezepte'] as $b)
                            <span class="block text-[var(--fa-ink-2)]">+ {{ $b['name'] }}<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]"> · harmoniert mit {{ $b['mit'] }}</span></span>
                        @endforeach
                        @if($v['formwechsel'] === [] && $v['basisrezepte'] === [])
                            <span class="block text-[var(--fa-ink-3)]">kein passendes Basisrezept im Bestand</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($leise->isNotEmpty())
        <details class="group" data-gruppe="weitere">
            <summary class="flex cursor-pointer list-none items-center gap-1.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)] hover:text-[var(--fa-ink-2)]">
                @svg('heroicon-m-chevron-right', 'w-3.5 h-3.5 shrink-0 transition-transform group-open:rotate-90')
                Weitere Paare <span class="tabular-nums opacity-70">{{ $leise->count() }}</span>
                <span class="font-normal">· nur „passen" oder ohne aromatischen Bezug</span>
            </summary>
            <ul class="mt-1 flex flex-col gap-0.5 text-[var(--fa-ink-3)]" data-aussagen="weitere">
                @foreach($leise as $x)
                    @php [, $text] = $zeile($x); @endphp
                    <li class="truncate" title="{{ $x['text'] }}">{{ $text }}<span class="text-[length:var(--fa-text-sm)]"> · {{ $x['typ'] === 'passt' ? 'passen' : 'kein Bezug' }}</span></li>
                @endforeach
            </ul>
        </details>
    @endif

    @if($hatUngeprueft)
        <p class="flex items-center gap-3 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" data-grundlage-legende>
            <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-[var(--fa-ok)]"></span> belegt oder gemessen</span>
            <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full border border-[var(--fa-ink-3)]"></span> aus Dossier, ungeprüft</span>
        </p>
    @endif
</div>
