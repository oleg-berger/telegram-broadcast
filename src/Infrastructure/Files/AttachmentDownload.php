<?php
declare(strict_types=1);

namespace Broadcast\Infrastructure\Files;

use Broadcast\Application\AttachmentLinks;
use Broadcast\Application\AttachmentStorage;

final readonly class AttachmentDownload
{
    public function __construct(private AttachmentLinks $links, private AttachmentStorage $storage) {}

    /** @param callable(int,array<string,string>):void $headers @param callable(string):void $write */
    public function serve(string $method, string $token, int $now, callable $headers, callable $write): int
    {
        $base = ['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer'];
        if (!in_array($method,['GET','HEAD'],true)) {
            $headers(405,$base+['Allow'=>'GET, HEAD','Content-Length'=>'0']);
            return 405;
        }
        $stream = null;
        try {
            $attachment = $this->links->resolve($token,$now);
            if ($attachment === null) { throw new \RuntimeException(); }
            $path = $this->storage->path($attachment->storageKey);
            $stream = fopen($path,'rb');
            if ($stream === false) { throw new \RuntimeException(); }
            $stat = fstat($stream);
            if (!$stat || $stat['size'] !== $attachment->byteSize) { throw new \RuntimeException(); }
            $hash = hash_init('sha256');
            if (hash_update_stream($hash,$stream,$attachment->byteSize+1) !== $attachment->byteSize
                || !hash_equals($attachment->sha256,hash_final($hash)) || !rewind($stream)) {
                throw new \RuntimeException();
            }
        } catch (\Throwable) {
            if (is_resource($stream)) { fclose($stream); }
            $headers(404,$base+['Content-Length'=>'0']);
            return 404;
        }
        try {
            $headers(200,$base+['Content-Type'=>$attachment->mime,'Content-Length'=>(string)$attachment->byteSize,
                'Content-Disposition'=>"attachment; filename=\"download\"; filename*=UTF-8''".rawurlencode($attachment->displayName)]);
            if ($method === 'GET') {
                $remaining = $attachment->byteSize;
                while ($remaining > 0) {
                    $chunk = fread($stream,min(65536,$remaining));
                    if ($chunk === false || $chunk === '') { throw new \RuntimeException('Attachment streaming interrupted.'); }
                    $write($chunk);
                    $remaining -= strlen($chunk);
                }
            }
            return 200;
        } finally {
            fclose($stream);
        }
    }
}
