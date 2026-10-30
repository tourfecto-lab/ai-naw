<?php

/** Tourfecto - AI Voice Agent: normalised provider failure (never carries credentials). */
class VoiceProviderException extends RuntimeException
{
    public int $httpCode;
    public string $providerCode;

    public function __construct(string $message, int $httpCode = 0, string $providerCode = '')
    {
        parent::__construct($message);
        $this->httpCode = $httpCode;
        $this->providerCode = $providerCode;
    }
}
