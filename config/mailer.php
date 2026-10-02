<?php
/**
 * SMTP Mail Configuration
 *
 * This file defines SMTP settings for PHPMailer.
 * It reads from environment variables first (production), then falls back
 * to the constants below (local development).
 *
 * -------------------------------------------------------------------------
 * SETUP INSTRUCTIONS — fill in your SMTP credentials below:
 * -------------------------------------------------------------------------
 * MAIL_HOST        — SMTP server hostname  e.g. 'smtp.gmail.com'
 * MAIL_PORT        — SMTP port             587 (TLS) or 465 (SSL)
 * MAIL_USERNAME    — SMTP login username / email address
 * MAIL_PASSWORD    — SMTP login password or App Password
 * MAIL_ENCRYPTION  — 'tls' (recommended) or 'ssl'
 * MAIL_FROM_ADDRESS— The From: address shown in sent emails
 * MAIL_FROM_NAME   — The From: display name shown in sent emails
 * -------------------------------------------------------------------------
 *
 * For Gmail:
 *   - Enable 2-Step Verification on the account
 *   - Generate an App Password at myaccount.google.com/apppasswords
 *   - Use MAIL_PORT = 587, MAIL_ENCRYPTION = 'tls'
 *
 * -------------------------------------------------------------------------
 * DO NOT commit real credentials to version control.
 * Add config/mailer.php to .gitignore if using Git.
 * -------------------------------------------------------------------------
 */

require_once __DIR__ . '/env.php';

return [
    'host'         => getenv('MAIL_HOST')         ?: 'smtp.gmail.com',
    'port'         => (int)(getenv('MAIL_PORT')   ?: 587),
    'username'     => getenv('MAIL_USERNAME')      ?: 'ncst.marine.academy@gmail.com',
    'password'     => getenv('MAIL_PASSWORD')      ?: 'cetwndaivkqzqhfw',
    'encryption'   => getenv('MAIL_ENCRYPTION')    ?: 'tls',
    'from_address' => getenv('MAIL_FROM_ADDRESS')  ?: 'ncst.marine.academy@gmail.com',
    'from_name'    => getenv('MAIL_FROM_NAME')     ?: 'NCST Maritime Academy',
    'verify_peer'  => getenv('MAIL_VERIFY_PEER') !== false
        ? filter_var(getenv('MAIL_VERIFY_PEER'), FILTER_VALIDATE_BOOLEAN)
        : false, // Set false for local dev / antivirus SSL inspection compatibility
];

