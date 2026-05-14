#!/usr/bin/env php
<?php

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/mailer.php';

$logFile = __DIR__ . '/../storage/logs/worker.log';

function worker_log(string $message): void
{
    global $logFile;
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

function worker_heartbeat(string $state = 'idle', ?int $queueId = null, ?string $email = null): void
{
    try {
        $stmt = db()->prepare(
            "replace into worker_status (name, pid, current_queue_id, current_email, state, heartbeat_at)
             values ('default', ?, ?, ?, ?, now())"
        );
        $stmt->execute([getmypid() ?: null, $queueId, $email, $state]);
    } catch (Throwable $error) {
        worker_log('heartbeat error: ' . $error->getMessage());
    }
}

function heartbeat_sleep(int $seconds, string $state = 'waiting', ?int $queueId = null, ?string $email = null): void
{
    $remaining = max(1, $seconds);
    while ($remaining > 0) {
        worker_heartbeat($state, $queueId, $email);
        $chunk = min(5, $remaining);
        sleep($chunk);
        $remaining -= $chunk;
    }
}

worker_log('worker started');
worker_heartbeat('started');

while (true) {
    try {
        $pdo = db();
        worker_heartbeat('polling');
        $pdo->beginTransaction();

        $item = $pdo->query(
            "select q.*, c.name as contact_name, c.company, c.vars_json,
                    ca.name as campaign_name, ca.interval_seconds, ca.status as campaign_status,
                    t.subject, t.html_body, t.text_body
             from send_queue q
             join contacts c on c.id = q.contact_id
             join campaigns ca on ca.id = q.campaign_id
             join templates t on t.id = ca.template_id
             where q.status = 'queued' and ca.status in ('queued','sending')
             order by q.id
             limit 1
             for update"
        )->fetch();

        if (!$item) {
            $pdo->commit();
            heartbeat_sleep(5, 'idle');
            continue;
        }

        $suppressed = $pdo->prepare('select email from suppressions where email = ? limit 1');
        $suppressed->execute([$item['email']]);
        if ($suppressed->fetch()) {
            $stmt = $pdo->prepare("update send_queue set status = 'skipped', error = 'suppressed' where id = ?");
            $stmt->execute([$item['id']]);
            $pdo->commit();
            worker_heartbeat('skipped', (int) $item['id'], $item['email']);
            continue;
        }

        $pdo->prepare("update campaigns set status = 'sending', started_at = coalesce(started_at, now()) where id = ?")
            ->execute([$item['campaign_id']]);
        $pdo->prepare("update send_queue set status = 'processing', locked_at = now(), error = null where id = ?")
            ->execute([$item['id']]);

        $pdo->commit();
        worker_heartbeat('sending', (int) $item['id'], $item['email']);

        $vars = json_decode($item['vars_json'] ?: '{}', true) ?: [];
        $vars = array_merge($vars, [
            'email' => $item['email'],
            'name' => $item['contact_name'] ?: explode('@', $item['email'])[0],
            'company' => $item['company'] ?: '',
            'sender_name' => config()['smtp']['from_name'],
            'unsubscribe_url' => unsubscribe_url($item['email']),
        ]);

        $subject = render_template($item['subject'], $vars);
        $htmlBody = render_template($item['html_body'], $vars, true);
        $textBody = render_template($item['text_body'], $vars);

        try {
            send_smtp_mail($item['email'], $subject, $htmlBody, $textBody);
            $pdo->prepare("update send_queue set status = 'sent', sent_at = now(), error = null where id = ?")
                ->execute([$item['id']]);
            worker_heartbeat('sent', (int) $item['id'], $item['email']);
            worker_log("sent queue_id={$item['id']} email={$item['email']}");
        } catch (Throwable $mailError) {
            $pdo->prepare("update send_queue set status = 'failed', error = ? where id = ?")
                ->execute([substr($mailError->getMessage(), 0, 1000), $item['id']]);
            worker_heartbeat('failed', (int) $item['id'], $item['email']);
            worker_log("failed queue_id={$item['id']} email={$item['email']} error=" . $mailError->getMessage());
        }

        $left = $pdo->prepare("select count(*) from send_queue where campaign_id = ? and status in ('queued','processing')");
        $left->execute([$item['campaign_id']]);
        if ((int) $left->fetchColumn() === 0) {
            $pdo->prepare("update campaigns set status = 'done', finished_at = now() where id = ? and status = 'sending'")
                ->execute([$item['campaign_id']]);
        }

        heartbeat_sleep(max(1, (int) $item['interval_seconds']), 'waiting', (int) $item['id'], $item['email']);
    } catch (Throwable $error) {
        worker_log('worker error: ' . $error->getMessage());
        heartbeat_sleep(10, 'error');
    }
}
