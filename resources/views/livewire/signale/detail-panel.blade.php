{{-- Signal-Detail (Modal) — Spec 21 · Tranche P (S3a). Read-only Sprungliste: was ist los,
     was ist zu tun, welche Objekte sind betroffen, wie entwickelt sich der Typ, und ob der Typ
     gedämpft werden soll. Bearbeitet wird im jeweiligen Editor bzw. über „KI erledigen lassen"
     in der Signal-Zeile.

     fa-pass 2026-10-05: auf Bausteine <x-fa::…> umgestellt. Funktion, wire:-Bindungen,
     Events und data-Marker unverändert. --}}
{{-- Achtung Blade-Falle: in dieser Datei NUR Block-Form für PHP. Die Kurzform mit Klammern
     neben einem Block lässt Blades Roh-Block-Regex alles dazwischen unkompiliert. --}}
@php
    $schwereTon = ['kritisch' => 'crit', 'warnung' => 'warn', 'info' => 'info'];
    $ton = $sig !== null ? ($schwereTon[$sig->severity->value] ?? 'info') : 'info';
    $istWeg = $plan !== null && $plan['kind'] === 'navigate';
    $metaZahl = $betroffen ? number_format($betroffen['total'], 0, ',', '.') : null;
    $verlaufMeta = $policy !== null ? $policy['count'] . ' offen' : null;
    $bereich = $sig !== null ? \Platform\FoodAlchemist\Livewire\ReviewQueue::bereichLabel($sig->type) : null;
    $polBadge = match ($policy['state'] ?? 'alarm') {
        'stumm' => ['Stumm', 'neutral'],
        'akzeptiert' => ['Akzeptiert', 'ok'],
        'frist_abgelaufen' => ['Frist abgelaufen', 'warn'],
        default => ['Meldet ungedämpft', 'neutral'],
    };
    $delta = $policy['delta'] ?? null;
    $sparkTon = ($delta ?? 0) > 0 ? 'text-[var(--fa-crit)]' : (($delta ?? 0) < 0 ? 'text-[var(--fa-ok)]' : 'text-[var(--fa-ink-3)]');
    $kiUrteil = $sig !== null && $sig->type->istKiUrteil();
    $linkKlasse = 'min-w-0 flex-1 flex items-center gap-2 text-left text-[length:var(--fa-text-md)] text-[var(--fa-accent)] hover:text-[var(--fa-accent-hover)] hover:underline';
    $artLabel = ['recipe' => null, 'gp' => 'Grundprodukt', 'concept' => 'Konzept', 'foodbook' => 'Foodbook'];
@endphp

