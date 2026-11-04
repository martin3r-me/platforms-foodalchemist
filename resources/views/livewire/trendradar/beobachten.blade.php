@php
    use Platform\FoodAlchemist\Support\GoogleNewsSuche;
    use Platform\FoodAlchemist\Support\TrendVokabular;
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
@endphp
<div class="flex flex-col gap-4" data-themen-beobachten>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-semibold">Deine Suchthemen <span class="{{ $leise }}">· {{ $auftraege->count() }}</span></h2>
            <p class="{{ $leise }}">Google News nach Themen beobachten und passende Artikel im Eingang prüfen.</p>
        </div>
        @if($darf)<x-fa::button variant="primary" size="sm" icon="heroicon-m-plus" wire:click="neu">Thema hinzufügen</x-fa::button>@endif
    </div>
    @unless($hostAktiv)<x-fa::notice tone="warn">Der automatische Abruf ist auf diesem Server ausgeschaltet. Die Treffer-Vorschau funktioniert weiterhin.</x-fa::notice>@endunless
    @if($meldung)<x-fa::notice tone="ok">{{ $meldung }}</x-fa::notice>@endif
    @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
    @forelse($auftraege as $q)
        @php
            $suche = GoogleNewsSuche::lesen($q->url);
            $anzeige = GoogleNewsSuche::anzeige($suche);
            $titel = preg_replace('/^Google News:\s*|\s*\(DE\)$/u', '', $q->name);
            $aktiv = $q->aktiv && $q->rhythmus !== 'aus';
        @endphp
        <article class="fa-surface p-3 flex flex-col gap-3" wire:key="suchauftrag-{{ $q->id }}" data-suchthema-karte>
            <div class="flex items-start justify-between gap-3">
                <h3 class="font-semibold min-w-0 break-words">{{ $titel }}</h3>
                <x-fa::badge :tone="$aktiv ? 'ok' : 'neutral'">{{ $aktiv ? 'Aktiv' : 'Pausiert' }}</x-fa::badge>
            </div>
            <div class="flex flex-wrap gap-1.5" aria-label="Suchbegriffe">
                @forelse($anzeige['begriffe'] as $begriff)
                    <x-fa::badge class="max-w-full !h-auto !whitespace-normal break-words">{{ $begriff }}</x-fa::badge>
                @empty<span class="{{ $leise }}">Individuelle Suche · Ausdruck unter Details</span>@endforelse
            </div>
            @if($anzeige['kontext'] !== [])<p class="{{ $leise }}">Im Kontext: {{ implode(' · ', $anzeige['kontext']) }}</p>@endif
            <div class="flex flex-wrap gap-x-3 gap-y-1 {{ $leise }}">
                <span>{{ ['DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz'][$suche['markt']] }}</span>
                <span>{{ TrendVokabular::RHYTHMEN[$q->rhythmus] }}</span>
                <span>{{ $q->letzter_abruf_at ? 'Abgerufen '.$q->letzter_abruf_at->format('d.m. H:i') : 'Erster Abruf steht aus' }}</span>
            </div>
            @if($q->letzter_fehler)<x-fa::notice tone="warn">{{ $q->letzter_fehler }}</x-fa::notice>@endif
            <div class="flex flex-wrap items-center gap-2 border-t border-[var(--fa-line)] pt-2 mt-auto">
                @if($darf && (int) $q->team_id === (int) $teamId)
                    <x-fa::button size="sm" variant="ghost" icon="heroicon-m-pencil-square" wire:click="bearbeiten({{ $q->id }})">Bearbeiten</x-fa::button>
                    @if($q->rhythmus !== 'aus')<x-fa::button size="sm" variant="ghost" wire:click="pausieren({{ $q->id }})" wire:loading.attr="disabled">{{ $q->aktiv ? 'Pausieren' : 'Fortsetzen' }}</x-fa::button>@endif
                @else<span class="{{ $leise }}">Vom übergeordneten Team</span>@endif
                <details class="{{ $leise }} min-w-0 w-full">
                    <summary class="cursor-pointer">Details zur Suche</summary>
                    <div class="pt-2 flex flex-col gap-2">
                        <p>{{ GoogleNewsSuche::MAERKTE[$suche['markt']] }} · {{ GoogleNewsSuche::ZEITRAEUME[$suche['zeitraum']] }}</p>
                        @if($suche['ausschliessen'])<p>Ausgeschlossen: {{ str_replace("\n", ', ', $suche['ausschliessen']) }}</p>@endif
                        <p class="break-words">Suchausdruck: {{ $suche['ausdruck'] ?: str_replace("\n", ' · ', $suche['begriffe']) }}</p>
                    </div>
                </details>
            </div>
        </article>
    @empty
        <x-fa::empty title="Welches Thema interessiert dich?" icon="heroicon-o-magnifying-glass">Füge dein erstes Thema hinzu, zum Beispiel Fermentation oder alkoholfreie Getränke.</x-fa::empty>
    @endforelse
    </div>
    <p class="{{ $leise }}">RSS und Vorschau ohne API-Gebühren. Die anschließende KI-Auswertung im Eingang kann Kosten verursachen. Google-Trends-Suchspitzen werden separat unter „Quellen“ verwaltet.</p>

    @if($darf)
    <x-foodalchemist::modal name="suchthema-editor" :title="$quelleId ? 'Suchthema bearbeiten' : 'Neues Suchthema'" size="max-w-2xl">
        <form id="suchthema-form" wire:submit="speichern" class="flex flex-col gap-4" data-suchauftrag-form>
            <x-fa::field label="Thema" for="beob-name"><x-fa::input id="beob-name" wire:model="form.name" placeholder="z. B. Alkoholfreie Getränke" /></x-fa::field>
            @if($form['ausdruck'] === '')
                <x-fa::field label="Wonach möchtest du suchen?" for="beob-begriffe" hint="Ein Begriff pro Zeile oder durch Kommas getrennt. Einer davon muss vorkommen.">
                    <x-fa::textarea id="beob-begriffe" wire:model="form.begriffe" rows="3" placeholder="Mocktails, alkoholfreie Cocktails, Zero Proof" />
                </x-fa::field>
            @else
                @php $bestehend = GoogleNewsSuche::anzeige($form); @endphp
                <div class="flex flex-col gap-2">
                    <p class="text-[length:var(--fa-text-sm)] font-medium">Diese Suche beobachtet</p>
                    <div class="flex flex-wrap gap-1.5">@foreach($bestehend['begriffe'] as $wort)<x-fa::badge>{{ $wort }}</x-fa::badge>@endforeach</div>
                    @if($bestehend['kontext'])<p class="{{ $leise }}">Im Kontext: {{ implode(' · ', $bestehend['kontext']) }}</p>@endif
                    <p class="{{ $leise }}">Die bestehende Suche enthält zusätzliche Bedingungen. Du kannst sie unter „Suchlogik anpassen“ bearbeiten.</p>
                </div>
            @endif
            <x-fa::field label="Begriffe ausschließen" for="beob-aus" optional><x-fa::input id="beob-aus" wire:model="form.ausschliessen" placeholder="z. B. Stellenangebote, Gewinnspiel" /></x-fa::field>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <x-fa::field label="Markt" for="beob-markt"><x-fa::select id="beob-markt" wire:model="form.markt" :options="GoogleNewsSuche::MAERKTE" /></x-fa::field>
                <x-fa::field label="Artikelzeitraum" for="beob-zeit"><x-fa::select id="beob-zeit" wire:model="form.zeitraum" :options="GoogleNewsSuche::ZEITRAEUME" /></x-fa::field>
                <x-fa::field label="Wie oft prüfen?" for="beob-rhythmus"><x-fa::select id="beob-rhythmus" wire:model="form.rhythmus" :options="TrendVokabular::RHYTHMEN" /></x-fa::field>
            </div>
            <details class="{{ $leise }}" wire:key="suchlogik-{{ $quelleId ?? 'neu' }}">
                <summary class="cursor-pointer">Suchlogik anpassen</summary>
                <div class="pt-2 flex flex-col gap-2">
                    <p>Ein erweiterter Ausdruck ersetzt die einfache Begriffsliste. Bestehende Bedingungen bleiben beim Speichern erhalten.</p>
                    <x-fa::input wire:model="form.ausdruck" aria-label="Erweiterter Suchausdruck" />
                    @if($form['ausdruck'] !== '')
                        <p>Alternativ eine neue einfache Begriffsliste verwenden:</p>
                        <x-fa::textarea wire:model="form.begriffe" rows="2" aria-label="Neue einfache Suchbegriffe" />
                        <x-fa::button type="button" size="sm" wire:click="$set('form.ausdruck', '')">Zur einfachen Begriffsliste wechseln</x-fa::button>
                    @endif
                </div>
            </details>
            @if($errors->any())<x-fa::notice tone="crit"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-fa::notice>@endif
            @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
            @if($vorschau !== null)
                <x-fa::section title="Treffer-Vorschau">
                    <p class="{{ $leise }}">{{ $vorschau['anzahl'] }} gefunden · {{ $vorschau['gefiltert'] }} herausgefiltert · bis zu 10 Treffer. Es wird noch nichts übernommen.</p>
                    <ul class="flex flex-col gap-2 mt-3">@forelse($vorschau['treffer'] as $treffer)
                        <li><a href="{{ $treffer['url'] }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $treffer['titel'] }}</a></li>
                    @empty<li>Keine passenden Treffer. Versuche breitere Begriffe oder einen längeren Zeitraum.</li>@endforelse</ul>
                </x-fa::section>
            @endif
        </form>
        <x-slot:footer>
            <span wire:loading role="status" class="{{ $leise }}">Wird verarbeitet …</span>
            <x-fa::button variant="ghost" @click="$dispatch('modal.close', { name: 'suchthema-editor' })">Abbrechen</x-fa::button>
            <x-fa::button wire:click="trefferAnsehen" wire:loading.attr="disabled">Treffer ansehen</x-fa::button>
            <x-fa::button type="submit" form="suchthema-form" variant="primary" wire:loading.attr="disabled">{{ $quelleId ? 'Änderungen speichern' : 'Beobachtung starten' }}</x-fa::button>
        </x-slot:footer>
    </x-foodalchemist::modal>
    @endif
</div>
