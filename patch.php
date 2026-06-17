<?php
require 'includes/db.php';
try {
    $pdo->exec("ALTER TABLE media MODIFY type VARCHAR(50) NOT NULL");
    echo "Success!";
} catch (Exception $e) {
    echo $e->getMessage();
}
