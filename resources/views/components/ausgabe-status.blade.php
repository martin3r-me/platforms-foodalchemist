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
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($istAktiv = $zustand === 'laeuft' || $zustand === 'geplant' || $zustand === 'abgelaufen')
{{-- Farbe folgt dem Lauf-Zustand, nicht dem Status: „aktiv, aber abgelaufen" ist kein Erfolg. --}}
@php($zustandPill = [
    'laeuft' => $variantPill['success'], 'geplant' => $variantPill['info'],
    'abgelaufen' => $variantPill['warning'], 'inaktiv' => $variantPill['warning'],
    'entwurf' => $variantPill['secondary'], 'archiviert' => $variantPill['secondary'],
])
@php($zustandLabel = [
    'laeuft' => 'Läuft', 'geplant' => 'Geplant', 'abgelaufen' => 'Abgelaufen',
    'inaktiv' => 'Inaktiv', 'entwurf' => 'Entwurf', 'archiviert' => 'Archiviert',
])

<div class="space-y-3" data-ausgabe-status>

    {{-- Lauf-Zustand + Schnellschalter --}}
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div class="flex items-center gap-2">
            @if($zustand)
                <span class="{{ $pill }} {{ $zustandPill[$zustand] ?? $variantPill['secondary'] }}" data-ausgabe-zustand="{{ $zustand }}">
                    {{ $zustandLabel[$zustand] ?? $zustand }}
                </span>
            @endif
            @if($grund)<span class="text-[11px] text-gray-500">{{ $grund }}</span>@endif
        </div>

        @if($toggle)
            {{-- Ein Klick nimmt eine laufende Ausgabe vom Netz und zurück, ohne den Umweg über
                 das Status-Dropdown und ohne zu archivieren. --}}
            <button type="button" wire:click="{{ $toggle }}" class="{{ $btnGhostXs }} {{ $istAktiv ? 'text-amber-700' : 'text-emerald-700' }}"
                    data-ausgabe-toggle>
                {{ $istAktiv ? 'Vom Netz nehmen' : 'Aktiv schalten' }}
            </button>
        @endif
    </div>

    @if($konflikt)
        {{-- Hinweis, kein Verbot: zwei gleichzeitig laufende Ausgaben können gewollt sein
             (Übergangsphase, Sonderkarte). Die Übersicht führt sie trotzdem als Konflikt. --}}
        <p class="text-[11px] text-amber-700" data-ausgabe-konflikt>{{ $konflikt }}</p>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div>
            <label class="{{ $label }} block mb-1">Status</label>
            <select wire:model="{{ $statusModel }}" class="{{ $input }}" data-ausgabe-status-select>
                @foreach(\Platform\FoodAlchemist\Enums\AusgabeStatus::optionen() as $v => $l)
                    <option value="{{ $v }}">{{ $l }}</option>
                @endforeach
            </select>
        </div>

        @if($vonModel)
            <div>
                <label class="{{ $label }} block mb-1">Gültig ab</label>
                <input type="date" wire:model="{{ $vonModel }}" class="{{ $input }}" />
            </div>
            <div>
                <label class="{{ $label }} block mb-1">Gültig bis</label>
                <input type="date" wire:model="{{ $bisModel }}" class="{{ $input }}" />
            </div>
        @elseif($fensterHinweis)
            <div class="md:col-span-2">
                <label class="{{ $label }} block mb-1">Zeitraum</label>
                <p class="text-xs text-gray-600 pt-1.5">{{ $fensterHinweis }}</p>
            </div>
        @endif

        @if($outletModel)
            <div>
                <label class="{{ $label }} block mb-1">Betrieb</label>
                @if($betriebe === null || $betriebe->isEmpty())
                    {{-- Ohne gepflegte Betriebe ist die Betriebsbrille leer — den Weg dorthin
                         nennen, statt ein totes Select zu zeigen. --}}
                    <p class="text-[11px] text-gray-500 pt-1.5">
                        Noch kein Betrieb angelegt —
                        <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'betriebe']) }}"
                           class="text-violet-600 hover:underline" wire:navigate>in den Einstellungen pflegen</a>.
                    </p>
                @else
                    <select wire:model="{{ $outletModel }}" class="{{ $input }}" data-ausgabe-outlet>
                        <option value="">– kein Betrieb –</option>
                        @foreach($betriebe as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        @endif

    </div>

    <p class="text-[10px] text-gray-500">
        Betrieb und CRM-Kunde sind beide optional. Eine Ausgabe ohne beides erscheint im Controlling
        unter „ohne Zuordnung" — sie ist nicht verloren, aber in keiner der beiden Brillen.
    </p>
</div>
