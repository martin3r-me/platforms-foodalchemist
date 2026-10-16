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
        @if($fehler)<x-fa::notice tone="crit" class="mb-1" data-fruehere-fehler>{{ $fehler }}</x-fa::notice>@endif
        <div x-show="offen" x-cloak class="max-h-72 overflow-auto rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" data-fruehere-liste>
            <table class="fa-table">
                <thead>
                    <tr>
                        <th>Gültig ab</th><th>Gültig bis</th><th>Art</th><th class="num">Preis</th><th class="num">Je Basiseinheit</th><th>Quelle</th>@if($darfEdit)<th></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($daten['zeilen'] as $i => $z)
                        <tr wire:key="fruehere-{{ $z['id'] ?? $i }}" data-fruehere-zeile data-aktion="{{ $z['aktion'] ? '1' : '0' }}" data-ausgeblendet="{{ $z['ausgeblendet'] ? '1' : '0' }}" data-verdacht="{{ $z['verdacht'] ? '1' : '0' }}">
                            <td class="whitespace-nowrap text-[var(--fa-ink-2)] {{ $z['ausgeblendet'] ? 'line-through' : '' }}">{{ $datum($z['von']) }}</td>
                            <td class="whitespace-nowrap text-[var(--fa-ink-2)] {{ $z['ausgeblendet'] ? 'line-through' : '' }}">{{ $datum($z['bis']) ?? '–' }}</td>
                            <td>
                                <x-fa::badge :tone="$z['aktion'] ? 'warn' : 'neutral'">{{ $z['aktion'] ? 'Aktion' : 'Standard-EK' }}</x-fa::badge>
                                @if($z['verdacht'])
                                    <x-fa::badge tone="warn" title="Weicht um mehr als das {{ config('foodalchemist.preise.ausreisser.faktor') }}-Fache vom Vorgänger und vom Nachfolger ab und galt nur kurz. Wird nicht automatisch ausgeblendet." data-verdacht-badge>möglicher Erfassungsfehler</x-fa::badge>
                                @endif
                                @if($z['ausgeblendet'])
                                    <x-fa::badge tone="neutral" data-ausgeblendet-badge>ausgeblendet</x-fa::badge>
                                @endif
                            </td>
                            <td class="num font-medium {{ $z['ausgeblendet'] ? 'line-through text-[var(--fa-ink-3)]' : '' }}">{{ $zahl($z['preis']) }} €</td>
                            <td class="num text-[var(--fa-ink-2)] {{ $z['ausgeblendet'] ? 'line-through' : '' }}">
                                @if($z['je_basis'] !== null){{ $zahl($z['je_basis']) }} {{ $z['einheit'] }}@else<span class="text-[var(--fa-ink-3)]">nicht berechenbar</span>@endif
                            </td>
                            <td class="text-[var(--fa-ink-3)]">
                                {{ $z['quelle'] }}
                                @if($z['ausgeblendet'] && $z['grund'])<span class="block text-[length:var(--fa-text-sm)]" data-ausgeblendet-grund>Grund: {{ $z['grund'] }}</span>@endif
                            </td>
                            @if($darfEdit)
                                <td class="whitespace-nowrap" x-data="{ frage: false }">
                                    @if($z['ausgeblendet'])
                                        <x-fa::button size="sm" variant="ghost" wire:click="einblenden({{ $z['id'] }})" data-fruehere-einblenden>Einblenden</x-fa::button>
                                    @else
                                        <x-fa::button size="sm" variant="ghost" x-show="! frage" x-on:click="frage = true" data-fruehere-ausblenden-oeffnen>als Fehler ausblenden</x-fa::button>
                                        <span x-show="frage" x-cloak class="inline-flex items-center gap-1.5">
                                            <x-fa::input size="sm" wire:model="gruende.{{ $z['id'] }}" placeholder="Grund (Pflicht)" aria-label="Grund zum Ausblenden" class="w-44" data-fruehere-grund />
                                            <x-fa::button size="sm" variant="danger" wire:click="ausblenden({{ $z['id'] }})" data-fruehere-ausblenden>Ausblenden</x-fa::button>
                                            <x-fa::button size="sm" variant="ghost" x-on:click="frage = false">Abbrechen</x-fa::button>
                                        </span>
                                    @endif
                                </td>
                            @endif
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
