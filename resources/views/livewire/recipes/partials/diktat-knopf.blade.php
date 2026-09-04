{{--
    Diktat-Knopf für EIN Textfeld einer beliebigen Livewire-Komponente.

    Erwartet: $audio  = Name der Upload-Property (z. B. 'briefingAudio')
              $btnAi  = Button-Klasse aus dem Eltern-Scope (Ui::maps(), wie im Planungs-Pendant)
    Optional:  $label = Knopf-Text (Default „diktieren"), $marker = data-Attribut

    Gegenstück in der Komponente: `updated<Audio>()` transkribiert über SttServiceContract
    und HÄNGT den Text an das Zielfeld an (nie ersetzen — ein Diktat ist ein Nachtrag).

    Bewusst ohne Ziel-Umweg: das Planungs-Pendant
    (`livewire/planung/partials/diktat.blade.php`) muss über `diktatZiel` steuern,
    weil dort SECHS Briefing-Felder auf einen Recorder zeigen. Hier gehört zu jedem
    Feld genau eine Upload-Property, also entfällt der Umweg — und mit ihm die
    Fehlerquelle „Ziel nicht gesetzt, Text landet im falschen Feld".

    Reines STT, kein Tool-Loop: was gesagt wurde, steht danach im Feld.
--}}
@php($label = $label ?? 'diktieren')

<span x-data="{
        rec: null, chunks: [], laeuft: false,
        async start() {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1 } });
            this.chunks = [];
            this.rec = new MediaRecorder(stream, { mimeType: 'audio/webm;codecs=opus' });
            this.rec.ondataavailable = e => this.chunks.push(e.data);
            this.rec.onstop = () => {
                stream.getTracks().forEach(t => t.stop());
                $wire.upload(@js($audio), new Blob(this.chunks, { type: 'audio/webm' }), () => {}, () => {}, () => {});
            };
            this.rec.start(); this.laeuft = true;
        },
        stop() { this.rec?.stop(); this.laeuft = false; },
     }">
    <button type="button" @click="laeuft ? stop() : start()" :class="laeuft ? 'animate-pulse' : ''"
            class="{{ $btnAi }}" @isset($marker) data-diktat="{{ $marker }}" @endisset
            :title="laeuft ? 'Aufnahme beenden und übernehmen' : 'Statt tippen: sprechen'">
        <span x-show="laeuft" x-cloak>@svg('heroicon-o-stop', 'w-3.5 h-3.5')</span>
        <span x-show="! laeuft">@svg('heroicon-o-microphone', 'w-3.5 h-3.5')</span>
        <span x-text="laeuft ? 'Stopp & übernehmen' : @js($label)"></span>
    </button>
</span>
