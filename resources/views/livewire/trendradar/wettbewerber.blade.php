@php
    use Platform\FoodAlchemist\Support\ObservatoriumInhalt as O;
    use Platform\FoodAlchemist\Services\WettbewerbService as W;
@endphp
<div class="fa-wett" data-wettbewerber-research>
    @include('foodalchemist::livewire.trendradar.wettbewerber-styles')
    <div class="fa-wett-toolbar"><p>Profile pflegen, Firmenbezug prüfen und bestätigte Meldungen veröffentlichen.</p>@if($darf && ! $istHandy)<button type="button" class="fa-btn" wire:click="neu">+ Wettbewerber anlegen</button>@endif<a href="{{ route('foodalchemist.observatorium.index', ['blick' => 'wettbewerb']) }}" wire:navigate>Wettbewerb im Observatorium →</a></div>
    @if($meldung)<p role="status" class="fa-wett-notice">{{ $meldung }}</p>@endif
    @if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    @if($editorOffen && $darf && ! $istHandy)
        <form wire:submit="speichern" class="fa-wett-panel">
            <h2>{{ $editId ? 'Wettbewerber bearbeiten' : 'Wettbewerber anlegen' }}</h2>
            <div class="fa-wett-form">
                <label>Name<input wire:model="form.name" required maxlength="190"></label>
                <label>Website<input wire:model="form.website" type="url" placeholder="https://…"></label>
                <label>Marken & Namensvarianten<textarea wire:model="form.aliases" rows="2" placeholder="Mit Komma oder neuer Zeile trennen"></textarea></label>
                <label>Suchkontext (optional)<textarea wire:model="form.suchbegriffe" rows="2" placeholder="Zum Beispiel Catering, Gastronomie"></textarea><small>Die Firmennamen werden mit mindestens einem Kontextbegriff kombiniert.</small></label>
                <label>Ausschlüsse<textarea wire:model="form.ausschliessen" rows="2" placeholder="Mehrdeutige oder unpassende Begriffe"></textarea></label>
                <label>Branchen<select multiple wire:model="form.branchen" size="5">@foreach(O::BRANCHEN as $key => $name)<option value="{{ $key }}">{{ $name }}</option>@endforeach</select></label>
                <label>Region<select wire:model="form.region"><option value="">Region offen</option><option value="DE">Deutschland</option><option value="AT">Österreich</option><option value="CH">Schweiz</option></select></label>
                <label>Gebiet / Schwerpunkt<input wire:model="form.gebiet" maxlength="190" placeholder="Zum Beispiel Rheinland, bundesweit"></label>
                <label>Offizieller Presse-RSS (optional)<input type="url" wire:model="form.presse_feed" placeholder="https://…/feed"><small>Nur einen tatsächlichen Feed eintragen. Leeren pausiert und löst einen bisherigen Pressefeed.</small></label>
                <label>Weitere bestehende Quellen<select multiple wire:model="form.quellen_ids" size="5">@foreach($quellen as $q)<option value="{{ $q->id }}">{{ $q->name }}</option>@endforeach</select></label>
                <label>Notiz zum Profil<textarea wire:model="form.notiz" rows="3"></textarea><small>Eigene Einschätzung, kein Quellenfakt.</small></label>
                <div><label class="fa-wett-check"><input type="checkbox" wire:model="form.aktiv"> Profil aktiv: neue Zuordnungsvorschläge zulassen</label><label class="fa-wett-check"><input type="checkbox" wire:model="form.rss_aktiv"> Google News und Presse-RSS automatisch abrufen</label><small>Neue Suchen starten pausiert. RSS ist kostenlos; die vorhandene nachgelagerte KI-Auswertung kann das bestehende Budget nutzen.</small><label>Abrufrhythmus<select wire:model="form.rhythmus"><option value="taeglich">Täglich</option><option value="woechentlich">Wöchentlich</option><option value="monatlich">Monatlich</option><option value="aus">Aus</option></select></label></div>
            </div>
            <div class="fa-wett-actions"><button type="submit" class="fa-btn" wire:loading.attr="disabled">Profil speichern</button><button type="button" class="fa-btn" wire:click="$set('editorOffen', false)">Abbrechen</button></div>
        </form>
    @endif
    <div class="fa-wett-profile" aria-label="Wettbewerberprofile">
        @forelse($profile as $p)<button class="fa-wett-profil {{ $profilId === $p->id ? 'is-active' : '' }}" type="button" wire:click="waehlen({{ $p->id }})" @if($profilId === $p->id) aria-current="true" @endif><strong>{{ $p->name }}</strong><span>{{ $p->region ?: 'Region offen' }}{{ $p->gebiet ? ' · '.$p->gebiet : '' }}</span><small>{{ $p->aktiv ? 'Profil aktiv' : 'Profil pausiert' }} · RSS {{ $p->rss_aktiv && $p->aktiv ? 'aktiv' : 'pausiert' }}</small></button>@empty<div class="fa-wett-panel"><h2>Noch keine Wettbewerberprofile.</h2><p>Lege Unternehmen mit Marken, Website und Suchbegriffen an. Vorschläge werden vor Veröffentlichung geprüft.</p></div>@endforelse
    </div>
    @if($profil)
        <section class="fa-wett-panel" data-wettbewerber-profil="{{ $profil->id }}"><div class="fa-wett-toolbar"><h2>{{ $profil->name }}</h2>@if($darf && ! $istHandy && (int)$profil->team_id === (int)$teamId)<button type="button" class="fa-btn" wire:click="bearbeiten({{ $profil->id }})">Profil bearbeiten</button><button type="button" class="fa-btn" wire:click="bestandPruefen" wire:loading.attr="disabled">Bestand auf Firmenbezug prüfen</button>@endif</div>
            @if($profil->website)<a href="{{ $profil->website }}" target="_blank" rel="noopener noreferrer">Unternehmenswebsite öffnen ↗</a>@endif
            <p>{{ implode(' · ', array_map(fn($b) => O::BRANCHEN[$b] ?? $b, $profil->branchen ?? [])) }}</p>
            <p><strong>Namensvarianten:</strong> {{ implode(', ', $profil->aliases ?? []) ?: 'Keine zusätzlichen Varianten' }}<br><strong>Suchkontext:</strong> {{ implode(', ', $profil->suchbegriffe ?? []) ?: 'Ohne zusätzlichen Kontext' }}</p>
            @if($profil->notiz)<p><strong>Eigene Profilnotiz:</strong> {{ $profil->notiz }}</p>@endif
            <small>Neue Eingänge werden kostenlos nach Namen, Domain und zugeordneten Quellen geprüft. Vorschläge verändern weder Trendfreigaben noch persönliche Merklisten.</small>
        </section>
        <div class="fa-wett-toolbar"><label>Zuordnungen<select wire:model.live="status"><option value="vorgeschlagen">Zu prüfen</option><option value="bestaetigt">Bestätigt</option><option value="abgelehnt">Abgelehnt</option><option value="">Alle</option></select></label><span>{{ $funde->total() }} Meldungen</span></div>
        @forelse($funde as $f)@php $e = $f->artikel; @endphp
            <article class="fa-wett-panel" wire:key="wf-{{ $f->id }}" data-wettbewerb-fund="{{ $f->id }}"><small>{{ $f->status === 'vorgeschlagen' ? 'Zuordnungsvorschlag · noch ungeprüft' : ($f->status === 'bestaetigt' ? 'Firmenbezug bestätigt' : 'Zuordnung abgelehnt') }} · {{ $e->veroeffentlicht_am?->format('d.m.Y') ?? 'Datum offen' }}</small><h3>{{ $e->titel }}</h3><p>{{ $e->zusammenfassung ?: $e->teaser }}</p><small>{{ $e->herausgeber ?: $e->quelle?->name }}</small><p><strong>Zuordnungsgrund:</strong> {{ $f->grund }}</p><a href="{{ $e->link() }}" target="_blank" rel="noopener noreferrer">Originalquelle öffnen ↗</a>
                @if($darf && ! $istHandy && (int)$f->team_id === (int)$teamId)
                    <div class="fa-wett-form"><label>Marktbewegung<select wire:model="bewertung.{{ $e->id }}.bewegung">@foreach(W::BEWEGUNGEN as $k => $n)<option value="{{ $k }}" @selected($f->bewegung === $k)>{{ $n }}</option>@endforeach</select></label><label>Eigene Einordnung<textarea rows="2" wire:model="bewertung.{{ $e->id }}.einordnung" placeholder="Bedeutung für uns; klar von der Meldung getrennt">{{ $f->einordnung }}</textarea></label></div>
                    <div class="fa-wett-actions"><button type="button" class="fa-btn" wire:click="entscheiden({{ $e->id }}, 'bestaetigt')">{{ $f->status === 'bestaetigt' ? 'Bewertung speichern' : 'Firmenbezug bestätigen' }}</button><button type="button" class="fa-btn" wire:click="entscheiden({{ $e->id }}, 'abgelehnt')">Zuordnung ablehnen</button>@if($f->status !== 'vorgeschlagen')<button type="button" class="fa-btn" wire:click="entscheiden({{ $e->id }}, 'vorgeschlagen')">Erneut prüfen</button>@endif</div>
                @elseif($f->einordnung)<p><strong>Eigene Einordnung:</strong> {{ $f->einordnung }}</p>@endif
                @if($f->geprueft_at)<small>Zuletzt redaktionell geprüft: {{ $f->geprueft_at->format('d.m.Y H:i') }}</small>@endif
            </article>
        @empty<div class="fa-wett-panel"><p>Keine Meldungen für diese Auswahl. Prüfe den Bestand oder warte auf neue Quellenabrufe.</p></div>@endforelse
        @if($funde->hasPages())<div class="fa-wett-actions"><button type="button" class="fa-btn" wire:click="previousPage('wettbewerbSeite')" @disabled($funde->onFirstPage())>Zurück</button><span>Seite {{ $funde->currentPage() }} von {{ $funde->lastPage() }}</span><button type="button" class="fa-btn" wire:click="nextPage('wettbewerbSeite')" @disabled(! $funde->hasMorePages())>Weiter</button></div>@endif
        @if($darf && ! $istHandy)<section class="fa-wett-panel"><h3>Weitere Meldung manuell zuordnen</h3><label>Im eigenen Eingang suchen<input wire:model.live.debounce.300ms="artikelSuche" type="search" placeholder="Mindestens drei Zeichen"></label>@foreach($manuelleTreffer as $e)<div class="fa-wett-toolbar"><span>{{ $e->titel }}</span><button type="button" class="fa-btn" wire:click="entscheiden({{ $e->id }}, 'bestaetigt')">Firmenbezug bestätigen</button></div>@endforeach</section>@endif
    @elseif($profile->isNotEmpty())<p>Wähle ein Unternehmen, um Fundstellen und Zuordnungen zu prüfen.</p>@endif
</div>
