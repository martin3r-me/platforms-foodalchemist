{{-- Format-Modul (#3): Detail-Panel auf Concepter-Niveau — Cockpit (Preisspanne €/P + Ø +
     Editionen/Struktur-Zählung), Identität (Name/Consumer/Claim/Status/Herkunft + Dimensionen),
     Editionen (Concept-Slots → €/P, Paket-Badge, Direkt-Sprung in den Concepter), Struktur-Blöcke
     + Aktionen (Bearbeiten · Druck/Karte · Report · Löschen). Light styling wie das Concepter-Panel. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($statusLabel = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiv'])
@php($originLabel = ['eigen' => 'Eigen', 'gruppe' => 'Gruppe', 'kunde' => 'Kunde'])

<div class="p-4 space-y-4 min-h-full bg-gray-500/[0.04]" data-formate-panel>
    @if($format === null)
        <div class="py-16 text-center text-sm text-gray-500">
            <div class="text-2xl mb-2">@svg('heroicon-o-squares-2x2', 'w-6 h-6 inline-block')</div>
            Format auswählen.
        </div>
    @else
        {{-- Hero --}}
        @if($format->heroImage)
            <img src="{{ $format->heroImage->url() }}" alt="{{ $format->name }}"
                 class="w-full h-40 object-cover rounded-xl border border-black/5" />
        @endif

        {{-- Kopf (Identität) --}}
        <div>
            <div class="flex items-start justify-between gap-2">
                <h3 class="text-base font-semibold tracking-tight text-gray-900 leading-snug">{{ $format->name }}</h3>
                <span class="{{ $pill }} {{ ['draft' => $variantPill['secondary'], 'active' => $variantPill['success'], 'archiviert' => $variantPill['warning']][$format->status] ?? $variantPill['secondary'] }} shrink-0">
                    {{ $statusLabel[$format->status] ?? $format->status }}
                </span>
            </div>
            @if($format->consumer_name)<p class="text-xs italic text-gray-500 mt-0.5">„{{ $format->consumer_name }}"</p>@endif
            @if($format->claim)<p class="text-xs italic text-violet-600 mt-0.5">„{{ $format->claim }}"</p>@endif
            <div class="flex flex-wrap items-center gap-1.5 mt-2">
                @if($format->origin === 'kunde')
                    <span class="{{ $pill }} {{ $variantPill['warning'] }}" title="Kunden-IP — nicht für andere adaptieren">Kunde 🔒</span>
                @elseif($format->origin)
                    <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $originLabel[$format->origin] ?? $format->origin }}</span>
                @endif
                @if($format->customer)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $format->customer }}</span>@endif
            </div>
        </div>

        {{-- Cockpit (Format-Ökonomie): Preisspanne €/P + Ø + Editionen --}}
        <div class="relative overflow-hidden {{ $card }} px-3.5 py-2.5" data-formate-cockpit>
            <div class="{{ $cardAccent }}"></div>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <span class="text-[10px] font-medium uppercase tracking-wider text-violet-600">Preisspanne / Person</span>
                    <p class="text-2xl font-bold text-violet-700 leading-none mt-1 tabular-nums">
                        @if($range['min'] === null)
                            —
                        @elseif($range['min'] === $range['max'])
                            {{ number_format($range['min'], 2, ',', '.') }} €
                        @else
                            {{ number_format($range['min'], 2, ',', '.') }}–{{ number_format($range['max'], 2, ',', '.') }} €
                        @endif
                    </p>
                </div>
                <span class="{{ $pill }} {{ $variantPill['secondary'] }} shrink-0">{{ $cockpit['n_editionen'] }} {{ $cockpit['n_editionen'] === 1 ? 'Edition' : 'Editionen' }}</span>
            </div>
            <div class="flex flex-wrap gap-x-5 gap-y-1 mt-3 pt-2.5 border-t border-black/5 text-xs">
                <span class="text-gray-500">Ø €/Person <span class="text-gray-900 font-medium tabular-nums">{{ $cockpit['avg'] !== null ? number_format($cockpit['avg'], 2, ',', '.') . ' €' : '—' }}</span></span>
                <span class="text-gray-500">Struktur-Blöcke <span class="text-gray-900 font-medium tabular-nums">{{ $cockpit['n_struktur'] }}</span></span>
            </div>
        </div>

        {{-- Dimensionen (dieselben Concept-Facetten am Format) --}}
        @php($moments = $format->serviceMoments ?? collect())
        @php($seasons = $format->seasons ?? collect())
        @php($targets = $format->targetGroups ?? collect())
        @if($format->servingForm || $format->eventType || $moments->isNotEmpty() || $seasons->isNotEmpty() || $targets->isNotEmpty())
            <div class="flex flex-wrap gap-1" data-formate-dimensionen>
                @if($format->servingForm)<span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $format->servingForm->label }}</span>@endif
                @if($format->eventType)<span class="{{ $pill }} {{ $variantPill['info'] }}">{{ $format->eventType->name }}</span>@endif
                @foreach($moments as $m)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $m->name }}</span>@endforeach
                @foreach($seasons as $sa)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $sa->name }}</span>@endforeach
                @foreach($targets as $zg)<span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $zg->name }}</span>@endforeach
            </div>
        @endif

        @if($format->story)
            <x-foodalchemist::section title="Marken-Story" icon="heroicon-o-sparkles">
                <p class="text-[13px] text-gray-700 whitespace-pre-line">{{ \Illuminate\Support\Str::limit($format->story, 360) }}</p>
            </x-foodalchemist::section>
        @endif

        {{-- Editionen (F2: referenzierte Concepts der type=concept-Slots) --}}
        @php($conceptSlots = $format->slots->where('type', 'concept'))
        <x-foodalchemist::section title="Editionen" icon="heroicon-o-rectangle-stack" :meta="$conceptSlots->count()">
            @forelse($conceptSlots as $s)
                @php($e = $s->concept)
                @continue($e === null)
                <div wire:key="fed-{{ $s->id }}" class="flex items-center justify-between gap-2 text-[13px] py-1 border-b border-black/5 last:border-0">
                    <span class="min-w-0 flex items-center gap-1.5 truncate">
                        @if(($e->kind ?? null) === 'paket')<span class="{{ $pill }} {{ $variantPill['info'] }} shrink-0">Paket</span>@endif
                        <a href="{{ route('foodalchemist.concepter.index', ['edit' => $e->id]) }}" target="_blank"
                           class="truncate text-gray-800 hover:text-violet-600 hover:underline decoration-dotted" title="Im Concepter-Editor öffnen">{{ $e->consumer_name ?: $e->name }}</a>
                    </span>
                    <span class="shrink-0 tabular-nums text-gray-500">{{ $e->price_per_person_cache !== null ? number_format((float) $e->price_per_person_cache, 2, ',', '.') . ' €' : '—' }}</span>
                </div>
            @empty
                <p class="text-[11px] text-gray-500 py-0.5">Noch keine Editionen zugeordnet.</p>
            @endforelse
        </x-foodalchemist::section>

        {{-- Struktur-Blöcke (header/text/spacer) — Gliederung des Formats --}}
        @php($strukturSlots = $format->slots->whereIn('type', ['header', 'text', 'spacer']))
        @if($strukturSlots->isNotEmpty())
            <x-foodalchemist::section title="Struktur" icon="heroicon-o-bars-3-bottom-left" :meta="$strukturSlots->count()">
                @foreach($strukturSlots as $s)
                    <div wire:key="fst-{{ $s->id }}" class="flex items-center gap-2 text-[13px] py-0.5">
                        <span class="{{ $pill }} {{ $variantPill['secondary'] }} shrink-0">{{ ['header' => 'Header', 'text' => 'Text', 'spacer' => 'Leerzeile'][$s->type] ?? $s->type }}</span>
                        <span class="min-w-0 truncate text-gray-700">{{ $s->title ?: $s->text_content ?: ($s->type === 'spacer' ? ($s->height ?? 'mittel') : '—') }}</span>
                    </div>
                @endforeach
            </x-foodalchemist::section>
        @endif

        {{-- Aktionen — Labels konsistent: „Druck/Karte" (schöne Ausgabe) + „Report" (technisch) --}}
        <div class="flex flex-wrap items-center gap-1.5 pt-2 border-t border-black/5">
            <button type="button" wire:click="bearbeiten" class="{{ $btnGhostXs }}">@svg('heroicon-o-pencil-square', 'w-3.5 h-3.5') Bearbeiten</button>
            <a href="{{ route('foodalchemist.formate.dokument', ['id' => $format->id]) }}" target="_blank"
               class="{{ $btnGhostXs }}" title="Schöne Kunden-Ausgabe (Druck/PDF)" data-formate-panel-druck>
                @svg('heroicon-o-printer', 'w-3.5 h-3.5') Druck/Karte
            </a>
            <a href="{{ route('foodalchemist.formate.report', ['id' => $format->id, 'profil' => 'voll']) }}" target="_blank"
               class="{{ $btnGhostXs }}" title="Technischer Report mit Profilen + Filtern (Editionen → volle Kaskade)" data-formate-panel-report>
                @svg('heroicon-o-document-text', 'w-3.5 h-3.5') Report
            </a>
            <button type="button" wire:click="loeschen" wire:confirm="Format wirklich löschen? Die Editionen werden wieder freistehend."
                    class="{{ $btnGhostXs }} text-rose-600">Löschen</button>
        </div>
    @endif
</div>
