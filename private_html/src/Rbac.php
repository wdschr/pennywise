<?php

declare(strict_types=1);

/**
 * Every user owns a fully private budget. Access to someone else's data is
 * only possible in two ways:
 *   1. An admin, viewing any budget read-only for oversight.
 *   2. A user who has been granted read-only access to specific categories
 *      of another user's budget (category_shares).
 *
 * ViewContext resolves "whose data am I looking at, and what can I do with
 * it" for the current request, based on an optional ?owner=<id> query param.
 */
final class Rbac
{
    /**
     * @return array{owner_id:int, owner_name:string, can_edit:bool, category_ids:?array, is_self:bool}
     */
    public static function context(array $currentUser, ?string $requestedOwnerId = null): array
    {
        $selfContext = [
            'owner_id' => (int) $currentUser['id'],
            'owner_name' => $currentUser['display_name'],
            'can_edit' => true,
            'category_ids' => null,
            'is_self' => true,
        ];

        if ($requestedOwnerId === null || $requestedOwnerId === '' || (int) $requestedOwnerId === (int) $currentUser['id']) {
            return $selfContext;
        }

        $ownerId = (int) $requestedOwnerId;
        $owner = self::findUser($ownerId);
        if ($owner === null) {
            return $selfContext;
        }

        if ($currentUser['role'] === 'admin') {
            return [
                'owner_id' => $ownerId,
                'owner_name' => $owner['display_name'],
                'can_edit' => false,
                'category_ids' => null,
                'is_self' => false,
            ];
        }

        $categoryIds = self::sharedCategoryIds($ownerId, (int) $currentUser['id']);
        if ($categoryIds === []) {
            return $selfContext;
        }

        return [
            'owner_id' => $ownerId,
            'owner_name' => $owner['display_name'],
            'can_edit' => false,
            'category_ids' => $categoryIds,
            'is_self' => false,
        ];
    }

    public static function findUser(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT id, display_name, email, role FROM users WHERE id = :id AND is_active = 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return int[]
     */
    public static function sharedCategoryIds(int $ownerId, int $viewerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT category_id FROM category_shares WHERE owner_user_id = :owner AND shared_with_user_id = :viewer'
        );
        $stmt->execute(['owner' => $ownerId, 'viewer' => $viewerId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'category_id'));
    }

    /**
     * Budgets shared with the current user (for the "Shared with me" nav).
     * @return array<int, array{id:int, display_name:string}>
     */
    public static function ownersSharedWithMe(int $viewerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT DISTINCT u.id, u.display_name
             FROM category_shares cs
             JOIN users u ON u.id = cs.owner_user_id
             WHERE cs.shared_with_user_id = :viewer AND u.is_active = 1
             ORDER BY u.display_name'
        );
        $stmt->execute(['viewer' => $viewerId]);

        return $stmt->fetchAll();
    }

    /**
     * All active users other than the given one (used to populate the
     * admin "view as" picker and the category-sharing picker).
     */
    public static function otherActiveUsers(int $excludeId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, display_name, email, role FROM users WHERE id != :id AND is_active = 1 ORDER BY display_name'
        );
        $stmt->execute(['id' => $excludeId]);

        return $stmt->fetchAll();
    }
}
