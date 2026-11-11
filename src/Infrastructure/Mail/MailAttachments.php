<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Mail;

use Broadcast\Domain\ApiFailure;
use Broadcast\Domain\Attachment;
use Broadcast\Domain\MailSizeExceeded;
use PHPMailer\PHPMailer\PHPMailer;

final class MailAttachments
{
    /** @param list<array{attachment:Attachment,path:string}> $attachments */
    public static function prepare(PHPMailer $mail, array $attachments, int $maxMessageBytes): void
    {
        if (!array_is_list($attachments) || count($attachments) > 10 || $maxMessageBytes < 1
            || preg_match('/[\r\n\x00]/', $mail->Subject)) {
            throw new ApiFailure('Invalid email preparation input.');
        }
        $totalBytes = strlen($mail->Body);
        $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
        // PHPMailer retains raw bytes, base64 bodies, MIME and API encodings together.
        $memoryBudget = $memoryLimit < 0 ? PHP_INT_MAX : max(0, intdiv($memoryLimit - memory_get_usage(true) - 16_777_216, 8));
        $mail->AllowEmpty = $attachments !== [];
        try {
            foreach ($attachments as $item) {
                $attachment = $item['attachment'] ?? null;
                $path = $item['path'] ?? null;
                if (!$attachment instanceof Attachment || !is_string($path) || is_link($path) || !is_file($path)) {
                    throw new ApiFailure('Email attachment unavailable.');
                }
                $totalBytes += $attachment->byteSize;
                // Bound memory before MIME/base64 expansion and before opening a file.
                if ($totalBytes > $maxMessageBytes || $totalBytes > $memoryBudget) {
                    throw new MailSizeExceeded();
                }
                clearstatcache(true, $path);
                if (filesize($path) !== $attachment->byteSize) {
                    throw new ApiFailure('Email attachment changed.');
                }
                $bytes = file_get_contents($path, false, null, 0, Attachment::MAX_BYTES + 1);
                if ($bytes === false || strlen($bytes) !== $attachment->byteSize || !hash_equals($attachment->sha256, hash('sha256', $bytes))) {
                    throw new ApiFailure('Email attachment changed.');
                }
                // Freeze bytes now so later path changes cannot alter an approved message.
                $mail->addStringAttachment($bytes, $attachment->displayName, PHPMailer::ENCODING_BASE64, $attachment->mime);
            }
            if (!$mail->preSend()) {
                throw new ApiFailure('Email preparation failed.');
            }
            if (strlen($mail->getSentMIMEMessage()) > $maxMessageBytes) {
                throw new MailSizeExceeded();
            }
        } catch (ApiFailure|MailSizeExceeded $failure) {
            throw $failure;
        } catch (\Throwable) {
            throw new ApiFailure('Email preparation failed.');
        }
    }
}
