{{-- #380 Q2 — Kundensichere Preis-Aufschlüsselung: Kapitel · Pax · €/Person · Zwischensumme + netto/MwSt/brutto.
     KEIN EK/Marge (interna-frei). Datenquelle: content.preis_aufschluesselung (normalizeAngebot). --}}
@php($pa = $content['preis_aufschluesselung'] ?? null)
@php($nf = fn ($v) => number_format((float) $v, 2, ',', '.'))
@php($modus = $style['preis_anzeige'] ?? 'beide')
@if($pa && count($pa['zeilen'] ?? []))
    <div class="pt-measure pt-reveal" style="margin:1.5rem auto;">
        <h3 style="font-size:1.05rem;font-weight:700;color:var(--pt-text,#111827);margin:0 0 .5rem;">Preis-Übersicht</h3>
        <div style="border-top:2px solid var(--pt-accent,#6d28d9);">
            <div style="display:flex;gap:.75rem;padding:.35rem 0;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--pt-muted,#9ca3af);">
                <span style="flex:1;">Leistung</span><span style="width:5rem;text-align:right;">Gäste</span><span style="width:6.5rem;text-align:right;">€/Person</span><span style="width:7rem;text-align:right;">Summe</span>
            </div>
            @foreach($pa['zeilen'] as $z)
                <div style="display:flex;gap:.75rem;padding:.4rem 0;border-top:1px solid rgba(0,0,0,.06);font-size:.9rem;">
                    <span style="flex:1;color:var(--pt-text,#374151);">{{ $z['titel'] }}</span>
                    <span style="width:5rem;text-align:right;color:var(--pt-muted,#6b7280);">{{ $z['pax'] ?: '—' }}</span>
                    <span style="width:6.5rem;text-align:right;color:var(--pt-muted,#6b7280);">
                        @if(($z['alternativen'] ?? false) && ($z['preis_range'] ?? null))
                            {{ $z['preis_range']['min'] !== null ? $nf($z['preis_range']['min']) : '—' }}–{{ $z['preis_range']['max'] !== null ? $nf($z['preis_range']['max']) : '—' }} €
                        @elseif(($z['vk_pro_person'] ?? null) !== null)
                            {{ $nf($z['vk_pro_person']) }} €
                        @else — @endif
                    </span>
                    <span style="width:7rem;text-align:right;font-weight:600;color:var(--pt-text,#374151);">{{ ($z['gesamt'] ?? null) !== null ? $nf($z['gesamt']) . ' €' : '—' }}</span>
                </div>
            @endforeach
            @if($modus !== 'brutto')
                <div style="display:flex;gap:.75rem;padding:.5rem 0 .25rem;margin-top:.25rem;border-top:2px solid var(--pt-accent,#6d28d9);font-size:.95rem;font-weight:700;color:var(--pt-text,#111827);">
                    <span style="flex:1;">Gesamt (netto)</span><span style="width:5rem;text-align:right;color:var(--pt-muted,#9ca3af);font-weight:400;">Ø {{ $pa['pax'] ?: '—' }}</span><span style="width:6.5rem;"></span><span style="width:7rem;text-align:right;">{{ $nf($pa['netto']) }} €</span>
                </div>
            @endif
            @if($modus === 'beide')
                <div style="display:flex;gap:.75rem;padding:.2rem 0;font-size:.85rem;color:var(--pt-muted,#6b7280);">
                    <span style="flex:1;">zzgl. MwSt ({{ rtrim(rtrim(number_format((float) $pa['mwst_satz'], 1, ',', '.'), '0'), ',') }} %)</span><span style="width:5rem;"></span><span style="width:6.5rem;"></span><span style="width:7rem;text-align:right;">{{ $nf($pa['mwst_betrag']) }} €</span>
                </div>
            @endif
            @if($modus !== 'netto')
                <div style="display:flex;gap:.75rem;padding:.35rem 0;{{ $modus === 'brutto' ? 'margin-top:.25rem;border-top:2px solid var(--pt-accent,#6d28d9);' : 'border-top:1px solid rgba(0,0,0,.06);' }}font-size:1.05rem;font-weight:700;color:var(--pt-text,#111827);">
                    <span style="flex:1;">Gesamt (brutto)</span><span style="width:5rem;text-align:right;color:var(--pt-muted,#9ca3af);font-weight:400;">@if($modus === 'brutto')Ø {{ $pa['pax'] ?: '—' }}@endif</span><span style="width:6.5rem;"></span><span style="width:7rem;text-align:right;">{{ $nf($pa['brutto']) }} €</span>
                </div>
                @if($modus === 'brutto')
                    <div style="display:flex;gap:.75rem;padding:.2rem 0;font-size:.8rem;color:var(--pt-muted,#9ca3af);">
                        <span style="flex:1;">inkl. {{ rtrim(rtrim(number_format((float) $pa['mwst_satz'], 1, ',', '.'), '0'), ',') }} % MwSt ({{ $nf($pa['mwst_betrag']) }} €)</span>
                    </div>
                @endif
                        @endif
            @if(! empty($pa['zzgl']))
                {{-- Spec 94b: zzgl. nach Aufwand (geschätzt, nicht im Gesamt) und voraussichtliche Gesamtsumme --}}
                <div class="pt-zzgl" data-pt-zzgl>
                    <div class="pt-zzgl-titel">Zzgl. nach Aufwand{{ collect($pa['zzgl'])->contains(fn ($z) => $z['verbrauch'] ?? false) ? ' bzw. Verbrauch' : '' }} (geschätzt)</div>
                    @foreach($pa['zzgl'] as $z)
                        <div class="pt-zzgl-zeile"><span>{{ $z['titel'] }}@if($z['verbrauch'] ?? false) nach Verbrauch @endif · ca. {{ $z['menge'] }} {{ $z['einheit'] }} à {{ $nf($z['satz']) }} €@if(! empty($z['deckel'])) · höchstens {{ $nf($z['deckel']) }} €@endif</span><span>≈ {{ $nf($z['betrag']) }} €</span></div>
                    @endforeach
                    <div class="pt-zzgl-zeile pt-zzgl-summe" data-pt-voraussichtlich><span>Voraussichtlich gesamt inkl. Aufwand (netto)</span><span>ca. {{ $nf($pa['voraussichtlich_netto']) }} €</span></div>
                    <div class="pt-zzgl-hinweis">Der Aufwand ist geschätzt und nicht im Gesamtpreis enthalten. Abgerechnet wird nach tatsächlichem Aufwand bzw. Verbrauch, höchstens bis zum genannten Höchstbetrag.</div>
                </div>
            @endif
        </div>
    </div>
@endif
