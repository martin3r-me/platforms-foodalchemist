{{-- Spec 33 P4 — Mehrbetriebs-Sicht: eine Matrix, zwei Brillen, ein Stichtag-Regler.
     Beobachten hier, schalten an der Ausgabe (Zeilen springen in den jeweiligen Editor).

     fa-pass 2026-10-05: Tokens + Bausteine. Brille als Umschalter in Chip-Optik (wire:click bleibt,
     kein wire:model: die Methode validiert), Tabellen als fa-table, Zustände als Badge in
     Zustandsfarben, Leerzustand mit nächstem Schritt. Reihenfolge der Blöcke unverändert. --}}
@php
    $zustandTon = [
        'laeuft' => 'ok', 'geplant' => 'info',
        'abgelaufen' => 'warn', 'inaktiv' => 'warn',
        'entwurf' => 'neutral', 'archiviert' => 'neutral',
    ];
    $datum = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d.m.y') : null;
    $zeitraum = fn ($von, $bis) => ($von ? $datum($von) : 'unbefristet') . ($bis ? ' bis ' . $datum($bis) : '');
@endphp

<div class="flex flex-col gap-4" data-ctrl-portfolio>
    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[75ch]">
        Welche Speisekarten, Speisepläne und Angebote an einem Tag laufen, je Betrieb oder je Kunde.
        Ein Klick auf eine Ausgabe öffnet sie zum Bearbeiten.
    </p>

    {{-- Brille + Stichtag --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <p class="mb-1.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Sicht</p>
            <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Sicht" data-ctrl-brille>
                @foreach(['betrieb' => 'Je Betrieb', 'kunde' => 'Je Kunde'] as $key => $lbl)
                    <button type="button" wire:click="brilleSetzen('{{ $key }}')"
                            role="radio" aria-checked="{{ $brille === $key ? 'true' : 'false' }}"
                            data-ctrl-brille-btn="{{ $key }}"
                            class="inline-flex items-center h-8 px-3 rounded-full border text-[length:var(--fa-text-md)] transition-colors duration-150 {{ $brille === $key ? 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)] font-medium' : 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}">
                        {{ $lbl }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="flex items-end gap-2">
            <x-fa::field label="Stichtag" for="ctrl-pf-stichtag">
                <x-fa::input type="date" id="ctrl-pf-stichtag" wire:model.live="stichtag" class="w-40" data-ctrl-stichtag />
            </x-fa::field>
            <x-fa::button variant="ghost" wire:click="heute">Heute</x-fa::button>
        </div>
    </div>

    @if($leer)
        <x-fa::empty compact icon="heroicon-o-user-group" title="Kein Team zugeordnet">
            Ohne Team gibt es keine Ausgaben. Wähle oben ein Team aus.
        </x-fa::empty>
    @else
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
            Stand {{ $stichtagAnzeige }}. Eine Zelle zeigt, was an diesem Tag <strong class="font-semibold text-[var(--fa-ink-2)]">läuft</strong>:
            aktiv und im Gültigkeitszeitraum. Ein Strich heißt, hier läuft nichts.
        </p>

        {{-- ── Matrix ─────────────────────────────────────────────────────── --}}
        @if($matrix === [])
            <div class="rounded-[var(--fa-radius-surface)] border border-dashed border-[var(--fa-line-strong)]">
                <x-fa::empty :icon="$brille === 'betrieb' ? 'heroicon-o-building-storefront' : 'heroicon-o-user'"
                             :title="$brille === 'betrieb' ? 'Noch kein Betrieb angelegt' : 'Noch keiner Ausgabe ein Kunde zugeordnet'">
                    @if($brille === 'betrieb')
                        Die Sicht je Betrieb braucht gepflegte Standorte.
                    @else
                        Kunden werden an der Ausgabe selbst eingetragen, im jeweiligen Editor.
                    @endif
                    @if($brille === 'betrieb')
                        <x-slot:action>
                            <x-fa::button size="sm" variant="secondary" icon="heroicon-o-plus" :href="route('foodalchemist.einstellungen', ['sektion' => 'betriebe'])" wire:navigate>
                                Betriebe in den Einstellungen anlegen
                            </x-fa::button>
                        </x-slot:action>
                    @endif
                </x-fa::empty>
            </div>
        @else
            <div class="overflow-x-auto rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)]">
                <table class="fa-table" data-ctrl-portfolio-matrix>
                    <thead>
                        <tr>
                            <th>{{ $brille === 'betrieb' ? 'Betrieb' : 'Kunde' }}</th>
                            @foreach($arten as $art => $meta)
                                <th>{{ $meta['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($matrix as $key => $zeile)
                            <tr wire:key="pf-{{ $brille }}-{{ $key }}">
                                <td class="font-medium align-top">{{ $zeile['label'] }}</td>
                                @foreach($arten as $art => $meta)
                                    @php $zellen = $zeile['zellen'][$art] ?? []; @endphp
                                    <td class="align-top min-w-[12rem]" data-ctrl-zelle="{{ $art }}">
                                        @if($zellen === [])
                                            {{-- Leere Zelle = Lücke. Das ist die Aussage, nicht ein fehlender Wert. --}}
                                            <span class="text-[var(--fa-ink-3)]" title="Läuft hier gerade nicht">–</span>
                                        @else
                                            <div class="flex flex-col gap-1.5">
                                                @foreach($zellen as $z)
                                                    <div class="min-w-0">
                                                        <a href="{{ $z['route'] }}" wire:navigate
                                                           class="text-[var(--fa-accent)] hover:underline">{{ $z['name'] }}</a>
                                                        <div class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ $zeitraum($z['von'], $z['bis']) }}</div>
                                                    </div>
                                                @endforeach
                                                @if(count($zellen) > 1)
                                                    <span><x-fa::badge tone="warn" icon="heroicon-m-exclamation-triangle">{{ count($zellen) }} parallel</x-fa::badge></span>
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
            <x-fa::section variant="plain" title="Parallel laufend" icon="heroicon-o-exclamation-triangle" :meta="count($konflikte) . ' ' . (count($konflikte) === 1 ? 'Fall' : 'Fälle')"
                           description="Zwei Ausgaben derselben Art für denselben Betrieb oder Kunden. Kann gewollt sein, etwa beim Übergang oder für eine Sonderkarte."
                           data-ctrl-portfolio-konflikte>
                <ul class="flex flex-col gap-1">
                    @foreach($konflikte as $k)
                        <li class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">
                            <strong class="font-semibold text-[var(--fa-ink)]">{{ $k['zuordnung'] }}</strong> · {{ $arten[$k['art']]['label'] }}:
                            {{ collect($k['ausgaben'])->pluck('name')->implode(' · ') }}
                        </li>
                    @endforeach
                </ul>
            </x-fa::section>
        @endif

        {{-- ── Ohne Zuordnung ─────────────────────────────────────────────── --}}
        @if(count($ohneZuordnung))
            <x-fa::section variant="plain" title="Ohne Zuordnung" icon="heroicon-o-question-mark-circle" :meta="count($ohneZuordnung) . ' ' . (count($ohneZuordnung) === 1 ? 'Ausgabe' : 'Ausgaben')"
                           description="Weder Betrieb noch Kunde eingetragen. Diese Ausgaben erscheinen in keiner Sicht. Nicht verloren, aber nicht steuerbar: im Editor einen Betrieb oder Kunden setzen."
                           data-ctrl-portfolio-ohne>
                <div class="overflow-x-auto">
                    <table class="fa-table">
                        <thead>
                            <tr>
                                <th>Ausgabe</th>
                                <th>Art</th>
                                <th>Zustand</th>
                                <th class="num">Positionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ohneZuordnung as $z)
                                <tr wire:key="pf-ohne-{{ $z['art'] }}-{{ $z['id'] }}">
                                    <td><a href="{{ $z['route'] }}" wire:navigate class="text-[var(--fa-accent)] hover:underline">{{ $z['name'] }}</a></td>
                                    <td class="text-[var(--fa-ink-2)]">{{ $z['art_label'] }}</td>
                                    <td>
                                        <x-fa::badge :tone="$zustandTon[$z['zustand']] ?? 'neutral'">{{ $z['status_label'] }}</x-fa::badge>
                                        @if($z['grund'])<span class="ml-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $z['grund'] }}</span>@endif
                                    </td>
                                    <td class="num text-[var(--fa-ink-2)]">{{ $z['n_positionen'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-fa::section>
        @endif

        {{-- ── Alles, mit Grund ───────────────────────────────────────────── --}}
        <x-fa::section variant="plain" title="Alle Ausgaben" icon="heroicon-o-queue-list"
                       description="Auch was nicht läuft, mit dem Grund dazu.">
            <div class="overflow-x-auto" data-ctrl-portfolio-liste>
                <table class="fa-table">
                    <thead>
                        <tr>
                            <th>Ausgabe</th>
                            <th>Art</th>
                            <th>Zustand</th>
                            <th>Betrieb</th>
                            <th>Kunde</th>
                            <th>Zeitraum</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($zeilen as $z)
                            <tr wire:key="pf-all-{{ $z['art'] }}-{{ $z['id'] }}">
                                <td><a href="{{ $z['route'] }}" wire:navigate class="{{ $z['laeuft'] ? 'text-[var(--fa-accent)]' : 'text-[var(--fa-ink-2)]' }} hover:underline">{{ $z['name'] }}</a></td>
                                <td class="text-[var(--fa-ink-2)]">{{ $z['art_label'] }}</td>
                                {{-- Warum etwas nicht läuft, steht dabei: drei unterscheidbare Gründe
                                     statt eines grauen Punkts. --}}
                                <td>
                                    <x-fa::badge :tone="$zustandTon[$z['zustand']] ?? 'neutral'">{{ $z['status_label'] }}</x-fa::badge>
                                    @if($z['grund'])<div class="mt-0.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $z['grund'] }}</div>@endif
                                </td>
                                <td class="text-[var(--fa-ink-2)]">{{ $z['outlet_name'] ?? '–' }}</td>
                                <td class="text-[var(--fa-ink-2)]">{{ $z['kunde'] ?? '–' }}</td>
                                <td class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums whitespace-nowrap">{{ $zeitraum($z['von'], $z['bis']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-fa::section>
    @endif
</div>
