<?php
/**
 * outbox.php — approval queue for automated outbound email.
 *
 * Architecture:
 *   - Operator-initiated emails (OTP, manual broadcasts, diag tests):
 *     keep calling ses_send() directly. Bypass outbox.
 *   - Automation-triggered emails (form submissions, webhooks, cron):
 *     call outbox_queue() instead. The draft sits in `outbox` until
 *     the operator approves it in dashboard/outbox.php.
 *
 * outbox_queue() returns ['ok' => true, 'outbox_id' => N, 'status' => 'pending'].
 * outbox_send() (called from dashboard) flips the row to 'sent' and
 * actually invokes ses_send().
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ses.php';

/**
 * Queue an email draft for operator review.
 *
 * @param string $to_email
 * @param string $subject
 * @param string $html_body
 * @param array  $opts  Recognised keys:
 *   - kind           (string, required) e.g. submission_ack
 *   - funnel         (string)           sponsor|investor|dj|idol|vendor|cosplay|guild|donation
 *   - to_name        (string)
 *   - reply_to       (string)
 *   - from_email     (string)
 *   - from_name      (string)
 *   - source_table   (string)           e.g. submissions
 *   - source_id      (int)              submissions.id
 *   - actor          (string)           default 'system'
 *   - note           (string)           audit note
 *
 * @return array{ok:bool, outbox_id?:int, status?:string, error?:string}
 */
