<?php
declare(strict_types=1);

/*
 * Lokal konfigurasjon for utstyrslån.
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
    'session_name' => 'UTSTYR_SESS',
];
