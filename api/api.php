<?php
// ============================================================
// api.php  –  Backend PHP para Recolección BinniBus
// Coloca este archivo en tu servidor junto a config.php
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ── Configuración de BD ───────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'ezyro_41603862_binnibus');
define('DB_USER', 'ezyro_41603862');       // ← cambia
define('DB_PASS', 'Nazarinvs9?');           // ← cambia

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        //$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function resp(bool $ok, $data = null, string $msg = ''): void
{
    echo json_encode(['ok' => $ok, 'data' => $data, 'msg' => $msg]);
    exit;
}

// ── Router ────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch ($action) {

        // ── LOGIN ─────────────────────────────────────────────
        case 'login':
            $user = trim($body['usuario'] ?? '');
            $clave = $body['clave'] ?? '';
            if (!$user || !$clave)
                resp(false, null, 'Datos incompletos');

            $hash = hash('sha256', $clave);
            $db = getDB();
            $st = $db->prepare("
                SELECT u.NumeroUsuario, u.Nombre, u.NombreUsuario, u.Activo,
                       t.Descripcion AS TipoUsuario
                FROM   cat_Usuarios u
                JOIN   cat_TipoUsuarios t ON t.NumeroTipoUsuario = u.NumeroTipoUsuario
                WHERE  u.NombreUsuario = ? AND u.Clave = ?
            ");
            $st->execute([$user, $hash]);
            $row = $st->fetch();
            if (!$row)
                resp(false, null, 'Usuario o contraseña incorrectos');
            if (!$row['Activo'])
                resp(false, null, 'El usuario no está activo en el sistema');
            resp(true, $row);

        // ── TERMINALES ACTIVAS ────────────────────────────────
        case 'terminales':
            $db = getDB();
            $st = $db->query("
                SELECT NumeroTerminal, Terminal
                FROM   cat_Terminales
                WHERE  Activo = 1
                ORDER  BY Terminal DESC
            ");
            resp(true, $st->fetchAll());

        // ── BUSCAR RECOLECCIONES ──────────────────────────────
        case 'buscar':
            $fecha = $body['fecha'] ?? '';
            $terminal = $body['terminal'] ?? 0;   // 0 = TODAS
            if (!$fecha)
                resp(false, null, 'Fecha requerida');

            $db = getDB();
            $params = [$fecha];
            $where = 'r.Fecha = ?';
            if ($terminal > 0) {
                $where .= ' AND r.NumeroTerminal = ?';
                $params[] = $terminal;
            }

            $sql = "
                SELECT
                    r.NumeroRecoleccionBinniBus,
                    r.Fecha,
                    t.Terminal,
                    r.NumeroTerminal,
                    c.NumeroCamion,
                    IFNULL(d.Recuento, 0)   AS Importe,
                    r.Bloqueado
                FROM RecoleccionBinniBus r
                JOIN cat_Terminales t ON t.NumeroTerminal = r.NumeroTerminal
                JOIN RecoleccionBinniBusDetalle d ON d.NumeroRecoleccionBinniBus = r.NumeroRecoleccionBinniBus
                JOIN cat_Camiones c ON c.NumeroCamion = d.NumeroCamion
                WHERE $where
                ORDER BY r.Fecha, t.Terminal, c.NumeroCamion
            ";
            $st = $db->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll();
            resp(true, $rows);

        // ── GUARDAR RECUENTO ──────────────────────────────────
        case 'guardar':
            /*
             * body: {
             *   numeroUsuario: int,
             *   items: [{ NumeroRecoleccionBinniBus, NumeroCamion, Importe }]
             * }
             */
            $numUsuario = (int) ($body['numeroUsuario'] ?? 0);
            $items = $body['items'] ?? [];
            if (!$numUsuario || empty($items))
                resp(false, null, 'Datos insuficientes');

            $db = getDB();
            $hoy = date('Y-m-d');

            $db->beginTransaction();
            $stDet = $db->prepare("
                UPDATE RecoleccionBinniBusDetalle
                SET    Recuento = ?
                WHERE  NumeroRecoleccionBinniBus = ? AND NumeroCamion = ?
            ");
            $stRec = $db->prepare("
                UPDATE RecoleccionBinniBus
                SET    FechaRecuento = ?, NumeroUsuarioRecuento = ?
                WHERE  NumeroRecoleccionBinniBus = ?
            ");
            foreach ($items as $it) {
                $stDet->execute([
                    (float) $it['Importe'],
                    $it['NumeroRecoleccionBinniBus'],
                    $it['NumeroCamion'],
                ]);
                $stRec->execute([
                    $hoy,
                    $numUsuario,
                    $it['NumeroRecoleccionBinniBus'],
                ]);
            }
            $db->commit();
            resp(true, null, 'Guardado correctamente');

        // ── BLOQUEAR ──────────────────────────────────────────
        case 'bloquear':
            /*
             * body: {
             *   numeroUsuario: int,
             *   ids: ["NumeroRecoleccionBinniBus", ...]
             * }
             */
            $numUsuario = (int) ($body['numeroUsuario'] ?? 0);
            $ids = $body['ids'] ?? [];
            if (!$numUsuario || empty($ids))
                resp(false, null, 'Datos insuficientes');

            $db = getDB();
            $db->beginTransaction();
            $st = $db->prepare("
                UPDATE RecoleccionBinniBus
                SET    Bloqueado = 1, NumeroUsuarioBloqueo = ?
                WHERE  NumeroRecoleccionBinniBus = ?
            ");
            foreach ($ids as $id) {
                $st->execute([$numUsuario, $id]);
            }
            $db->commit();
            resp(true, null, 'Registros bloqueados');

        default:
            resp(false, null, 'Acción desconocida');
    }
} catch (Throwable $e) {
    resp(false, null, 'Error del servidor: ' . $e->getMessage());
}
