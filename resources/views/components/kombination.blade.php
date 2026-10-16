{{-- Spec 60 · P7 — „Passt das zusammen?": Aussagen der Kombinationslogik mit Grundlage.
     Daten: Kombinationslogik::daten(). Gericht: Bestandteile = Basisrezepte, mit Vorschlägen.
     Basisrezept: eigenes Aromenprofil + Aussagen über seine Zutaten.

     Kompakt (Dominique 2026-10-07: „sehr viel Text", Spannung immer offen):
     - offen nur, was zu tun ist (Konflikt, Es fehlt); Harmonie, Spannung, Klassiker, ohne Zuordnung
       eingeklappt mit Anzahl, Grund-Aroma (★★)/„neutral" unter „Weitere Paare" — ★★ ist keine Empfehlung.
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

    // Hinweis-Satz „Hinweis zu <A> + <B>: <Begriff> – <Grund>" → nur „<Begriff> – <Grund>" (Labels können „:" enthalten).
    $voll = collect($daten['bestandteile'] ?? [])->mapWithKeys(fn ($t) => [(string) $t['schluessel'] => (string) $t['label']]);
    $hinweisRest = function (array $x) use ($voll): string {
        $kopf = 'Hinweis zu '.($voll[(string) ($x['bestandteile'][0] ?? '')] ?? '').' + '.($voll[(string) ($x['bestandteile'][1] ?? '')] ?? '').': ';

        return str_starts_with($x['text'], $kopf) ? mb_substr($x['text'], mb_strlen($kopf)) : $x['text'];
    };

    // Zeile: [Chip, Text]. Fehlt ein Name (ältere Daten), bleibt der volle Satz stehen.
    $zeile = function (array $x) use ($n, $achse, $hinweisRest): array {
        [$a0, $a1] = [$n($x, 0), $n($x, 1)];
        return match ($x['typ']) {
            'spannung' => $a0 && $a1 ? [$achse($x), $a1.' → '.$a0] : [null, $x['text']],
            'konflikt' => $a0 && $a1 ? [null, $a0.' ↔ '.$a1] : [null, $x['text']],
            'bedarf_offen' => [$achse($x), ($a0 ? 'für '.$a0 : $x['text']).(str_starts_with($x['text'], 'Es fehlt') ? '' : ' · könnte fehlen')
                .(preg_match('/ – (über die Zubereitung|Partner oder Zubereitung)$/u', $x['text'], $m) ? ' · '.$m[1] : '')],
            'unbekannt' => [null, $a0 ?? $x['text']],
            'hinweis' => [null, ($a0 && $a1 ? $a0.' + '.$a1.' · ' : '').$hinweisRest($x)],
            default => $a0 && $a1 ? [null, $a0.' + '.$a1] : [null, $x['text']],
        };
    };
    $belegt = ['inspire_gemessen', 'naehrwert_belegt', 'dossier_geprueft', 'regel'];

    // [Titel, offen?, Ton]
    // Composer-Auswahl (art=auswahl): „Fehlt noch" ist eine Chance, kein Fehler — eingeklappt, die Urteilszeile nennt es schon.
    $auswahl = ($daten['art'] ?? null) === 'auswahl';
    $gruppen = [
        'konflikt' => ['Konflikt', true, 'crit'],
        'bedarf_offen' => $auswahl ? ['Fehlt noch', false, null] : ['Es fehlt', true, 'warn'],
        'harmoniert' => ['Harmonie (gemessen)', false, null],
        'spannung' => ['Spannung', false, null],
        'kombination' => ['Klassiker', false, null],
        'hinweis' => ['Hinweis aus Dossier', false, null],   // grau, keine Ampel (Kurator 15.10.)
        'unbekannt' => ['Ohne Aroma-Zuordnung', false, null],
    ];
    $leise = $liste('passt')->concat($liste('neutral'));
    $hatUngeprueft = collect($a)->flatten(1)->contains(fn ($x) => is_array($x) && in_array($x['grundlage'] ?? null, ['dossier_entwurf', 'ki_einschaetzung'], true));
@endphp
<div class="flex flex-col gap-2.5 text-[length:var(--fa-text-md)]" data-kombination data-art="{{ $daten['art'] ?? '' }}">
    @if(! empty($daten['urteil']['text']))
        {{-- Ein Satz Urteil vor den Zahlen (Dominique 16.10.: „würdest du als User verstehen, ob das eine gute Kombi ist?") --}}
        <p class="font-medium {{ ($daten['urteil']['ton'] ?? null) === 'warn' ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-ink)]' }}" data-urteil>{{ $daten['urteil']['text'] }}</p>
    @endif
    <p class="text-[var(--fa-ink-2)]" data-zusammenfassung>{{ $daten['zusammenfassung'] ?? '' }}</p>
    @if(! empty($daten['technik']))
        <div data-technik>
            <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)] mb-1">Zubereitung</p>
            <ul class="flex flex-col gap-0.5 text-[var(--fa-ink-2)]">
                @foreach($daten['technik'] as $t)<li>→ {{ $t }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if(! empty($daten['profil']['anker']))
        <div data-profil>
            <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)] mb-1">Aromenprofil · {{ number_format((float) $daten['profil']['abdeckung'], 0, ',', '.') }} % der Masse zugeordnet</p>
            <div class="flex flex-wrap gap-1">
                @foreach($daten['profil']['anker'] as $k)
                    <span class="rounded-[var(--fa-radius-control)] bg-[var(--fa-hover)] px-2 py-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $k['name'] }} {{ number_format($k['anteil'], 0, ',', '.') }} %</span>
                @endforeach
            </div>
            @if(! empty($daten['profil']['eigenschaften']))
                {{-- Was das Basisrezept selbst liefert (Röstaroma, Fett, Knusper …), Stufe 0–3 als Punkte --}}
                <p class="mt-2 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)] mb-1">Liefert</p>
                <div class="flex flex-wrap gap-1" data-profil-liefert>
                    @foreach($daten['profil']['eigenschaften'] as $e)
                        @php $p = max(1, min(3, (int) round($e['stufe']))); @endphp
                        <span class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] px-2 py-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]"
                              title="Stufe {{ number_format($e['stufe'], 1, ',', '.') }} · {{ $e['grundlage'] }}">{{ $e['label'] }} <span class="tracking-tight">{{ str_repeat('●', $p).str_repeat('○', 3 - $p) }}</span></span>
                    @endforeach
                </div>
            @endif
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
            <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)] mb-1">{{ ($daten['modus'] ?? null) === 'basisrezept' ? 'Was der Zubereitung fehlt' : 'Was dem Teller fehlt' }}</p>
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
                        @foreach($v['zutaten'] ?? [] as $z)
                            <span class="block text-[var(--fa-ink-2)]" data-vorschlag-zutat>+ {{ $z }}</span>
                        @endforeach
                        @if($v['formwechsel'] === [] && $v['basisrezepte'] === [] && ($v['zutaten'] ?? []) === [])
                            <span class="block text-[var(--fa-ink-3)]">{{ ($v['deckung'] ?? 'partner') === 'zubereitung' ? 'über die Zubereitung lösen' : (($daten['modus'] ?? null) === 'basisrezept' ? 'keine passende Zutat mit ★★★ zur Auswahl' : 'kein passendes Basisrezept im Bestand') }}</span>
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
                <span class="font-normal">· nur Grund-Aromen gemeinsam oder ohne aromatischen Bezug</span>
            </summary>
            <ul class="mt-1 flex flex-col gap-0.5 text-[var(--fa-ink-3)]" data-aussagen="weitere">
                @foreach($leise as $x)
                    @php [, $text] = $zeile($x); @endphp
                    <li class="truncate" title="{{ $x['text'] }}">{{ $text }}<span class="text-[length:var(--fa-text-sm)]"> · {{ $x['typ'] === 'passt' ? 'Grund-Aroma' : 'kein Bezug' }}</span></li>
                @endforeach
            </ul>
        </details>
    @endif

    @if($hatUngeprueft)
        <p class="flex items-center gap-3 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" data-grundlage-legende>
            <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-[var(--fa-ok)]"></span> belegt oder gemessen</span>
            <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full border border-[var(--fa-ink-3)]"></span> ungeprüft (Dossier oder KI)</span>
        </p>
    @endif
</div>
