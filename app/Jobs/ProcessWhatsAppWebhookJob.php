<?php
class ProcessWhatsAppWebhookJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        require_once dirname(__DIR__).'/Services/Chat/WhatsAppWebhookInbox.php';
        $id=(int)($payload['event_id']??0);
        if ($id<1) throw new InvalidArgumentException('Missing WhatsApp event id');
        WhatsAppWebhookInbox::process($id);
    }
}
