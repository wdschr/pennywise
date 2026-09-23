<?php

declare(strict_types=1);

final class Categories
{
    public static function forOwner(int $ownerId, ?string $kind = null, bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM categories WHERE owner_user_id = :owner';
        $params = ['owner' => $ownerId];

        if ($kind !== null) {
            $sql .= ' AND kind = :kind';
            $params['kind'] = $kind;
        }
        if (!$includeArchived) {
            $sql .= ' AND is_archived = 0';
        }
        $sql .= ' ORDER BY sort_order, name';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function presetsForCategory(int $categoryId, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM preset_items WHERE category_id = :cat';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order, name';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(['cat' => $categoryId]);

        return $stmt->fetchAll();
    }

    public static function allPresetsForOwner(int $ownerId, bool $activeOnly = true): array
    {
        $sql = 'SELECT pi.* FROM preset_items pi WHERE pi.owner_user_id = :owner';
        if ($activeOnly) {
            $sql .= ' AND pi.is_active = 1';
        }
        $sql .= ' ORDER BY pi.sort_order, pi.name';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(['owner' => $ownerId]);

        return $stmt->fetchAll();
    }

    public static function nextSortOrder(int $ownerId, string $kind): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next FROM categories WHERE owner_user_id = :owner AND kind = :kind'
        );
        $stmt->execute(['owner' => $ownerId, 'kind' => $kind]);

        return (int) $stmt->fetchColumn();
    }
}
