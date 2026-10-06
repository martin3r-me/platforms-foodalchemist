{{-- Speiseplan-Vorschau = Aushang (Druck-Layout, nur lesend) — fa-pass (2026-10-05), nur Tokens.
     Raster Linie × Tag + Legende. Erwartet: $vorschau (SpeiseplanService::dokumentDaten). --}}
@php
    $leg = $vorschau['legende'];
@endphp

<section class="fa-surface p-6 flex flex-col gap-4 min-w-0" wire:key="sp-vorschau-{{ $vorschau['plan']->id }}" aria-label="Aushang-Vorschau">
    <header class="text-center">
        <h2 class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">{{ $vorschau['plan']->name }}</h2>
        <p class="mt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $vorschau['mahlzeitLabel'] ?? '' }} · {{ $vorschau['kwLabel'] ?? '' }}</p>
    </header>

    <div class="overflow-x-auto">
        <table class="fa-table">
            <thead>
                <tr>
                    <th>Linie</th>
                    @foreach($vorschau['tage'] as $t)
                        <th>{{ $t['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($vorschau['zeilen'] as $zeile)
                    <tr class="align-top">
                        <td class="align-top whitespace-nowrap">
                            <span class="inline-flex items-center gap-2 font-medium text-[var(--fa-ink)]">
                                <span class="w-2.5 h-2.5 rounded-full shrink-0 {{ $zeile['color'] ? '' : 'bg-[var(--fa-ink-3)]' }}" @if($zeile['color']) style="background: {{ $zeile['color'] }}" @endif></span>
                                {{ $zeile['linie'] }}
                            </span>
                        </td>
                        @foreach($vorschau['tage'] as $t)
                            <td class="align-top min-w-[9rem] text-[var(--fa-ink)]">
                                @forelse($zeile['zellen'][$t['ymd']] ?? [] as $e)
                                    <div class="py-0.5">
                                        <span>{{ $e['name'] }}</span>@if(!empty($e['codes']))<sup class="ml-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ implode(',', $e['codes']) }}</sup>@endif
                                    </div>
                                @empty
                                    <span class="text-[var(--fa-ink-3)]">–</span>
                                @endforelse
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($vorschau['tage']) + 1 }}">
                        <x-fa::empty icon="heroicon-o-calendar-days" title="Noch keine Belegung">Oben „Plan bearbeiten" wählen und Gerichte in die Tage setzen.</x-fa::empty>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if(count($leg['allergene']) || count($leg['zusatzstoffe']))
        <footer class="pt-3 border-t border-[var(--fa-line)] flex flex-col gap-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] leading-relaxed">
            @if(count($leg['allergene']))
                <p><span class="font-semibold text-[var(--fa-ink)]">Allergene:</span>
                    @foreach($leg['allergene'] as $a)<span class="font-semibold text-[var(--fa-accent)]">{{ $a['code'] }}</span> {{ $a['label'] }}@if(!$loop->last) · @endif @endforeach
                </p>
            @endif
            @if(count($leg['zusatzstoffe']))
                <p><span class="font-semibold text-[var(--fa-ink)]">Zusatzstoffe:</span>
                    @foreach($leg['zusatzstoffe'] as $z)<span class="font-semibold text-[var(--fa-accent)]">{{ $z['code'] }}</span> {{ $z['label'] }}@if(!$loop->last) · @endif @endforeach
                </p>
            @endif
            <p class="text-[var(--fa-ink-3)]">Kennzeichnung nach LMIV und ZZulV, nach dem Vorsorgeprinzip über alle Komponenten; <span class="font-semibold text-[var(--fa-accent)]">*</span> = Spuren möglich.</p>
        </footer>
    @endif
</section>
