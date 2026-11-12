<?php

declare(strict_types=1);

namespace app\Infrastructure\Identity;

use RuntimeException;

final class VerificationMailer
{
private const LOGO_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAALQAAACKCAIAAACM3/e4AAAAAXNSR0IB2cksfwAAAAlwSFlzAAALEwAACxMBAJqcGAAAjv5JREFUeJyEfQecXUW9/5Rzzu13+256JQmpJAECCAIiHQUFpCqC2EB9+lf5q/hUfMVn4+mzI6KCoIACIoj0gPTQkhASEtKTTXazfW8/ZeY//cy5u7z//Ww2d0+ZMzO/9v2VmeMEEQWUAgCB+lD+l/7Of0F5Cqp/7CAVJ6A+Ss0Jc0ncBEjebD9I3xIfMm3b98mbeS+S/Yy7Zn8oiHsEgd2cPDKha+a0OqcvsLsmf9u3UgiSz4bJ8VHVN2j1Euq+6EvMtIhZ5B9AxGMITZBB9c+0rPtrDxtaUywvpvFIzHPNVKqx6rutbqkj/Lwjm4CQqs7qUSky2XQDMDE4M1+wqWFFSetGEN9rBmc3ZMYB7Um3qWQYj0KrNTlA1nk1UgjlBJjZb6IgjduEMXPCmKGhZBIQz4Zmf6D/1j2n9mP0leIH0vi8ZDeo2BTGc6tnSgydGvZBQFJCThMVTKgfINkXWhIjHhbLZMxB9pgtJlMDZf0R3GONzJJK8UTRtGNaiBlfPQ3qeaExw8T9iEkOEydochKtfpvb6CTj0ISAk97YREc5mxanqoHCxI00MU9UjZomxFXQRo/Pun4Ci8IJDcLEF3O/EYLmp1ttKdk1o6OUEAqRYG0kTlOpSGBS1WmGSeiexGzTpueCWCMmu5NU0PaVMJ5bx3CelkKoGVJKrGRkamkxJSNS0JSKo01PgVT3QTOZ1g6GErSZjRKzrXWPJRdGg1hqFcbq0aanmH1bHGIlasahWpYzQuMBJkwXtNXeZBNqM7m+CBpWS8hR3EP5NGhZMYQhJWL2acxAyraIyZTTpdW8mSilLanmG6v372CVJucMqJjMkhFuVgwZEm3aQvQOht1i/iT7QouqcrJo0kKKudGqN9GoNZkTbUJC9dj8pR5q2Ss1iQl+U7ygWDIhjs0DjC1gQu1BJSmTffSlsQBQxVOSnDBxlW5HEBVxPiiPwHybAB1E9lPNKjAka9apWmFZnZ3wrUk+m2cjntIJzCKZQ0hO09X2E6lBMVRxuiXr2lonbDy1Jl+YN5tXVDtJ1KdPxUekFqcwBnOUJtCGdat5LGzSInTCNbEtmsjwRi4tQBDPgurVJLeZm4FUVwmKJm4yIFHrI0AJQAg0hvr3fuXr835xE/IwoQTGE5GY+bjzsZ4zRyaaE3028XQ9TqPwaWJc4lHxBDt6EiCN5wTGV7zjbCTO2FybEK7JbtdoOeYym1SKu5KEaDKuCeUHLU4W9q7pcZZrBZQafgfxh8knxDf/L58YcCQ0bPw9qTHlf6JDiJ1BSJwerQw+8ODs75RxdwtwXE47gVJlx2ksDhZ8aOKGBO5JiLrW0E2dU4rLGvIkEM0Rs0ulGpOCl4S3xkzG00a1pU7KFJR6JlZEyhjGOgNYnGexoGFbbTOsDki2gNACzNQoCApNu1oEqH2NltCYkaD1xNgu6tM0nmDzdD12qAdknzFGyVKdseMaT6K+mELpsLIfxL5Gle1bQWnIf+CRKSGt3fwz/8gjnCWLvVnzOX8QMREQGeADtCOm5oIm3FeJUZLevi1ittUFiXHC5IXWQcfGsvIOY0qApc5ijtSj11aVmpkG2gIBmzT//481HmONbL6UhhMmrpBdaCK17qNl+JIzlZg5rUWaqWhAbNyXRO+S7SshUUi/SZ02a0RxEfNMCGU6g4T+vttvqvz+Tz2VsBU75R98dyBdWPTY3xEAUUQs62aZBC3yNrpVbB73s0m2qBEPzet26CpxTxP7OFSRWd6jzJkRbjF4PXLQpLGE6MNmnpTtqBuVNbAI8I5BqPiMmfEEh1gnre9ab9pTF0uqzbRNWqr5iPluFDrV4Y4J7AESBIDxqIGaLvEFmv5pqVGMzn4IiSD0sov/7SeVQnb0f35...';

public function send(string $email, string $firstName, string $token, int $ttlMinutes = 30): bool
{
    $transport = $this->transport();
    if ($transport === 'log' || $transport === 'file') {
        $this->logVerification($email, $token, $ttlMinutes);
        return true;
    }
    if (!in_array($transport, ['smtp', 'api'], true)) {
        throw new RuntimeException('Only SMTP or API email transport is allowed');
    }

    $config = $this->mailConfig();
    if ($this->isIncomplete($transport, $config)) {
        if ($this->isDevelopmentMode()) {
            $this->logVerification($email, $token, $ttlMinutes);
            return true;
        }
        throw new RuntimeException($transport === 'api'
            ? 'MAIL API configuration is incomplete'
            : 'SMTP email configuration is incomplete');
    }

    $verificationUrl = $this->buildVerificationUrl($email, $token);
    $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = htmlspecialchars($verificationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $logoSrc = $this->logoSource();
    $ttlMinutes = max(1, $ttlMinutes);
    $ttlText = $ttlMinutes . ' minutos';
    $subject = 'Activa tu cuenta de SEDERG_PAY';
    $body = "Hola {$firstName},\n\n" . "Activa tu cuenta haciendo clic en este enlace:\n{$verificationUrl}\n\n" . "El enlace vence en {$ttlText} y solo puede utilizarse una vez.\n" . "Si no solicitaste esta cuenta, ignora este mensaje.";
    $html = <<<HTML
<!doctype html>
<html lang="es">
<body style="margin:0;background:#f8fafc;font-family:'Inter',Arial,Helvetica,sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:48px 24px;background:#f8fafc;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#ffffff;border-radius:30px;overflow:hidden;box-shadow:0 32px 96px rgba(10,43,107,.15);">
        <tr><td style="padding:48px 48px 36px;background:linear-gradient(135deg,#0a2b6c 0%,#1d4ed8 48%,#06b6d4 100%);border-radius:24px 24px 0 0;text-align:center;">
          <img src="{$logoSrc}" alt="SEDERG_PAY" style="height:56;width:auto;display:block;margin:0 auto 24px;filter:drop-shadow(0 8px 24px rgba(0,0,0,.3));border-radius:8px;" />
          <p style="margin:0;color:rgba(255,255,255,.9);font-size:14px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;">SEDERG_PAY</p>
        </td></tr>
        <tr><td style="padding:36px 48px 48px;">
          <p style="margin:0 0 16px;color:#0f172a;font-size:14px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;">Acceso seguro</p>
          <h1 style="margin:0 0 24px;font-size:36px;line-height:1.2;color:#1e293b;">Activa tu cuenta</h1>
          <p style="margin:0 0 28px;font-size:18px;line-height:1.6;color:#475569;">Hola {$safeFirstName}. Ya casi puedes empezar a gestionar tus remesas de forma clara, rápida y trazable.</p>
          <p style="margin:0 0 32px;font-size:15px;line-height:1.6;color:#475569;">Hemos preparado esta activación para:</p>
          <p style="margin:0 0 32px;font-size:16px;font-weight:600;color:#1e293b;">{$safeEmail}</p>
          <table role="presentation" cellspacing="0" cellpadding="0"><tr><td style="margin:0 auto;border-radius:16px;background:linear-gradient(135deg,#1d4ed8,#06b6d4);box-shadow:0 12px 32px rgba(11,79,156,.35);width:fit-content;">
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
    return $this->deliver($email, $subject, $body, $html, $config);
}

public function sendPasswordReset(string $email, string $firstName, string $token, int $ttlMinutes = 30): bool
{
    $transport = $this->transport();
    if ($transport === 'log' || $transport === 'file') {
        $this->logPasswordReset($email, $token, $ttlMinutes);
        return true;
    }
    if (!in_array($transport, ['smtp', 'api'], true)) {
        throw new RuntimeException('Only SMTP or API email transport is allowed');
    }

    $config = $this->mailConfig();
    if ($this->isIncomplete($transport, $config)) {
        if ($this->isDevelopmentMode()) {
            $this->logPasswordReset($email, $token, $ttlMinutes);
            return true;
        }
        throw new RuntimeException($transport === 'api'
            ? 'MAIL API configuration is incomplete'
            : 'SMTP email configuration is incomplete');
    }

    $appUrl = rtrim((string) (getenv('SEDERG_APP_URL') ?: 'http://localhost:5173'), '/');
    $resetUrl = $appUrl . '/?reset_password=1&email=' . rawurlencode($email) . '&token=' . rawurlencode($token);
    $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $logoSrc = $this->logoSource();
    $ttlMinutes = max(1, $ttlMinutes);
    $subject = 'Restablece tu contraseña de SEDERG_PAY';
    $body = "Hola {$firstName},\n\nRestablece tu contraseña con este enlace:\n{$resetUrl}\n\nEl enlace vence en {$ttlMinutes} minutos y solo puede utilizarse una vez.\nSi no solicitaste este cambio, ignora este mensaje.";
    $html = <<<HTML
<!doctype html>
<html lang="es"><body style="margin:0;background:#f8fafc;font-family:'Inter',Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:48px 24px;background:#f8fafc;"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#ffffff;border-radius:30px;overflow:hidden;box-shadow:0 32px 96px rgba(10,43,107,.15);">
<tr><td style="padding:48px 48px 36px;background:linear-gradient(135deg,#0a2b6c 0%,#1d4ed8 48%,#06b6d4 100%);border-radius:24px 24px 0 0;text-align:center;"><img src="{$logoSrc}" alt="SEDERG_PAY" style="height:56;width:auto;display:block;margin:0 auto 24px;border-radius:8px;" /><p style="margin:0;color:rgba(255,255,255,.9);font-size:14px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;">SEDERG_PAY</p></td></tr>
<tr><td style="padding:36px 48px 48px;"><p style="margin:0 0 16px;color:#0f172a;font-size:14px;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;">Seguridad de cuenta</p><h1 style="margin:0 0 24px;font-size:36px;line-height:1.2;color:#1e293b;">Restablece tu contraseña</h1><p style="margin:0 0 20px;font-size:18px;line-height:1.6;color:#475569;">Hola {$safeFirstName}. Recibimos una solicitud para restablecer la contraseña de {$safeEmail}.</p>
<table role="presentation" cellspacing="0" cellpadding="0"><tr><td style="border-radius:16px;background:linear-gradient(135deg,#1d4ed8,#06b6d4);box-shadow:0 12px 32px rgba(11,79,156,.35);"><a href="{$safeUrl}" style="display:block;padding:18px 38px;border-radius:16px;color:#ffffff;text-decoration:none;font-size:16px;font-weight:700;">Crear nueva contraseña</a></td></tr></table>
<p style="margin:24px 0 0;font-size:14px;line-height:1.6;color:#94a3b8;">Este enlace vence en {$ttlMinutes} minutos y solo puede utilizarse una vez. Si no solicitaste este cambio, puedes ignorar este mensaje.</p>
<p style="margin:0;padding-top:28px;border-top:1px solid #e2e8f0;font-size:12px;line-height:1.6;color:#94a3b8;word-break:break-all;">{$safeUrl}</p>
</td></tr>
<tr><td style="padding:24px 48px;background:#f1f5f9;color:#94a3b8;font-size:12px;">SEDERG_PAY<br>Si no solicitaste este cambio, ignora este mensaje.</td></tr>
</table></td></tr></table></body></html>
HTML;
    return $this->deliver($email, $subject, $body, $html, $config);
}

private function logPasswordReset(string $email, string $token, int $ttlMinutes): void
{
    $appUrl = rtrim((string) (getenv('SEDERG_APP_URL') ?: 'http://localhost:5173'), '/');
    $resetUrl = $appUrl . '/?reset_password=1&email=' . rawurlencode($email) . '&token=' . rawurlencode($token);
    $ttlMinutes = max(1, $ttlMinutes);
    $this->writeDevLog("[SEDERG_PAY][DEV][password-reset] email={$email} ttl_minutes={$ttlMinutes} url={$resetUrl}");
}

public function sendRemittanceReceipt(string $email, string $firstName, array $remittance): void
{
    $transport = $this->transport();
    $config = $this->mailConfig();
    $canSend = ($transport === 'smtp' && !$config['smtp_incomplete'])
        || ($transport === 'api' && !$config['api_incomplete']);
    if (!$canSend) {
        if ($transport === 'log' || $transport === 'file' || $this->isDevelopmentMode()) {
            $this->logGeneric($email, 'remittance-receipt', 'Comprobante de remesa ' . (string) ($remittance['reference'] ?? ''));
            return;
        }
        throw new RuntimeException($transport === 'api'
            ? 'MAIL API configuration is incomplete'
            : 'SMTP email configuration is incomplete');
    }

    $esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $dash = static fn(mixed $v): string => ($v === null || $v === '') ? '—' : (string) $v;

    $direction = ($remittance['direction'] ?? '') === 'cu_to_ru' ? 'Cuba hacia Rusia' : 'Rusia hacia Cuba';
    $bFirst = trim((string) ($remittance['beneficiary_first_name'] ?? ''));
    $bLast = trim((string) ($remittance['beneficiary_last_name'] ?? ''));
    $beneficiary = trim($bFirst . ' ' . $bLast);
    if ($beneficiary === '') {
        $beneficiary = '—';
    }
    $account = '—';
    if (!empty($remittance['payout_account_last4'])) {
        $account = 'Cuenta ****' . $remittance['payout_account_last4'];
    } elseif (!empty($remittance['payout_card_last4'])) {
        $account = 'Tarjeta ****' . $remittance['payout_card_last4'];
    }
    $rate = $dash($remittance['exchange_rate'] ?? '') . ' ' . $dash($remittance['receive_currency'] ?? '') . ' por ' . $dash($remittance['send_currency'] ?? '');

    $rows = [
        'Referencia' => (string) ($remittance['reference'] ?? ''),
        'Dirección' => $direction,
        'Enviar' => $dash($remittance['send_amount'] ?? '') . ' ' . $dash($remittance['send_currency'] ?? ''),
        'Recibir' => $dash($remittance['receive_amount'] ?? '') . ' ' . $dash($remittance['receive_currency'] ?? ''),
        'Comisión' => $dash($remittance['fee_amount'] ?? '') . ' ' . $dash($remittance['fee_currency'] ?? ''),
        'Total a transferir' => $dash($remittance['total_debit_amount'] ?? '') . ' ' . $dash($remittance['send_currency'] ?? ''),
        'Tasa' => $rate,
        'Estado' => $dash($remittance['status'] ?? ''),
        'Beneficiario' => $beneficiary,
        'Método de pago' => $dash($remittance['payout_method_code'] ?? '') . (!empty($remittance['payout_provider']) ? ' · ' . $dash($remittance['payout_provider']) : ''),
        'Cuenta/tarjeta' => $account,
        'Creada' => $dash($remittance['created_at'] ?? ''),
    ];

    $subject = 'Comprobante de remesa ' . (string) ($remittance['reference'] ?? '');
    $lines = ["Hola {$firstName}", '', 'Tu remesa fue creada:', ''];
    foreach ($rows as $label => $value) {
        $lines[] = $label . ': ' . $value;
    }
    $lines[] = '';
    $lines[] = 'Información incluida en el QR: ' . $dash($remittance['payment_qr_payload'] ?? '');
    $body = implode("\n", $lines);

    $rowsHtml = '';
    foreach ($rows as $label => $value) {
        $rowsHtml .= '<tr><td style="padding:5px 0;font-size:13px;color:#8a94a9;">' . $esc($label) . '</td>'
            . '<td style="padding:5px 0 5px 14px;font-size:13px;color:#17213d;text-align:right;">' . $esc($value) . '</td></tr>';
    }

    $safeFirstName = $esc($firstName);
    $safeReference = $esc((string) ($remittance['reference'] ?? ''));
    $safeQrPayload = $esc((string) ($remittance['payment_qr_payload'] ?? ''));
    $logoSrc = $this->logoSource();
    $html = <<<HTML
<!doctype html><html lang="es"><body style="margin:0;background:#eef3fa;color:#0f1f3d;font-family:'Inter',Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:36px 12px;background:#eef3fa;"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:24px;overflow:hidden;border:1px solid #dde6f2;box-shadow:0 24px 60px rgba(15,31,61,.12);">
<tr><td style="padding:26px 40px;background:linear-gradient(135deg,#0a2b6b 0%,#0b4f9c 52%,#00aeef 100%);">
  <img src="{$logoSrc}" alt="SEDERG_PAY" style="height:42px;width:auto;display:block;filter:drop-shadow(0 4px 12px rgba(0,0,0,.25));" />
  <p style="margin:10px 0 0;color:rgba(255,255,255,.82);font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;">Comprobante de operación</p>
</td></tr>
<tr><td style="padding:34px 40px;">
<p style="margin:0 0 10px;color:#0b4f9c;font-size:12px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;">Comprobante de operación</p>
<h1 style="margin:0 0 16px;font-size:26px;color:#10264d;">Remesa {$safeReference}</h1>
<p style="margin:0 0 22px;font-size:15px;color:#42536b;">Hola {$safeFirstName}, guardamos los datos de tu operación.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #edf0f6;">{$rowsHtml}</table>
<p style="margin:20px 0 0;padding:16px;background:#f4f7fc;border-radius:12px;word-break:break-all;font-size:12px;color:#71809d;"><strong style="color:#10264d;">Datos incluidos en el QR:</strong><br>{$safeQrPayload}</p>
</td></tr>
<tr><td style="padding:20px 40px;background:#f4f7fc;color:#71809d;font-size:12px;border-top:1px solid #e1e7f3;">SEDERG_PAY — Conserva este comprobante para dar seguimiento a tu remesa.</td></tr>
</table></td></tr></table></body></html>
HTML;
    $this->deliver($email, $subject, $body, $html, $config);
}

private function smtpConfig(): array
{
    return $this->mailConfig();
}

/**
 * Notifica por correo el cambio de estado de un expediente KYC.
 *
 * Estados admitidos: pending_review (expediente recibido), approved
 * (verificacion superada), rejected (expediente denegado) y
 * requires_action (se necesita documentacion adicional).
 */
public function sendKycStatusChange(string $email, string $firstName, string $status, string $reason = ''): void
{
    $transport = $this->transport();
    $config = $this->mailConfig();
    $canSend = ($transport === 'smtp' && !$config['smtp_incomplete'])
        || ($transport === 'api' && !$config['api_incomplete']);
    if (!$canSend) {
        if ($transport === 'log' || $transport === 'file' || $this->isDevelopmentMode()) {
            $this->logGeneric($email, 'kyc-status', 'KYC ' . $status);
            return;
        }
        throw new RuntimeException($transport === 'api'
            ? 'MAIL API configuration is incomplete'
            : 'SMTP email configuration is incomplete');
    }

    $esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeFirstName = $esc($firstName);
    $safeReason = trim($reason);
    $appUrl = rtrim((string) (getenv('SEDERG_APP_URL') ?: 'http://localhost:5173'), '/');
    $safeAppUrl = $esc($appUrl);

    [$title, $headline, $intro, $note] = match ($status) {
        'approved' => [
            'Verificacion de identidad completada',
            'Tu identidad ha sido verificada',
            'Ya puedes enviar remesas sin el limite diario de 500 RUB.',
            'Si necesitas actualizar tus documentos, subelos desde la seccion de verificacion de identidad.',
        ],
        'rejected' => [
            'Verificacion de identidad denegada',
            'No pudimos verificar tu identidad',
            'Revisa el motivo indicado y vuelve a enviar el expediente con la documentacion correcta.',
            '',
        ],
        'requires_action' => [
            'Necesitamos documentacion adicional',
            'Tu expediente necesita informacion adicional',
            'Adjunta los documentos que te solicitamos para completar la verificacion.',
            '',
        ],
        default => [
            'Expediente de verificacion recibido',
            'Recibimos tu expediente de verificacion',
            'Nuestro equipo esta revisando tus documentos. Te avisaremos en cuanto haya novedades.',
            '',
        ],
    };

    $subject = 'SEDERG_PAY — ' . $title;
    $lines = ["Hola {$firstName},", '', $intro];
    if ($safeReason !== '') {
        $lines[] = '';
        $lines[] = 'Motivo del equipo de revision:';
        $lines[] = $safeReason;
    }
    if ($note !== '') {
        $lines[] = '';
        $lines[] = $note;
    }
    $lines[] = '';
    $lines[] = 'Puedes consultar el estado de tu expediente en ' . $appUrl;
    $body = implode("\n", $lines);

    $reasonBlock = '';
    if ($safeReason !== '') {
        $reasonBlock = '<p style="margin:0 0 20px;padding:14px 16px;background:#f4f7fc;border-radius:12px;font-size:14px;color:#42536b;">'
            . '<strong style="display:block;margin-bottom:6px;color:#10264d;">Motivo del equipo de revision</strong>'
            . $esc($safeReason) . '</p>';
    }
    $noteBlock = '';
    if ($note !== '') {
        $noteBlock = '<p style="margin:0 0 20px;font-size:14px;color:#71809d;">' . $esc($note) . '</p>';
    }

    $logoSrc = $this->logoSource();
    $html = <<<HTML
<!doctype html><html lang="es"><body style="margin:0;background:#eef3fa;color:#0f1f3d;font-family:'Inter',Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:36px 12px;background:#eef3fa;"><tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:24px;overflow:hidden;border:1px solid #dde6f2;box-shadow:0 24px 60px rgba(15,31,61,.12);">
<tr><td style="padding:26px 40px;background:linear-gradient(135deg,#0a2b6b 0%,#0b4f9c 52%,#00aeef 100%);">
  <img src="{$logoSrc}" alt="SEDERG_PAY" style="height:42px;width:auto;display:block;filter:drop-shadow(0 4px 12px rgba(0,0,0,.25));" />
  <p style="margin:10px 0 0;color:rgba(255,255,255,.82);font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;">Verificacion de identidad</p>
</td></tr>
<tr><td style="padding:34px 40px;">
<p style="margin:0 0 10px;color:#0b4f9c;font-size:12px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;">KYC</p>
<h1 style="margin:0 0 16px;font-size:26px;color:#10264d;">{$esc($headline)}</h1>
<p style="margin:0 0 22px;font-size:15px;color:#42536b;">Hola {$safeFirstName}, {$esc($intro)}</p>
{$reasonBlock}{$noteBlock}
<p style="margin:0;font-size:14px;color:#42536b;"><a href="{$safeAppUrl}" style="color:#0b4f9c;font-weight:600;">Ir a SEDERG_PAY</a></p>
</td></tr>
<tr><td style="padding:20px 40px;background:#f4f7fc;color:#71809d;font-size:12px;border-top:1px solid #e1e7f3;">SEDERG_PAY — Remesas Rusia &nbsp; Cuba</td></tr>
</table></td></tr></table></body></html>
HTML;
    $this->deliver($email, $subject, $body, $html, $config);
}

private function transport(): string
{
    return strtolower(trim((string) (getenv('SEDERG_MAIL_TRANSPORT') ?: 'smtp')));
}

private function isIncomplete(string $transport, array $config): bool
{
    if ($transport === 'api') {
        return !empty($config['api_incomplete']);
    }

    return !empty($config['smtp_incomplete']);
}

/**
 * Envia el mensaje y, en modo sandbox, evita que un fallo de entrega rompa el
 * flujo del usuario: la traza queda en el log de desarrollo.
 */
private function deliver(string $email, string $subject, string $body, string $html, array $config): bool
{
    try {
        $this->sendMessage($email, $subject, $body, $html, $config);

        return false;
    } catch (\Throwable $exception) {
        if (!$this->isDevelopmentMode()) {
            throw $exception;
        }
        $this->writeDevLog('[SEDERG_PAY][DEV][mail-error] ' . $exception->getMessage());
        $this->logGeneric($email, 'mail-fallback', 'No se pudo enviar el correo: ' . $subject);

        return true;
    }
}

private function mailConfig(): array
{
    $host = trim((string) getenv('SEDERG_MAIL_HOST'));
    $port = (int) (getenv('SEDERG_MAIL_PORT') ?: 587);
    $username = trim((string) getenv('SEDERG_MAIL_USERNAME'));
    $password = (string) getenv('SEDERG_MAIL_PASSWORD');
    $from = trim((string) getenv('SEDERG_MAIL_FROM'));
    $encryption = strtolower(trim((string) (getenv('SEDERG_MAIL_ENCRYPTION') ?: 'tls')));
    $auth = $username !== '' || $password !== '';
    if ($encryption === 'none' || $encryption === '') {
        $encryption = 'none';
    }
    $smtpIncomplete = $host === ''
        || $from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)
        || $port < 1 || $port > 65535
        || !in_array($encryption, ['tls', 'ssl', 'none'], true)
        || ($auth && ($username === '' || $password === ''));

    $apiUrl = trim((string) getenv('SEDERG_MAIL_API_URL')) ?: 'https://api.brevo.com/v3/smtp/email';
    $apiKey = trim((string) getenv('SEDERG_MAIL_API_KEY'));
    $apiKeyHeader = trim((string) getenv('SEDERG_MAIL_API_KEY_HEADER')) ?: 'api-key';
    $fromName = trim((string) getenv('SEDERG_MAIL_FROM_NAME')) ?: 'SEDERG_PAY';
    $apiIncomplete = $apiKey === '' || $from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL);

