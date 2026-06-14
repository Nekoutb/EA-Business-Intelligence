<?php
/**
 * DEPRECATED — neutralised stub.
 *
 * Was a one-time maintenance/diagnostic helper. This host's incremental deploy
 * does not remove files deleted from the repo, so it is overwritten with this
 * inert stub (a modification, which DOES propagate). It exposes nothing and
 * does nothing. Safe to delete entirely on a full redeploy.
 */
http_response_code(410);
header('Content-Type: text/plain');
echo 'Gone.';
