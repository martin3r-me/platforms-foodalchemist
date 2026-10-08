{{-- Formate-Editor — fa-pass (2026-10-05), Werkbank-Modus auf Bausteinen <x-fa::…>.
     Häufigste Aufgabe: den Aufbau der Karte pflegen (Editionen und Pakete einfügen, Reihenfolge,
     Gästetexte). Der Kopf trägt genau eine Hauptaktion (Speichern), Karte und Bericht liegen im Menü
     „Weitere Aktionen". Alle wire:-Bindungen, wire:keys, Event-Namen und data-Marker unverändert. --}}
@php
    $statusOptionen = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiviert'];
    $herkunftOptionen = ['' => 'Offen', 'eigen' => 'Eigen', 'gruppe' => 'Gruppe', 'kunde' => 'Kunde'];
    $blockLabel = ['header' => 'Überschrift', 'text' => 'Freitext', 'spacer' => 'Leerzeile'];
    $hoehen = ['klein' => 'Klein', 'mittel' => 'Mittel', 'gross' => 'Groß'];
    $geld = fn ($wert) => $wert === null ? null : number_format((float) $wert, 2, ',', '.') . ' €';

    // Facetten-Chip (Mehrfachauswahl, wirkt sofort über toggleFacette) — seitenlokal, Tokens only.
    $chip = 'inline-flex items-center h-7 px-2.5 rounded-full border text-[length:var(--fa-text-md)] transition-colors duration-150';
    $chipAn = 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)] font-medium';
    $chipAus = 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)] hover:border-[var(--fa-ink-3)]';

    // Segment-Schalter (Konzepte | Pakete) im Einfüge-Bereich.
    $segment = 'flex-1 h-7 px-2.5 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-md)] font-medium transition-colors duration-150';
    $segmentAn = 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm';
    $segmentAus = 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]';

    $menuePunkt = 'flex w-full items-center gap-2 px-3 py-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';

    // Keine nackten Mengen-Zähler an Reitern (Dominique 2026-10-05: „warum steht da Aufbau 1?") —
    // Zahlen am Reiter nur für Offenes/Handlungsbedarf. Die Menge steht im Reiter selbst.
@endphp

