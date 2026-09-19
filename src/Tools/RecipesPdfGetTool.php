<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ReportExportService;

/** Plattform-PDF über MCP: kurzlebiger Snapshot-Download oder explizit Base64. */
class RecipesPdfGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const FILTERS = [
        'stammdaten' => 'Stammdaten und Kennzahlen', 'zutaten' => 'Zutatenliste',
        'steps' => 'Arbeitsschritte', 'sensorik' => 'Sensorikprofil',
        'produktion' => 'Produktionsdaten', 'preise' => 'Preise und Kalkulation',
        'lieferanten' => 'Lieferanteninformationen', 'kaskade' => 'Unterrezepte auflösen',
        'bilder' => 'Bilder', 'deklaration' => 'Deklaration und Allergene',
        'naehrwerte' => 'Nährwerte', 'notizen' => 'Interne Notizen',
        'regeneration' => 'Regeneration', 'anrichten' => 'Anrichten', 'behaelter' => 'Behälter',
    ];

    public function getName(): string
    {
        return 'foodalchemist.recipes.PDF';
    }

    public function getDescription(): string
    {
        return 'Erzeugt das Plattform-PDF eines sichtbaren Basisrezepts oder Gerichts. Standard: download_url, 10 Minuten gültig, direkt ohne Browser-Login abrufbar; wer den Link besitzt, kann ihn bis zum Ablauf nutzen. Alternativ transport=base64: content_base64 dekodieren und unter filename speichern (große Tool-Antwort). Profile: kurz, produktion (Standard), kalkulation, voll. Einzelne Inhaltsfilter (z.B. preise=false, steps=true) überschreiben das Profil; weglassen erhält den Profilstandard. ziel_kg oder ziel_menge skaliert den Report. Freigabestatus bleibt im Dokument sichtbar.';
    }

    public function getSchema(): array
    {
        $filters = [];
        foreach (self::FILTERS as $key => $label) {
            $filters[$key] = ['type' => 'boolean', 'description' => $label . ': true einschließen, false ausblenden; weglassen = Profilstandard.'];
        }
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'id' => ['type' => 'integer'],
            'transport' => ['type' => 'string', 'enum' => ['download', 'base64'], 'default' => 'download'],
            'profil' => ['type' => 'string', 'enum' => ['kurz', 'produktion', 'kalkulation', 'voll'], 'default' => 'produktion'],
            'ziel_kg' => ['type' => 'number', 'minimum' => 0, 'description' => 'Report auf diese Ziel-Ausbeute in kg hochrechnen.'],
            'ziel_menge' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000, 'description' => 'Gericht: Anzahl der Darreichungen.'],
            'darreichung' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Darreichungs-ID des Gerichts für ziel_menge; weglassen = Standard.'],
            ...$filters,
        ], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $unknown = array_diff(array_keys($arguments), array_keys($this->getSchema()['properties']));
        if ($unknown !== []) {
            return ToolResult::error('Unbekannte PDF-Filter: ' . implode(', ', $unknown), 'VALIDATION_ERROR');
        }
        $rules = [
            'id' => 'required|integer|min:1',
            'transport' => 'sometimes|in:download,base64',
            'profil' => 'sometimes|in:kurz,produktion,kalkulation,voll',
            'ziel_kg' => 'sometimes|numeric|min:0',
            'ziel_menge' => 'sometimes|integer|between:0,1000000',
            'darreichung' => 'sometimes|integer|min:1',
        ];
        foreach (self::FILTERS as $key => $label) {
            $rules[$key] = 'sometimes|boolean';
        }
        $validator = \Illuminate\Support\Facades\Validator::make($arguments, $rules);
        if ($validator->fails()) {
            return ToolResult::error($validator->errors()->first(), 'VALIDATION_ERROR');
        }
        try {
            $service = app(ReportExportService::class);
            $data = $service->rezeptDaten($team, (int) $arguments['id'], $service->optionen($arguments, 'recipe'));
        } catch (ModelNotFoundException $e) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return ToolResult::error('PDF-Export benötigt DomPDF auf dem Server.', 'PDF_UNAVAILABLE');
        }
        try {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('foodalchemist::dokumente.report', $data + ['istPdf' => true])->output();
        } catch (\Throwable $e) {
            report($e);
            return ToolResult::error('PDF konnte nicht gerendert werden.', 'PDF_RENDER_FAILED');
        }
        // Verhindert unbeschränkt große Antworten im MCP-Transport.
        if (strlen($pdf) > 2 * 1024 * 1024) {
            return ToolResult::error('PDF überschreitet 2 MiB. Bitte das Profil kurz verwenden.', 'PDF_TOO_LARGE');
        }

        $filename = ($data['typ'] === 'gericht' ? 'Gericht-' : 'Basisrezept-') . (int) $arguments['id'] . '.pdf';
        $result = ['filename' => $filename, 'mime_type' => 'application/pdf', 'size_bytes' => strlen($pdf), 'optionen' => $data['optionen']];
        if (($arguments['transport'] ?? 'download') === 'base64') {
            return ToolResult::success($result + ['encoding' => 'base64', 'content_base64' => base64_encode($pdf)]);
        }

        $token = bin2hex(random_bytes(32));
        $expires = now()->addMinutes(10);
        try {
            $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'foodalchemist.recipes.pdf_download', $expires, ['token' => $token]);
            $stored = \Illuminate\Support\Facades\Cache::put('foodalchemist:mcp-recipe-pdf:' . $token,
                // MySQL-DatabaseStore serialisiert ohne Binärkodierung in eine UTF-8-Textspalte.
                ['content_base64' => base64_encode($pdf), 'filename' => $filename], $expires);
            if (! $stored) {
                return ToolResult::error('PDF-Download konnte nicht zwischengespeichert werden.', 'PDF_DOWNLOAD_FAILED');
            }
        } catch (\Throwable $e) {
            report($e);
            return ToolResult::error('PDF-Download konnte nicht bereitgestellt werden.', 'PDF_DOWNLOAD_FAILED');
        }

        return ToolResult::success($result + ['download_url' => $url, 'expires_at' => $expires->toIso8601String()]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'read_only' => true, 'idempotent' => false,
            'risk_level' => 'safe', 'requires_auth' => true, 'requires_team' => true,
            'cost_class' => 'local_db', 'tags' => ['foodalchemist', 'rezept', 'pdf', 'export'],
            'related_tools' => ['foodalchemist.recipes.GET']];
    }
}
