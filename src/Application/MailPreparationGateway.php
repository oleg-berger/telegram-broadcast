<?php
declare(strict_types=1);

namespace Broadcast\Application;

interface MailPreparationGateway extends MailGateway
{
    /** True means one external preparation operation ran; send on a later worker tick. */
    public function prepareDelivery(): bool;
}
