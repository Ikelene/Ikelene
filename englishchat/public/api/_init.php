<?php
// Locates the private/ folder (outside the web root) and loads the bootstrap.
// Override with the EC_PRIVATE_DIR environment variable if your host needs it.
$ecPrivate = getenv('EC_PRIVATE_DIR') ?: dirname(__DIR__, 2) . '/private';
if (!is_file($ecPrivate . '/lib/bootstrap.php')) {
    http_response_code(500);
    exit('Configuration error: private folder not found.');
}
require_once $ecPrivate . '/lib/bootstrap.php';
