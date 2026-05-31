<?php
/**
 * Basic export test - verify script runs without errors
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

echo "Testing database connection...\n";

try {
    $db = getDbConnection();
    echo "✓ Database connected successfully\n";

    // Test basic query
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM member LIMIT 1");
    $result = $stmt->fetch();
    echo "✓ Member table accessible (count: {$result['cnt']})\n";

    // Test eventEntry exists
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM eventEntry LIMIT 1");
    $result = $stmt->fetch();
    echo "✓ eventEntry table accessible (count: {$result['cnt']})\n";

    echo "\nAll tests passed!\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
