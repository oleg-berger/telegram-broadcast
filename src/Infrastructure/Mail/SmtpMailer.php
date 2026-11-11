<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Mail;

use Broadcast\Application\AttachmentMailGateway;
use Broadcast\Domain\ApiFailure;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

final class SmtpMailer implements AttachmentMailGateway
{
    public function __construct(
        private string $host,
        private int $port,
        private string $encryption,
        private string $username,
        private string $password,
        private string $fromAddress,
        private string $fromName,
        private string $replyTo,
        private bool $test = false,
        private int $maxMessageBytes = 25_000_000,
    ) {
        if (!in_array($encryption, ['starttls', 'tls'], true) && !($test && $encryption === 'none')) {
            throw new \InvalidArgumentException('Secure SMTP is required.');
        }
    }

    public function send(string $to, string $subject, string $text): void
    {
        $this->sendWithAttachments($to, $subject, $text, []);
    }

    public function sendWithAttachments(string $to, string $subject, string $text, array $attachments): void
    {
        $mail = new PHPMailer(true);
        $smtp = new DeliverySmtp();
        $mail->setSMTPInstance($smtp);
        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->SMTPAuth = $this->username !== '';
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            $mail->SMTPSecure = match ($this->encryption) {
                'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
                'tls' => PHPMailer::ENCRYPTION_SMTPS,
                default => '',
            };
            $mail->SMTPAutoTLS = !$this->test;
            $mail->Timeout = 20;
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_BASE64;
            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->addReplyTo($this->replyTo ?: $this->fromAddress);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $text;
            $mail->isHTML(false);
            MailAttachments::prepare($mail, $attachments, $this->maxMessageBytes);
            $mail->postSend();
        } catch (Exception) {
            $code = (int) ($mail->getSMTPInstance()->getError()['smtp_code'] ?? 0);
            // Never propagate upstream messages: they may contain addresses or credentials.
            throw new ApiFailure('SMTP delivery failed.', $code < 500, uncertain: $smtp->acceptanceUncertain());
        } finally {
            $mail->smtpClose();
        }
    }
}
