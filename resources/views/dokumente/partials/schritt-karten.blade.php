{{--
    Spec 27 Phase 4 — Anleitung als Schritt-Karten im Küchen-Druck.
    Erwartet (via @include): $schritte (list), $zubereitung (?string Fallback),
    $mitFotos (bool), $istPdf (bool).

    Fällt auf den Freitext zurück, wenn ein Rezept noch keine Schritte hat (Bestand
    vor dem Backfill / Alt-Auftrag ohne steps_snapshot) — nichts geht verloren.
    Fotos: im PDF wird der lokale Dateipfad genutzt (DomPDF lädt remote URLs nur mit
    isRemoteEnabled), im HTML-Druck die URL.

    fa-pass Druck-Muster (2026-10-05): Tabelle Nummer | Text, Phasen als Zwischenzeile.
    Im Freitext-Rückfall werden Markdown-Zwischentitel („## Mise en Place") als Phase
    gesetzt statt als Rohzeichen gedruckt — der Text wird vorher escaped.
--}}
@if(!empty($schritte))
    <div class="anleitung">
        <table class="schritte">
            <tbody>
            @php($letztePhase = '__init__')
            @foreach($schritte as $s)
                @if(($s['phase'] ?? '') !== $letztePhase)
                    @php($letztePhase = $s['phase'] ?? '')
                    @if($letztePhase !== '')
                        <tr><td class="schritt-nr phase-nr"></td><td class="anleitung-phase">{{ $letztePhase }}</td></tr>
                    @endif
                @endif
                <tr class="schritt">
                    <td class="schritt-nr">{{ $s['nr'] }}</td>
                    <td class="schritt-text">
                        {{ $s['text'] }}
                        @if(($mitFotos ?? true) && !empty($s['fotos']))
                            <div class="schritt-fotos">
                                @foreach($s['fotos'] as $f)
                                    @php($quelle = ($istPdf ?? false) ? ($f['pfad_abs'] ?? null) : ($f['url'] ?? null))
                                    @if($quelle)
                                        <span class="schritt-foto">
                                            <img src="{{ $quelle }}" alt="{{ $f['caption'] ?? ('Schritt ' . $s['nr']) }}" />
                                            @if($f['caption'] ?? null)<span class="cap">{{ $f['caption'] }}</span>@endif
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@elseif(!empty($zubereitung))
    <div class="zubereitung-fallback">{!! preg_replace('/^[ \t]*#{1,6}[ \t]+(.+?)[ \t]*$/m', '<span class="zf-phase">$1</span>', e(trim((string) preg_replace("/\n(?:[ \t]*\n)+/", "\n", str_replace("\r", '', (string) $zubereitung))))) !!}</div>
@endif
