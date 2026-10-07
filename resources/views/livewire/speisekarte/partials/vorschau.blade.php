{{-- Speisekarte-Vorschau (Kundensicht, nur lesend, fa-pass 2026-10-05) — dieselben Daten wie Dokument
     und Präsentation. Namen über die Wording-Kette, Preise brutto/netto, Fußnoten.
     Die Markenfarbe ist ein Laufzeitwert der Karte; ohne eigene Farbe gilt der Akzent.
     Erwartet: $vorschau (SpeisekarteService::dokumentDaten). --}}
@php
    $brutto = $vorschau['brutto'];
    $brand = ($vorschau['branding']['color'] ?? null) ?: 'var(--fa-accent)';
    $leg = $vorschau['legende'];
@endphp

<div class="fa-surface p-6 md:p-8 min-w-0" wire:key="sk-vorschau-{{ $vorschau['karte']->id }}">
    @if($vorschau['branding']['logo'] ?? null)
        <div class="text-center mb-4"><img src="{{ $vorschau['branding']['logo'] }}" alt="" class="inline-block max-h-16 object-contain" /></div>
    @endif
    <h2 class="text-[length:var(--fa-text-2xl)] font-semibold tracking-tight text-center text-[var(--fa-ink)] break-words">{{ $vorschau['karte']->name }}</h2>
    <div class="mx-auto h-1 w-14 rounded my-3" style="background: {{ $brand }};"></div>
    @if($vorschau['branding']['cover'] ?? null)
        <div class="mb-5"><img src="{{ $vorschau['branding']['cover'] }}" alt="" class="w-full max-h-72 object-cover rounded-[var(--fa-radius-surface)]" /></div>
    @endif

    <div class="max-w-2xl mx-auto">
        @forelse($vorschau['rubriken'] as $rubrik)
            <div class="mb-6" @if($rubrik['depth'] > 0) style="margin-left: {{ $rubrik['depth'] }}rem" @endif>
                <h3 class="text-[length:var(--fa-text-md)] font-semibold uppercase tracking-wide border-b pb-1 mb-2" style="color: {{ $brand }}; border-color: color-mix(in srgb, {{ $brand }} 20%, transparent);">{{ $rubrik['title'] }}</h3>
                @if($rubrik['claim'])<p class="text-[length:var(--fa-text-md)] italic text-[var(--fa-ink-2)] mb-2">{{ $rubrik['claim'] }}</p>@endif

                @php
                    $prevVg = null;
                @endphp
                @foreach($rubrik['positionen'] as $pos)
                    {{-- Werkstrang M Phase D: Wahl-Gruppe „A oder B“ zwischen aufeinanderfolgenden Positionen derselben Gruppe. --}}
                    @if(($pos['variant_group_id'] ?? null) !== null && ($pos['variant_group_id'] ?? null) === $prevVg)
                        <div class="text-[length:var(--fa-text-sm)] italic text-[var(--fa-ink-3)] text-center py-0.5">oder</div>
                    @endif
                    @if($pos['typ'] === 'header')
                        <div class="font-semibold text-[length:var(--fa-text-sm)] uppercase tracking-wide text-[var(--fa-ink-2)] mt-3">{{ $pos['name'] }}</div>
                    @elseif($pos['typ'] === 'text')
                        <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] italic py-0.5">{{ $pos['consumer_text'] ?: $pos['name'] }}</p>
                    @elseif($pos['typ'] === 'spacer')
                        <div class="h-2"></div>
                    @else
                        @php
                            $w = $brutto ? $pos['vk_brutto'] : $pos['vk_netto'];
                        @endphp
                        <div class="flex items-baseline gap-2 py-1 text-[length:var(--fa-text-base)]">
                            <span class="min-w-0 break-words text-[var(--fa-ink)]">
                                {{ $pos['name'] }}@if(!empty($pos['codes']))<sup class="ml-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ implode(',', $pos['codes']) }}</sup>@endif
                            </span>
                            <span class="flex-1 min-w-4 border-b border-dotted border-[var(--fa-line-strong)] -translate-y-0.5"></span>
                            <span class="tabular-nums text-[var(--fa-ink)] whitespace-nowrap">
                                @if($w !== null){{ number_format((float) $w, 2, ',', '.') }} €@else<x-fa::money :value="null" />@endif
                            </span>
                        </div>
                        @if(!empty($pos['wein']))<div class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] -mt-0.5 mb-1">{{ implode(' · ', array_map('ucfirst', array_values($pos['wein']))) }}</div>@endif
                        @if($pos['consumer_text'])<div class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] -mt-0.5 mb-1">{{ $pos['consumer_text'] }}</div>@endif
                        @foreach(($pos['gaenge'] ?? []) as $gang)
                            <div class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] {{ $gang['type'] === 'header' ? 'font-semibold uppercase tracking-wide mt-1' : '' }}" style="padding-left: {{ (($gang['einrueckung'] ?? 0) + 1) * 0.5 }}rem">{{ $gang['text'] }}</div>
                        @endforeach
                    @endif
                    @php
                        $prevVg = $pos['variant_group_id'] ?? null;
                    @endphp
                @endforeach
            </div>
        @empty
            <x-fa::empty icon="heroicon-o-clipboard-document-list" title="Die Karte ist noch leer">
                Oben „Karte bearbeiten“ öffnen und Rubriken mit Gerichten anlegen.
            </x-fa::empty>
        @endforelse

        <div class="mt-6 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
            Alle Preise in Euro{{ $brutto ? ', inkl. ' . rtrim(rtrim(number_format($vorschau['mwstSatz'], 1, ',', '.'), '0'), ',') . ' % MwSt.' : ' (netto)' }}
        </div>

        @if(count($leg['allergene']) || count($leg['zusatzstoffe']))
            <div class="mt-3 pt-3 border-t border-[var(--fa-line)] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] leading-relaxed">
                @if(count($leg['allergene']))
                    <div><span class="font-semibold uppercase tracking-wide text-[var(--fa-ink)]">Allergene:</span>
                        @foreach($leg['allergene'] as $a)<span style="color: {{ $brand }}" class="font-semibold">{{ $a['code'] }}</span> {{ $a['label'] }}@if(!$loop->last) · @endif @endforeach
                    </div>
                @endif
                @if(count($leg['zusatzstoffe']))
                    <div class="mt-1"><span class="font-semibold uppercase tracking-wide text-[var(--fa-ink)]">Zusatzstoffe:</span>
                        @foreach($leg['zusatzstoffe'] as $z)<span style="color: {{ $brand }}" class="font-semibold">{{ $z['code'] }}</span> {{ $z['label'] }}@if(!$loop->last) · @endif @endforeach
                    </div>
                @endif
                <div class="mt-1 text-[var(--fa-ink-3)]">Kennzeichnung nach LMIV und ZZulV, im Zweifel wird gekennzeichnet; <span class="font-semibold" style="color: {{ $brand }}">*</span> = Spuren möglich.</div>
            </div>
        @endif
    </div>
</div>
