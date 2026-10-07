{{--
    Spec 33 · P5 — Betriebs-Status einer Ausgabeform: Status · Gültigkeitsfenster · Zuordnung.

    Ein Bauteil für alle drei Ausgabeformen (Foodbook, Speisekarte, Speiseplan). Sie halten ihre
    Formularfelder unterschiedlich — mal als `form.*`-Array, mal als flache Livewire-Properties —
    deshalb nimmt das Bauteil die `wire:model`-PFADE als Strings entgegen, statt eine Struktur
    vorzuschreiben. Ein Pfad, der `null` ist, blendet sein Feld aus (der Speiseplan hat kein
    eigenes Gültigkeitsfenster, er leitet es aus seinen Einträgen ab).

    Geschrieben wird NICHT hier, sondern über das `speichern` der jeweiligen Komponente und damit
    über ihren eigenen Service — dort hängen Team-Guard, Normalisierung und Audit.

    Nutzung:
        x-foodalchemist::ausgabe-status
            status-model="form.status"  von-model="form.gueltig_von"  bis-model="form.gueltig_bis"
            outlet-model="form.outlet_id"
            :betriebe="$betriebe"  :zustand="$fb->laufZustand()"  :grund="$fb->laufGrund()"
            :konflikt="$konflikt"  toggle="aktivUmschalten"
--}}
@props([
    'statusModel',
    'vonModel' => null,          {{-- null = Form hat kein eigenes Fenster (Speiseplan) --}}
    'bisModel' => null,
    'outletModel' => null,
    'betriebe' => null,          {{-- Collection|null — ohne gepflegte Betriebe kein Select --}}
    'zustand' => null,           {{-- laeuft|geplant|abgelaufen|entwurf|inaktiv|archiviert --}}
    'grund' => null,
    'konflikt' => null,          {{-- Hinweis, kein Verbot — Überschneidungen können gewollt sein --}}
    'toggle' => null,            {{-- Livewire-Methode für den Schnellschalter aktiv/inaktiv --}}
    'fensterHinweis' => null,    {{-- z. B. abgeleitetes Speiseplan-Fenster als Klartext --}}
])
@php
    $istAktiv = $zustand === 'laeuft' || $zustand === 'geplant' || $zustand === 'abgelaufen';
    // Farbe folgt dem Lauf-Zustand, nicht dem Status: „aktiv, aber abgelaufen" ist kein Erfolg.
    $zustandTon = [
        'laeuft' => 'ok', 'geplant' => 'info',
        'abgelaufen' => 'warn', 'inaktiv' => 'warn',
        'entwurf' => 'neutral', 'archiviert' => 'neutral',
    ];
    $zustandLabel = [
        'laeuft' => 'Läuft', 'geplant' => 'Geplant', 'abgelaufen' => 'Abgelaufen',
        'inaktiv' => 'Inaktiv', 'entwurf' => 'Entwurf', 'archiviert' => 'Archiviert',
    ];
@endphp

<div class="flex flex-col gap-3" data-ausgabe-status>

    {{-- Lauf-Zustand + Schnellschalter --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2 min-w-0">
            @if($zustand)
                <x-fa::badge :tone="$zustandTon[$zustand] ?? 'neutral'" data-ausgabe-zustand="{{ $zustand }}">{{ $zustandLabel[$zustand] ?? ucfirst((string) $zustand) }}</x-fa::badge>
            @endif
            @if($grund)<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $grund }}</span>@endif
        </div>

        @if($toggle)
            {{-- Ein Klick nimmt eine laufende Ausgabe vom Netz und zurück, ohne den Umweg über
                 den Status und ohne zu archivieren. --}}
            <x-fa::button size="sm" :icon="$istAktiv ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle'" wire:click="{{ $toggle }}" data-ausgabe-toggle>
                {{ $istAktiv ? 'Vom Netz nehmen' : 'Aktiv schalten' }}
            </x-fa::button>
        @endif
    </div>

    @if($konflikt)
        {{-- Hinweis, kein Verbot: zwei gleichzeitig laufende Ausgaben können gewollt sein
             (Übergangsphase, Sonderkarte). Die Übersicht führt sie trotzdem als Konflikt. --}}
        <x-fa::signal tone="warn" data-ausgabe-konflikt><span>{{ $konflikt }}</span></x-fa::signal>
    @endif

    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,11rem),1fr))] gap-3">
        <div class="col-span-full">
            <x-fa::choice :name="$statusModel" :live="false" label="Status" :options="\Platform\FoodAlchemist\Enums\AusgabeStatus::optionen()" data-ausgabe-status-select />
        </div>

        @if($vonModel)
            <x-fa::field label="Gültig ab">
                <x-fa::input type="date" wire:model="{{ $vonModel }}" aria-label="Gültig ab" />
            </x-fa::field>
            <x-fa::field label="Gültig bis">
                <x-fa::input type="date" wire:model="{{ $bisModel }}" aria-label="Gültig bis" />
            </x-fa::field>
        @elseif($fensterHinweis)
            <x-fa::field label="Zeitraum">
                <p class="pt-1.5 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">{{ $fensterHinweis }}</p>
            </x-fa::field>
        @endif

        @if($outletModel)
            <x-fa::field label="Betrieb" optional>
                @if($betriebe === null || $betriebe->isEmpty())
                    {{-- Ohne gepflegte Betriebe ist die Betriebsbrille leer — den Weg dorthin
                         nennen, statt ein totes Auswahlfeld zu zeigen. --}}
                    <p class="pt-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
                        Noch kein Betrieb angelegt.
                        <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'betriebe']) }}"
                           class="text-[var(--fa-accent)] hover:underline" wire:navigate>Betriebe in den Einstellungen anlegen</a>
                    </p>
                @else
                    <x-fa::select wire:model="{{ $outletModel }}" placeholder="Kein Betrieb" aria-label="Betrieb" data-ausgabe-outlet>
                        @foreach($betriebe as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </x-fa::select>
                @endif
            </x-fa::field>
        @endif
    </div>

    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
        Betrieb und CRM-Kunde sind beide optional. Eine Ausgabe ohne beides erscheint im Controlling
        unter „ohne Zuordnung“. Sie ist nicht verloren, aber in keiner der beiden Ansichten.
    </p>
</div>
