<?php
// ================================================================
// shared/notify.php — Author notifications
//
// Emails the author of a resource when someone likes / forks /
// comments on it. Best-effort: never throws into the caller, and
// silently no-ops when there's no email / the author opted out /
// it's the author's own action / a duplicate within 24h.
// ================================================================

require_once __DIR__ . '/mailer.php';

/**
 * Notify the author of a resource about activity on it.
 *
 * @param string $type   'like' | 'fork' | 'comment'
 * @param array  $extra  ['body' => string]  (for comments)
 */
function notifyResourceAuthor(
    PDO $db,
    int $resourceId,
    int $actorUserId,
    string $actorName,
    string $type,
    array $extra = []
): void {
    try {
        // Only Google-authored community resources have a local user record + email.
        $stmt = $db->prepare("
            SELECT r.title, r.author_user_id,
                   u.email, u.email_notifications, u.unsubscribe_token
            FROM resources r
            JOIN users u ON u.id = r.author_user_id
            WHERE r.id = ? AND r.author_tenant_id = 0 AND r.is_active = 1
        ");
        $stmt->execute([$resourceId]);
        $row = $stmt->fetch();

        if (!$row) return;                                            // no local author
        $authorId = (int) $row['author_user_id'];
        if ($authorId === $actorUserId) return;                      // no self-notify
        if (!(int) $row['email_notifications']) return;              // opted out
        if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) return;

        // Dedup: one email per (recipient, actor, resource, type) per 24h.
        $dedup = $db->prepare("
            SELECT 1 FROM notification_log
            WHERE recipient_user_id = ? AND actor_user_id = ? AND resource_id = ? AND type = ?
              AND created_at > NOW() - INTERVAL 1 DAY
            LIMIT 1
        ");
        $dedup->execute([$authorId, $actorUserId, $resourceId, $type]);
        if ($dedup->fetch()) return;

        // Ensure the author has an unsubscribe token.
        $token = $row['unsubscribe_token'];
        if (!$token) {
            $token = bin2hex(random_bytes(16));
            $db->prepare("UPDATE users SET unsubscribe_token = ? WHERE id = ?")
               ->execute([$token, $authorId]);
        }

        $base     = 'https://iarepo.com';
        $resUrl   = $base . '/resource/' . $resourceId;
        $unsubUrl = $base . '/unsubscribe.php?token=' . $token;

        $title = htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8');
        $actor = htmlspecialchars($actorName, ENT_QUOTES, 'UTF-8');

        $map = [
            'like'    => ['❤️ Le dio like a tu recurso',  "<strong>{$actor}</strong> le dio like a tu recurso:"],
            'fork'    => ['⑂ Hicieron un fork de tu recurso', "<strong>{$actor}</strong> hizo un fork de tu recurso:"],
            'comment' => ['💬 Nuevo comentario en tu recurso', "<strong>{$actor}</strong> comentó en tu recurso:"],
        ];
        [$subjPrefix, $line] = $map[$type] ?? ['Actividad en tu recurso', "{$actor} interactuó con tu recurso:"];
        $subject = $subjPrefix . ' — ' . $row['title'];

        $quote = '';
        if ($type === 'comment' && !empty($extra['body'])) {
            $body  = htmlspecialchars(mb_substr((string) $extra['body'], 0, 400), ENT_QUOTES, 'UTF-8');
            $quote = '<blockquote style="border-left:3px solid #7c3aed;margin:14px 0;padding:8px 14px;color:#475569;background:#f8fafc;border-radius:4px">'
                   . nl2br($body) . '</blockquote>';
        }

        $inner = '<p style="font-size:.95rem;color:#1e293b;margin:0 0 6px">' . $line . '</p>'
               . '<p style="font-size:1.05rem;font-weight:700;color:#0f172a;margin:0 0 4px">' . $title . '</p>'
               . $quote
               . '<p style="margin:20px 0 8px"><a href="' . $resUrl . '" style="display:inline-block;background:linear-gradient(135deg,#7c3aed,#06b6d4);color:#fff;text-decoration:none;padding:10px 22px;border-radius:8px;font-weight:600;font-size:.9rem">Ver el recurso →</a></p>'
               . '<p style="margin-top:24px;font-size:.72rem;color:#94a3b8">Recibes esto porque alguien interactuó con tu recurso en iarepo. '
               . '<a href="' . $unsubUrl . '" style="color:#94a3b8">Dejar de recibir estos correos</a>.</p>';

        $sent = sendMail($row['email'], $subject, emailShell($inner), [
            'list_unsubscribe' => $unsubUrl,
        ]);

        if ($sent) {
            $db->prepare("
                INSERT INTO notification_log (recipient_user_id, actor_user_id, resource_id, type)
                VALUES (?, ?, ?, ?)
            ")->execute([$authorId, $actorUserId, $resourceId, $type]);
        }
    } catch (\Throwable $e) {
        // Notifications must never break the underlying action.
        if (function_exists('api_log')) {
            api_log('error', 'notifyResourceAuthor failed', ['error' => $e->getMessage(), 'resource' => $resourceId]);
        }
    }
}
