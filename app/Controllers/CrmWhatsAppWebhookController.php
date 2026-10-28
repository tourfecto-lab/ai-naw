<?php
/** Legacy URL delegates to the signed, sender-routed official inbox. */
class CrmWhatsAppWebhookController extends WhatsAppCloudController
{
    public function verify(array $params=[]): array {return $this->platformWebhookVerify($params);}
    public function receive(array $params=[]): array {return $this->platformWebhook($params);}
}
