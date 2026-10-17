<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Spec 84 E-4 (C3b) · Cache für den Livewire-Scan und die Tool-Metadaten, mit RÜCKFALL statt Pflicht-Cache.
 *
 * Gemessen 17.10. (Sandbox, Opcache warm): jeder Boot lud 612 Tool- und 144 Livewire-Klassen und baute 604 Tool-Objekte —
 * rund 22 ms (Tools) + 7 ms (Livewire) von 150 ms je Request, auch für Seiten, die kein MCP-Tool brauchen.
 *
 * Regeln (Spec 84 §5.4): Der Host deployt per update.sh; ein vergessener Cache-Befehl darf nie zu einem halb leeren MCP
 * führen. Deshalb gibt es KEINEN Cache-Befehl: Fehlt die Datei, passt der Schlüssel nicht oder ist sie kaputt, wird live
 * gescannt und die Datei neu geschrieben (selbstheilend). Kann nicht geschrieben werden, bleibt es beim Live-Scan.
 * Fehlt im Cache ein Eintrag, baut ToolListe dieses eine Tool live.
 *
 * Schlüssel: Inhalt von `Tools/_liste.php` + Modul-Version (Composer-Referenz, ändert sich mit jedem Deploy) + Verzeichnis-
 * Stempel von `Livewire/` (neue/entfernte Dateien). Ohne Composer-Referenz (lokale Entwicklung, Path-Repo) zusätzlich die
 * Änderungszeiten aller Dateien in `Tools/` und `Livewire/`, damit jede Code-Änderung sofort greift.
 */
final class ScanCache
{
    /** @var array{schluessel: string, livewire?: array<string, string>, tools?: array<string, array{name: string, meta: array}>}|null */
    private static ?array $geladen = null;

    public static function pfad(): string
    {
        return (string) (config('foodalchemist.scan_cache_pfad') ?: app()->bootstrapPath('cache/foodalchemist-scan.php'));
    }

    /** Für Tests / nach Änderungen im selben Prozess. */
    public static function vergessen(): void
    {
        self::$geladen = null;
    }

    /**
     * @param  callable(): array<string, string>  $liveScan
     * @return array<string, string> Alias → Klasse
     */
    public static function livewire(callable $liveScan): array
    {
        $c = self::laden();
        if (isset($c['livewire']) && is_array($c['livewire'])) {
            return $c['livewire'];
        }
        $map = $liveScan();
        self::schreiben(['livewire' => $map]);

        return $map;
    }

    /**
     * @param  list<class-string>  $klassen
     * @param  callable(list<class-string>): array<string, array{name: string, meta: array}>  $liveScan
     * @return array<string, array{name: string, meta: array}> Klasse → Name/Metadaten (nur fehlerfrei gebaute Tools)
     */
    public static function tools(array $klassen, callable $liveScan): array
    {
        $c = self::laden();
        if (isset($c['tools']) && is_array($c['tools'])) {
            return $c['tools'];
        }
        $meta = $liveScan($klassen);
        self::schreiben(['tools' => $meta]);

        return $meta;
    }

    /** @return array<string, mixed>|null gültiger Cache-Inhalt oder null */
    private static function laden(): ?array
    {
        if (self::$geladen !== null) {
            return self::$geladen;
        }
        $schluessel = self::schluessel();
        $inhalt = null;
        try {
            $pfad = self::pfad();
            if (is_file($pfad)) {
                $roh = include $pfad;
                if (is_array($roh) && ($roh['schluessel'] ?? null) === $schluessel) {
                    $inhalt = $roh;
                }
            }
        } catch (\Throwable $e) {
            unset($e);   // kaputte Datei = kein Cache → Live-Scan (bewusst still, Boot darf nie reißen)
        }

        return self::$geladen = $inhalt ?? ['schluessel' => $schluessel];
    }

    /** Teil-Ergebnis in die Datei mischen (atomar über Umbenennen); Fehler = kein Cache, nie ein Abbruch. */
    private static function schreiben(array $teil): void
    {
        $neu = array_merge(self::laden() ?? [], $teil, ['schluessel' => self::schluessel()]);
        self::$geladen = $neu;
        try {
            $pfad = self::pfad();
            $tmp = $pfad . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, '<?php return ' . var_export($neu, true) . ';' . PHP_EOL) !== false) {
                @rename($tmp, $pfad);
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($pfad, true);
                }
            }
        } catch (\Throwable $e) {
            unset($e);   // nicht beschreibbar: bleibt beim Live-Scan
        }
    }

    private static ?string $schluesselMerker = null;

    public static function schluessel(): string
    {
        if (self::$schluesselMerker !== null) {
            return self::$schluesselMerker;
        }
        $src = dirname(__DIR__);
        $teile = [md5_file($src . '/Tools/_liste.php') ?: 'ohne-liste', self::verzeichnisStempel($src . '/Livewire')];
        $ref = null;
        try {
            // Path-Repo (lokale Sandbox: vendor-Pfad ist ein Symlink) trägt eine feste Referenz, die Code-Änderungen nicht
            // abbildet → dort nie die Referenz, sondern die Datei-Stempel.
            $installiert = \Composer\InstalledVersions::getInstallPath('martin3r/platform-foodalchemist');
            if ($installiert !== null && ! is_link(rtrim($installiert, '/'))) {
                $ref = \Composer\InstalledVersions::getReference('martin3r/platform-foodalchemist');
            }
        } catch (\Throwable $e) {
            unset($e);
        }
        $teile[] = $ref !== null && $ref !== '' ? 'ref:' . $ref : 'dev:' . self::dateiStempel([$src . '/Tools', $src . '/Livewire']);

        return self::$schluesselMerker = md5(implode('|', $teile));
    }

    /** Änderungszeiten aller Unterverzeichnisse (ändern sich, wenn Dateien dazukommen oder wegfallen) — nur stat, kein Lesen. */
    private static function verzeichnisStempel(string $dir): string
    {
        $t = [];
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            $t[] = filemtime($dir);
            foreach ($it as $f) {
                if ($f->isDir()) {
                    $t[] = $f->getPathname() . ':' . $f->getMTime();
                }
            }
        } catch (\Throwable $e) {
            unset($e);
        }

        return md5(implode(',', $t));
    }

    /** @param list<string> $dirs */
    private static function dateiStempel(array $dirs): string
    {
        $t = [];
        foreach ($dirs as $dir) {
            try {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                    $t[] = $f->getPathname() . ':' . $f->getMTime();
                }
            } catch (\Throwable $e) {
                unset($e);
            }
        }
        sort($t);

        return md5(implode(',', $t));
    }

    /** Nur für Tests: Schlüssel neu berechnen lassen. */
    public static function schluesselVergessen(): void
    {
        self::$schluesselMerker = null;
    }
}
