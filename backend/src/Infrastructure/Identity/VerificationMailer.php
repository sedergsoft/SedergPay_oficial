<?php
declare(strict_types=1);
namespace app\Infrastructure\Identity;
use RuntimeException;
final class VerificationMailer
{
private const LOGO_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAALQAAACKCAIAAACM3/e4AAAAAXNSR0IB2cksfwAAAAlwSFlzAAALEwAACxMBAJqcGAAAjv5JREFUeJyEfQecXUW9/5Rzzu13+256JQmpJAECCAIiHQUFpCqC2EB9+lf5q/hUfMVn4+mzI6KCoIACIoj0gPTQkhASEtKTTXazfW8/ZeY//cy5u7z//Ww2d0+ZMzO/9v2VmeMEEQWUAgCB+lD+l/7Of0F5Cqp/7CAVJ6A+Ss0Jc0ncBEjebD9I3xIfMm3b98mbeS+S/Yy7Zn8oiHsEgd2cPDKha+a0OqcvsLsmf9u3UgiSz4bJ8VHVN2j1Euq+6EvMtIhZ5B9AxGMITZBB9c+0rPtrDxtaUywvpvFIzHPNVKqx6rutbqkj/Lwjm4CQqs7qUSky2XQDMDE4M1+wqWFFSetGEN9rBmc3ZMYB7Um3qWQYj0KrNTlA1nk1UgjlBJjZb6IgjduEMXPCmKGhZBIQz4Zmf6D/1j2n9mP0leIH0vi8ZDeo2BTGc6tnSgydGvZBQFJCThMVTKgfINkXWhIjHhbLZMxB9pgtJlMDZf0R3GONzJJK8UTRtGNaiBlfPQ3qeaExw8T9iEkOEydochKtfpvb6CTj0ISAk97YREc5mxanqoHCxI00MU9UjZomxFXQRo/Pun4Ci8IJDcLEF3O/EYLmp1ttKdk1o6OUEAqRYG0kTlOpSGBS1WmGSeiexGzTpueCWCMmu5NU0PaVMJ5bx3CelkKoGVJKrGRkamkxJSNS0JSKo01PgVT3QTOZ1g6GErSZjRKzrXWPJRdGg1hqFcbq0aanmH1bHGIlasahWpYzQuMBJkwXtNXeZBNqM7m+CBpWS8hR3EP5NGhZMYQhJWL2acxAyraIyZTTpdW8mSilLanmG6v372CVJucMqJjMkhFuVgwZEm3aQvQOht1i/iT7QouqcrJo0kKKudGqN9GoNZkTbUJC9dj8pR5q2Ss1iQl+U7ygWDIhjs0DjC1gQu1BJSmTffSlsQBQxVOSnDBxlW5HEBVxPiiPwHybAB1E9lPNKjAka9apWmFZnZ3wrUk+m2cjntIJzCKZQ0hO09X2E6lBMVRxuiXr2lonbDy1Jl+YN5tXVDtJ1KdPxUekFqcwBnOUJtCGdat5LGzSInTCNbEtmsjwRi4tQBDPgurVJLeZm4FUVwmKJm4yIFHrI0AJQAg0hvr3fuXr835xE/IwoQTGE5GY+bjzsZ4zRyaaE3028XQ9TqPwaWJc4lHxBDt6EiCN5wTGV7zjbCTO2FybEK7JbtdoOeYym1SKu5KEaDKuCeUHLU4W9q7pcZZrBZQafgfxh8knxDf/L58YcCQ0bPw9qTHlf6JDiJ1BSJwerQw+8ODs75RxdwtwXE47gVJlx2ksDhZ8aOKGBO5JiLrW0E2dU4rLGvIkEM0Rs0ulGpOCl4S3xkzG00a1pU7KFJR6JlZEyhjGOgNYnGexoGFbbTOsDki2gNACzNQoCApNu1oEqH2NltCYkaD1xNgu6tM0nmDzdD12qAdknzFGyVKdseMaT6K+mELpsLIfxL5Gle1bQWnIf+CRKSGt3fwz/8gjnCWLvVnzOX8QMREQGeADtCOm5oIm3FeJUZLevi1ittUFiXHC5IXWQcfGsvIOY0qApc5ijtSj11aVmpkG2gIBmzT//481HmONbL6UhhMmrpBdaCK17qNl+JIzlZg5rUWaqWhAbNyXRO+S7SshUUi/SZ02a0RxEfNMCGU6g4T+vttvqvz+Tz2VsBU75R98dyBdWPTY3xEAUUQs62aZBC3yNrpVbB73s0m2qBEPzet26CpxTxP7OFSRWd6jzJkRbjF4PXLQpLGE6MNmnpTtqBuVNbAI8I5BqPiMmfEEh1gnre9ab9pTF0uqzbRNWqr5iPluFDrV4Y4J7AESBIDxqIGaLvEFmv5pqVGMzn4IiSD0sov/7SeVQnb0f35...';

public function send(string $email, string $firstName, string $token, int $ttlMinutes = 30): bool
{
    $transport = getenv('SEDERG_MAIL_TRANSPORT') ?: 'smtp';
    if ($transport === 'log' || $transport === 'file') {
        $this->logVerification($email, $token, $ttlMinutes);
        return true;
    }
    if ($transport !== 'smtp') {
        throw new RuntimeException('Only SMTP email transport is allowed');
    }
    $host = trim((string) getenv('SEDERG_MAIL_HOST'));
    $port = (int) (getenv('SEDERG_MAIL_PORT') ?: 587);
    $username = trim((string) getenv('SEDERG_MAIL_USERNAME'));
    $password = (string) getenv('SEDERG_MAIL_PASSWORD');
    $from = trim((string) getenv('SEDERG_MAIL_FROM'));
    $encryption = strtolower(trim((string) (getenv('SEDERG_MAIL_ENCRYPTION') ?: 'tls')));

    $configIncomplete = $host === '' || $username === '' || $password === ''
        || $from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)
        || $port < 1 || $port > 65535
        || !in_array($encryption, ['tls', 'ssl'], true);
    if ($configIncomplete) {
        if ($this->isDevelopmentMode()) {
            $this->logVerification($email, $token, $ttlMinutes);
            return true;
        }
        throw new RuntimeException('SMTP email configuration is incomplete');
    }

