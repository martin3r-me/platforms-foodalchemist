{{-- Einstellungen › Übersicht (Neuordnung 23.10., E-2): Kachel je Reiter mit zählbaren Fakten, Link je Bereich. --}}
@php
    $ton = ['ok' => 'text-[var(--fa-ok)]', 'offen' => 'text-[var(--fa-warn)]', 'info' => 'text-[var(--fa-ink-3)]'];
    $zeichen = ['ok' => 'heroicon-m-check-circle', 'offen' => 'heroicon-m-exclamation-circle', 'info' => 'heroicon-m-information-circle'];
@endphp
<div class="flex flex-col gap-5" data-settings-start>
    @if($offen + $erledigt > 0)
        <div class="flex flex-wrap items-center gap-3 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]" data-start-fortschritt>
            <span>Grundeinstellungen</span>
            {{-- E-3: Fortschritt im FA-Akzent (Grün bleibt für „eingerichtet“ je Zeile) --}}
            <span class="w-40 h-2 rounded-full bg-[var(--fa-neutral-soft)] overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $offen + $erledigt }}" aria-valuenow="{{ $erledigt }}">
                <span class="block h-2 bg-[var(--fa-accent)]" style="width: {{ (int) round(100 * $erledigt / max(1, $offen + $erledigt)) }}%"></span>
            </span>
            <strong class="text-[var(--fa-ink)]">{{ $erledigt }} von {{ $offen + $erledigt }}</strong>{{ $offen > 0 ? ' · ' . $offen . ' offen' : '' }}
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach($kacheln as $key => $k)
            <div class="rounded-[var(--fa-radius-card)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-4 flex flex-col gap-2" data-start-kachel="{{ $key }}">
                <h3 class="flex items-center gap-2 text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]"><span class="w-2 h-2 rounded-full bg-[var(--fa-accent)]"></span>{{ $k['label'] }}</h3>
                <ul class="flex flex-col gap-1.5">
                    @foreach($k['zeilen'] as $z)
                        <li>
                            <a href="{{ $z['url'] }}" wire:navigate class="flex items-start gap-2 rounded-[var(--fa-radius-control)] -mx-1 px-1 py-0.5 hover:bg-[var(--fa-hover)]" data-start-link="{{ $z['sektion'] }}">
                                @if($z['status'] !== null)
                                    <span class="mt-0.5 shrink-0 {{ $ton[$z['status']['zustand']] }}" data-start-zustand="{{ $z['status']['zustand'] }}">@svg($zeichen[$z['status']['zustand']], 'w-4 h-4')</span>
                                @else
                                    <span class="mt-0.5 shrink-0 text-[var(--fa-ink-3)]">@svg('heroicon-m-chevron-right', 'w-4 h-4')</span>
                                @endif
                                <span class="min-w-0">
                                    <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $z['label'] }}</span>
                                    @if($z['status'] !== null)<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]"> · {{ $z['status']['text'] }}</span>@endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach

        @if($seiten !== [])
            <div class="rounded-[var(--fa-radius-card)] border border-dashed border-[var(--fa-line-strong)] p-4 flex flex-col gap-2" data-start-kachel="seiten">
                <h3 class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">Eigene Seiten</h3>
                @foreach($seiten as $key => $s)
                    <a href="{{ $s['url'] }}" wire:navigate class="flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-accent)] hover:underline" data-start-seite="{{ $key }}">
                        @svg('heroicon-m-arrow-top-right-on-square', 'w-4 h-4'){{ $s['label'] }}</a>
                @endforeach
            </div>
        @endif
    </div>
</div>
