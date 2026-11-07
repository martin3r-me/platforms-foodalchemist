{{-- Team-Pinnwand (Observatorium zentral 07.11.2026): gemeinsam für alle im Team. Meldungen aus dem Observatorium + eigene Ideen. --}}
<div data-observatorium-pinnwand>
    @if($darfPinnen)
        <form class="fa-obs-briefing" wire:submit="ideeAnpinnen" data-pinnwand-idee>
            <h2>Eigene Idee anheften</h2>
            <p class="fa-obs-subtitle">Bleibt in eurem Team. Meldungen heftest du in der Detailansicht mit „An Team-Pinnwand“ an.</p>
            <label class="fa-obs-field search"><input type="text" wire:model="ideeTitel" maxlength="200" placeholder="Titel, z. B. Linsen-Bowl für die Mittagslinie" aria-label="Titel der Idee" required></label>
            <label class="fa-obs-field search"><textarea wire:model="ideeNotiz" maxlength="1000" rows="3" placeholder="Notiz (optional)" aria-label="Notiz"></textarea></label>
            <label class="fa-obs-field search"><input type="url" wire:model="ideeLink" maxlength="500" placeholder="Link (optional, https://…)" aria-label="Link"></label>
            <div class="fa-obs-actions"><button class="fa-obs-button primary" type="submit" wire:loading.attr="disabled">@svg('heroicon-o-plus')Anheften</button></div>
        </form>
    @endif
    @if($pins->isEmpty())
        <div class="fa-obs-empty"><h2>Eure Pinnwand ist noch leer.</h2><p>Heftet Meldungen aus dem Observatorium oder eigene Ideen an. Alle im Team sehen sie.</p></div>
    @else
        <div class="fa-obs-grid">
            @foreach($pins as $p)
                <article class="fa-obs-story" wire:key="pin-{{ $p->id }}" data-pinnwand-eintrag="{{ $p->id }}" x-data="{ notiz: @js($p->notiz ?? ''), bearbeiten: false }">
                    @if($p->eingang)
                        <span class="fa-obs-pill">Meldung · {{ $p->eingang->herausgeber ?: $p->eingang->quelle?->name }}</span>
                        <h3><button type="button" class="fa-obs-link" wire:click="oeffnen({{ $p->eingang->id }})">{{ $p->eingang->titel }}</button></h3>
                        <p>{{ \Illuminate\Support\Str::limit($p->eingang->zusammenfassung ?: $p->eingang->teaser, 180) }}</p>
                    @elseif($p->eingang_id)
                        <span class="fa-obs-pill">Meldung</span><p class="fa-obs-subtitle">Die Meldung ist nicht mehr im Observatorium.</p>
                    @else
                        <span class="fa-obs-pill idea">Eigene Idee</span>
                        <h3>{{ $p->titel }}</h3>
                        @if($p->link)<a class="fa-obs-link" href="{{ $p->link }}" target="_blank" rel="noopener noreferrer">Link öffnen @svg('heroicon-o-arrow-up-right', 'fa-obs-inline-icon', ['width' => 16, 'height' => 16])</a>@endif
                    @endif
                    <p x-show="!bearbeiten && notiz" x-text="notiz" class="fa-obs-subtitle"></p>
                    @if($darfPinnen)
                        <div x-show="bearbeiten" x-cloak><label class="fa-obs-field search"><textarea x-model="notiz" maxlength="1000" rows="3" aria-label="Notiz"></textarea></label></div>
                        <div class="fa-obs-actions">
                            <button type="button" class="fa-obs-link" x-show="!bearbeiten" x-on:click="bearbeiten = true">Notiz</button>
                            <button type="button" class="fa-obs-link" x-show="bearbeiten" x-cloak x-on:click="$wire.pinNotiz({{ $p->id }}, notiz); bearbeiten = false">Speichern</button>
                            <button type="button" class="fa-obs-link" wire:click="pinEntfernen({{ $p->id }})" wire:confirm="Von der Team-Pinnwand nehmen?">Abnehmen</button>
                        </div>
                    @endif
                    <div class="fa-obs-meta"><span>{{ $p->created_at?->format('d.m.Y') }}</span></div>
                </article>
            @endforeach
        </div>
    @endif
</div>
