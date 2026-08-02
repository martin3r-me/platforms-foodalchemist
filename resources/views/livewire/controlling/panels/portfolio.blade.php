{{-- Spec 33 P4 — Mehrbetriebs-Sicht: eine Matrix, zwei Brillen, ein Stichtag-Regler.
     Beobachten hier, schalten an der Ausgabe (Zeilen springen in den jeweiligen Editor). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($zustandPill = [
    'laeuft' => $variantPill['success'], 'geplant' => $variantPill['info'],
    'abgelaufen' => $variantPill['warning'], 'inaktiv' => $variantPill['warning'],
    'entwurf' => $variantPill['secondary'], 'archiviert' => $variantPill['secondary'],
])

<div class="space-y-4" data-ctrl-portfolio>

    {{-- Brille + Stichtag --}}
    <div class="flex items-end justify-between gap-3 flex-wrap">
        <div>
            <div class="{{ $label }} mb-1">Brille</div>
            <div class="inline-flex rounded-lg overflow-hidden border border-black/10" data-ctrl-brille>
                @foreach(['betrieb' => 'Betrieb', 'kunde' => 'Kunde'] as $key => $lbl)
                    <button type="button" wire:click="brilleSetzen('{{ $key }}')"
                            data-ctrl-brille-btn="{{ $key }}"
                            class="px-3 py-1.5 text-xs font-medium transition-colors duration-150 {{ $brille === $key ? 'bg-violet-500/15 text-violet-700' : 'text-gray-600 hover:bg-black/[0.04]' }}">
                        {{ $lbl }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="flex items-end gap-2">
            <div>
                <label class="{{ $label }} block mb-1">Stichtag</label>
                <input type="date" wire:model.live="stichtag" class="{{ $input }} !w-40" data-ctrl-stichtag />
            </div>
            <button type="button" wire:click="heute" class="{{ $btnGhostXs }}">Heute</button>
        </div>
    </div>

    @if($leer)
        <p class="text-xs text-gray-500">Kein Team zugeordnet.</p>
    @else
        <p class="text-[11px] text-gray-500">
            Stand {{ $stichtagAnzeige }}. Eine Zelle zeigt, was an diesem Tag
            <strong>läuft</strong> — Status aktiv und Datum im Gültigkeitsfenster.
        </p>

        {{-- ── Matrix ─────────────────────────────────────────────────────── --}}
        @if($matrix === [])
            <div class="{{ $sectionCard }} text-center py-8">
                <p class="text-sm text-gray-900 font-medium">
                    {{ $brille === 'betrieb' ? 'Noch kein Betrieb angelegt' : 'Noch keiner Ausgabe ein Kunde zugeordnet' }}
                </p>
                <p class="text-xs text-gray-500 mt-1">
                    @if($brille === 'betrieb')
                        Die Betriebsbrille braucht gepflegte Standorte —
                        <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'betriebe']) }}"
                           class="text-violet-600 hover:underline" wire:navigate>in den Einstellungen anlegen</a>.
                    @else
                        Kunden entstehen an der Ausgabe selbst — trag sie im jeweiligen Editor ein.
                    @endif
                </p>
            </div>
        @else
            <div class="{{ $sectionCard }} !p-0 overflow-x-auto">
                <table class="w-full text-xs" data-ctrl-portfolio-matrix>
                    <thead>
                        <tr class="text-left text-gray-500 border-b border-black/10">
                            <th class="px-3 py-2 font-medium">{{ $brille === 'betrieb' ? 'Betrieb' : 'Kunde' }}</th>
                            @foreach($arten as $art => $meta)
                                <th class="px-3 py-2 font-medium">{{ $meta['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($matrix as $key => $zeile)
                            <tr class="border-b border-black/5" wire:key="pf-{{ $brille }}-{{ $key }}">
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $zeile['label'] }}</td>
                                @foreach($arten as $art => $meta)
                                    @php($zellen = $zeile['zellen'][$art] ?? [])
                                    <td class="px-3 py-2 align-top" data-ctrl-zelle="{{ $art }}">
                                        @if($zellen === [])
                                            {{-- Leere Zelle = Lücke. Das ist die Aussage, nicht ein fehlender Wert. --}}
                                            <span class="text-gray-300" title="Läuft hier gerade nicht">—</span>
                                        @else
                                            <div class="space-y-1">
                                                @foreach($zellen as $z)
                                                    <a href="{{ $z['route'] }}" wire:navigate
                                                       class="block text-violet-700 hover:underline">{{ $z['name'] }}</a>
                                                    <div class="text-[10px] text-gray-500">
                                                        {{ $z['von'] ? \Illuminate\Support\Carbon::parse($z['von'])->format('d.m.y') : 'unbefristet' }}
                                                        @if($z['bis']) – {{ \Illuminate\Support\Carbon::parse($z['bis'])->format('d.m.y') }}@endif
                                                    </div>
                                                @endforeach
                                                @if(count($zellen) > 1)
                                                    <span class="{{ $pill }} {{ $variantPill['warning'] }}">{{ count($zellen) }} parallel</span>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- ── Konflikte ──────────────────────────────────────────────────── --}}
        @if(count($konflikte))
            <div class="{{ $sectionCard }}" data-ctrl-portfolio-konflikte>
                <h4 class="text-xs font-medium text-gray-900">Parallel laufend ({{ count($konflikte) }})</h4>
                <p class="text-[11px] text-gray-500 mt-0.5">
                    Zwei Ausgaben derselben Art in derselben Zuordnung. Kann gewollt sein
                    (Übergang, Sonderkarte) — hier steht nur, dass es so ist.
                </p>
                <ul class="mt-2 space-y-1">
                    @foreach($konflikte as $k)
                        <li class="text-[11px] text-gray-700">
                            <strong>{{ $k['zuordnung'] }}</strong> · {{ $arten[$k['art']]['label'] }}:
                            {{ collect($k['ausgaben'])->pluck('name')->implode(' · ') }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ── Ohne Zuordnung ─────────────────────────────────────────────── --}}
        @if(count($ohneZuordnung))
            <div class="{{ $sectionCard }}" data-ctrl-portfolio-ohne>
                <h4 class="text-xs font-medium text-gray-900">Ohne Zuordnung ({{ count($ohneZuordnung) }})</h4>
                <p class="text-[11px] text-gray-500 mt-0.5">
                    Weder Betrieb noch Kunde — diese Ausgaben erscheinen in keiner der beiden
                    Brillen. Nicht verloren, aber nicht steuerbar.
                </p>
                <div class="overflow-x-auto mt-2">
                    <table class="{{ $table }}">
                        <thead>
                            <tr>
                                <th class="{{ $th }} text-left">Ausgabe</th>
                                <th class="{{ $th }} text-left">Art</th>
                                <th class="{{ $th }} text-left">Zustand</th>
                                <th class="{{ $th }} text-right">Positionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ohneZuordnung as $z)
                                <tr class="{{ $tr }}" wire:key="pf-ohne-{{ $z['art'] }}-{{ $z['id'] }}">
                                    <td class="{{ $td }}"><a href="{{ $z['route'] }}" wire:navigate class="text-violet-700 hover:underline">{{ $z['name'] }}</a></td>
                                    <td class="{{ $td }} text-gray-600">{{ $z['art_label'] }}</td>
                                    <td class="{{ $td }}">
                                        <span class="{{ $pill }} {{ $zustandPill[$z['zustand']] ?? $variantPill['secondary'] }}">{{ $z['status_label'] }}</span>
                                        @if($z['grund'])<span class="text-[10px] text-gray-500 ml-1">{{ $z['grund'] }}</span>@endif
                                    </td>
                                    <td class="{{ $td }} text-right tabular-nums text-gray-500">{{ $z['n_positionen'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- ── Alles, mit Grund ───────────────────────────────────────────── --}}
        <div class="{{ $sectionCard }} !p-0 overflow-x-auto" data-ctrl-portfolio-liste>
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-left text-gray-500 border-b border-black/10">
                        @foreach(['Ausgabe', 'Art', 'Zustand', 'Betrieb', 'Kunde', 'Zeitraum'] as $h)
                            <th class="px-3 py-2 font-medium">{{ $h }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($zeilen as $z)
                        <tr class="border-b border-black/5 {{ $z['laeuft'] ? '' : 'opacity-70' }}" wire:key="pf-all-{{ $z['art'] }}-{{ $z['id'] }}">
                            <td class="px-3 py-2"><a href="{{ $z['route'] }}" wire:navigate class="text-violet-700 hover:underline">{{ $z['name'] }}</a></td>
                            <td class="px-3 py-2 text-gray-600">{{ $z['art_label'] }}</td>
                            {{-- Warum etwas nicht läuft, steht dabei — drei unterscheidbare Gründe
                                 statt eines grauen Punkts. --}}
                            <td class="px-3 py-2">
                                <span class="{{ $pill }} {{ $zustandPill[$z['zustand']] ?? $variantPill['secondary'] }}">{{ $z['status_label'] }}</span>
                                @if($z['grund'])<div class="text-[10px] text-gray-500 mt-0.5">{{ $z['grund'] }}</div>@endif
                            </td>
                            <td class="px-3 py-2 text-gray-600">{{ $z['outlet_name'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $z['kunde'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-[11px] text-gray-500">
                                {{ $z['von'] ? \Illuminate\Support\Carbon::parse($z['von'])->format('d.m.y') : 'unbefristet' }}
                                @if($z['bis']) – {{ \Illuminate\Support\Carbon::parse($z['bis'])->format('d.m.y') }}@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
