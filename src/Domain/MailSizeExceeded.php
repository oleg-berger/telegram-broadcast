<?php
declare(strict_types=1);

namespace Broadcast\Domain;

/** MIME preparation rejected the message before any delivery network operation. */
final class MailSizeExceeded extends \RuntimeException
{
    public function __construct() { parent::__construct('Email exceeds size limit.'); }
}
