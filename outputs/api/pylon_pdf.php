<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$configPath = __DIR__ . '/../private/config.php';
if (is_file($configPath)) {
    require_once $configPath;
}

function pylon_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function pylon_request_payload(): array
{
    $payload = $_POST;
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            pylon_json(400, ['ok' => false, 'error' => 'invalid_json']);
        }
        $payload = array_merge($payload, $decoded);
    }
    return array_merge($_GET, $payload);
}

function pylon_config_value(string $key): ?string
{
    if (defined($key)) {
        $value = constant($key);
        return is_scalar($value) ? (string) $value : null;
    }

    foreach ([$key, strtolower($key)] as $name) {
        if (isset($GLOBALS[$name]) && is_scalar($GLOBALS[$name])) {
            return (string) $GLOBALS[$name];
        }
    }

    $env = getenv($key);
    return $env === false ? null : $env;
}

function pylon_authorize(): void
{
    $expected = pylon_config_value('ARKGRID_AGENT_TOKEN');
    if ($expected === null || $expected === '') {
        pylon_json(500, ['ok' => false, 'error' => 'agent_token_not_configured']);
    }

    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        pylon_json(401, ['ok' => false, 'error' => 'missing_bearer_token']);
    }

    if (!hash_equals($expected, trim($matches[1]))) {
        pylon_json(403, ['ok' => false, 'error' => 'invalid_bearer_token']);
    }
}