    return compact('host', 'port', 'username', 'password', 'from', 'encryption', 'auth') + [
        'smtp_incomplete' => $smtpIncomplete,
        'api_incomplete' => $apiIncomplete,
        'api_url' => $apiUrl,
        'api_key' => $apiKey,
        'api_key_header' => $apiKeyHeader,
        'from_name' => $fromName,
    ];
}

private function buildVerificationUrl(string $email, string $token): string
{
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
    return $appUrl . '/?verify_email=' . rawurlencode($email) . '&token=' . rawurlencode($token);
}

private function getLogoImage(): ?array
{
    // El logotipo se busca primero en una ruta versionada (resources/brand) y
    // despues en los assets publicados de Yii. La version invertida se usa en
    // las cabeceras oscuras de los correos.
    $candidates = [
        __DIR__ . '/../../../resources/brand/sederg-pay-logo-inverted.png',
        __DIR__ . '/../../../resources/brand/sederg-pay-logo.png',
        __DIR__ . '/../../../web/assets/sederg-pay-logo-inverted.png',
        __DIR__ . '/../../../web/assets/sederg-pay-logo.png',
    ];
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $data = @file_get_contents($path);
        if ($data === false || $data === '') {
            continue;
        }

        return [
            'cid' => 'logo',
            'data' => $data,
            'mime' => 'image/png',
            'path' => $path,
        ];
    }

    return null;
}

