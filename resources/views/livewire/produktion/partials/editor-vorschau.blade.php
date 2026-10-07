    <div x-show="tab === 'vorschau'" x-cloak class="pt-4 flex flex-col gap-4">
    {{-- Reiter VORSCHAU: was die Ziele ergeben, bevor gespeichert wird. Reihenfolge wie bisher:
         erst, was die ganze Produktion betrifft (Diät, Behälter), dann die Ansätze je Rezept. --}}
        {{-- Küchen-Manager: Diät-/Allergen-Übersicht über die ganze Produktion (Rollup der Rezepte) --}}
        @if($allergenRollup)
            @php
                $diaeten = ['is_vegan' => 'vegan', 'is_vegetarian' => 'vegetarisch', 'is_halal' => 'halal', 'is_gluten_free' => 'glutenfrei', 'is_lactose_free' => 'laktosefrei'];
            @endphp
            <x-fa::section title="Diät und Allergene" icon="heroicon-o-shield-check"
                           :meta="'aus ' . $allergenRollup['n_gerichte'] . ' Rezepten'"
                           description="Vegan, vegetarisch, halal, gluten- und laktosefrei gilt nur, wenn alle Rezepte es erfüllen. „Enthält“ heißt: mindestens ein Rezept.">
                <div class="flex flex-wrap items-center gap-1.5" data-produktion-allergene>
                    @foreach($diaeten as $feld => $text)
                        @if($allergenRollup[$feld])
                            <x-fa::badge tone="ok" icon="heroicon-m-check">{{ $text }}</x-fa::badge>
                        @else
                            <x-fa::badge>nicht {{ $text }}</x-fa::badge>
                        @endif
                    @endforeach
                    @if($allergenRollup['contains_pork'])<x-fa::badge tone="warn">enthält Schwein</x-fa::badge>@endif
                    @if($allergenRollup['contains_beef'])<x-fa::badge tone="warn">enthält Rind</x-fa::badge>@endif
                </div>
                <p class="{{ $leise }}">Sicherheit der Angaben: {{ \Platform\FoodAlchemist\Support\Labels::konfidenz($allergenRollup['confidence'] ?? null) }}. Die schwächste Angabe eines Rezepts zählt.</p>
            </x-fa::section>
        @endif

        {{-- Spec 51: was insgesamt gepackt werden muss. Die Kueche packt nicht je Rezept, sie packt
             den Wagen — nur die Basis-Variante zaehlt, Alternativen sind ein Angebot, keine zweite Wahrheit. --}}
        @if($behaelterRollup)
            <x-fa::section title="Behälter" icon="heroicon-o-archive-box" description="Was für die ganze Produktion gepackt werden muss.">
                <div class="flex flex-wrap items-center gap-1.5" data-produktion-behaelter>
                    @forelse($behaelterRollup['summe'] as $name => $anzahl)
                        <x-fa::badge>{{ $anzahl }} × {{ $name }}</x-fa::badge>
                    @empty
                        <span class="{{ $leise }}">Kein Behälter-Bedarf gerechnet.</span>
                    @endforelse
                </div>
                @if($behaelterRollup['ohne'] > 0)
                    <x-fa::signal tone="warn">{{ $behaelterRollup['ohne'] }} {{ $behaelterRollup['ohne'] === 1 ? 'Rezept' : 'Rezepte' }} nicht bemessbar: Referenzmenge oder Ausbeute fehlt</x-fa::signal>
                @endif
            </x-fa::section>
        @endif
    <x-fa::section title="Ansätze je Rezept" icon="heroicon-o-calculator">
        @if($vorschau === null)
            <x-fa::empty compact icon="heroicon-o-calculator" title="Noch keine Vorschau">Im Reiter Ziele mindestens ein Ziel einfügen, dann stehen hier Ansätze, Mengen und Arbeitszeit.</x-fa::empty>
        @else
            <div class="overflow-x-auto -mx-4 px-4">
                <table class="fa-table">
                    <thead><tr>
                        <th class="w-full min-w-[12rem]">Rezept</th>
                        <th class="num">Ansätze</th>
                        <th class="num">Menge</th>
                        <th>Behälter</th>
                        <th class="num">Arbeitszeit</th>
                    </tr></thead>
                    <tbody>
                        @foreach($vorschau['rezepte'] as $r)
                            @php
                                $behaelter = \Platform\FoodAlchemist\Services\BehaelterBedarfService::kurz($r['behaelter'] ?? null);
                            @endphp
                            <tr>
                                <td>
                                    <span class="text-[var(--fa-ink)]">{{ $r['name'] }}</span>
                                    @if($r['ist_basisrezept'])<x-fa::badge class="ml-1">Basisrezept</x-fa::badge>@endif
                                </td>
                                <td class="num font-medium">{{ $menge($r['ansaetze']) }}</td>
                                <td class="num">
                                    @if($r['portionen'] !== null)
                                        <x-fa::menge :value="$r['portionen']" unit="Portionen" :decimals="0" />
                                    @elseif($r['produzierte_menge_kg'] !== null)
                                        <x-fa::menge :value="$r['produzierte_menge_kg']" unit="kg" />
                                    @else
                                        <span class="text-[var(--fa-ink-3)]">–</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" data-vorschau-behaelter>{{ $behaelter ?? '–' }}</td>
                                <td class="num">
                                    @if($r['arbeitszeit_min'] !== null)
                                        <x-fa::menge :value="$r['arbeitszeit_min']" unit="min" :decimals="0" />
                                    @else
                                        <x-fa::signal tone="warn" title="Am Rezept ist keine Arbeitszeit hinterlegt">fehlt</x-fa::signal>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @foreach($vorschau['warnungen'] as $w)
                <x-fa::notice tone="warn">{{ $w }}</x-fa::notice>
            @endforeach
        @endif
    </x-fa::section>
    </div>{{-- /Vorschau-Panel --}}
