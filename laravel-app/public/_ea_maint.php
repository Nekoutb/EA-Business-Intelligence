<?php
/**
 * DEPRECATED — neutralised stub.
 *
 * This was a one-time maintenance helper. This host's incremental deploy
 * does not remove files deleted from the repo, so rather than leave the
 * functional tool live we overwrite it with this inert stub (a modification,
 * which DOES propagate). It exposes nothing and does nothing. Safe to delete
 * entirely on a full redeploy.
 */
http_response_code(410);
header('Content-Type: text/plain');
echo 'Gone.';
