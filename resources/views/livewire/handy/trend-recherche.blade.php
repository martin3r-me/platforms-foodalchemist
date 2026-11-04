@php use Platform\FoodAlchemist\Support\TrendVokabular as V; @endphp
{{-- Mobile Recherche-Übersicht: ausschließlich lesen, vorhandene Team-Sicht und Filter. --}}
<div class="space-y-3 min-w-0" data-fa-handy-recherche="{{ $reiter }}">
    <p class="{{ $leise }}">Am Handy kannst du den Stand prüfen. Suchbegriffe und Einstellungen pflegst du am Computer oder per MCP.</p>
    @if(in_array($reiter, ['quellen', 'beobachten'], true))
        @forelse($handyQuellen as $q)
            @php
                $suchauftrag = \Platform\FoodAlchemist\Support\GoogleNewsSuche::lesen($q->url);
                $begriffe = $suchauftrag ? \Platform\FoodAlchemist\Support\GoogleNewsSuche::anzeige($suchauftrag) : null;
            @endphp
            <article class="rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-3 space-y-2 min-w-0 wrap-anywhere" wire:key="hr-{{ $reiter }}-{{ $q->id }}">
                <div class="flex items-start justify-between gap-2">
                    <h2 class="font-semibold">{{ $q->name }}</h2>
                    <x-fa::badge :tone="$q->aktiv && $q->rhythmus !== 'aus' ? 'ok' : 'neutral'">{{ $q->aktiv && $q->rhythmus !== 'aus' ? 'Aktiv' : 'Pausiert' }}</x-fa::badge>
                </div>
                @if($begriffe)
                    <div class="flex flex-wrap gap-1.5">@foreach($begriffe['begriffe'] as $wort)<x-fa::badge class="max-w-full !whitespace-normal !h-auto">{{ $wort }}</x-fa::badge>@endforeach</div>
                    @if($begriffe['kontext'])<p class="{{ $leise }}">Kontext: {{ implode(' · ', $begriffe['kontext']) }}</p>@endif
                @endif
                <p class="{{ $leise }}">{{ V::QUELLEN[$q->art] ?? $q->art }} · {{ V::RHYTHMEN[$q->rhythmus] ?? $q->rhythmus }} · {{ $q->abruf_weg === 'mcp' ? 'MCP-Sichtung erforderlich' : 'Automatischer Plattform-Abruf' }}</p>
                <p class="{{ $leise }}">Letzter Abruf: {{ $q->letzter_abruf_at?->format('d.m.Y H:i') ?? 'noch nicht abgerufen' }} · {{ $q->gefiltert_letzter_abruf }} herausgefiltert</p>
                @if($q->letzter_fehler)<x-fa::notice tone="warn">{{ $q->letzter_fehler }}</x-fa::notice>@endif
                <details class="{{ $leise }}">
                    <summary class="min-h-11 flex items-center cursor-pointer">Suchdetails und Filter</summary>
                    <p class="break-words">{{ $suchauftrag ? ($suchauftrag['ausdruck'] ?: str_replace("\n", ' · ', $suchauftrag['begriffe'])) : $q->url }}</p>
                    <p>Stichworte: {{ implode(', ', $q->stichworte_ein ?? []) ?: 'Team-Standard' }}</p>
                    @if($q->stichworte_aus)<p>Ausgeschlossen: {{ implode(', ', $q->stichworte_aus) }}</p>@endif
                </details>
            </article>
        @empty<x-fa::empty compact title="Keine passenden Einträge" icon="heroicon-o-magnifying-glass">Prüfe deinen Suchbegriff.</x-fa::empty>@endforelse
    @elseif($reiter === 'eingang')
        <p class="{{ $leise }}">Die neuesten 50 passenden Einträge einschließlich verworfener Artikel. Vorschläge werden erst nach Prüfung zu Trends.</p>
        @forelse($handyEingang as $e)
            <article class="rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)] bg-[var(--fa-surface)] p-3 space-y-2 min-w-0 wrap-anywhere" wire:key="he-{{ $e->id }}">
                <x-fa::badge>{{ V::EINGANG_STATUS[$e->status] ?? $e->status }}</x-fa::badge>
                <h2 class="font-semibold">{{ $e->titel }}</h2>
                <p class="{{ $leise }}">{{ $e->quelle?->name ?? 'Manuell eingereicht' }}</p>
                @if($e->zusammenfassung)<p>{{ $e->zusammenfassung }}</p>@endif
                @if($e->thema)<p class="{{ $leise }}">Entwicklung: {{ $e->thema }}</p>@endif
                @if($e->begruendung)<p class="{{ $leise }}">{{ $e->begruendung }}</p>@endif
                <a href="{{ $e->link() }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center min-h-11 text-[var(--fa-accent)] underline">Original öffnen</a>
            </article>
        @empty<x-fa::empty compact title="Noch keine passenden Artikel" icon="heroicon-o-inbox">Neue passende Funde erscheinen hier nach dem Abruf.</x-fa::empty>@endforelse
    @elseif($handyAutomatik)
        <x-fa::section title="Auswertungsweg">
            <p>{{ $handyAutomatik['ki_weg'] === 'extern' ? 'Externe KI-Aufträge über MCP' : 'Plattform-KI für automatische Abrufe' }}</p>
            <p class="{{ $leise }}">MCP-Einreichungen können externe KI-Aufträge erzeugen. Ein Assistent muss diese abholen und beantworten.</p>
        </x-fa::section>
        <x-fa::section title="Automatik-Status">
            <p>{{ \Platform\FoodAlchemist\Support\TrendAutomatikRegeln::STUFEN[$handyAutomatik['stufe']] }}</p>
            <p>{{ $handyAutomatik['schatten'] ? 'Schattenmodus: berechnet Vorschläge, schreibt keine Trends.' : 'Schattenmodus aus: Änderungen gemäß Automatik-Stufe möglich.' }}</p>
            <p class="{{ $leise }}">Trend-KI-Budget: {{ $handyAutomatik['ki_budget_eur'] !== null ? number_format($handyAutomatik['ki_budget_eur'], 2, ',', '.').' €' : 'Kein eigener Trend-Deckel' }}</p>
        </x-fa::section>
        <x-fa::section title="Vom Fund zum Radar">
            <ol class="list-decimal pl-5 space-y-2"><li>Abruf und Stichwortfilter</li><li>KI prüft Relevanz, Zusammenfassung und Thema</li><li>Funde zum gleichen erkannten Thema werden gebündelt</li><li>Regeln schlagen Hype oder Trend vor</li><li>Radar erst mit Einordnung, weiteren Belegen und freigegebenem Status</li></ol>
            <p class="{{ $leise }}">Google-Trends-Suchspitzen bestätigen einen Trend niemals allein.</p>
        </x-fa::section>
    @endif
</div>
