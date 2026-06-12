<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/openclaw_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Allow: GET, POST, OPTIONS');
    http_response_code(204);
    exit;
}

$pdo = openclaw_pdo();
$action = (string) ($_GET['action'] ?? '');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'status') {
        $actor = openclaw_machine_actor($pdo);
        openclaw_require_low_risk_machine_action($pdo, $actor, 'openclaw.status');

        $jobs = $pdo->query(
            "SELECT status, COUNT(*) AS count FROM openclaw_agent_jobs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) GROUP BY status"
        )->fetchAll();

        $runs = $pdo->query(
            "SELECT run_type, run_date, status, updated_at FROM openclaw_daily_runs WHERE run_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY run_date DESC, run_type ASC"
        )->fetchAll();

        openclaw_json_response([
            'ok' => true,
            'actor' => [
                'type' => $actor['type'],
                'id' => $actor['id'],
                'name' => $actor['name'],
            ],
            'jobs_24h' => $jobs,
            'daily_runs' => $runs,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        openclaw_json_response([
            'ok' => false,
            'error' => 'method_not_allowed',
        ], 405);
    }

    $payload = openclaw_request_json();
    $actor = openclaw_machine_actor($pdo);

    if ($action === 'dispatch') {
        $jobAction = trim((string) ($payload['action'] ?? ''));
        if ($jobAction === '') {
            openclaw_json_response([
                'ok' => false,
                'error' => 'action_required',
            ], 400);
        }

        openclaw_require_low_risk_machine_action($pdo, $actor, $jobAction);
        $jobId = openclaw_queue_job($pdo, $actor, $jobAction, (array) ($payload['payload'] ?? []));

        openclaw_json_response([
            'ok' => true,
            'job_id' => $jobId,
            'status' => 'queued',
        ], 202);
    }

    if ($action === 'approval-reminders') {
        openclaw_require_low_risk_machine_action($pdo, $actor, 'openclaw.approval_reminders');
        $runId = openclaw_trigger_daily_run($pdo, $actor, 'approval_reminder', $payload);
        $jobId = openclaw_queue_job($pdo, $actor, 'openclaw.approval_reminders', [
            'daily_run_id' => $runId,
            'payload' => $payload,
        ]);

        openclaw_json_response([
            'ok' => true,
            'run_id' => $runId,
            'job_id' => $jobId,
            'status' => 'queued',
        ], 202);
    }

    if ($action === 'decision-report') {
        openclaw_require_low_risk_machine_action($pdo, $actor, 'openclaw.decision_report');
        $runId = openclaw_trigger_daily_run($pdo, $actor, 'decision_report', $payload);
        $jobId = openclaw_queue_job($pdo, $actor, 'openclaw.decision_report', [
            'daily_run_id' => $runId,
            'payload' => $payload,
        ]);

        openclaw_json_response([
            'ok' => true,
            'run_id' => $runId,
            'job_id' => $jobId,
            'status' => 'queued',
        ], 202);
    }

    openclaw_json_response([
        'ok' => false,
        'error' => 'unknown_openclaw_action',
    ], 404);
} catch (Throwable $exception) {
    openclaw_json_response([
        'ok' => false,
        'error' => 'openclaw_request_failed',
    ], 500);
}
