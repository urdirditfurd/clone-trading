<?php
declare(strict_types=1);

/*
 * Copiez ce fichier en config.php, puis renseignez votre clé Mistral.
 * Vous pouvez aussi définir MISTRAL_API_KEY dans les variables d'environnement
 * du serveur, ce qui évite de stocker la clé dans un fichier versionné.
 */

return [
    'mistral_api_key' => getenv('MISTRAL_API_KEY') ?: '',
    'mistral_model' => getenv('MISTRAL_MODEL') ?: 'mistral-small-latest',
    'coingecko_base_url' => 'https://api.coingecko.com/api/v3',
    'sqlite_path' => __DIR__ . '/data/quantum_nexus.sqlite',
    'log_path' => __DIR__ . '/logs/app.log',
    'request_timeout' => 30,
];