/**
 * Devuelve el origen de la imagen del logotipo.
 *
 * Se incrusta como data URI (no como cid) porque muchos clientes de correo
 * no renderizan imagenes cid y el logotipo se veria roto o invisible. Si no
 * hay fichero disponible, se usa el logotipo embebido en el propio codigo.
 */
private function logoSource(): string
{
    $logo = $this->getLogoImage();
    if ($logo !== null) {
        return 'data:image/png;base64,' . base64_encode($logo['data']);
    }

    return 'data:image/png;base64,' . self::LOGO_BASE64;
}

private function sendMessage(string $email, string $subject, string $body, string $html, ?array $config = null): void
{
    $config = $config ?? $this->mailConfig();
    if ($this->transport() === 'api') {
        if (!empty($config['api_incomplete'])) {
            throw new RuntimeException('MAIL API configuration is incomplete');
        }
        $this->sendViaApi($email, $subject, $body, $html, $config);

        return;
    }
    if (!empty($config['smtp_incomplete'])) {
        throw new RuntimeException('SMTP email configuration is incomplete');
    }
    $host = $config['host'];
    $port = $config['port'];
    $username = (string) ($config['username'] ?? '');
    $password = (string) ($config['password'] ?? '');
    $from = $config['from'];
    $encryption = $config['encryption'];

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
        if (!empty($config['auth'])) {
            $this->command($socket, 'AUTH LOGIN', 334);
            $this->command($socket, base64_encode($username), 334);
            $this->command($socket, base64_encode($password), 235);
        }
        $this->command($socket, "MAIL FROM:<{$from}>", 250);
        $this->command($socket, "RCPT TO:<{$email}>", [250, 251]);
        $this->command($socket, 'DATA', 354);

        $message = $this->buildMimeMessage($from, $email, $subject, $body, $html);
        $this->write($socket, $this->dotStuff($message) . "\r\n.");
        $this->expect($socket, 250);
        $this->command($socket, 'QUIT', 221);
    } finally {
        fclose($socket);
    }
}

