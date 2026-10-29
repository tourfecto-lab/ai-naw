<?php

/**
 * GET /media/out/{name} - public on purpose: Meta's servers fetch the attachment of a message we send from the inbox. The
 * name is 128 random bits, the file expires after two days, and only files stored by MetaDmMedia can be reached.
 */
class OutboundMediaController extends Controller
{
    public function show(array $params = []): array
    {
        $name = (string) ($params['name'] ?? '');
        $path = MetaDmMedia::path($name);
        $mime = $path ? MetaDmMedia::mimeForExt((string) pathinfo($name, PATHINFO_EXTENSION)) : null;
        if (!$path || !$mime) {
            return $this->error('Not found', 404);
        }
        $inline = preg_match('#^(image/|audio/|video/)#', $mime) === 1;
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: public, max-age=3600');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="media.' . pathinfo($name, PATHINFO_EXTENSION) . '"');
        readfile($path);
        exit;
    }
}