function outbox_queue(string $to_email, string $subject, string $html_body, array $opts = []): array {
    $kind = trim((string)($opts['kind'] ?? ''));
    if ($kind === '') {
        return ['ok' => false, 'error' => 'outbox_queue: kind is required'];
    }
    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'outbox_queue: invalid to_email'];
    }
    if ($subject === '' || $html_body === '') {
        return ['ok' => false, 'error' => 'outbox_queue: subject and html_body required'];
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT INTO outbox
               (kind, funnel, to_email, to_name, subject, html_body,
                reply_to, from_email, from_name, source_table, source_id, status)
             VALUES
               (:kind, :funnel, :to_email, :to_name, :subject, :html_body,
                :reply_to, :from_email, :from_name, :source_table, :source_id, "pending")'
        );
        $stmt->execute([
            ':kind'         => $kind,
            ':funnel'       => $opts['funnel']       ?? null,
            ':to_email'     => $to_email,
            ':to_name'      => $opts['to_name']      ?? null,
            ':subject'      => $subject,
            ':html_body'    => $html_body,
            ':reply_to'     => $opts['reply_to']     ?? null,
            ':from_email'   => $opts['from_email']   ?? null,
            ':from_name'    => $opts['from_name']    ?? null,
            ':source_table' => $opts['source_table'] ?? null,
            ':source_id'    => $opts['source_id']    ?? null,
        ]);
        $id = (int)$pdo->lastInsertId();

        outbox_log_action($id, 'queued', $opts['actor'] ?? 'system', $opts['note'] ?? null);

        return ['ok' => true, 'outbox_id' => $id, 'status' => 'pending'];
    } catch (Throwable $e) {
        error_log('outbox_queue failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Approve and immediately send an outbox row.
 *
 * @return array{ok:bool, status?:string, ses?:array, error?:string}
 */
function outbox_send(int $outbox_id, string $actor = 'system'): array {
    try {
        $pdo = db();
        $row = $pdo->prepare('SELECT * FROM outbox WHERE id = :id LIMIT 1');
        $row->execute([':id' => $outbox_id]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return ['ok' => false, 'error' => 'outbox row not found'];
        }
        if ($r['status'] === 'sent') {
            return ['ok' => true, 'status' => 'sent', 'note' => 'already sent'];
        }
        if ($r['status'] === 'rejected') {
            return ['ok' => false, 'error' => 'cannot send a rejected row'];
        }

        // Mark approved first so a failure mid-send shows correctly.
        $upd = $pdo->prepare(
            'UPDATE outbox SET status="approved", reviewed_at=NOW(), reviewed_by=:by
             WHERE id=:id AND status IN ("pending","failed","approved")'
        );
        $upd->execute([':by' => $actor, ':id' => $outbox_id]);
        outbox_log_action($outbox_id, 'approved', $actor);

        $opts = [];
        if (!empty($r['to_name']))    $opts['to_name']    = $r['to_name'];
        if (!empty($r['reply_to']))   $opts['reply_to']   = $r['reply_to'];
        if (!empty($r['from_email'])) $opts['from_email'] = $r['from_email'];
        if (!empty($r['from_name']))  $opts['from_name']  = $r['from_name'];

        $res = ses_send($r['to_email'], $r['subject'], $r['html_body'], $opts);

        if (!empty($res['ok'])) {
            $pdo->prepare(
                'UPDATE outbox SET status="sent", sent_at=NOW(), ses_log_id=:slog, error_summary=NULL
                 WHERE id=:id'
            )->execute([
                ':slog' => $res['log_id'] ?? null,
                ':id'   => $outbox_id,
            ]);
            outbox_log_action($outbox_id, 'sent', $actor);
            return ['ok' => true, 'status' => 'sent', 'ses' => $res];
        } else {
            $err = substr((string)($res['error'] ?? 'unknown'), 0, 500);
            $pdo->prepare(
                'UPDATE outbox SET status="failed", error_summary=:err WHERE id=:id'
            )->execute([':err' => $err, ':id' => $outbox_id]);
            outbox_log_action($outbox_id, 'failed', $actor, $err);
            return ['ok' => false, 'status' => 'failed', 'error' => $err];
        }
    } catch (Throwable $e) {
        error_log('outbox_send failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Reject a pending row (won't be sent).
 */
function outbox_reject(int $outbox_id, string $actor = 'system', ?string $note = null): array {
    try {
        $pdo = db();
        $pdo->prepare(
            'UPDATE outbox SET status="rejected", reviewed_at=NOW(), reviewed_by=:by
             WHERE id=:id AND status="pending"'
        )->execute([':by' => $actor, ':id' => $outbox_id]);
        outbox_log_action($outbox_id, 'rejected', $actor, $note);
        return ['ok' => true, 'status' => 'rejected'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Edit a pending draft before approval (operator can adjust copy).
 */
function outbox_update_draft(int $outbox_id, array $fields, string $actor = 'system'): array {
    $allowed = ['subject', 'html_body', 'to_email', 'to_name', 'reply_to'];
    $set = [];
    $params = [':id' => $outbox_id];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $fields)) {
            $set[] = "$f = :$f";
            $params[":$f"] = $fields[$f];
        }
    }
    if (!$set) return ['ok' => false, 'error' => 'no editable fields supplied'];
    try {
        $pdo = db();
        $sql = 'UPDATE outbox SET ' . implode(', ', $set)
             . ' WHERE id=:id AND status IN ("pending","failed")';
        $pdo->prepare($sql)->execute($params);
        outbox_log_action($outbox_id, 'edited', $actor, 'updated: ' . implode(',', array_keys($fields)));
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function outbox_log_action(int $outbox_id, string $action, string $actor = 'system', ?string $note = null): void {
    try {
        db()->prepare(
            'INSERT INTO outbox_actions (outbox_id, action, actor, note)
             VALUES (:id, :action, :actor, :note)'
        )->execute([
            ':id'     => $outbox_id,
            ':action' => $action,
            ':actor'  => $actor,
            ':note'   => $note,
        ]);
    } catch (Throwable $e) {
        // Don't break the parent flow over a logging failure.
        error_log('outbox_log_action: ' . $e->getMessage());
    }
}

function outbox_count_pending(): int {
    try {
        $stmt = db()->query('SELECT COUNT(*) FROM outbox WHERE status="pending"');
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
