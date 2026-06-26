<?php
/**
 * Inert. This endpoint previously hosted token-guarded read-only diagnostics
 * used during development. Those investigations are complete, so the handler is
 * disabled and returns 410. Re-introduce a guarded handler here only for a
 * specific, time-boxed diagnostic.
 */
http_response_code(410);
header('Content-Type: text/plain');
echo 'gone';
