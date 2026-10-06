{{-- Spec 29: Menü-Vorschau (Kundensicht, nur lesen) — gespeicherter Stand der letzten Foodbook-Speicherung.
     Quelle = Snapshot aus FoodbookService::vorschauSnapshotAktualisieren; Druck/Präsentation bleiben live.
     Herausgelöst aus dem alten `vorschau`-Tab, damit die Listen-Seite das fertige Ergebnis dauerhaft
     zeigt (Ansehen ≠ Bearbeiten). fa-pass: Tokens und x-fa-Bausteine; Kapitel als klar getrennte
     Abschnitte, fehlende Gast-Texte sichtbar markiert.
     Erwartet im Scope: $fb, $menue, $menueSnapshotAt, $feedbackAgg. --}}
@php
    $einzug = fn ($stufe) => 'margin-left: ' . (12 + ((int) $stufe) * 12) . 'px';
    $gericht = 'text-[length:var(--fa-text-md)] leading-snug text-[var(--fa-ink-2)]';
    $ohneWording = 'italic text-[var(--fa-warn)]';
    $zwischen = 'text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-2)] mt-1.5';
@endphp
<section class="fa-surface p-5 sm:p-6 flex flex-col gap-5 min-w-0" data-fb-menue-vorschau>
    <header class="flex flex-wrap items-start justify-between gap-3 pb-4 border-b border-[var(--fa-line)]">
        <div class="min-w-0">
            <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]">So sieht der Kunde das Foodbook</p>
            <h2 class="mt-0.5 text-[length:var(--fa-text-2xl)] font-semibold tracking-tight text-[var(--fa-ink)] break-words">{{ $fb->label }}</h2>
            @if($menue['customer'] ?? null)
                <p class="mt-0.5 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">{{ $menue['customer'] }}@if(($menue['kontakt'] ?? null) && $menue['kontakt'] !== $menue['customer']) · {{ $menue['kontakt'] }}@endif</p>
            @endif
        </div>
        {{-- #8: ein Foodbook hat KEINEN eigenen Preis (kein sinnvoller Gesamt-€/P über einen Katalog) —
             Preise leben ausschließlich auf Concept-/Paket-Ebene (die Blöcke unten). --}}
        @if($menueSnapshotAt)
            <x-fa::badge icon="heroicon-m-clock">Stand {{ $menueSnapshotAt->format('d.m.Y, H:i') }} Uhr</x-fa::badge>
        @endif
    </header>

    @if($menue === null)
        <x-fa::empty icon="heroicon-o-book-open" title="Noch keine gespeicherte Vorschau">
            Foodbook bearbeiten und einmal speichern. Danach steht hier, was der Kunde sieht.
        </x-fa::empty>
    @else
        <div class="flex flex-col gap-6">
            @forelse($menue['kapitel'] ?? [] as $k)
                <section class="min-w-0" style="margin-left: {{ $k['depth'] * 16 }}px">
                    {{-- #8: ein Kapitel hat KEINEN eigenen Preis — nur die Concepts/Pakete darin (Block-Ebene). --}}
                    <h3 class="pb-1.5 mb-2 border-b border-[var(--fa-line)] text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)] break-words">{{ $k['title'] }}</h3>
                    @if(! empty($k['text']))<p class="mb-2 text-[length:var(--fa-text-md)] leading-relaxed text-[var(--fa-ink-2)] whitespace-pre-line">{{ $k['text'] }}</p>@endif

                    @if($k['ist_format'] ?? false)
                        {{-- B: LEBENDES Format-Kapitel — Editionen (Concepte) + Struktur live aus dem Format. --}}
                        @if($k['claim'] ?? null)<p class="mb-2 text-[length:var(--fa-text-md)] italic text-[var(--fa-ink-2)]">{{ $k['claim'] }}</p>@endif
                        @forelse($k['editionen'] ?? [] as $ed)
                            @if(($ed['typ'] ?? '') === 'header')
                                <p class="mt-3 text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">{{ $ed['name'] }}</p>
                            @elseif(($ed['typ'] ?? '') === 'text')
                                <p class="mt-1 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] whitespace-pre-line">{{ $ed['text'] }}</p>
                            @elseif(($ed['typ'] ?? '') === 'spacer')
                                <div class="h-2"></div>
                            @else
                                <div class="mt-2 flex flex-col gap-0.5">
                                    <p class="flex flex-wrap items-baseline gap-x-2 text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">
                                        <span class="break-words">{{ $ed['name'] }}</span>
                                        @if($ed['preis_pp'] ?? null)<span class="text-[length:var(--fa-text-md)] font-normal text-[var(--fa-ink-2)] tabular-nums">{{ number_format((float) $ed['preis_pp'], 2, ',', '.') }} € pro Person</span>@endif
                                    </p>
                                    @if($ed['claim'] ?? null)<p class="text-[length:var(--fa-text-sm)] italic text-[var(--fa-ink-3)]">{{ $ed['claim'] }}</p>@endif
                                    @foreach($ed['gerichte'] ?? [] as $g)
                                        @if(($g['type'] ?? '') === 'paket' || ($g['type'] ?? '') === 'header')
                                            <p class="{{ $zwischen }} ml-3">{{ $g['text'] }}</p>
                                        @else
                                            <p class="{{ $gericht }} {{ ($g['source'] ?? '') === 'name' ? $ohneWording : '' }}" style="{{ $einzug($g['einrueckung'] ?? 0) }}">{{ $g['text'] }}@if(($g['source'] ?? '') === 'name')<span class="ml-1.5 not-italic text-[length:var(--fa-text-sm)]">Gästetext fehlt</span>@endif</p>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        @empty
                            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-3)]">Das Format hat noch keine Editionen.</p>
                        @endforelse
                    @else
                        @forelse($k['bloecke'] as $b)
                            @php $istKonzept = in_array($b['type'], ['concept_ref', 'recipe_ref'], true); @endphp
                            <div class="flex flex-col gap-0.5 {{ ($b['ist_header'] || $istKonzept) ? 'mt-2.5 first:mt-0' : '' }}">
                                <p class="break-words {{ $b['ist_header'] ? 'text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink-2)]' : ($istKonzept ? 'text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]' : 'text-[length:var(--fa-text-md)] text-[var(--fa-ink)]') }}">{{ $b['label'] }}</p>
                                @if($b['untertitel'] ?? null)<p class="text-[length:var(--fa-text-sm)] italic text-[var(--fa-ink-3)]">{{ $b['untertitel'] }}</p>@endif
                                @foreach($b['gerichte'] ?? [] as $g)
                                    @if($g['type'] === 'paket' || $g['type'] === 'header')
                                        <p class="{{ $zwischen }} ml-3">{{ $g['text'] }}</p>
                                    @else
                                        @php $gfb = ($g['recipe_id'] ?? null) ? ($feedbackAgg[$g['recipe_id']] ?? null) : null; @endphp
                                        <p class="{{ $gericht }} {{ $g['source'] === 'name' ? $ohneWording : '' }}" style="{{ $einzug($g['einrueckung']) }}">
                                            {{ $g['text'] }}@if($g['source'] === 'name')<span class="ml-1.5 not-italic text-[length:var(--fa-text-sm)]">Gästetext fehlt</span>@endif
                                            @if($gfb && $gfb['count'] > 0)
                                                <span class="ml-1.5 inline-flex items-center gap-0.5 not-italic align-middle text-[length:var(--fa-text-sm)] tabular-nums {{ ($gfb['avg'] ?? 0) >= 4 ? 'text-[var(--fa-ok)]' : (($gfb['avg'] ?? 0) >= 3 ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-crit)]') }}"
                                                      title="{{ $gfb['count'] }} Bewertungen aus dem Gäste-Feedback">@svg('heroicon-m-star', 'w-3.5 h-3.5'){{ $gfb['avg'] !== null ? number_format((float) $gfb['avg'], 1, ',', '.') : '–' }}</span>
                                            @endif
                                        </p>
                                    @endif
                                @endforeach
                            </div>
                        @empty
                            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-3)]">In diesem Kapitel steht noch nichts.</p>
                        @endforelse
                    @endif
                </section>
            @empty
                <x-fa::empty icon="heroicon-o-queue-list" title="Noch keine Kapitel" compact>
                    Im Editor Kapitel anlegen und Concepts einfügen.
                </x-fa::empty>
            @endforelse
        </div>
    @endif

    <p class="pt-3 border-t border-[var(--fa-line)] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
        Die Gerichtnamen kommen aus dem Gästetext: zuerst der Text im Foodbook, dann der des Konzepts, dann der Standard im Verkauf, zuletzt der interne Name.
        <span class="italic text-[var(--fa-warn)]">Kursiv markiert</span> heißt: kein Gästetext gepflegt.
    </p>
</section>
