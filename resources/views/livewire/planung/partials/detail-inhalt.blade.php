{{-- Planung · Detail (rechte Leiste am Computer, Schritt „Kontext“ am Handy #1003 PR7): Kopf, offene Punkte, Stand je Stufe. --}}
<div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]">
    @if($active)
        @php
            $aktStufen = $kaskaden[$active->id]['stufen'] ?? [];
            $aktKaskade = $kaskaden[(int) $active->id]['status'] ?? 'entwurf';
            $aktPruefen = collect($aktStufen)->filter(fn ($st) => ($st['zustand'] ?? null) === 'prüfen');
        @endphp
        <x-fa::detail-kopf :title="$anzeigeTitel($active)"
            :subtitle="! empty($active->source_trend_refs) ? 'Herkunft: Trendradar (' . (count($active->source_trend_refs['trend_ids'] ?? []) + count($active->source_trend_refs['inspiration_ids'] ?? []) + count($active->source_trend_refs['fundstueck_ids'] ?? [])) . ' Impulse)' : 'Herkunft: Freier Brief'">
            @if(filled($active->title) && trim((string) $active->title) !== $anzeigeTitel($active))
                <p class="mt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] break-words">Planung: {{ $active->title }}</p>
            @endif
            <x-slot:badges>
                <x-fa::badge :tone="$kaskadeStatusTon[$aktKaskade] ?? 'neutral'">{{ \Illuminate\Support\Str::ucfirst($aktKaskade) }}</x-fa::badge>
                <x-fa::badge tone="accent">{{ $statusLabel[$active->status] ?? $active->status }}</x-fa::badge>
                <x-fa::badge title="Kreativ-Modus">{{ $modeLabel[$active->creative_mode] ?? $active->creative_mode }}</x-fa::badge>
            </x-slot:badges>
            @unless($imEditor ?? false){{-- #1003 PR7: im Kontext-Schritt ist der Editor schon offen --}}
            <x-slot:aktion>
                <x-fa::button variant="primary" size="sm" icon="heroicon-m-pencil-square" wire:click="oeffne({{ $active->id }})">Im Editor öffnen</x-fa::button>
            </x-slot:aktion>
            @endunless
            @unless($handy ?? false){{-- #1003 PR7: Planung verwerfen bleibt am Computer --}}
            <x-slot:menue>
                <x-fa::menu-item danger icon="heroicon-m-trash"
                    wire:click="planungVerwerfen({{ $active->id }})" wire:confirm="{{ $verwerfenFrage }}" data-planung-details-verwerfen>Planung verwerfen</x-fa::menu-item>
            </x-slot:menue>
            @endunless
        </x-fa::detail-kopf>

        @if($aktKaskade === 'fehlgeschlagen' || $aktPruefen->isNotEmpty())
            <div class="flex flex-col gap-1">
                @if($aktKaskade === 'fehlgeschlagen')
                    <x-fa::signal tone="crit">Die letzte Erstellung ist fehlgeschlagen.</x-fa::signal>
                @endif
                @foreach($aktPruefen as $st)
                    <x-fa::signal tone="warn">{{ $st['label'] }}: Ergebnisse prüfen.</x-fa::signal>
                @endforeach
            </div>
        @endif

        {{-- Stand je Stufe, ohne den Editor zu öffnen (finale Etappe). --}}
        <div class="flex flex-col">
            <x-fa::section variant="plain" title="Fortschritt je Stufe" icon="heroicon-o-queue-list" data-planung-kaskadenstand>
                @if($aktStufen === [])
                    <x-fa::empty compact icon="heroicon-o-queue-list" title="Noch nichts erstellt">Im Editor einen Brief formulieren und die Erstellung starten.</x-fa::empty>
                @else
                    <ul class="flex flex-col">
                        @foreach($aktStufen as $st)
                            <li class="flex items-center justify-between gap-2 py-1.5 border-b border-[var(--fa-line)] last:border-0 text-[length:var(--fa-text-md)]">
                                <span class="text-[var(--fa-ink)]">{{ $st['label'] }}</span>
                                <span class="flex items-center gap-2">
                                    <span class="text-[var(--fa-ink-3)] tabular-nums">{{ $st['fertig'] }}/{{ $st['total'] }}</span>
                                    <x-fa::badge :tone="$zustandTon[$st['zustand']] ?? 'neutral'">{{ \Illuminate\Support\Str::ucfirst($st['zustand']) }}</x-fa::badge>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-fa::section>
        </div>
    @else
        <x-fa::empty icon="heroicon-o-cursor-arrow-rays" title="Keine Planung gewählt">Eine Karte anklicken, um Herkunft, Stand und Fortschritt zu sehen.</x-fa::empty>
    @endif
</div>
