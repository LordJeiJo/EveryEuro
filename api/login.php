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

$pdo = db();
$stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password_hash'])) {
    json_response(['ok' => false, 'error' => 'Credenciales inválidas.'], 401);
}

$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['email'] = $email;

json_response([
    'ok' => true,
    'email' => $email,
]);
