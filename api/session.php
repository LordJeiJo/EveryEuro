<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/db.php';

if (isset($_SESSION['user_id'])) {
    json_response([
        'ok' => true,
        'authenticated' => true,
        'email' => $_SESSION['email'] ?? '',
    ]);
}

json_response([
    'ok' => true,
    'authenticated' => false,
]);
