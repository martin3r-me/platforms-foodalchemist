{{-- Spec 52 (Grundsatz E): Wissens-Steuerung sichtbar + einstellbar — vorher hatte nur die ALTE Bindungs-Ebene eine UI.
     Häufigste Aufgabe: nachsehen, welcher Arbeitsschritt welches Wissen bekommt, und dort nachsteuern.
     Darum oben der Überblick + die Liste der Arbeitsschritte, darunter die globalen Regeln (Leitplanken, Arten, Kategorien).
     Technische Schlüssel bleiben klein sichtbar (für Abgleich mit Protokollen), führend ist der lesbare Name. --}}
@php
    use Platform\FoodAlchemist\Livewire\Settings\Wissenssteuerung as WS;

    $menuPunkt = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $menuGefahr = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-[length:var(--fa-text-md)] text-[var(--fa-crit)] hover:bg-[var(--fa-crit-soft)]';
    $schluessel = 'font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $kleinTitel = 'text-[length:var(--fa-text-sm)] font-semibold text-[var(--fa-ink-2)]';
    $zahl = fn ($n) => number_format((int) $n, 0, ',', '.');
    $katName = fn ($slug) => $kategorieLabels[$slug] ?? WS::lesbar($slug);
    $routingModi = collect(['always', 'discovery', 'grounding', 'none'])->mapWithKeys(fn ($m) => [$m => WS::modusLabel($m)])->all();
@endphp