<div>
    <x-foodalchemist::modal name="formate-editor" title="Format bearbeiten" :title-name="$format?->name" fullscreen dark-canvas>
        <x-slot:actions>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <span x-data="{ zeigen: false }" x-on:formate-gespeichert.window="zeigen = true; setTimeout(() => zeigen = false, 1600)"
                      x-show="zeigen" x-cloak class="inline-flex items-center gap-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ok)]">
                    @svg('heroicon-m-check', 'w-4 h-4') Gespeichert
                </span>
                @if($format)
                    <div class="relative" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                        <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                        <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-60 fa-surface shadow-lg py-1">
                            <a href="{{ route('foodalchemist.formate.dokument', ['id' => $format->id]) }}" target="_blank" role="menuitem" x-on:click="offen = false"
                               class="{{ $menuePunkt }}" title="Karte für den Kunden, zum Drucken oder als PDF">
                                @svg('heroicon-o-printer', 'w-4 h-4 text-[var(--fa-ink-3)]') Karte drucken
                            </a>
                            <a href="{{ route('foodalchemist.formate.report', ['id' => $format->id, 'profil' => 'voll']) }}" target="_blank" role="menuitem" x-on:click="offen = false"
                               class="{{ $menuePunkt }}" title="Ausführlicher Bericht über alle Editionen mit Rezepten, Preisen und Deklaration">
                                @svg('heroicon-o-document-text', 'w-4 h-4 text-[var(--fa-ink-3)]') Bericht öffnen
                            </a>
                        </div>
                    </div>
                @endif
                {{-- Spec 65: erst „Bearbeiten" (Sperre), dann Abbrechen/Speichern; Speichern beendet die Bearbeitung, der Editor bleibt offen --}}
                <x-foodalchemist::bearbeiten-leiste :zustand="$sperr">
                    <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="bearbeitungSpeichern" wire:loading.attr="disabled" wire:target="bearbeitungSpeichern" data-format-speichern>Speichern</x-fa::button>
                </x-foodalchemist::bearbeiten-leiste>
            </div>
        </x-slot:actions>

        @if($format === null)
            <x-fa::empty icon="heroicon-o-rectangle-group" title="Kein Format geladen">Das Format ist nicht mehr verfügbar. Editor schließen und die Liste neu laden.</x-fa::empty>
        @else
            <x-foodalchemist::editor-tabs marker="format" action="setTab" :active="$tab" :tabs="[
                'identitaet' => 'Identität',
                'editionen' => 'Aufbau',
                'kalkulation' => 'Kalkulation',
                'bilder' => 'Bilder',
                'notizen' => 'Notizen',
            ]" />

            {{-- Spec 77d: fremdes Format nur lesend → eigene Kopie; Kopie mit geändertem Original --}}
            @if(($herkunft ?? null) !== null)
                @if($herkunft['fremd'])
                    <x-fa::notice tone="info" data-eigene-kopie-hinweis>
                        Dieses Format gehört {{ $herkunft['besitzer'] ?? 'einem anderen Team' }} und ist hier nur lesbar. Zum Anpassen eine eigene Kopie anlegen.
                        <x-slot:actions>
                            <x-fa::button size="sm" icon="heroicon-m-document-duplicate" wire:click="eigeneKopieAnlegen" data-eigene-kopie>Eigene Kopie</x-fa::button>
                        </x-slot:actions>
                    </x-fa::notice>
                @elseif($herkunft['original_geaendert'])
                    <x-fa::notice tone="warn" data-original-geaendert>Das Original dieser Kopie wurde seit dem Kopieren geändert.</x-fa::notice>
                @endif
            @endif

            {{-- Spec 65: Reiter im Server-Modus liefern nur die Leiste — den Panel-Bereich sperrt im Lesemodus
                 dieses fieldset (Reiterleiste bleibt außerhalb bedienbar). --}}
            <fieldset @disabled(in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true)) class="contents" data-fa-lesemodus="{{ in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true) ? '1' : '0' }}">

            @if($fehler)
                <x-fa::notice tone="crit" title="Nicht gespeichert">{{ $fehler }}</x-fa::notice>
            @endif

            {{-- ── IDENTITÄT: wer ist das Format, wofür wird es eingesetzt ── --}}
            @if($tab === 'identitaet')
                <div class="grid gap-4 xl:grid-cols-2">
                    <x-fa::section title="Marke" icon="heroicon-o-tag">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-fa::field label="Interner Name" for="fmt-name" class="sm:col-span-2">
                                <x-fa::input id="fmt-name" wire:model="form.name" placeholder="z. B. CHEFS.CORNER, WORLD ON A PLATE" />
                            </x-fa::field>
                            <x-fa::field label="Name für Gäste" for="fmt-consumer" hint="Steht auf der Karte. Leer lassen, dann gilt der interne Name.">
                                <x-fa::input id="fmt-consumer" wire:model="form.consumer_name" />
                            </x-fa::field>
                            <x-fa::field label="Claim" for="fmt-claim">
                                <x-fa::input id="fmt-claim" wire:model="form.claim" placeholder="z. B. WORLD ON A PLATE" />
                            </x-fa::field>
                        </div>
                    </x-fa::section>

                    <x-fa::section title="Herkunft und Status" icon="heroicon-o-shield-check">
                        <x-fa::choice name="form.status" :live="false" label="Status" :options="$statusOptionen" />
                        <x-fa::choice name="form.origin" :live="false" label="Herkunft" :options="$herkunftOptionen" />
                        <div x-data x-show="$wire.form.origin === 'kunde'" x-cloak>
                            <x-fa::field label="Kunde" for="fmt-customer" hint="Kundeneigenes Format. Nur für diesen Kunden verwenden, nie für andere übernehmen.">
                                <x-fa::input id="fmt-customer" wire:model="form.customer" placeholder="Name des Kunden" />
                            </x-fa::field>
                        </div>
                    </x-fa::section>
                </div>

                {{-- F1: dieselben Merkmale wie am Concept, aus den Einstellungen gepflegt. Wirkt sofort. --}}
                <x-fa::section title="Einsatz" icon="heroicon-o-calendar-days"
                               description="Dieselben Merkmale wie bei den Konzepten, gepflegt in den Einstellungen. Änderungen hier werden sofort gespeichert.">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-fa::field label="Servierform" for="fmt-servierform">
                            <x-fa::select id="fmt-servierform" wire:model="form.serving_form_id" wire:change="speichern" placeholder="Keine Angabe">
                                @foreach($servierformen as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                            </x-fa::select>
                        </x-fa::field>
                        <x-fa::field label="Eventtyp" for="fmt-eventtyp">
                            <x-fa::select id="fmt-eventtyp" wire:model="form.event_type_id" wire:change="speichern" placeholder="Keine Angabe">
                                @foreach($eventtypen as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                            </x-fa::select>
                        </x-fa::field>
                    </div>
                    <div class="flex flex-col gap-3">
                        @foreach([
                            ['Einsatzmoment', 'einsatzmoment_ids', $einsatzmomente],
                            ['Saison', 'saison_ids', $saisons],
                            ['Zielgruppe', 'target_group_ids', $zielgruppen],
                        ] as [$facetteLabel, $facetteFeld, $facetteWerte])
                            @if($facetteWerte->isNotEmpty())
                                <div class="flex flex-col gap-1.5" role="group" aria-label="{{ $facetteLabel }}">
                                    <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">{{ $facetteLabel }}</span>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($facetteWerte as $wert)
                                            @php
                                                $gewaehlt = in_array($wert->id, $form[$facetteFeld] ?? []);
                                            @endphp
                                            <button type="button" wire:key="fac-{{ $facetteFeld }}-{{ $wert->id }}" wire:click="toggleFacette('{{ $facetteFeld }}', {{ $wert->id }})"
                                                    aria-pressed="{{ $gewaehlt ? 'true' : 'false' }}"
                                                    class="{{ $chip }} {{ $gewaehlt ? $chipAn : $chipAus }}">{{ $wert->name }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </x-fa::section>

                <x-fa::section title="Marken-Story" icon="heroicon-o-sparkles" description="Der Text für Kunde und Präsentation.">
                    <x-fa::textarea wire:model="form.story" rows="7" placeholder="Worum geht es in diesem Format, was erlebt der Gast …" aria-label="Marken-Story" />
                </x-fa::section>
            @endif

            {{-- ── AUFBAU (F2): links die Positionen der Karte, rechts das Einfügen (F6) ── --}}
            @if($tab === 'editionen')
                @php
                    $conceptSlots = $aufbauSlots->where('type', 'concept');
                    $nEditionen = $conceptSlots->count();
                    $nPositionen = $aufbauSlots->count();
                    $facettenAktiv = collect([$pickerServierform, $pickerEventtyp, $pickerMoment, $pickerSaison])->filter(fn ($v) => (string) $v !== '')->count();
                @endphp
                <div class="flex flex-col lg:flex-row gap-4 items-start">
                    {{-- ═══ LINKS: Positionen ═══ --}}
                    <div class="flex-1 min-w-0 w-full flex flex-col gap-3">
                        {{-- Kartenkopf: kommt automatisch aus der Identität --}}
                        <div class="fa-surface px-4 py-3 flex flex-col gap-0.5">
                            <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Kartenkopf, automatisch aus der Identität</span>
                            <p class="text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">{{ $format->consumer_name ?: $format->name }}</p>
                            @if($format->claim)<p class="text-[length:var(--fa-text-md)] italic text-[var(--fa-ink-2)]">„{{ $format->claim }}“</p>@endif
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="flex items-baseline gap-2 text-[length:var(--fa-text-lg)] font-semibold text-[var(--fa-ink)]">
                                Aufbau
                                <span class="text-[length:var(--fa-text-sm)] font-normal text-[var(--fa-ink-3)] tabular-nums">{{ $nEditionen }} {{ $nEditionen === 1 ? 'Edition' : 'Editionen' }} · {{ $nPositionen }} {{ $nPositionen === 1 ? 'Position' : 'Positionen' }}</span>
                            </h3>
                            @if($einfuegenNachId !== null)
                                <span class="inline-flex flex-wrap items-center gap-2 text-[length:var(--fa-text-sm)] text-[var(--fa-accent)]">
                                    @svg('heroicon-m-map-pin', 'w-4 h-4') Neues landet unter der markierten Position
                                    <x-fa::button size="sm" variant="ghost" wire:click="einfuegenZiel(null)">Ans Ende setzen</x-fa::button>
                                </span>
                            @endif
                        </div>

                        @forelse($aufbauSlots as $s)
                            @php
                                $istZiel = $einfuegenNachId === $s->id;
                                $rahmen = $istZiel ? 'border-[var(--fa-accent)] ring-1 ring-[var(--fa-accent)]' : '';
                            @endphp
                            @if($s->type === 'concept')
                                @php
                                    $c = $s->concept;
                                    $istPaketSlot = ($c?->kind ?? null) === 'paket';
                                @endphp
                                <div wire:key="slot-{{ $s->id }}" class="fa-surface p-4 flex flex-col gap-3 {{ $rahmen }}">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0 flex flex-wrap items-center gap-2">
                                            <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">Position {{ $loop->iteration }}</span>
                                            @if($istPaketSlot)<x-fa::badge tone="info" icon="heroicon-m-archive-box">Paket</x-fa::badge>@else<x-fa::badge tone="accent">Edition</x-fa::badge>@endif
                                            <span class="min-w-0 break-words text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">{{ $c?->name ?? 'Konzept nicht mehr verfügbar' }}</span>
                                        </div>
                                        <div class="flex items-center gap-0.5 shrink-0">
                                            @if($c)
                                                <x-fa::icon-button size="sm" icon="heroicon-m-arrow-top-right-on-square" label="Im Concepter öffnen, um Gerichte und Abschnitte zu bearbeiten"
                                                    :href="route('foodalchemist.concepter.index', ['edit' => $c->id])" target="_blank" />
                                            @endif
                                            <x-fa::icon-button size="sm" icon="heroicon-m-map-pin" :label="$istZiel ? 'Einfügeziel aufheben' : 'Neues unter dieser Position einfügen'"
                                                wire:click="einfuegenZiel({{ $s->id }})" class="{{ $istZiel ? 'text-[var(--fa-accent)] bg-[var(--fa-accent-soft)]' : '' }}" aria-pressed="{{ $istZiel ? 'true' : 'false' }}" />
                                            <x-fa::icon-button size="sm" icon="heroicon-m-chevron-up" label="Nach oben schieben" wire:click="slotHochRunter({{ $s->id }}, -1)" :disabled="$loop->first" class="disabled:opacity-30 disabled:pointer-events-none" />
                                            <x-fa::icon-button size="sm" icon="heroicon-m-chevron-down" label="Nach unten schieben" wire:click="slotHochRunter({{ $s->id }}, 1)" :disabled="$loop->last" class="disabled:opacity-30 disabled:pointer-events-none" />
                                            <x-fa::icon-button size="sm" icon="heroicon-m-x-mark" tone="danger" label="Position entfernen (das Konzept bleibt erhalten)" wire:click="slotEntfernen({{ $s->id }})" />
                                        </div>
                                    </div>

                                    @if($c)
                                        {{-- Gästetexte des Konzepts: geteilt, die Änderung gilt im Konzept selbst --}}
                                        <div class="grid gap-3 md:grid-cols-2">
                                            <x-fa::field label="Titel für Gäste" for="fmt-c-titel-{{ $s->id }}">
                                                <x-fa::input id="fmt-c-titel-{{ $s->id }}" value="{{ $c->consumer_name }}" placeholder="{{ $c->name }}"
                                                    wire:change="conceptWordingSpeichern({{ $c->id }}, 'consumer_name', $event.target.value)" />
                                            </x-fa::field>
                                            <x-fa::field label="Claim" for="fmt-c-claim-{{ $s->id }}">
                                                <x-fa::input id="fmt-c-claim-{{ $s->id }}" value="{{ $c->claim }}" placeholder="z. B. Die neue Küche der Welt"
                                                    wire:change="conceptWordingSpeichern({{ $c->id }}, 'claim', $event.target.value)" />
                                            </x-fa::field>
                                            <x-fa::field label="Hinführung" for="fmt-c-hin-{{ $s->id }}" class="md:col-span-2" hint="Gilt im Konzept selbst, also überall, wo es verwendet wird.">
                                                <x-fa::textarea id="fmt-c-hin-{{ $s->id }}" rows="2" placeholder="Ein, zwei Sätze, die den Gast zu dieser Edition führen …"
                                                    wire:change="conceptWordingSpeichern({{ $c->id }}, 'description', $event.target.value)">{{ $c->description }}</x-fa::textarea>
                                            </x-fa::field>
                                        </div>

                                        {{-- Vorschau der Karte: Abschnitte und Gerichte, aufgelöst wie im Foodbook --}}
                                        <div class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)] px-3 py-2.5">
                                            <div class="flex items-center justify-between gap-2 mb-1.5">
                                                <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">So steht es auf der Karte</span>
                                                <x-fa::money :value="$c->price_per_person_cache" per="Person" class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" />
                                            </div>
                                            <div class="flex flex-col gap-0.5">
                                                @forelse($editionMenus[$s->id] ?? [] as $g)
                                                    @if($g['type'] === 'header')
                                                        <p class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-ink)] mt-1.5 first:mt-0" style="margin-left:{{ ($g['einrueckung'] ?? 0) * 14 }}px">{{ $g['text'] }}</p>
                                                    @elseif($g['type'] === 'paket')
                                                        <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)] mt-1 flex items-center gap-1.5" style="margin-left:{{ ($g['einrueckung'] ?? 0) * 14 }}px">
                                                            <x-fa::badge tone="info">Paket</x-fa::badge>
                                                            <span class="min-w-0 break-words">{{ $g['text'] }}</span>
                                                            @if(($g['preis'] ?? null) !== null)<x-fa::money :value="$g['preis']" class="ml-auto text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" />@endif
                                                        </p>
                                                    @else
                                                        {{-- Format C1: direkte Gericht-Zeile (slot_id) inline bearbeitbar, gilt nur in diesem Format --}}
                                                        @php
                                                            $slotKey = isset($g['slot_id']) ? $s->id . ':' . $g['slot_id'] : null;
                                                        @endphp
                                                        @if($slotKey !== null && $editSlotKey === $slotKey)
                                                            <div class="flex flex-wrap items-center gap-1.5 py-0.5" style="margin-left:{{ 8 + ($g['einrueckung'] ?? 0) * 14 }}px">
                                                                <x-fa::input size="sm" wire:model="editSlotWording" wire:keydown.enter="slotWordingSpeichern" wire:keydown.escape="slotWordingAbbrechen"
                                                                    class="flex-1 min-w-[12rem]" placeholder="Name auf der Karte, leer lassen für den Standardtext" aria-label="Name auf der Karte" data-fmt-slot-input />
                                                                <x-fa::button size="sm" variant="primary" wire:click="slotWordingSpeichern">Text übernehmen</x-fa::button>
                                                                <x-fa::icon-button size="sm" icon="heroicon-m-x-mark" label="Bearbeiten abbrechen" wire:click="slotWordingAbbrechen" />
                                                            </div>
                                                        @else
                                                            <p class="group/dish text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] leading-snug flex flex-wrap items-center gap-1.5" style="margin-left:{{ 8 + ($g['einrueckung'] ?? 0) * 14 }}px">
                                                                <span class="min-w-0 break-words">{{ $g['text'] }}</span>
                                                                @if(($g['source'] ?? null) === 'name')<x-fa::signal tone="warn">Verkaufstext fehlt</x-fa::signal>@endif
                                                                @if(isset($g['slot_id']))
                                                                    <button type="button" wire:click="slotWordingBearbeiten({{ $s->id }}, {{ $g['slot_id'] }}, @js(($g['source'] ?? null) === 'name' ? '' : $g['text']))"
                                                                            class="shrink-0 inline-flex items-center justify-center w-6 h-6 rounded-[var(--fa-radius-control)] text-[var(--fa-ink-3)] hover:text-[var(--fa-accent)] hover:bg-[var(--fa-hover)] opacity-40 group-hover/dish:opacity-100 focus-visible:opacity-100 transition-opacity"
                                                                            title="Name auf der Karte bearbeiten (nur in diesem Format)" aria-label="Name auf der Karte bearbeiten" data-fmt-slot-edit>@svg('heroicon-m-pencil', 'w-3.5 h-3.5')</button>
                                                                @endif
                                                            </p>
                                                        @endif
                                                    @endif
                                                @empty
                                                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Noch keine Gerichte. Das Konzept im Concepter füllen.</p>
                                                @endforelse
                                            </div>
                                        </div>
                                    @else
                                        <x-fa::notice tone="crit">Das Konzept dieser Position ist nicht mehr verfügbar. Position entfernen.</x-fa::notice>
                                    @endif
                                </div>
                            @else
                                {{-- Gliederung: Überschrift / Freitext / Leerzeile --}}
                                <div wire:key="slot-{{ $s->id }}" class="fa-surface px-4 py-3 flex items-start gap-3 {{ $rahmen }}">
                                    <div class="flex-1 min-w-0">
                                        @if($s->type === 'header')
                                            <x-fa::field label="Überschrift" for="fmt-b-{{ $s->id }}">
                                                <x-fa::input id="fmt-b-{{ $s->id }}" value="{{ $s->title }}" placeholder="Überschrift …" class="font-medium"
                                                    wire:change="blockSpeichern({{ $s->id }}, 'title', $event.target.value)" />
                                            </x-fa::field>
                                        @elseif($s->type === 'text')
                                            <x-fa::field label="Freitext" for="fmt-b-{{ $s->id }}">
                                                <x-fa::textarea id="fmt-b-{{ $s->id }}" rows="2" placeholder="Freitext …"
                                                    wire:change="blockSpeichern({{ $s->id }}, 'text_content', $event.target.value)">{{ $s->text_content }}</x-fa::textarea>
                                            </x-fa::field>
                                        @else
                                            <x-fa::field label="Leerzeile" for="fmt-b-{{ $s->id }}">
                                                <x-fa::select id="fmt-b-{{ $s->id }}" class="w-40" wire:change="blockSpeichern({{ $s->id }}, 'height', $event.target.value)">
                                                    @foreach($hoehen as $h => $txt)
                                                        <option value="{{ $h }}" @selected(($s->height ?? 'mittel') === $h)>{{ $txt }}</option>
                                                    @endforeach
                                                </x-fa::select>
                                            </x-fa::field>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-0.5 shrink-0 pt-6">
                                        <x-fa::icon-button size="sm" icon="heroicon-m-map-pin" :label="$istZiel ? 'Einfügeziel aufheben' : 'Neues unter dieser Position einfügen'"
                                            wire:click="einfuegenZiel({{ $s->id }})" class="{{ $istZiel ? 'text-[var(--fa-accent)] bg-[var(--fa-accent-soft)]' : '' }}" aria-pressed="{{ $istZiel ? 'true' : 'false' }}" />
                                        <x-fa::icon-button size="sm" icon="heroicon-m-chevron-up" label="Nach oben schieben" wire:click="slotHochRunter({{ $s->id }}, -1)" :disabled="$loop->first" class="disabled:opacity-30 disabled:pointer-events-none" />
                                        <x-fa::icon-button size="sm" icon="heroicon-m-chevron-down" label="Nach unten schieben" wire:click="slotHochRunter({{ $s->id }}, 1)" :disabled="$loop->last" class="disabled:opacity-30 disabled:pointer-events-none" />
                                        <x-fa::icon-button size="sm" icon="heroicon-m-x-mark" tone="danger" label="{{ ($blockLabel[$s->type] ?? 'Position') }} entfernen" wire:click="slotEntfernen({{ $s->id }})" />
                                    </div>
                                </div>
                            @endif
                        @empty
                            <div class="fa-surface">
                                <x-fa::empty icon="heroicon-o-rectangle-stack" title="Noch kein Aufbau">Rechts eine neue Edition anlegen, ein Konzept oder Paket einfügen oder die Karte mit Überschriften gliedern.</x-fa::empty>
                            </div>
                        @endforelse
                    </div>

                    {{-- ═══ RECHTS: Einfügen (Conceptor-Stil) ═══ --}}
                    <aside class="w-full lg:w-80 xl:w-96 shrink-0 fa-surface p-4 flex flex-col gap-4 lg:sticky lg:top-14 self-start">
                        {{-- Picker: Konzept ODER Paket einfügen — das Häufigste zuerst --}}
                        <div class="flex flex-col gap-2">
                            <h4 class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">Einfügen</h4>
                            <div class="flex gap-1 p-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] border border-[var(--fa-line)]" role="tablist" data-formate-picker-tabs>
                                <button type="button" role="tab" aria-selected="{{ $pickerTab === 'concept' ? 'true' : 'false' }}" wire:click="setPickerTab('concept')" class="{{ $segment }} {{ $pickerTab === 'concept' ? $segmentAn : $segmentAus }}">Konzepte</button>
                                <button type="button" role="tab" aria-selected="{{ $pickerTab === 'paket' ? 'true' : 'false' }}" wire:click="setPickerTab('paket')" class="{{ $segment }} {{ $pickerTab === 'paket' ? $segmentAn : $segmentAus }}">Pakete</button>
                            </div>

                            @if($pickerTab === 'paket')
                                <x-fa::input type="search" wire:model.live.debounce.300ms="paketSuche" placeholder="Paket suchen …" aria-label="Paket suchen" />
                                <x-fa::select wire:model.live="pickerKlasse" placeholder="Alle Klassen" aria-label="Klasse" data-formate-picker-klasse>
                                    @foreach($pickerPaketKlassen as $kl)<option value="{{ $kl }}">{{ $kl }}</option>@endforeach
                                </x-fa::select>
                            @else
                                <x-fa::input type="search" wire:model.live.debounce.300ms="editionSuche" placeholder="Konzept suchen …" aria-label="Konzept suchen" />
                                <x-fa::select wire:model.live="pickerKlasse" placeholder="Alle Klassen" aria-label="Klasse" data-formate-picker-klasse>
                                    @foreach($pickerKlassen as $kl)<option value="{{ $kl }}">{{ $kl }}</option>@endforeach
                                </x-fa::select>
                            @endif

                            {{-- Merkmal-Filter: eingeklappt, öffnet von selbst, wenn einer gesetzt ist --}}
                            @if($servierformen->isNotEmpty() || $eventtypen->isNotEmpty() || $einsatzmomente->isNotEmpty() || $saisons->isNotEmpty())
                                <details class="group" wire:ignore.self @if($facettenAktiv > 0) open @endif>
                                    <summary class="inline-flex items-center gap-1 cursor-pointer select-none text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]">
                                        @svg('heroicon-m-chevron-right', 'w-4 h-4 transition-transform group-open:rotate-90')
                                        Nach Merkmalen filtern
                                        @if($facettenAktiv > 0)<x-fa::badge tone="accent">{{ $facettenAktiv }}</x-fa::badge>@endif
                                    </summary>
                                    <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2 gap-2" data-formate-picker-facetten>
                                        @if($servierformen->isNotEmpty())
                                            <x-fa::select size="sm" wire:model.live="pickerServierform" placeholder="Jede Servierform" aria-label="Servierform">
                                                @foreach($servierformen as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                                            </x-fa::select>
                                        @endif
                                        @if($eventtypen->isNotEmpty())
                                            <x-fa::select size="sm" wire:model.live="pickerEventtyp" placeholder="Jeder Eventtyp" aria-label="Eventtyp">
                                                @foreach($eventtypen as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                                            </x-fa::select>
                                        @endif
                                        @if($einsatzmomente->isNotEmpty())
                                            <x-fa::select size="sm" wire:model.live="pickerMoment" placeholder="Jeder Einsatzmoment" aria-label="Einsatzmoment">
                                                @foreach($einsatzmomente as $em)<option value="{{ $em->id }}">{{ $em->name }}</option>@endforeach
                                            </x-fa::select>
                                        @endif
                                        @if($saisons->isNotEmpty())
                                            <x-fa::select size="sm" wire:model.live="pickerSaison" placeholder="Jede Saison" aria-label="Saison">
                                                @foreach($saisons as $sa)<option value="{{ $sa->id }}">{{ $sa->name }}</option>@endforeach
                                            </x-fa::select>
                                        @endif
                                    </div>
                                </details>
                            @endif

                            {{-- Trefferliste je Reiter --}}
                            @if($pickerTab === 'paket')
                                <ul class="flex flex-col max-h-[46vh] overflow-auto -mx-1 px-1" data-formate-paket-liste>
                                    @forelse($paketKandidaten as $k)
                                        <li wire:key="pkand-{{ $k->id }}" class="flex items-center gap-2 py-1.5 border-t border-[var(--fa-line)] first:border-t-0">
                                            <span class="min-w-0 flex-1 break-words text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" title="{{ $k->name }}">{{ $k->consumer_name ?: $k->name }}</span>
                                            <x-fa::money :value="$k->price_per_person_cache" class="shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" />
                                            <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="conceptEinfuegen({{ $k->id }})" class="shrink-0">Einfügen</x-fa::button>
                                        </li>
                                    @empty
                                        <li><x-fa::empty compact icon="heroicon-o-archive-box" title="Keine aktiven Pakete gefunden">Suche oder Filter ändern.</x-fa::empty></li>
                                    @endforelse
                                </ul>
                            @else
                                <ul class="flex flex-col max-h-[46vh] overflow-auto -mx-1 px-1" data-formate-concept-liste>
                                    @forelse($kandidaten as $k)
                                        <li wire:key="kand-{{ $k->id }}" class="flex items-center gap-2 py-1.5 border-t border-[var(--fa-line)] first:border-t-0">
                                            <span class="min-w-0 flex-1 break-words text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" title="{{ $k->name }}">{{ $k->consumer_name ?: $k->name }}</span>
                                            <x-fa::money :value="$k->price_per_person_cache" class="shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" />
                                            <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="conceptEinfuegen({{ $k->id }})" class="shrink-0">Einfügen</x-fa::button>
                                        </li>
                                    @empty
                                        <li><x-fa::empty compact icon="heroicon-o-rectangle-stack" title="Keine aktiven Konzepte gefunden">Suche oder Filter ändern.</x-fa::empty></li>
                                    @endforelse
                                </ul>
                            @endif
                        </div>

                        {{-- Neue Edition mit Abschnitts-Gerüst --}}
                        <div class="flex flex-col gap-2 pt-4 border-t border-[var(--fa-line)]">
                            <h4 class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">Neue Edition</h4>
                            <x-fa::field for="fmt-neue-edition" hint="Legt die Abschnitte {{ implode(', ', \Platform\FoodAlchemist\Services\FormatService::SEKTIONS_GERUEST) }} gleich mit an.">
                                <div class="flex items-center gap-2">
                                    <x-fa::input id="fmt-neue-edition" wire:model="neueEditionName" wire:keydown.enter="neueEdition" placeholder="Name, z. B. FUTURE FLAVORS" class="flex-1 min-w-0" />
                                    <x-fa::button icon="heroicon-m-plus" wire:click="neueEdition" class="shrink-0">Edition anlegen</x-fa::button>
                                </div>
                            </x-fa::field>
                        </div>

                        {{-- Gliederung der Karte --}}
                        <div class="flex flex-col gap-2 pt-4 border-t border-[var(--fa-line)]">
                            <h4 class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">Gliederung</h4>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-plus" wire:click="blockHinzu('header')">Überschrift</x-fa::button>
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-plus" wire:click="blockHinzu('text')">Freitext</x-fa::button>
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-plus" wire:click="blockHinzu('spacer')">Leerzeile</x-fa::button>
                            </div>
                        </div>
                    </aside>
                </div>
            @endif

            {{-- ── KALKULATION (F4): wie rechnen sich die Editionen ── --}}
            @if($tab === 'kalkulation')
                @php
                    if ($kalkSumme['min'] === null) {
                        $spanne = $kalkSumme['n'] > 0 ? 'Preis fehlt' : '–';
                    } elseif ($kalkSumme['min'] === $kalkSumme['max']) {
                        $spanne = $geld($kalkSumme['min']);
                    } else {
                        $spanne = number_format($kalkSumme['min'], 2, ',', '.') . ' bis ' . $geld($kalkSumme['max']);
                    }
                    // Editionen ohne Preis nicht verschweigen: die Spanne gilt nur für die bepreisten.
                    $ohnePreis = $kalkZeilen->whereNull('vk')->count();
                    $kennzahlen = [
                        ['label' => 'Preisspanne je Person', 'value' => $spanne, 'primary' => $kalkSumme['min'] !== null, 'tone' => $kalkSumme['min'] === null && $kalkSumme['n'] > 0 ? 'crit' : null, 'kpi' => 'spanne',
                         'hint' => ($kalkSumme['min'] !== null && $ohnePreis > 0) ? $ohnePreis . ' ohne Preis' : null],
                        ['label' => 'Ø Preis je Person', 'value' => $geld($kalkSumme['avg']) ?? '–', 'kpi' => 'schnitt'],
                        ['label' => 'Ø Wareneinsatz', 'value' => $kalkSumme['avg_w'] !== null ? number_format($kalkSumme['avg_w'], 1, ',', '.') . ' %' : '–', 'tone' => ($kalkSumme['avg_w'] ?? 0) > 35 ? 'crit' : null, 'kpi' => 'wareneinsatz'],
                        ['label' => $kalkSumme['n'] === 1 ? 'Edition' : 'Editionen', 'value' => (string) $kalkSumme['n'], 'kpi' => 'editionen'],
                    ];
                @endphp
                <x-fa::kpis :items="$kennzahlen" />

                <x-fa::section title="Editionen" icon="heroicon-o-calculator" :meta="$kalkSumme['n']"
                               description="Die Werte kommen aus den Konzepten. Preise und Kosten pflegst du im jeweiligen Konzept.">
                    @if($kalkZeilen->isEmpty())
                        <x-fa::empty compact icon="heroicon-o-rectangle-stack" title="Noch keine Editionen">Im Reiter Aufbau ein Konzept einfügen.</x-fa::empty>
                    @else
                        <div class="overflow-x-auto -mx-4">
                            <table class="fa-table">
                                <thead>
                                    <tr>
                                        <th class="w-full">Edition</th>
                                        <th class="num">Preis je Person</th>
                                        <th class="num">Wareneinsatz je Person</th>
                                        <th class="num">Wareneinsatz in %</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($kalkZeilen as $z)
                                        <tr>
                                            <td class="break-words">{{ $z['name'] }}</td>
                                            <td class="num"><x-fa::money :value="$z['vk']" /></td>
                                            <td class="num text-[var(--fa-ink-2)]"><x-fa::money :value="$z['ek']" missing="Kosten fehlen" /></td>
                                            <td class="num {{ ($z['w'] !== null && $z['w'] > 35) ? 'text-[var(--fa-crit)] font-medium' : '' }}">{{ $z['w'] !== null ? number_format($z['w'], 1, ',', '.') . ' %' : '–' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-fa::section>
            @endif

            {{-- ── BILDER ── --}}
            @if($tab === 'bilder')
                <x-fa::section title="Bild hochladen" icon="heroicon-o-arrow-up-tray" description="Bis 8 MB. Das erste Bild wird automatisch Titelbild.">
                    <input type="file" wire:model="bildUpload" accept="image/*" aria-label="Bild auswählen"
                           class="block w-full text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] file:mr-3 file:h-9 file:px-3.5 file:rounded-[var(--fa-radius-control)] file:border file:border-[var(--fa-line-strong)] file:bg-[var(--fa-surface)] file:text-[var(--fa-ink)] file:font-medium file:cursor-pointer hover:file:bg-[var(--fa-hover)]" />
                    <div wire:loading wire:target="bildUpload" class="text-[length:var(--fa-text-sm)] text-[var(--fa-accent)]">Lädt hoch …</div>
                    @error('bildUpload')<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-crit)]" role="alert">{{ $message }}</p>@enderror
                </x-fa::section>

                <x-fa::section title="Bildwelt" icon="heroicon-o-photo" :meta="$format->images->count()">
                    @if($format->images->isEmpty())
                        <x-fa::empty compact icon="heroicon-o-photo" title="Noch keine Bilder">Oben ein Bild hochladen.</x-fa::empty>
                    @else
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                            @foreach($format->images as $img)
                                <div wire:key="img-{{ $img->id }}" class="rounded-[var(--fa-radius-surface)] overflow-hidden border bg-[var(--fa-surface)] {{ $img->is_hero ? 'border-[var(--fa-accent)] ring-1 ring-[var(--fa-accent)]' : 'border-[var(--fa-line)]' }}">
                                    <div class="relative">
                                        <img src="{{ $img->url() }}" alt="{{ $img->caption }}" class="w-full h-36 object-cover" />
                                        @if($img->is_hero)<x-fa::badge tone="accent" icon="heroicon-m-star" class="absolute top-2 left-2">Titelbild</x-fa::badge>@endif
                                    </div>
                                    <div class="p-2.5 flex flex-col gap-2">
                                        <x-fa::input size="sm" value="{{ $img->caption }}" wire:change="bildCaption({{ $img->id }}, $event.target.value)"
                                            placeholder="Bildunterschrift …" aria-label="Bildunterschrift" />
                                        <div class="flex items-center justify-between gap-1">
                                            @unless($img->is_hero)
                                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-star" wire:click="heroSetzen({{ $img->id }})">Als Titelbild setzen</x-fa::button>
                                            @else
                                                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Titelbild der Marke</span>
                                            @endunless
                                            <x-fa::icon-button size="sm" icon="heroicon-m-trash" tone="danger" label="Bild löschen" wire:click="bildLoeschen({{ $img->id }})" wire:confirm="Bild löschen?" />
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-fa::section>
            @endif

            {{-- ── NOTIZEN ── --}}
            @if($tab === 'notizen')
                <x-fa::section title="Interne Notiz" icon="heroicon-o-pencil-square" description="Nur für das Team, erscheint nicht auf der Karte.">
                    <x-fa::textarea wire:model="form.note" rows="10" placeholder="Interne Notizen zum Format …" aria-label="Interne Notiz" />
                </x-fa::section>
            @endif
            </fieldset>
        @endif
    </x-foodalchemist::modal>
</div>
