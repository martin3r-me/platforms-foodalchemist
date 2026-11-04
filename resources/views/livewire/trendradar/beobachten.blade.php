<div class="flex flex-col gap-5" data-themen-beobachten>
    <x-fa::section title="Was möchtest du beobachten?" description="Begriffe eines Themas werden alternativ gesucht: Ein passender Begriff genügt. Google News liefert Artikel; die Einordnung erfolgt anschließend im Eingang." />
    @unless($hostAktiv)<x-fa::notice tone="warn">Der automatische Quellen-Abruf ist auf diesem Server ausgeschaltet. Suchen und Vorschau funktionieren; der Rhythmus greift erst mit aktiviertem Server-Zeitplan.</x-fa::notice>@endunless
    <x-fa::notice tone="info">Google News wird per RSS ohne API-Gebühren abgefragt. Die Vorschau verwendet keine KI. Die spätere Auswertung im Eingang kann KI-Kosten verursachen. Google-Trends-Messkurven sind hier noch nicht angebunden.</x-fa::notice>
    @if($meldung)<x-fa::notice tone="ok">{{ $meldung }}</x-fa::notice>@endif
    @if($fehler)<x-fa::notice tone="crit">{{ $fehler }}</x-fa::notice>@endif
    @if($darf)
    <x-fa::section :title="$quelleId ? 'Beobachtung bearbeiten' : 'Neues Thema beobachten'">
        <form wire:submit="speichern" class="flex flex-col gap-4" data-suchauftrag-form>
            <x-fa::field label="Thema" for="beob-name"><x-fa::input id="beob-name" wire:model="form.name" placeholder="Alkoholfreie Getränke" /></x-fa::field>
            <x-fa::field label="Suchbegriffe" for="beob-begriffe" hint="Ein Begriff pro Zeile oder durch Kommas getrennt, z. B. Mocktails, alkoholfreie Cocktails, Zero Proof. Mehrere Wörter werden als zusammenhängende Phrase gesucht.">
                <textarea id="beob-begriffe" wire:model="form.begriffe" rows="3" class="w-full rounded border border-[var(--fa-line)] p-2"></textarea>
            </x-fa::field>
            <x-fa::field label="Ausschließen" for="beob-aus" optional hint="Begriffe oder Phrasen, die in der Google-Suche ausgeschlossen werden."><x-fa::input id="beob-aus" wire:model="form.ausschliessen" placeholder="Stellenangebote, Gewinnspiel" /></x-fa::field>
            <details @if($form['ausdruck'] !== '') open @endif>
                <summary>Erweiterter Suchausdruck</summary>
                <p>Bestehende komplexe Suchen bleiben hier erhalten. Ein Ausdruck ersetzt die Suchbegriffe oben. Zum Wechsel auf die einfache Begriffsliste dieses Feld leeren.</p>
                <x-fa::input wire:model="form.ausdruck" aria-label="Erweiterter Suchausdruck" />
            </details>
            <div class="flex flex-wrap gap-3">
                <x-fa::field label="Markt" for="beob-markt"><x-fa::select id="beob-markt" wire:model="form.markt" :options="\Platform\FoodAlchemist\Support\GoogleNewsSuche::MAERKTE" /></x-fa::field>
                <x-fa::field label="Artikel aus" for="beob-zeit"><x-fa::select id="beob-zeit" wire:model="form.zeitraum" :options="\Platform\FoodAlchemist\Support\GoogleNewsSuche::ZEITRAEUME" /></x-fa::field>
                <x-fa::field label="Abrufrhythmus" for="beob-rhythmus"><x-fa::select id="beob-rhythmus" wire:model="form.rhythmus" :options="\Platform\FoodAlchemist\Support\TrendVokabular::RHYTHMEN" /></x-fa::field>
            </div>
            @if($errors->any())<x-fa::notice tone="crit"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-fa::notice>@endif
            <div class="flex flex-wrap gap-2">
                <x-fa::button type="button" wire:click="trefferAnsehen" wire:loading.attr="disabled">Treffer ansehen</x-fa::button>
                <x-fa::button type="submit" variant="primary" wire:loading.attr="disabled">{{ $quelleId ? 'Änderungen speichern' : 'Beobachtung starten' }}</x-fa::button>
                @if($quelleId)<x-fa::button type="button" wire:click="neu">Neues Thema</x-fa::button>@endif
                <span wire:loading role="status">Wird verarbeitet …</span>
            </div>
        </form>
    </x-fa::section>
    @endif
    @if($vorschau !== null)
    <x-fa::section title="Treffer-Vorschau">
        <p>{{ $vorschau['anzahl'] }} Artikel gefunden, {{ $vorschau['gefiltert'] }} durch Quellen-/Teamfilter ausgeschlossen. Bis zu zehn Treffer; es wurde nichts in den Eingang übernommen.</p>
        <ul class="flex flex-col gap-2 mt-3">@forelse($vorschau['treffer'] as $treffer)
            <li><a href="{{ $treffer['url'] }}" target="_blank" rel="noopener noreferrer" class="underline">{{ $treffer['titel'] }}</a></li>
        @empty<li>Keine passenden Treffer. Versuche breitere Begriffe oder einen längeren Zeitraum.</li>@endforelse</ul>
    </x-fa::section>
    @endif
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    @forelse($auftraege as $q)
        @php $suche = \Platform\FoodAlchemist\Support\GoogleNewsSuche::lesen($q->url); @endphp
        <article class="fa-surface p-4 flex flex-col gap-3" wire:key="suchauftrag-{{ $q->id }}">
            <div class="flex justify-between gap-2"><h3 class="font-semibold">{{ $q->name }}</h3><x-fa::badge>{{ $q->aktiv && $q->rhythmus !== 'aus' ? 'Aktiv' : 'Pausiert' }}</x-fa::badge></div>
            <p class="whitespace-pre-line">{{ $suche['begriffe'] ?: $suche['ausdruck'] }}</p>
            @if($suche['ausschliessen'])<p>Ausgeschlossen: {{ str_replace("\n", ', ', $suche['ausschliessen']) }}</p>@endif
            <p>{{ \Platform\FoodAlchemist\Support\GoogleNewsSuche::MAERKTE[$suche['markt']] }} · {{ \Platform\FoodAlchemist\Support\GoogleNewsSuche::ZEITRAEUME[$suche['zeitraum']] }} · {{ \Platform\FoodAlchemist\Support\TrendVokabular::RHYTHMEN[$q->rhythmus] }}</p>
            <p>Letzter Abruf: {{ $q->letzter_abruf_at?->format('d.m.Y H:i') ?? 'noch nicht abgerufen' }}</p>
            @if($q->letzter_fehler)<x-fa::notice tone="warn">{{ $q->letzter_fehler }}</x-fa::notice>@endif
            @if($darf && (int) $q->team_id === (int) $teamId)<div class="flex gap-2">
                <x-fa::button wire:click="bearbeiten({{ $q->id }})">Bearbeiten</x-fa::button>
                <x-fa::button wire:click="pausieren({{ $q->id }})">{{ $q->aktiv ? 'Pausieren' : 'Fortsetzen' }}</x-fa::button>
            </div>@else<p>Vom übergeordneten Team bereitgestellt.</p>@endif
        </article>
    @empty<x-fa::empty title="Noch keine Themen" icon="heroicon-o-magnifying-glass">Lege oben ein Thema mit deinen Suchbegriffen an.</x-fa::empty>@endforelse
    </div>
</div>
