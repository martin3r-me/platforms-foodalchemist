{{--
    Diktat-Knopf für EIN Briefing-Feld der Planungsstelle.

    Erwartet: $ziel = Property-Pfad aus Index::DIKTAT_ZIELE
              (z. B. 'fbBrief' oder 'eingabe.gericht.brief')
    Optional:  $mitLeitplanken = Scope-Name (rezept|gericht|concept) → zeigt zusätzlich
               den „Leitplanken aus Briefing"-Knopf. Nur die drei Erstell-Scopes haben Regler.
    Optional:  $mitRecorder = false blendet den Mikrofon-Recorder aus (Default true).
               Kurskorrektur „pro Tab genau EINE Diktierfunktion" (2026-09-19): die drei
               Creation-Scopes haben seither das Agent-Panel als einzigen Diktier-Weg
               (Transkript geht dort in dasselbe Feld, siehe VoiceModal::updatedAudio() +
               Planung\Index::agentDiktatUebernehmen()) — der „Leitplanken aus Briefing"-
               Knopf bleibt aber unabhängig vom Recorder bestehen, er liest nur das Feld.

    EIN Baustein statt sechs Kopien des Recorders: der MediaRecorder-Code ist der fehler-
    trächtigste Teil (Mime, Stream-Freigabe, Upload-Callback), und sechs Kopien hätten
    sechs Stellen zum Auseinanderlaufen.

    Reines STT, kein Tool-Loop — das Briefing soll exakt das sein, was gesagt wurde.
    Gedeutet wird danach sichtbar in EINEM Schritt (Leitplanken vorschlagen).
--}}
@php($mitLeitplanken = $mitLeitplanken ?? null)
@php($mitRecorder = $mitRecorder ?? true)

{{-- Spec 53/D: gemeinsamer Recorder-Baustein statt eigener Inline-MediaRecorder-Kopie (hart
     kodiertes `audio/webm;codecs=opus` scheiterte auf Safari vor jeder Aufnahme). `before`
     setzt `diktatZiel` VOR dem Mikrofonzugriff — dieses Diktat teilt sich `briefAudio` mit den
     anderen beiden Scopes, das Ziel muss also stehen, bevor der Upload beim Server ankommt. --}}
<div class="flex flex-wrap items-center gap-2 mt-1 mb-2" wire:key="diktat-recorder-{{ $ziel }}">
    @if($mitRecorder)
        <div class="contents" x-data="FaVoiceRecorder({
                property: 'briefAudio', maxMs: 20000, minMs: 700,
                before: async () => { await $wire.set('diktatZiel', @js($ziel)); },
             })">
            <button type="button" @click="laeuft ? stop() : start()" :disabled="! unterstuetzt"
                    :class="laeuft ? 'animate-pulse' : ''" class="{{ $btnGhost }} disabled:opacity-40 inline-flex items-center gap-1" data-planung-diktat="{{ $ziel }}">
                <span x-show="laeuft" x-cloak>@svg('heroicon-o-stop', 'w-3.5 h-3.5')</span>
                <span x-show="! laeuft">@svg('heroicon-o-microphone', 'w-3.5 h-3.5')</span>
                <span x-text="laeuft ? 'Stopp & übernehmen' : 'Briefing diktieren'"></span>
            </button>
            <span class="text-[11px] text-gray-500" x-show="laeuft" x-cloak data-planung-diktat-status><span x-text="sekunden"></span>s / 20s</span>
            <span class="text-[11px] text-gray-500" x-show="hochladenLaeuft" x-cloak>wird hochgeladen …</span>
            <span class="text-xs text-rose-500" x-show="fehler" x-cloak x-text="fehler" data-planung-diktat-fehler></span>
        </div>
    @endif

    @if($mitLeitplanken !== null)
        <button type="button" wire:click="leitplankenAusBriefing('{{ $mitLeitplanken }}')"
                wire:loading.attr="disabled" wire:target="leitplankenAusBriefing"
                class="{{ $btnGhost }} disabled:opacity-40 inline-flex items-center gap-1 whitespace-nowrap"
                data-planung-leitplanken-vorschlag="{{ $mitLeitplanken }}">
            @svg('heroicon-o-adjustments-horizontal', 'w-3.5 h-3.5')
            <span wire:loading.remove wire:target="leitplankenAusBriefing">Leitplanken aus Briefing</span>
            <span wire:loading wire:target="leitplankenAusBriefing">Leitplanken werden abgeleitet …</span>
        </button>
    @endif
</div>
