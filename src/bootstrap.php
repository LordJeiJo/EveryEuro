<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config.php';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

session_start();

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$baseUrl = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($baseUrl === '.' || $baseUrl === '/') {
    $baseUrl = '';
}
define('BASE_URL', $baseUrl);

function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function app_url(string $path = ''): string {
    $base = BASE_URL;
    if ($path === '' || $path === '/') {
        return $base !== '' ? $base : '/';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    return $base . $path;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('CSRF token inválido.');
    }
}

function is_logged_in(): bool {
    return !empty($_SESSION['user']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . app_url('index.php?page=login'));
        exit;
    }
}

function db(array $config): PDO {
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('PRAGMA table_info(' . $table . ')');
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
    return in_array($column, $columns, true);
}

function init_db(array $config): void {
    $pdo = db($config);
    $pdo->exec('CREATE TABLE IF NOT EXISTS movements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        fecha TEXT NOT NULL,
        descripcion TEXT NOT NULL,
        importe REAL NOT NULL,
        categoria TEXT NOT NULL,
        notas TEXT,
        estado TEXT NOT NULL,
        mes TEXT NOT NULL,
        cuenta TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre TEXT NOT NULL,
        orden INTEGER NOT NULL DEFAULT 0,
        activa INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre TEXT NOT NULL,
        tipo TEXT NOT NULL,
        orden INTEGER NOT NULL DEFAULT 0,
        activa INTEGER NOT NULL DEFAULT 1,
        is_favorite INTEGER NOT NULL DEFAULT 0,
        keywords TEXT NOT NULL DEFAULT ""
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        patron TEXT NOT NULL,
        categoria TEXT NOT NULL,
        prioridad INTEGER NOT NULL DEFAULT 0,
        tipo TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS budgets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        month TEXT NOT NULL,
        category_id INTEGER NOT NULL,
        planned_amount REAL NOT NULL DEFAULT 0,
        notes TEXT
    )');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS budgets_month_category ON budgets (month, category_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS extraordinary_expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        month TEXT NOT NULL,
        descripcion TEXT NOT NULL,
        importe REAL NOT NULL,
        categoria_id INTEGER,
        notas TEXT,
        position INTEGER NOT NULL DEFAULT 0
    )');

    if (!column_exists($pdo, 'extraordinary_expenses', 'position')) {
        $pdo->exec('ALTER TABLE extraordinary_expenses ADD COLUMN position INTEGER NOT NULL DEFAULT 0');
    }
    $pdo->exec('UPDATE extraordinary_expenses SET position = id WHERE position IS NULL OR position = 0');

    if (!column_exists($pdo, 'categories', 'is_favorite')) {
        $pdo->exec('ALTER TABLE categories ADD COLUMN is_favorite INTEGER NOT NULL DEFAULT 0');
    }
    if (!column_exists($pdo, 'categories', 'keywords')) {
        $pdo->exec('ALTER TABLE categories ADD COLUMN keywords TEXT NOT NULL DEFAULT ""');
    }

    $count = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if ($count === 0) {
        $seed = [
            ['Ingresos', 'ingreso'],
            ['Vivienda', 'gasto'],
            ['Alimentación', 'gasto'],
            ['Transporte', 'gasto'],
            ['Suscripciones', 'gasto'],
            ['Ocio', 'gasto'],
            ['Salud', 'gasto'],
            ['Educación', 'gasto'],
            ['Compras', 'gasto'],
            ['Regalos', 'gasto'],
            ['Inversión', 'ahorro'],
            ['Ahorro', 'ahorro'],
            ['Imprevistos', 'gasto'],
        ];
        $stmt = $pdo->prepare('INSERT INTO categories (nombre, tipo, orden, activa) VALUES (?, ?, ?, 1)');
        foreach ($seed as $idx => $row) {
            $stmt->execute([$row[0], $row[1], $idx]);
        }
    }

    $accountCount = (int)$pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
    if ($accountCount === 0) {
        $accounts = [
            'Habitual',
            'Tarjeta',
            'Efectivo',
            'Ahorro',
        ];
        $stmt = $pdo->prepare('INSERT INTO accounts (nombre, orden, activa) VALUES (?, ?, 1)');
        foreach ($accounts as $idx => $name) {
            $stmt->execute([$name, $idx]);
        }
    }
}

function month_from_date(string $date): string {
    return substr($date, 0, 7);
}

function format_amount(float $amount): string {
    $formatted = number_format(abs($amount), 2, ',', '.');
    return $amount < 0 ? "-{$formatted}" : $formatted;
}
