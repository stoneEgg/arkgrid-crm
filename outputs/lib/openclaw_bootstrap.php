<?php

declare(strict_types=1);

function openclaw_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function openclaw_request_json(): array
{
    $body = file_get_contents('php://input');
    if ($body === false || trim($body) === '') {
        return [];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        openclaw_json_response([
            'ok' => false,
            'error' => 'invalid_json',
        ], 400);
    }

    return $decoded;
}

function openclaw_database_config(): array
{
    $config = [];
    $privateConfig = dirname(__DIR__) . '/private/config.php';

    if (is_readable($privateConfig)) {
        $loaded = require $privateConfig;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    }

    return [
        'dsn' => getenv('OPENCLAW_DATABASE_DSN') ?: ($config['database']['dsn'] ?? $config['db']['dsn'] ?? null),
        'user' => getenv('OPENCLAW_DATABASE_USER') ?: ($config['database']['user'] ?? $config['db']['user'] ?? null),
        'password' => getenv('OPENCLAW_DATABASE_PASSWORD') ?: ($config['database']['password'] ?? $config['db']['password'] ?? null),
    ];
}

function openclaw_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = openclaw_database_config();
    if (!$config['dsn']) {
        openclaw_json_response([
            'ok' => false,
            'error' => 'database_not_configured',
        ], 503);
    }

    try {
        $pdo = new PDO((string) $config['dsn'], (string) $config['user'], (string) $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        openclaw_json_response([
            'ok' => false,
            'error' => 'database_connection_failed',
        ], 503);
    }

    return $pdo;
}

function openclaw_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1) {
        return trim($matches[1]);
    }

    $machineToken = $_SERVER['HTTP_X_MACHINE_TOKEN'] ?? '';
    if ($machineToken !== '') {
        return trim($machineToken);
    }

    return null;
}

function openclaw_machine_actor(PDO $pdo): array
{
    $token = openclaw_bearer_token();
    if ($token === null || $token === '') {
        openclaw_json_response([
            'ok' => false,
            'error' => 'machine_token_required',
        ], 401);
    }

    $statement = $pdo->prepare(
        'SELECT id, name, allowed_actions, risk_level FROM openclaw_machine_tokens WHERE token_hash = :token_hash AND is_active = 1 LIMIT 1'
    );
    $statement->execute(['token_hash' => hash('sha256', $token)]);
    $actor = $statement->fetch();

    if (!$actor) {
        openclaw_json_response([
            'ok' => false,
            'error' => 'invalid_machine_token',
        ], 401);
    }

    $allowedActions = json_decode((string) $actor['allowed_actions'], true);
    if (!is_array($allowedActions)) {
        $allowedActions = [];
    }

    $update = $pdo->prepare('UPDATE openclaw_machine_tokens SET last_used_at = NOW() WHERE id = :id');
    $update->execute(['id' => $actor['id']]);

    return [
        'type' => 'machine',
        'id' => (int) $actor['id'],
        'name' => (string) $actor['name'],
        'risk_level' => (string) $actor['risk_level'],
        'allowed_actions' => $allowedActions,
    ];
}

function openclaw_action_risk(string $action): string
{
    $highRisk = [
        'approve',
        'reject',
        'change_pricing',
        'modify_credentials',
        'credential',
        'secret',
        'key',
        'token',
        'password',
        'ssh',
        'oauth',
        'rotate_secret',
        'delete',
        'payment',
    ];

    foreach ($highRisk as $blocked) {
        if (stripos($action, $blocked) !== false) {
            return 'high';
        }
    }

    return 'low';
}

function openclaw_require_low_risk_machine_action(PDO $pdo, array $actor, string $action): void
{
    $riskLevel = openclaw_action_risk($action);
    $allowed = in_array($action, $actor['allowed_actions'], true)
        || in_array('*', $actor['allowed_actions'], true);

    if ($riskLevel !== 'low' || !$allowed) {
        openclaw_audit($pdo, $actor, $action, $riskLevel, 'denied', [
            'reason' => $riskLevel !== 'low' ? 'high_risk_machine_action' : 'action_not_allowed',
        ]);

        openclaw_json_response([
            'ok' => false,
            'error' => $riskLevel !== 'low' ? 'machine_token_low_risk_only' : 'action_not_allowed',
        ], 403);
    }
}

function openclaw_audit(PDO $pdo, array $actor, string $action, string $riskLevel, string $outcome, array $metadata = []): void
{
    $statement = $pdo->prepare(
        'INSERT INTO openclaw_audit_events (actor_type, actor_id, action, risk_level, outcome, metadata) VALUES (:actor_type, :actor_id, :action, :risk_level, :outcome, :metadata)'
    );
    $statement->execute([
        'actor_type' => $actor['type'] ?? 'system',
        'actor_id' => $actor['id'] ?? null,
        'action' => $action,
        'risk_level' => $riskLevel,
        'outcome' => $outcome,
        'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function openclaw_queue_job(PDO $pdo, array $actor, string $action, array $payload = []): int
{
    $riskLevel = openclaw_action_risk($action);
    $statement = $pdo->prepare(
        'INSERT INTO openclaw_agent_jobs (action, payload, risk_level, requested_by_type, requested_by_id) VALUES (:action, :payload, :risk_level, :requested_by_type, :requested_by_id)'
    );
    $statement->execute([
        'action' => $action,
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'risk_level' => $riskLevel,
        'requested_by_type' => $actor['type'],
        'requested_by_id' => $actor['id'],
    ]);

    $jobId = (int) $pdo->lastInsertId();
    openclaw_audit($pdo, $actor, $action, $riskLevel, 'queued', ['job_id' => $jobId]);

    return $jobId;
}

function openclaw_trigger_daily_run(PDO $pdo, array $actor, string $runType, array $payload = []): int
{
    $runDate = (new DateTimeImmutable('today'))->format('Y-m-d');
    $statement = $pdo->prepare(
        'INSERT INTO openclaw_daily_runs (run_type, run_date, status, triggered_by_type, triggered_by_id, payload) VALUES (:run_type, :run_date, :status, :triggered_by_type, :triggered_by_id, :payload) ON DUPLICATE KEY UPDATE status = VALUES(status), triggered_by_type = VALUES(triggered_by_type), triggered_by_id = VALUES(triggered_by_id), payload = VALUES(payload), updated_at = NOW()'
    );
    $statement->execute([
        'run_type' => $runType,
        'run_date' => $runDate,
        'status' => 'queued',
        'triggered_by_type' => $actor['type'],
        'triggered_by_id' => $actor['id'],
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $idStatement = $pdo->prepare('SELECT id FROM openclaw_daily_runs WHERE run_type = :run_type AND run_date = :run_date LIMIT 1');
    $idStatement->execute([
        'run_type' => $runType,
        'run_date' => $runDate,
    ]);
    $runId = (int) $idStatement->fetchColumn();

    openclaw_audit($pdo, $actor, $runType, 'low', 'triggered', ['run_id' => $runId, 'run_date' => $runDate]);

    return $runId;
}
