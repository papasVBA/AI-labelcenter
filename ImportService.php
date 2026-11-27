<?php

declare(strict_types=1);

namespace LabelCenter\Excel;

use LabelCenter\Db\PgConnection;
use PhpOffice\PhpSpreadsheet\Style\Fill;

final class ImportService
{
    public function __construct(
        private PgConnection $pg
    ) {
    }

    public function run(
        WorkbookReader $reader,
        array $staticHeaders,
        callable $prepareReport
    ): array {

        $validation = null;
        $currentRow = null;

        $summary = [
            'imported' => 0,
            'inserted' => 0,
            'replaced' => 0
        ];

        $replacedRows = [];

        try {

            // -----------------------------------
            // BEGIN TRANSACTION
            // -----------------------------------
            $this->pg->beginTransaction();

            // Nečekáme nekonečně na cizí transakci.
            $this->pg->exec(
                "SET LOCAL lock_timeout = '5s'"
            );

            // Zabráníme změnám referenčních dat
            // mezi validací a vlastním importem.
            $this->pg->exec("
                LOCK TABLE
                    public.cache_storecards,
                    public.spelman_lc_templates
                IN SHARE MODE
            ");

            // Serializujeme importy a ostatní zápisy
            // do štítků a jejich Fields.
            $this->pg->exec("
                LOCK TABLE
                    public.spelman_lc_labels,
                    public.spelman_lc_fields
                IN SHARE ROW EXCLUSIVE MODE
            ");

            // -----------------------------------
            // OPAKOVANÁ VALIDACE
            // -----------------------------------
            $validator = new ImportValidator(
                $this->pg,
                $staticHeaders
            );

            $validation = $validator->validate(
                $reader
            );

            if (!$validation['canImport']) {

                $this->pg->rollBack();

                $this->markNotImported(
                    $reader,
                    $validation
                );

                $result = [
                    'status'     => 'VALIDATION_FAILED',
                    'validation' => $validation['summary'],
                    'summary'    => $summary
                ];

                $prepareReport();

                return $result;
            }

            // -----------------------------------
            // CACHE ŠABLON
            // -----------------------------------
            $templates = $this->loadTemplateFields();

            // -----------------------------------
            // IMPORT JEDNOTLIVÝCH ŘÁDKŮ
            // -----------------------------------
            foreach (
                $validation['rows'] as $row => $item
            ) {

                $currentRow = $row;

                $data = $item['data'];

                $storecardId = $item['storecardId'];
                $templateId = $item['templateId'];

                if (
                    !array_key_exists(
                        $templateId,
                        $templates
                    )
                ) {
                    throw new \RuntimeException(
                        'Šablona nebyla nalezena.'
                    );
                }

                // -------------------------------
                // 1. Existující business key
                // -------------------------------
                $existing = $this->pg->query("
                    SELECT id

                    FROM public.spelman_lc_labels

                    WHERE storecard_id = :scid
                      AND template_id = :tempid
                      AND name = :name
                ", [
                    ':scid'   => $storecardId,
                    ':tempid' => $templateId,
                    ':name'   => $data['NAME']
                ]);

                // Nekonzistentní DB nesmíme
                // automaticky opravovat mazáním.
                if (count($existing) > 1) {

                    throw new \RuntimeException(
                        'Duplicitní business key v DB.'
                    );
                }

                // -------------------------------
                // 2. DELETE původního štítku
                // -------------------------------
                if (count($existing) === 1) {

                    $deleted = $this->pg->exec("
                        DELETE FROM
                            public.spelman_lc_labels
                        WHERE id = :id
                    ", [
                        ':id' => $existing[0]['id']
                    ]);

                    if ($deleted !== 1) {

                        throw new \RuntimeException(
                            'Původní štítek nebyl odstraněn.'
                        );
                    }

                    // Fields odstraní ON DELETE CASCADE.
                    $replacedRows[$row] = true;

                    $summary['replaced']++;

                } else {

                    $summary['inserted']++;
                }

                // -------------------------------
                // 3. INSERT nového štítku
                // -------------------------------
                $inserted = $this->pg->query("
                    INSERT INTO
                        public.spelman_lc_labels
                    (
                        name,
                        template_id,
                        storecard_id,
                        notice,
                        qpp
                    )
                    VALUES
                    (
                        :name,
                        :tempid,
                        :scid,
                        :notice,
                        :qpp
                    )
                    RETURNING id
                ", [
                    ':name'   => $data['NAME'],
                    ':tempid' => $templateId,
                    ':scid'   => $storecardId,
                    ':notice' => $data['NOTICE'],
                    ':qpp'    => $data['QTY']
                ]);

                if (!isset($inserted[0]['id'])) {

                    throw new \RuntimeException(
                        'INSERT nevrátil ID štítku.'
                    );
                }

                $newId = $inserted[0]['id'];

                // -------------------------------
                // 4. Vytvoření všech Fields
                // -------------------------------
                foreach (
                    $templates[$templateId] as $field
                ) {

                    $this->pg->exec("
                        INSERT INTO
                            public.spelman_lc_fields
                        (
                            label_id,
                            field_name,
                            field_content,
                            notice
                        )
                        VALUES
                        (
                            :labelid,
                            :name,
                            :content,
                            :notice
                        )
                    ", [
                        ':labelid' => $newId,
                        ':name'    => $field['name'],
                        ':content' => $field['content'],
                        ':notice'  => 'Auto-generated'
                    ]);
                }

                // -------------------------------
                // 5. Statické overrides
                // -------------------------------
                foreach (
                    $item['staticValues']
                    as $fieldName => $content
                ) {

                    $updated = $this->pg->exec("
                        UPDATE
                            public.spelman_lc_fields

                        SET field_content = :content

                        WHERE label_id = :labelid
                          AND field_name = :fieldname
                    ", [
                        ':content'   => $content,
                        ':labelid'   => $newId,
                        ':fieldname' => $fieldName
                    ]);

                    if ($updated !== 1) {

                        throw new \RuntimeException(
                            'Neočekávaný počet Fields: '
                            . $fieldName
                        );
                    }
                }

