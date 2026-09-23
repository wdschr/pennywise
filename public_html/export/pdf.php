<?php

declare(strict_types=1);

require __DIR__ . '/../../private_html/bootstrap.php';

$currentUser = Auth::requireLogin();
$ctx = Rbac::context($currentUser, $_GET['owner'] ?? null);

$to = $_GET['to'] ?? date('Y-m-d');
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-5 months', strtotime(date('Y-m-01'))));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d');

$summary = Transactions::summary($ctx['owner_id'], $from, $to, $ctx['category_ids']);
$items = Transactions::query($ctx['owner_id'], ['from' => $from, 'to' => $to, 'category_ids' => $ctx['category_ids']], 5000);
$siteName = (string) Config::get('site_name', 'Pennywise');

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator($siteName);
$pdf->SetAuthor($ctx['owner_name']);
$pdf->SetTitle('Budget report ' . $from . ' to ' . $to);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);

function money_html(float $amount): string
{
    return Format::money($amount);
}

$html = '<h1 style="font-size:18px;">' . htmlspecialchars($siteName) . ' - Budget report</h1>';
$html .= '<p>' . htmlspecialchars($ctx['owner_name']) . ' &middot; ' . htmlspecialchars(Format::date($from)) . ' to ' . htmlspecialchars(Format::date($to)) . '</p>';

$html .= '<table cellpadding="6" border="0" style="margin-bottom:14px;">
  <tr>
    <td width="33%" style="background-color:#eef3fc;"><strong>Income</strong><br/>' . money_html($summary['income']) . '</td>
    <td width="33%" style="background-color:#fdeee6;"><strong>Expenses</strong><br/>' . money_html($summary['expense']) . '</td>
    <td width="33%" style="background-color:#eafaf0;"><strong>Net</strong><br/>' . money_html($summary['net']) . '</td>
  </tr>
</table>';

$html .= '<h2 style="font-size:14px;">By category</h2>';
$html .= '<table cellpadding="5" border="1" style="border-collapse:collapse;font-size:9pt;">
  <tr style="background-color:#333;color:#fff;"><th>Category</th><th>Type</th><th align="right">Total</th></tr>';
foreach ($summary['by_category'] as $c) {
    $html .= '<tr><td>' . htmlspecialchars($c['name']) . '</td><td>' . ($c['kind'] === 'income' ? 'Income' : 'Expense') . '</td><td align="right">' . money_html((float) $c['total']) . '</td></tr>';
}
$html .= '</table>';

$html .= '<h2 style="font-size:14px;">Transactions</h2>';
$html .= '<table cellpadding="4" border="1" style="border-collapse:collapse;font-size:8pt;">
  <tr style="background-color:#333;color:#fff;"><th>Date</th><th>Type</th><th>Category</th><th>Description</th><th align="right">Amount</th></tr>';
foreach ($items as $t) {
    $html .= '<tr><td>' . htmlspecialchars(Format::date($t['txn_date'])) . '</td><td>' . ($t['kind'] === 'income' ? 'Income' : 'Expense') . '</td><td>' . htmlspecialchars($t['category_name']) . '</td><td>' . htmlspecialchars((string) $t['description']) . '</td><td align="right">' . money_html((float) $t['amount']) . '</td></tr>';
}
$html .= '</table>';

$pdf->writeHTML($html, true, false, true, false, '');

$filename = 'budget-report-' . $from . '-to-' . $to . '.pdf';
$pdf->Output($filename, 'D');
Audit::log((int) $currentUser['id'], 'export_pdf', null, null, ['from' => $from, 'to' => $to, 'owner_id' => $ctx['owner_id']]);
