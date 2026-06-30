# clone-trading

# CoinGecko AI Mistral Quantique - Kit operationnel

Ce dossier contient les fichiers backend a copier dans votre projet pour rendre
l'interface crypto operationnelle avec CoinGecko, Mistral AI, SQLite et le mode
bulk.

## Fichiers

- `index.php` : routeur API PHP appele par le frontend via `const API = 'index.php'`.
- `config.example.php` : modele de configuration.
- `data/` : dossier de la base SQLite.
- `logs/` : dossier des journaux d'erreurs.

## Installation

1. Copiez le contenu de ce dossier a la racine de votre site, au meme niveau que
   votre fichier HTML.
2. Copiez `config.example.php` en `config.php`.
3. Ajoutez votre cle Mistral dans `config.php` :

```php
'mistral_api_key' => 'VOTRE_CLE_MISTRAL',
```

Vous pouvez aussi definir la variable d'environnement `MISTRAL_API_KEY`.

4. Assurez-vous que PHP peut ecrire dans :

```txt
data/
logs/
```

5. Lancez votre site via un serveur PHP :

```bash
php -S localhost:8000
```

Puis ouvrez :

```txt
http://localhost:8000
```

## Endpoints implementes

Le fichier `index.php` gere les actions deja attendues par le JavaScript :

- `init_db`
- `fetch_coingecko`
- `get_coins`
- `bulk_analyze`
- `get_stats`
- `get_agents`
- `generate_agents`
- `rl_step`
- `get_advice`
- `coin_detail`
- `get_analyses`

## Fonctionnement Mistral

Si `mistral_api_key` est configuree, chaque analyse bulk appelle :

```txt
https://api.mistral.ai/v1/chat/completions
```

Si aucune cle n'est configuree, le backend utilise une heuristique locale afin
de garder l'interface testable. Les resultats indiquent alors un fallback local.

## Sequence conseillee dans l'interface

1. Cliquer sur `INITIALISER`.
2. Cliquer sur `ASPIRER COINGECKO`.
3. Cliquer sur `ANALYSER EN BULK`.
4. Consulter les onglets `CRYPTOS`, `ANALYSES` et `AGENTS`.
5. Lancer `RENFORCEMENT` si besoin.

## Pre-requis serveur

- PHP 8.1 ou plus recent.
- Extension PDO SQLite activee.
- Acces HTTP sortant vers CoinGecko et Mistral.
