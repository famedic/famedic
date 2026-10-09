#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\LaboratoryPreparation\Parsing\LaboratoryInstructionOfficialSourceSplitter;

require __DIR__.'/../vendor/autoload.php';

if ($argc < 2) {
    fwrite(STDERR, "Uso: php scripts/generate_casos_prueba_oficiales_v3.php <ruta-al-xlsx>\n");
    exit(1);
}

$xlsxPath = $argv[1];

if (! is_readable($xlsxPath)) {
    fwrite(STDERR, "No se puede leer el archivo: {$xlsxPath}\n");
    exit(1);
}

if (! class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Se requiere ext-zip para leer el XLSX.\n");
    exit(1);
}

$zip = new ZipArchive;
if ($zip->open($xlsxPath) !== true) {
    fwrite(STDERR, "No se pudo abrir el XLSX.\n");
    exit(1);
}

$workbookXml = $zip->getFromName('xl/workbook.xml');
$relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
if ($workbookXml === false || $relsXml === false) {
    fwrite(STDERR, "Workbook o rels no encontrados.\n");
    exit(1);
}

$rels = [];
$relsDoc = simplexml_load_string($relsXml);
if ($relsDoc !== false) {
    foreach ($relsDoc->Relationship as $relationship) {
        $rels[(string) $relationship['Id']] = (string) $relationship['Target'];
    }
}

$sheetPath = null;
$workbook = simplexml_load_string($workbookXml);
if ($workbook !== false) {
    $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    foreach ($workbook->sheets->sheet as $sheet) {
        if ((string) $sheet['name'] === 'Casos_prueba') {
            $relId = (string) $sheet->attributes('r', true)['id'];
            $target = $rels[$relId] ?? null;
            if ($target !== null) {
                $sheetPath = str_starts_with($target, 'worksheets/') ? 'xl/'.$target : 'xl/worksheets/'.basename($target);
            }
            break;
        }
    }
}

if ($sheetPath === null) {
    fwrite(STDERR, "No se encontró la hoja Casos_prueba.\n");
    exit(1);
}

$sharedStrings = [];
$sharedXml = $zip->getFromName('xl/sharedStrings.xml');
if ($sharedXml !== false) {
    $shared = simplexml_load_string($sharedXml);
    if ($shared !== false) {
        foreach ($shared->si as $si) {
            if (isset($si->t)) {
                $sharedStrings[] = (string) $si->t;
            } else {
                $parts = [];
                foreach ($si->r as $run) {
                    $parts[] = (string) $run->t;
                }
                $sharedStrings[] = implode('', $parts);
            }
        }
    }
}

$sheetXml = $zip->getFromName($sheetPath);
$zip->close();

if ($sheetXml === false) {
    fwrite(STDERR, "No se pudo leer {$sheetPath}.\n");
    exit(1);
}

/**
 * @return array<string, string>
 */
function readSheetRows(string $sheetXml, array $sharedStrings): array
{
    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) {
        return [];
    }

    $rows = [];

    foreach ($sheet->sheetData->row as $row) {
        $rowIndex = (int) $row['r'];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            $col = preg_replace('/\d+/', '', $ref) ?? '';
            $type = (string) ($cell['t'] ?? '');
            $value = '';

            if ($type === 's') {
                $value = $sharedStrings[(int) ($cell->v ?? 0)] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cell->is->t ?? '');
                if ($value === '' && isset($cell->is->r)) {
                    $parts = [];
                    foreach ($cell->is->r as $run) {
                        $parts[] = (string) $run->t;
                    }
                    $value = implode('', $parts);
                }
            } else {
                $value = (string) ($cell->v ?? '');
            }

            $rows["{$rowIndex}:{$col}"] = $value;
        }
    }

    return $rows;
}

/**
 * @param  array<string, string>  $cells
 * @return array<string, string>
 */
function rowAssoc(array $cells, int $rowNumber, array $columns): array
{
    $assoc = [];
    foreach ($columns as $column => $header) {
        $assoc[$header] = $cells["{$rowNumber}:{$column}"] ?? '';
    }

    return $assoc;
}

$cells = readSheetRows($sheetXml, $sharedStrings);
$columns = [
    'A' => 'Caso',
    'B' => 'Nivel',
    'C' => 'Contexto paciente',
    'D' => 'Estudios de la orden',
    'E' => 'Indicaciones fuente (texto original)',
    'F' => 'Ruta esperada',
    'G' => 'Reglas',
    'H' => 'Criterio de aceptación',
    'I' => 'Salida / comportamiento esperado',
];

$splitter = new LaboratoryInstructionOfficialSourceSplitter;
$cases = [];

for ($row = 4; $row <= 200; $row++) {
    $assoc = rowAssoc($cells, $row, $columns);
    $caseId = trim($assoc['Caso'] ?? '');
    if ($caseId === '') {
        continue;
    }

    $sourceText = (string) ($assoc['Indicaciones fuente (texto original)'] ?? '');
    $studies = $splitter->split($caseId, $sourceText);

    $cases[] = [
        'case_id' => $caseId,
        'nivel' => trim((string) ($assoc['Nivel'] ?? '')),
        'patient_context' => trim((string) ($assoc['Contexto paciente'] ?? '')),
        'order_studies_list' => trim((string) ($assoc['Estudios de la orden'] ?? '')),
        'source_text' => $sourceText,
        'expected_route' => trim((string) ($assoc['Ruta esperada'] ?? '')),
        'expected_rules' => trim((string) ($assoc['Reglas'] ?? '')),
        'acceptance_criteria' => trim((string) ($assoc['Criterio de aceptación'] ?? '')),
        'expected_output' => trim((string) ($assoc['Salida / comportamiento esperado'] ?? '')),
        'studies' => $studies,
    ];
}

if (count($cases) !== 40) {
    fwrite(STDERR, 'Se esperaban 40 casos; se encontraron '.count($cases).".\n");
    exit(1);
}

$output = __DIR__.'/../tests/Support/LaboratoryPreparation/casos_prueba_oficiales_v3.json';
file_put_contents($output, json_encode($cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

echo 'Generados '.count($cases)." casos oficiales en {$output}\n";
