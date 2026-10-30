<?php

/**
 * Tourfecto - AI Voice Agent: the XML a provider executes for a call (TwiML for Twilio, TeXML for Telnyx: same verbs).
 * Shared so both providers answer, speak, listen (<Gather input="speech">), hand over (<Dial>) and hang up identically.
 */
trait TexmlInstructions
{
    /** opts: stream_url (wss://), forward_to (E.164), sip_uri, say, record */
    public function inboundInstructions(array $opts): array
    {
        $x = static fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES);
        $inner = '';
        if (!empty($opts['say'])) {
            $inner .= '<Say>' . $x($opts['say']) . '</Say>';
        }
        if (!empty($opts['stream_url'])) {
            if (!preg_match('#^wss://#i', (string) $opts['stream_url'])) {
                throw new InvalidArgumentException('stream_url must be wss://');
            }
            $params = '';
            foreach ((array) ($opts['stream_params'] ?? []) as $k => $v) {
                if (preg_match('/^[A-Za-z0-9_]{1,40}$/', (string) $k)) {
                    $params .= '<Parameter name="' . $x($k) . '" value="' . $x($v) . '"/>';
                }
            }
            $inner .= $params === ''
                ? '<Connect><Stream url="' . $x($opts['stream_url']) . '"/></Connect>'
                : '<Connect><Stream url="' . $x($opts['stream_url']) . '">' . $params . '</Stream></Connect>';
        } elseif (!empty($opts['forward_to'])) {
            $rec = !empty($opts['record']) ? ' record="record-from-answer"' : '';
            $inner .= '<Dial' . $rec . '>' . $x(self::e164($opts['forward_to'])) . '</Dial>';
        } elseif (!empty($opts['sip_uri'])) {
            $inner .= '<Dial><Sip>' . $x($opts['sip_uri']) . '</Sip></Dial>';
        }
        return ['content_type' => 'text/xml', 'body' => '<?xml version="1.0" encoding="UTF-8"?><Response>' . $inner . '</Response>'];
    }

    public function turnInstructions(array $o): array
    {
        $x = static fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES);
        $voice = preg_match('/^[A-Za-z0-9._\-]{2,60}$/', (string) ($o['voice'] ?? '')) ? $o['voice'] : 'Polly.Joanna';
        $say = trim((string) ($o['say'] ?? '')) === '' ? '' : '<Say voice="' . $x($voice) . '">' . $x($o['say']) . '</Say>';
        $inner = $say;
        if (!empty($o['gather_url'])) {
            if (!preg_match('#^https://#i', (string) $o['gather_url'])) {
                throw new InvalidArgumentException('gather_url must be https');
            }
            $loc = preg_match('/^[a-z]{2}-[A-Z]{2}$/', (string) ($o['language'] ?? '')) ? $o['language'] : 'en-US';
            $inner = '<Gather input="speech" action="' . $x($o['gather_url']) . '" method="POST" language="' . $x($loc) . '" speechTimeout="auto" timeout="6" actionOnEmptyResult="true">' . $say . '</Gather>';
        } elseif (!empty($o['transfer_to'])) {
            $num = $x(self::e164($o['transfer_to']));
            if (!empty($o['whisper_url'])) {
                if (!preg_match('#^https://#i', (string) $o['whisper_url'])) {
                    throw new InvalidArgumentException('whisper_url must be https');
                }
                $inner = $say . '<Dial><Number url="' . $x($o['whisper_url']) . '">' . $num . '</Number></Dial>'; // the person hears the briefing before the caller is connected
            } else {
                $inner = $say . '<Dial>' . $num . '</Dial>';
            }
        } else {
            $inner = $say . '<Hangup/>';
        }
        return ['content_type' => 'text/xml', 'body' => '<?xml version="1.0" encoding="UTF-8"?><Response>' . $inner . '</Response>'];
    }
}
