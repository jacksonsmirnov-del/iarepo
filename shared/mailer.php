<?php
// ================================================================
// shared/mailer.php — Minimal transactional mailer
//
// Sends HTML email via PHP mail() (routed through Hostinger's
// hsendmail). Kept behind a single function so the transport can
// be swapped for an API provider (Resend/Mailgun) later without
// touching callers.
// ================================================================

/**
 * Send an HTML email. Returns true if the MTA accepted it.
 *
 * @param array $opts  from, reply_to, list_unsubscribe
 */
function sendMail(string $to, string $subject, string $htmlBody, array $opts = []): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    // From address — overridable via .env (MAIL_FROM), must be on a domain
    // with valid SPF/DKIM for good deliverability.
    $from    = $opts['from'] ?? mailFromDefault();
    $replyTo = $opts['reply_to'] ?? 'noreply@iarepo.com';

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from,
        'Reply-To: ' . $replyTo,
        'X-Mailer: iarepo',
    ];
    if (!empty($opts['list_unsubscribe'])) {
        $headers[] = 'List-Unsubscribe: <' . $opts['list_unsubscribe'] . '>';
        $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
    }

    // RFC 2047 encode the subject so accents/emoji survive
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    return @mail($to, $encodedSubject, $htmlBody, implode("\r\n", $headers));
}

/** Default From, read once from .env.php if present. */
function mailFromDefault(): string
{
    static $from = null;
    if ($from === null) {
        $env  = @require dirname(__DIR__) . '/.env.php';
        $from = (is_array($env) && !empty($env['MAIL_FROM']))
            ? $env['MAIL_FROM']
            : 'iarepo <noreply@iarepo.com>';
    }
    return $from;
}

/**
 * Wrap body content in a simple branded HTML shell.
 */
function emailShell(string $innerHtml): string
{
    return '<!DOCTYPE html><html><body style="margin:0;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 0"><tr><td align="center">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)">'
        . '<tr><td style="background:linear-gradient(135deg,#7c3aed,#06b6d4);padding:16px 24px">'
        . '<img src="https://iarepo.com/apple-touch-icon.png" width="30" height="30" alt="iarepo" style="display:inline-block;vertical-align:middle;border-radius:8px;border:0">'
        . '<span style="color:#fff;font-size:1.15rem;font-weight:700;letter-spacing:.5px;vertical-align:middle;margin-left:10px">iarepo</span></td></tr>'
        . '<tr><td style="padding:24px">' . $innerHtml . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}
