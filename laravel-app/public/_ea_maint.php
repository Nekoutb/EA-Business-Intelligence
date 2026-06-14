<?php
/**
 * DEPRECATED — neutralised stub. This host's incremental deploy does not remove
 * files deleted from the repo, so the helper is overwritten with this inert stub
 * (a modification, which DOES propagate). It exposes nothing and does nothing.
 */
http_response_code(410);
header('Content-Type: text/plain');
echo 'Gone.';
