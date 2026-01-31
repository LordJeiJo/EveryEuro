<?php
require __DIR__ . '/src/bootstrap.php';

init_db($config);

$page = $_GET['page'] ?? 'movements';
$action = $_GET['action'] ?? null;

$pdo = db($config);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function redirect_with_message(string $message, string $location = 'index.php'): void {
    $_SESSION['flash'] = $message;
    header('Location: ' . app_url($location));
    exit;
}

function safe_redirect_target(string $target, string $fallback = 'index.php'): string {
    $fallbackUrl = app_url($fallback);
    $target = trim($target);
    if ($target === '') {
        return $fallbackUrl;
    }
    $parts = parse_url($target);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return $fallbackUrl;
    }
    $path = $parts['path'] ?? '';
    if ($path === '') {
        return $fallbackUrl;
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    return $path . $query . $fragment;
}

function save_budget(PDO $pdo, string $month, int $categoryId, ?string $plannedRaw, ?string $notesRaw): void {
    $plannedRaw = $plannedRaw ?? '';
    $notes = trim($notesRaw ?? '');
    $plannedValue = trim($plannedRaw);
    $plannedAmount = $plannedValue === '' ? null : (float)str_replace(',', '.', $plannedValue);

    if ($plannedAmount === null && $notes === '') {
        $stmt = $pdo->prepare('DELETE FROM budgets WHERE month = ? AND category_id = ?');
        $stmt->execute([$month, $categoryId]);
        return;
    }

    $plannedAmount = max(0, (float)($plannedAmount ?? 0));
    $stmt = $pdo->prepare('INSERT INTO budgets (month, category_id, planned_amount, notes) VALUES (?, ?, ?, ?)
        ON CONFLICT(month, category_id) DO UPDATE SET planned_amount = excluded.planned_amount, notes = excluded.notes');
    $stmt->execute([$month, $categoryId, $plannedAmount, $notes]);
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $user = trim($_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? '';
    if ($user === $config['admin_user'] && password_verify($pass, $config['admin_pass_hash'])) {
        $_SESSION['user'] = $user;
        redirect_with_message('Bienvenido de nuevo.');
    }
    redirect_with_message('Ups, las credenciales no coinciden.', 'index.php?page=login');
}

if ($action === 'logout') {
    session_destroy();
    header('Location: ' . app_url('index.php?page=login'));
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

    if ($descripcion === '' || $categoria === '' || $cuenta === '') {
        redirect_with_message('Añade descripción, categoría y cuenta para continuar.', 'index.php');
    }

    $stmt = $pdo->prepare('INSERT INTO movements (fecha, descripcion, importe, categoria, notas, estado, mes, cuenta) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$fecha, $descripcion, $importe, $categoria, $notas, $estado, $mes, $cuenta]);
    $_SESSION['last_account'] = $cuenta;
    redirect_with_message('Apuntado. ¡Listo!', 'index.php?month=' . urlencode($mes));
}

if ($action === 'delete_movement' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM movements WHERE id = ?');
    $stmt->execute([$id]);
    redirect_with_message('Movimiento eliminado.', 'index.php');
}

if ($action === 'bulk_review' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $monthValue = $_POST['month'] ?? date('Y-m');
    $filters = [
        'status' => $_POST['status'] ?? 'all',
        'category' => $_POST['category'] ?? 'all',
        'account' => $_POST['account'] ?? 'all',
        'search' => trim($_POST['search'] ?? ''),
    ];
    $where = ['mes = ?', 'estado = ?'];
    $params = [$monthValue, 'pendiente'];
    if ($filters['category'] !== 'all' && $filters['category'] !== '') {
        $where[] = 'categoria = ?';
        $params[] = $filters['category'];
    }
    if ($filters['account'] !== 'all' && $filters['account'] !== '') {
        $where[] = 'cuenta = ?';
        $params[] = $filters['account'];
    }
    if ($filters['search'] !== '') {
        $where[] = '(descripcion LIKE ? OR notas LIKE ? OR cuenta LIKE ?)';
        $search = '%' . $filters['search'] . '%';
        $params = array_merge($params, [$search, $search, $search]);
    }
    $stmt = $pdo->prepare('UPDATE movements SET estado = ? WHERE ' . implode(' AND ', $where));
    $stmt->execute(array_merge(['revisado'], $params));
    $_SESSION['flash'] = 'Movimientos marcados como revisados. ✔️';
    $redirectTarget = safe_redirect_target($_POST['redirect'] ?? '', 'index.php?month=' . urlencode($monthValue));
    header('Location: ' . $redirectTarget);
    exit;
}

if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $estado = $_POST['estado'] === 'revisado' ? 'revisado' : 'pendiente';
    $stmt = $pdo->prepare('UPDATE movements SET estado = ? WHERE id = ?');
    $stmt->execute([$estado, $id]);
    $_SESSION['flash'] = 'Estado actualizado. ✔️';
    $redirectTarget = safe_redirect_target($_POST['redirect'] ?? '', 'index.php');
    header('Location: ' . $redirectTarget);
    exit;
}

if ($action === 'quick_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $categoria = trim($_POST['categoria'] ?? '');
    if ($categoria !== '') {
        $stmt = $pdo->prepare('UPDATE movements SET categoria = ? WHERE id = ?');
        $stmt->execute([$categoria, $id]);
    }
    redirect_with_message('Categoría al día.', 'index.php');
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
    $_SESSION['last_account'] = $cuenta;
    redirect_with_message('Movimiento actualizado.', 'index.php?month=' . urlencode($mes));
}

if ($action === 'save_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $tipo = $_POST['tipo'] ?? 'gasto';
    $orden = (int)($_POST['orden'] ?? 0);
    $activa = isset($_POST['activa']) ? 1 : 0;
    $keywordsRaw = trim((string)($_POST['keywords'] ?? ''));
    $keywords = trim(preg_replace('/\s*,\s*/', ', ', str_replace([';', "\n", "\r"], ',', $keywordsRaw)), " \t\n\r\0\x0B,");

    if ($nombre === '') {
        redirect_with_message('Nombre requerido.', 'index.php?page=categories');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE categories SET nombre = ?, tipo = ?, orden = ?, activa = ?, keywords = ? WHERE id = ?');
        $stmt->execute([$nombre, $tipo, $orden, $activa, $keywords, $id]);
        redirect_with_message('Categoría actualizada. ✔️', 'index.php?page=categories');
    }

    $stmt = $pdo->prepare('INSERT INTO categories (nombre, tipo, orden, activa, keywords) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$nombre, $tipo, $orden, $activa, $keywords]);
    redirect_with_message('Categoría creada. ✨', 'index.php?page=categories');
}

if ($action === 'save_account' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $orden = (int)($_POST['orden'] ?? 0);
    $activa = isset($_POST['activa']) ? 1 : 0;

    if ($nombre === '') {
        redirect_with_message('Necesito un nombre para la cuenta.', 'index.php?page=accounts');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE accounts SET nombre = ?, orden = ?, activa = ? WHERE id = ?');
        $stmt->execute([$nombre, $orden, $activa, $id]);
        redirect_with_message('Cuenta actualizada. ✔️', 'index.php?page=accounts');
    }

    $stmt = $pdo->prepare('INSERT INTO accounts (nombre, orden, activa) VALUES (?, ?, ?)');
    $stmt->execute([$nombre, $orden, $activa]);
    redirect_with_message('Cuenta creada. ✨', 'index.php?page=accounts');
}

if ($action === 'save_budgets' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $monthValue = $_POST['month'] ?? date('Y-m');
    $planned = $_POST['planned_amount'] ?? [];
    $notes = $_POST['notes'] ?? [];
    $singleId = (int)($_POST['save_single'] ?? 0);

    if ($singleId > 0) {
        save_budget($pdo, $monthValue, $singleId, $planned[$singleId] ?? null, $notes[$singleId] ?? null);
        redirect_with_message('Presupuesto guardado.', 'index.php?page=budget&month=' . urlencode($monthValue));
    }

    foreach ($planned as $categoryId => $amount) {
        save_budget($pdo, $monthValue, (int)$categoryId, (string)$amount, $notes[$categoryId] ?? null);
    }
    foreach ($notes as $categoryId => $note) {
        if (isset($planned[$categoryId])) {
            continue;
        }
        save_budget($pdo, $monthValue, (int)$categoryId, null, (string)$note);
    }

    redirect_with_message('Presupuestos listos.', 'index.php?page=budget&month=' . urlencode($monthValue));
}

if ($action === 'copy_budgets' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $monthValue = $_POST['month'] ?? date('Y-m');
    $confirmOverwrite = ($_POST['confirm_overwrite'] ?? '') === '1';

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM budgets WHERE month = ?');
    $stmt->execute([$monthValue]);
    $existingCount = (int)$stmt->fetchColumn();

    if ($existingCount > 0 && !$confirmOverwrite) {
        redirect_with_message('Confirma la sobrescritura para copiar el mes anterior.', 'index.php?page=budget&month=' . urlencode($monthValue));
    }

    $date = DateTime::createFromFormat('Y-m', $monthValue) ?: new DateTime();
    $prevMonth = $date->modify('-1 month')->format('Y-m');

    $stmt = $pdo->prepare('SELECT category_id, planned_amount, notes FROM budgets WHERE month = ?');
    $stmt->execute([$prevMonth]);
    $previous = $stmt->fetchAll();
    if (!$previous) {
        redirect_with_message('No hay presupuestos en el mes anterior.', 'index.php?page=budget&month=' . urlencode($monthValue));
    }

    $pdo->prepare('DELETE FROM budgets WHERE month = ?')->execute([$monthValue]);
    $insert = $pdo->prepare('INSERT INTO budgets (month, category_id, planned_amount, notes) VALUES (?, ?, ?, ?)');
    foreach ($previous as $row) {
        $insert->execute([$monthValue, $row['category_id'], $row['planned_amount'], $row['notes']]);
    }

    redirect_with_message('Presupuestos copiados. ✔️', 'index.php?page=budget&month=' . urlencode($monthValue));
}

if ($action === 'add_extraordinary' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $monthValue = $_POST['month'] ?? date('Y-m');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $importe = (float)str_replace(',', '.', $_POST['importe'] ?? '0');
    $categoriaId = (int)($_POST['categoria_id'] ?? 0);
    $notas = trim($_POST['notas'] ?? '');

    if ($descripcion === '' || $importe <= 0) {
        redirect_with_message('Añade descripción e importe para continuar.', 'index.php?page=extraordinary&year=' . substr($monthValue, 0, 4));
    }

    $stmt = $pdo->prepare('INSERT INTO extraordinary_expenses (month, descripcion, importe, categoria_id, notas) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$monthValue, $descripcion, $importe, $categoriaId > 0 ? $categoriaId : null, $notas]);
    redirect_with_message('Gasto extraordinario guardado.', 'index.php?page=extraordinary&year=' . substr($monthValue, 0, 4));
}

if ($action === 'delete_extraordinary' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $yearValue = $_POST['year'] ?? date('Y');
    $stmt = $pdo->prepare('DELETE FROM extraordinary_expenses WHERE id = ?');
    $stmt->execute([$id]);
    redirect_with_message('Gasto extraordinario eliminado.', 'index.php?page=extraordinary&year=' . urlencode((string)$yearValue));
}

if ($action === 'delete_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    redirect_with_message('Categoría eliminada.', 'index.php?page=categories');
}

if ($action === 'delete_account' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM accounts WHERE id = ?');
    $stmt->execute([$id]);
    redirect_with_message('Cuenta eliminada.', 'index.php?page=accounts');
}

if ($action === 'export_backup') {
    $data = [
        'schema_version' => $config['schema_version'],
        'exported_at' => date('c'),
        'movements' => $pdo->query('SELECT * FROM movements')->fetchAll(),
        'categories' => $pdo->query('SELECT * FROM categories')->fetchAll(),
        'accounts' => $pdo->query('SELECT * FROM accounts')->fetchAll(),
        'rules' => $pdo->query('SELECT * FROM rules')->fetchAll(),
        'budgets' => $pdo->query('SELECT * FROM budgets')->fetchAll(),
        'extraordinary_expenses' => $pdo->query('SELECT * FROM extraordinary_expenses')->fetchAll(),
    ];
    $filename = 'everyeuro-backup-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'import_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!isset($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
        redirect_with_message('Archivo inválido.', 'index.php?page=backup');
    }
    $json = file_get_contents($_FILES['backup']['tmp_name']);
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        redirect_with_message('JSON inválido.', 'index.php?page=backup');
    }
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM movements');
        $pdo->exec('DELETE FROM categories');
        $pdo->exec('DELETE FROM accounts');
        $pdo->exec('DELETE FROM rules');
        $pdo->exec('DELETE FROM budgets');
        $pdo->exec('DELETE FROM extraordinary_expenses');
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
        $insertCat = $pdo->prepare('INSERT INTO categories (id, nombre, tipo, orden, activa, is_favorite, keywords) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($payload['categories'] ?? [] as $row) {
            $insertCat->execute([
                $row['id'],
                $row['nombre'],
                $row['tipo'],
                $row['orden'],
                $row['activa'],
                $row['is_favorite'] ?? 0,
                $row['keywords'] ?? '',
            ]);
        }
        $insertAccount = $pdo->prepare('INSERT INTO accounts (id, nombre, orden, activa) VALUES (?, ?, ?, ?)');
        foreach ($payload['accounts'] ?? [] as $row) {
            $insertAccount->execute([
                $row['id'],
                $row['nombre'],
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
        $insertBudget = $pdo->prepare('INSERT INTO budgets (id, month, category_id, planned_amount, notes) VALUES (?, ?, ?, ?, ?)');
        foreach ($payload['budgets'] ?? [] as $row) {
            $insertBudget->execute([
                $row['id'],
                $row['month'],
                $row['category_id'],
                $row['planned_amount'],
                $row['notes'] ?? '',
            ]);
        }
        $insertExtra = $pdo->prepare('INSERT INTO extraordinary_expenses (id, month, descripcion, importe, categoria_id, notas) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($payload['extraordinary_expenses'] ?? [] as $row) {
            $insertExtra->execute([
                $row['id'],
                $row['month'],
                $row['descripcion'],
                $row['importe'],
                $row['categoria_id'] ?? null,
                $row['notas'] ?? '',
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        redirect_with_message('Error al importar.', 'index.php?page=backup');
    }
    redirect_with_message('Copia importada.', 'index.php?page=backup');
}

$categories = $pdo->query('SELECT * FROM categories ORDER BY orden, nombre')->fetchAll();
$accounts = $pdo->query('SELECT * FROM accounts ORDER BY orden, nombre')->fetchAll();
$activeAccounts = array_values(array_filter($accounts, static function (array $account): bool {
    return (int)$account['activa'] === 1;
}));
$categoriesById = [];
foreach ($categories as $cat) {
    $categoriesById[(int)$cat['id']] = $cat;
}
$lastAccount = $_SESSION['last_account'] ?? '';
$quickEntryAccounts = $activeAccounts;
if (empty($quickEntryAccounts)) {
    $quickEntryAccounts = $accounts;
}
$defaultAccount = $lastAccount;
$quickEntryAccountNames = array_map(static fn(array $account): string => $account['nombre'], $quickEntryAccounts);
if ($defaultAccount === '' || !in_array($defaultAccount, $quickEntryAccountNames, true)) {
    $defaultAccount = $quickEntryAccounts[0]['nombre'] ?? '';
}
$currentUri = $_SERVER['REQUEST_URI'] ?? app_url('index.php');

$month = $_GET['month'] ?? date('Y-m');
$yearInput = $_GET['year'] ?? substr($month, 0, 4);
$year = preg_match('/^\d{4}$/', (string)$yearInput) ? (string)$yearInput : date('Y');
$yearLike = $year . '-%';
$filters = [
    'status' => $_GET['status'] ?? 'all',
    'category' => $_GET['category'] ?? 'all',
    'account' => $_GET['account'] ?? 'all',
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
if ($filters['account'] !== 'all' && $filters['account'] !== '') {
    $where[] = 'cuenta = ?';
    $params[] = $filters['account'];
}
if ($filters['search'] !== '') {
    $where[] = '(descripcion LIKE ? OR notas LIKE ? OR cuenta LIKE ?)';
    $search = '%' . $filters['search'] . '%';
    $params = array_merge($params, [$search, $search, $search]);
}

$stmt = $pdo->prepare('SELECT * FROM movements WHERE ' . implode(' AND ', $where) . ' ORDER BY fecha DESC, id DESC');
$stmt->execute($params);
$movements = $stmt->fetchAll();
$pendingCount = 0;
$filteredTotal = 0.0;
foreach ($movements as $move) {
    if ($move['estado'] === 'pendiente') {
        $pendingCount++;
    }
    $filteredTotal += (float)$move['importe'];
}

$totals = $pdo->prepare('SELECT SUM(CASE WHEN importe > 0 THEN importe ELSE 0 END) as ingresos, SUM(CASE WHEN importe < 0 THEN importe ELSE 0 END) as gastos FROM movements WHERE mes = ?');
$totals->execute([$month]);
$totalsRow = $totals->fetch();
$ingresos = (float)($totalsRow['ingresos'] ?? 0);
$gastos = (float)($totalsRow['gastos'] ?? 0);
$balance = $ingresos + $gastos;
$realGastos = abs($gastos);

$yearTotals = $pdo->prepare('SELECT SUM(CASE WHEN importe > 0 THEN importe ELSE 0 END) as ingresos, SUM(CASE WHEN importe < 0 THEN importe ELSE 0 END) as gastos FROM movements WHERE mes LIKE ?');
$yearTotals->execute([$yearLike]);
$yearTotalsRow = $yearTotals->fetch();
$yearIngresos = (float)($yearTotalsRow['ingresos'] ?? 0);
$yearGastos = (float)($yearTotalsRow['gastos'] ?? 0);
$yearBalance = $yearIngresos + $yearGastos;
$yearRealGastos = abs($yearGastos);

$budgetStmt = $pdo->prepare('SELECT * FROM budgets WHERE month = ?');
$budgetStmt->execute([$month]);
$budgetRows = $budgetStmt->fetchAll();
$budgetsByCategory = [];
foreach ($budgetRows as $row) {
    $budgetsByCategory[(int)$row['category_id']] = $row;
}

$budgetTotals = ['ingresos' => 0.0, 'gastos' => 0.0];
foreach ($budgetsByCategory as $categoryId => $budget) {
    $type = $categoriesById[$categoryId]['tipo'] ?? 'gasto';
    if ($type === 'ingreso') {
        $budgetTotals['ingresos'] += (float)$budget['planned_amount'];
    } else {
        $budgetTotals['gastos'] += (float)$budget['planned_amount'];
    }
}

$budgetBalance = $budgetTotals['ingresos'] - $budgetTotals['gastos'];

$yearBudgetsStmt = $pdo->prepare('SELECT category_id, SUM(planned_amount) as planned_amount FROM budgets WHERE month LIKE ? GROUP BY category_id');
$yearBudgetsStmt->execute([$yearLike]);
$yearBudgetRows = $yearBudgetsStmt->fetchAll();
$yearBudgetsByCategory = [];
foreach ($yearBudgetRows as $row) {
    $yearBudgetsByCategory[(int)$row['category_id']] = $row;
}

$yearBudgetTotals = ['ingresos' => 0.0, 'gastos' => 0.0];
foreach ($yearBudgetsByCategory as $categoryId => $budget) {
    $type = $categoriesById[$categoryId]['tipo'] ?? 'gasto';
    if ($type === 'ingreso') {
        $yearBudgetTotals['ingresos'] += (float)$budget['planned_amount'];
    } else {
        $yearBudgetTotals['gastos'] += (float)$budget['planned_amount'];
    }
}

$yearBudgetBalance = $yearBudgetTotals['ingresos'] - $yearBudgetTotals['gastos'];

$extraordinaryStmt = $pdo->prepare('SELECT e.*, c.nombre as categoria_nombre FROM extraordinary_expenses e LEFT JOIN categories c ON e.categoria_id = c.id WHERE e.month LIKE ? ORDER BY e.month DESC, e.id DESC');
$extraordinaryStmt->execute([$yearLike]);
$extraordinaryRows = $extraordinaryStmt->fetchAll();
$extraordinaryByMonth = [];
$extraordinaryTotals = [];
foreach ($extraordinaryRows as $row) {
    $monthKey = $row['month'];
    $extraordinaryByMonth[$monthKey] ??= [];
    $extraordinaryByMonth[$monthKey][] = $row;
    $extraordinaryTotals[$monthKey] = ($extraordinaryTotals[$monthKey] ?? 0) + (float)$row['importe'];
}
$extraordinaryYearTotal = array_sum($extraordinaryTotals);
$extraordinaryMonthlyContribution = $extraordinaryYearTotal > 0 ? ($extraordinaryYearTotal / 12) : 0.0;

$monthNames = [
    '01' => 'Enero',
    '02' => 'Febrero',
    '03' => 'Marzo',
    '04' => 'Abril',
    '05' => 'Mayo',
    '06' => 'Junio',
    '07' => 'Julio',
    '08' => 'Agosto',
    '09' => 'Septiembre',
    '10' => 'Octubre',
    '11' => 'Noviembre',
    '12' => 'Diciembre',
];

function ratio_percent(float $actual, float $budget): int {
    if ($budget <= 0) {
        return 0;
    }
    return (int)round(($actual / $budget) * 100);
}

function ring_progress(int $ratio): int {
    return min(100, max(0, $ratio));
}

function ring_overflow(int $ratio): int {
    return max(0, $ratio - 100);
}

$summaryRatios = [
    'ingresos' => ratio_percent($ingresos, $budgetTotals['ingresos']),
    'gastos' => ratio_percent($realGastos, $budgetTotals['gastos']),
];
$balanceLabel = $balance > 0 ? 'Superávit' : ($balance < 0 ? 'Déficit' : 'Ajustado');
$balanceTone = $balance > 0 ? 'positive' : ($balance < 0 ? 'negative' : 'neutral');

$yearSummaryRatios = [
    'ingresos' => ratio_percent($yearIngresos, $yearBudgetTotals['ingresos']),
    'gastos' => ratio_percent($yearRealGastos, $yearBudgetTotals['gastos']),
];
$yearBalanceLabel = $yearBalance > 0 ? 'Superávit' : ($yearBalance < 0 ? 'Déficit' : 'Ajustado');
$yearBalanceTone = $yearBalance > 0 ? 'positive' : ($yearBalance < 0 ? 'negative' : 'neutral');

$summaryCategoriesStmt = $pdo->prepare('SELECT c.id, c.nombre, c.tipo, c.orden,
    COALESCE(SUM(CASE WHEN m.importe > 0 THEN m.importe ELSE 0 END), 0) AS ingresos,
    COALESCE(SUM(CASE WHEN m.importe < 0 THEN m.importe ELSE 0 END), 0) AS gastos
    FROM categories c
    LEFT JOIN movements m ON m.categoria = c.nombre AND m.mes = ?
    GROUP BY c.id
    ORDER BY c.orden, c.nombre');
$summaryCategoriesStmt->execute([$month]);
$summaryCategories = $summaryCategoriesStmt->fetchAll();

$yearSummaryCategoriesStmt = $pdo->prepare('SELECT c.id, c.nombre, c.tipo, c.orden,
    COALESCE(SUM(CASE WHEN m.importe > 0 THEN m.importe ELSE 0 END), 0) AS ingresos,
    COALESCE(SUM(CASE WHEN m.importe < 0 THEN m.importe ELSE 0 END), 0) AS gastos
    FROM categories c
    LEFT JOIN movements m ON m.categoria = c.nombre AND m.mes LIKE ?
    GROUP BY c.id
    ORDER BY c.orden, c.nombre');
$yearSummaryCategoriesStmt->execute([$yearLike]);
$yearSummaryCategories = $yearSummaryCategoriesStmt->fetchAll();

$actualByCategory = [];
foreach ($summaryCategories as $row) {
    $actualByCategory[(int)$row['id']] = $row['tipo'] === 'ingreso'
        ? (float)$row['ingresos']
        : abs((float)$row['gastos']);
}

$activeCategories = array_values(array_filter($categories, static function (array $cat): bool {
    return (int)$cat['activa'] === 1;
}));
$hasBudgetsForMonth = !empty($budgetsByCategory);

function current_url(array $override = []): string {
    $params = array_merge($_GET, $override);
    return app_url('index.php?' . http_build_query($params));
}

?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EveryEuro</title>
    <link rel="stylesheet" href="<?= h(app_url('assets/styles.css')) ?>">
</head>
<body>
<div class="app">
    <header class="topbar">
        <div class="topbar-left">
            <?php if (is_logged_in()): ?>
                <button class="menu-toggle" type="button" id="menuToggle" aria-expanded="false" aria-controls="appNav">
                    <span class="sr-only">Abrir menú</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h16v2H4v-2Z" fill="currentColor"/>
                    </svg>
                </button>
            <?php endif; ?>
            <div class="brand">
                <span class="dot"></span>
                <div>
                    <h1>EveryEuro</h1>
                    <p>Tu panel rápido para saber cómo va el mes.</p>
                </div>
            </div>
        </div>
        <?php if (is_logged_in()): ?>
        <nav class="nav" id="appNav">
            <a href="<?= h(app_url('index.php')) ?>" class="<?= $page === 'movements' ? 'active' : '' ?>">Movimientos</a>
            <a href="<?= h(app_url('index.php?page=accounts')) ?>" class="<?= $page === 'accounts' ? 'active' : '' ?>">Cuentas</a>
            <a href="<?= h(app_url('index.php?page=categories')) ?>" class="<?= $page === 'categories' ? 'active' : '' ?>">Categorías</a>
            <a href="<?= h(app_url('index.php?page=extraordinary')) ?>" class="<?= $page === 'extraordinary' ? 'active' : '' ?>">Extraordinarios</a>
            <a href="<?= h(app_url('index.php?page=budget')) ?>" class="<?= $page === 'budget' ? 'active' : '' ?>">Presupuesto</a>
            <a href="<?= h(app_url('index.php?page=summary')) ?>" class="<?= $page === 'summary' ? 'active' : '' ?>">Análisis</a>
            <a href="<?= h(app_url('index.php?page=backup')) ?>" class="<?= $page === 'backup' ? 'active' : '' ?>">Backup</a>
            <a href="<?= h(app_url('index.php?action=logout')) ?>">Salir</a>
        </nav>
        <?php endif; ?>
    </header>

    <?php if ($flash): ?>
        <div class="flash"><?= h($flash) ?></div>
    <?php endif; ?>

    <?php if ($page === 'login'): ?>
        <section class="panel login">
            <h2>Acceso</h2>
            <form method="post" action="<?= h(app_url('index.php?action=login')) ?>">
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
            <h2>Tus categorías</h2>
            <p class="muted">Define palabras clave para sugerir categorías al capturar movimientos.</p>
            <form method="post" action="<?= h(app_url('index.php?action=save_category')) ?>" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="id" value="0">
                <input type="text" name="nombre" placeholder="Ej: Vivienda" required>
                <select name="tipo">
                    <option value="ingreso">Ingreso</option>
                    <option value="gasto" selected>Gasto</option>
                    <option value="ahorro">Ahorro</option>
                    <option value="extra">Extra</option>
                </select>
                <input type="number" name="orden" value="0" min="0">
                <label class="switch icon-toggle">
                    <input type="checkbox" name="activa" checked>
                    <span class="toggle-icon" aria-hidden="true">
                        <svg class="icon-on" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M12 5c5.523 0 9.5 4.5 10.5 7-1 2.5-4.977 7-10.5 7S2.5 14.5 1.5 12C2.5 9.5 6.477 5 12 5Zm0 3.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Z" fill="currentColor"/>
                        </svg>
                        <svg class="icon-off" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M3 5.27 4.28 4l15.72 15.73-1.27 1.27-3.2-3.2A10.72 10.72 0 0 1 12 19c-5.523 0-9.5-4.5-10.5-7a12.9 12.9 0 0 1 3.54-4.88L3 5.27Zm5.06 5.06a3.5 3.5 0 0 0 4.95 4.95L8.06 10.33ZM12 7c.75 0 1.47.14 2.14.4L12.8 6.06A10.72 10.72 0 0 0 12 5c-1.9 0-3.64.53-5.1 1.3l1.43 1.43A7.2 7.2 0 0 1 12 7Zm9.5 5c-.5 1.25-2 3.4-4.2 4.86l-1.45-1.45C17.3 14.4 18.6 12.8 19.5 12c-.9-.8-2.2-2.4-3.7-3.4l1.5-1.5c2.1 1.4 3.6 3.6 4.2 4.9Z" fill="currentColor"/>
                        </svg>
                    </span>
                    <span class="sr-only">Visible</span>
                </label>
                <label>
                    Palabras clave
                    <input type="text" name="keywords" placeholder="Ej: súper, Mercadona">
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
                            <th>Visible</th>
                            <th>Palabras clave</th>
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
                                <td><?= $cat['keywords'] !== '' ? h($cat['keywords']) : '—' ?></td>
                                <td>
                                    <button class="ghost icon-button" type="button" data-edit='<?= h(json_encode($cat)) ?>' aria-label="Editar" title="Editar">
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                            <path d="M15.232 5.232a2.5 2.5 0 0 1 3.536 3.536L8.5 19.036l-4.5 1 1-4.5 10.232-10.304Zm2.12 1.414a.5.5 0 0 0-.707 0l-1.06 1.06 1.414 1.415 1.06-1.061a.5.5 0 0 0 0-.707l-.707-.707Zm-2.475 2.475L6.5 17.5l-0.5 2 2-0.5 8.379-8.379-1.414-1.415Z" fill="currentColor"/>
                                        </svg>
                                        <span class="sr-only">Editar</span>
                                    </button>
                                    <form method="post" action="<?= h(app_url('index.php?action=delete_category')) ?>" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                        <button class="danger icon-button" type="submit" aria-label="Eliminar" title="Eliminar">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                <path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v8h-2V9Zm4 0h2v8h-2V9ZM7 9h2v8H7V9Zm-1 12h12a2 2 0 0 0 2-2V9H4v10a2 2 0 0 0 2 2Z" fill="currentColor"/>
                                            </svg>
                                            <span class="sr-only">Eliminar</span>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <dialog id="categoryDialog">
                <form method="post" action="<?= h(app_url('index.php?action=save_category')) ?>" class="dialog-form">
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
                    <label class="switch icon-toggle">
                        <input type="checkbox" name="activa" id="catActiva">
                        <span class="toggle-icon" aria-hidden="true">
                            <svg class="icon-on" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M12 5c5.523 0 9.5 4.5 10.5 7-1 2.5-4.977 7-10.5 7S2.5 14.5 1.5 12C2.5 9.5 6.477 5 12 5Zm0 3.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Z" fill="currentColor"/>
                            </svg>
                            <svg class="icon-off" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M3 5.27 4.28 4l15.72 15.73-1.27 1.27-3.2-3.2A10.72 10.72 0 0 1 12 19c-5.523 0-9.5-4.5-10.5-7a12.9 12.9 0 0 1 3.54-4.88L3 5.27Zm5.06 5.06a3.5 3.5 0 0 0 4.95 4.95L8.06 10.33ZM12 7c.75 0 1.47.14 2.14.4L12.8 6.06A10.72 10.72 0 0 0 12 5c-1.9 0-3.64.53-5.1 1.3l1.43 1.43A7.2 7.2 0 0 1 12 7Zm9.5 5c-.5 1.25-2 3.4-4.2 4.86l-1.45-1.45C17.3 14.4 18.6 12.8 19.5 12c-.9-.8-2.2-2.4-3.7-3.4l1.5-1.5c2.1 1.4 3.6 3.6 4.2 4.9Z" fill="currentColor"/>
                            </svg>
                        </span>
                        <span class="sr-only">Visible</span>
                    </label>
                    <label>
                        Palabras clave
                        <input type="text" name="keywords" id="catKeywords" placeholder="Ej: súper, gasolina">
                    </label>
                    <menu>
                        <button type="button" class="ghost" id="closeCategory">Cancelar</button>
                        <button type="submit" class="primary">Guardar</button>
                    </menu>
                </form>
            </dialog>
        </section>
    <?php elseif ($page === 'accounts'): ?>
        <section class="panel">
            <h2>Tus cuentas</h2>
            <p class="muted">Agrupa movimientos por cuenta para filtrar al instante.</p>
            <form method="post" action="<?= h(app_url('index.php?action=save_account')) ?>" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="id" value="0">
                <input type="text" name="nombre" placeholder="Ej: Tarjeta" required>
                <input type="number" name="orden" value="0" min="0">
                <label class="switch icon-toggle">
                    <input type="checkbox" name="activa" checked>
                    <span class="toggle-icon" aria-hidden="true">
                        <svg class="icon-on" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M12 5c5.523 0 9.5 4.5 10.5 7-1 2.5-4.977 7-10.5 7S2.5 14.5 1.5 12C2.5 9.5 6.477 5 12 5Zm0 3.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Z" fill="currentColor"/>
                        </svg>
                        <svg class="icon-off" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M3 5.27 4.28 4l15.72 15.73-1.27 1.27-3.2-3.2A10.72 10.72 0 0 1 12 19c-5.523 0-9.5-4.5-10.5-7a12.9 12.9 0 0 1 3.54-4.88L3 5.27Zm5.06 5.06a3.5 3.5 0 0 0 4.95 4.95L8.06 10.33ZM12 7c.75 0 1.47.14 2.14.4L12.8 6.06A10.72 10.72 0 0 0 12 5c-1.9 0-3.64.53-5.1 1.3l1.43 1.43A7.2 7.2 0 0 1 12 7Zm9.5 5c-.5 1.25-2 3.4-4.2 4.86l-1.45-1.45C17.3 14.4 18.6 12.8 19.5 12c-.9-.8-2.2-2.4-3.7-3.4l1.5-1.5c2.1 1.4 3.6 3.6 4.2 4.9Z" fill="currentColor"/>
                        </svg>
                    </span>
                    <span class="sr-only">Visible</span>
                </label>
                <button type="submit" class="primary">Añadir</button>
            </form>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Orden</th>
                            <th>Visible</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <tr>
                                <td><?= h($account['nombre']) ?></td>
                                <td><?= h((string)$account['orden']) ?></td>
                                <td><?= $account['activa'] ? 'Sí' : 'No' ?></td>
                                <td>
                                    <button class="ghost icon-button" type="button" data-edit-account='<?= h(json_encode($account)) ?>' aria-label="Editar" title="Editar">
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                            <path d="M15.232 5.232a2.5 2.5 0 0 1 3.536 3.536L8.5 19.036l-4.5 1 1-4.5 10.232-10.304Zm2.12 1.414a.5.5 0 0 0-.707 0l-1.06 1.06 1.414 1.415 1.06-1.061a.5.5 0 0 0 0-.707l-.707-.707Zm-2.475 2.475L6.5 17.5l-0.5 2 2-0.5 8.379-8.379-1.414-1.415Z" fill="currentColor"/>
                                        </svg>
                                        <span class="sr-only">Editar</span>
                                    </button>
                                    <form method="post" action="<?= h(app_url('index.php?action=delete_account')) ?>" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
                                        <button class="danger icon-button" type="submit" aria-label="Eliminar" title="Eliminar">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                <path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v8h-2V9Zm4 0h2v8h-2V9ZM7 9h2v8H7V9Zm-1 12h12a2 2 0 0 0 2-2V9H4v10a2 2 0 0 0 2 2Z" fill="currentColor"/>
                                            </svg>
                                            <span class="sr-only">Eliminar</span>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <dialog id="accountDialog">
                <form method="post" action="<?= h(app_url('index.php?action=save_account')) ?>" class="dialog-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="id" id="accountId" value="0">
                    <label>
                        Nombre
                        <input type="text" name="nombre" id="accountNombre" required>
                    </label>
                    <label>
                        Orden
                        <input type="number" name="orden" id="accountOrden" min="0">
                    </label>
                    <label class="switch icon-toggle">
                        <input type="checkbox" name="activa" id="accountActiva">
                        <span class="toggle-icon" aria-hidden="true">
                            <svg class="icon-on" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M12 5c5.523 0 9.5 4.5 10.5 7-1 2.5-4.977 7-10.5 7S2.5 14.5 1.5 12C2.5 9.5 6.477 5 12 5Zm0 3.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Z" fill="currentColor"/>
                            </svg>
                            <svg class="icon-off" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M3 5.27 4.28 4l15.72 15.73-1.27 1.27-3.2-3.2A10.72 10.72 0 0 1 12 19c-5.523 0-9.5-4.5-10.5-7a12.9 12.9 0 0 1 3.54-4.88L3 5.27Zm5.06 5.06a3.5 3.5 0 0 0 4.95 4.95L8.06 10.33ZM12 7c.75 0 1.47.14 2.14.4L12.8 6.06A10.72 10.72 0 0 0 12 5c-1.9 0-3.64.53-5.1 1.3l1.43 1.43A7.2 7.2 0 0 1 12 7Zm9.5 5c-.5 1.25-2 3.4-4.2 4.86l-1.45-1.45C17.3 14.4 18.6 12.8 19.5 12c-.9-.8-2.2-2.4-3.7-3.4l1.5-1.5c2.1 1.4 3.6 3.6 4.2 4.9Z" fill="currentColor"/>
                            </svg>
                        </span>
                        <span class="sr-only">Visible</span>
                    </label>
                    <menu>
                        <button type="button" class="ghost" id="closeAccount">Cancelar</button>
                        <button type="submit" class="primary">Guardar</button>
                    </menu>
                </form>
            </dialog>
        </section>
    <?php elseif ($page === 'summary'): ?>
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Análisis del mes</h2>
                    <p class="muted">Comparativa clara entre lo presupuestado y lo real.</p>
                </div>
                <form class="inline-form" method="get" action="<?= h(app_url('index.php')) ?>">
                    <input type="hidden" name="page" value="summary">
                    <input type="month" name="month" value="<?= h($month) ?>">
                    <button type="submit" class="ghost">Cambiar</button>
                </form>
            </div>
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-card-header">
                        <div>
                            <h3>Ingresos</h3>
                            <p class="positive"><?= format_amount($ingresos) ?> €</p>
                        </div>
                        <?php $ingresosRatio = $summaryRatios['ingresos']; ?>
                        <div class="summary-ring <?= $ingresosRatio > 100 ? 'over' : '' ?>"
                             style="--progress: <?= ring_progress($ingresosRatio) ?>; --overflow: <?= ring_overflow($ingresosRatio) ?>; --ring-color: var(--success);">
                            <span><?= $summaryRatios['ingresos'] ?>%</span>
                        </div>
                    </div>
                    <p class="summary-meta">Sobre <?= format_amount($budgetTotals['ingresos']) ?> € presupuestados.</p>
                </div>
                <div class="summary-card">
                    <div class="summary-card-header">
                        <div>
                            <h3>Gastos</h3>
                            <p class="negative"><?= format_amount($realGastos) ?> €</p>
                        </div>
                        <?php $gastosRatio = $summaryRatios['gastos']; ?>
                        <div class="summary-ring <?= $gastosRatio > 100 ? 'over' : '' ?>"
                             style="--progress: <?= ring_progress($gastosRatio) ?>; --overflow: <?= ring_overflow($gastosRatio) ?>; --ring-color: var(--danger);">
                            <span><?= $summaryRatios['gastos'] ?>%</span>
                        </div>
                    </div>
                    <p class="summary-meta">Sobre <?= format_amount($budgetTotals['gastos']) ?> € presupuestados.</p>
                </div>
                <div class="summary-card">
                    <div class="summary-card-header">
                        <div>
                            <h3>Saldo del mes</h3>
                            <p class="summary-amount <?= $balanceTone ?>"><?= format_amount($balance) ?> €</p>
                        </div>
                        <span class="summary-status <?= $balanceTone ?>"><?= $balanceLabel ?></span>
                    </div>
                    <p class="summary-meta">Diferencia (debería de ser cero): <?= format_amount($budgetBalance) ?> €.</p>
                </div>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Categoría</th>
                            <th>Presupuestado</th>
                            <th>Real</th>
                            <th>Diferencia</th>
                            <th>Progreso</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summaryCategories as $row):
                            $budget = $budgetsByCategory[(int)$row['id']]['planned_amount'] ?? null;
                            $actual = $row['tipo'] === 'ingreso' ? (float)$row['ingresos'] : abs((float)$row['gastos']);
                            $budgetValue = $budget !== null ? (float)$budget : 0.0;
                            $hasPositiveBudget = $budgetValue > 0;
                            $diff = $hasPositiveBudget ? $budgetValue - $actual : null;
                            $progress = $hasPositiveBudget ? ($actual / $budgetValue) * 100 : null;
                            $isOverBudget = $hasPositiveBudget && $actual > $budgetValue;
                            $isOffBudget = !$hasPositiveBudget && $actual > 0;
                            $isPending = $hasPositiveBudget && $actual == 0.0;
                            $progressClamped = $progress !== null ? min(100, max(0, $progress)) : 0;
                            $overflowProgress = $progress !== null ? max(0, $progress - 100) : 0;
                            ?>
                            <tr>
                                <td><?= h($row['nombre']) ?></td>
                                <td><?= $budget !== null ? format_amount($budgetValue) . ' €' : '-' ?></td>
                                <td><?= format_amount($actual) ?> €</td>
                                <td class="<?= $diff === null ? '' : ($isPending ? 'muted' : ($diff >= 0 ? 'positive' : 'negative')) ?>">
                                    <?= $diff !== null ? format_amount($diff) . ' €' : '-' ?>
                                </td>
                                <td>
                                    <?php if ($progress === null): ?>
                                        <?php if ($isOffBudget): ?>
                                            <span class="status-badge warning">Fuera de presupuesto</span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="progress <?= $overflowProgress > 0 ? 'has-overflow' : '' ?>">
                                            <div class="progress-bars">
                                                <div class="progress-bar <?= $isOverBudget ? 'over' : '' ?> <?= $isPending ? 'pending' : '' ?>">
                                                    <span class="progress-fill" style="width: <?= $progressClamped ?>%"></span>
                                                </div>
                                                <?php if ($overflowProgress > 0): ?>
                                                    <div class="progress-bar overflow">
                                                        <span class="progress-fill" style="width: <?= min(100, $overflowProgress) ?>%"></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <small class="<?= $isPending ? 'muted' : '' ?>"><?= (int)round($progress) ?>%</small>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Análisis del año</h2>
                    <p class="muted">Sumatorio de todos los meses del año seleccionado.</p>
                </div>
                <form class="inline-form" method="get" action="<?= h(app_url('index.php')) ?>">
                    <input type="hidden" name="page" value="summary">
                    <input type="number" name="year" min="2000" max="2100" value="<?= h($year) ?>" aria-label="Año">
                    <button type="submit" class="ghost">Cambiar año</button>
                </form>
            </div>
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-card-header">
                        <div>
                            <h3>Ingresos</h3>
                            <p class="positive"><?= format_amount($yearIngresos) ?> €</p>
                        </div>
                        <?php $ingresosRatio = $yearSummaryRatios['ingresos']; ?>
                        <div class="summary-ring <?= $ingresosRatio > 100 ? 'over' : '' ?>"
                             style="--progress: <?= ring_progress($ingresosRatio) ?>; --overflow: <?= ring_overflow($ingresosRatio) ?>; --ring-color: var(--success);">
                            <span><?= $yearSummaryRatios['ingresos'] ?>%</span>
                        </div>
                    </div>
                    <p class="summary-meta">Sobre <?= format_amount($yearBudgetTotals['ingresos']) ?> € presupuestados.</p>
                </div>
                <div class="summary-card">
                    <div class="summary-card-header">
                        <div>
                            <h3>Gastos</h3>
                            <p class="negative"><?= format_amount($yearRealGastos) ?> €</p>
                        </div>
                        <?php $gastosRatio = $yearSummaryRatios['gastos']; ?>
                        <div class="summary-ring <?= $gastosRatio > 100 ? 'over' : '' ?>"
                             style="--progress: <?= ring_progress($gastosRatio) ?>; --overflow: <?= ring_overflow($gastosRatio) ?>; --ring-color: var(--danger);">
                            <span><?= $yearSummaryRatios['gastos'] ?>%</span>
                        </div>
                    </div>
                    <p class="summary-meta">Sobre <?= format_amount($yearBudgetTotals['gastos']) ?> € presupuestados.</p>
                </div>
                <div class="summary-card">
                    <div class="summary-card-header">
                        <div>
                            <h3>Saldo del año</h3>
                            <p class="summary-amount <?= $yearBalanceTone ?>"><?= format_amount($yearBalance) ?> €</p>
                        </div>
                        <span class="summary-status <?= $yearBalanceTone ?>"><?= $yearBalanceLabel ?></span>
                    </div>
                    <p class="summary-meta">Diferencia (debería de ser cero): <?= format_amount($yearBudgetBalance) ?> €.</p>
                </div>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Categoría</th>
                            <th>Presupuestado</th>
                            <th>Real</th>
                            <th>Diferencia</th>
                            <th>Progreso</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($yearSummaryCategories as $row):
                            $budget = $yearBudgetsByCategory[(int)$row['id']]['planned_amount'] ?? null;
                            $actual = $row['tipo'] === 'ingreso' ? (float)$row['ingresos'] : abs((float)$row['gastos']);
                            $budgetValue = $budget !== null ? (float)$budget : 0.0;
                            $hasPositiveBudget = $budgetValue > 0;
                            $diff = $hasPositiveBudget ? $budgetValue - $actual : null;
                            $progress = $hasPositiveBudget ? ($actual / $budgetValue) * 100 : null;
                            $isOverBudget = $hasPositiveBudget && $actual > $budgetValue;
                            $isOffBudget = !$hasPositiveBudget && $actual > 0;
                            $isPending = $hasPositiveBudget && $actual == 0.0;
                            $progressClamped = $progress !== null ? min(100, max(0, $progress)) : 0;
                            $overflowProgress = $progress !== null ? max(0, $progress - 100) : 0;
                            ?>
                            <tr>
                                <td><?= h($row['nombre']) ?></td>
                                <td><?= $budget !== null ? format_amount($budgetValue) . ' €' : '-' ?></td>
                                <td><?= format_amount($actual) ?> €</td>
                                <td class="<?= $diff === null ? '' : ($isPending ? 'muted' : ($diff >= 0 ? 'positive' : 'negative')) ?>">
                                    <?= $diff !== null ? format_amount($diff) . ' €' : '-' ?>
                                </td>
                                <td>
                                    <?php if ($progress === null): ?>
                                        <?php if ($isOffBudget): ?>
                                            <span class="status-badge warning">Fuera de presupuesto</span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="progress <?= $overflowProgress > 0 ? 'has-overflow' : '' ?>">
                                            <div class="progress-bars">
                                                <div class="progress-bar <?= $isOverBudget ? 'over' : '' ?> <?= $isPending ? 'pending' : '' ?>">
                                                    <span class="progress-fill" style="width: <?= $progressClamped ?>%"></span>
                                                </div>
                                                <?php if ($overflowProgress > 0): ?>
                                                    <div class="progress-bar overflow">
                                                        <span class="progress-fill" style="width: <?= min(100, $overflowProgress) ?>%"></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <small class="<?= $isPending ? 'muted' : '' ?>"><?= (int)round($progress) ?>%</small>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif ($page === 'extraordinary'): ?>
        <section class="panel">
            <div class="panel-header">
                <div class="extra-summary">
                    <h2>Extraordinarios</h2>
                    <p class="muted">Apunta gastos no recurrentes para repartirlos después en el presupuesto mensual.</p>
                    <div class="extra-totals">
                        <div>
                            <span>Total anual</span>
                            <strong><?= format_amount($extraordinaryYearTotal) ?> €</strong>
                        </div>
                        <div>
                            <span>Aportación mensual</span>
                            <strong><?= format_amount($extraordinaryMonthlyContribution) ?> €</strong>
                        </div>
                    </div>
                </div>
                <div class="panel-actions">
                    <form class="inline-form" method="get" action="<?= h(app_url('index.php')) ?>">
                        <input type="hidden" name="page" value="extraordinary">
                        <input type="number" name="year" value="<?= h($year) ?>" min="2000" max="2100">
                        <button type="submit" class="ghost">Cambiar año</button>
                    </form>
                    <div class="budget-controls">
                        <label for="extraColumns">Tarjetas por fila</label>
                        <select id="extraColumns">
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3" selected>3</option>
                            <option value="4">4</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="extra-grid">
                <?php for ($i = 1; $i <= 12; $i++):
                    $monthKey = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
                    $monthValue = $year . '-' . $monthKey;
                    $monthLabel = $monthNames[$monthKey] ?? $monthKey;
                    $monthTotal = $extraordinaryTotals[$monthValue] ?? 0.0;
                    $monthRows = $extraordinaryByMonth[$monthValue] ?? [];
                    ?>
                    <div class="extra-card">
                        <div class="extra-card-header">
                            <div>
                                <h3><?= h($monthLabel) ?></h3>
                                <p class="muted"><?= h($monthValue) ?></p>
                            </div>
                            <span class="extra-total"><?= format_amount($monthTotal) ?> €</span>
                        </div>
                        <div class="extra-list">
                            <?php if (empty($monthRows)): ?>
                                <p class="muted">Sin gastos registrados.</p>
                            <?php else: ?>
                                <?php foreach ($monthRows as $row): ?>
                                    <div class="extra-item">
                                        <div>
                                            <strong><?= h($row['descripcion']) ?></strong>
                                            <p class="muted">
                                                <?= h($row['categoria_nombre'] ?? 'Sin categoría') ?>
                                                <?= $row['notas'] !== '' ? '· ' . h($row['notas']) : '' ?>
                                            </p>
                                        </div>
                                        <div class="extra-item-actions">
                                            <span class="amount negative"><?= format_amount((float)$row['importe']) ?> €</span>
                                            <form method="post" action="<?= h(app_url('index.php?action=delete_extraordinary')) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <input type="hidden" name="year" value="<?= h($year) ?>">
                                                <button type="submit" class="danger icon-button" aria-label="Eliminar gasto">
                                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                        <path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v8h-2V9Zm4 0h2v8h-2V9ZM7 9h2v8H7V9Zm-1 12h12a2 2 0 0 0 2-2V9H4v10a2 2 0 0 0 2 2Z" fill="currentColor"/>
                                                    </svg>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <form method="post" action="<?= h(app_url('index.php?action=add_extraordinary')) ?>" class="extra-form">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="month" value="<?= h($monthValue) ?>">
                            <label>
                                Concepto
                                <input type="text" name="descripcion" placeholder="Ej: Seguro anual" required>
                            </label>
                            <label>
                                Importe
                                <input type="number" step="0.01" min="0" name="importe" placeholder="0,00" required>
                            </label>
                            <label>
                                Categoría
                                <select name="categoria_id" required>
                                    <option value="">Selecciona</option>
                                    <?php foreach ($activeCategories as $cat): ?>
                                        <option value="<?= (int)$cat['id'] ?>"><?= h($cat['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="full">
                                Notas
                                <input type="text" name="notas" placeholder="Opcional">
                            </label>
                            <div class="actions">
                                <button type="submit" class="primary">Añadir</button>
                            </div>
                        </form>
                    </div>
                <?php endfor; ?>
            </div>
        </section>
    <?php elseif ($page === 'budget'): ?>
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Presupuesto por categoría</h2>
                    <p class="muted">El presupuesto es el protagonista: ajusta rápido y ve el progreso al instante.</p>
                </div>
                <div class="panel-actions">
                    <form class="inline-form" method="get" action="<?= h(app_url('index.php')) ?>">
                        <input type="hidden" name="page" value="budget">
                        <input type="month" name="month" value="<?= h($month) ?>">
                        <button type="submit" class="ghost">Cambiar mes</button>
                    </form>
                    <form method="post" action="<?= h(app_url('index.php?action=copy_budgets')) ?>" class="inline-form" data-has-existing="<?= $hasBudgetsForMonth ? '1' : '0' ?>">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="month" value="<?= h($month) ?>">
                        <input type="hidden" name="confirm_overwrite" value="0">
                        <button type="submit" class="ghost" id="copyBudgets">Copiar del mes anterior</button>
                    </form>
                    <div class="budget-controls">
                        <label for="budgetColumns">Tarjetas por fila</label>
                        <select id="budgetColumns">
                            <option value="2">2</option>
                            <option value="3" selected>3</option>
                            <option value="4">4</option>
                        </select>
                    </div>
                </div>
            </div>
            <form method="post" action="<?= h(app_url('index.php?action=save_budgets')) ?>" class="budget-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="month" value="<?= h($month) ?>">
                <div class="budget-grid">
                    <?php foreach ($activeCategories as $cat):
                        $budget = $budgetsByCategory[(int)$cat['id']] ?? null;
                        $planned = (float)($budget['planned_amount'] ?? 0);
                        $actual = $actualByCategory[(int)$cat['id']] ?? 0.0;
                        $remaining = $planned - $actual;
                        $progress = $planned > 0 ? min(100, ($actual / $planned) * 100) : 0;
                        ?>
                        <div class="budget-card">
                            <div class="budget-card-header">
                                <div>
                                    <h3><?= h($cat['nombre']) ?></h3>
                                    <p class="muted"><?= h(ucfirst($cat['tipo'])) ?></p>
                                </div>
                                <span class="budget-chip <?= $remaining >= 0 ? 'positive' : 'negative' ?>">
                                    <?= $remaining >= 0 ? 'Vas bien' : 'Ojo' ?>
                                </span>
                            </div>
                            <div class="budget-metrics">
                                <div>
                                    <span>Presupuesto</span>
                                    <strong><?= format_amount($planned) ?> €</strong>
                                </div>
                                <div>
                                    <span>Real</span>
                                    <strong><?= format_amount($actual) ?> €</strong>
                                </div>
                                <div>
                                    <span>Te quedan</span>
                                    <strong class="<?= $remaining >= 0 ? 'positive' : 'negative' ?>"><?= format_amount($remaining) ?> €</strong>
                                </div>
                            </div>
                            <div class="budget-bar <?= $remaining < 0 ? 'over' : '' ?>" role="progressbar" aria-valuenow="<?= (int)$progress ?>" aria-valuemin="0" aria-valuemax="100">
                                <span style="width: <?= (int)$progress ?>%"></span>
                            </div>
                            <div class="budget-edit">
                                <label>
                                    Presupuesto
                                    <input type="number" step="0.01" min="0" name="planned_amount[<?= (int)$cat['id'] ?>]" value="<?= h($budget['planned_amount'] ?? '') ?>" placeholder="0,00">
                                </label>
                                <button class="ghost" type="submit" name="save_single" value="<?= (int)$cat['id'] ?>">Guardar</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="actions">
                    <button type="submit" class="primary">Guardar todo</button>
                </div>
            </form>
        </section>
    <?php elseif ($page === 'backup'): ?>
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Backup</h2>
                    <p class="muted">Guarda tu información y recupérala en segundos.</p>
                </div>
            </div>
            <div class="backup-grid">
                <div class="backup-card">
                    <h3>Exportar</h3>
                    <p class="muted">Descarga un archivo JSON con todos tus movimientos, categorías y presupuestos.</p>
                    <a href="<?= h(app_url('index.php?action=export_backup')) ?>" class="primary backup-icon" aria-label="Exportar copia">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M12 3a1 1 0 0 1 1 1v8.59l2.3-2.3 1.4 1.42-4.7 4.7-4.7-4.7 1.4-1.42 2.3 2.3V4a1 1 0 0 1 1-1Zm-7 14h14v2H5v-2Z" fill="currentColor"/>
                        </svg>
                        <span class="sr-only">Exportar copia</span>
                    </a>
                </div>
                <div class="backup-card backup-import">
                    <h3>Importar</h3>
                    <p class="muted">Restaura una copia previa. Se reemplazarán los datos actuales.</p>
                    <form method="post" action="<?= h(app_url('index.php?action=import_backup')) ?>" enctype="multipart/form-data" class="backup-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="file" name="backup" accept="application/json" required>
                        <button type="submit" class="danger backup-icon" aria-label="Importar copia">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M12 20a1 1 0 0 1-1-1v-8.59l-2.3 2.3-1.4-1.42 4.7-4.7 4.7 4.7-1.4 1.42-2.3-2.3V19a1 1 0 0 1-1 1Zm-7-14h14V4H5v2Z" fill="currentColor"/>
                            </svg>
                            <span class="sr-only">Importar copia</span>
                        </button>
                    </form>
                </div>
            </div>
        </section>
    <?php elseif ($page === 'edit_movement'):
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM movements WHERE id = ?');
        $stmt->execute([$id]);
        $movement = $stmt->fetch();
        if (!$movement) {
            redirect_with_message('Movimiento no encontrado.', 'index.php');
        }
        ?>
        <section class="panel">
            <h2>Editar gasto</h2>
            <form method="post" action="<?= h(app_url('index.php?action=save_movement')) ?>" class="form-grid">
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
                    Cuenta
                    <select name="cuenta">
                        <option value="">Sin cuenta</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?= h($account['nombre']) ?>" <?= $account['nombre'] === $movement['cuenta'] ? 'selected' : '' ?>>
                                <?= h($account['nombre']) ?>
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
                <details class="optional-details full">
                    <summary>Notas opcionales</summary>
                    <textarea name="notas" rows="3" placeholder="Añade contexto si lo necesitas"><?= h($movement['notas']) ?></textarea>
                </details>
                <div class="actions full">
                    <a href="<?= h(app_url('index.php')) ?>" class="ghost">Cancelar</a>
                    <button type="submit" class="primary">Guardar</button>
                </div>
            </form>
        </section>
    <?php else: ?>
        <section class="panel quick-entry">
            <div class="panel-header">
                <div>
                    <h2>Captura rápida</h2>
                    <p class="muted">Descripción, importe y categoría sugerida sin pasos extra.</p>
                </div>
            </div>
            <form method="post" action="<?= h(app_url('index.php?action=add_movement')) ?>" class="quick-entry-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="quick-entry-row account">
                    <label>
                        Cuenta
                        <select name="cuenta" required>
                            <?php if (empty($quickEntryAccounts)): ?>
                                <option value="" selected>Sin cuentas activas</option>
                            <?php else: ?>
                                <?php foreach ($quickEntryAccounts as $account): ?>
                                    <option value="<?= h($account['nombre']) ?>" <?= $account['nombre'] === $defaultAccount ? 'selected' : '' ?>>
                                        <?= h($account['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </label>
                </div>
                <div class="quick-entry-row primary">
                    <label>
                        Fecha
                        <input type="date" name="fecha" value="<?= h(date('Y-m-d')) ?>" required>
                    </label>
                    <label>
                        Descripción
                        <input type="text" name="descripcion" id="descriptionInput" placeholder="Ej: Supermercado" required>
                    </label>
                    <label>
                        Importe
                        <input type="number" step="0.01" name="importe" placeholder="-24,90" required>
                    </label>
                </div>
                <div class="quick-entry-row category">
                    <label>
                        Categoría sugerida
                        <select name="categoria" id="categorySelect" required>
                            <option value="">Selecciona...</option>
                            <?php foreach ($categories as $cat): ?>
                                <?php if (!$cat['activa']) continue; ?>
                                <option value="<?= h($cat['nombre']) ?>" data-keywords="<?= h($cat['keywords']) ?>"><?= h($cat['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="primary">Listo</button>
                </div>
            </form>
        </section>

        <section class="panel month-status">
            <div class="panel-header">
                <div>
                    <h2>Estado del mes</h2>
                    <p class="muted"><?= $balance >= 0 ? 'Vas bien este mes. Sigue así.' : 'Ojo con los gastos. Toca ajustar.' ?></p>
                </div>
            </div>
            <div class="status-cards">
                <div class="status-card">
                    <span>Ingresos</span>
                    <strong class="positive"><?= format_amount($ingresos) ?> €</strong>
                </div>
                <div class="status-card">
                    <span>Gastos</span>
                    <strong class="negative"><?= format_amount($realGastos) ?> €</strong>
                </div>
                <div class="status-card">
                    <span>Balance</span>
                    <strong class="<?= $balance >= 0 ? 'positive' : 'negative' ?>"><?= format_amount($balance) ?> €</strong>
                </div>
            </div>
        </section>

        <section class="panel" id="movements">
            <div class="panel-header">
                <div>
                    <h2>Tu mes en números</h2>
                    <p class="muted" id="reviewHint">Pendientes destacados, revisados en gris.</p>
                </div>
                <div class="panel-actions">
                    <button type="button" class="ghost" id="reviewWeek">Revisar semana</button>
                    <?php if ($pendingCount > 0): ?>
                        <form method="post" action="<?= h(app_url('index.php?action=bulk_review')) ?>" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="month" value="<?= h($month) ?>">
                            <input type="hidden" name="status" value="<?= h($filters['status']) ?>">
                            <input type="hidden" name="category" value="<?= h($filters['category']) ?>">
                            <input type="hidden" name="account" value="<?= h($filters['account']) ?>">
                            <input type="hidden" name="search" value="<?= h($filters['search']) ?>">
                            <input type="hidden" name="redirect" value="<?= h($currentUri . '#movements') ?>">
                            <button type="submit" class="ghost">Marcar todo como revisado</button>
                        </form>
                    <?php endif; ?>
                    <form class="inline-form" method="get" action="<?= h(app_url('index.php')) ?>">
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
                        <select name="account">
                            <option value="all">Todas cuentas</option>
                            <?php foreach ($accounts as $account): ?>
                                <option value="<?= h($account['nombre']) ?>" <?= $filters['account'] === $account['nombre'] ? 'selected' : '' ?>>
                                    <?= h($account['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="search" name="search" value="<?= h($filters['search']) ?>" placeholder="Buscar">
                        <button type="submit" class="ghost">Filtrar</button>
                    </form>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="movements-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Descripción</th>
                            <th>Importe</th>
                            <th>Categoría</th>
                            <th>Cuenta</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movements as $move): ?>
                            <?php $isPending = $move['estado'] === 'pendiente'; ?>
                            <?php $amountValue = (float)$move['importe']; ?>
                            <tr id="movement-<?= (int)$move['id'] ?>" class="movement-row <?= $isPending ? 'pending' : 'reviewed' ?>" data-movement data-status="<?= h($move['estado']) ?>" data-date="<?= h($move['fecha']) ?>">
                                <td><?= h($move['fecha']) ?></td>
                                <td>
                                    <strong><?= h($move['descripcion']) ?></strong>
                                    <?php if (!empty($move['notas'])): ?>
                                        <div class="muted"><?= h($move['notas']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="amount <?= $amountValue >= 0 ? 'positive' : 'negative' ?>"><?= format_amount(abs($amountValue)) ?> €</td>
                                <td>
                                    <form method="post" action="<?= h(app_url('index.php?action=quick_category')) ?>" class="inline">
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
                                    <span class="account-pill"><?= $move['cuenta'] !== '' ? h($move['cuenta']) : '—' ?></span>
                                </td>
                                <td>
                                    <form method="post" action="<?= h(app_url('index.php?action=update_status')) ?>" class="inline status-form" data-status-form>
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$move['id'] ?>">
                                        <input type="hidden" name="estado" value="<?= $move['estado'] === 'pendiente' ? 'revisado' : 'pendiente' ?>">
                                        <input type="hidden" name="redirect" value="<?= h($currentUri . '#movement-' . (int)$move['id']) ?>">
                                        <button class="status-pill <?= $isPending ? 'pending' : 'reviewed' ?>" type="submit">
                                            <?php if ($isPending): ?>
                                                <svg class="status-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                    <path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 5v5.25l3.5 2.05-.9 1.46L11 13V7h2Z" fill="currentColor"/>
                                                </svg>
                                                <span class="sr-only">Pendiente</span>
                                            <?php else: ?>
                                                <svg class="status-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                    <path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm-1.1 13.6 5.8-5.8 1.4 1.4-7.2 7.2-3.8-3.8 1.4-1.4 2.4 2.4Z" fill="currentColor"/>
                                                </svg>
                                                <span class="sr-only">Revisado</span>
                                            <?php endif; ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <a class="ghost icon-button" href="<?= h(app_url('index.php?page=edit_movement&id=' . (int)$move['id'])) ?>" aria-label="Editar" title="Editar">
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                            <path d="M15.232 5.232a2.5 2.5 0 0 1 3.536 3.536L8.5 19.036l-4.5 1 1-4.5 10.232-10.304Zm2.12 1.414a.5.5 0 0 0-.707 0l-1.06 1.06 1.414 1.415 1.06-1.061a.5.5 0 0 0 0-.707l-.707-.707Zm-2.475 2.475L6.5 17.5l-0.5 2 2-0.5 8.379-8.379-1.414-1.415Z" fill="currentColor"/>
                                        </svg>
                                        <span class="sr-only">Editar</span>
                                    </a>
                                    <form method="post" action="<?= h(app_url('index.php?action=delete_movement')) ?>" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$move['id'] ?>">
                                        <button class="danger icon-button" type="submit" aria-label="Eliminar" title="Eliminar">
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                <path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v8h-2V9Zm4 0h2v8h-2V9ZM7 9h2v8H7V9Zm-1 12h12a2 2 0 0 0 2-2V9H4v10a2 2 0 0 0 2 2Z" fill="currentColor"/>
                                            </svg>
                                            <span class="sr-only">Eliminar</span>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" class="muted"><strong>Total</strong></td>
                            <td class="amount <?= $filteredTotal >= 0 ? 'positive' : 'negative' ?>">
                                <strong><?= format_amount(abs($filteredTotal)) ?> €</strong>
                            </td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>
<script src="<?= h(app_url('assets/app.js')) ?>"></script>
</body>
</html>
