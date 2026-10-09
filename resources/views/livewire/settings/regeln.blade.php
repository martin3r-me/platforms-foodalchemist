{{-- Spec 81 Teil G — Einstellungen › Regeln. Links Filter, Mitte die Regeln nach Regelwerk und Paragraf, rechts der
     Editor der gewählten Regel (Formular je Art, Beispiele, Probelauf, Versionen). Speichern wirkt nur auf künftige
     Anlagen und Prüfungen. --}}
@php
    $klein = 'text-[length:var(--fa-text-sm)]';
    $artTon = ['vokabular' => 'info', 'ersetzung' => 'accent', 'pflichtangabe' => 'warn', 'verbot' => 'crit', 'zuordnung' => 'ok', 'schwelle' => 'neutral'];
    $wirkungKurz = ['korrigieren' => 'korrigiert', 'blockieren' => 'blockiert', 'warnen' => 'warnt'];
    $sperrLesen = in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true);
    $spalten = ['wert' => 'Wert', 'aliase' => 'Andere Schreibweisen (Komma)', 'gruppe' => 'Gruppe', 'von' => 'Von', 'nach' => 'Nach',
        'begriff' => 'Begriff', 'ziel_name' => 'Ziel (Grundprodukt / Rezept)'];
@endphp

<div class="flex flex-col gap-4" data-settings-regeln>
    @include('foodalchemist::livewire.settings.partials.sperr-leiste', ['sofort' => true])
    @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
    @if($meldung)<x-fa::notice tone="ok">{{ $meldung }}</x-fa::notice>@endif

    <x-fa::section title="Regeln" :meta="$aktivGesamt . ' von ' . $gesamt . ' aktiv'"
        description="Mechanische Regeln aus den Regelwerken: der Code setzt sie beim Anlegen, Prüfen und im Matching durch, ohne KI. Begründungen und Fachwissen stehen weiter im Wissensmodul.">
        <div class="grid grid-cols-1 gap-4 {{ $offen ? 'xl:grid-cols-[12rem_minmax(0,0.7fr)_minmax(0,1.5fr)]' : 'lg:grid-cols-[13rem_minmax(0,1fr)]' }}">
            {{-- Filter --}}
            <nav class="flex flex-col gap-3 self-start" aria-label="Filter">
                <x-fa::input size="sm" wire:model.live.debounce.300ms="suche" placeholder="Suchen …" aria-label="Regeln suchen" />
                <div class="flex flex-col gap-0.5">
                    <p class="{{ $klein }} font-medium uppercase tracking-wide text-[var(--fa-ink-3)]">Regelwerk</p>
                    @foreach(['' => 'Alle'] + \Platform\FoodAlchemist\Livewire\Settings\Regeln::REGELWERKE as $k => $label)
                        @php $n = $k === '' ? $gesamt : ($zaehler[$k] ?? 0); @endphp
                        @if($k === '' || $n > 0)
                            <button type="button" wire:click="$set('regelwerk', '{{ $k }}')"
                                class="flex items-center justify-between rounded-[var(--fa-radius-control)] px-2 py-1.5 text-left text-[length:var(--fa-text-md)] {{ $regelwerk === $k ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'hover:bg-[var(--fa-hover)]' }}">
                                <span>{{ $label }}</span><span class="{{ $klein }} tabular-nums text-[var(--fa-ink-3)]">{{ $n }}</span>
                            </button>
                        @endif
                    @endforeach
                </div>
                <div class="flex flex-col gap-0.5">
                    <p class="{{ $klein }} font-medium uppercase tracking-wide text-[var(--fa-ink-3)]">Art</p>
                    @foreach(['' => 'Alle'] + \Platform\FoodAlchemist\Livewire\Settings\Regeln::ARTEN as $k => $label)
                        <button type="button" wire:click="$set('art', '{{ $k }}')"
                            class="rounded-[var(--fa-radius-control)] px-2 py-1.5 text-left text-[length:var(--fa-text-md)] {{ $art === $k ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'hover:bg-[var(--fa-hover)]' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </nav>

            {{-- Liste --}}
            <div class="flex min-w-0 flex-col gap-4">
                @forelse($gruppen as $rw => $regeln)
                    <div class="overflow-hidden rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" wire:key="rw-{{ $rw }}">
                        <p class="border-b border-[var(--fa-line)] bg-[var(--fa-ground)] px-3 py-2 font-medium text-[var(--fa-ink)]">{{ \Platform\FoodAlchemist\Livewire\Settings\Regeln::REGELWERKE[$rw] ?? $rw }}</p>
                        @foreach($regeln as $r)
                            <button type="button" wire:click="oeffne({{ $r->id }})" wire:key="regel-{{ $r->id }}"
                                class="grid w-full grid-cols-[4rem_minmax(0,1fr)_auto] items-center gap-3 border-t border-[var(--fa-line)] px-3 py-2 text-left first:border-t-0 {{ $offen?->id === $r->id ? 'bg-[var(--fa-accent-soft)]' : 'hover:bg-[var(--fa-hover)]' }} {{ $r->aktiv ? '' : 'opacity-60' }}"
                                data-regel="{{ $r->schluessel }}">
                                <span class="font-mono {{ $klein }} text-[var(--fa-ink-2)]">{{ $r->paragraph ?? '–' }}</span>
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-[var(--fa-ink)]">{{ $r->titel }}</span>
                                    <span class="block truncate {{ $klein }} text-[var(--fa-ink-3)]">{{ $wirkungKurz[$r->wirkung] ?? $r->wirkung }} · v{{ $r->version }}</span>
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <x-fa::badge :tone="$artTon[$r->art] ?? 'neutral'">{{ \Platform\FoodAlchemist\Livewire\Settings\Regeln::ARTEN[$r->art] ?? $r->art }}</x-fa::badge>
                                    <x-fa::badge :tone="$r->aktiv ? 'ok' : 'neutral'">{{ $r->aktiv ? 'aktiv' : 'aus' }}</x-fa::badge>
                                </span>
                            </button>
                        @endforeach
                    </div>
                @empty
                    <x-fa::empty icon="heroicon-o-funnel" title="Keine Regel in dieser Auswahl">Anderen Filter wählen.</x-fa::empty>
                @endforelse
            </div>

            {{-- Editor --}}
            @if($offen)
                <aside class="flex min-w-0 flex-col gap-4 self-start rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-4 xl:sticky xl:top-3" data-regel-editor="{{ $offen->schluessel }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="{{ $klein }} text-[var(--fa-ink-3)]">{{ \Platform\FoodAlchemist\Livewire\Settings\Regeln::REGELWERKE[$offen->regelwerk] ?? $offen->regelwerk }} {{ $offen->paragraph }} · {{ \Platform\FoodAlchemist\Livewire\Settings\Regeln::ARTEN[$offen->art] ?? $offen->art }} · wirkt auf <span class="font-mono">{{ $offen->ziel }}</span></p>
                            <h3 class="text-[length:var(--fa-text-base)] font-semibold text-[var(--fa-ink)]">{{ $offen->titel }}</h3>
                        </div>
                        <x-fa::icon-button size="sm" icon="heroicon-o-x-mark" label="Schließen" wire:click="schliesse" />
                    </div>

                    <fieldset @disabled($sperrLesen) class="contents" data-fa-lesemodus="{{ $sperrLesen ? '1' : '0' }}">
                    <div class="flex flex-wrap items-center gap-2">
                        @if($offen->aktiv)
                            <x-fa::button size="sm" variant="secondary" icon="heroicon-o-pause" wire:click="setzeAktiv({{ $offen->id }}, false)"
                                wire:confirm="Regel ausschalten? Der Code prüft diesen Punkt dann nicht mehr.">Ausschalten</x-fa::button>
                        @else
                            <x-fa::button size="sm" variant="primary" icon="heroicon-o-play" wire:click="setzeAktiv({{ $offen->id }}, true)"
                                wire:confirm="Regel einschalten? Sie greift ab sofort bei jeder Anlage und Prüfung.">Einschalten</x-fa::button>
                        @endif
                        <span class="{{ $klein }} text-[var(--fa-ink-3)]">Schlüssel <span class="font-mono">{{ $offen->schluessel }}</span></span>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-fa::field label="Titel" for="regel-titel"><x-fa::input id="regel-titel" wire:model="form.titel" /></x-fa::field>
                        <x-fa::field label="Wirkung" for="regel-wirkung">
                            <x-fa::select id="regel-wirkung" wire:model="form.wirkung">
                                @foreach(\Platform\FoodAlchemist\Livewire\Settings\Regeln::WIRKUNGEN as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                            </x-fa::select>
                        </x-fa::field>
                    </div>

                    {{-- Formular je Art --}}
                    @if(in_array($form['art'] ?? '', ['vokabular', 'ersetzung', 'zuordnung'], true))
                        @php $keys = array_keys(($form['zeilen'] ?? [])[0] ?? match($form['art']) { 'vokabular' => ['wert' => 1, 'aliase' => 1, 'gruppe' => 1], 'ersetzung' => ['von' => 1, 'nach' => 1], default => ['begriff' => 1, 'aliase' => 1, 'ziel_name' => 1] }); @endphp
                        <div class="flex flex-col gap-1.5">
                            <p class="{{ $klein }} font-medium text-[var(--fa-ink-2)]">{{ ['vokabular' => 'Erlaubte Werte', 'ersetzung' => 'Ersetzungen', 'zuordnung' => 'Zuordnungen'][$form['art']] }} ({{ count($form['zeilen'] ?? []) }})</p>
                            <div class="max-h-[28rem] overflow-auto rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
                                <table class="fa-table">
                                    <thead><tr>@foreach($keys as $k)<th>{{ $spalten[$k] ?? $k }}</th>@endforeach<th><span class="sr-only">Entfernen</span></th></tr></thead>
                                    <tbody>
                                        @foreach($form['zeilen'] ?? [] as $i => $z)
                                            <tr wire:key="zeile-{{ $offen->id }}-{{ $i }}">
                                                @foreach($keys as $k)<td><x-fa::input size="sm" wire:model="form.zeilen.{{ $i }}.{{ $k }}" aria-label="{{ $spalten[$k] ?? $k }}" class="w-full min-w-24" /></td>@endforeach
                                                <td><x-fa::icon-button size="sm" icon="heroicon-o-trash" label="Zeile entfernen" wire:click="zeileWeg({{ $i }})" /></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div><x-fa::button size="sm" variant="ghost" icon="heroicon-m-plus" wire:click="zeileDazu">Zeile hinzufügen</x-fa::button></div>
                        </div>
                        @if($form['art'] === 'vokabular')
                            <x-fa::field label="Muster" for="regel-muster" optional hint="Eine je Zeile; <mm> = Zahl mit mm, <zahl> = Zahl, z. B. „Würfel <mm>“">
                                <x-fa::textarea id="regel-muster" rows="2" wire:model="form.muster" />
                            </x-fa::field>
                        @endif
                        @if($form['art'] === 'ersetzung')
                            <x-fa::field label="Ausnahmen" for="regel-ausnahmen" optional hint="Wörter, bei denen die Regel schweigt (Komma)"><x-fa::input id="regel-ausnahmen" wire:model="form.ausnahmen" /></x-fa::field>
                        @endif
                        @if($form['art'] === 'zuordnung')
                            <p class="{{ $klein }} text-[var(--fa-ink-3)]">Kontext-Bedingungen (z. B. nur From Scratch) und Wortmengen-Optionen bleiben beim Speichern erhalten.</p>
                        @endif
                    @elseif(in_array($form['art'] ?? '', ['verbot', 'pflichtangabe'], true))
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-fa::field :label="$form['art'] === 'verbot' ? 'Verbotene Wörter' : 'Eines davon muss vorkommen'" for="regel-tokens" hint="Ein Wort je Zeile">
                                <x-fa::textarea id="regel-tokens" rows="8" wire:model="form.tokens" />
                            </x-fa::field>
                            <x-fa::field label="Muster (Regex)" for="regel-muster2" optional hint="Ein Muster je Zeile, z. B. /\b\d+\s*kg\b/iu — wird beim Speichern geprüft">
                                <x-fa::textarea id="regel-muster2" rows="8" wire:model="form.muster" class="font-mono" />
                            </x-fa::field>
                        </div>
                        <x-fa::field label="Ausnahmen" for="regel-ausn" optional hint="Wörter, bei denen die Regel schweigt (Komma)"><x-fa::input id="regel-ausn" wire:model="form.ausnahmen" /></x-fa::field>
                        <x-fa::field :label="$form['art'] === 'verbot' ? 'Begründung im Befund' : 'Hinweis im Befund'" for="regel-grund"><x-fa::input id="regel-grund" wire:model="form.grund" /></x-fa::field>
                        @if(! empty($offen->params['bedingung']))
                            <p class="{{ $klein }} text-[var(--fa-ink-3)]">Gilt nur, wenn: @foreach($offen->params['bedingung'] as $feld => $werte){{ $feld }} = {{ implode(' / ', (array) $werte) }}@if(! $loop->last) · @endif @endforeach</p>
                        @endif
                    @elseif(($form['art'] ?? '') === 'schwelle')
                        <div class="grid gap-3 sm:grid-cols-4">
                            <x-fa::field label="Vergleich" for="regel-vgl">
                                <x-fa::select id="regel-vgl" wire:model.live="form.vergleich">
                                    @foreach(['>=' => 'mindestens', '<=' => 'höchstens', '>' => 'mehr als', '<' => 'weniger als', 'zwischen' => 'zwischen'] as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                                </x-fa::select>
                            </x-fa::field>
                            @if(($form['vergleich'] ?? '') === 'zwischen')
                                <x-fa::field label="Von" for="regel-min"><x-fa::input id="regel-min" numeric wire:model="form.min" /></x-fa::field>
                                <x-fa::field label="Bis" for="regel-max"><x-fa::input id="regel-max" numeric wire:model="form.max" /></x-fa::field>
                            @else
                                <x-fa::field label="Wert" for="regel-wert"><x-fa::input id="regel-wert" numeric wire:model="form.wert" /></x-fa::field>
                            @endif
                            <x-fa::field label="Einheit" for="regel-einheit" optional><x-fa::input id="regel-einheit" wire:model="form.einheit" /></x-fa::field>
                        </div>
                    @endif

                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-fa::field label="Beispiele: richtig" for="regel-richtig" optional hint="Eines je Zeile — darf keinen Befund auslösen">
                            <x-fa::textarea id="regel-richtig" rows="3" wire:model="form.richtig" />
                        </x-fa::field>
                        <x-fa::field label="Beispiele: falsch" for="regel-falsch" optional hint="Eines je Zeile — muss einen Befund auslösen">
                            <x-fa::textarea id="regel-falsch" rows="3" wire:model="form.falsch" />
                        </x-fa::field>
                    </div>
                    <x-fa::field label="Notiz" for="regel-notiz" optional><x-fa::textarea id="regel-notiz" rows="2" wire:model="form.notiz" /></x-fa::field>

                    <div class="flex flex-wrap items-center justify-end gap-2 border-t border-[var(--fa-line)] pt-3">
                        <x-fa::button size="sm" variant="secondary" icon="heroicon-o-beaker" wire:click="pruefeProbelauf" data-regel-probelauf>Probelauf</x-fa::button>
                        <x-fa::button size="sm" variant="primary" icon="heroicon-o-check" wire:click="speichern" data-regel-speichern>Speichern</x-fa::button>
                    </div>
                    </fieldset>

                    @if($probelauf)
                        <div class="flex flex-col gap-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-ground)] p-3" data-regel-probelauf-ergebnis>
                            <p class="font-medium text-[var(--fa-ink)]">Probelauf · {{ $probelauf['bestand'] }}</p>
                            <p class="{{ $klein }} text-[var(--fa-ink-2)]">
                                {{ number_format($probelauf['geprueft'], 0, ',', '.') }} geprüft · <b>{{ number_format($probelauf['nachher'], 0, ',', '.') }} betroffen</b>
                                (vorher {{ number_format($probelauf['vorher'], 0, ',', '.') }}, <span class="text-[var(--fa-warn)]">+{{ $probelauf['neu_betroffen'] }} neu</span>, <span class="text-[var(--fa-ok)]">−{{ $probelauf['nicht_mehr'] }} nicht mehr</span>)
                            </p>
                            @foreach($probelauf['pruefung'] as $p)<x-fa::signal tone="crit">{{ $p }}</x-fa::signal>@endforeach
                            @if($probelauf['beispiele'] !== [])
                                <ul class="flex max-h-64 flex-col gap-1 overflow-y-auto {{ $klein }}">
                                    @foreach($probelauf['beispiele'] as $b)
                                        <li class="flex gap-2"><span class="shrink-0">{{ $b['neu'] ? '＋' : '·' }}</span><span class="min-w-0"><span class="text-[var(--fa-ink)]">{{ $b['text'] }}</span> <span class="text-[var(--fa-ink-3)]">{{ $b['ergebnis'] }}</span></span></li>
                                    @endforeach
                                </ul>
                            @endif
                            <p class="{{ $klein }} text-[var(--fa-ink-3)]">Speichern ändert nur künftige Anlagen und Prüfungen. Der Bestand bleibt, wie er ist.</p>
                        </div>
                    @endif

                    @if($versionen->count() > 1)
                        <div class="flex flex-col gap-1">
                            <p class="{{ $klein }} font-medium text-[var(--fa-ink-2)]">Versionen</p>
                            @foreach($versionen as $v)
                                <div class="flex items-center justify-between gap-2 {{ $klein }}" wire:key="v-{{ $v->id }}">
                                    <span class="text-[var(--fa-ink-2)]">v{{ $v->version }} · {{ $v->created_at?->format('d.m.Y H:i') }} · {{ $v->aktiv ? 'aktiv' : 'aus' }}</span>
                                    @if($v->version !== $offen->version)
                                        <x-fa::button size="sm" variant="ghost" wire:click="zurueckAuf({{ $v->version }})" wire:confirm="Fassung v{{ $v->version }} wiederherstellen?">Zurück auf diese Fassung</x-fa::button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if($offen->dossier_slug)
                        <p class="{{ $klein }} text-[var(--fa-ink-3)]">Herkunft: Dossier <span class="font-mono">{{ $offen->dossier_slug }}</span></p>
                    @endif
                </aside>
            @endif
        </div>
    </x-fa::section>
</div>
