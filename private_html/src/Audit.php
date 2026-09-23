<?php

declare(strict_types=1);

final class Audit
{
    public static function log(?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, array $meta = []): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO audit_log (user_id, action, entity_type, entity_id, meta, ip_address, created_at)
             VALUES (:user_id, :action, :entity_type, :entity_id, :meta, :ip, NOW())'
        );

        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'meta' => $meta === [] ? null : json_encode($meta),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}
