{{-- Spec 80 D4: Rezeptansicht — Zutaten mit Herkunft, Zubereitung, „Woher das kommt" (Suchbegriffe, Wissen,
     Bestand, Pairing, beim Anlegen korrigiert). Basisrezept = Ansatz, nie Portionen. --}}
@php
    $klein = 'text-[length:var(--fa-text-sm)]';
    $kopf = 'text-[length:var(--fa-text-sm)] font-medium uppercase tracking-wide text-[var(--fa-ink-3)]';
    $menge = fn ($m) => rtrim(rtrim(number_format((float) $m, 2, ',', '.'), '0'), ',');
    $wissenName = fn (string $slug) => \Illuminate\Support\Str::of($slug)->before('@')->afterLast('--')->replace(['-', '_'], ' ')->ucfirst();
    $w = $rezept['woher'];
@endphp
<div class="flex flex-col gap-4 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-3" data-fortschritt-rezept>
    <div class="flex flex-col gap-1.5">
        <p class="{{ $kopf }}">Zutaten{{ $rezept['ansatz'] ? ' · Ansatz ' . $menge($rezept['ansatz']) . ' kg' : '' }}</p>
        <table class="w-full {{ $klein }}">
            <tbody>
                @foreach($rezept['zutaten'] as $z)
                    <tr class="border-t border-[var(--fa-line)]">
                        <td class="w-16 py-1.5 pr-2 text-right tabular-nums text-[var(--fa-ink)]">{{ $menge($z['menge']) }}</td>
                        <td class="w-8 py-1.5 text-[var(--fa-ink-3)]">{{ $z['einheit'] }}</td>
                        <td class="py-1.5 {{ $z['verweis'] ? 'text-[var(--fa-accent)]' : 'text-[var(--fa-ink)]' }}">
                            {{ $z['verweis'] ? '↳ ' : '' }}{{ $z['text'] }}
                            @if($z['notiz'] !== '')<span class="text-[var(--fa-ink-3)]"> · {{ $z['notiz'] }}</span>@endif
                        </td>
                        <td class="py-1.5 pl-2 text-right text-[var(--fa-ink-3)]">{{ $z['herkunft'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($rezept['zubereitung'] !== [])
        <div class="flex flex-col gap-1.5">
            <p class="{{ $kopf }}">Zubereitung</p>
            <ol class="flex list-decimal flex-col gap-1 pl-5 {{ $klein }} text-[var(--fa-ink-2)]">
                @foreach($rezept['zubereitung'] as $schritt)<li>{{ $schritt }}</li>@endforeach
            </ol>
        </div>
    @endif

    <div class="flex flex-col gap-1.5">
        <p class="{{ $kopf }}">Woher das kommt</p>
        <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1.5 {{ $klein }}">
            <dt class="text-[var(--fa-ink-3)]">Suchbegriffe</dt>
            <dd class="flex flex-wrap gap-1">
                @forelse($w['suchbegriffe'] as $t)<x-fa::badge tone="neutral">{{ $t }}</x-fa::badge>@empty<span class="text-[var(--fa-ink-3)]">—</span>@endforelse
            </dd>
            <dt class="text-[var(--fa-ink-3)]">Wissen</dt>
            <dd class="text-[var(--fa-ink-2)]">{{ $w['wissen'] !== [] ? collect($w['wissen'])->take(6)->map($wissenName)->implode(' · ') . (count($w['wissen']) > 6 ? ' · +' . (count($w['wissen']) - 6) : '') : '—' }}</dd>
            <dt class="text-[var(--fa-ink-3)]">Pairing</dt>
            <dd class="text-[var(--fa-ink-2)]">
                {{ $w['pairing'] !== [] ? implode(', ', $w['pairing']) : '—' }}
                @if($w['ohne_anker'] !== [])<span class="text-[var(--fa-ink-3)]"> · kein Anker für: {{ implode(', ', $w['ohne_anker']) }}</span>@endif
            </dd>
            @if($w['abgelehnt'] !== [])
                <dt class="text-[var(--fa-ink-3)]">Bestand</dt>
                <dd class="flex flex-col gap-0.5 text-[var(--fa-ink-2)]">
                    @foreach($w['abgelehnt'] as $a)<span>nicht übernommen: {{ $a['name'] ?? '' }} — {{ $a['grund'] ?? '' }}</span>@endforeach
                </dd>
            @endif
            @if($w['korrigiert'] !== [])
                <dt class="text-[var(--fa-ink-3)]">Korrigiert</dt>
                <dd class="flex flex-col gap-0.5 text-[var(--fa-ink-2)]">
                    @foreach($w['korrigiert'] as $k)<span>{{ $k }}</span>@endforeach
                </dd>
            @endif
        </dl>
    </div>
</div>