<div class="flex flex-col gap-6" data-settings-wissenssteuerung>
    {{-- Spec 65: „Bearbeiten" sperrt den Bereich für das Team, „Fertig" gibt frei --}}
    @php $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true); @endphp
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    @if($fehler !== null)
        <x-fa::notice tone="crit" data-ws-fehler>{{ $fehler }}</x-fa::notice>
    @endif
    @if($hinweis !== null)
        <x-fa::notice tone="ok" data-ws-hinweis>{{ $hinweis }}</x-fa::notice>
    @endif

    {{-- Vorschläge für alle „Arbeitsschritt"-Felder: Eingabe bleibt frei, Tippen schlägt vor. --}}
    <datalist id="ws-schritte">
        @foreach($schrittVorschlaege as $k)<option value="{{ $k }}">{{ WS::schrittLabel($k) }}</option>@endforeach
    </datalist>

    {{-- ── Überblick ── --}}
    <div class="flex flex-col gap-2" data-ws-zaehler>
        <x-fa::kpis :items="[
            ['label' => 'Versorgt', 'value' => $zahl($bericht['gesteuert']), 'tone' => 'ok'],
            ['label' => 'Bewusst ohne Wissen', 'value' => $zahl($bericht['bewusst_leer'])],
            ['label' => 'Ohne Wissen', 'value' => $zahl($bericht['ungesteuert']), 'tone' => $bericht['ungesteuert'] > 0 ? 'warn' : null],
            ['label' => 'Fehlerhaft', 'value' => $zahl($bericht['fehlerhaft']), 'tone' => $bericht['fehlerhaft'] > 0 ? 'crit' : null],
        ]" />
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[90ch]">
            <span class="font-medium text-[var(--fa-ink-2)]">Ohne Wissen</span> heißt: aus der Wissensbasis erreicht diesen Arbeitsschritt nichts.
            Regeln im KI-Auftrag selbst gelten trotzdem.
            <span class="font-medium text-[var(--fa-ink-2)]">Fehlerhaft</span> heißt: es ist Wissen hinterlegt, es kommt aber nicht an.
        </p>
    </div>

    {{-- ── Arbeitsschritte ── --}}
    <x-fa::section title="Arbeitsschritte" icon="heroicon-o-cpu-chip" :meta="count($profile) . ' von ' . $bericht['keys']"
                   description="Zeile anklicken zeigt, welches Wissen dieser Schritt bekommt. Der Wissensumfang je Erstellung begrenzt, wie viel Text die KI pro Aufruf mitbekommt.">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-3">
                <x-fa::select wire:model.live="bereich" size="sm" class="w-48" aria-label="Bereich" data-ws-bereich>
                    <option value="">Alle Bereiche ({{ $bericht['keys'] }})</option>
                    @foreach($bereiche as $b)<option value="{{ $b }}">{{ WS::BEREICH_LABEL[$b] ?? WS::lesbar($b) }}</option>@endforeach
                </x-fa::select>
                <label class="flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] cursor-pointer whitespace-nowrap">
                    <input type="checkbox" wire:model.live="nurBefunde" class="w-4 h-4 rounded accent-[var(--fa-accent)]" data-ws-nur-befunde /> Nur mit Befund
                </label>
            </div>
        </x-slot:actions>

        <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
        <div class="overflow-x-auto -mx-4">
            <table class="fa-table min-w-[760px]" data-ws-profile>
                <thead>
                    <tr>
                        <th>Arbeitsschritt</th>
                        <th>Zustand</th>
                        <th class="num">Verbindlich</th>
                        <th class="num">Gesucht</th>
                        <th class="num">Wissensumfang je Erstellung</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($profile as $p)
                        @php
                            [$zustandText, $zustandTon] = WS::ZUSTAND_LABEL[$p['zustand']] ?? [WS::lesbar($p['zustand']), 'neutral'];
                            $istOffen = $offen === $p['prompt_key'];
                            $nPflicht = count($p['pflicht']);
                            $nSuche = count(array_filter($p['routing'], fn ($r) => $r['mode'] !== 'none'));
                        @endphp
                        <tr class="cursor-pointer" wire:key="p-{{ $p['prompt_key'] }}" wire:click="toggleOffen('{{ $p['prompt_key'] }}')" data-ws-zustand="{{ $p['zustand'] }}" aria-expanded="{{ $istOffen ? 'true' : 'false' }}">
                            <td title="Stand {{ $p['fingerabdruck'] }}">
                                <div class="flex items-start gap-2">
                                    @svg($istOffen ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right', 'w-4 h-4 mt-0.5 shrink-0 text-[var(--fa-ink-3)]')
                                    <div class="min-w-0">
                                        <span class="block font-medium text-[var(--fa-ink)]">{{ WS::schrittLabel($p['prompt_key']) }}</span>
                                        <span class="block {{ $schluessel }}">
                                            {{ $p['prompt_key'] }}
                                            @if($p['alt_schluessel'])
                                                <span class="text-[var(--fa-warn)]" title="Dieser Schritt holt sein Wissen unter einem älteren Schlüssel. Ein Aufruf, zwei Namen.">· sucht als {{ $p['routing_key'] }}</span>
                                            @endif
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td><x-fa::badge :tone="$zustandTon">{{ $zustandText }}</x-fa::badge></td>
                            <td class="num {{ $nPflicht ? '' : 'text-[var(--fa-ink-3)]' }}">{{ $nPflicht ?: '–' }}</td>
                            <td class="num {{ $nSuche ? '' : 'text-[var(--fa-ink-3)]' }}">{{ $nSuche ?: '–' }}</td>
                            {{-- Das Budget war hier immer nur ABLESBAR — eine Diagnose ohne Therapie.
                                 Jetzt ist die rechte Zahl der Knopf: er ist der Hebel fuer Kosten gegen
                                 Qualitaet, und er gehoert dem Betreiber, nicht dem Release-Zyklus.
                                 x-on:click.stop: Klicks hier klappen die Zeile nicht mehr mit auf. --}}
                            <td class="num" x-data="{}" x-on:click.stop>
                                @if($budgetKey === $p['prompt_key'])
                                    <div class="flex items-center justify-end gap-1.5">
                                        <span class="text-[var(--fa-ink-3)]" title="davon verbindlich">{{ $zahl($p['pflicht_zeichen']) }} /</span>
                                        <x-fa::input type="number" min="1" step="100" wire:model="budgetWert" wire:keydown.enter="saveBudget"
                                                     size="sm" numeric class="w-28" aria-label="Wissensumfang in Zeichen" />
                                        <x-fa::button size="sm" variant="primary" wire:click="saveBudget">Speichern</x-fa::button>
                                        <x-fa::button size="sm" variant="ghost" wire:click="resetBudget('{{ $p['prompt_key'] }}')" title="Zurück auf den ausgelieferten Standard">Standard</x-fa::button>
                                        <x-fa::icon-button icon="heroicon-m-x-mark" label="Abbrechen" size="sm" wire:click="$set('budgetKey', null)" />
                                    </div>
                                @else
                                    <button type="button" wire:click="editBudget('{{ $p['prompt_key'] }}')"
                                            class="inline-flex items-center gap-1.5 rounded-[var(--fa-radius-control)] px-1.5 py-0.5 hover:bg-[var(--fa-hover)]"
                                            title="Wissensumfang ändern (Zeichen: verbindlich / gesamt)">
                                        <span class="text-[var(--fa-ink-3)]">{{ $zahl($p['pflicht_zeichen']) }} /</span>
                                        <span class="font-medium text-[var(--fa-ink)]">{{ $zahl($p['budget_total']) }}</span>
                                        @if(($eingestellteBudgets[$p['prompt_key']] ?? null) !== null)
                                            <x-fa::badge tone="accent" title="Weicht vom ausgelieferten Standard ab">Angepasst</x-fa::badge>
                                        @endif
                                        @svg('heroicon-m-pencil', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]')
                                    </button>
                                @endif
                            </td>
                        </tr>

                        @if($p['befunde'] !== [])
                            <tr wire:key="b-{{ $p['prompt_key'] }}">
                                <td colspan="5" class="pt-0">
                                    <ul class="flex flex-col gap-1 pl-6">
                                        @foreach($p['befunde'] as $b)
                                            <li class="text-[length:var(--fa-text-sm)]" title="Befund {{ $b['code'] }}">
                                                <x-fa::signal :tone="$b['schwere'] === 'blockiert' ? 'crit' : 'warn'">{{ $b['schwere'] === 'blockiert' ? 'Blockiert' : 'Hinweis' }}</x-fa::signal>
                                                <span class="text-[var(--fa-ink-2)]">
                                                    @if($b['slug'] !== null)<span class="font-mono text-[var(--fa-ink)]">{{ $b['slug'] }}</span>: @endif{{ $b['text'] }}
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </td>
                            </tr>
                        @endif

                        @if($istOffen)
                            <tr wire:key="d-{{ $p['prompt_key'] }}">
                                <td colspan="5" class="pt-0">
                                    <div class="ml-6 rounded-[var(--fa-radius-surface)] bg-[var(--fa-ground)] border border-[var(--fa-line)] p-3 flex flex-col gap-4">
                                        {{--
                                            KANON-EDITOR (Spec 52 · Paket 3). Bis hier war diese Seite nur
                                            ein Bericht: sie zeigte, was verbindlich ist, und zum Ändern
                                            musste man nach MCP. Seit F2 ist der Kanon die EINZIGE Quelle
                                            für „muss in diesen Prompt" — ohne Editor hätte die
                                            abgeschaffte Bindungs-Ebene weiterhin die einzige UI gehabt.
                                        --}}
                                        <div class="flex flex-col gap-1.5">
                                            <div class="flex items-center justify-between gap-3">
                                                <div>
                                                    <p class="{{ $kleinTitel }}">Verbindliches Wissen</p>
                                                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Kommt immer vollständig mit und wird nie gekürzt.</p>
                                                </div>
                                                @if($darfSchreiben)
                                                    <x-fa::button size="sm" :variant="$kanonKey === $p['prompt_key'] ? 'ghost' : 'secondary'"
                                                                  :icon="$kanonKey === $p['prompt_key'] ? 'heroicon-m-check' : 'heroicon-m-pencil-square'"
                                                                  wire:click="kanonEdit('{{ $p['prompt_key'] }}')">
                                                        {{ $kanonKey === $p['prompt_key'] ? 'Bearbeiten beenden' : 'Verbindliches Wissen bearbeiten' }}
                                                    </x-fa::button>
                                                @endif
                                            </div>
                                            @forelse($p['pflicht'] as $d)
                                                <div class="flex items-center gap-2 text-[length:var(--fa-text-md)]" wire:key="kp-{{ $p['prompt_key'] }}-{{ $d['slug'] }}">
                                                    @svg('heroicon-m-document-text', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                                                    <span class="font-mono text-[var(--fa-ink)] break-all">{{ $d['slug'] }}</span>
                                                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] whitespace-nowrap">Version {{ $d['version'] }} · {{ $zahl($d['zeichen']) }} Zeichen</span>
                                                    @if($darfSchreiben && $kanonKey === $p['prompt_key'])
                                                        <x-fa::icon-button icon="heroicon-m-x-mark" label="Nicht mehr verbindlich" size="sm" tone="danger"
                                                                           wire:click="kanonRemove('{{ $p['prompt_key'] }}', '{{ $d['slug'] }}')" />
                                                    @endif
                                                </div>
                                            @empty
                                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Kein verbindliches Wissen.</p>
                                            @endforelse
                                        </div>

                                        @if($p['wenn_platz'] !== [])
                                            <div class="flex flex-col gap-1.5">
                                                <div>
                                                    <p class="{{ $kleinTitel }}">Wenn Platz ist</p>
                                                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Fällt bei Platzmangel als Ganzes weg, wird nie angeschnitten.</p>
                                                </div>
                                                @foreach($p['wenn_platz'] as $d)
                                                    <div class="flex items-center gap-2 text-[length:var(--fa-text-md)]" wire:key="kw-{{ $p['prompt_key'] }}-{{ $d['slug'] }}">
                                                        @svg('heroicon-m-document', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                                                        <span class="font-mono text-[var(--fa-ink)] break-all">{{ $d['slug'] }}</span>
                                                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] whitespace-nowrap">Version {{ $d['version'] }}</span>
                                                        @if($darfSchreiben && $kanonKey === $p['prompt_key'])
                                                            <x-fa::icon-button icon="heroicon-m-x-mark" label="Nicht mehr verbindlich" size="sm" tone="danger"
                                                                               wire:click="kanonRemove('{{ $p['prompt_key'] }}', '{{ $d['slug'] }}')" />
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        @if($darfSchreiben && $kanonKey === $p['prompt_key'])
                                            <div class="fa-surface p-3 flex flex-col gap-3" data-kanon-editor>
                                                <p class="{{ $kleinTitel }}">Dossier verbindlich machen</p>
                                                <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,14rem),1fr))] items-start">
                                                    <x-fa::field label="Dossier suchen" for="kanon-suche-{{ $loop->index }}" hint="Name oder Titel, ab 2 Zeichen.">
                                                        <x-fa::input id="kanon-suche-{{ $loop->index }}" type="search" wire:model.live.debounce.300ms="kanonSuche" placeholder="z. B. regelwerk" data-kanon-suche />
                                                    </x-fa::field>
                                                    <x-fa::choice name="kanonForm.mode" :live="false" label="Art der Aufnahme"
                                                                  :options="['pflicht' => 'Verbindlich', 'wenn_platz' => 'Wenn Platz ist']" />
                                                    <x-fa::field label="Reihenfolge" for="kanon-ord-{{ $loop->index }}" optional>
                                                        <x-fa::input id="kanon-ord-{{ $loop->index }}" type="number" wire:model="kanonForm.ord" numeric class="max-w-[8rem]" />
                                                    </x-fa::field>
                                                </div>

                                                @if($kanonTreffer->isNotEmpty())
                                                    <div class="flex flex-col rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] divide-y divide-[var(--fa-line)] max-h-72 overflow-y-auto" role="listbox" aria-label="Gefundene Dossiers">
                                                        @foreach($kanonTreffer as $t)
                                                            <button type="button" wire:key="kt-{{ $t->slug }}" wire:click="$set('kanonForm.slug', '{{ $t->slug }}')" role="option"
                                                                    aria-selected="{{ $kanonForm['slug'] === $t->slug ? 'true' : 'false' }}"
                                                                    class="flex flex-wrap items-center gap-x-2 gap-y-0.5 w-full text-left px-2.5 py-1.5 text-[length:var(--fa-text-md)] hover:bg-[var(--fa-hover)] {{ $kanonForm['slug'] === $t->slug ? 'bg-[var(--fa-accent-soft)]' : '' }}">
                                                                <span class="font-mono text-[var(--fa-ink)] break-all">{{ $t->slug }}</span>
                                                                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $katName($t->category) }} · {{ $zahl($t->char_count) }} Zeichen</span>
                                                                @unless($t->active)<x-fa::badge tone="warn">inaktiv</x-fa::badge>@endunless
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                @endif

                                                <div class="flex flex-wrap items-center justify-between gap-3">
                                                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] min-w-0">
                                                        @if($kanonForm['slug'] !== '')
                                                            Gewählt: <span class="font-mono text-[var(--fa-ink)]">{{ $kanonForm['slug'] }}</span>
                                                        @else
                                                            Noch kein Dossier gewählt.
                                                        @endif
                                                    </p>
                                                    <x-fa::button variant="primary" size="sm" icon="heroicon-m-plus" wire:click="kanonAdd" data-kanon-add
                                                                  :disabled="$kanonForm['slug'] === ''">Dossier aufnehmen</x-fa::button>
                                                </div>

                                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                                                    <strong class="font-medium text-[var(--fa-ink-2)]">Verbindlich</strong> kommt immer vollständig. Ist der Wissensumfang dafür zu klein, wird der Aufruf gestoppt.
                                                    <strong class="font-medium text-[var(--fa-ink-2)]">Wenn Platz ist</strong> fällt bei Platzmangel als ganzes Dossier weg, nie als Anschnitt.
                                                    Ein inaktives Dossier lässt sich vorbereitend aufnehmen, liefert aber erst nach dem Aktivieren.
                                                </p>
                                            </div>
                                        @endif

                                        <div class="flex flex-col gap-1.5">
                                            <div>
                                                <p class="{{ $kleinTitel }}">Gesuchtes Wissen</p>
                                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                                                    Sucht als <span class="font-mono">{{ $p['routing_key'] }}</span>, gemeinsamer Wissensumfang {{ $zahl($p['budget_total']) }} Zeichen.
                                                </p>
                                            </div>
                                            @forelse($p['routing'] as $r)
                                                <div class="flex flex-wrap items-center gap-2 text-[length:var(--fa-text-md)]">
                                                    <span class="text-[var(--fa-ink)]">{{ isset($r['art']) && $r['art'] ? (WS::ART_LABEL[$r['art']] ?? WS::lesbar($r['art'])) : $katName($r['category']) }}</span>
                                                    @svg('heroicon-m-arrow-long-right', 'w-4 h-4 text-[var(--fa-ink-3)]')
                                                    <x-fa::badge :tone="$r['mode'] === 'none' ? 'neutral' : 'info'">{{ WS::modusLabel($r['mode']) }}</x-fa::badge>
                                                    @if($r['max_docs'])<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">höchstens {{ $r['max_docs'] }} {{ (int) $r['max_docs'] === 1 ? 'Quelle' : 'Quellen' }}</span>@endif
                                                </div>
                                            @empty
                                                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Keine Suche eingestellt.</p>
                                            @endforelse
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="5"><x-fa::empty icon="heroicon-o-funnel" title="Kein Arbeitsschritt passt zum Filter" compact>Bereich auf „Alle Bereiche“ stellen oder „Nur mit Befund“ abwählen.</x-fa::empty></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        </fieldset>
    </x-fa::section>

    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
    {{-- ── Achsen-Bindungen (Spec 52/H2) ── --}}
    @php
        $achsenNamen = collect(array_keys($achsen))->merge(array_keys($achsenConfig))->unique()->sort()->values();
    @endphp
    <x-fa::section title="Wissen nach Leitplanken" icon="heroicon-o-adjustments-horizontal" data-ws-achsen
                   description="Trägt eine Leitplanke diesen Wert (zum Beispiel Anlass „Dinner“), kommt das zugeordnete Dossier immer mit, unabhängig von jeder Suche. Gepflegt wird die Zuordnung derzeit über die Schnittstelle, hier ist sie sichtbar.">
        @if($achsenNamen->isEmpty())
            <x-fa::empty icon="heroicon-o-adjustments-horizontal" title="Keine Leitplanke mit Wissen verknüpft" compact />
        @else
            <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,22rem),1fr))]">
                @foreach($achsenNamen as $achse)
                    @php
                        $werteGepflegt = $achsen[$achse] ?? [];
                        $werteConfig = $achsenConfig[$achse] ?? [];
                    @endphp
                    <div class="rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] p-3 flex flex-col gap-2 min-w-0" wire:key="achse-{{ $achse }}">
                        <p class="font-medium text-[var(--fa-ink)]">{{ WS::ACHSE_LABEL[$achse] ?? WS::lesbar($achse) }} <span class="{{ $schluessel }}">{{ $achse }}</span></p>
                        <ul class="flex flex-col gap-1.5">
                            @foreach(collect(array_keys($werteGepflegt))->merge(array_keys($werteConfig))->unique()->sort() as $wert)
                                @php
                                    $gepflegt = $werteGepflegt[$wert] ?? null;
                                @endphp
                                <li class="flex flex-col gap-0.5 text-[length:var(--fa-text-md)] min-w-0">
                                    <span class="flex flex-wrap items-center gap-1.5">
                                        <span class="text-[var(--fa-ink)]">{{ WS::lesbar($wert) }}</span>
                                        @if($gepflegt !== null)
                                            <x-fa::badge tone="ok">Gepflegt</x-fa::badge>
                                        @elseif(($werteConfig[$wert] ?? []) !== [])
                                            <x-fa::badge>Standard</x-fa::badge>
                                        @else
                                            <x-fa::signal tone="warn">Kein Dossier, dieser Wert bringt kein Wissen mit</x-fa::signal>
                                        @endif
                                    </span>
                                    @if($gepflegt !== null)
                                        <span class="font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] break-all">{{ implode(', ', $gepflegt) }}</span>
                                    @elseif(($werteConfig[$wert] ?? []) !== [])
                                        <span class="font-mono text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] break-all">{{ implode(', ', $werteConfig[$wert]) }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif

        @if($datenwerkOhneAchse !== [])
            <x-fa::notice tone="warn" title="Datenwerke ohne Leitplanke">
                Diese Dossiers sind als Datenwerk eingeordnet, hängen aber an keiner Leitplanke. Sie landen deshalb in der Suche, statt gezielt aufgelöst zu werden:
                <span class="block mt-1 font-mono text-[length:var(--fa-text-sm)] break-all">{{ implode(', ', $datenwerkOhneAchse) }}</span>
            </x-fa::notice>
        @endif
    </x-fa::section>

    {{-- ── Arten ── --}}
    @php
        $artRoutings = $routings->filter(fn ($r) => ! empty($r->art));
    @endphp
    <x-fa::section title="Wissen nach Art" icon="heroicon-o-squares-2x2" data-ws-arten
                   description="Welche Art von Wissen darf ein Arbeitsschritt benutzen? Regeln kommen aus dem verbindlichen Wissen, Datenwerke werden über Leitplanken aufgelöst, Fachwissen und Referenzen werden gesucht. Sobald ein Schritt eine Art-Steuerung hat, gilt die Kategorie-Steuerung nur noch für Dossiers ohne Art.">
        @if($artRoutings->isEmpty())
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Noch keine Art-Steuerung gesetzt.</p>
        @else
            <ul class="flex flex-col divide-y divide-[var(--fa-line)] border-y border-[var(--fa-line)]">
                @foreach($artRoutings as $r)
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2" wire:key="art-{{ $r->id }}">
                        <div class="min-w-0 flex-1">
                            <span class="block font-medium text-[var(--fa-ink)]">{{ WS::schrittLabel($r->feature) }}</span>
                            <span class="block {{ $schluessel }}">{{ $r->feature }}</span>
                        </div>
                        <x-fa::badge>{{ WS::ART_LABEL[$r->art] ?? WS::lesbar($r->art) }}</x-fa::badge>
                        <x-fa::badge :tone="$r->mode === 'none' ? 'neutral' : 'info'">{{ WS::modusLabel($r->mode) }}</x-fa::badge>
                        @if($r->mode === 'discovery')
                            <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">{{ $r->max_docs ? 'höchstens ' . $r->max_docs . ' Suchtreffer' : 'Standardzahl an Suchtreffern' }}</span>
                        @endif
                        @if($darfSchreiben)
                            <div class="flex items-center gap-1">
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-pencil-square" wire:click="editArt({{ $r->id }})">Bearbeiten</x-fa::button>
                                <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                    <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" size="sm" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                    <div class="hidden w-56 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                        <button type="button" role="menuitem" x-on:click="offen = false" wire:click="delete({{ $r->id }})"
                                                wire:confirm="Art-Steuerung entfernen? Ohne verbleibende Art-Steuerung gilt für diesen Schritt wieder die Kategorie-Steuerung."
                                                class="{{ $menuGefahr }}">
                                            @svg('heroicon-m-trash', 'w-4 h-4')
                                            Art-Steuerung entfernen
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if($darfSchreiben)
            <div class="flex flex-col gap-3 pt-1">
                <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,16rem),1fr))] items-start">
                    <x-fa::field label="Arbeitsschritt" for="ws-art-feature" hint="Tippen schlägt bekannte Schritte vor.">
                        <x-fa::input id="ws-art-feature" wire:model="artForm.feature" list="ws-schritte" placeholder="z. B. recipe.generator" />
                    </x-fa::field>
                    <x-fa::choice name="artForm.art" :live="false" label="Art" idPrefix="ws-art"
                                  :options="['fachwissen' => 'Fachwissen', 'referenz' => 'Referenz', 'datenwerk' => 'Datenwerk']" />
                    <x-fa::choice name="artForm.mode" :live="false" label="Verwendung" idPrefix="ws-art"
                                  :options="['discovery' => 'Passendes suchen', 'resolve' => 'Werte auflösen', 'none' => 'Bewusst nicht']" />
                    <x-fa::field label="Höchstens Suchtreffer" for="ws-art-max">
                        <x-fa::input id="ws-art-max" wire:model="artForm.max_docs" type="number" min="1" numeric class="max-w-[8rem]" />
                    </x-fa::field>
                    <x-fa::field label="Alter Einzeldeckel" for="ws-art-deckel" hint="Ohne Wirkung, nur noch zur Ansicht.">
                        <x-fa::input id="ws-art-deckel" disabled wire:model="artForm.max_chars_per_doc" type="number" min="1" numeric class="max-w-[8rem]" />
                    </x-fa::field>
                </div>
                <div class="flex justify-end">
                    <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="saveArt">Art-Steuerung speichern</x-fa::button>
                </div>
            </div>
        @endif
    </x-fa::section>

    {{-- ── Routing-Editor ── --}}
    <x-fa::section title="Wissen nach Kategorie" icon="heroicon-o-folder-open" data-ws-routings
                   description="Welcher Arbeitsschritt bekommt Wissen aus welcher Kategorie. Diese Einstellungen gelten für alle Teams.">
        <ul class="grid gap-x-6 gap-y-1 grid-cols-[repeat(auto-fit,minmax(min(100%,22rem),1fr))] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
            <li><span class="font-medium text-[var(--fa-ink-2)]">Passendes suchen</span>: für wachsende Kategorien, gedeckelt.</li>
            <li><span class="font-medium text-[var(--fa-ink-2)]">Immer vollständig</span>: nur für kleine, feste Mengen.</li>
            <li><span class="font-medium text-[var(--fa-ink-2)]">Je Hauptzutat</span>: Auszüge passend zur Hauptzutat.</li>
            <li><span class="font-medium text-[var(--fa-ink-2)]">Bewusst nicht</span>: der Schritt bekommt aus dieser Kategorie nichts. Ohne Eintrag wird nur bei Bedarf gesucht.</li>
        </ul>

        @php
            $kategorieRoutings = $routings->filter(fn ($r) => empty($r->art));
        @endphp
        @if($kategorieRoutings->isEmpty())
            <x-fa::empty icon="heroicon-o-folder-open" title="Noch keine Kategorie-Steuerung" compact>Unten einen Arbeitsschritt mit einer Kategorie verknüpfen.</x-fa::empty>
        @else
            <div class="overflow-x-auto -mx-4">
                <table class="fa-table min-w-[720px]">
                    <thead>
                        <tr>
                            <th>Arbeitsschritt</th>
                            <th>Kategorie</th>
                            <th>Verwendung</th>
                            <th class="num">Höchstens Dossiers</th>
                            <th><span class="sr-only">Aktionen</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($kategorieRoutings as $r)
                            <tr wire:key="r-{{ $r->id }}">
                                @if($editId === $r->id)
                                    <td colspan="5" class="bg-[var(--fa-accent-soft)]">
                                        <p class="mb-2 text-[var(--fa-ink)]">
                                            <span class="font-medium">{{ WS::schrittLabel($r->feature) }}</span>
                                            @svg('heroicon-m-arrow-long-right', 'inline w-4 h-4 mx-1 text-[var(--fa-ink-3)]')
                                            <span class="font-medium">{{ $katName($r->category) }}</span>
                                            <span class="{{ $schluessel }} ml-1">{{ $r->feature }} · {{ $r->category }}</span>
                                        </p>
                                        <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,14rem),1fr))] items-start">
                                            <x-fa::choice name="form.mode" :live="false" label="Verwendung" idPrefix="ws-edit" :options="$routingModi" data-ws-mode />
                                            <x-fa::field label="Höchstens Dossiers" for="ws-edit-max-{{ $r->id }}" optional>
                                                <x-fa::input id="ws-edit-max-{{ $r->id }}" wire:model="form.max_docs" numeric placeholder="–" class="max-w-[8rem]" />
                                            </x-fa::field>
                                            <x-fa::field label="Alter Einzeldeckel" for="ws-edit-deckel-{{ $r->id }}" hint="Ohne Wirkung, nur noch zur Ansicht.">
                                                <x-fa::input id="ws-edit-deckel-{{ $r->id }}" disabled wire:model="form.max_chars_per_doc" numeric placeholder="–" class="max-w-[8rem]" />
                                            </x-fa::field>
                                        </div>
                                        <div class="flex justify-end gap-2 pt-3">
                                            <x-fa::button variant="ghost" wire:click="cancel">Abbrechen</x-fa::button>
                                            <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="save" data-ws-save>Steuerung speichern</x-fa::button>
                                        </div>
                                    </td>
                                @else
                                    <td class="align-top">
                                        <span class="block font-medium text-[var(--fa-ink)]">
                                            {{ WS::schrittLabel($r->feature) }}
                                            @if(in_array($r->feature, $alias, true))
                                                <x-fa::badge tone="warn" class="ml-1" title="Älterer Schlüssel: versorgt Arbeitsschritte mit anderem Namen">Alter Schlüssel</x-fa::badge>
                                            @endif
                                        </span>
                                        <span class="block {{ $schluessel }}">{{ $r->feature }}</span>
                                    </td>
                                    <td class="align-top">
                                        <span class="text-[var(--fa-ink)]">{{ $katName($r->category) }}</span>
                                        @if(! in_array($r->category, $kategorien, true))
                                            <x-fa::badge tone="crit" class="ml-1" title="Diese Kategorie steht nicht in den Wissens-Kategorien, die Zeile steuert nichts">Unbekannt</x-fa::badge>
                                        @endif
                                    </td>
                                    <td class="align-top"><x-fa::badge :tone="$r->mode === 'none' ? 'neutral' : 'info'">{{ WS::modusLabel($r->mode) }}</x-fa::badge></td>
                                    <td class="num align-top {{ $r->max_docs ? '' : 'text-[var(--fa-ink-3)]' }}" @if($r->max_chars_per_doc) title="Alter Einzeldeckel: {{ number_format($r->max_chars_per_doc, 0, ',', '.') }} Zeichen (ohne Wirkung)" @endif>{{ $r->max_docs ?? '–' }}</td>
                                    <td class="align-top">
                                        @if($darfSchreiben)
                                            <div class="flex items-center justify-end gap-1">
                                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-pencil-square" wire:click="edit({{ $r->id }})" data-ws-edit>Bearbeiten</x-fa::button>
                                                <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false">
                                                    <x-fa::icon-button icon="heroicon-m-ellipsis-horizontal" label="Weitere Aktionen" size="sm" x-on:click="toggle($event)" aria-haspopup="menu" x-bind:aria-expanded="offen" />
                                                    <div class="hidden w-56 fa-surface shadow-lg py-1" x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu">
                                                        <button type="button" role="menuitem" x-on:click="offen = false" wire:click="delete({{ $r->id }})"
                                                                wire:confirm="Steuerung {{ WS::schrittLabel($r->feature) }} mit {{ $katName($r->category) }} entfernen? Danach wird diese Kategorie für den Schritt nur noch bei Bedarf durchsucht."
                                                                class="{{ $menuGefahr }}">
                                                            @svg('heroicon-m-trash', 'w-4 h-4')
                                                            Steuerung entfernen
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        @else
                                            <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] whitespace-nowrap">Nur Master-Team</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($darfSchreiben)
            <div class="flex flex-col gap-3 pt-3 border-t border-[var(--fa-line)]" data-ws-neu>
                <p class="{{ $kleinTitel }}">Neue Kategorie-Steuerung</p>
                <div class="grid gap-3 grid-cols-[repeat(auto-fit,minmax(min(100%,14rem),1fr))] items-start">
                    <x-fa::field label="Arbeitsschritt" for="ws-neu-feature" required hint="Tippen schlägt bekannte Schritte vor.">
                        <x-fa::input id="ws-neu-feature" wire:model="form.feature" list="ws-schritte" placeholder="z. B. recipe.geschmack" data-ws-neu-feature />
                    </x-fa::field>
                    <x-fa::field label="Kategorie" for="ws-neu-kategorie" required>
                        <x-fa::select id="ws-neu-kategorie" wire:model="form.category" placeholder="Kategorie wählen" data-ws-neu-kategorie>
                            @foreach($kategorien as $k)<option value="{{ $k }}">{{ $katName($k) }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                    <x-fa::choice name="form.mode" :live="false" label="Verwendung" idPrefix="ws-neu" :options="$routingModi" />
                    <x-fa::field label="Höchstens Dossiers" for="ws-neu-max" optional>
                        <x-fa::input id="ws-neu-max" wire:model="form.max_docs" numeric class="max-w-[8rem]" />
                    </x-fa::field>
                    <x-fa::field label="Alter Einzeldeckel" for="ws-neu-deckel" hint="Ohne Wirkung, nur noch zur Ansicht.">
                        <x-fa::input id="ws-neu-deckel" disabled wire:model="form.max_chars_per_doc" numeric class="max-w-[8rem]" />
                    </x-fa::field>
                </div>
                <div class="flex justify-end">
                    <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="save" data-ws-neu-anlegen>Steuerung setzen</x-fa::button>
                </div>
            </div>
        @endif
    </x-fa::section>
    </fieldset>
</div>
