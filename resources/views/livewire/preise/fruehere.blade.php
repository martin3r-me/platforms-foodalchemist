{{-- Frühere Preise (Necta-Historie), eingeklappt unter den aktuellen Preisen. Aktion ist markiert, in der Linie fehlt sie. Livewire braucht immer ein Wurzel-Element, auch ohne Historie. --}}
@php
    $datum = fn (?string $d) => $d !== null ? \Carbon\Carbon::parse($d)->format('d.m.Y') : null;
    $zahl = fn (float $v) => number_format($v, 2, ',', '.');
@endphp
<div data-fruehere-wurzel>
@if($daten['gesamt'] > 0)
    <div x-data="{ offen: false }" class="flex flex-col gap-2" data-fruehere-preise>
        <button type="button" x-on:click="offen = ! offen" x-bind:aria-expanded="offen"
                class="inline-flex items-center gap-1.5 self-start text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]" data-fruehere-toggle>
            @svg('heroicon-m-chevron-right', 'w-4 h-4 shrink-0 transition-transform')
            Frühere Preise ({{ $daten['gesamt'] }})
        </button>
        <div x-show="offen" x-cloak class="max-h-72 overflow-auto rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" data-fruehere-liste>
            <table class="fa-table">
                <thead>
                    <tr>
                        <th>Gültig ab</th><th>Gültig bis</th><th>Art</th><th class="num">Preis</th><th class="num">Je Basiseinheit</th><th>Quelle</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($daten['zeilen'] as $i => $z)
                        <tr wire:key="fruehere-{{ $i }}" data-fruehere-zeile data-aktion="{{ $z['aktion'] ? '1' : '0' }}">
                            <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $datum($z['von']) }}</td>
                            <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $datum($z['bis']) ?? '–' }}</td>
                            <td><x-fa::badge :tone="$z['aktion'] ? 'warn' : 'neutral'">{{ $z['aktion'] ? 'Aktion' : 'Standard-EK' }}</x-fa::badge></td>
                            <td class="num font-medium">{{ $zahl($z['preis']) }} €</td>
                            <td class="num text-[var(--fa-ink-2)]">
                                @if($z['je_basis'] !== null){{ $zahl($z['je_basis']) }} {{ $z['einheit'] }}@else<span class="text-[var(--fa-ink-3)]">nicht berechenbar</span>@endif
                            </td>
                            <td class="text-[var(--fa-ink-3)]">{{ $z['quelle'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($daten['gekappt'])
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" x-show="offen" x-cloak data-fruehere-gekappt>Die neuesten {{ count($daten['zeilen']) }} von {{ $daten['gesamt'] }} Ständen. Den ganzen Verlauf zeigt die Linie.</p>
        @endif
    </div>
@endif
</div>
