<?php
require_once __DIR__ . '/config/db.php';
try {
    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sponsors (
            id SERIAL PRIMARY KEY, 
            name VARCHAR(100) NOT NULL, 
            image_path VARCHAR(255) NOT NULL, 
            created_at TIMESTAMPTZ DEFAULT NOW()
        );
    ");
    echo "Sponsors table created successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
