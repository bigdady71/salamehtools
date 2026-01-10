<?php
/**
 * Quick script to check and remove all triggers on s_stock table
 */
require_once __DIR__ . '/bootstrap.php';

$pdo = db();

echo "<h1>Database Trigger Inspector</h1>";

// Check triggers on order_items table (potential source of double deduction)
echo "<h2>Checking triggers on ORDER_ITEMS table...</h2>";
$orderItemsTriggers = $pdo->query("SHOW TRIGGERS WHERE `Table` = 'order_items'")->fetchAll(PDO::FETCH_ASSOC);
if (empty($orderItemsTriggers)) {
    echo "<p style='color: green;'>No triggers found on order_items table.</p>";
} else {
    echo "<p style='color: red; font-weight: bold;'>FOUND " . count($orderItemsTriggers) . " trigger(s) on order_items - THIS IS LIKELY THE CAUSE OF DOUBLE DEDUCTION!</p>";
    foreach ($orderItemsTriggers as $t) {
        echo "<div style='background:#fee;padding:10px;margin:10px 0;border:2px solid red;'>";
        echo "<strong>" . htmlspecialchars($t['Trigger']) . "</strong> - " . $t['Event'] . " " . $t['Timing'] . "<br>";
        echo "<pre style='font-size:11px;'>" . htmlspecialchars($t['Statement']) . "</pre>";
        echo "<a href='?drop=" . urlencode($t['Trigger']) . "' style='background:#dc2626;color:white;padding:8px 16px;text-decoration:none;border-radius:5px;font-weight:bold;' onclick='return confirm(\"Drop this trigger?\")'>DROP THIS TRIGGER</a>";
        echo "</div>";
    }
}

// Check triggers on orders table
echo "<h2>Checking triggers on ORDERS table...</h2>";
$ordersTriggers = $pdo->query("SHOW TRIGGERS WHERE `Table` = 'orders'")->fetchAll(PDO::FETCH_ASSOC);
if (empty($ordersTriggers)) {
    echo "<p style='color: green;'>No triggers found on orders table.</p>";
} else {
    echo "<p style='color: orange;'>Found " . count($ordersTriggers) . " trigger(s) on orders table:</p>";
    foreach ($ordersTriggers as $t) {
        echo "<div style='background:#fef3c7;padding:10px;margin:10px 0;border:2px solid orange;'>";
        echo "<strong>" . htmlspecialchars($t['Trigger']) . "</strong> - " . $t['Event'] . " " . $t['Timing'] . "<br>";
        echo "<pre style='font-size:11px;'>" . htmlspecialchars($t['Statement']) . "</pre>";
        echo "<a href='?drop=" . urlencode($t['Trigger']) . "' style='background:#f97316;color:white;padding:8px 16px;text-decoration:none;border-radius:5px;' onclick='return confirm(\"Drop this trigger?\")'>DROP</a>";
        echo "</div>";
    }
}

echo "<h2>Checking triggers on S_STOCK table...</h2>";

// Get all triggers for s_stock table
$triggers = $pdo->query("SHOW TRIGGERS WHERE `Table` = 's_stock'")->fetchAll(PDO::FETCH_ASSOC);

if (empty($triggers)) {
    echo "<p style='color: green;'>No triggers found on s_stock table.</p>";
} else {
    echo "<p style='color: red;'>Found " . count($triggers) . " trigger(s):</p>";
    echo "<ul>";
    foreach ($triggers as $trigger) {
        echo "<li><strong>" . htmlspecialchars($trigger['Trigger']) . "</strong> - " . htmlspecialchars($trigger['Event']) . " " . htmlspecialchars($trigger['Timing']) . "</li>";
    }
    echo "</ul>";

    // Drop all triggers
    if (isset($_GET['fix']) && $_GET['fix'] === '1') {
        echo "<h3>Removing all triggers...</h3>";
        foreach ($triggers as $trigger) {
            try {
                $pdo->exec("DROP TRIGGER IF EXISTS `" . $trigger['Trigger'] . "`");
                echo "<p style='color: green;'>Dropped trigger: " . htmlspecialchars($trigger['Trigger']) . "</p>";
            } catch (Exception $e) {
                echo "<p style='color: red;'>Failed to drop " . htmlspecialchars($trigger['Trigger']) . ": " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }
        echo "<p><strong>Done! <a href='check_triggers.php'>Click here to verify</a></strong></p>";
    } else {
        echo "<p><a href='?fix=1' style='background: #ef4444; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Click here to REMOVE ALL TRIGGERS</a></p>";
    }
}

// Also check for any other triggers in the database
echo "<h2>All triggers in database:</h2>";
$allTriggers = $pdo->query("SHOW TRIGGERS")->fetchAll(PDO::FETCH_ASSOC);
if (empty($allTriggers)) {
    echo "<p>No triggers found in database.</p>";
} else {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Trigger</th><th>Event</th><th>Table</th><th>Timing</th><th>Statement</th><th>Action</th></tr>";
    foreach ($allTriggers as $t) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($t['Trigger']) . "</td>";
        echo "<td>" . htmlspecialchars($t['Event']) . "</td>";
        echo "<td>" . htmlspecialchars($t['Table']) . "</td>";
        echo "<td>" . htmlspecialchars($t['Timing']) . "</td>";
        echo "<td><pre style='max-width:400px;overflow:auto;font-size:11px;'>" . htmlspecialchars(substr($t['Statement'] ?? '', 0, 500)) . "</pre></td>";
        echo "<td><a href='?drop=" . urlencode($t['Trigger']) . "' style='background:#ef4444;color:white;padding:5px 10px;text-decoration:none;border-radius:3px;' onclick='return confirm(\"Drop this trigger?\")'>Drop</a></td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "<p style='margin-top:20px;'><a href='?drop_all=1' style='background:#dc2626;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;font-weight:bold;' onclick='return confirm(\"DROP ALL TRIGGERS? This cannot be undone!\")'>DROP ALL TRIGGERS</a></p>";
}

// Handle drop single trigger
if (isset($_GET['drop'])) {
    $triggerName = $_GET['drop'];
    try {
        $pdo->exec("DROP TRIGGER IF EXISTS `" . preg_replace('/[^a-zA-Z0-9_]/', '', $triggerName) . "`");
        echo "<p style='color:green;font-weight:bold;'>Dropped trigger: " . htmlspecialchars($triggerName) . " - <a href='check_triggers.php'>Refresh</a></p>";
    } catch (Exception $e) {
        echo "<p style='color:red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// Handle drop all triggers
if (isset($_GET['drop_all']) && $_GET['drop_all'] === '1') {
    echo "<h3>Dropping ALL triggers...</h3>";
    foreach ($allTriggers as $t) {
        try {
            $pdo->exec("DROP TRIGGER IF EXISTS `" . $t['Trigger'] . "`");
            echo "<p style='color:green;'>Dropped: " . htmlspecialchars($t['Trigger']) . "</p>";
        } catch (Exception $e) {
            echo "<p style='color:red;'>Failed to drop " . htmlspecialchars($t['Trigger']) . ": " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    echo "<p><strong><a href='check_triggers.php'>Click to verify all triggers removed</a></strong></p>";
}
