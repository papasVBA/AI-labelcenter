<?php

declare(strict_types=1);

require_once '../../bootstrap.php';
require_once BASE_PATH . '/vendor/autoload.php';

use LabelCenter\Db\PgConnection;
use LabelCenter\Excel\WorkbookBuilder;
use LabelCenter\Excel\WorkbookReader;
use LabelCenter\Excel\ImportService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Exception
    as ReaderException;

$tmpFile = null;
$spreadsheet = null;

try {

    // ---------------------------------------
    // HTTP METHOD
    // ---------------------------------------
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        header('Allow: POST');

        throw new \InvalidArgumentException(
            'Je vyžadována metoda POST.'
        );
    }

    // ---------------------------------------
    // UPLOAD
    // ---------------------------------------
    $file = $_FILES['file'] ?? null;

    if (
        !is_array($file)
        || ($file['error'] ?? null)
            !== UPLOAD_ERR_OK
        || !is_uploaded_file($file['tmp_name'] ?? '')
    ) {

        throw new \InvalidArgumentException(
            'Soubor nebyl správně nahrán.'
        );
    }

    $extension = strtolower(
        pathinfo(
            $file['name'],
            PATHINFO_EXTENSION
        )
    );

    if ($extension !== 'xlsx') {

        throw new \InvalidArgumentException(
            'Je podporován pouze formát XLSX.'
        );
    }

    if ($file['size'] > 20 * 1024 * 1024) {

        throw new \InvalidArgumentException(
            'Soubor překračuje limit 20 MB.'
        );
    }

    // ---------------------------------------
    // KONFIGURACE
    // ---------------------------------------
    $placeholdersFile =
        BASE_PATH . '/../settings/Placeholders.JSON';

    $builder = new WorkbookBuilder(
        $placeholdersFile
    );

    $headers = $builder->getLabelHeaders();

    $staticHeaders = array_slice($headers, 6);

    // ---------------------------------------
    // NAČTENÍ XLSX
    // ---------------------------------------
    $reader = new WorkbookReader(
        $file['tmp_name'],
        $headers
    );

    $spreadsheet = $reader->getSpreadsheet();

    $spreadsheet->setActiveSheetIndex(
        $spreadsheet->getIndex(
            $reader->getSheet()
        )
    );

    // ---------------------------------------
    // DOČASNÝ VÝSTUPNÍ SOUBOR
    // ---------------------------------------
    $tmpFile = tempnam(
        sys_get_temp_dir(),
        'lc_import_'
    );

    if ($tmpFile === false) {

        throw new \RuntimeException(
            'Nelze vytvořit dočasný soubor.'
        );
    }

    // Callback pro generování reportu
    // před COMMIT nebo po ROLLBACK.
    $prepareReport = static function () use (
        $spreadsheet,
        $tmpFile
    ): void {

        $writer = new Xlsx($spreadsheet);

        $writer->setPreCalculateFormulas(false);

        $writer->save($tmpFile);

        clearstatcache(true, $tmpFile);

        if (
            !is_file($tmpFile)
            || filesize($tmpFile) === 0
        ) {

            throw new \RuntimeException(
                'Výstupní XLSX nebyl vytvořen.'
            );
        }
    };

    // ---------------------------------------
    // TRANSAKČNÍ IMPORT
    // ---------------------------------------
    $pg = new PgConnection('labelcenter');

    $service = new ImportService($pg);

    $result = $service->run(
        $reader,
        $staticHeaders,
        $prepareReport
    );

    // ---------------------------------------
    // NÁZEV VÝSLEDNÉHO SOUBORU
    // ---------------------------------------
    $filename = 'LabelCenter_IMPORT_'
        . $result['status']
        . '_'
        . date('Ymd_His')
        . '.xlsx';

    // ---------------------------------------
    // DOWNLOAD
    // ---------------------------------------
    header(
        'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    header(
        'Content-Disposition: attachment; filename="'
        . $filename . '"'
    );

    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    // Výsledky původní validace.
    header(
        'X-LC-Total: '
        . $result['validation']['total']
    );

    header(
        'X-LC-Valid: '
        . $result['validation']['valid']
    );

    header(
        'X-LC-Warnings: '
        . $result['validation']['warnings']
    );

    header(
        'X-LC-Errors: '
        . $result['validation']['errors']
    );

    // Výsledky skutečného importu.
    header(
        'X-LC-Import-Status: '
        . $result['status']
    );

    header(
        'X-LC-Imported: '
        . $result['summary']['imported']
    );

    header(
        'X-LC-Inserted: '
        . $result['summary']['inserted']
    );

    header(
        'X-LC-Replaced: '
        . $result['summary']['replaced']
    );

    readfile($tmpFile);

} catch (\InvalidArgumentException | ReaderException $e) {

    http_response_code(400);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'error' => $e instanceof ReaderException
            ? 'Soubor nelze načíst jako XLSX.'
            : $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {

    error_log(
        'LabelCenter XLSX import: '
        . $e->getMessage()
    );

    if (!headers_sent()) {

        http_response_code(500);

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode([
            'error' =>
                'Import se nezdařil nebo jeho výsledek nelze potvrdit. Ověřte stav databáze.'
        ], JSON_UNESCAPED_UNICODE);
    }

} finally {

    if (is_string($tmpFile) && is_file($tmpFile)) {
        unlink($tmpFile);
    }

    if ($spreadsheet !== null) {
        $spreadsheet->disconnectWorksheets();
    }
}
