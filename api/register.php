<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Método no permitido.'], 405);
}

$data = read_json_body();
$email = strtolower(trim($data['email'] ?? ''));
$password = $data['password'] ?? '';

if ($email === '' || $password === '') {
    json_response(['ok' => false, 'error' => 'Email y contraseña son obligatorios.'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Email no válido.'], 400);
}

if (strlen($password) < 6) {
    json_response(['ok' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres.'], 400);
}

$pdo = db();
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, created_at) VALUES (:email, :hash, :created_at)');
    $stmt->execute([
        'email' => $email,
        'hash' => $hash,
        'created_at' => date('c'),
    ]);
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'UNIQUE')) {
        json_response(['ok' => false, 'error' => 'Ese email ya está registrado.'], 409);
    }

    json_response(['ok' => false, 'error' => 'No se pudo crear el usuario.'], 500);
}

$_SESSION['user_id'] = (int) $pdo->lastInsertId();
$_SESSION['email'] = $email;

json_response([
    'ok' => true,
    'email' => $email,
]);
