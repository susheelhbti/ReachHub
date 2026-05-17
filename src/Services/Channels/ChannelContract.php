<?php

namespace ReachHub\Services\Channels;

use ReachHub\Models\Campaign;
use ReachHub\Models\Contact;

interface ChannelContract
{
    /**
     * Send the campaign to a single contact.
     * Returns an array with keys: success (bool), message_id (?string), error (?string)
     */
    public function send(Campaign $campaign, Contact $contact): array;

    /**
     * Returns the channel identifier string (email, whatsapp, sms, push)
     */
    public function channelName(): string;

    /**
     * Validate channel-specific configuration before sending.
     * Throws \RuntimeException if config is invalid.
     */
    public function validateConfig(): void;
}
