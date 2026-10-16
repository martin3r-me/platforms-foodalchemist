{{-- Reiter „Produktpass" am Lieferantenartikel: Datei hochladen, Versionen, gelesene Felder, Übernahme, Allergen-Bestätigung.
     Allergene werden NIE direkt geschrieben — erst die Bestätigung trägt sie ein; bis dahin gilt der bisherige Stand. --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $statusText = ['hochgeladen' => 'wartet', 'liest' => 'wird gelesen', 'gelesen' => 'gelesen', 'fehler' => 'Fehler', 'verworfen' => 'verworfen'];
    $statusTon = ['gelesen' => 'ok', 'fehler' => 'crit', 'liest' => 'info', 'hochgeladen' => 'info'];
    $quelleText = ['produktpass' => 'Produktpass', 'websuche' => 'Websuche'];
    $wertText = function ($w, ?string $einheit = null) {
        if (is_float($w)) { $w = rtrim(rtrim(number_format($w, 2, ',', '.'), '0'), ','); }
        $t = Str::limit((string) $w, 140);
        return $einheit ? $t.' '.$einheit : $t;
    };
    $aktuellText = fn ($v) => $v === null ? '—' : (is_float($v) ? rtrim(rtrim(number_format($v, 2, ',', '.'), '0'), ',') : Str::limit((string) $v, 80));
    $allergenText = ['enthalten' => 'enthalten', 'spuren' => 'Spuren', 'nicht_enthalten' => 'nicht enthalten', 'unbekannt' => 'unbekannt'];
    $angezeigt = $stand['angezeigt'];
    $abweichungText = function (array $a, ?string $w) use ($allergenText) {
        if ($w === null || $w === '') { return 'keine Angabe'; }
        if ($a['objekt'] === 'item_allergen' && $a['feld'] === 'details') {
            return collect((array) json_decode($w, true))->map(fn ($v, $k) => $k.' ('.($allergenText[$v] ?? $v).')')->implode(', ') ?: 'keine Angabe';
        }
        if ($a['objekt'] === 'item_allergen') { return $allergenText[$w] ?? $w; }
        if ($a['objekt'] === 'item_declaration') { return match ((int) $w) { 3 => 'ja', 1 => 'nein', default => 'unbekannt' }; }
        return Str::limit($w, 160);
    };
@endphp
<div class="flex flex-col gap-4" data-produktpass-panel @if($laeuft) wire:poll.4s @endif>
    @if($fehler)
        <x-fa::notice tone="crit" data-produktpass-fehler>{{ $fehler }}</x-fa::notice>
    @endif
    @if($hinweis)
        <x-fa::notice tone="ok" data-produktpass-hinweis>{{ $hinweis }}</x-fa::notice>
    @endif

    <x-fa::section title="Produktpass" icon="heroicon-o-document-text"
        description="PDF oder Foto des Produktpasses bzw. Datenblatts. Die KI liest Allergene, Zusatzstoffe, Nährwerte, Zutatenliste, Gebinde, Haltbarkeit, Herkunft und Marke. Was nicht im Dokument steht, bleibt leer.">
        @if($darfEdit)
            <div class="flex flex-wrap items-end gap-3" data-produktpass-upload>
                <input type="file" wire:model="datei" accept=".pdf,image/jpeg,image/png,image/webp" aria-label="Produktpass wählen"
                    class="fa-control h-9 text-[length:var(--fa-text-md)] min-w-0">
                <x-fa::button variant="primary" icon="heroicon-m-arrow-up-tray" wire:click="hochladen" wire:loading.attr="disabled"
                    wire:target="hochladen,datei" data-produktpass-hochladen>Hochladen und lesen</x-fa::button>
                @if($webQuelleDa)
                    <x-fa::button icon="heroicon-m-magnifying-glass" wire:click="recherchieren" wire:loading.attr="disabled" wire:target="recherchieren"
                        title="Ohne Produktpass im Web recherchieren — nur Vorschläge mit Quelle" data-produktpass-recherchieren>Im Web recherchieren</x-fa::button>
                @endif
            </div>
        @else
            <x-fa::notice tone="info" data-produktpass-nur-lesen>Dieser Artikel kommt aus einem übergeordneten Katalog. Den Produktpass ansehen und herunterladen kannst du, hochladen und übernehmen kann nur das Team, dem er gehört.</x-fa::notice>
        @endif

        @if($stand['versionen'] === [])
            <x-fa::empty compact icon="heroicon-o-document-text" title="Noch kein Produktpass" data-produktpass-leer>
                Lade den Produktpass hoch, dann stehen die gelesenen Werte hier zur Prüfung.
            </x-fa::empty>
        @else
            <table class="fa-table" data-produktpass-versionen>
                <thead><tr><th>Version</th><th>Datum</th><th>Quelle</th><th>Datei</th><th>Stand</th><th class="text-right">Aktionen</th></tr></thead>
                <tbody>
                @foreach($stand['versionen'] as $v)
                    <tr wire:key="pp-v-{{ $v['id'] }}" @class(['bg-[var(--fa-accent-soft)]' => ($angezeigt['id'] ?? null) === $v['id']]) data-produktpass-version="{{ $v['id'] }}">
                        <td class="tabular-nums">V{{ $v['version'] }}</td>
                        <td class="tabular-nums">{{ $v['datum'] }}</td>
                        <td>{{ $quelleText[$v['quelle']] ?? $v['quelle'] }}</td>
                        <td class="min-w-0">
                            @if($v['datei'] && ($downloadUrls[$v['id']] ?? null))
                                <a href="{{ $downloadUrls[$v['id']] }}" target="_blank" rel="noopener" class="underline text-[var(--fa-accent)] truncate" data-produktpass-download>{{ Str::limit($v['datei'], 36) }}</a>
                            @else — @endif
                        </td>
                        <td>
                            <x-fa::badge :tone="$statusTon[$v['status']] ?? 'neutral'">{{ $statusText[$v['status']] ?? $v['status'] }}</x-fa::badge>
                            @if($v['status'] === 'fehler' && $v['fehler'])<span class="{{ $leise }} block max-w-xs">{{ Str::limit($v['fehler'], 120) }}</span>@endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <x-fa::button size="sm" variant="ghost" wire:click="anzeigen({{ $v['id'] }})">Anzeigen</x-fa::button>
                            @if($darfEdit && $v['datei'] && in_array($v['status'], ['fehler', 'gelesen'], true))
                                <x-fa::button size="sm" variant="ghost" wire:click="erneutLesen({{ $v['id'] }})">Erneut lesen</x-fa::button>
                            @endif
                            @if($darfEdit)
                                <x-fa::button size="sm" variant="ghost" wire:click="verwerfen({{ $v['id'] }})" wire:confirm="Diese Version verwerfen? Bereits übernommene Werte bleiben stehen.">Verwerfen</x-fa::button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </x-fa::section>

    @if($abweichungen !== [])
        <x-fa::section title="Necta-Abweichungen" icon="heroicon-o-arrows-right-left" meta="{{ count($abweichungen) }} offen" data-produktpass-abweichungen
            description="Necta meldet an diesen Feldern etwas anderes als der Produktpass. Der Produktpass-Wert gilt, bis du entscheidest. Ist der Pass veraltet, lade oben einen neueren hoch.">
            <table class="fa-table">
                <thead><tr><th>Feld</th><th>Produktpass (gilt)</th><th>Necta</th><th class="text-right">Entscheidung</th></tr></thead>
                <tbody>
                @foreach($abweichungen as $a)
                    <tr wire:key="pp-abw-{{ $a['id'] }}" data-produktpass-abweichung="{{ $a['id'] }}">
                        <td>{{ $a['label'] }}@if($a['kritisch']) <x-fa::badge tone="crit">Kennzeichnung</x-fa::badge>@endif</td>
                        <td>{{ $abweichungText($a, $a['pass']) }}</td>
                        <td class="{{ $leise }}">{{ $abweichungText($a, $a['necta']) }}</td>
                        <td class="text-right whitespace-nowrap">
                            @if($darfEdit)
                                <x-fa::button size="sm" variant="ghost" wire:click="passBehalten({{ $a['id'] }})" data-produktpass-pass-behalten>Pass behalten</x-fa::button>
                                <x-fa::button size="sm" wire:click="nectaUebernehmen({{ $a['id'] }})"
                                    wire:confirm="Den Necta-Wert eintragen? Der Produktpass schützt dieses Feld danach nicht mehr." data-produktpass-necta-uebernehmen>Necta übernehmen</x-fa::button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </x-fa::section>
    @endif

    @if($angezeigt !== null && $angezeigt['status'] === 'gelesen')
        <x-fa::section title="Gelesene Werte" icon="heroicon-o-clipboard-document-check" meta="V{{ $angezeigt['version'] }} · {{ $quelleText[$angezeigt['quelle']] ?? $angezeigt['quelle'] }}"
            description="Ab {{ rtrim(rtrim(number_format($schwelle * 100, 0), '0'), ',') }} % Sicherheit trägt die KI Nährwerte, Zutatenliste, Zusatzstoffe und Haltbarkeit direkt ein, Gebinde nur in leere Felder. Alles andere ist ein Vorschlag — übernommen wird per Klick.">
            <x-slot:actions>
                @if($darfEdit && $angezeigt['quelle'] === 'produktpass')
                    <x-fa::button size="sm" variant="ghost" wire:click="versionUebernehmen"
                        title="Wendet die automatischen Regeln dieser Version erneut an und ersetzt dabei Werte, die eine ältere Version geschrieben hat. Für einzelne Vorschläge: „Alle Vorschläge übernehmen“ bzw. der Knopf je Zeile."
                        data-produktpass-version-uebernehmen>Als aktuelle Version übernehmen</x-fa::button>
                @endif
                @if($darfEdit && $vorschau && (count($vorschau['leer']) + count($vorschau['abweichend'])) > 0)
                    @if($vorschau['abweichend'] === [])
                        <x-fa::button size="sm" variant="primary" icon="heroicon-m-check" wire:click="alleUebernehmen(false)" data-produktpass-alle-uebernehmen>Alle Vorschläge übernehmen ({{ count($vorschau['leer']) }})</x-fa::button>
                    @else
                        @php
                            $labelVon = fn (string $k) => collect($stand['felder'])->firstWhere('key', $k)['label'] ?? $k;
                            $frage = count($vorschau['leer']).' Vorschläge gehen in leere Felder. '.count($vorschau['abweichend']).' überschreiben einen vorhandenen Wert ('
                                .collect($vorschau['abweichend'])->map($labelVon)->implode(', ').'). Trotzdem alle übernehmen?';
                        @endphp
                        <x-fa::button size="sm" variant="primary" icon="heroicon-m-check" wire:click="alleUebernehmen(true)" wire:confirm="{{ $frage }}"
                            data-produktpass-alle-uebernehmen>Alle Vorschläge übernehmen ({{ count($vorschau['leer']) + count($vorschau['abweichend']) }})</x-fa::button>
                    @endif
                @endif
            </x-slot:actions>
            @if($stand['felder'] === [])
                <x-fa::empty compact icon="heroicon-o-question-mark-circle" title="Keine Felder erkannt" data-produktpass-felder-leer>
                    Im Dokument ließ sich nichts sicher ablesen. Schärfer fotografieren oder ein Datenblatt als PDF hochladen.
                </x-fa::empty>
            @else
                <table class="fa-table" data-produktpass-felder>
                    <thead><tr><th>Feld</th><th>Gelesen</th><th>Sicherheit</th><th>Bisher</th><th>Stand</th><th></th></tr></thead>
                    <tbody>
                    @foreach($stand['felder'] as $f)
                        <tr wire:key="pp-f-{{ $f['key'] }}" data-produktpass-feld="{{ $f['key'] }}">
                            <td>{{ $f['label'] }}</td>
                            <td class="max-w-sm">
                                {{ $wertText($f['wert'], $f['einheit']) }}@if($f['abgeleitet']) <span class="{{ $leise }}">(abgeleitet)</span>@endif
                                @if($f['im_dokument'] ?? null)<span class="block {{ $leise }}">im Dokument: {{ $f['im_dokument'] }}</span>@endif
                                @if($f['url'])<a href="{{ $f['url'] }}" target="_blank" rel="noopener nofollow" class="block underline text-[var(--fa-accent)] {{ $leise }} truncate">{{ Str::limit($f['url'], 48) }}</a>@endif
                            </td>
                            <td><x-fa::badge :tone="$f['konfidenz'] >= $schwelle ? 'ok' : 'warn'" class="tabular-nums">{{ number_format($f['konfidenz'] * 100, 0) }} %</x-fa::badge></td>
                            <td class="{{ $leise }}">{{ $aktuellText($f['aktuell']) }}</td>
                            <td>
                                @if($f['status'] === 'uebernommen')
                                    <x-fa::badge tone="ok">übernommen</x-fa::badge>
                                @elseif($f['status'] === 'gleichwertig')
                                    <x-fa::badge tone="neutral" title="Der Artikel hat diese Menge schon (z. B. 660 g im Dokument = 0,660 kg am Artikel)." data-produktpass-gleichwertig>entspricht dem Artikel</x-fa::badge>
                                @else
                                    <x-fa::badge tone="neutral">Vorschlag</x-fa::badge>
                                @endif
                            </td>
                            <td class="text-right">
                                @if($darfEdit && $f['status'] !== 'uebernommen' && $f['uebernehmbar'])
                                    <x-fa::button size="sm" wire:click="feldUebernehmen('{{ $f['key'] }}')" data-produktpass-feld-uebernehmen>Übernehmen</x-fa::button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            @if(($angezeigt['verworfen_felder'] ?? []) !== [])
                <p class="{{ $leise }}" data-produktpass-verworfen>Nicht verwertbar und ausgelassen: {{ implode(' · ', array_slice($angezeigt['verworfen_felder'], 0, 6)) }}{{ count($angezeigt['verworfen_felder']) > 6 ? ' …' : '' }}</p>
            @endif
        </x-fa::section>

        @if($stand['allergene'] !== [] || $stand['allergene_details'] !== [] || $stand['zusatzstoffe'] !== [])
            <x-fa::section title="Kennzeichnung aus dem Dokument" icon="heroicon-o-shield-exclamation" data-produktpass-allergene data-produktpass-kennzeichnung
                description="Allergene und Zusatzstoffe sind ein Vorschlag mit Zitat. Erst deine Bestätigung trägt sie zusammen ein — bis dahin gilt der bisherige Stand.">
                @if($angezeigt['allergene_status'] === 'vorschlag')
                    <x-fa::notice tone="warn" data-produktpass-allergene-offen>Noch nicht bestätigt. Der bisherige Stand des Artikels bleibt unverändert.</x-fa::notice>
                @elseif($angezeigt['allergene_status'] === 'bestaetigt')
                    <x-fa::notice tone="ok" data-produktpass-allergene-bestaetigt>Bestätigt und am Artikel eingetragen.</x-fa::notice>
                @elseif($angezeigt['allergene_status'] === 'verworfen')
                    <x-fa::notice tone="info">Vorschlag verworfen, der bisherige Stand gilt.</x-fa::notice>
                @endif
                @if($stand['allergene'] !== [])
                    <table class="fa-table" data-produktpass-allergen-tabelle>
                        <thead><tr><th>Allergen</th><th>Laut Dokument</th><th>Sicherheit</th><th>Bisher am Artikel</th></tr></thead>
                        <tbody>
                        @foreach($stand['allergene'] as $a)
                            <tr wire:key="pp-a-{{ $a['key'] }}" data-produktpass-allergen="{{ $a['key'] }}">
                                <td>{{ $a['label'] }}</td>
                                <td><x-fa::badge :tone="$a['vorschlag'] === 'enthalten' ? 'crit' : ($a['vorschlag'] === 'spuren' ? 'warn' : 'neutral')">{{ $allergenText[$a['vorschlag']] ?? $a['vorschlag'] }}</x-fa::badge>
                                    @if($a['zitat'] ?? null)<span class="block {{ $leise }}">„{{ Str::limit($a['zitat'], 120) }}“</span>@endif
                                    @if($a['url'])<a href="{{ $a['url'] }}" target="_blank" rel="noopener nofollow" class="block underline text-[var(--fa-accent)] {{ $leise }} truncate">{{ Str::limit($a['url'], 48) }}</a>@endif</td>
                                <td><x-fa::badge :tone="$a['konfidenz'] >= $schwelle ? 'ok' : 'warn'" class="tabular-nums">{{ number_format($a['konfidenz'] * 100, 0) }} %</x-fa::badge></td>
                                <td class="{{ $leise }}">{{ $allergenText[$a['aktuell']] ?? $a['aktuell'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
                @if($stand['allergene_details'] !== [])
                    <p class="{{ $leise }}" data-produktpass-unterarten>Unterarten: {{ collect($stand['allergene_details'])->map(fn ($d, $k) => $k.' ('.($allergenText[$d['wert']] ?? $d['wert']).')')->implode(', ') }}</p>
                @endif
                @if($stand['zusatzstoffe'] !== [])
                    <table class="fa-table" data-produktpass-zusatzstoffe>
                        <thead><tr><th>Zusatzstoff / Kennzeichnung</th><th>Laut Dokument</th><th>Sicherheit</th><th>Bisher am Artikel</th><th></th></tr></thead>
                        <tbody>
                        @foreach($stand['zusatzstoffe'] as $zs)
                            <tr wire:key="pp-z-{{ $zs['key'] }}" data-produktpass-zusatzstoff="{{ $zs['key'] }}">
                                <td>{{ $zs['label'] }}</td>
                                <td>{{ $zs['vorschlag'] === 'ja' ? 'ja' : 'nein' }}
                                    @if($zs['zitat'] ?? null)<span class="block {{ $leise }}">„{{ Str::limit($zs['zitat'], 120) }}“</span>@endif
                                    @if($zs['url'])<a href="{{ $zs['url'] }}" target="_blank" rel="noopener nofollow" class="block underline text-[var(--fa-accent)] {{ $leise }} truncate">{{ Str::limit($zs['url'], 48) }}</a>@endif</td>
                                <td><x-fa::badge :tone="$zs['konfidenz'] >= $schwelle ? 'ok' : 'warn'" class="tabular-nums">{{ number_format($zs['konfidenz'] * 100, 0) }} %</x-fa::badge></td>
                                <td class="{{ $leise }}">{{ $zs['aktuell'] ?? 'unbekannt' }}</td>
                                <td>@if($zs['status'] === 'uebernommen')<x-fa::badge tone="ok">schon übernommen</x-fa::badge>@endif</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
                @if($darfEdit && $angezeigt['allergene_status'] === 'vorschlag')
                    <div class="flex gap-2">
                        <x-fa::button variant="primary" icon="heroicon-m-check" wire:click="kennzeichnungBestaetigen"
                            wire:confirm="Allergene und Zusatzstoffe aus dem Dokument am Artikel eintragen? Das ist eine Kennzeichnung — bitte vorher gegen das Dokument prüfen."
                            data-produktpass-kennzeichnung-bestaetigen data-produktpass-allergene-bestaetigen>Kennzeichnung bestätigen</x-fa::button>
                        <x-fa::button variant="ghost" wire:click="kennzeichnungVerwerfen" data-produktpass-kennzeichnung-verwerfen data-produktpass-allergene-verwerfen>Vorschlag verwerfen</x-fa::button>
                    </div>
                @endif
            </x-fa::section>
        @endif
    @endif
</div>
