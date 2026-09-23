<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

final class Mailer
{
    public static function send(string $toEmail, string $toName, string $subject, string $bodyHtml): bool
    {
        $mailConfig = (array) Config::get('mail', []);
        $fromAddress = $mailConfig['from_address'] ?: 'no-reply@' . self::guessDomain();
        $fromName = $mailConfig['from_name'] ?: (string) Config::get('site_name', 'Pennywise');

        $mail = new PHPMailer(true);

        try {
            if (!empty($mailConfig['smtp_host'])) {
                $mail->isSMTP();
                $mail->Host = $mailConfig['smtp_host'];
                $mail->Port = (int) ($mailConfig['smtp_port'] ?? 587);
                $mail->SMTPAuth = !empty($mailConfig['smtp_user']);
                if ($mail->SMTPAuth) {
                    $mail->Username = $mailConfig['smtp_user'];
                    $mail->Password = $mailConfig['smtp_pass'];
                }
                if (!empty($mailConfig['smtp_secure'])) {
                    $mail->SMTPSecure = $mailConfig['smtp_secure'];
                }
            } else {
                $mail->isMail();
            }

            $mail->setFrom($fromAddress, $fromName);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $bodyHtml;
            $mail->AltBody = strip_tags($bodyHtml);

            return $mail->send();
        } catch (\Throwable $e) {
            error_log('Mailer error: ' . $e->getMessage());
            return false;
        }
    }

    private static function guessDomain(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = explode(':', $host, 2)[0]; // strip a port, if present
        return preg_replace('/^www\./', '', $host);
    }
}
