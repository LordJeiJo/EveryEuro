<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/db.php';

$user = require_session();

$month = $_GET['month'] ?? '';
if ($month === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = read_json_body();
    $month = $body['month'] ?? '';
}

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    json_response(['ok' => false, 'error' => 'Mes inválido.'], 400);
}

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare('SELECT data_json, updated_at FROM budgets WHERE user_id = :user_id AND month = :month');
    $stmt->execute([
        'user_id' => $user['user_id'],
        'month' => $month,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        json_response(['ok' => true, 'data' => null]);
    }

    $data = json_decode($row['data_json'], true);
    json_response([
        'ok' => true,
        'data' => $data,
        'updated_at' => $row['updated_at'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = read_json_body();
    $data = $body['data'] ?? null;

    if (!is_array($data)) {
        json_response(['ok' => false, 'error' => 'Datos inválidos.'], 400);
    }

    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = date('c');

    $stmt = $pdo->prepare('INSERT INTO budgets (user_id, month, data_json, updated_at)
        VALUES (:user_id, :month, :data_json, :updated_at)
        ON CONFLICT(user_id, month) DO UPDATE SET data_json = excluded.data_json, updated_at = excluded.updated_at');
    $stmt->execute([
        'user_id' => $user['user_id'],
        'month' => $month,
        'data_json' => $encoded,
        'updated_at' => $now,
    ]);

    json_response(['ok' => true, 'updated_at' => $now]);
}

json_response(['ok' => false, 'error' => 'Método no permitido.'], 405);
