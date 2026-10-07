{{--
    Diktat-Knopf für EIN Textfeld einer beliebigen Livewire-Komponente.

    Erwartet: $audio  = Name der Upload-Property (z. B. 'briefingAudio')
    Optional:  $label = Knopf-Text (Default „Diktieren"), $marker = data-Attribut,
               $btnAi = eigene Knopf-Klasse (z. B. Küchen-Wand im Tagesplan). Ohne Angabe gilt die
                        KI-Knopf-Optik aus den Tokens (hell + Werkbank-Modus).

    Gegenstück in der Komponente: `updated<Audio>()` transkribiert über SttServiceContract
    und HÄNGT den Text an das Zielfeld an (nie ersetzen — ein Diktat ist ein Nachtrag).

    Bewusst ohne Ziel-Umweg: das Planungs-Pendant
    (`livewire/planung/partials/diktat.blade.php`) muss über `diktatZiel` steuern,
    weil dort SECHS Briefing-Felder auf einen Recorder zeigen. Hier gehört zu jedem
    Feld genau eine Upload-Property, also entfällt der Umweg — und mit ihm die
    Fehlerquelle „Ziel nicht gesetzt, Text landet im falschen Feld".

    Reines STT, kein Tool-Loop: was gesagt wurde, steht danach im Feld.
--}}
@php
    $label = $label ?? 'Diktieren';
    $knopfKlasse = $btnAi ?? 'inline-flex items-center gap-1.5 h-7 px-2.5 whitespace-nowrap rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] font-medium bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] border border-[var(--fa-accent-line)] hover:bg-[var(--fa-accent-soft-hover)] transition-colors duration-150';
@endphp

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
            class="{{ $knopfKlasse }}" @isset($marker) data-diktat="{{ $marker }}" @endisset
            :title="laeuft ? 'Aufnahme beenden und übernehmen' : 'Sprechen statt tippen'">
        <span x-show="laeuft" x-cloak>@svg('heroicon-o-stop', 'w-3.5 h-3.5')</span>
        <span x-show="! laeuft">@svg('heroicon-o-microphone', 'w-3.5 h-3.5')</span>
        <span x-text="laeuft ? 'Stoppen und übernehmen' : @js($label)"></span>
    </button>
</span>
