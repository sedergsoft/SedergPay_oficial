<?php

declare(strict_types=1);

namespace app\Infrastructure\Identity;

use RuntimeException;

final class VerificationMailer
{
    public function send(string $email, string $firstName, string $token): void
    {
        if ((getenv('SEDERG_MAIL_TRANSPORT') ?: 'smtp') !== 'smtp') {
            throw new RuntimeException('Only SMTP email transport is allowed');
        }
        $host = trim((string) getenv('SEDERG_MAIL_HOST'));
        $port = (int) (getenv('SEDERG_MAIL_PORT') ?: 587);
        $username = trim((string) getenv('SEDERG_MAIL_USERNAME'));
        $password = (string) getenv('SEDERG_MAIL_PASSWORD');
        $from = trim((string) getenv('SEDERG_MAIL_FROM'));
        $encryption = strtolower(trim((string) (getenv('SEDERG_MAIL_ENCRYPTION') ?: 'tls')));

        if ($host === '' || $username === '' || $password === ''
            || $from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)
            || $port < 1 || $port > 65535
            || !in_array($encryption, ['tls', 'ssl'], true)) {
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
        $verificationUrl = $appUrl . '/?verify_email=' . rawurlencode($email)
            . '&token=' . rawurlencode($token);
        $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEmail = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($verificationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = 'Activa tu cuenta de SEDERG_PAY';
        $body = "Hola {$firstName},\n\n"
            . "Activa tu cuenta haciendo clic en este enlace:\n{$verificationUrl}\n\n"
            . "El enlace vence en 30 minutos y solo puede utilizarse una vez.\n"
            . "Si no solicitaste esta cuenta, ignora este mensaje.";
        $html = <<<HTML
<!doctype html>
<html lang="es">
<body style="margin:0;background:#f4f7fc;color:#17213d;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:32px 12px;background:#f4f7fc;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:20px;overflow:hidden;border:1px solid #e1e7f3;">
        <tr><td style="padding:30px 34px;background:linear-gradient(120deg,#173b93,#245bd7 58%,#e23b45);">
          <div style="font-size:24px;font-weight:800;letter-spacing:-1px;color:#ffffff;">
            <span style="display:inline-block;width:36px;height:36px;line-height:36px;text-align:center;border-radius:11px;background:#ffffff;color:#245bd7;margin-right:8px;">S</span>
            SEDERG<span style="color:#ffd9dc;">_PAY</span>
          </div>
        </td></tr>
        <tr><td style="padding:38px 34px 30px;">
          <p style="margin:0 0 10px;color:#245bd7;font-size:12px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;">Acceso seguro</p>
          <h1 style="margin:0 0 16px;font-size:30px;line-height:1.15;color:#17213d;">Activa tu cuenta</h1>
          <p style="margin:0 0 22px;font-size:16px;line-height:1.6;color:#53617d;">Hola {$safeFirstName}. Ya casi puedes empezar a gestionar tus remesas de forma clara, rápida y trazable.</p>
          <p style="margin:0 0 12px;font-size:14px;color:#53617d;">Hemos preparado esta activación para:</p>
          <p style="margin:0 0 26px;font-size:15px;font-weight:700;color:#17213d;">{$safeEmail}</p>
          <table role="presentation" cellspacing="0" cellpadding="0"><tr><td style="border-radius:11px;background:#245bd7;">
            <a href="{$safeUrl}" style="display:inline-block;padding:14px 22px;border-radius:11px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;">Activar mi cuenta</a>
          </td></tr></table>
          <p style="margin:24px 0 0;font-size:13px;line-height:1.6;color:#71809d;">Este enlace vence en 30 minutos y solo puede utilizarse una vez. No tendrás que volver a introducir tu contraseña para activar la cuenta.</p>
          <p style="margin:22px 0 0;padding-top:20px;border-top:1px solid #edf0f6;font-size:12px;line-height:1.6;color:#8a94a9;">Si el botón no funciona, copia y pega este enlace en tu navegador:<br><span style="word-break:break-all;color:#245bd7;">{$safeUrl}</span></p>
        </td></tr>
        <tr><td style="padding:20px 34px;background:#f8faff;color:#71809d;font-size:12px;line-height:1.5;">SEDERG_PAY · Remesas Rusia ↔ Cuba<br>Si no solicitaste esta cuenta, puedes ignorar este mensaje.</td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
        $this->sendMessage($email, $subject, $body, $html);
    }

    public function sendRemittanceReceipt(string $email, string $firstName, array $remittance): void
    {
        $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeReference = htmlspecialchars((string) $remittance['reference'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSend = htmlspecialchars((string) $remittance['send_amount'] . ' ' . $remittance['send_currency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeReceive = htmlspecialchars((string) $remittance['receive_amount'] . ' ' . $remittance['receive_currency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeStatus = htmlspecialchars((string) $remittance['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeQrPayload = htmlspecialchars((string) ($remittance['payment_qr_payload'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $subject = 'Comprobante de remesa ' . $remittance['reference'];
        $body = "Hola {$firstName},\n\n"
            . "Tu remesa {$remittance['reference']} fue creada.\n"
            . "Enviar: {$remittance['send_amount']} {$remittance['send_currency']}\n"
            . "Recibir: {$remittance['receive_amount']} {$remittance['receive_currency']}\n"
            . "Estado: {$remittance['status']}\n"
            . "Referencia de pago: {$remittance['reference']}\n"
            . "Información QR: {$remittance['payment_qr_payload']}\n";
        $html = <<<HTML
<!doctype html><html lang="es"><body style="margin:0;background:#f4f7fc;color:#17213d;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:32px 12px;background:#f4f7fc;"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#fff;border-radius:20px;overflow:hidden;border:1px solid #e1e7f3;">
<tr><td style="padding:30px 34px;background:#0b4f9c;color:#fff;font-size:24px;font-weight:800;">SEDERG<span style="color:#00aeef;">_PAY</span></td></tr>
<tr><td style="padding:34px;"><p style="color:#0b4f9c;font-size:12px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;">Comprobante de operación</p>
<h1 style="color:#10264d;">Remesa creada</h1><p>Hola {$safeFirstName}, guardamos los datos de tu operación.</p>
<p><strong>Referencia:</strong> {$safeReference}<br><strong>Enviar:</strong> {$safeSend}<br><strong>Recibir:</strong> {$safeReceive}<br><strong>Estado:</strong> {$safeStatus}</p>
<p style="padding:16px;background:#f4f6f9;border-radius:10px;word-break:break-all;"><strong>Datos incluidos en el QR:</strong><br>{$safeQrPayload}</p>
</td></tr><tr><td style="padding:20px 34px;background:#f8faff;color:#71809d;font-size:12px;">SEDERG_PAY · Conserva este comprobante para dar seguimiento.</td></tr>
</table></td></tr></table></body></html>
HTML;
        $this->sendMessage($email, $subject, $body, $html);
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
            $message = implode("\r\n", $headers) . "\r\n\r\n"
                . "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$body}\r\n\r\n"
                . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n\r\n"
                . "--{$boundary}--";
            $this->write($socket, $this->dotStuff($message) . "\r\n.");
            $this->expect($socket, 250);
            $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
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
