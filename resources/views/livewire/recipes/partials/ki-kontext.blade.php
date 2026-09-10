{{-- KI-Kontext der Erstellung (2026-09-06) — Rezept UND Gericht. Zeigt NACH der Erstellung,
     welches Wissen der Generator gelesen hat (Kanäle aus dem Call-Log, Prompt-Größen aus der
     Messsonde). Vorher lebte diese Sicht nur im Generator-Modal, solange es offen war.
     Erwartet: $kiKontext = RecipeKiKontextService::fuerRezept() → null blendet die Sektion aus. --}}
@if(($kiKontext ?? null) !== null)
    @php
        $km = $kiKontext['meta'];
        $kmMeta = ($km['dossiers'] ?? 0) . ' Dossier' . (($km['dossiers'] ?? 0) === 1 ? '' : 's') . ' · Erstellung';
    @endphp
    <x-foodalchemist::section title="KI-Kontext" icon="heroicon-o-cpu-chip" :meta="$kmMeta" data-sektion="ki-kontext">
        @php
            $kmTeile = [$km['feature'] === 'vk.generator' ? 'Gericht-Generator' : 'Basisrezept-Generator'];
            if ($km['erstellt_am']) { $kmTeile[] = \Illuminate\Support\Carbon::parse($km['erstellt_am'])->format('d.m.Y H:i'); }
            if ($km['model']) { $kmTeile[] = $km['model']; }
            if ($km['tokens_in'] > 0) { $kmTeile[] = number_format($km['tokens_in'], 0, ',', '.') . ' / ' . number_format($km['tokens_out'], 0, ',', '.') . ' Token'; }
        @endphp
        <p class="text-[11px] text-gray-500" data-ki-kontext-meta>{{ implode(' · ', $kmTeile) }}</p>
        <x-foodalchemist::kontext-inspektor :kontext="$kiKontext['kontext']" />
    </x-foodalchemist::section>
@endif

@if(!empty($kiHistorie))
    <x-foodalchemist::section title="KI-Aufrufhistorie" icon="heroicon-o-clock" data-sektion="ki-historie">
        <p class="text-xs text-gray-500">Tatsächlich verwendete Quellen aus dem Aufrufprotokoll. Gespeichert ist der Kanonstand; Suchwissen und Einstellungen sind kein vollständiger historischer Profilstand.</p>
        @foreach($kiHistorie as $call)
            <details class="mt-3 rounded border border-black/10 p-3" data-ki-call="{{ $call['call_log_id'] }}">
                <summary class="cursor-pointer text-xs">{{ $call['feature'] }} · {{ $call['erstellt_am'] }} @if($call['fehler']) · Fehler @endif</summary>
                <div class="mt-2 space-y-2 text-xs">
                    @if($call['knowledge_run_id'])
                        <p class="break-all">Wissenslauf: {{ $call['knowledge_run_id'] }}</p>
                        <p class="break-all">Kanon-Fingerprint: {{ $call['knowledge_snapshot_hash'] ?? 'Nicht protokolliert' }}</p>
                    @else
                        <p>Kein gespeicherter Wissenslauf für diesen Aufruf.</p>
                    @endif
                    @if($call['snapshot_fehler'])<p class="text-amber-700">{{ $call['snapshot_fehler'] }}</p>@endif
                    @if($call['fehler'])<p class="text-red-700">{{ $call['fehler'] }}</p>@endif
                    <x-foodalchemist::kontext-inspektor :kontext="['wissen' => $call['kanaele'] ?: ['wissen' => $call['wissen_slugs']], 'prompt' => $call['groessen']]" />
                    @if(empty($call['wissen_slugs']))<p>Keine Wissensquellen protokolliert.</p>@endif
                    @foreach($call['snapshot_quellen'] as $source)
                        <details><summary class="cursor-pointer">Gespeicherte Quelle: {{ $source['file'] }}</summary>
                            <pre class="whitespace-pre-wrap break-words max-h-80 overflow-auto mt-2 text-xs">{{ $source['text'] }}</pre>
                        </details>
                    @endforeach
                    @if($call['ohne_gespeicherten_text'])
                        <p>Nur Quellenverweis gespeichert, kein historischer Volltext: {{ implode(', ', $call['ohne_gespeicherten_text']) }}</p>
                    @endif
                </div>
            </details>
        @endforeach
    </x-foodalchemist::section>
@endif
