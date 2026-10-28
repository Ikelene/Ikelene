<?php
declare(strict_types=1);
// Public front-end settings (no secrets).
require __DIR__ . '/_init.php';
header('Cache-Control: public, max-age=300');
json_out(['recaptcha_site_key' => (string)config('recaptcha.site_key') ?: null]);
