{{-- #380 Q2 — Kundensichere Preis-Aufschlüsselung: Kapitel · Pax · €/Person · Zwischensumme + netto/MwSt/brutto.
     KEIN EK/Marge (interna-frei). Datenquelle: content.preis_aufschluesselung (normalizeAngebot). --}}
@php($pa = $content['preis_aufschluesselung'] ?? null)
@php($nf = fn ($v) => number_format((float) $v, 2, ',', '.'))
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
            <div style="display:flex;gap:.75rem;padding:.5rem 0 .25rem;margin-top:.25rem;border-top:2px solid var(--pt-accent,#6d28d9);font-size:.95rem;font-weight:700;color:var(--pt-text,#111827);">
                <span style="flex:1;">Gesamt (netto)</span><span style="width:5rem;text-align:right;color:var(--pt-muted,#9ca3af);font-weight:400;">Ø {{ $pa['pax'] ?: '—' }}</span><span style="width:6.5rem;"></span><span style="width:7rem;text-align:right;">{{ $nf($pa['netto']) }} €</span>
            </div>
            <div style="display:flex;gap:.75rem;padding:.2rem 0;font-size:.85rem;color:var(--pt-muted,#6b7280);">
                <span style="flex:1;">zzgl. MwSt ({{ rtrim(rtrim(number_format((float) $pa['mwst_satz'], 1, ',', '.'), '0'), ',') }} %)</span><span style="width:5rem;"></span><span style="width:6.5rem;"></span><span style="width:7rem;text-align:right;">{{ $nf($pa['mwst_betrag']) }} €</span>
            </div>
            <div style="display:flex;gap:.75rem;padding:.35rem 0;border-top:1px solid rgba(0,0,0,.06);font-size:1.05rem;font-weight:700;color:var(--pt-text,#111827);">
                <span style="flex:1;">Gesamt (brutto)</span><span style="width:5rem;"></span><span style="width:6.5rem;"></span><span style="width:7rem;text-align:right;">{{ $nf($pa['brutto']) }} €</span>
            </div>
        </div>
    </div>
@endif
