{{-- P-8-Zutaten-Kern — EINE Quelle für Modal (M4-07), Voll-Editor (Rezept/Gericht) und Worker-Kaskade.

     fa-pass 2026-10 (Werkbank-Umbau): Die häufigste Aufgabe ist Mengen und Einheiten prüfen und
     Zutaten ergänzen/tauschen. Darum oben EINE Hinzufügen-Zeile (Suche + eingeklappte Filter,
     Trefferliste klappt erst beim Suchen auf) statt zweier fester Seitenspalten, die meist leer
     waren und in der schmalen Worker-Spalte die Tabelle abschnitten. Darunter die Tabelle,
     Summen unter den Preisspalten, Ausbeute und Preis-Hinweis UNTER der Tabelle (bricht um,
     wird nie abgeschnitten). Nur --fa-*-Tokens und x-fa-Bausteine → hell und Werkbank stimmen. --}}
@php($typFarben = $typFarben ?? \Platform\FoodAlchemist\Services\TeamSettingsService::TYP_FARBEN_DEFAULTS)
{{-- Phase 5: Typ-Farben (Team-Einstellung) als Laufzeit-Stil — Text = Farbe, Grund = Farbe + 1a (10 %). --}}
@php($typStyle = fn (string $t) => isset($typFarben[$t]) ? 'color:' . $typFarben[$t] . ';background-color:' . $typFarben[$t] . '1a' : '')
@php($rollenText = ['aroma_treiber' => 'Aromaträger', 'komponente' => 'Komponente', 'beilage' => 'Beilage', 'garnitur' => 'Garnitur'])
@php($ib = 'inline-flex items-center justify-center shrink-0 w-7 h-7 rounded-[var(--fa-radius-control)] text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] hover:bg-[var(--fa-hover)] transition-colors duration-150 disabled:opacity-30 disabled:pointer-events-none')
@php($feld = 'fa-control h-8 text-[length:var(--fa-text-md)]')
@php($spalten = $vkKontext ? 11 : 10)

@if($fehler !== null)
    <x-fa::notice tone="crit" class="mb-3" data-editor-fehler>{{ $fehler }}</x-fa::notice>
@endif

{{-- wire:key: Alpine wertet x-data bei morphdom NICHT neu aus — Rezept-Wechsel muss das Element ersetzen --}}
<div wire:key="zutaten-editor-{{ $rezept?->id ?? 0 }}"
     x-data="zutatenEditor(@js($zeilenJson), @js(! $eingebettet), @js($einheiten->keyBy('id')->map(fn ($e) => ['slug' => $e->slug, 'dim' => $e->dimension, 'g' => $e->default_in_g !== null ? (float) $e->default_in_g : ($e->default_in_ml !== null ? (float) $e->default_in_ml : null)])->all()), @js($browserVokabular ?? null))"
     data-zutaten-editor
     class="flex flex-col gap-3 min-w-0"
     @garverluste-vorschlagen.window="garverluste()">

    {{-- ── Zutaten einfügen wie im Original (Dominique 2026-10-05): links Grundprodukte, Mitte Suche + Tabelle,
         rechts Basisrezepte. Ab 1.800 px drei Spalten; auf dem Laptop eine Einfüge-Spalte mit Umschalter + Tabelle; schmal untereinander. ── --}}
        <x-fa::notice tone="warn" x-show="tauschIdx !== null" x-cloak data-tausch-banner>
            Zutat in Zeile <span class="font-semibold tabular-nums" x-text="(tauschIdx ?? 0) + 1"></span> tauschen: Ersatz in der Trefferliste mit Plus wählen. Menge und Einheit bleiben.
            <x-slot:actions>
                <x-fa::button size="sm" variant="ghost" x-on:click="tauschIdx = null" data-tausch-abbrechen>Tausch abbrechen</x-fa::button>
            </x-slot:actions>
        </x-fa::notice>

    <div x-data="{ einfuegenAus: 'gp' }" class="grid grid-cols-1 lg:grid-cols-[minmax(17rem,22rem)_minmax(0,1fr)] wide:grid-cols-[minmax(16rem,1fr)_minmax(0,2.6fr)_minmax(16rem,1fr)] gap-3 items-start" data-zutaten-layout>
        <aside class="fa-surface p-3 flex flex-col gap-3 min-w-0 lg:col-start-1 lg:row-start-1 lg:sticky lg:top-0" :class="einfuegenAus === 'gp' ? '' : 'max-wide:hidden'" aria-label="Grundprodukte einfügen">
            {{-- Laptop (< 1.800 px): EINE Einfüge-Spalte mit Umschalter; ab 1.800 px stehen beide Spalten nebeneinander --}}
            <div role="group" aria-label="Einfügen aus" class="flex p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)] wide:hidden">
                <button type="button" @click="einfuegenAus = 'gp'" :aria-pressed="einfuegenAus === 'gp'"
                        class="flex-1 h-8 px-2 rounded-[5px] text-[length:var(--fa-text-sm)] font-medium transition-colors"
                        :class="einfuegenAus === 'gp' ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]'">Grundprodukte <span class="tabular-nums text-[var(--fa-ink-3)]" x-text="gpTotal"></span></button>
                <button type="button" @click="einfuegenAus = 'rez'" :aria-pressed="einfuegenAus === 'rez'"
                        class="flex-1 h-8 px-2 rounded-[5px] text-[length:var(--fa-text-sm)] font-medium transition-colors"
                        :class="einfuegenAus === 'rez' ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]'">Basisrezepte <span class="tabular-nums text-[var(--fa-ink-3)]" x-text="rezTotal"></span></button>
            </div>
