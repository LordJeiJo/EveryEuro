<?php
require __DIR__ . '/src/bootstrap.php';

init_db($config);

$page = $_GET['page'] ?? 'movements';
$action = $_GET['action'] ?? null;

$pdo = db($config);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function redirect_with_message(string $message, string $location = '/index.php'): void {
    $_SESSION['flash'] = $message;
    header('Location: ' . $location);
    exit;
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $user = trim($_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? '';
    if ($user === $config['admin_user'] && password_verify($pass, $config['admin_pass_hash'])) {
        $_SESSION['user'] = $user;
        redirect_with_message('Bienvenido.');
    }
    redirect_with_message('Credenciales inválidas.', '/index.php?page=login');
}

if ($action === 'logout') {
    session_destroy();
    header('Location: /index.php?page=login');
    exit;
}

if ($page !== 'login') {
    require_login();
}

if ($action === 'add_movement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $importe = (float)str_replace(',', '.', $_POST['importe'] ?? '0');
    $categoria = trim($_POST['categoria'] ?? '');
    $notas = trim($_POST['notas'] ?? '');
    $cuenta = trim($_POST['cuenta'] ?? '');
    $estado = 'pendiente';
    $mes = month_from_date($fecha);

    if ($descripcion === '' || $categoria === '') {
        redirect_with_message('Completa descripción y categoría.', '/index.php');
    }

    $stmt = $pdo->prepare('INSERT INTO movements (fecha, descripcion, importe, categoria, notas, estado, mes, cuenta) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$fecha, $descripcion, $importe, $categoria, $notas, $estado, $mes, $cuenta]);
    redirect_with_message('Movimiento añadido.', '/index.php?month=' . urlencode($mes));
}

if ($action === 'delete_movement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM movements WHERE id = ?');
    $stmt->execute([$id]);
    redirect_with_message('Movimiento eliminado.', '/index.php');
}

if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $estado = $_POST['estado'] === 'revisado' ? 'revisado' : 'pendiente';
    $stmt = $pdo->prepare('UPDATE movements SET estado = ? WHERE id = ?');
    $stmt->execute([$estado, $id]);
    redirect_with_message('Estado actualizado.', '/index.php');
}

if ($action === 'quick_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $categoria = trim($_POST['categoria'] ?? '');
    if ($categoria !== '') {
        $stmt = $pdo->prepare('UPDATE movements SET categoria = ? WHERE id = ?');
        $stmt->execute([$categoria, $id]);
    }
    redirect_with_message('Categoría actualizada.', '/index.php');
}

if ($action === 'save_movement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $importe = (float)str_replace(',', '.', $_POST['importe'] ?? '0');
    $categoria = trim($_POST['categoria'] ?? '');
    $notas = trim($_POST['notas'] ?? '');
    $cuenta = trim($_POST['cuenta'] ?? '');
    $estado = $_POST['estado'] === 'revisado' ? 'revisado' : 'pendiente';
    $mes = month_from_date($fecha);

    $stmt = $pdo->prepare('UPDATE movements SET fecha = ?, descripcion = ?, importe = ?, categoria = ?, notas = ?, estado = ?, mes = ?, cuenta = ? WHERE id = ?');
    $stmt->execute([$fecha, $descripcion, $importe, $categoria, $notas, $estado, $mes, $cuenta, $id]);
    redirect_with_message('Movimiento actualizado.', '/index.php?month=' . urlencode($mes));
}

if ($action === 'save_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $tipo = $_POST['tipo'] ?? 'gasto';
    $orden = (int)($_POST['orden'] ?? 0);
    $activa = isset($_POST['activa']) ? 1 : 0;

    if ($nombre === '') {
        redirect_with_message('Nombre requerido.', '/index.php?page=categories');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE categories SET nombre = ?, tipo = ?, orden = ?, activa = ? WHERE id = ?');
        $stmt->execute([$nombre, $tipo, $orden, $activa, $id]);
        redirect_with_message('Categoría actualizada.', '/index.php?page=categories');
    }

    $stmt = $pdo->prepare('INSERT INTO categories (nombre, tipo, orden, activa) VALUES (?, ?, ?, ?)');
    $stmt->execute([$nombre, $tipo, $orden, $activa]);
    redirect_with_message('Categoría creada.', '/index.php?page=categories');
}