    $appUrl = rtrim((string) (getenv('SEDERG_APP_URL') ?: 'http://localhost:5173'), '/');
    $appUrlParts = parse_url($appUrl);
    $appUrlScheme = is_array($appUrlParts) ? ($appUrlParts['scheme'] ?? '') : '';
    $appUrlHost = is_array($appUrlParts) ? ($appUrlParts['host'] ?? '') : '';
    $isLocalHttp = in_array($appUrlHost, ['localhost', '127.0.0.1'], true) && $appUrlScheme === 'http';
    if (!filter_var($appUrl, FILTER_VALIDATE_URL)
        || !in_array($appUrlScheme, ['http', 'https'], true)
        || (!$isLocalHttp && $appUrlScheme !== 'https')) {
        throw new RuntimeException('SEDERG_APP_URL is invalid');
    }
    $verificationUrl = $appUrl . '/?verify_email=' . rawurlencode($email) . '&token=' . rawurlencode($token);
    $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = htmlspecialchars($verificationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $logoSrc = $this->logoCid();
    $ttlMinutes = max(1, $ttlMinutes);
    $ttlText = "{$ttlMinutes} minutos";
    $subject = 'Activa tu cuenta de SEDERG_PAY';
    $body = "Hola {$firstName},\n\n" . "Activa tu cuenta haciendo clic en este enlace:\n{$verificationUrl}\n\n" . "El enlace vence en {$ttlText} y solo puede utilizarse una vez.\n" . "Si no solicitaste esta cuenta, ignora este mensaje.";
    $html = <<<HTML
<!doctype html>
<html lang="es">
<body style="margin:0;background:#f8fafc;font-family:'Inter',Arial,Helvetica,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:48px 24px;background:#f8fafc;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#ffffff;border-radius:30px;overflow:hidden;box-shadow:0 32px 96px rgba(10,43,107,.15);">
        <tr><td style="padding:48px 48px 36px;background:linear-gradient(135deg:#0a2b6c 0%,#1d4ed8 48%,#06b6d4 100%);border-radius:24px 24px 0 0;text-align:center;">
          <img src="{$logoSrc}" alt="SEDERG_PAY" style="height:56;width:auto;display:block;margin:0 auto 24px;filter:drop-shadow(0 8px 24px rgba(0,0,0,.3));border-radius:8px;" />
          <p style="margin:0;color:rgba(255,255,255,.9);font-size:14px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;">SEDERG_PAY</p>
        </td></tr>
        <tr><td style="padding:36px 48px 48px;">
          <p style="margin:0 0 16px;color:#0f172a;font-size:14px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;">Acceso seguro</p>
          <h1 style="margin:0 0 24px;font-size:36px;line-height:1.2;color:#1e293b;">Activa tu cuenta</h1>
          <p style="margin:0 0 28px;font-size:18px;line-height:1.6;color:#475569;">Hola {$safeFirstName}. Ya casi puedes empezar a gestionar tus remesas de forma clara, rápida y trazable.</p>
          <p style="margin:0 0 32px;font-size:15px;line-height:1.6;color:#475569;">Hemos preparado esta activación para:</p>
          <p style="margin:0 0 32px;font-size:16px;font-weight:600;color:#1e293b;">{$safeEmail}</p>
          <table role="presentation" cellspacing="0" cellpadding="0"><tr><td style="margin:0 auto;border-radius:16px;background:linear-gradient(135deg:#1d4ed8,#06b6d4);box-shadow:0 12px 32px rgba(11,79,156,.35);width:fit-content;">
            <a href="{$safeUrl}" style="display:block;padding:18px 38px;border-radius:16px;color:#ffffff;text-decoration:none;font-size:16px;font-weight:700;letter-spacing:.05em;">Activar mi cuenta</a>
          </td></tr></table>
          <p style="margin:0 0 32px;font-size:14px;line-height:1.6;color:#94a3b8;">Este enlace vence en {$ttlText} y solo puede utilizarse una vez. No tendrás que volver a introducir tu contraseña para activar la cuenta.</p>
          <p style="margin:0;padding-top:28px;border-top:1px solid #e2e8f0;font-size:12px;line-height:1.6;color:#94a3b8;">Si el botón no funciona, copia y pega este enlace en tu navegador:<br><span style="word-break:break-all;color:#06b6dc;">{$safeUrl}</span></p>
        </td></tr>
        <tr><td style="padding:24px 48px;background:#f1f5f9;color:#94a3b8;font-size:12px;line-height:1.5;">
 SEDERG_PAY &nbsp; Remesas Rusia &nbsp; Cuba<br>Si no solicitaste esta cuenta, puedes ignorar este mensaje.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    $this->sendMessage($email, $subject, $body, $html);
    return false;
}

public function sendRemittanceReceipt(string $email, string $firstName, array $remittance): void
{
    $esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $dash = static fn(mixed $v): string => ($v === null || $v === '') ? '—' : (string) $v;

    $direction = ($remittance['direction'] ?? '') === 'cu_to_ru' ? 'Cuba hacia Rusia' : 'Rusia hacia Cuba';
    $bFirst = trim((string) ($remittance['beneficiary_first_name'] ?? ''));
    $bLast = trim((string) ($remittance['beneficiary_last_name'] ?? ''));
    $beneficiary = trim($bFirst . ' ' . $bLast);
    if ($beneficiary === '') {
        $beneficiary = '—';
    }
    $account = !empty($remittance['payout_account_last4']) ? 'Cuenta ****' . $remittance['payout_account_last4'] : (!empty($remittance['payout_card_last4']) ? 'Tarjeta ****' . $remittance['payout_card_last4'] : '—');
    $rate = $dash($remittance['exchange_rate']) . ' ' . $dash($remittance['receive_currency']) . ' por ' . $dash($remittance['send_currency']);

    $rows = [
        'Referencia' => (string) $remittance['reference'],
            'Dirección' => $direction,
            'Enviar' => $dash($remittance['send_amount']) . ' ' . $dash($remittance['send_currency']),
            'Recibir' => $dash($remittance['receive_amount']) . ' ' . $dash($remittance['receive_currency']),
            'Comisión' => $dash($remittance['fee_amount']) . ' ' . $dash($remittance['fee_currency']),
            'Total a transferir' => $dash($remittance['total_debit_amount']) . ' ' . $dash($remittance['send_currency']),
            'Tasa' => $rate,
            'Estado' => $dash($remittance['status']),
            'Beneficiario' => $beneficiary,
            'Método de pago' => $dash($remittance['payout_method_code']) . (!empty($remittance['payout_provider']) ? ' · ' . $dash($remittance['payout_provider']) : ''),
            'Cuenta/tarjeta' => $account,
            'Creada' => $dash($remittance['created_at']),
      ];

     $subject = 'Comprobante de remesa ' . $remittance['reference'];
        $lines = ["Hola {$firstName}", '', 'Tu remesa fue creada:', ''];
        foreach ($rows as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }
        $lines[] = '';
        $lines[] = 'Información incluida en el QR: ' . $dash($remittance['payment_qr_payload'] ?? '');
        $body = implode("\n", $lines);

    $rowsHtml = '';
    foreach ($rows as $label => $value) {
        $rowsHtml .= '<tr><td style="padding:5px 0;font-size:13px;color:#8a94a9;">' . $esc($label) . '</td>' . '<td style="padding:5px 0 5px 14px;font-size:13px;color:#17213d;text-align:right;">' . $esc($value) . '</td></tr>';
    }

    $safeFirstName = $esc($firstName);
    $safeReference = $esc((string) $remittance['reference']);
    $safeQrPayload = $esc((string) ($remittance['payment_qr_payload'] ?? ''));
    $logoSrc = $this->logoCid();
    $html = <<<HTML
<!doctype html><html lang="es"><body style="margin:0;background:#eef3fa;color:#0f1f3d;font-family:'Inter',Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:36px 12px;background:#eef3fa;"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:24px;overflow:hidden;border:1px solid #dde6f2;box-shadow:0 24px 60px rgba(15,31,61,.12);">
<tr><td style="padding:26px 40px;background:linear-gradient(135deg:#0a2b6b 0%,#0b4f9c 52%,#00aeef 100%);">
  <img src="{$logoSrc}" alt="SEDERG_PAY" style="height:42px;width:auto;display:block;filter:drop-shadow(0 4px 12px rgba(0,0,0,.25));" />
  <p style="margin:10px 0 0;color:rgba(255,255,255,.82);font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;">Comprobante de operaci�n</p>
</td></tr>
<tr><td style="padding:34px 40px;">
<p style="margin:0 0 10px;color:#0b4f9c;font-size:12px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;">Comprobante de operaci�n</p>
<h1 style="margin:0 0 16px;font-size:26px;color:#10264d;">Remesa {$safeReference}</h1>
<p style="margin:0 0 22px;font-size:15px;color:#42536b;">Hola {$safeFirstName}, guardamos los datos de tu operaci�n.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #edf0f6;">{$rowsHtml}</table>
<p style="margin:20px 0 0;padding:16px;background:#f4f7fc;border-radius:12px;word-break:break-all;font-size:12px;color:#71809d;"><strong style="color:#10264d;">Datos incluidos en el QR:</strong><br>{$safeQrPayload}</p>
</td></tr>
<tr><td style="padding:20px 40px;background:#f4f7fc;color:#71809d;font-size:12px;border-top:1px solid #e1e7f3;">SEDERG_PAY � Conserva este comprobante para dar seguimiento a tu remesa.</td></tr>
</table></td></tr></table></body></html>
HTML;
    $this->sendMessage($email, $subject, $body, $html);
}

private function getLogoImage(): ?array
{
    $path = __DIR__ . '/../../../web/assets/sederg-pay-logo.png';
    if (!is_file($path)) {
        return null;
    }
    $data = @file_get_contents($path);
    if ($data === false) {
        return null;
    }
    return [
        'cid' => 'logo',
        'data' => $data,
        'mime' => 'image/png',
        'base64' => 'data:image/png;base64,' . base64_encode($data),
    ];
}

/**
 * Get CID for logo image attachment
 *
 * @return string CID reference (e.g., 'cid:logo')
 */
private function logoCid(): string
{
    $logo = $this->getLogoImage();
    if ($logo !== null) {
        return 'cid:' . $logo['cid'];
    }
    return 'data:image/png;base64,' . self::LOGO_BASE64;
}

private function sendMessage(string $email, string $subject, string $body, string $html): void
{
    $host = trim((string) getenv('SEDERG_MAIL_HOST'));
    $port = (int) (getenv('SEDERG_MAIL_PORT') ?: 587);
    $username = trim((string) getenv('SEDERG_MAIL_USERNAME'));
    $password = (string) getenv('SEDERG_MAIL_PASSWORD');
    $from = trim((string) getenv('SEDERG_MAIL_FROM'));
    $encryption = strtolower(trim((string) (getenv('SEDERG_MAIL_ENCRYPTION') ?: 'tls')));
    $remote = $encryption === 'ssl' ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
    $socket = @stream_socket_client($remote, $errorCode, $errorMessage, 20, STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) {
        throw new RuntimeException("SMTP connection failed: {$errorMessage} ({$errorCode})");
    }
    stream_set_timeout($socket, 20);
    try {
        $this->expect($socket, 220);
        $this->command($socket, 'EHLO sederg-pay.local', 250);
        if ($encryption === 'tls') {
            $this->command($socket, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP TLS negotiation failed');
            }
            $this->command($socket, 'EHLO sederg-pay.local', 250);
        }
        $this->command($socket, 'AUTH LOGIN', 334);
        $this->command($socket, base64_encode($username), 334);
        $this->command($socket, base64_encode($password), 235);
        $this->command($socket, "MAIL FROM:<{$from}>", 250);
        $this->command($socket, "RCPT TO:<{$email}>", 250);
        $this->command($socket, 'DATA', 354);
        $boundary = '=_sederg_' . bin2hex(random_bytes(12));
        $headers = ["From: SEDERG_PAY <{$from}>", "To: {$email}", "Subject: {$subject}", 'Date: ' . gmdate('r'), 'Message-ID: <' . bin2hex(random_bytes(16)) . '@sederg-pay.local>', 'MIME-Version: 1.0', "Content-Type: multipart/alternative; boundary=\"{$boundary}\""];
        $logo = $this->getLogoImage();
        $attachmentPart = '';
        if ($logo !== null) {
            $attachmentPart = "--{$boundary}\r\n" .
                "Content-Type: {$logo['mime']}; name=\"logo.png\"\r\n" .
                "Content-Transfer-Encoding: base64\r\n" .
                "Content-ID: <{$logo['cid']}>\r\n" .
                "Content-Disposition: inline; filename=\"logo.png\"\r\n" .
                "\r\n" . base64_encode($logo['data']) . "\r\n";
        }
        $message = implode("\r\n", $headers) . "\r\n\r\n" .
            "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$body}\r\n\r\n" .
            "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n\r\n" .
            $attachmentPart .
            "--{$boundary}--";
        $this->write($socket, $this->dotStuff($message) . "\r\n.");
        $this->expect($socket, 250);
        $this->command($socket, 'QUIT', 221);
    } finally {
        fclose($socket);
    }
}

private function isDevelopmentMode(): bool
{
    return getenv('SEDERG_AUTH_MODE') === 'development' || getenv('YII_ENV') === 'dev';
}

private function logVerification(string $email, string $token, int $ttlMinutes): void
{
    $appUrl = rtrim((string) (getenv('SEDERG_APP_URL') ?: 'http://localhost:5173'), '/');
    $verificationUrl = $appUrl . '/?verify_email=' . rawurlencode($email) . '&token=' . rawurlencode($token);
    $ttlMinutes = max(1, $ttlMinutes);
    $message = "[SEDERG_PAY][DEV][verification] email={$email} ttl_minutes={$ttlMinutes} url={$verificationUrl}";
    error_log($message);
    $logDir = dirname(__DIR__, 3) . '/runtime/logs';
    if (is_dir($logDir) && is_writable($logDir)) {
        @file_put_contents($logDir . '/verification-dev.log', '[' . gmdate('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}

private function command($socket, string $command, int $expectedCode): void
{
    $this->write($socket, $command);
    $this->expect($socket, $expectedCode);
}

private function write($socket, string $value): void
{
    if (fwrite($socket, $value . "\r\n") === false) {
        throw new RuntimeException('SMTP write failed');
    }
}

private function expect($socket, int $expectedCode): string
{
    $response = '';
    while (($line = fgets($socket)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    if (!preg_match('/^' . $expectedCode . '\b/m', $response)) {
        throw new RuntimeException("SMTP server rejected command: {$response}");
    }
    return $response;
}

private function dotStuff(string $message): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $message);
    $stuffed = preg_replace('/^\./m', '..', $normalized);
    if (!is_string($stuffed)) {
        throw new RuntimeException('SMTP message encoding failed');
    }
    return str_replace("\n", "\r\n", $stuffed);
}
}