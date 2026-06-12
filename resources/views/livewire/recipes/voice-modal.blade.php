{{-- M7-10: 🎙 Voice — MediaRecorder (Opus mono) → STT → Tool-Loop; Proposals mit Bestätigen (GL-07) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="voice-modal" title="🎙 Sprachbefehl" size="max-w-xl">
    <div x-data="{
            rec: null, chunks: [], läuft: false,
            async start() {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1 } });
                this.chunks = [];
                this.rec = new MediaRecorder(stream, { mimeType: 'audio/webm;codecs=opus' });
                this.rec.ondataavailable = e => this.chunks.push(e.data);
                this.rec.onstop = () => {
                    stream.getTracks().forEach(t => t.stop());
                    const blob = new Blob(this.chunks, { type: 'audio/webm' });
                    $wire.upload('audio', blob, () => {}, () => {}, () => {});
                };
                this.rec.start(); this.läuft = true;
            },
            stop() { this.rec?.stop(); this.läuft = false; },
         }" class="space-y-3" data-voice>

        <div class="flex items-center gap-2">
            <button type="button" @click="läuft ? stop() : start()"
                    :class="läuft ? 'animate-pulse' : ''" class="{{ $btnPrimary }}" data-voice-rec>
                <span x-text="läuft ? '⏹ Stopp & senden' : '🎙 Aufnahme starten'"></span>
            </button>
            <span class="text-xs text-gray-400">Kurz-Befehl sprechen (wenige Sekunden) — z. B. »Suche BBQ-Sauce«, »Öffne Rezept …«, »Klassifiziere …«</span>
        </div>

        {{-- Fallback/Sandbox: Befehl tippen --}}
        <form wire:submit.prevent="verarbeiteText($refs.cmd.value)" class="flex gap-2">
            <input type="text" x-ref="cmd" placeholder="… oder Befehl tippen" class="{{ $input }} flex-1" data-voice-text />
            <button type="submit" class="{{ $btnGhostXs }}">Senden</button>
        </form>

        @if($fehler !== null)<p class="text-sm text-rose-500" data-voice-fehler>{{ $fehler }}</p>@endif
        @if($transcript !== null)
            <p class="text-xs text-gray-400" data-voice-transcript>Transkript: »{{ $transcript }}«</p>
        @endif

        @if($ergebnis !== null)
            <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2 space-y-1.5" data-voice-ergebnis>
                @if($ergebnis['text'] !== null)
                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ $ergebnis['text'] }}</p>
                @endif
                <p class="text-[10px] text-gray-400">{{ $ergebnis['runden'] }} Runde(n) · {{ count($ergebnis['tool_laeufe']) }} Tool-Aufruf(e) · {{ $ergebnis['elapsed_ms'] }} ms</p>
                @foreach($ergebnis['proposals'] as $i => $p)
                    <div class="rounded bg-violet-500/10 border border-violet-500/30 px-2 py-1.5 text-sm" wire:key="vp-{{ $i }}" data-voice-proposal>
                        ✨ Speisen-Klasse: <span class="font-medium">{{ $p['klasse_name'] ?? 'kein Treffer' }}</span>
                        <span class="text-xs text-gray-400">· {{ round(($p['confidence'] ?? 0) * 100) }} %</span>
                        @if($p['accepted'] ?? false)
                            <span class="{{ $pill }} {{ $variantPill['success'] }} ml-1">übernommen</span>
                        @elseif(($p['klasse_id'] ?? null) !== null)
                            <button type="button" wire:click="proposalUebernehmen({{ $i }})" class="{{ $btnGhostXs }} text-emerald-600 ml-1" data-voice-proposal-accept>Bestätigen</button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-foodalchemist::modal>