<fieldset class="flex flex-col gap-2 min-w-0">
                <legend class="sr-only">Grundprodukte filtern</legend>
                <div class="grid grid-cols-1 gap-2">
                    <select x-model="gpFilter.wg" @change="gpFilter.sub = ''; browse()" aria-label="Warengruppe" class="{{ $feld }} fa-select pr-8" data-gp-filter-wg>
                        <option value="">Alle Warengruppen</option>
                        <template x-for="w in (vokabular?.warengruppen ?? [])" :key="w.code">
                            <option :value="w.code" x-text="w.name"></option>
                        </template>
                    </select>
                    <select x-model="gpFilter.sub" @change="browse()" aria-label="Kategorie" class="{{ $feld }} fa-select pr-8" data-gp-filter-sub>
                        <option value="">Alle Kategorien</option>
                        <template x-for="su in subKategorienFuerWg()" :key="su.commodity_group_code + su.sub_category">
                            <option :value="su.sub_category" x-text="su.sub_category"></option>
                        </template>
                    </select>
                </div>
                <button type="button" @click="gpFilter.mehr = !gpFilter.mehr"
                        class="self-start inline-flex items-center gap-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)] hover:text-[var(--fa-accent-hover)]" data-gp-mehr-filter>
                    <span class="inline-flex transition-transform" :class="gpFilter.mehr ? 'rotate-90' : ''">@svg('heroicon-m-chevron-right', 'w-4 h-4')</span> <span x-text="gpFilter.mehr ? 'Weniger Filter' : 'Zustand, Bio, Regional, Favoriten'"></span>
                </button>
                <div x-show="gpFilter.mehr" x-cloak class="flex flex-wrap items-center gap-2">
                    <select x-model="gpFilter.condition" @change="browse()" aria-label="Zustand" class="{{ $feld }} fa-select w-full pr-8">
                        <option value="">Jeder Zustand</option>
                        <template x-for="z in (vokabular?.zustande ?? [])" :key="z"><option :value="z" x-text="z"></option></template>
                    </select>
                    <label class="fa-chip"><input type="checkbox" x-model="gpFilter.bio" @change="browse()" class="sr-only peer" /><span>Bio</span></label>
                    <label class="fa-chip"><input type="checkbox" x-model="gpFilter.regional" @change="browse()" class="sr-only peer" /><span>Regional</span></label>
                    {{-- 06·H4: Picker auf die kuratierten Favoriten verengen --}}
                    <label class="fa-chip" title="Nur kuratierte Favoriten"><input type="checkbox" x-model="gpFilter.nur_favoriten" @change="browse()" class="sr-only peer" /><span class="gap-1">@svg('heroicon-m-star', 'w-3.5 h-3.5') Favoriten</span></label>
                </div>
            </fieldset>
            <div class="flex flex-col min-w-0" data-browser-gps>
                <p class="mb-1.5 flex items-center gap-2 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">
                    <span class="inline-flex items-center h-[22px] px-2 rounded-full" style="{{ $typStyle('gp') }}">Grundprodukte</span>
                    <span class="tabular-nums" x-text="gpTotal"></span>
                </p>
                <div class="flex flex-col max-h-[28rem] overflow-y-auto rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" data-gp-liste>
                    <template x-for="ziel in gpListe" :key="'bg' + ziel.id">
                        <div class="flex items-center gap-1.5 px-2 py-1 border-b border-[var(--fa-line)] last:border-b-0 hover:bg-[var(--fa-hover)]">
                            <button type="button" @click="parke(ziel)" class="min-w-0 flex-1 text-left leading-snug text-[length:var(--fa-text-md)] text-[var(--fa-ink)] break-words" x-text="ziel.name" :title="'Übernehmen: ' + ziel.name"></button>
                            <span class="shrink-0 tabular-nums text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" x-text="ziel.preis_label ?? 'Preis fehlt'"></span>
                            <button type="button" x-show="ziel.id" @click="Livewire.dispatch('gp-modal.oeffnen', { id: ziel.id })"
                                    class="{{ $ib }}" title="Grundprodukt ansehen" aria-label="Grundprodukt ansehen">@svg('heroicon-o-cube', 'w-4 h-4')</button>
                            <button type="button" @click="parke(ziel)" data-parke
                                    class="{{ $ib }} text-[var(--fa-accent)] hover:text-[var(--fa-accent-hover)] hover:bg-[var(--fa-accent-soft)]"
                                    title="Übernehmen, dann Menge eingeben" aria-label="Übernehmen">@svg('heroicon-m-plus', 'w-4 h-4')</button>
                        </div>
                    </template>
                    {{-- Erst wenn wirklich gesucht wurde „keine Treffer" (2026-08-20). --}}
                    <p x-show="!browserGeladen" class="px-2 py-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Suchbegriff eingeben oder Filter wählen.</p>
                    <p x-show="browserGeladen && gpListe.length === 0" class="px-2 py-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Keine Grundprodukte gefunden.</p>
                    <p x-show="gpTotal > 200" x-cloak class="px-2 py-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" x-text="(gpTotal - 200) + ' weitere, bitte Suche oder Filter verfeinern'"></p>
                </div>
            </div>
        </aside>

        <div class="flex flex-col gap-3 min-w-0 lg:col-start-2 lg:row-start-1">
    <div class="sticky top-0 z-10 flex flex-col gap-2 p-3 rounded-[var(--fa-radius-surface)] bg-[var(--fa-surface)] border border-[var(--fa-line)] shadow-sm" data-add-zeile>
        {{-- Suche (ohne geparktes Ziel) --}}
        <div x-show="geparkt === null" class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-[14rem]">
                @svg('heroicon-m-magnifying-glass', 'pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--fa-ink-3)]')
                <input type="search" x-model="browseQ" @focus="browseOnce()" @input.debounce.300ms="sucheGetippt()"
                       placeholder="Zutat oder Basisrezept hinzufügen"
                       aria-label="Zutat oder Basisrezept suchen"
                       class="fa-control h-9 pl-8 text-[length:var(--fa-text-md)]" data-browse-suche />
            </div>
        </div>

        {{-- Park-Zeile: Ziel gewählt → Menge tippen, Enter fügt ein --}}
        <div x-show="geparkt !== null" x-cloak class="flex flex-wrap items-center gap-2" data-park-zeile>
            <span class="shrink-0 inline-flex items-center h-[22px] px-2 rounded-full text-[length:var(--fa-text-sm)] font-medium"
                  :style="geparkt?.type === 'gp' ? '{{ $typStyle('gp') }}' : '{{ $typStyle('basisrezept') }}'"
                  x-text="geparkt?.type === 'gp' ? 'Grundprodukt' : 'Basisrezept'"></span>
            <span class="min-w-[8rem] flex-1 truncate text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]" x-text="geparkt?.name?.replace('↳ ', '')" data-park-name></span>
            <input type="text" inputmode="decimal" x-model="neu.quantity" @keydown.enter.prevent="einfuegen()" placeholder="Menge" aria-label="Menge"
                   class="{{ $feld }} w-24 text-right tabular-nums" data-park-quantity />
            {{-- #9c: nur die für das geparkte Produkt hinterlegten/umrechenbaren Einheiten --}}
            <select x-model.number="neu.unit_vocab_id" aria-label="Einheit" class="{{ $feld }} fa-select w-24 pr-7" data-park-unit>
                <template x-for="e in erlaubteEinheiten(geparkt)" :key="e.id"><option :value="e.id" x-text="e.slug"></option></template>
            </select>
            <label class="inline-flex items-center gap-1.5 shrink-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">
                <input type="checkbox" x-model="neu.is_optional" class="w-4 h-4 accent-[var(--fa-accent)]" /> optional
            </label>
            <x-fa::button size="sm" icon="heroicon-m-plus" x-on:click="einfuegen()" title="Einfügen (Enter)" data-park-einfuegen>Zutat einfügen</x-fa::button>
            <button type="button" @click="verwerfen()" class="{{ $ib }}" title="Auswahl verwerfen" aria-label="Auswahl verwerfen" data-park-verwerfen>@svg('heroicon-m-x-mark', 'w-4 h-4')</button>
        </div>

        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Treffer mit Plus übernehmen, Menge eingeben, Enter fügt die Zutat ein. Die Einheit kommt vom Produkt.</p>

            <div x-show="aktiveFilter() > 0" x-cloak>
                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" x-on:click="filterZuruecksetzen()">Filter zurücksetzen</x-fa::button>
            </div>

    </div>
    {{-- ── Zutaten-Tabelle: scrollt seitlich in schmalen Spalten (Worker), statt abzuschneiden ── --}}
    <div class="overflow-x-auto rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)]">
        <table class="fa-table fa-table--compact" data-zutaten-tabelle>
            {{-- R5: BIS-Spalte raus (quantity_max bleibt in den Daten); drei EK-Sichten --}}
            @php($koepfe = ['Pos.' => null, 'Menge' => null, 'Einheit' => null, 'Zutat' => 'Klick auf den Namen öffnet Grundprodukt oder Basisrezept als Fenster über dem Editor']
                + ($vkKontext ? ['Rolle' => 'Rolle im Gericht: Aromaträger, Komponente, Beilage oder Garnitur'] : [])
                + ['Garverlust %' => 'Gewichtsverlust beim Garen', 'Anteil %' => 'Anteil am Gesamtgewicht des Rezepts (Summe 100 %). Optionale Zutaten und Zutaten ohne Gramm-Umrechnung zählen nicht. Bäckerprozent rechnet der Grammaturen-Rechner.', 'EK' => 'Einkaufspreis mit dem Hauptartikel. Damit rechnet das Rezept.', 'Günstigster' => 'Einkaufspreis mit dem günstigsten Artikel hinter dem Grundprodukt', 'Ø' => 'Einkaufspreis im Durchschnitt aller Artikel hinter dem Grundprodukt', '' => null])
            <thead><tr>
                @foreach($koepfe as $head => $tip)
                    <th class="{{ $head === 'Zutat' ? 'w-full' : 'w-px' }} {{ in_array($head, ['Garverlust %', 'Anteil %', 'EK', 'Günstigster', 'Ø'], true) ? 'text-right' : '' }}" @if($tip) title="{{ $tip }}" @endif>
                        @if($tip)<span class="cursor-help underline decoration-dotted decoration-[var(--fa-ink-3)] underline-offset-2">{{ $head }}</span>@else{{ $head }}@endif
                        @if($head === '')<span class="sr-only">Aktionen</span>@endif
                    </th>
                @endforeach
            </tr></thead>
            {{-- tbody je Zutat: Haupt-Zeile + aufklappbare Artikel-Zeile (HTML erlaubt mehrere tbody) --}}
            <template x-for="(zeile, i) in rows" :key="zeile._key">
                <tbody @dragover.prevent @dragenter.prevent @drop.prevent="dropAuf(i)"
                       :class="dragIdx === i ? 'opacity-40' : ''" data-editor-zeile>
                <tr class="transition-colors duration-500" :class="(zeile.is_optional ? 'opacity-60 ' : '') + (zeile._flash ? 'bg-[var(--fa-ok-soft)]' : '')">
                    <td class="whitespace-nowrap">
                        <div class="flex items-center gap-0.5">
                            {{-- R4: setData ist PFLICHT, sonst startet Safari den Drag gar nicht --}}
                            <span class="inline-flex cursor-grab active:cursor-grabbing text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] select-none" draggable="true"
                                  @dragstart="dragIdx = i; $event.dataTransfer.setData('text/plain', String(i)); $event.dataTransfer.effectAllowed = 'move'"
                                  @dragend="dragIdx = null" title="Ziehen zum Sortieren" data-drag-handle>@svg('heroicon-m-bars-2', 'w-4 h-4')</span>
                            {{-- R15: Hoch/Runter als zuverlässige Sortier-Alternative zu Ziehen --}}
                            <span class="inline-flex flex-col">
                                <button type="button" class="inline-flex text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] disabled:opacity-30"
                                        :disabled="i === 0" @click="verschiebe(i, -1)" title="Nach oben" aria-label="Nach oben" data-zeile-hoch>@svg('heroicon-m-chevron-up', 'w-3.5 h-3.5')</button>
                                <button type="button" class="inline-flex text-[var(--fa-ink-3)] hover:text-[var(--fa-ink)] disabled:opacity-30"
                                        :disabled="i === rows.length - 1" @click="verschiebe(i, 1)" title="Nach unten" aria-label="Nach unten" data-zeile-runter>@svg('heroicon-m-chevron-down', 'w-3.5 h-3.5')</button>
                            </span>
                            <span class="ml-0.5 tabular-nums text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" x-text="i + 1"></span>
                        </div>
                    </td>
                    <td><input type="text" inputmode="decimal" x-model="zeile.quantity" aria-label="Menge" class="{{ $feld }} w-[4.5rem] text-right tabular-nums" data-quantity /></td>
                    <td>
                        {{-- #9c: nur die für dieses Produkt hinterlegten/umrechenbaren Einheiten (Fallback: alle).
                             :selected ist PFLICHT (2026-09-04): x-model schiebt seinen Wert nur EINMAL in den Select,
                             bevor die x-for-Optionen existieren; ohne :selected stand jede Zeile optisch auf „g". --}}
                        <select x-model.number="zeile.unit_vocab_id" aria-label="Einheit" class="{{ $feld }} fa-select w-[4.5rem] pr-6">
                            <template x-for="e in erlaubteEinheiten(zeile)" :key="e.id"><option :value="e.id" :selected="e.id === zeile.unit_vocab_id" x-text="e.slug"></option></template>
                        </select>
                    </td>
                    <td class="min-w-[10rem]">
                        <div class="flex items-center gap-1 min-w-0">
                            {{-- R7: Klick öffnet das Ziel als Fenster über dem Editor (Stand bleibt) --}}
                            <template x-if="zeile.gp_id || zeile.referenced_recipe_id">
                                <button type="button"
                                        class="min-w-0 text-left leading-snug text-[var(--fa-accent)] hover:text-[var(--fa-accent-hover)] hover:underline"
                                        x-text="(zeile.ziel_name ?? (zeile.display_name ?? zeile.raw_text) ?? '').replace('↳ ', '')"
                                        :title="(zeile.gp_id ? 'Grundprodukt öffnen' : 'Basisrezept öffnen') + (herkunftText(zeile) ? ' · ' + herkunftText(zeile) : '')"
                                        @click="zeile.gp_id
                                            ? Livewire.dispatch('gp-modal.oeffnen', { id: zeile.gp_id })
                                            : Livewire.dispatch('recipe-modal.oeffnen', { id: zeile.referenced_recipe_id })"
                                        data-ziel-link></button>
                            </template>
                            <template x-if="!zeile.gp_id && !zeile.referenced_recipe_id">
                                <span class="min-w-0 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                    <span class="leading-snug text-[var(--fa-ink-2)]" x-text="zeile.ziel_name ?? (zeile.display_name ?? zeile.raw_text)"></span>
                                    <x-fa::signal tone="warn" title="Ohne Verknüpfung zählt die Zutat nicht in Preis und Deklaration">nicht verknüpft</x-fa::signal>
                                </span>
                            </template>
                            <button type="button" x-show="zeile.gp_id" class="{{ $ib }}" :class="zeile._peek ? 'text-[var(--fa-accent)]' : ''"
                                    title="Artikel hinter dem Grundprodukt zeigen" aria-label="Artikel zeigen"
                                    @click="peek(zeile)" data-gp-peek>@svg('heroicon-o-cube', 'w-4 h-4')</button>
                            {{-- Basisrezept als Fenster öffnen (kein Sprung) --}}
                            <button type="button" x-show="zeile.referenced_recipe_id" class="{{ $ib }}" title="Basisrezept ansehen" aria-label="Basisrezept ansehen"
                                    @click="Livewire.dispatch('recipe-modal.oeffnen', { id: zeile.referenced_recipe_id })" data-rez-oeffnen>@svg('heroicon-o-book-open', 'w-4 h-4')</button>
                        </div>
                    </td>
                    @if($vkKontext)
                        <td>
                            <select x-model="zeile.role" aria-label="Rolle" class="{{ $feld }} fa-select w-36 pr-7" data-role-select>
                                <option value="">Ohne Rolle</option>
                                @foreach(\Platform\FoodAlchemist\Services\SpeisenKlassenService::ROLLEN as $role)
                                    <option value="{{ $role }}">{{ $rollenText[$role] ?? ucfirst(str_replace('_', ' ', $role)) }}</option>
                                @endforeach
                            </select>
                        </td>
                    @endif
                    {{-- Garverlust: KI-Schätzung in Akzent markiert; Tippen macht den Wert manuell --}}
                    <td class="num">
                        <div class="inline-flex items-center gap-1">
                            <span x-show="zeile._garverlust_ki" x-cloak class="inline-flex text-[var(--fa-accent)]" title="Von der KI geschätzt">@svg('heroicon-m-sparkles', 'w-3.5 h-3.5')</span>
                            <input type="text" inputmode="decimal" x-model="zeile.cooking_loss_pct" placeholder="0" aria-label="Garverlust in Prozent"
                                   class="{{ $feld }} w-14 text-right tabular-nums"
                                   :class="zeile._garverlust_ki ? 'text-[var(--fa-accent)] border-[var(--fa-accent-line)]' : ''"
                                   :title="zeile._garverlust_ki ? 'Von der KI geschätzt. Tippen übernimmt den Wert als eigenen.' : ''"
                                   @input="zeile._garverlust_ki = false" data-garverlust-input />
                        </div>
                    </td>
                    {{-- Gewichtsanteil — % vom Gesamtgewicht (Summe 100 %), reine Anzeige --}}
                    <td class="num">
                        <span class="text-[var(--fa-ink-2)]" x-text="anteilPctFmt(zeile)"
                              :title="anteilPct(zeile) !== null ? 'Anteil am Gesamtgewicht' : (zeile.is_optional ? 'Optional, zählt nicht' : 'Keine Gramm-Umrechnung hinterlegt')"></span>
                    </td>
                    <td class="num" data-zeilen-ek-live>
                        {{-- F2 (#511a): unbepreiste Zutat zeigt „Preis fehlt" statt eines stillen Strichs --}}
                        <x-fa::badge tone="crit" x-show="preisFehlt(zeile)" x-cloak title="Kein Preis hinterlegt, diese Zutat fehlt im Wareneinsatz" data-ek-unpriced>Preis fehlt</x-fa::badge>
                        <span x-show="!preisFehlt(zeile)" x-text="ekAnzeige(zeile)"
                              :class="ohnePreis(zeile) || zeilenEk(zeile) === null ? 'text-[var(--fa-ink-3)]' : 'font-medium text-[var(--fa-ink)]'"></span>
                    </td>
                    <td class="num text-[var(--fa-ink-2)]" data-zeilen-ek-min>
                        <span x-text="ekAnzeige(zeile, 'ek_pro_g_min')"></span>
                    </td>
                    <td class="num text-[var(--fa-ink-2)]" data-zeilen-ek-avg>
                        <span x-text="ekAnzeige(zeile, 'ek_pro_g_avg')"></span>
                    </td>
                    <td class="whitespace-nowrap">
                        <div class="flex items-center justify-end gap-0.5">
                            <label class="inline-flex items-center gap-1 mr-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" title="Optional: zählt nicht in Ausbeute und Kosten">
                                <input type="checkbox" x-model="zeile.is_optional" class="w-3.5 h-3.5 accent-[var(--fa-accent)]" aria-label="Optional" /><span class="sr-only">optional</span>
                            </label>
                            {{-- Ersatz (Äquivalenz-Katalog): nur sichtbar, wenn hinterlegt; 1 Klick tauscht um, Menge × Faktor --}}
                            <button type="button" x-show="zeile.ersatz" x-cloak class="{{ $ib }} text-[var(--fa-ok)] hover:text-[var(--fa-ok)] hover:bg-[var(--fa-ok-soft)]"
                                    :title="ersatzTitel(zeile)" :aria-label="ersatzTitel(zeile)" @click="ersatzTausch(i)" data-zeile-ersatz>@svg('heroicon-m-arrow-path', 'w-4 h-4')</button>
                            <button type="button" class="{{ $ib }}" :class="tauschIdx === i ? 'text-[var(--fa-accent)] bg-[var(--fa-accent-soft)]' : ''" @click="starteTausch(i)"
                                    title="Zutat tauschen, Menge und Einheit bleiben" aria-label="Zutat tauschen" data-zeile-tausch>@svg('heroicon-m-arrows-right-left', 'w-4 h-4')</button>
                            <button type="button" class="{{ $ib }} hover:text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]" @click="rows.splice(i, 1)"
                                    title="Zutat entfernen" aria-label="Zutat entfernen" data-zeile-entfernen>@svg('heroicon-m-trash', 'w-4 h-4')</button>
                        </div>
                    </td>
                </tr>
                {{-- Artikel hinter dem Grundprodukt (D-5 §4.2.3), Stern = Hauptartikel --}}
                <tr x-show="zeile._peek" x-cloak>
                    <td colspan="{{ $spalten }}" class="bg-[var(--fa-ground)]">
                        <div class="flex flex-col gap-1.5 py-1" data-gp-peek-tabelle>
                            <p class="flex items-center gap-1.5 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">
                                @svg('heroicon-o-cube', 'w-4 h-4 text-[var(--fa-ink-3)]')
                                <span class="tabular-nums" x-text="(zeile._peek?.length ?? 0) + ' Artikel für'"></span><span class="font-semibold text-[var(--fa-ink)]" x-text="zeile.ziel_name"></span>
                            </p>
                            <div class="overflow-x-auto rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)]">
                                <table class="fa-table fa-table--compact">
                                    <thead><tr>
                                        <th class="w-px"><span class="sr-only">Hauptartikel</span></th><th>Lieferant</th><th>Art.-Nr.</th>
                                        <th>Bezeichnung</th><th>Marke</th><th>Gebinde</th>
                                        <th class="text-right">Preis</th><th class="text-right">Vergleichspreis</th><th class="text-right" title="Wie sicher der Artikel zum Grundprodukt passt">Treffer</th>
                                    </tr></thead>
                                    <tbody>
                                        <template x-for="(la, j) in (zeile._peek ?? [])" :key="j">
                                            <tr :aria-selected="la.lead ? 'true' : 'false'">
                                                <td><span x-show="la.lead" class="inline-flex text-[var(--fa-accent)]" title="Hauptartikel, damit rechnet das Rezept">@svg('heroicon-s-star', 'w-4 h-4')</span></td>
                                                <td class="whitespace-nowrap text-[var(--fa-ink-2)]" x-text="la.lieferant"></td>
                                                <td class="whitespace-nowrap tabular-nums text-[var(--fa-ink-2)]" x-text="la.artikelnr"></td>
                                                <td class="min-w-[12rem]" x-text="la.label"></td>
                                                <td class="text-[var(--fa-ink-2)]" x-text="la.marke ?? '–'"></td>
                                                <td class="whitespace-nowrap text-[var(--fa-ink-2)]" x-text="la.vpe ?? '–'"></td>
                                                <td class="num"><span x-show="la.price" x-text="la.price"></span><x-fa::badge tone="crit" x-show="!la.price">Preis fehlt</x-fa::badge></td>
                                                <td class="num text-[var(--fa-ink-2)]" x-text="la.vergleichspreis ?? '–'"></td>
                                                <td class="num text-[var(--fa-ink-2)]" x-text="la.match ?? '–'"></td>
                                            </tr>
                                        </template>
                                        <tr x-show="(zeile._peek ?? []).length === 0">
                                            <td colspan="9" class="text-[var(--fa-ink-3)]">Keine Artikel mit diesem Grundprodukt verknüpft.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </td>
                </tr>
                </tbody>
            </template>
            <tbody x-show="rows.length === 0" x-cloak>
                <tr><td colspan="{{ $spalten }}">
                    <x-fa::empty compact icon="heroicon-o-queue-list" title="Noch keine Zutaten">Oben suchen und mit Plus übernehmen.</x-fa::empty>
                </td></tr>
            </tbody>
            <tfoot>
                <tr class="border-t border-[var(--fa-line-strong)]">
                    <td colspan="{{ $vkKontext ? 7 : 6 }}" class="text-right text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Summe</td>
                    <td class="num font-semibold text-[var(--fa-ink)]" data-summe-live>
                        <span x-text="summeAnzeige()"></span>
                    </td>
                    <td class="num text-[var(--fa-ink-2)]" data-summe-min>
                        <span x-text="summeAnzeige('ek_pro_g_min')"></span>
                    </td>
                    <td class="num text-[var(--fa-ink-2)]" data-summe-avg>
                        <span x-text="summeAnzeige('ek_pro_g_avg')"></span>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- Unter der Tabelle: Ausbeute + Preis-Hinweis — bricht um, wird in schmalen Spalten nie abgeschnitten --}}
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <p class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]" data-yield-live
           title="Vorläufig. Putzverluste aus den Grundprodukten und Umrechnungen rechnet das Speichern genau nach.">
            Ausbeute ca. <span class="font-semibold tabular-nums text-[var(--fa-ink)]" x-text="yieldLive()"></span>
            @svg('heroicon-m-information-circle', 'w-4 h-4 text-[var(--fa-ink-3)]')
        </p>
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Werte rechnen beim Tippen mit, verbindlich nach dem Speichern.</p>
    </div>
    {{-- F2 (#511a): EK-Vollständigkeit, sobald wert-relevante Zutaten ohne Preis dabei sind --}}
    <x-fa::notice tone="warn" x-show="bepreistInfo().total > bepreistInfo().priced" x-cloak data-ek-unvollstaendig>
        Nur <span class="font-semibold tabular-nums" x-text="bepreistInfo().priced"></span> von <span class="font-semibold tabular-nums" x-text="bepreistInfo().total"></span> Zutaten haben einen Preis. Der Wareneinsatz ist unvollständig, bitte Preis am Grundprodukt oder Artikel ergänzen.
    </x-fa::notice>

    {{-- Konsolidierung 2026-08 (#1b): kein eigener Speichern-Knopf im Kern. Der Zutaten-Save wird vom
         JEWEILIGEN Host adressiert angestoßen (`zutaten-speichern`, MVP-046): Rezept-/Gericht-Editor über
         ihren Haupt-„Speichern", das Planungs-Cockpit über den Knopf in step-zeile, das Standalone-Modal
         über seinen Kopf. So gibt es pro Editor nur EINEN Speichern-Weg. --}}
        </div>

        <aside class="fa-surface p-3 flex flex-col gap-3 min-w-0 lg:col-start-1 lg:row-start-1 wide:col-start-3 lg:sticky lg:top-0" :class="einfuegenAus === 'rez' ? '' : 'max-wide:hidden'" aria-label="Basisrezepte einfügen">
            {{-- Laptop (< 1.800 px): EINE Einfüge-Spalte mit Umschalter; ab 1.800 px stehen beide Spalten nebeneinander --}}
            <div role="group" aria-label="Einfügen aus" class="flex p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)] wide:hidden">
                <button type="button" @click="einfuegenAus = 'gp'" :aria-pressed="einfuegenAus === 'gp'"
                        class="flex-1 h-8 px-2 rounded-[5px] text-[length:var(--fa-text-sm)] font-medium transition-colors"
                        :class="einfuegenAus === 'gp' ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]'">Grundprodukte <span class="tabular-nums text-[var(--fa-ink-3)]" x-text="gpTotal"></span></button>
                <button type="button" @click="einfuegenAus = 'rez'" :aria-pressed="einfuegenAus === 'rez'"
                        class="flex-1 h-8 px-2 rounded-[5px] text-[length:var(--fa-text-sm)] font-medium transition-colors"
                        :class="einfuegenAus === 'rez' ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]'">Basisrezepte <span class="tabular-nums text-[var(--fa-ink-3)]" x-text="rezTotal"></span></button>
            </div>
<fieldset class="flex flex-col gap-2 min-w-0">
                <legend class="sr-only">Basisrezepte filtern</legend>
                <div class="grid grid-cols-1 gap-2">
                    <select x-model="rezFilter.hg" @change="rezFilter.kat = ''; browse()" aria-label="Hauptgruppe" class="{{ $feld }} fa-select pr-8" data-rez-filter-hg>
                        <option value="">Alle Hauptgruppen</option>
                        <template x-for="h in (vokabular?.hauptgruppen ?? [])" :key="h.id">
                            <option :value="h.id" x-text="h.label"></option>
                        </template>
                    </select>
                    <select x-model="rezFilter.kat" @change="browse()" aria-label="Kategorie" class="{{ $feld }} fa-select pr-8" data-rez-filter-kat>
                        <option value="">Alle Kategorien</option>
                        <template x-for="k in kategorienFuerHg()" :key="k.id">
                            <option :value="k.id" x-text="k.label"></option>
                        </template>
                    </select>
                    <select x-model="rezFilter.level" @change="browse()" aria-label="Niveau" class="{{ $feld }} fa-select pr-8" data-rez-filter-niveau>
                        <option value="">Jedes Niveau</option>
                        <template x-for="n in (vokabular?.niveaus ?? [])" :key="n.slug">
                            <option :value="n.slug" x-text="n.label"></option>
                        </template>
                    </select>
                </div>
            </fieldset>
            <div class="flex flex-col min-w-0" data-browser-rezepte>
                <p class="mb-1.5 flex items-center gap-2 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">
                    <span class="inline-flex items-center h-[22px] px-2 rounded-full" style="{{ $typStyle('basisrezept') }}">Basisrezepte</span>
                    <span class="tabular-nums" x-text="rezTotal"></span>
                </p>
                <div class="flex flex-col max-h-[28rem] overflow-y-auto rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" data-rez-liste>
                    <template x-for="ziel in rezListe" :key="'br' + ziel.id">
                        <div class="flex items-center gap-1.5 px-2 py-1 border-b border-[var(--fa-line)] last:border-b-0 hover:bg-[var(--fa-hover)]">
                            <button type="button" @click="parke(ziel)" class="min-w-0 flex-1 text-left leading-snug text-[length:var(--fa-text-md)] text-[var(--fa-ink)] break-words" :title="'Übernehmen: ' + ziel.name.replace('↳ ', '')">
                                <span x-text="ziel.name.replace('↳ ', '')"></span>
                                <span x-show="(ziel.niveaus ?? []).length > 0" class="ml-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" x-text="niveauText(ziel)"></span>
                            </button>
                            <span class="shrink-0 tabular-nums text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" x-text="ziel.preis_label ?? 'Preis fehlt'"></span>
                            <button type="button" x-show="ziel.id" @click="Livewire.dispatch('recipe-modal.oeffnen', { id: ziel.id })"
                                    class="{{ $ib }}" title="Basisrezept ansehen" aria-label="Basisrezept ansehen">@svg('heroicon-o-book-open', 'w-4 h-4')</button>
                            <button type="button" @click="parke(ziel)" data-parke
                                    class="{{ $ib }} text-[var(--fa-accent)] hover:text-[var(--fa-accent-hover)] hover:bg-[var(--fa-accent-soft)]"
                                    title="Übernehmen, dann Menge eingeben" aria-label="Übernehmen">@svg('heroicon-m-plus', 'w-4 h-4')</button>
                        </div>
                    </template>
                    <p x-show="!browserGeladen" class="px-2 py-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Suchbegriff eingeben oder Filter wählen.</p>
                    <p x-show="browserGeladen && rezListe.length === 0" class="px-2 py-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Keine Basisrezepte gefunden.</p>
                    <p x-show="rezTotal > 200" x-cloak class="px-2 py-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" x-text="(rezTotal - 200) + ' weitere, bitte Suche oder Filter verfeinern'"></p>
                </div>
            </div>
        </aside>
    </div>
</div>
