<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

session_unset();
session_destroy();

json_response(['ok' => true]);
