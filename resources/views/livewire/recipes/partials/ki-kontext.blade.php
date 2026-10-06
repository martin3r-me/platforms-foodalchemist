{{-- KI-Kontext der Erstellung (2026-09-06) — Rezept UND Gericht. Zeigt NACH der Erstellung,
     welches Wissen der Generator gelesen hat (Kanäle aus dem Call-Log, Prompt-Größen aus der
     Messsonde). Vorher lebte diese Sicht nur im Generator-Modal, solange es offen war.
     Erwartet: $kiKontext = RecipeKiKontextService::fuerRezept() → null blendet die Sektion aus.
     fa-pass: x-fa::section + Tokens (hell + Werkbank-Modus). --}}
@php
    $kmFunktion = fn (?string $f) => [
        'vk.generator' => 'Gericht-Generator',
        'recipe.generator' => 'Basisrezept-Generator',
        'recipe.steps' => 'Zubereitungs-Schritte',
        'vk.plating' => 'Anrichte-Schritte',
    ][$f ?? ''] ?? (string) $f;
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
@endphp
@if(($kiKontext ?? null) !== null)
    @php
        $km = $kiKontext['meta'];
        $nDossiers = (int) ($km['dossiers'] ?? 0);
        $kmMeta = $nDossiers . ($nDossiers === 1 ? ' Wissensblatt' : ' Wissensblätter') . ' bei der Erstellung';
        $kmTeile = [$km['feature'] === 'vk.generator' ? 'Gericht-Generator' : 'Basisrezept-Generator'];
        if ($km['erstellt_am']) { $kmTeile[] = \Illuminate\Support\Carbon::parse($km['erstellt_am'])->format('d.m.Y H:i'); }
        if ($km['model']) { $kmTeile[] = $km['model']; }
        if ($km['tokens_in'] > 0) { $kmTeile[] = number_format($km['tokens_in'], 0, ',', '.') . ' Token gelesen, ' . number_format($km['tokens_out'], 0, ',', '.') . ' geschrieben'; }
    @endphp
    <x-fa::section variant="plain" title="KI-Kontext" icon="heroicon-o-cpu-chip" :meta="$kmMeta" data-sektion="ki-kontext">
        <p class="{{ $leise }}" data-ki-kontext-meta>{{ implode(' · ', $kmTeile) }}</p>
        <x-foodalchemist::kontext-inspektor :kontext="$kiKontext['kontext']" />
    </x-fa::section>
@endif

@if(!empty($kiHistorie))
    <x-fa::section variant="plain" title="KI-Aufrufe" icon="heroicon-o-clock" :meta="(string) count($kiHistorie)" data-sektion="ki-historie"
        description="Welche Quellen die KI tatsächlich gelesen hat, aus dem Aufrufprotokoll. Gespeichert ist der Stand des Regelwerks; Suchwissen und Einstellungen sind kein vollständiger historischer Stand.">
        <div class="flex flex-col gap-2">
            @foreach($kiHistorie as $call)
                <details class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-3 py-2" data-ki-call="{{ $call['call_log_id'] }}" wire:key="ki-call-{{ $call['call_log_id'] }}">
                    <summary class="cursor-pointer flex flex-wrap items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                        <span class="font-medium">{{ $kmFunktion($call['feature']) }}</span>
                        <span class="{{ $leise }} tabular-nums">{{ $call['erstellt_am'] }}</span>
                        @if($call['fehler'])<x-fa::badge tone="crit">Fehler</x-fa::badge>@endif
                    </summary>
                    <div class="mt-2 flex flex-col gap-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                        @if($call['knowledge_run_id'])
                            <p class="break-all">Wissenslauf: <span class="font-mono">{{ $call['knowledge_run_id'] }}</span></p>
                            <p class="break-all">Prüfsumme des Regelwerks: <span class="font-mono">{{ $call['knowledge_snapshot_hash'] ?? 'nicht protokolliert' }}</span></p>
                        @else
                            <p>Kein gespeicherter Wissenslauf für diesen Aufruf.</p>
                        @endif
                        @if($call['snapshot_fehler'])<x-fa::signal tone="warn">{{ $call['snapshot_fehler'] }}</x-fa::signal>@endif
                        @if($call['fehler'])<x-fa::signal tone="crit">{{ $call['fehler'] }}</x-fa::signal>@endif
                        <x-foodalchemist::kontext-inspektor :kontext="['wissen' => $call['kanaele'] ?: ['wissen' => $call['wissen_slugs']], 'prompt' => $call['groessen']]" />
                        @if(empty($call['wissen_slugs']))<p>Keine Wissensquellen protokolliert.</p>@endif
                        @foreach($call['snapshot_quellen'] as $source)
                            <details>
                                <summary class="cursor-pointer">Gespeicherte Quelle: <span class="font-mono">{{ $source['file'] }}</span></summary>
                                <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] p-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink)]">{{ $source['text'] }}</pre>
                            </details>
                        @endforeach
                        @if($call['ohne_gespeicherten_text'])
                            <p>Nur der Verweis ist gespeichert, kein historischer Volltext: <span class="font-mono">{{ implode(', ', $call['ohne_gespeicherten_text']) }}</span></p>
                        @endif
                    </div>
                </details>
            @endforeach
        </div>
    </x-fa::section>
@endif
