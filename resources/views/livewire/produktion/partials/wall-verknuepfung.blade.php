{{-- Spec 83 M2 — Verknüpfung Ober-/Unterrezept an der Karte des Küchenmonitors. Quelle: Auftragsdaten
     (Zutaten-Snapshot `typ = sub` + `ref_recipe_id`, Unterrezept als eigene Zeile im selben Auftrag), siehe Tagesplan::wallVerknuepfungen.
     Erwartet: $verknuepfung = $wallVerknuepfung[$z->id] ?? null. Oberrezept: „braucht“ mit Haken-Stand (Haken als Icon, offen als Strich); Unterrezept: „für“. --}}
@php($v = $verknuepfung ?? [])
@if(! empty($v['braucht']))
    <p class="mt-1.5 text-sm text-[var(--fa-ink-2)]" data-tagesplan-wall-braucht>
        <span class="font-semibold">braucht:</span>
        @foreach($v['braucht'] as $k)
            <span class="whitespace-normal break-words {{ $k['erledigt'] ? 'text-[var(--fa-ok)]' : '' }}" data-tagesplan-wall-braucht-eintrag="{{ $k['id'] }}">{{ $k['name'] }} <span class="inline-flex items-center font-bold" aria-label="{{ $k['erledigt'] ? 'erledigt' : 'offen' }}" data-braucht-stand="{{ $k['erledigt'] ? 'erledigt' : 'offen' }}">@if($k['erledigt'])@svg('heroicon-m-check', 'w-4 h-4')@else–@endif</span></span>@if(! $loop->last)<span class="text-[var(--fa-ink-3)]"> · </span>@endif
        @endforeach
    </p>
@endif
@if(! empty($v['fuer']))
    <p class="mt-1.5 text-sm text-[var(--fa-ink-2)]" data-tagesplan-wall-fuer><span class="font-semibold">für:</span> {{ collect($v['fuer'])->pluck('name')->implode(' · ') }}</p>
@endif
