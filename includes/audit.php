<?php

declare(strict_types=1);

/**
 * Logs an action to the audit_logs table.
 *
 * @param PDO $pdo Database connection
 * @param int|null $actorUserId User who performed the action (null for system actions)
 * @param string $action Action performed (e.g., 'payment_recorded', 'customer_created')
 * @param string|null $targetTable Table affected (e.g., 'payments', 'customers')
 * @param int|null $targetId Primary key of affected record
 * @param array $metadata Additional context as associative array
 * @return void
 */
function audit_log(
    PDO $pdo,
    ?int $actorUserId,
    string $action,
    ?string $targetTable = null,
    ?int $targetId = null,
    array $metadata = []
): void {
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (actor_user_id, action, target_table, target_id, metadata, created_at)
        VALUES (:actor, :action, :target_table, :target_id, :metadata, NOW())
    ");

    $stmt->execute([
        ':actor' => $actorUserId,
        ':action' => $action,
        ':target_table' => $targetTable,
        ':target_id' => $targetId,
        ':metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);
}
