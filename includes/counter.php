<?php

declare(strict_types=1);

/**
 * Atomically increments a counter and returns the next value.
 * Uses SELECT FOR UPDATE to prevent race conditions.
 *
 * @param PDO $pdo Active PDO connection with a transaction started
 * @param string $counterName Name of the counter (e.g., 'order_number', 'invoice_number')
 * @return int The next counter value
 * @throws PDOException if counter doesn't exist or lock fails
 */
function get_next_counter(PDO $pdo, string $counterName): int
{
    // Lock the counter row for update (prevents race conditions)
    $stmt = $pdo->prepare("
        SELECT current_value
        FROM counters
        WHERE name = :name
        FOR UPDATE
    ");
    $stmt->execute([':name' => $counterName]);

    $current = $stmt->fetchColumn();
    if ($current === false) {
        throw new RuntimeException("Counter '{$counterName}' not found in counters table");
    }

    $next = (int)$current + 1;

    // Update the counter
    $updateStmt = $pdo->prepare("
        UPDATE counters
        SET current_value = :value
        WHERE name = :name
    ");
    $updateStmt->execute([
        ':value' => $next,
        ':name' => $counterName
    ]);

    return $next;
}

/**
 * Generates a formatted order number using atomic counter.
 * Format: {customerId}-{salespersonId}-{sequence} (e.g., 5-3-000021)
 *
 * @param PDO $pdo Active PDO connection (must be in transaction)
 * @param int $customerId The customer ID
 * @param int $salespersonId The salesperson ID
 * @return string Formatted order number
 */
function generate_order_number(PDO $pdo, int $customerId, int $salespersonId): string
{
    $number = get_next_counter($pdo, 'order_number');
    $sequence = str_pad((string)$number, 6, '0', STR_PAD_LEFT);

    return $customerId . '-' . $salespersonId . '-' . $sequence;
}


/**
 * Generates a formatted invoice number using atomic counter.
 * Format: {customerId}-{salespersonId}-{sequence} (e.g., 5-3-000021)
 *
 * @param PDO $pdo Active PDO connection (must be in transaction)
 * @param int $customerId The customer ID
 * @param int $salespersonId The salesperson ID
 * @return string Formatted invoice number
 */
function generate_invoice_number(PDO $pdo, int $customerId = 0, int $salespersonId = 0): string
{
    $number = get_next_counter($pdo, 'invoice_number');
    $sequence = str_pad((string)$number, 6, '0', STR_PAD_LEFT);

    if ($customerId > 0 && $salespersonId > 0) {
        return $customerId . '-' . $salespersonId . '-' . $sequence;
    }

    return 'INV-' . $sequence;
}