<x-foodalchemist::modal name="signal-detail" title="Signal" size="max-w-2xl">
    <div data-signal-panel class="flex flex-col gap-5">
    @if($sig === null)
        <x-fa::empty icon="heroicon-o-bell" title="Kein Signal gewählt">In der Liste bei einem Signal „Betroffene ansehen“ wählen.</x-fa::empty>
    @else
        {{-- Kopf: Schwere, Titel, Bereich und Art --}}
        <header class="flex flex-col gap-2">
            <div class="flex flex-wrap items-center gap-1.5">
                <x-fa::badge :tone="$ton">{{ $sig->severity->label() }}</x-fa::badge>
                @if(! $sig->status->istOffen())
                    <x-fa::badge :tone="$sig->status->value === 'erledigt' ? 'ok' : 'neutral'">{{ $sig->status->label() }}</x-fa::badge>
                @endif
                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $bereich }}</span>
            </div>
            <h3 class="text-[length:var(--fa-text-lg)] font-semibold leading-snug text-[var(--fa-ink)]">{{ $sig->title }}</h3>
            <p class="inline-flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                @svg($sig->type->icon(), 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]') {{ $sig->type->label() }}
            </p>
            @if($sig->description)
                <p class="text-[length:var(--fa-text-md)] leading-relaxed text-[var(--fa-ink-2)] max-w-[70ch]">{{ $sig->description }}</p>
            @endif
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" title="Quelle: {{ $sig->source }}">
                Erkannt am {{ $sig->created_at?->format('d.m.Y') }} um {{ $sig->created_at?->format('H:i') }} Uhr
            </p>
        </header>

        {{-- Was tun? 22·H4b/V-033: drei Lagen, drei Ausgaben. Auto-Fix/KI-Assistenz (Knopf in der
             Signal-Zeile), Weg-Satz (der Mensch geht selbst hin) und „kein Weg" mit Begründung. --}}
        @if($plan !== null && $sig->status->istOffen())
            <x-fa::notice tone="info" :title="$istWeg ? 'So beheben' : $plan['flavorLabel']">
                {{ $plan['plan'] }}
                @unless($istWeg)
                    <span class="block mt-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Starten über „KI erledigen lassen“ in der Liste.</span>
                @endunless
            </x-fa::notice>
        @elseif($plan === null && $ohneWeg !== null && $sig->status->istOffen())
            <div class="flex items-start gap-2.5 px-3.5 py-3 rounded-[var(--fa-radius-surface)] bg-[var(--fa-neutral-soft)]" data-signal-ohne-weg>
                @svg('heroicon-o-hand-raised', 'w-5 h-5 shrink-0 mt-px text-[var(--fa-ink-3)]')
                <div class="min-w-0 text-[length:var(--fa-text-md)]">
                    <p class="font-semibold text-[var(--fa-ink)]">Im System nicht automatisch lösbar</p>
                    <p class="text-[var(--fa-ink-2)]">{{ $ohneWeg }}</p>
                </div>
            </div>
        @endif

        {{-- Rückmeldungen (Regler gespeichert/entfernt, Fehler) --}}
        @if($meldung)
            <x-fa::notice tone="ok" data-signal-meldung>{{ $meldung }}</x-fa::notice>
        @endif
        @if($fehler)
            <x-fa::notice tone="crit" data-signal-fehler>{{ $fehler }}</x-fa::notice>
        @endif

        {{-- ── Betroffene Objekte: volle Liste + Sortierung ── --}}
        <x-fa::section variant="plain" title="Betroffen" icon="heroicon-o-queue-list" :meta="$metaZahl">
            <x-slot:actions>
                <div role="group" aria-label="Sortierung" class="flex p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]">
                    @foreach(['name' => 'A bis Z', 'name_desc' => 'Z bis A', 'art' => 'Nach Art'] as $wert => $lbl)
                        <button type="button" wire:key="sigsort-{{ $wert }}" wire:click="setSort('{{ $wert }}')" aria-pressed="{{ $sort === $wert ? 'true' : 'false' }}"
                                class="h-7 px-2.5 rounded-[5px] text-[length:var(--fa-text-sm)] font-medium transition-colors {{ $sort === $wert ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}"
                                data-signal-sort="{{ $wert }}">{{ $lbl }}</button>
                    @endforeach
                </div>
            </x-slot:actions>

            @if($betroffen && count($betroffen['items']))
                <div class="flex flex-col divide-y divide-[var(--fa-line)]">
                    @foreach($betroffen['items'] as $it)
                        @php
                            $istGewaehlt = $objektKind === $it['kind'] && $objektId === $it['id'];
                            $art = $it['kind'] === 'recipe' ? ($it['is_sales_recipe'] ? 'Gericht' : 'Basisrezept') : ($artLabel[$it['kind']] ?? null);
                        @endphp
                        <div wire:key="sigobj-{{ $it['kind'] }}-{{ $it['id'] }}-{{ $loop->index }}" class="{{ $istGewaehlt ? 'bg-[var(--fa-accent-soft)]' : '' }}">
                            <div class="flex items-center gap-2 px-1 py-2">
                                @if($it['kind'] === 'recipe')
                                    {{-- Tranche B (S5b): bei KI-Urteil-Typen öffnet das Modal direkt mit den abgelegten
                                         Befunden. Kein Prüf-Call beim Sprung (kein Egress, keine zweite Befundlage). --}}
                                    <button type="button"
                                            wire:click="$dispatch('{{ $it['is_sales_recipe'] ? 'vk-modal.oeffnen' : 'recipe-modal.oeffnen' }}', { id: {{ $it['id'] }}, copilot: {{ $kiUrteil ? 'true' : 'false' }} })"
                                            class="{{ $linkKlasse }}"
                                            title="{{ $it['is_sales_recipe'] ? 'Verkaufsgericht' : 'Basisrezept' }} öffnen{{ $kiUrteil ? ', mit den Befunden der KI' : '' }}">
                                        <span class="truncate">{{ $it['name'] }}</span>
                                    </button>
                                @elseif($it['kind'] === 'gp')
                                    <a href="{{ route('foodalchemist.gps.index', ['gp' => $it['id']]) }}" wire:navigate class="{{ $linkKlasse }}" title="Grundprodukt öffnen">
                                        <span class="truncate">{{ $it['name'] }}</span>
                                    </a>
                                @elseif($it['kind'] === 'concept')
                                    {{-- Tranche C: der Concepter wählt über ?sel= vor (dasselbe Muster wie ?gp= bei den GPs) --}}
                                    <a href="{{ route('foodalchemist.concepter.index', ['tab' => 'concepts', 'sel' => $it['id']]) }}" wire:navigate class="{{ $linkKlasse }}" title="Konzept im Concepter öffnen">
                                        <span class="truncate">{{ $it['name'] }}</span>
                                    </a>
                                @elseif($it['kind'] === 'foodbook')
                                    {{-- Tranche D: die Leitstelle wählt über ?fb= vor (Foodbooks\Index::$selectedId) --}}
                                    <a href="{{ route('foodalchemist.foodbooks.index', ['fb' => $it['id']]) }}" wire:navigate class="{{ $linkKlasse }}" title="Foodbook in der Leitstelle öffnen">
                                        <span class="truncate">{{ $it['name'] }}</span>
                                    </a>
                                @else
                                    <span class="min-w-0 flex-1 truncate text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" title="{{ $it['name'] }}">{{ $it['name'] }}</span>
                                @endif

                                @if($art)<span class="shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $art }}</span>@endif

                                {{-- „Was noch?" gilt für jedes auflösbare Objekt (SignalObjectService::KINDS). --}}
                                @if(in_array($it['kind'], \Platform\FoodAlchemist\Services\SignalObjectService::KINDS, true))
                                    <x-fa::button variant="ghost" size="sm" icon="heroicon-m-bell-alert" wire:click="objektWaehlen('{{ $it['kind'] }}', {{ $it['id'] }})"
                                                  aria-expanded="{{ $istGewaehlt ? 'true' : 'false' }}" title="Alle offenen Signale an diesem Objekt"
                                                  data-signal-objekt="{{ $it['kind'] }}-{{ $it['id'] }}">Weitere Befunde</x-fa::button>
                                @endif
                            </div>

                            {{-- Objekt-zentrische Sicht: alle offenen Signale am selben Objekt --}}
                            @if($istGewaehlt)
                                <div class="mx-1 mb-2 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)] px-3 py-2.5" data-signal-objekt-sicht>
                                    <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)] mb-1.5">Offene Signale an diesem Objekt</p>
                                    @if(count($objektSignale) <= 1)
                                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Nur dieses Signal. Einmal beheben genügt.</p>
                                    @else
                                        <div class="flex flex-col gap-0.5">
                                            @foreach($objektSignale as $os)
                                                <button type="button" wire:key="objsig-{{ $os['id'] }}" wire:click="signalOeffnen({{ $os['id'] }})"
                                                        class="w-full flex items-center gap-2 text-left rounded-[var(--fa-radius-control)] px-2 py-1.5 transition-colors {{ $os['id'] === $sig->id ? 'bg-[var(--fa-neutral-soft)]' : 'hover:bg-[var(--fa-hover)]' }}">
                                                    <x-fa::badge :tone="$schwereTon[$os['severity']] ?? 'info'" class="shrink-0" :icon="$os['icon']" title="{{ ucfirst($os['severity']) }}"></x-fa::badge>
                                                    <span class="min-w-0 flex-1 truncate text-[length:var(--fa-text-md)] {{ $os['id'] === $sig->id ? 'font-medium text-[var(--fa-ink)]' : 'text-[var(--fa-ink-2)]' }}" title="{{ $os['label'] }}">{{ $os['label'] }}</span>
                                                    @if($os['hat_ki'])<span class="shrink-0 text-[var(--fa-accent)]" title="KI-Schritt vorhanden">@svg('heroicon-m-sparkles', 'w-4 h-4')</span>@endif
                                                    @if($os['id'] === $sig->id)<span class="shrink-0 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">dieses</span>@endif
                                                </button>
                                            @endforeach
                                        </div>
                                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] mt-1.5">
                                            {{ count($objektSignale) }} Befunde am selben Objekt. In einem Durchgang beheben statt {{ count($objektSignale) }}-mal öffnen.
                                        </p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if($betroffen['total'] > $betroffen['gezeigt'])
                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                        Zeigt {{ number_format($betroffen['gezeigt'], 0, ',', '.') }} von {{ number_format($betroffen['total'], 0, ',', '.') }}
                        (höchstens {{ $panelLimit }}). Die übrigen erscheinen, sobald diese behoben sind.
                    </p>
                @endif
            @elseif($betroffen)
                <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">
                    Für diese Art gibt es keine Einzelaufstellung, der Befund ist zusammengefasst.
                    @if($betroffen['total'] > 0) Betroffen sind {{ number_format($betroffen['total'], 0, ',', '.') }} Objekte. @endif
                </p>
            @else
                <x-fa::empty compact icon="heroicon-o-queue-list" title="Keine betroffenen Objekte hinterlegt" />
            @endif
        </x-fa::section>

        {{-- ── Verlauf (E1): Signal-Seite der Reihe, Schlüssel = Signal-Typ ── --}}
        <x-fa::section variant="plain" title="Verlauf dieser Art" icon="heroicon-o-presentation-chart-line" :meta="$verlaufMeta">
            @if($spark !== null)
                <div class="flex items-center gap-4" data-signal-spark>
                    <svg viewBox="0 0 {{ $spark['w'] }} {{ $spark['h'] }}" class="w-full h-10 overflow-visible" preserveAspectRatio="none" aria-hidden="true">
                        <polyline points="{{ $spark['points'] }}" fill="none" stroke="currentColor" stroke-width="1.5"
                                  vector-effect="non-scaling-stroke" class="{{ $sparkTon }}" />
                    </svg>
                    <div class="shrink-0 text-right">
                        <div class="text-[length:var(--fa-text-lg)] font-semibold tabular-nums text-[var(--fa-ink)] leading-none">{{ $spark['letzter'] }}</div>
                        @if($delta !== null && $delta !== 0)
                            <div class="text-[length:var(--fa-text-sm)] tabular-nums {{ $delta > 0 ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ok)]' }}">{{ $delta > 0 ? '+' : '' }}{{ $delta }} seit letzter Prüfung</div>
                        @endif
                    </div>
                </div>
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">
                    {{ $spark['punkte'] }} Messungen · niedrigster Stand {{ $spark['min'] }}, höchster {{ $spark['max'] }} ·
                    seit {{ \Illuminate\Support\Carbon::parse($spark['von'])->format('d.m.Y H:i') }}
                </p>
            @else
                <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">
                    Noch keine Reihe. Der Verlauf entsteht ab der zweiten Prüfung.
                    @if(($policy['count'] ?? 0) > 0)Aktuell {{ $policy['count'] }} offen.@endif
                </p>
            @endif
        </x-fa::section>

        {{-- ── Rausch-Guard (E2): der Regler gilt für den TYP, nicht für dieses eine Signal. ── --}}
        <x-fa::section variant="plain" title="Meldungen dieser Art dämpfen" icon="heroicon-o-adjustments-horizontal"
                       description="Gilt für alle Signale der Art, nicht nur für dieses.">
            <x-slot:actions>
                <x-fa::button variant="ghost" size="sm" :icon="$policyForm ? 'heroicon-m-x-mark' : 'heroicon-m-adjustments-horizontal'" wire:click="policyFormUmschalten"
                              aria-expanded="{{ $policyForm ? 'true' : 'false' }}" data-signal-policy-toggle>{{ $policyForm ? 'Schließen' : 'Einstellen' }}</x-fa::button>
            </x-slot:actions>

            @if($policy !== null)
                <div class="flex items-start gap-2" data-signal-policy-state>
                    <x-fa::badge :tone="$polBadge[1]" class="shrink-0">{{ $polBadge[0] }}</x-fa::badge>
                    <div class="min-w-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">
                        <p>{{ $policy['hinweis'] }}</p>
                        @if($policy['note'])<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] italic">{{ $policy['note'] }}</p>@endif
                        @if($policy['geerbt'])
                            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Vom übergeordneten Team übernommen. Speichern legt eine eigene Einstellung an, die sie überstimmt.</p>
                        @endif
                    </div>
                </div>
            @endif

            @if($policyForm)
                <div class="fa-surface p-4 flex flex-col gap-3" data-signal-policy-form>
                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] leading-relaxed">
                        Gilt für <span class="font-semibold">alle</span> Signale der Art „{{ $sig->type->label() }}“.
                        Schwelle und Frist fassen nur den Bestand zusammen, neue Fälle melden sich weiter.
                        Nur „Stumm“ schaltet auch die Meldung über Verschlechterungen ab.
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <x-fa::field label="Zusammenfassen ab" for="sig-pol-schwelle" hint="Ab so vielen offenen Fällen nur eine Zeile zeigen.">
                            <x-fa::input id="sig-pol-schwelle" type="number" numeric min="0" wire:model="pThreshold" placeholder="leer = nie" data-signal-policy-threshold />
                        </x-fa::field>
                        <x-fa::field label="Akzeptiert bis" for="sig-pol-bis" hint="Danach meldet sich die Lage wieder.">
                            <x-fa::input id="sig-pol-bis" type="date" wire:model="pAcceptedUntil" data-signal-policy-until />
                        </x-fa::field>
                    </div>
                    <x-fa::field label="Begründung" for="sig-pol-notiz" hint="Wird bei der Lage angezeigt." optional>
                        <x-fa::input id="sig-pol-notiz" wire:model="pNote" maxlength="255" placeholder="z. B. Einkauf klärt neue Bezugsquelle bis Monatsende" data-signal-policy-note />
                    </x-fa::field>
                    <label class="flex items-center gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                        <input type="checkbox" wire:model="pMuted" class="w-4 h-4 rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] focus:ring-[var(--fa-accent)]" data-signal-policy-muted>
                        Stumm schalten <span class="text-[var(--fa-ink-3)]">(interessiert nicht, auch keine Meldung über Verschlechterungen)</span>
                    </label>
                    {{-- Entfernen links, Speichern rechts: Löschen nie direkt neben Speichern. --}}
                    <div class="flex items-center justify-between gap-2 pt-1">
                        <div>
                            @if($policy !== null && $policy['gesetzt'] && ! $policy['geerbt'])
                                <x-fa::button variant="danger" size="sm" icon="heroicon-m-trash" wire:click="policyEntfernen" data-signal-policy-remove>Einstellung entfernen</x-fa::button>
                            @endif
                        </div>
                        <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="policySpeichern" data-signal-policy-save>Einstellung speichern</x-fa::button>
                    </div>
                </div>
            @endif
        </x-fa::section>
    @endif
    </div>
</x-foodalchemist::modal>
