<?php
declare(strict_types=1);

/*
 * Lokal konfigurasjon for tørrvarelageret.
 *
 * På serveren:
 * 1. Kopier denne strukturen til api/config.local.php.
 * 2. Fyll inn databaseinfo og eit langt setup-passord.
 *
 * config.local.php er ignorert av git, slik at passord ikkje blir publisert.
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
    'session_name' => 'LAGER_SESS',
    'mail_enabled' => false,
    'mail_from' => 'lager@skuletech.no',
    'mail_from_name' => 'SkuleTech Tørrvarelager',
    'mail_reply_to' => '',
    'app_url' => 'https://skuletech.no/ALF/lager/dashbord.html',
    'monthly_report_token' => 'BYT_TIL_EIT_LANGT_HEMMELEG_TOKEN',
    'timezone' => 'Europe/Oslo',
];