if ($action === 'delete_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    redirect_with_message('Categoría eliminada.', '/index.php?page=categories');
}

if ($action === 'export_backup') {
    $data = [
        'schema_version' => $config['schema_version'],
        'exported_at' => date('c'),
        'movements' => $pdo->query('SELECT * FROM movements')->fetchAll(),
        'categories' => $pdo->query('SELECT * FROM categories')->fetchAll(),
        'rules' => $pdo->query('SELECT * FROM rules')->fetchAll(),
    ];
    $filename = 'everyeuro-backup-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'import_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!isset($_POST['confirm_import'])) {
        redirect_with_message('Debes confirmar la importación.', '/index.php?page=backup');
    }
    if (!isset($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
        redirect_with_message('Archivo inválido.', '/index.php?page=backup');
    }
    $json = file_get_contents($_FILES['backup']['tmp_name']);
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        redirect_with_message('JSON inválido.', '/index.php?page=backup');
    }
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM movements');
        $pdo->exec('DELETE FROM categories');
        $pdo->exec('DELETE FROM rules');
        $insertMove = $pdo->prepare('INSERT INTO movements (id, fecha, descripcion, importe, categoria, notas, estado, mes, cuenta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($payload['movements'] ?? [] as $row) {
            $insertMove->execute([
                $row['id'],
                $row['fecha'],
                $row['descripcion'],
                $row['importe'],
                $row['categoria'],
                $row['notas'] ?? '',
                $row['estado'],
                $row['mes'],
                $row['cuenta'] ?? '',
            ]);
        }
        $insertCat = $pdo->prepare('INSERT INTO categories (id, nombre, tipo, orden, activa) VALUES (?, ?, ?, ?, ?)');
        foreach ($payload['categories'] ?? [] as $row) {
            $insertCat->execute([
                $row['id'],
                $row['nombre'],
                $row['tipo'],
                $row['orden'],
                $row['activa'],
            ]);
        }
        $insertRule = $pdo->prepare('INSERT INTO rules (id, patron, categoria, prioridad, tipo) VALUES (?, ?, ?, ?, ?)');
        foreach ($payload['rules'] ?? [] as $row) {
            $insertRule->execute([
                $row['id'],
                $row['patron'],
                $row['categoria'],
                $row['prioridad'],
                $row['tipo'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        redirect_with_message('Error al importar.', '/index.php?page=backup');
    }
    redirect_with_message('Backup importado.', '/index.php?page=backup');
}

$categories = $pdo->query('SELECT * FROM categories ORDER BY orden, nombre')->fetchAll();
$fav_categories = array_slice($categories, 0, 5);

$month = $_GET['month'] ?? date('Y-m');
$filters = [
    'status' => $_GET['status'] ?? 'all',
    'category' => $_GET['category'] ?? 'all',
    'search' => trim($_GET['search'] ?? ''),
];

$where = ['mes = ?'];
$params = [$month];
if ($filters['status'] === 'pendiente' || $filters['status'] === 'revisado') {
    $where[] = 'estado = ?';
    $params[] = $filters['status'];
}
if ($filters['category'] !== 'all' && $filters['category'] !== '') {
    $where[] = 'categoria = ?';
    $params[] = $filters['category'];
}
if ($filters['search'] !== '') {
    $where[] = '(descripcion LIKE ? OR notas LIKE ? OR cuenta LIKE ?)';
    $search = '%' . $filters['search'] . '%';
    $params = array_merge($params, [$search, $search, $search]);
}

$stmt = $pdo->prepare('SELECT * FROM movements WHERE ' . implode(' AND ', $where) . ' ORDER BY fecha DESC, id DESC');
$stmt->execute($params);
$movements = $stmt->fetchAll();

$summaryStmt = $pdo->prepare('SELECT categoria, SUM(importe) as total FROM movements WHERE mes = ? GROUP BY categoria ORDER BY categoria');
$summaryStmt->execute([$month]);
$totalsByCategory = $summaryStmt->fetchAll();

$totals = $pdo->prepare('SELECT SUM(CASE WHEN importe > 0 THEN importe ELSE 0 END) as ingresos, SUM(CASE WHEN importe < 0 THEN importe ELSE 0 END) as gastos FROM movements WHERE mes = ?');
$totals->execute([$month]);
$totalsRow = $totals->fetch();
$ingresos = (float)($totalsRow['ingresos'] ?? 0);
$gastos = (float)($totalsRow['gastos'] ?? 0);
$balance = $ingresos + $gastos;

function current_url(array $override = []): string {
    $params = array_merge($_GET, $override);
    return '/index.php?' . http_build_query($params);
}

?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EveryEuro</title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<div class="app" data-theme="light">
    <header class="topbar">
        <div class="brand">
            <span class="dot"></span>
            <div>
                <h1>EveryEuro</h1>
                <p>Registra y revisa tus movimientos al vuelo.</p>
            </div>
        </div>
        <?php if (is_logged_in()): ?>
        <nav class="nav">
            <a href="/index.php" class="<?= $page === 'movements' ? 'active' : '' ?>">Movimientos</a>
            <a href="/index.php?page=categories" class="<?= $page === 'categories' ? 'active' : '' ?>">Categorías</a>
            <a href="/index.php?page=summary" class="<?= $page === 'summary' ? 'active' : '' ?>">Resumen</a>
            <a href="/index.php?page=backup" class="<?= $page === 'backup' ? 'active' : '' ?>">Backup</a>
            <a href="/index.php?action=logout">Salir</a>
        </nav>
        <?php endif; ?>
        <button class="theme-toggle" type="button" id="themeToggle">Modo</button>
    </header>

    <?php if ($flash): ?>
        <div class="flash"><?= h($flash) ?></div>
    <?php endif; ?>

    <?php if ($page === 'login'): ?>
        <section class="panel login">
            <h2>Acceso</h2>
            <form method="post" action="/index.php?action=login">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <label>
                    Usuario
                    <input type="text" name="user" required>
                </label>
                <label>
                    Contraseña
                    <input type="password" name="pass" required>
                </label>
                <button type="submit" class="primary">Entrar</button>
            </form>
        </section>
    <?php elseif ($page === 'categories'): ?>
        <section class="panel">
            <h2>Categorías</h2>
            <form method="post" action="/index.php?action=save_category" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="id" value="0">
                <input type="text" name="nombre" placeholder="Nueva categoría" required>
                <select name="tipo">
                    <option value="ingreso">Ingreso</option>
                    <option value="gasto" selected>Gasto</option>
                    <option value="ahorro">Ahorro</option>
                    <option value="extra">Extra</option>
                </select>
                <input type="number" name="orden" value="0" min="0">
                <label class="switch">
                    <input type="checkbox" name="activa" checked>
                    <span>Activa</span>
                </label>
                <button type="submit" class="primary">Añadir</button>
            </form>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Tipo</th>
                            <th>Orden</th>
                            <th>Activa</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td><?= h($cat['nombre']) ?></td>
                                <td><?= h($cat['tipo']) ?></td>
                                <td><?= h((string)$cat['orden']) ?></td>
                                <td><?= $cat['activa'] ? 'Sí' : 'No' ?></td>
                                <td>
                                    <button class="ghost" type="button" data-edit='<?= h(json_encode($cat)) ?>'>Editar</button>
                                    <form method="post" action="/index.php?action=delete_category" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                        <button class="danger" type="submit">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <dialog id="categoryDialog">
                <form method="post" action="/index.php?action=save_category" class="dialog-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="id" id="catId" value="0">
                    <label>
                        Nombre
                        <input type="text" name="nombre" id="catNombre" required>
                    </label>
                    <label>
                        Tipo
                        <select name="tipo" id="catTipo">
                            <option value="ingreso">Ingreso</option>
                            <option value="gasto">Gasto</option>
                            <option value="ahorro">Ahorro</option>
                            <option value="extra">Extra</option>
                        </select>
                    </label>
                    <label>
                        Orden
                        <input type="number" name="orden" id="catOrden" min="0">
                    </label>
                    <label class="switch">
                        <input type="checkbox" name="activa" id="catActiva">
                        <span>Activa</span>
                    </label>
                    <menu>
                        <button type="button" class="ghost" id="closeCategory">Cancelar</button>
                        <button type="submit" class="primary">Guardar</button>
                    </menu>
                </form>
            </dialog>
        </section>
    <?php elseif ($page === 'summary'): ?>
        <section class="panel">
            <h2>Resumen mensual</h2>
            <form class="inline-form" method="get" action="/index.php">
                <input type="hidden" name="page" value="summary">
                <input type="month" name="month" value="<?= h($month) ?>">
                <button type="submit" class="ghost">Cambiar</button>
            </form>
            <div class="summary-grid">
                <div class="summary-card">
                    <h3>Ingresos</h3>
                    <p class="positive">€ <?= format_amount($ingresos) ?></p>
                </div>
                <div class="summary-card">
                    <h3>Gastos</h3>
                    <p class="negative">€ <?= format_amount($gastos) ?></p>
                </div>
                <div class="summary-card">
                    <h3>Balance</h3>
                    <p class="<?= $balance >= 0 ? 'positive' : 'negative' ?>">€ <?= format_amount($balance) ?></p>
                </div>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Categoría</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($totalsByCategory as $row): ?>
                            <tr>
                                <td><?= h($row['categoria']) ?></td>
                                <td>€ <?= format_amount((float)$row['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif ($page === 'backup'): ?>
        <section class="panel">
            <h2>Backup</h2>
            <div class="backup-actions">
                <a href="/index.php?action=export_backup" class="primary">Exportar backup</a>
            </div>
            <form method="post" action="/index.php?action=import_backup" enctype="multipart/form-data" class="panel nested">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <h3>Importar backup</h3>
                <input type="file" name="backup" accept="application/json" required>
                <label class="switch">
                    <input type="checkbox" name="confirm_import">
                    <span>Entiendo que esto sobrescribe los datos actuales</span>
                </label>
                <button type="submit" class="danger">Importar</button>
            </form>
        </section>
    <?php elseif ($page === 'edit_movement'):
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM movements WHERE id = ?');
        $stmt->execute([$id]);
        $movement = $stmt->fetch();
        if (!$movement) {
            redirect_with_message('Movimiento no encontrado.', '/index.php');
        }
        ?>
        <section class="panel">
            <h2>Editar movimiento</h2>
            <form method="post" action="/index.php?action=save_movement" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$movement['id'] ?>">
                <label>
                    Fecha
                    <input type="date" name="fecha" value="<?= h($movement['fecha']) ?>" required>
                </label>
                <label>
                    Importe
                    <input type="number" step="0.01" name="importe" value="<?= h((string)$movement['importe']) ?>" required>
                </label>
                <label class="full">
                    Descripción
                    <input type="text" name="descripcion" value="<?= h($movement['descripcion']) ?>" required>
                </label>
                <label>
                    Categoría
                    <select name="categoria">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= h($cat['nombre']) ?>" <?= $cat['nombre'] === $movement['categoria'] ? 'selected' : '' ?>>
                                <?= h($cat['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Estado
                    <select name="estado">
                        <option value="pendiente" <?= $movement['estado'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                        <option value="revisado" <?= $movement['estado'] === 'revisado' ? 'selected' : '' ?>>Revisado</option>
                    </select>
                </label>
                <label class="full">
                    Notas
                    <textarea name="notas" rows="3"><?= h($movement['notas']) ?></textarea>
                </label>
                <label class="full">
                    Cuenta
                    <input type="text" name="cuenta" value="<?= h($movement['cuenta']) ?>">
                </label>
                <div class="actions full">
                    <a href="/index.php" class="ghost">Cancelar</a>
                    <button type="submit" class="primary">Guardar</button>
                </div>
            </form>
        </section>
    <?php else: ?>
        <section class="panel">
            <h2>Añadir movimiento</h2>
            <form method="post" action="/index.php?action=add_movement" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <label>
                    Fecha
                    <input type="date" name="fecha" value="<?= h(date('Y-m-d')) ?>" required>
                </label>
                <label>
                    Importe
                    <input type="number" step="0.01" name="importe" placeholder="-24.90" required>
                </label>
                <label class="full">
                    Descripción
                    <input type="text" name="descripcion" placeholder="Ej: Supermercado" required>
                </label>
                <div class="full">
                    <span class="label">Favoritas</span>
                    <div class="pill-group">
                        <?php foreach ($fav_categories as $cat): ?>
                            <button class="pill" type="button" data-category="<?= h($cat['nombre']) ?>">
                                <?= h($cat['nombre']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <label>
                    Categoría
                    <select name="categoria" id="categorySelect" required>
                        <option value="">Selecciona...</option>
                        <?php foreach ($categories as $cat): ?>
                            <?php if (!$cat['activa']) continue; ?>
                            <option value="<?= h($cat['nombre']) ?>"><?= h($cat['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Cuenta
                    <input type="text" name="cuenta" placeholder="Cuenta opcional">
                </label>
                <label class="full">
                    Notas
                    <textarea name="notas" rows="2" placeholder="Notas opcionales"></textarea>
                </label>
                <div class="actions full">
                    <button type="submit" class="primary">Guardar movimiento</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-header">
                <h2>Vista mensual</h2>
                <form class="inline-form" method="get" action="/index.php">
                    <input type="month" name="month" value="<?= h($month) ?>">
                    <select name="status">
                        <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>Todos</option>
                        <option value="pendiente" <?= $filters['status'] === 'pendiente' ? 'selected' : '' ?>>Pendientes</option>
                        <option value="revisado" <?= $filters['status'] === 'revisado' ? 'selected' : '' ?>>Revisados</option>
                    </select>
                    <select name="category">
                        <option value="all">Todas categorías</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= h($cat['nombre']) ?>" <?= $filters['category'] === $cat['nombre'] ? 'selected' : '' ?>>
                                <?= h($cat['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="search" name="search" value="<?= h($filters['search']) ?>" placeholder="Buscar">
                    <button type="submit" class="ghost">Filtrar</button>
                </form>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Descripción</th>
                            <th>Importe</th>
                            <th>Categoría</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $move): ?>
                            <tr>
                                <td><?= h($move['fecha']) ?></td>
                                <td>
                                    <strong><?= h($move['descripcion']) ?></strong>
                                    <?php if (!empty($move['notas'])): ?>
                                        <div class="muted"><?= h($move['notas']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="amount <?= $move['importe'] >= 0 ? 'positive' : 'negative' ?>">€ <?= format_amount((float)$move['importe']) ?></td>
                                <td>
                                    <form method="post" action="/index.php?action=quick_category" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$move['id'] ?>">
                                        <select name="categoria" onchange="this.form.submit()">
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?= h($cat['nombre']) ?>" <?= $cat['nombre'] === $move['categoria'] ? 'selected' : '' ?>>
                                                    <?= h($cat['nombre']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <form method="post" action="/index.php?action=update_status" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$move['id'] ?>">
                                        <input type="hidden" name="estado" value="<?= $move['estado'] === 'pendiente' ? 'revisado' : 'pendiente' ?>">
                                        <button class="ghost" type="submit"><?= h($move['estado']) ?></button>
                                    </form>
                                </td>
                                <td>
                                    <a class="ghost" href="/index.php?page=edit_movement&id=<?= (int)$move['id'] ?>">Editar</a>
                                    <form method="post" action="/index.php?action=delete_movement" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$move['id'] ?>">
                                        <button class="danger" type="submit">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>
<script src="/assets/app.js"></script>
</body>
</html>
