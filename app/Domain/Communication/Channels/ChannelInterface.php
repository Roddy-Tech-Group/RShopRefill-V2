<?php

namespace App\Domain\Communication\Channels;

use App\Domain\Communication\DTOs\CommunicationPayload;
use App\Domain\Communication\DTOs\DeliveryResult;

interface ChannelInterface
{
    /**
     * Deliver the communication payload via this channel.
     */
    public function send(CommunicationPayload $payload): DeliveryResult;
}
