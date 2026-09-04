{{--
    Diktat-Knopf für EIN Briefing-Feld der Planungsstelle.

    Erwartet: $ziel = Property-Pfad aus Index::DIKTAT_ZIELE
              (z. B. 'fbBrief' oder 'eingabe.gericht.brief')
    Optional:  $mitLeitplanken = Scope-Name (rezept|gericht|concept) → zeigt zusätzlich
               den „Leitplanken aus Briefing"-Knopf. Nur die drei Erstell-Scopes haben Regler.

    EIN Baustein statt sechs Kopien des Recorders: der MediaRecorder-Code ist der fehler-
    trächtigste Teil (Mime, Stream-Freigabe, Upload-Callback), und sechs Kopien hätten
    sechs Stellen zum Auseinanderlaufen.

    Reines STT, kein Tool-Loop — das Briefing soll exakt das sein, was gesagt wurde.
    Gedeutet wird danach sichtbar in EINEM Schritt (Leitplanken vorschlagen).
--}}
@php($mitLeitplanken = $mitLeitplanken ?? null)

<div class="flex flex-wrap items-center gap-2 mt-1 mb-2"
     x-data="{
        rec: null, chunks: [], laeuft: false,
        async start() {
            await $wire.set('diktatZiel', @js($ziel));   /* erst das Ziel setzen, dann senden */
            const stream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1 } });
            this.chunks = [];
            this.rec = new MediaRecorder(stream, { mimeType: 'audio/webm;codecs=opus' });
            this.rec.ondataavailable = e => this.chunks.push(e.data);
            this.rec.onstop = () => {
                stream.getTracks().forEach(t => t.stop());
                $wire.upload('briefAudio', new Blob(this.chunks, { type: 'audio/webm' }), () => {}, () => {}, () => {});
            };
            this.rec.start(); this.laeuft = true;
        },
        stop() { this.rec?.stop(); this.laeuft = false; },
     }">
    <button type="button" @click="laeuft ? stop() : start()" :class="laeuft ? 'animate-pulse' : ''"
            class="{{ $btnGhost }} inline-flex items-center gap-1" data-planung-diktat="{{ $ziel }}">
        <span x-show="laeuft" x-cloak>@svg('heroicon-o-stop', 'w-3.5 h-3.5')</span>
        <span x-show="! laeuft">@svg('heroicon-o-microphone', 'w-3.5 h-3.5')</span>
        <span x-text="laeuft ? 'Stopp & übernehmen' : 'Briefing diktieren'"></span>
    </button>

    @if($mitLeitplanken !== null)
        <button type="button" wire:click="leitplankenAusBriefing('{{ $mitLeitplanken }}')" @disabled($laeuft ?? false)
                wire:loading.attr="disabled" wire:target="leitplankenAusBriefing"
                class="{{ $btnGhost }} disabled:opacity-40 inline-flex items-center gap-1 whitespace-nowrap"
                data-planung-leitplanken-vorschlag="{{ $mitLeitplanken }}">
            @svg('heroicon-o-adjustments-horizontal', 'w-3.5 h-3.5')
            <span wire:loading.remove wire:target="leitplankenAusBriefing">Leitplanken aus Briefing</span>
            <span wire:loading wire:target="leitplankenAusBriefing">Leitplanken werden abgeleitet …</span>
        </button>
    @endif
</div>
