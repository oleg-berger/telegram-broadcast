<?php
declare(strict_types=1);

namespace Broadcast\Application;

/** Optional private staging support; older storage adapters remain compatible. */
interface AttachmentStagingStorage extends AttachmentStorage
{
    /** Return an unpredictable, nonexistent destination for exclusive download creation. */
    public function downloadPath(): string;
    /** Remove only exact temporary basenames older than one day; never follow links. */
    public function cleanupTemporary(int $now): int;
}
