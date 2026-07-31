{{--
    Spec 27 Phase 4 — Anleitung als Schritt-Karten im Küchen-Druck.
    Erwartet (via @include): $schritte (list), $zubereitung (?string Fallback),
    $mitFotos (bool), $istPdf (bool).

    Fällt auf den Freitext zurück, wenn ein Rezept noch keine Schritte hat (Bestand
    vor dem Backfill / Alt-Auftrag ohne steps_snapshot) — nichts geht verloren.
    Fotos: im PDF wird der lokale Dateipfad genutzt (DomPDF lädt remote URLs nur mit
    isRemoteEnabled), im HTML-Druck die URL.
--}}
@if(!empty($schritte))
    <div class="anleitung">
        @php($letztePhase = '__init__')
        @foreach($schritte as $s)
            @if(($s['phase'] ?? '') !== $letztePhase)
                @php($letztePhase = $s['phase'] ?? '')
                @if($letztePhase !== '')
                    <p class="anleitung-phase">{{ $letztePhase }}</p>
                @endif
            @endif
            <div class="schritt">
                <span class="schritt-nr">{{ $s['nr'] }}</span>
                <div class="schritt-body">
                    <div class="schritt-text">{{ $s['text'] }}</div>
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
                </div>
            </div>
        @endforeach
    </div>
@elseif(!empty($zubereitung))
    <div class="zubereitung-fallback">{{ $zubereitung }}</div>
@endif