function pylon_forbidden_key_scan(array $value, string $prefix = ''): ?string
{
    $blocked = [
        'approve',
        'approved',
        'approval',
        'delete',
        'deleted',
        'password',
        'price',
        'pricing',
        'secret',
        'token',
    ];

    foreach ($value as $key => $child) {
        $keyName = is_string($key) ? strtolower($key) : (string) $key;
        foreach ($blocked as $needle) {
            if (str_contains($keyName, $needle)) {
                return ltrim($prefix . '.' . $keyName, '.');
            }
        }
        if (is_array($child)) {
            $found = pylon_forbidden_key_scan($child, ltrim($prefix . '.' . $keyName, '.'));
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

function pylon_db(): PDO
{
    foreach (['pdo', 'db'] as $globalName) {
        if (isset($GLOBALS[$globalName]) && $GLOBALS[$globalName] instanceof PDO) {
            return $GLOBALS[$globalName];
        }
    }

    foreach (['db', 'get_db', 'get_pdo', 'arkgrid_db'] as $functionName) {
        if (function_exists($functionName)) {
            $candidate = $functionName();
            if ($candidate instanceof PDO) {
                return $candidate;
            }
        }
    }

    $dsn = pylon_config_value('ARKGRID_DB_DSN') ?: pylon_config_value('DB_DSN');
    $user = pylon_config_value('ARKGRID_DB_USER') ?: pylon_config_value('DB_USER');
    $pass = pylon_config_value('ARKGRID_DB_PASS') ?: pylon_config_value('DB_PASS') ?: '';
    if ($dsn !== null && $dsn !== '') {
        $pdo = new PDO($dsn, $user ?? '', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    }

    pylon_json(500, ['ok' => false, 'error' => 'database_not_configured']);
}

function pylon_int(array $payload, string $key, int $default, int $min, int $max): int
{
    $value = isset($payload[$key]) ? (int) $payload[$key] : $default;
    return max($min, min($max, $value));
}

function pylon_string_or_null(array $payload, string $key, int $maxLength = 255): ?string
{
    if (!isset($payload[$key])) {
        return null;
    }
    $value = trim((string) $payload[$key]);
    if ($value === '') {
        return null;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }
    return substr($value, 0, $maxLength);
}

function pylon_json_or_null(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_string($value)) {
        json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $value;
        }
    }
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        pylon_json(400, ['ok' => false, 'error' => 'invalid_metadata_json']);
    }
    return $encoded;
}

function pylon_fetch_job(PDO $pdo, int $jobId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM pylon_pdf_jobs WHERE id = :id');
    $stmt->execute([':id' => $jobId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function pylon_list_pending(PDO $pdo, array $payload): void
{
    $limit = pylon_int($payload, 'limit', 25, 1, 100);
    $stmt = $pdo->prepare(
        "SELECT * FROM pylon_pdf_jobs
         WHERE status IN ('pending', 'failed')
           AND attempts < max_attempts
           AND available_at <= NOW()
         ORDER BY priority DESC, available_at ASC, id ASC
         LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    pylon_json(200, ['ok' => true, 'jobs' => $stmt->fetchAll()]);
}

function pylon_claim(PDO $pdo, array $payload): void
{
    $agent = pylon_string_or_null($payload, 'agent', 128) ?: 'openclaw';
    $jobId = isset($payload['job_id']) ? (int) $payload['job_id'] : null;

    $pdo->beginTransaction();
    try {
        if ($jobId !== null && $jobId > 0) {
            $stmt = $pdo->prepare(
                "SELECT * FROM pylon_pdf_jobs
                 WHERE id = :id
                   AND status IN ('pending', 'failed')
                   AND attempts < max_attempts
                   AND available_at <= NOW()
                 FOR UPDATE"
            );
            $stmt->execute([':id' => $jobId]);
        } else {
            $stmt = $pdo->query(
                "SELECT * FROM pylon_pdf_jobs
                 WHERE status IN ('pending', 'failed')
                   AND attempts < max_attempts
                   AND available_at <= NOW()
                 ORDER BY priority DESC, available_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
        }

        $job = $stmt->fetch();
        if ($job === false) {
            $pdo->commit();
            pylon_json(200, ['ok' => true, 'job' => null]);
        }

        $update = $pdo->prepare(
            "UPDATE pylon_pdf_jobs
             SET status = 'claimed',
                 attempts = attempts + 1,
                 claimed_by = :agent,
                 claimed_at = NOW(),
                 last_error = NULL
             WHERE id = :id"
        );
        $update->execute([':agent' => $agent, ':id' => (int) $job['id']]);
        $pdo->commit();

        pylon_json(200, ['ok' => true, 'job' => pylon_fetch_job($pdo, (int) $job['id'])]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function pylon_complete(PDO $pdo, array $payload): void
{
    $jobId = isset($payload['job_id']) ? (int) $payload['job_id'] : 0;
    $fileName = pylon_string_or_null($payload, 'file_name', 255);
    $storagePath = pylon_string_or_null($payload, 'storage_path', 4096);
    if ($jobId <= 0 || $fileName === null || $storagePath === null) {
        pylon_json(400, ['ok' => false, 'error' => 'job_id_file_name_storage_path_required']);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM pylon_pdf_jobs WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch();
        if ($job === false) {
            $pdo->commit();
            pylon_json(404, ['ok' => false, 'error' => 'job_not_found']);
        }

        $insert = $pdo->prepare(
            "INSERT INTO pylon_documents (
                job_id, proposal_id, proposal_number, customer_id, customer_name,
                document_type, pylon_document_id, file_name, storage_path, sha256_hash,
                file_size_bytes, mime_type, downloaded_by, downloaded_at, metadata_json
             ) VALUES (
                :job_id, :proposal_id, :proposal_number, :customer_id, :customer_name,
                :document_type, :pylon_document_id, :file_name, :storage_path, :sha256_hash,
                :file_size_bytes, :mime_type, :downloaded_by, NOW(), :metadata_json
             )"
        );
        $insert->execute([
            ':job_id' => $jobId,
            ':proposal_id' => pylon_string_or_null($payload, 'proposal_id', 128) ?: $job['proposal_id'],
            ':proposal_number' => pylon_string_or_null($payload, 'proposal_number', 128) ?: $job['proposal_number'],
            ':customer_id' => pylon_string_or_null($payload, 'customer_id', 128) ?: $job['customer_id'],
            ':customer_name' => pylon_string_or_null($payload, 'customer_name', 255) ?: $job['customer_name'],
            ':document_type' => pylon_string_or_null($payload, 'document_type', 64) ?: 'proposal_pdf',
            ':pylon_document_id' => pylon_string_or_null($payload, 'pylon_document_id', 128),
            ':file_name' => $fileName,
            ':storage_path' => $storagePath,
            ':sha256_hash' => pylon_string_or_null($payload, 'sha256_hash', 64),
            ':file_size_bytes' => isset($payload['file_size_bytes']) ? (int) $payload['file_size_bytes'] : null,
            ':mime_type' => pylon_string_or_null($payload, 'mime_type', 128) ?: 'application/pdf',
            ':downloaded_by' => pylon_string_or_null($payload, 'agent', 128) ?: pylon_string_or_null($payload, 'downloaded_by', 128),
            ':metadata_json' => pylon_json_or_null($payload['metadata'] ?? null),
        ]);
        $documentId = (int) $pdo->lastInsertId();

        $update = $pdo->prepare(
            "UPDATE pylon_pdf_jobs
             SET status = 'completed',
                 completed_at = NOW(),
                 last_error = NULL
             WHERE id = :id"
        );
        $update->execute([':id' => $jobId]);
        $pdo->commit();

        pylon_json(200, ['ok' => true, 'job' => pylon_fetch_job($pdo, $jobId), 'document_id' => $documentId]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function pylon_fail(PDO $pdo, array $payload): void
{
    $jobId = isset($payload['job_id']) ? (int) $payload['job_id'] : 0;
    $message = pylon_string_or_null($payload, 'error', 4096) ?: 'download_failed';
    if ($jobId <= 0) {
        pylon_json(400, ['ok' => false, 'error' => 'job_id_required']);
    }

    $retryAfter = pylon_int($payload, 'retry_after_seconds', 900, 0, 86400);
    $availableAt = gmdate('Y-m-d H:i:s', time() + $retryAfter);
    $stmt = $pdo->prepare(
        "UPDATE pylon_pdf_jobs
         SET status = 'failed',
             last_error = :error,
             available_at = :available_at
         WHERE id = :id"
    );
    $stmt->execute([':error' => $message, ':available_at' => $availableAt, ':id' => $jobId]);

    if ($stmt->rowCount() === 0) {
        pylon_json(404, ['ok' => false, 'error' => 'job_not_found']);
    }
    pylon_json(200, ['ok' => true, 'job' => pylon_fetch_job($pdo, $jobId)]);
}

function pylon_manual_login_required(PDO $pdo, array $payload): void
{
    $jobId = isset($payload['job_id']) ? (int) $payload['job_id'] : 0;
    $reason = pylon_string_or_null($payload, 'reason', 4096) ?: 'manual_login_required';
    if ($jobId <= 0) {
        pylon_json(400, ['ok' => false, 'error' => 'job_id_required']);
    }

    $stmt = $pdo->prepare(
        "UPDATE pylon_pdf_jobs
         SET status = 'manual_login_required',
             last_error = :reason
         WHERE id = :id"
    );
    $stmt->execute([':reason' => $reason, ':id' => $jobId]);
    if ($stmt->rowCount() === 0) {
        pylon_json(404, ['ok' => false, 'error' => 'job_not_found']);
    }
    pylon_json(200, ['ok' => true, 'job' => pylon_fetch_job($pdo, $jobId)]);
}

function pylon_list_documents(PDO $pdo, array $payload): void
{
    $limit = pylon_int($payload, 'limit', 50, 1, 200);
    $where = [];
    $params = [];
    if (isset($payload['job_id']) && (int) $payload['job_id'] > 0) {
        $where[] = 'job_id = :job_id';
        $params[':job_id'] = (int) $payload['job_id'];
    }

    foreach (['proposal_id', 'customer_id', 'pylon_document_id'] as $key) {
        $value = pylon_string_or_null($payload, $key, 128);
        if ($value !== null) {
            $where[] = $key . ' = :' . $key;
            $params[':' . $key] = $value;
        }
    }

    $sql = 'SELECT * FROM pylon_documents';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY downloaded_at DESC, id DESC LIMIT :limit';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($key, $value, $type);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    pylon_json(200, ['ok' => true, 'documents' => $stmt->fetchAll()]);
}

try {
    pylon_authorize();
    $payload = pylon_request_payload();
    $forbidden = pylon_forbidden_key_scan($payload);
    if ($forbidden !== null) {
        pylon_json(400, ['ok' => false, 'error' => 'forbidden_field', 'field' => $forbidden]);
    }

    $action = pylon_string_or_null($payload, 'action', 64);
    if ($action === null) {
        pylon_json(400, ['ok' => false, 'error' => 'action_required']);
    }

    $pdo = pylon_db();
    match ($action) {
        'list-pending' => pylon_list_pending($pdo, $payload),
        'claim' => pylon_claim($pdo, $payload),
        'complete' => pylon_complete($pdo, $payload),
        'fail' => pylon_fail($pdo, $payload),
        'manual-login-required' => pylon_manual_login_required($pdo, $payload),
        'list-documents' => pylon_list_documents($pdo, $payload),
        default => pylon_json(400, ['ok' => false, 'error' => 'unsupported_action']),
    };
} catch (Throwable $error) {
    pylon_json(500, ['ok' => false, 'error' => 'server_error', 'message' => $error->getMessage()]);
}
