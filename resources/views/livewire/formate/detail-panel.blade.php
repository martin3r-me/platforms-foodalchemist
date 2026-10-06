{{-- Formate-Detail — Anatomie Detail-Panels (DESIGN.md, Muster concepter/detail-panel), 2026-10-05.
     Reihenfolge: Bild, Kopf (Name, Status, Herkunft, EINE Hauptaktion „Im Editor öffnen", Karte/Bericht/Löschen im Menü),
     Kennzahlen (Preisspanne je Person), offene Punkte, Inhalt (Editionen, Gliederung der Karte), Einsatz, Marken-Story.
     Inhalt vollständig, nichts eingeklappt. Funktion, wire:-Bindungen und data-Marker unverändert (Marker Druck/Bericht
     sitzen jetzt an den Menü-Einträgen). --}}
@php
    $statusLabel = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiviert'];
    $statusTon = ['draft' => 'neutral', 'active' => 'ok', 'archiviert' => 'neutral'];
    $statusIcon = ['draft' => 'heroicon-m-pencil', 'active' => 'heroicon-m-check', 'archiviert' => 'heroicon-m-archive-box'];
    $originLabel = ['eigen' => 'Eigen', 'gruppe' => 'Gruppe', 'kunde' => 'Kunde'];
    $strukturLabel = ['header' => 'Überschrift', 'text' => 'Text', 'spacer' => 'Abstand'];
    $geld = fn (?float $wert) => $wert === null ? null : number_format($wert, 2, ',', '.') . ' €';
@endphp

<div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]" data-formate-panel>
    @if($format === null)
        <x-fa::empty icon="heroicon-o-rectangle-group" title="Kein Format gewählt">
            Links in der Liste ein Format anklicken, dann erscheinen hier Preisspanne, Editionen und Einsatz.
        </x-fa::empty>
    @else
        @php
            $conceptSlots = $format->slots->where('type', 'concept');
            $editionen = $conceptSlots->filter(fn ($s) => $s->concept !== null);
            $strukturSlots = $format->slots->whereIn('type', ['header', 'text', 'spacer']);
            $moments = $format->serviceMoments ?? collect();
            $seasons = $format->seasons ?? collect();
            $targets = $format->targetGroups ?? collect();
            $nEditionen = $cockpit['n_editionen'];
            $nOhnePreis = $cockpit['n_ohne_preis'] ?? 0;

            // Preisspanne: Editionen sind Alternativen → Spanne, keine Summe. Ohne Preis wird nichts geschätzt.
            if ($range['min'] === null) {
                $spanneText = $nEditionen > 0 ? 'Preis fehlt' : '–';
            } elseif ($range['min'] === $range['max']) {
                $spanneText = $geld($range['min']);
            } else {
                $spanneText = number_format($range['min'], 2, ',', '.') . ' bis ' . $geld($range['max']);
            }
            $kennzahlen = [
                ['label' => 'Preisspanne je Person', 'value' => $spanneText, 'primary' => $range['min'] !== null, 'tone' => $range['min'] === null && $nEditionen > 0 ? 'crit' : null, 'kpi' => 'spanne'],
                $cockpit['avg'] !== null || $nEditionen === 0
                    ? ['label' => 'Ø je Person', 'value' => $geld($cockpit['avg']) ?? '–', 'kpi' => 'schnitt']
                    : ['label' => 'Ø je Person', 'value' => 'fehlt', 'tone' => 'crit', 'kpi' => 'schnitt'],
                ['label' => $nEditionen === 1 ? 'Edition' : 'Editionen', 'value' => (string) $nEditionen, 'kpi' => 'editionen'],
            ];

            // Offene Punkte: was vor dem Einsatz beim Kunden noch fehlt.
            $offen = [];
            if ($nEditionen === 0) {
                $offen[] = ['crit', 'Noch keine Edition: im Editor unter „Aufbau“ ein Concept einfügen.'];
            } elseif ($nOhnePreis > 0) {
                $offen[] = ['warn', $nOhnePreis === 1 ? 'Einer Edition fehlt der Preis.' : $nOhnePreis . ' Editionen fehlt der Preis.'];
            }
        @endphp

        @if($format->heroImage)
            <img src="{{ $format->heroImage->url() }}" alt="{{ $format->name }}"
                 class="w-full h-40 object-cover rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)]" />
        @endif

        {{-- Kopf (Anatomie Detail-Panels): Name, Status, Herkunft, eine Hauptaktion, Weiteres im Menü --}}
        <x-fa::detail-kopf :title="$format->name" :subtitle="$format->consumer_name ? 'Für Gäste: ' . $format->consumer_name : null">
            @if($format->claim)
                <p class="mt-1 text-[length:var(--fa-text-md)] italic text-[var(--fa-ink-2)]">„{{ $format->claim }}“</p>
            @endif
            <x-slot:badges>
                <x-fa::badge :tone="$statusTon[$format->status] ?? 'neutral'" :icon="$statusIcon[$format->status] ?? null">{{ $statusLabel[$format->status] ?? $format->status }}</x-fa::badge>
                @if($format->origin === 'kunde')
                    <x-fa::badge tone="warn" icon="heroicon-m-lock-closed" title="Kundeneigenes Format, nicht für andere Kunden verwenden">Kunde</x-fa::badge>
                @elseif($format->origin)
                    <x-fa::badge title="Herkunft">{{ $originLabel[$format->origin] ?? $format->origin }}</x-fa::badge>
                @endif
                @if($format->customer)<x-fa::badge icon="heroicon-m-building-office" title="Kunde">{{ $format->customer }}</x-fa::badge>@endif
            </x-slot:badges>
            <x-slot:aktion>
                <x-fa::button variant="primary" size="sm" icon="heroicon-m-pencil-square" wire:click="bearbeiten">Im Editor öffnen</x-fa::button>
            </x-slot:aktion>
            <x-slot:menue>
                <x-fa::menu-item icon="heroicon-m-printer" :href="route('foodalchemist.formate.dokument', ['id' => $format->id])" target="_blank"
                    title="Karte für den Kunden, zum Drucken oder als PDF" data-formate-panel-druck>Karte drucken</x-fa::menu-item>
                <x-fa::menu-item icon="heroicon-m-document-text" :href="route('foodalchemist.formate.report', ['id' => $format->id, 'profil' => 'voll'])" target="_blank"
                    title="Ausführlicher Bericht über alle Editionen mit Rezepten, Preisen und Deklaration" data-formate-panel-report>Bericht öffnen</x-fa::menu-item>
                <x-fa::menu-item danger icon="heroicon-m-trash" wire:click="loeschen"
                    wire:confirm="Format wirklich löschen? Die Editionen bleiben als eigenständige Konzepte erhalten.">Format löschen</x-fa::menu-item>
            </x-slot:menue>
        </x-fa::detail-kopf>

        {{-- Kennzahlen (Format-Ökonomie): Preisspanne ist die Hauptzahl, dazu Ø und Zahl der Editionen --}}
        <div class="flex flex-col gap-3" data-formate-cockpit>
            <x-fa::kpis :items="$kennzahlen" />
            @if($offen !== [])
                <div class="flex flex-col gap-1">
                    @foreach($offen as [$ton, $text])
                        <x-fa::signal :tone="$ton">{{ $text }}</x-fa::signal>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="flex flex-col">
            {{-- Editionen (F2: referenzierte Concepts der type=concept-Slots) --}}
            <x-fa::section variant="plain" title="Editionen" icon="heroicon-o-rectangle-stack" :meta="$conceptSlots->count()">
                @if($editionen->isEmpty())
                    <x-fa::empty compact icon="heroicon-o-rectangle-stack" title="Noch keine Editionen">Im Editor unter „Aufbau“ ein Concept einfügen.</x-fa::empty>
                @else
                    <ul class="flex flex-col">
                        @foreach($editionen as $s)
                            @php
                                $e = $s->concept;
                            @endphp
                            <li wire:key="fed-{{ $s->id }}" class="flex items-center justify-between gap-3 py-1.5 border-b border-[var(--fa-line)] last:border-0 text-[length:var(--fa-text-md)]">
                                <span class="min-w-0 flex items-center gap-1.5">
                                    @if(($e->kind ?? null) === 'paket')<x-fa::badge tone="info" class="shrink-0">Paket</x-fa::badge>@endif
                                    <a href="{{ route('foodalchemist.concepter.index', ['edit' => $e->id]) }}" target="_blank"
                                       class="min-w-0 break-words text-[var(--fa-ink)] hover:text-[var(--fa-accent)] hover:underline"
                                       title="{{ $e->consumer_name ?: $e->name }} im Concepter öffnen">{{ $e->consumer_name ?: $e->name }}</a>
                                </span>
                                <x-fa::money :value="$e->price_per_person_cache" per="Person" class="shrink-0 text-[var(--fa-ink-2)]" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-fa::section>

            {{-- Gliederung der Karte (Überschrift/Text/Abstand) --}}
            @if($strukturSlots->isNotEmpty())
                <x-fa::section variant="plain" title="Gliederung der Karte" icon="heroicon-o-bars-3-bottom-left" :meta="$strukturSlots->count()">
                    <ul class="flex flex-col gap-1">
                        @foreach($strukturSlots as $s)
                            @php
                                $inhalt = $s->title ?: $s->text_content ?: ($s->type === 'spacer' ? ($s->height ?? 'mittel') : '–');
                            @endphp
                            <li wire:key="fst-{{ $s->id }}" class="flex items-start gap-2 text-[length:var(--fa-text-md)]">
                                <x-fa::badge class="shrink-0">{{ $strukturLabel[$s->type] ?? $s->type }}</x-fa::badge>
                                <span class="min-w-0 break-words text-[var(--fa-ink-2)]">{{ $inhalt }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-fa::section>
            @endif

            {{-- Einsatz: dieselben Concept-Facetten am Format --}}
            @if($format->servingForm || $format->eventType || $moments->isNotEmpty() || $seasons->isNotEmpty() || $targets->isNotEmpty())
                <x-fa::section variant="plain" title="Einsatz" icon="heroicon-o-calendar-days">
                    <div class="flex flex-wrap gap-1.5" data-formate-dimensionen>
                        @if($format->eventType)<x-fa::badge tone="info" title="Anlass">{{ $format->eventType->name }}</x-fa::badge>@endif
                        @if($format->servingForm)<x-fa::badge tone="info" title="Serviceform">{{ $format->servingForm->label }}</x-fa::badge>@endif
                        @foreach($moments as $m)<x-fa::badge>{{ $m->name }}</x-fa::badge>@endforeach
                        @foreach($seasons as $sa)<x-fa::badge>{{ $sa->name }}</x-fa::badge>@endforeach
                        @foreach($targets as $zg)<x-fa::badge>{{ $zg->name }}</x-fa::badge>@endforeach
                    </div>
                </x-fa::section>
            @endif

            @if($format->story)
                <x-fa::section variant="plain" title="Marken-Story" icon="heroicon-o-sparkles">
                    <p class="text-[length:var(--fa-text-md)] leading-relaxed text-[var(--fa-ink-2)] whitespace-pre-line">{{ $format->story }}</p>
                </x-fa::section>
            @endif
        </div>
    @endif
</div>
