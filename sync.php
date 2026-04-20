<?php
declare(strict_types=1);

// ============================================================
// BOOTSTRAP
// ============================================================

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$dotenv->required(['API_URL', 'API_KEY_HEADER_NAME', 'API_KEY', 'DB_PATH', 'LOCK_FILE', 'LOG_FILE']);

// ============================================================
// CONFIGURACIÓN
// ============================================================

$config = [
    'api_url'        => $_ENV['API_URL'],
    'api_key_header' => $_ENV['API_KEY_HEADER_NAME'],
    'api_key'        => $_ENV['API_KEY'],
    'batch_size'     => (int) ($_ENV['BATCH_SIZE']     ?? 50),
    'http_timeout'   => (int) ($_ENV['HTTP_TIMEOUT']   ?? 8),
    'db_path'        => $_ENV['DB_PATH'],
    'lock_file'      => $_ENV['LOCK_FILE'],
    'log_file'       => $_ENV['LOG_FILE'],
    'log_max_bytes'  => (int) ($_ENV['LOG_MAX_BYTES']  ?? 1048576),
    // Si se define DEVICE_ID en .env, sobreescribe el device_id de la BD.
    // Útil cuando todos los registros pertenecen al mismo dispositivo
    // o al deployar en un servidor diferente.
    'device_id'      => $_ENV['DEVICE_ID'] ?? null,
];

// ============================================================
// HELPERS
// ============================================================

function logMessage(string $level, string $message, array $config): void
{
    $line = date('Y-m-d H:i:s') . " [$level] $message" . PHP_EOL;

    if (file_exists($config['log_file'])
        && filesize($config['log_file']) >= $config['log_max_bytes']) {
        $lines   = file($config['log_file']);
        $trimmed = array_slice($lines, -256);
        file_put_contents($config['log_file'], implode('', $trimmed));
    }

    file_put_contents($config['log_file'], $line, FILE_APPEND | LOCK_EX);
}

/**
 * Retorna un array con el resultado del intento:
 *   ok        — true si el servidor respondió HTTP 2xx
 *   http_code — código HTTP recibido (0 si hubo error de red)
 *   error     — descripción del fallo, vacía si todo fue bien
 *   response  — primeros 200 chars del cuerpo de respuesta
 */
function postMeasurement(array $record, array $config): array
{
    // device_id: usa el override del .env si está definido, si no el de la BD
    $deviceId = $config['device_id'] ?? $record['device_id'];

    // Normalizar timestamp al formato que espera el servidor: "YYYY-MM-DD HH:MM:SS"
    // La BD guarda ISO 8601 con microsegundos: "2026-04-05T18:50:35.473464"
    $timestamp = str_replace('T', ' ', substr($record['timestamp'], 0, 19));

    $payload = json_encode([
        'device_id'      => $deviceId,
        'distance_cm'    => (float) $record['distance_cm'],
        'temperature'    => round($record['temperature_x10'] / 10, 1),
        'signal_quality' => (int) $record['signal_quality'],
        'timestamp'      => $timestamp,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $config['api_url'],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $config['http_timeout'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            $config['api_key_header'] . ': ' . $config['api_key'],
        ],
    ]);

    $body      = (string) curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Error de red / transporte (timeout, DNS, TLS, etc.)
    if ($curlErrno !== 0) {
        return [
            'ok'        => false,
            'http_code' => 0,
            'error'     => "cURL #$curlErrno: $curlError",
            'response'  => '',
        ];
    }

    $ok = $httpCode >= 200 && $httpCode < 300;

    return [
        'ok'        => $ok,
        'http_code' => $httpCode,
        'error'     => $ok ? '' : "HTTP $httpCode",
        'response'  => mb_substr(trim($body), 0, 200),
    ];
}

// ============================================================
// LOCK FILE — evitar ejecuciones concurrentes
// ============================================================

$lockFile = $config['lock_file'];

if (file_exists($lockFile)) {
    $stalePid  = (int) trim((string) file_get_contents($lockFile));
    $isRunning = false;

    if ($stalePid > 0) {
        if (PHP_OS_FAMILY === 'Windows') {
            exec("tasklist /FI \"PID eq $stalePid\" /NH 2>NUL", $output);
            $isRunning = !empty(array_filter($output, fn($l) => str_contains($l, (string) $stalePid)));
        } else {
            $isRunning = file_exists("/proc/$stalePid");
        }
    }

    if ($isRunning) {
        exit(0);
    }

    logMessage('WARN', "Lock stale (PID $stalePid ya no corre). Sobreescribiendo.", $config);
}

file_put_contents($lockFile, (string) getmypid(), LOCK_EX);

register_shutdown_function(function () use ($lockFile): void {
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
});

// ============================================================
// BASE DE DATOS — abrir SQLite
// ============================================================

try {
    $db = new SQLite3($config['db_path']);
    $db->enableExceptions(true);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');
} catch (Exception $e) {
    logMessage('ERROR', 'No se pudo abrir la BD: ' . $e->getMessage(), $config);
    exit(1);
}

// ============================================================
// CONSULTA — registros pendientes
// ============================================================

$stmt = $db->prepare(
    'SELECT id, timestamp, device_id, distance_cm, temperature_x10,
            signal_quality, read_status
     FROM mediciones
     WHERE sync_status = 0
     ORDER BY id ASC
     LIMIT :batch_size'
);
$stmt->bindValue(':batch_size', $config['batch_size'], SQLITE3_INTEGER);
$result = $stmt->execute();

$records = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $records[] = $row;
}

if (empty($records)) {
    logMessage('INFO', 'Sin registros pendientes.', $config);
    $db->close();
    exit(0);
}

// ============================================================
// SYNC — enviar uno por uno, marcar exitosos
// ============================================================

$updateStmt = $db->prepare(
    'UPDATE mediciones SET sync_status = 1 WHERE id = :id'
);

$successCount = 0;
$failCount    = 0;

foreach ($records as $record) {
    $result = postMeasurement($record, $config);

    if ($result['ok']) {
        $updateStmt->bindValue(':id', $record['id'], SQLITE3_INTEGER);
        $updateStmt->execute();
        $updateStmt->reset();
        $successCount++;
    } else {
        $failCount++;

        $logMsg = "Fallo ID={$record['id']} | {$result['error']}";
        if ($result['response'] !== '') {
            $logMsg .= " | respuesta: {$result['response']}";
        }

        logMessage('ERROR', $logMsg, $config);
    }
}

// ============================================================
// RESUMEN
// ============================================================

$total = count($records);
logMessage('INFO', "Run completo. Procesados=$total Enviados=$successCount Fallidos=$failCount", $config);

$db->close();
exit(0);