/**
 * Envia el mensaje por una API HTTPS del proveedor de correo (Brevo y compatibles).
 * Necesario en nubes que bloquean el trafico SMTP saliente, como UpCloud en trial.
 */
private function sendViaApi(string $email, string $subject, string $body, string $html, array $config): void
{
    $parts = parse_url((string) $config['api_url']);
    $scheme = $parts['scheme'] ?? '';
    $host = $parts['host'] ?? '';
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $port = (int) ($parts['port'] ?? 443);
    if ($scheme !== 'https' || $host === '') {
        throw new RuntimeException('MAIL API configuration is invalid');
    }

    $payload = json_encode([
        'sender' => ['email' => (string) $config['from'], 'name' => (string) $config['from_name']],
        'to' => [['email' => $email]],
        'subject' => $subject,
        'htmlContent' => $html,
        'textContent' => $body,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('MAIL API payload encoding failed');
    }

    $socket = @stream_socket_client("ssl://{$host}:{$port}", $errorCode, $errorMessage, 20, STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) {
        throw new RuntimeException("MAIL API connection failed: {$errorMessage} ({$errorCode})");
    }
    stream_set_timeout($socket, 25);
    try {
        $request = "POST {$path} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . "Accept: application/json\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($payload) . "\r\n"
            . $config['api_key_header'] . ': ' . $config['api_key'] . "\r\n"
            . "User-Agent: SEDERG_PAY/1.0\r\n"
            . "Connection: close\r\n\r\n"
            . $payload;
        fwrite($socket, $request);
        $response = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }
    } finally {
        fclose($socket);
    }

    if (!preg_match('#^HTTP/1\.[01]\s+(\d{3})#', $response, $matches)) {
        throw new RuntimeException('MAIL API returned an invalid response');
    }
    $status = (int) $matches[1];
    if ($status >= 200 && $status < 300) {
        return;
    }
    $separator = strpos($response, "\r\n\r\n");
    $errorBody = $separator === false ? '' : substr($response, $separator + 4);
    $decoded = json_decode($errorBody, true);
    $detail = is_array($decoded)
        ? (string) ($decoded['message'] ?? $decoded['code'] ?? '')
        : substr($errorBody, 0, 200);
    throw new RuntimeException("MAIL API rejected the message ({$status}): {$detail}");
}

