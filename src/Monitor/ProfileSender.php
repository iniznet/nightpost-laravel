<?php

namespace Nightpost\Laravel\Monitor;

interface ProfileSender
{
    /**
     * Ship one sampled request profile to the monitor ingest.
     *
     * @param array<string, mixed> $payload
     */
    public function postProfile(array $payload): void;
}