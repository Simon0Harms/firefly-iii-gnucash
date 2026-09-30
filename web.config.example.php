<?php

// Optional settings for web.php: copy to web.config.php (next to web.php) and adjust.
// Every key can be left out; the values below are the defaults.

return [
    // Uploaded books, reports and logs, one directory per browser. Must NOT be reachable through
    // the web server (default: the system temp directory).
    'data_dir'        => sys_get_temp_dir().'/firefly-gnucash-web',

    // Fixed Firefly III URL, e.g. 'http://127.0.0.1' when Firefly runs on the same host.
    // Recommended with several users: the URL field is then read-only and the server does not
    // connect to addresses entered by users.
    'firefly_url'     => '',

    // PHP command line binary for the background jobs. Auto-detected: under Apache or PHP-FPM
    // the web server PHP is not a CLI, so e.g. /usr/bin/php8.4 is searched.
    'php_cli'         => '',

    // CA bundle for a Firefly with a self-signed certificate.
    'cacert'          => '',

    // Time zone for old GnuCash dates without neutral time (default: PHP setting or Europe/Berlin).
    'timezone'        => '',

    // Workspaces (and the browser cookie) not used for this long are deleted.
    'retention_hours' => 24,

    // Upload limit; PHP's upload_max_filesize and post_max_size must allow it too.
    'max_upload_mb'   => 200,
];
