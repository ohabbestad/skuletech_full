<?php
declare(strict_types=1);

/*
 * Lokal konfigurasjon for kantine-testen.
 *
 * På Domeneshop:
 * 1. Kopier config.local.example.php til config.local.php.
 * 2. Fyll inn databaseinfo og eit langt setup-passord.
 *
 * config.local.php er ignorert av git, slik at ekte passord ikkje blir med i koden.
 */

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    return require $localConfig;
}

return [
    'db_host' => 'localhost',
    'db_name' => 'FYLL_INN_DATABASE',
    'db_user' => 'FYLL_INN_BRUKAR',
    'db_pass' => 'FYLL_INN_PASSORD',
    'setup_key' => 'BYT_TIL_EIT_LANGT_HEMMELEG_OPPSETT_PASSORD',
    'session_name' => 'KANTINE_SESS',
    'session_lifetime_seconds' => 28800,
];
