<?php

/**
 * Tourfecto - Event Listener Contract
 * @version 1.0.0
 */
interface EventListenerInterface
{
    /**
     * @param AppEvent $event
     * @return void
     */
    public function handle(AppEvent $event): void;
}
