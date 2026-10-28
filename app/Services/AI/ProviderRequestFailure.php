<?php
class ProviderRequestFailure extends RuntimeException
{
    public bool $uncertain;
    public function __construct(bool $uncertain=false){parent::__construct('Unable to generate content. Please retry or contact support.');$this->uncertain=$uncertain;}
}
