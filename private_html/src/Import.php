<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

final class Import
{
    public const EXPECTED_HEADERS = ['Date', 'Type', 'Category', 'Description', 'Amount', 'Notes'];

    /**
     * Reads an uploaded csv/xlsx file into an array of associative rows
     * keyed by the header row. Accepts the first sheet only.
     */
    public static function parseFile(string $path, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $reader = match ($extension) {
            'csv' => IOFactory::createReader('Csv'),
            'xlsx', 'xls' => IOFactory::createReader(ucfirst($extension === 'xls' ? 'Xls' : 'Xlsx')),
            default => throw new RuntimeException('Unsupported file type. Please upload a .csv or .xlsx file.'),
        };

        if ($reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv) {
            $reader->setDelimiter(',');
            $reader->setInputEncoding('UTF-8');
        }

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, false);

        if ($rows === []) {
            return [];
        }

        $header = array_map(static fn($h) => trim((string) $h), array_shift($rows));
        $result = [];

        foreach ($rows as $row) {
            if (array_filter($row, static fn($v) => trim((string) $v) !== '') === []) {
                continue; // skip fully blank rows
            }
            $assoc = [];
            foreach ($header as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = $row[$i] ?? null;
            }
            $result[] = $assoc;
        }

        return $result;
    }

    /**
     * @return array{valid: array, errors: array}
     */
    public static function validateRows(int $ownerId, array $rows): array
    {
        $expenseCategories = self::nameMap(Categories::forOwner($ownerId, 'expense', true));
        $incomeCategories = self::nameMap(Categories::forOwner($ownerId, 'income', true));
        $pots = self::nameMap(Savings::potsForOwner($ownerId, true), 'name');

        $valid = [];
        $errors = [];

        foreach ($rows as $lineNo => $row) {
            $rowNum = $lineNo + 2; // account for header row, 1-indexed
            $type = trim((string) ($row['Type'] ?? ''));
            $typeLower = strtolower($type);
            $dateRaw = $row['Date'] ?? '';
            $category = trim((string) ($row['Category'] ?? ''));
            $description = trim((string) ($row['Description'] ?? ''));
            $amountRaw = $row['Amount'] ?? '';
            $notes = trim((string) ($row['Notes'] ?? ''));

            $date = self::parseDate($dateRaw);
            $amount = is_numeric(str_replace([',', '£', '$', '€'], '', (string) $amountRaw))
                ? (float) str_replace([',', '£', '$', '€'], '', (string) $amountRaw)
                : null;

            if (!in_array($typeLower, ['income', 'expense', 'saving'], true)) {
                $errors[] = ['row' => $rowNum, 'message' => 'Type must be Income, Expense or Saving (got "' . $type . '").'];
                continue;
            }
            if ($date === null) {
                $errors[] = ['row' => $rowNum, 'message' => 'Could not understand the date "' . $dateRaw . '".'];
                continue;
            }
            if ($amount === null || $amount == 0.0) {
                $errors[] = ['row' => $rowNum, 'message' => 'Amount must be a non-zero number.'];
                continue;
            }
            if ($category === '') {
                $errors[] = ['row' => $rowNum, 'message' => 'Category (or pot name, for savings) is required.'];
                continue;
            }

            if ($typeLower === 'saving') {
                $valid[] = [
                    'row' => $rowNum, 'kind' => 'saving', 'pot_name' => $category,
                    'pot_known' => isset($pots[strtolower($category)]),
                    'amount' => $amount, 'date' => $date, 'note' => $notes ?: $description,
                ];
                continue;
            }

            $map = $typeLower === 'income' ? $incomeCategories : $expenseCategories;
            $valid[] = [
                'row' => $rowNum, 'kind' => $typeLower, 'category_name' => $category,
                'category_known' => isset($map[strtolower($category)]),
                'category_id' => $map[strtolower($category)]['id'] ?? null,
                'description' => $description, 'amount' => abs($amount), 'date' => $date, 'note' => $notes,
            ];
        }

        return ['valid' => $valid, 'errors' => $errors];
    }

    /**
     * Commits previously validated rows. Missing categories/pots are
     * created automatically (as expense/income categories or general
     * savings pots) so a bulk historical import never blocks on setup.
     */
    public static function commit(int $ownerId, array $validRows, string $filename): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $batchStmt = $pdo->prepare('INSERT INTO import_batches (owner_user_id, filename, rows_imported) VALUES (:owner, :filename, 0)');
            $batchStmt->execute(['owner' => $ownerId, 'filename' => $filename]);
            $batchId = (int) $pdo->lastInsertId();

            $categoryCache = [];
            $potCache = [];
            $imported = 0;

            $insertTxn = $pdo->prepare(
                'INSERT INTO transactions (owner_user_id, kind, category_id, description, amount, txn_date, source, import_batch_id)
                 VALUES (:owner, :kind, :category_id, :description, :amount, :date, "import", :batch)'
            );
            $insertSaving = $pdo->prepare(
                'INSERT INTO savings_transactions (pot_id, amount, txn_date, note, import_batch_id) VALUES (:pot, :amount, :date, :note, :batch)'
            );

            foreach ($validRows as $row) {
                if ($row['kind'] === 'saving') {
                    $potId = self::resolvePot($pdo, $ownerId, $row['pot_name'], $potCache);
                    $insertSaving->execute([
                        'pot' => $potId, 'amount' => $row['amount'], 'date' => $row['date'],
                        'note' => $row['note'] ?: null, 'batch' => $batchId,
                    ]);
                } else {
                    $categoryId = $row['category_id'] ?? self::resolveCategory($pdo, $ownerId, $row['kind'], $row['category_name'], $categoryCache);
                    $insertTxn->execute([
                        'owner' => $ownerId, 'kind' => $row['kind'], 'category_id' => $categoryId,
                        'description' => $row['description'] ?: null, 'amount' => $row['amount'],
                        'date' => $row['date'], 'batch' => $batchId,
                    ]);
                }
                $imported++;
            }

            $update = $pdo->prepare('UPDATE import_batches SET rows_imported = :count WHERE id = :id');
            $update->execute(['count' => $imported, 'id' => $batchId]);

            $pdo->commit();
            Audit::log($ownerId, 'import_committed', 'import_batches', $batchId, ['rows' => $imported]);

            return $batchId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function undo(int $ownerId, int $batchId): void
    {
        $pdo = Database::pdo();
        $check = $pdo->prepare('SELECT id FROM import_batches WHERE id = :id AND owner_user_id = :owner');
        $check->execute(['id' => $batchId, 'owner' => $ownerId]);
        if ($check->fetchColumn() === false) {
            return;
        }

        $pdo->prepare('DELETE FROM transactions WHERE import_batch_id = :id AND owner_user_id = :owner')->execute(['id' => $batchId, 'owner' => $ownerId]);
        $pdo->prepare('DELETE FROM savings_transactions WHERE import_batch_id = :id')->execute(['id' => $batchId]);
        $pdo->prepare('DELETE FROM import_batches WHERE id = :id')->execute(['id' => $batchId]);

        Audit::log($ownerId, 'import_undone', 'import_batches', $batchId);
    }

    private static function resolveCategory(\PDO $pdo, int $ownerId, string $kind, string $name, array &$cache): int
    {
        $key = $kind . ':' . strtolower($name);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $stmt = $pdo->prepare('SELECT id FROM categories WHERE owner_user_id = :owner AND kind = :kind AND LOWER(name) = :name');
        $stmt->execute(['owner' => $ownerId, 'kind' => $kind, 'name' => strtolower($name)]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            $insert = $pdo->prepare(
                'INSERT INTO categories (owner_user_id, kind, name, sort_order) VALUES (:owner, :kind, :name, :sort)'
            );
            $insert->execute(['owner' => $ownerId, 'kind' => $kind, 'name' => $name, 'sort' => Categories::nextSortOrder($ownerId, $kind)]);
            $id = (int) $pdo->lastInsertId();
        }

        $cache[$key] = (int) $id;
        return (int) $id;
    }

    private static function resolvePot(\PDO $pdo, int $ownerId, string $name, array &$cache): int
    {
        $key = strtolower($name);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $stmt = $pdo->prepare('SELECT id FROM savings_pots WHERE owner_user_id = :owner AND LOWER(name) = :name');
        $stmt->execute(['owner' => $ownerId, 'name' => strtolower($name)]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            $insert = $pdo->prepare("INSERT INTO savings_pots (owner_user_id, name, pot_type) VALUES (:owner, :name, 'general')");
            $insert->execute(['owner' => $ownerId, 'name' => $name]);
            $id = (int) $pdo->lastInsertId();
        }

        $cache[$key] = (int) $id;
        return (int) $id;
    }

    private static function nameMap(array $rows, string $key = 'name'): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[strtolower($row[$key])] = $row;
        }
        return $map;
    }

    private static function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);
                return $dt->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $value = trim((string) $value);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'm/d/Y'] as $format) {
            $dt = \DateTime::createFromFormat('!' . $format, $value);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }
}
