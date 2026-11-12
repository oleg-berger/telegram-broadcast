<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Mail;

use PHPMailer\PHPMailer\SMTP;

final class DeliverySmtp extends SMTP
{
    private bool $terminatorAttempted = false;
    private bool $uncertain = false;

    public function data($msg_data)
    {
        $this->terminatorAttempted = false;
        $this->uncertain = false;
        $accepted = false;
        try {
            $accepted = parent::data($msg_data);
            return $accepted;
        } finally {
            $code = (int) ($this->getError()['smtp_code'] ?? 0);
            // A definite rejection is retryable by code; a lost final reply may hide acceptance.
            $this->uncertain = !$accepted && $this->terminatorAttempted && ($code < 400 || $code >= 600);
        }
    }

    public function client_send($data, $command = '')
    {
        if ($command === 'DATA END') { $this->terminatorAttempted = true; }
        return parent::client_send($data, $command);
    }

    public function acceptanceUncertain(): bool
    {
        return $this->uncertain;
    }
}