                $summary['imported']++;

                // ======================================
                // TEMPORARY ROLLBACK TEST
                // PO OTESTOVÁNÍ ODSTRANIT!
                // ======================================
                /*
                if ($summary['imported'] === 2) {

                    throw new \RuntimeException(
                        'TEST: Forced rollback after second label.'
                    );
                }
                */
                // ======================================
            }

            $currentRow = null;

            // -----------------------------------
            // PŘÍPRAVA ÚSPĚŠNÉHO REPORTU
            // -----------------------------------
            foreach (
                $validation['rows'] as $row => $item
            ) {

                $messages = ['IMPORTED'];

                if (isset($replacedRows[$row])) {

                    $messages[] = 'DELETED OLD';
                }

                $messages = array_merge(
                    $messages,
                    $item['warnings']
                );

                $this->writeResult(
                    $reader,
                    $row,
                    implode('/ ', $messages) . '/',
                    $item['warnings'] === []
                        ? 'C6EFCE'
                        : 'FFEB9C'
                );
            }

            // Nejdříve skutečně vytvoříme XLSX.
            // Pokud selže generování, import vrátíme.
            $prepareReport();

            // -----------------------------------
            // COMMIT
            // -----------------------------------
            if (!$this->pg->commit()) {

                throw new \RuntimeException(
                    'COMMIT se nezdařil.'
                );
            }

            return [
                'status'     => 'IMPORTED',
                'validation' => $validation['summary'],
                'summary'    => $summary
            ];

        } catch (\Throwable $e) {

            // -----------------------------------
            // ROLLBACK
            // -----------------------------------
            if (!$this->pg->inTransaction()) {
                // Stav COMMIT nelze bezpečně potvrdit.
                // Nesmíme uživateli slíbit rollback.
                throw $e;
            }

            if (!$this->pg->rollBack()) {

                throw new \RuntimeException(
                    'ROLLBACK se nezdařil.',
                    0,
                    $e
                );
            }

            error_log(
                'LabelCenter XLSX import'
                . ' / row: '
                . ($currentRow ?? '-')
                . ' / '
                . $e->getMessage()
            );

            // Pokud chyba nastala již během validace
            // nebo před ní, vrátíme HTTP chybu.
            if ($validation === null) {
                throw $e;
            }

            // Všechny změny byly vráceny.
            // Žádný řádek nesmí hlásit IMPORTED.
            foreach (
                $validation['rows'] as $row => $item
            ) {

                $messages = array_merge(
                    ['NOT IMPORTED', 'ROLLBACK'],
                    $item['errors'],
                    $item['warnings']
                );

                if ($row === $currentRow) {
                    $messages[] = 'IMPORT ERROR';
                }

                $this->writeResult(
                    $reader,
                    $row,
                    implode('/ ', $messages) . '/',
                    'FFC7CE'
                );
            }

            $prepareReport();

            return [
                'status'     => 'ROLLED_BACK',
                'validation' => $validation['summary'],
                'summary'    => [
                    'imported' => 0,
                    'inserted' => 0,
                    'replaced' => 0
                ]
            ];
        }
    }

    private function loadTemplateFields(): array
    {
        $rows = $this->pg->query("
            SELECT id, meta_json
            FROM public.spelman_lc_templates
        ");

        $result = [];

        foreach ($rows as $row) {

            $meta = $row['meta_json'] ?? null;

            if (is_string($meta)) {

                $meta = json_decode(
                    $meta,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            }

            $fields = [];

            foreach (
                ($meta['Fields'] ?? []) as $field
            ) {

                $fields[] = [
                    'name' =>
                        $field['PlaceHolder'] ?? '',

                    'content' =>
                        $field['Content'] ?? ''
                ];
            }

            $result[$row['id']] = $fields;
        }

        return $result;
    }

    private function markNotImported(
        WorkbookReader $reader,
        array $validation
    ): void {

        foreach (
            $validation['rows'] as $row => $item
        ) {

            $messages = array_merge(
                ['NOT IMPORTED'],
                $item['errors'],
                $item['warnings']
            );

            $this->writeResult(
                $reader,
                $row,
                implode('/ ', $messages) . '/',
                $item['errors'] !== []
                    ? 'FFC7CE'
                    : 'FFEB9C'
            );
        }
    }

    private function writeResult(
        WorkbookReader $reader,
        int $row,
        string $message,
        string $color
    ): void {

        $reader->writeText(
            $row,
            'TESTRESULT',
            $message
        );

        $cell =
            $reader->getColumn('TESTRESULT') . $row;

        $reader->getSheet()
            ->getStyle($cell)
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setRGB($color);
    }
}
