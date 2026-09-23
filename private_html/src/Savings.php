<?php

declare(strict_types=1);

final class Savings
{
    public static function potsForOwner(int $ownerId, bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM savings_pots WHERE owner_user_id = :owner';
        if (!$includeArchived) {
            $sql .= ' AND is_archived = 0';
        }
        $sql .= ' ORDER BY created_at';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(['owner' => $ownerId]);
        $pots = $stmt->fetchAll();

        foreach ($pots as &$pot) {
            $pot['balance'] = self::balance((int) $pot['id']);
        }

        return $pots;
    }

    public static function find(int $id, int $ownerId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM savings_pots WHERE id = :id AND owner_user_id = :owner');
        $stmt->execute(['id' => $id, 'owner' => $ownerId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row['balance'] = self::balance((int) $row['id']);
        return $row;
    }

    public static function balance(int $potId): float
    {
        $stmt = Database::pdo()->prepare('SELECT COALESCE(SUM(amount), 0) FROM savings_transactions WHERE pot_id = :pot');
        $stmt->execute(['pot' => $potId]);

        return (float) $stmt->fetchColumn();
    }

    public static function transactions(int $potId, int $limit = 200): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM savings_transactions WHERE pot_id = :pot ORDER BY txn_date DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute(['pot' => $potId]);

        return $stmt->fetchAll();
    }

    /**
     * Running balance over time for a pot, one point per transaction date.
     */
    public static function balanceHistory(int $potId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT txn_date, amount FROM savings_transactions WHERE pot_id = :pot ORDER BY txn_date ASC, id ASC'
        );
        $stmt->execute(['pot' => $potId]);

        $running = 0.0;
        $points = [];
        foreach ($stmt->fetchAll() as $row) {
            $running += (float) $row['amount'];
            $points[] = ['date' => $row['txn_date'], 'balance' => round($running, 2)];
        }

        return $points;
    }

    public static function totalBalanceForOwner(int $ownerId): float
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(st.amount), 0)
             FROM savings_transactions st
             JOIN savings_pots sp ON sp.id = st.pot_id
             WHERE sp.owner_user_id = :owner'
        );
        $stmt->execute(['owner' => $ownerId]);

        return (float) $stmt->fetchColumn();
    }
}
