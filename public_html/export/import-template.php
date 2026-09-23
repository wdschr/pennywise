<?php

declare(strict_types=1);

require __DIR__ . '/../../private_html/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

$currentUser = Auth::requireLogin();
$ownerId = (int) $currentUser['id'];

$expenseCategories = array_column(Categories::forOwner($ownerId, 'expense'), 'name');
$incomeCategories = array_column(Categories::forOwner($ownerId, 'income'), 'name');
$pots = array_column(Savings::potsForOwner($ownerId), 'name');

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Import');
$sheet->fromArray(Import::EXPECTED_HEADERS, null, 'A1');
$sheet->getStyle('A1:F1')->getFont()->setBold(true);

$examples = [
    [date('Y-m-d'), 'Expense', $expenseCategories[0] ?? 'Essential', 'Example: weekly shop', 45.20, ''],
    [date('Y-m-d'), 'Income', $incomeCategories[0] ?? 'Salary', 'Example: salary', 2200, ''],
    [date('Y-m-d'), 'Saving', $pots[0] ?? 'Emergency Fund', 'Example: contribution', 100, 'Delete these example rows before importing'],
];
$sheet->fromArray($examples, null, 'A2');

foreach (['A' => 14, 'B' => 12, 'C' => 20, 'D' => 30, 'E' => 12, 'F' => 30] as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}

$typeValidation = $sheet->getCell('B2')->getDataValidation();
$typeValidation->setType(DataValidation::TYPE_LIST);
$typeValidation->setErrorStyle(DataValidation::STYLE_STOP);
$typeValidation->setAllowBlank(false);
$typeValidation->setShowDropDown(true);
$typeValidation->setFormula1('"Income,Expense,Saving"');
for ($row = 2; $row <= 500; $row++) {
    $sheet->getCell('B' . $row)->setDataValidation(clone $typeValidation);
}

$ref = $spreadsheet->createSheet();
$ref->setTitle('Your categories');
$ref->fromArray(['Expense categories', 'Income categories', 'Savings pots'], null, 'A1');
$ref->getStyle('A1:C1')->getFont()->setBold(true);
$maxRows = max(count($expenseCategories), count($incomeCategories), count($pots));
for ($i = 0; $i < $maxRows; $i++) {
    $ref->fromArray([
        $expenseCategories[$i] ?? '',
        $incomeCategories[$i] ?? '',
        $pots[$i] ?? '',
    ], null, 'A' . ($i + 2));
}
$ref->getColumnDimension('A')->setWidth(22);
$ref->getColumnDimension('B')->setWidth(22);
$ref->getColumnDimension('C')->setWidth(22);
$ref->insertNewRowBefore(1, 1);
$ref->setCellValue('A1', 'Use these exact names in the Category column of the Import sheet. Unrecognised names will be created automatically as new categories/pots when you import.');
$ref->mergeCells('A1:C1');

$spreadsheet->setActiveSheetIndex(0);

$filename = 'budget-planner-import-template.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
