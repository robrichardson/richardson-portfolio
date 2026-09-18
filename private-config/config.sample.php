<?php
/**
 * Dropbox — private configuration.
 *
 * THIS FILE MUST NOT LIVE INSIDE public_html.
 * Upload it to:  /home/robric01/private/dropbox/config.php
 *
 * Rename from config.sample.php to config.php once filled in.
 */

return [

    // ---------------------------------------------------------------
    // Google OAuth — from console.cloud.google.com
    // ---------------------------------------------------------------
    'client_id'     => '',
    'client_secret' => '',

    // Leave blank. setup.php fills this in for you after you authorise.
    'refresh_token' => '',

    // Must match the Authorised redirect URI in the Google console exactly.
    'redirect_uri'  => 'https://robrichardson.co.uk/upload/setup.php',

    // ---------------------------------------------------------------
    // Access control
    // ---------------------------------------------------------------

    // The passphrase clients type before they can upload.
    // Generate with:  php -r "echo password_hash('your passphrase here', PASSWORD_DEFAULT);"
    // Set to null to make the drop box completely open (not recommended).
    'passphrase_hash' => null,

    // One-time key protecting setup.php, so a stranger can't hijack the
    // OAuth flow and point your drop box at their own Drive.
    // Make up a long random string. Change it if you ever re-run setup.
    'setup_key' => 'change-me-to-something-long-and-random',

    // ---------------------------------------------------------------
    // Behaviour
    // ---------------------------------------------------------------

    // Top-level folder created in your Drive. Everything nests under it.
    'root_folder_name' => 'Client Uploads',

    // Where the "you have files" email goes. null disables notifications.
    'notify_email' => 'robsclart@gmail.com',
    'notify_from'  => 'dropbox@robrichardson.co.uk',

    // Limits. Files go straight to Google, so these protect your Drive
    // quota rather than your server.
    'max_files_per_batch' => 25,
    'max_file_bytes'      => 5 * 1024 * 1024 * 1024,   // 5 GB per file
    'max_batch_bytes'     => 20 * 1024 * 1024 * 1024,  // 20 GB per drop

    // Extensions refused outright.
    'blocked_extensions' => ['php','phtml','phar','cgi','pl','exe','scr','bat','cmd','com','msi','vbs','js','jar','dll','sh'],

    // Failed passphrase attempts allowed per IP per hour.
    'max_attempts_per_hour' => 12,
];
