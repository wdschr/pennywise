<?php

declare(strict_types=1);

require __DIR__ . '/../../private_html/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$currentUser = Auth::requireLogin();
$ctx = Rbac::context($currentUser, $_GET['owner'] ?? null);

$to = $_GET['to'] ?? date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-5 months', strtotime(date('Y-m-01'))));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d');

$summary = Transactions::summary($ctx['owner_id'], $from, $to, $ctx['category_ids']);
$items = Transactions::query($ctx['owner_id'], ['from' => $from, 'to' => $to, 'category_ids' => $ctx['category_ids']], 5000);

$spreadsheet = new Spreadsheet();

$summarySheet = $spreadsheet->getActiveSheet();
$summarySheet->setTitle('Summary');
$summarySheet->fromArray([
    ['Budget report', $ctx['owner_name']],
    ['From', $from],
    ['To', $to],
    [],
    ['Income', $summary['income']],
    ['Expenses', $summary['expense']],
    ['Net', $summary['net']],
    [],
    ['Category', 'Type', 'Total'],
], null, 'A1');

$row = 10;
foreach ($summary['by_category'] as $c) {
    $summarySheet->fromArray([$c['name'], $c['kind'], (float) $c['total']], null, 'A' . $row);
    $row++;
}
$summarySheet->getStyle('A9:C9')->getFont()->setBold(true);
foreach (['A', 'B', 'C'] as $col) {
    $summarySheet->getColumnDimension($col)->setAutoSize(true);
}

$txnSheet = $spreadsheet->createSheet();
$txnSheet->setTitle('Transactions');
$txnSheet->fromArray(['Date', 'Type', 'Category', 'Description', 'Amount', 'Notes'], null, 'A1');
$txnSheet->getStyle('A1:F1')->getFont()->setBold(true);

$r = 2;
foreach ($items as $t) {
    $txnSheet->setCellValue('A' . $r, $t['txn_date']);
    $txnSheet->setCellValue('B' . $r, $t['kind'] === 'income' ? 'Income' : 'Expense');
    $txnSheet->setCellValue('C' . $r, $t['category_name']);
    $txnSheet->setCellValue('D' . $r, (string) $t['description']);
    $txnSheet->setCellValue('E' . $r, (float) $t['amount']);
    $txnSheet->setCellValue('F' . $r, (string) $t['notes']);
    $r++;
}
foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $col) {
    $txnSheet->getColumnDimension($col)->setAutoSize(true);
}

$spreadsheet->setActiveSheetIndex(0);

$filename = 'budget-report-' . $from . '-to-' . $to . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

Audit::log((int) $currentUser['id'], 'export_xlsx', null, null, ['from' => $from, 'to' => $to, 'owner_id' => $ctx['owner_id']]);
