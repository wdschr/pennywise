<?php

declare(strict_types=1);

final class Transactions
{
    /**
     * @param array{kind?:string, category_id?:int, from?:string, to?:string, q?:string, category_ids?:int[]} $filters
     */
    public static function query(int $ownerId, array $filters = [], int $limit = 500): array
    {
        [$where, $params] = self::buildWhere($ownerId, $filters);

        $stmt = Database::pdo()->prepare(
            "SELECT t.*, c.name AS category_name, c.color AS category_color
             FROM transactions t
             JOIN categories c ON c.id = t.category_id
             WHERE {$where}
             ORDER BY t.txn_date DESC, t.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private static function buildWhere(int $ownerId, array $filters): array
    {
        $where = ['t.owner_user_id = :owner'];
        $params = ['owner' => $ownerId];

        if (!empty($filters['kind']) && in_array($filters['kind'], ['income', 'expense'], true)) {
            $where[] = 't.kind = :kind';
            $params['kind'] = $filters['kind'];
        }

        if (!empty($filters['category_id'])) {
            $where[] = 't.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }

        if (!empty($filters['from'])) {
            $where[] = 't.txn_date >= :from';
            $params['from'] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 't.txn_date <= :to';
            $params['to'] = $filters['to'];
        }

        if (!empty($filters['q'])) {
            $where[] = '(t.description LIKE :q OR t.notes LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if (array_key_exists('category_ids', $filters) && $filters['category_ids'] !== null) {
            if ($filters['category_ids'] === []) {
                $where[] = '1 = 0';
            } else {
                $placeholders = [];
                foreach ($filters['category_ids'] as $i => $catId) {
                    $key = "cat_restrict_{$i}";
                    $placeholders[] = ":{$key}";
                    $params[$key] = (int) $catId;
                }
                $where[] = 't.category_id IN (' . implode(',', $placeholders) . ')';
            }
        }

        return [implode(' AND ', $where), $params];
    }

    public static function find(int $id, int $ownerId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM transactions WHERE id = :id AND owner_user_id = :owner');
        $stmt->execute(['id' => $id, 'owner' => $ownerId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array{income: float, expense: float, net: float, by_category: array}
     */
    public static function summary(int $ownerId, string $from, string $to, ?array $categoryIds = null): array
    {
        $filters = ['from' => $from, 'to' => $to];
        if ($categoryIds !== null) {
            $filters['category_ids'] = $categoryIds;
        }
        [$where, $params] = self::buildWhere($ownerId, $filters);

        $stmt = Database::pdo()->prepare(
            "SELECT t.kind, SUM(t.amount) AS total FROM transactions t WHERE {$where} GROUP BY t.kind"
        );
        $stmt->execute($params);

        $income = 0.0;
        $expense = 0.0;
        foreach ($stmt->fetchAll() as $row) {
            if ($row['kind'] === 'income') {
                $income = (float) $row['total'];
            } else {
                $expense = (float) $row['total'];
            }
        }

        $catStmt = Database::pdo()->prepare(
            "SELECT c.id, c.name, c.color, c.kind, SUM(t.amount) AS total
             FROM transactions t JOIN categories c ON c.id = t.category_id
             WHERE {$where}
             GROUP BY c.id, c.name, c.color, c.kind
             ORDER BY total DESC"
        );
        $catStmt->execute($params);

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'by_category' => $catStmt->fetchAll(),
        ];
    }

    /**
     * Monthly totals between two months (inclusive), for trend charts.
     * @return array<int, array{month:string, income:float, expense:float}>
     */
    public static function monthlyTrend(int $ownerId, string $fromMonth, string $toMonth, ?array $categoryIds = null): array
    {
        $filters = [
            'from' => $fromMonth . '-01',
            'to' => date('Y-m-t', strtotime($toMonth . '-01')),
        ];
        if ($categoryIds !== null) {
            $filters['category_ids'] = $categoryIds;
        }
        [$where, $params] = self::buildWhere($ownerId, $filters);

        $stmt = Database::pdo()->prepare(
            "SELECT DATE_FORMAT(t.txn_date, '%Y-%m') AS ym, t.kind, SUM(t.amount) AS total
             FROM transactions t WHERE {$where}
             GROUP BY ym, t.kind ORDER BY ym"
        );
        $stmt->execute($params);

        $months = [];
        $cursor = $fromMonth;
        while ($cursor <= $toMonth) {
            $months[$cursor] = ['month' => $cursor, 'income' => 0.0, 'expense' => 0.0];
            $cursor = date('Y-m', strtotime($cursor . '-01 +1 month'));
        }

        foreach ($stmt->fetchAll() as $row) {
            if (!isset($months[$row['ym']])) {
                continue;
            }
            $months[$row['ym']][$row['kind']] = (float) $row['total'];
        }

        return array_values($months);
    }
}
