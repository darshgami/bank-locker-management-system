<?php
require_once 'config/config.php';
$db = getDB();

$tables = ['payments', 'lockers', 'locker_assignments'];

ob_start();
foreach ($tables as $table) {
    echo "--- Table: $table ---\n";
    $st = $db->query("DESCRIBE $table");
    while ($row = $st->fetch()) {
        echo "Field: {$row['Field']} | Type: {$row['Type']} | Null: {$row['Null']} | Key: {$row['Key']} | Default: {$row['Default']}\n";
    }
    echo "\n";
}
file_put_contents('schema_output.txt', ob_get_clean());
echo "Schema written to schema_output.txt\n";