private function buildMimeMessage(string $from, string $to, string $subject, string $body, string $html): string
{
    $altBoundary = '=_sederg_alt_' . bin2hex(random_bytes(12));
    $textPart = "--{$altBoundary}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $body . "\r\n\r\n";
    $htmlPart = "--{$altBoundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $html . "\r\n\r\n";
    $alternative = $textPart . $htmlPart . "--{$altBoundary}--";

    $headers = [
        'From: ' . $this->encodeHeaderName('SEDERG_PAY') . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . $this->encodeHeader($subject),
        'Date: ' . gmdate('r'),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@sederg-pay.local>',
        'MIME-Version: 1.0',
    ];

    $logo = $this->getLogoImage();
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';

    return implode("\r\n", $headers) . "\r\n\r\n" . $alternative;
}

private function encodeHeader(string $value): string
{
    if (preg_match('/[^\x20-\x7E]/', $value) === 1) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

private function encodeHeaderName(string $value): string
{
    return $value;
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
    $this->writeDevLog($message);
}

private function logGeneric(string $email, string $kind, string $subject): void
{
    $message = "[SEDERG_PAY][DEV][{$kind}] email={$email} subject={$subject}";
    $this->writeDevLog($message);
}

private function writeDevLog(string $message): void
{
    error_log($message);
    $logDir = dirname(__DIR__, 3) . '/runtime/logs';
    if (is_dir($logDir) && is_writable($logDir)) {
        @file_put_contents($logDir . '/mail-dev.log', '[' . gmdate('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}

/**
 * @param int|int[] $expectedCode
 */
private function command($socket, string $command, int|array $expectedCode): void
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

/**
 * @param int|int[] $expectedCode
 */
private function expect($socket, int|array $expectedCode): string
{
    $response = '';
    while (($line = fgets($socket)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    $codes = is_array($expectedCode) ? $expectedCode : [$expectedCode];
    foreach ($codes as $code) {
        if (preg_match('/^' . $code . '\b/m', $response)) {
            return $response;
        }
    }
    $detail = trim($response);
    if ($detail === '') {
        $meta = stream_get_meta_data($socket);
        $detail = !empty($meta['timed_out']) ? 'empty response (connection timed out)' : 'empty response';
    }
    throw new RuntimeException('SMTP server rejected command: ' . $detail);
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