<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$configPath = __DIR__ . '/config.php';
$config = file_exists($configPath)
    ? require $configPath
    : require __DIR__ . '/config.example.php';

try {
    $action = (string)($_POST['action'] ?? jsonInput()['action'] ?? '');
    if ($action === '') {
        respond(['success' => false, 'error' => 'Action manquante']);
    }

    $db = db($config);

    switch ($action) {
        case 'init_db':
            initDb($db);
            seedAgents($db, (string)$config['mistral_model']);
            respond(['success' => true, 'message' => 'Base initialisee']);

        case 'generate_agents':
            initDb($db);
            $created = seedAgents($db, (string)$config['mistral_model'], true);
            respond(['success' => true, 'message' => $created . ' agents disponibles']);

        case 'fetch_coingecko':
            initDb($db);
            $page = max(1, (int)($_POST['page'] ?? 1));
            $perPage = min(250, max(1, (int)($_POST['per_page'] ?? 100)));
            $saved = fetchCoinGecko($db, $config, $page, $perPage);
            respond(['success' => true, 'saved' => $saved, 'page' => $page]);

        case 'get_coins':
            initDb($db);
            respond(getCoins($db));

        case 'bulk_analyze':
            initDb($db);
            seedAgents($db, (string)$config['mistral_model']);
            respond(bulkAnalyze($db, $config));

        case 'get_stats':
            initDb($db);
            respond(getStats($db));

        case 'get_agents':
            initDb($db);
            seedAgents($db, (string)$config['mistral_model']);
            respond(['success' => true, 'agents' => getAgents($db)]);

        case 'rl_step':
            initDb($db);
            respond(runRlStep($db));

        case 'get_advice':
            initDb($db);
            respond(['success' => true, 'advice' => buildAdvice($db)]);

        case 'coin_detail':
            initDb($db);
            $coinId = (string)($_POST['coin_id'] ?? '');
            respond(getCoinDetail($db, $coinId));

        case 'get_analyses':
            initDb($db);
            $limit = min(100, max(1, (int)($_POST['limit'] ?? 30)));
            respond(['success' => true, 'analyses' => getAnalyses($db, $limit)]);

        default:
            respond(['success' => false, 'error' => 'Action inconnue: ' . $action]);
    }
} catch (Throwable $e) {
    logError($config, $e->getMessage());
    respond(['success' => false, 'error' => $e->getMessage()]);
}

function jsonInput(): array
{
    static $json = null;
    if ($json !== null) {
        return $json;
    }
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    $json = is_array($decoded) ? $decoded : [];
    return $json;
}

function respond(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function db(array $config): PDO
{
    $path = (string)$config['sqlite_path'];
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    return $pdo;
}

function initDb(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS coins (
            id TEXT PRIMARY KEY,
            symbol TEXT,
            name TEXT,
            current_price REAL DEFAULT 0,
            market_cap REAL DEFAULT 0,
            market_cap_rank INTEGER DEFAULT 999999,
            volume_24h REAL DEFAULT 0,
            pct_change_24h REAL DEFAULT 0,
            pct_change_7d REAL DEFAULT 0,
            ath REAL DEFAULT 0,
            ath_pct REAL DEFAULT 0,
            sparkline TEXT,
            updated_at INTEGER
        );

        CREATE TABLE IF NOT EXISTS agents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            role TEXT NOT NULL,
            model TEXT NOT NULL,
            generation INTEGER DEFAULT 1,
            prompt TEXT,
            total_analyses INTEGER DEFAULT 0,
            avg_score REAL DEFAULT 0,
            status TEXT DEFAULT 'ACTIVE',
            created_at INTEGER
        );

        CREATE TABLE IF NOT EXISTS analyses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coin_id TEXT NOT NULL,
            symbol TEXT,
            coin_name TEXT,
            agent_id INTEGER,
            agent_name TEXT,
            signal TEXT,
            score REAL,
            summary TEXT,
            rationale TEXT,
            raw_json TEXT,
            created_at INTEGER
        );

        CREATE TABLE IF NOT EXISTS rl_state (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            episode INTEGER DEFAULT 0,
            epsilon REAL DEFAULT 1,
            best_score REAL DEFAULT 0,
            total_reward REAL DEFAULT 0,
            regime TEXT DEFAULT 'UNKNOWN',
            advice TEXT,
            updated_at INTEGER
        );

        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );
    ");

    $stmt = $db->prepare('INSERT OR IGNORE INTO rl_state (id, updated_at) VALUES (1, :now)');
    $stmt->execute([':now' => time()]);
}

function seedAgents(PDO $db, string $model, bool $force = false): int
{
    $count = (int)$db->query('SELECT COUNT(*) FROM agents')->fetchColumn();
    if ($count > 0 && !$force) {
        return $count;
    }

    $agents = [
        ['Oracle Quantique', 'Prediction de tendance et momentum probabiliste', 'Analyse le momentum, les ruptures et les probabilites de continuation.'],
        ['Risk Sentinel', 'Detection de risque et anomalies de volatilite', 'Identifie les risques extremes, la surchauffe, les volumes suspects et les cassures.'],
        ['Correlation Desk', 'Correlation inter-actifs et regime de marche', 'Compare les signaux entre actifs et decrit le regime global du marche.'],
        ['Liquidity Mapper', 'Lecture volume, liquidite et zones energetiques', 'Evalue la force du carnet simule via volume, market cap et variation.'],
        ['Narrative Synth', 'Synthese claire pour decision achat attente vente', 'Transforme les signaux techniques en recommandation lisible.'],
    ];

    $insert = $db->prepare('
        INSERT INTO agents (name, role, model, generation, prompt, created_at)
        VALUES (:name, :role, :model, 1, :prompt, :created_at)
    ');

    foreach ($agents as $agent) {
        $check = $db->prepare('SELECT COUNT(*) FROM agents WHERE name = :name');
        $check->execute([':name' => $agent[0]]);
        if ((int)$check->fetchColumn() > 0) {
            continue;
        }
        $insert->execute([
            ':name' => $agent[0],
            ':role' => $agent[1],
            ':model' => $model,
            ':prompt' => $agent[2],
            ':created_at' => time(),
        ]);
    }

    return (int)$db->query('SELECT COUNT(*) FROM agents')->fetchColumn();
}

function fetchCoinGecko(PDO $db, array $config, int $page, int $perPage): int
{
    $url = rtrim((string)$config['coingecko_base_url'], '/') . '/coins/markets?' . http_build_query([
        'vs_currency' => 'usd',
        'order' => 'market_cap_desc',
        'per_page' => $perPage,
        'page' => $page,
        'sparkline' => 'true',
        'price_change_percentage' => '24h,7d',
    ]);

    $rows = httpGetJson($url, (int)$config['request_timeout']);
    if (!is_array($rows)) {
        throw new RuntimeException('Reponse CoinGecko invalide');
    }

    $stmt = $db->prepare('
        INSERT INTO coins (
            id, symbol, name, current_price, market_cap, market_cap_rank,
            volume_24h, pct_change_24h, pct_change_7d, ath, ath_pct, sparkline, updated_at
        ) VALUES (
            :id, :symbol, :name, :current_price, :market_cap, :market_cap_rank,
            :volume_24h, :pct_change_24h, :pct_change_7d, :ath, :ath_pct, :sparkline, :updated_at
        )
        ON CONFLICT(id) DO UPDATE SET
            symbol = excluded.symbol,
            name = excluded.name,
            current_price = excluded.current_price,
            market_cap = excluded.market_cap,
            market_cap_rank = excluded.market_cap_rank,
            volume_24h = excluded.volume_24h,
            pct_change_24h = excluded.pct_change_24h,
            pct_change_7d = excluded.pct_change_7d,
            ath = excluded.ath,
            ath_pct = excluded.ath_pct,
            sparkline = excluded.sparkline,
            updated_at = excluded.updated_at
    ');

    $saved = 0;
    foreach ($rows as $row) {
        if (empty($row['id'])) {
            continue;
        }
        $stmt->execute([
            ':id' => (string)$row['id'],
            ':symbol' => strtoupper((string)($row['symbol'] ?? '')),
            ':name' => (string)($row['name'] ?? ''),
            ':current_price' => (float)($row['current_price'] ?? 0),
            ':market_cap' => (float)($row['market_cap'] ?? 0),
            ':market_cap_rank' => (int)($row['market_cap_rank'] ?? 999999),
            ':volume_24h' => (float)($row['total_volume'] ?? 0),
            ':pct_change_24h' => (float)($row['price_change_percentage_24h'] ?? 0),
            ':pct_change_7d' => (float)($row['price_change_percentage_7d_in_currency'] ?? 0),
            ':ath' => (float)($row['ath'] ?? 0),
            ':ath_pct' => (float)($row['ath_change_percentage'] ?? 0),
            ':sparkline' => json_encode($row['sparkline_in_7d']['price'] ?? [], JSON_UNESCAPED_SLASHES),
            ':updated_at' => time(),
        ]);
        $saved++;
    }

    return $saved;
}

function getCoins(PDO $db): array
{
    $limit = min(100, max(1, (int)($_POST['limit'] ?? 50)));
    $offset = max(0, (int)($_POST['offset'] ?? 0));
    $filter = strtoupper(trim((string)($_POST['filter'] ?? '')));
    $allowedSort = ['market_cap_rank', 'current_price', 'pct_change_24h', 'volume_24h', 'market_cap', 'name'];
    $sort = in_array((string)($_POST['sort'] ?? ''), $allowedSort, true) ? (string)$_POST['sort'] : 'market_cap_rank';
    $order = strtoupper((string)($_POST['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

    $where = '';
    $params = [];
    if ($filter !== '') {
        $where = 'WHERE UPPER(c.symbol) LIKE :filter OR UPPER(c.name) LIKE :filter';
        $params[':filter'] = '%' . $filter . '%';
    }

    $totalStmt = $db->prepare("SELECT COUNT(*) FROM coins c $where");
    $totalStmt->execute($params);
    $total = (int)$totalStmt->fetchColumn();

    $sql = "
        SELECT
            c.*,
            (
                SELECT a.signal FROM analyses a
                WHERE a.coin_id = c.id
                ORDER BY a.created_at DESC, a.id DESC
                LIMIT 1
            ) AS last_signal,
            (
                SELECT AVG(a.score) FROM analyses a
                WHERE a.coin_id = c.id
            ) AS avg_score
        FROM coins c
        $where
        ORDER BY c.$sort $order
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return ['success' => true, 'coins' => $stmt->fetchAll(), 'total' => $total];
}

function bulkAnalyze(PDO $db, array $config): array
{
    $offset = max(0, (int)($_POST['offset'] ?? 0));
    $batchSize = min(20, max(1, (int)($_POST['batch_size'] ?? 8)));
    $total = (int)$db->query('SELECT COUNT(*) FROM coins')->fetchColumn();
    if ($total === 0) {
        return ['success' => false, 'error' => 'Aucune crypto en base. Lancez CoinGecko avant le bulk.'];
    }

    $stmt = $db->prepare('SELECT * FROM coins ORDER BY market_cap_rank ASC LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $coins = $stmt->fetchAll();

    $agents = getAgents($db);
    if (!$agents) {
        seedAgents($db, (string)$config['mistral_model']);
        $agents = getAgents($db);
    }

    $results = [];
    foreach ($coins as $index => $coin) {
        $agent = $agents[($offset + $index) % max(1, count($agents))];
        $analysis = analyzeCoin($config, $coin, $agent);
        saveAnalysis($db, $coin, $agent, $analysis);
        $results[] = [
            'coin_id' => $coin['id'],
            'symbol' => $coin['symbol'],
            'signal' => $analysis['signal'],
            'score' => (int)$analysis['score'],
            'summary' => $analysis['summary'],
        ];
    }

    $nextOffset = $offset + count($coins);
    $progress = $total > 0 ? min(100, (int)round(($nextOffset / $total) * 100)) : 100;

    return [
        'success' => true,
        'results' => $results,
        'offset' => $nextOffset,
        'total' => $total,
        'progress' => $progress,
        'done' => $nextOffset >= $total,
        'agent_name' => $agents[0]['name'] ?? 'Agent IA',
    ];
}

function analyzeCoin(array $config, array $coin, array $agent): array
{
    $apiKey = trim((string)$config['mistral_api_key']);
    if ($apiKey === '') {
        return heuristicAnalysis($coin, $agent, 'Heuristique locale: ajoutez MISTRAL_API_KEY pour activer Mistral.');
    }

    $prompt = buildMistralPrompt($coin, $agent);
    $payload = [
        'model' => (string)$config['mistral_model'],
        'messages' => [
            ['role' => 'system', 'content' => 'Tu es un agent IA de finance quantique crypto. Tu reponds uniquement en JSON valide.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.25,
        'max_tokens' => 700,
    ];

    try {
        $response = httpPostJson(
            'https://api.mistral.ai/v1/chat/completions',
            $payload,
            ['Authorization: Bearer ' . $apiKey],
            (int)$config['request_timeout']
        );
        $content = (string)($response['choices'][0]['message']['content'] ?? '');
        $parsed = parseJsonFromText($content);
        if ($parsed) {
            return normalizeAnalysis($parsed, $coin, 'Mistral');
        }
    } catch (Throwable $e) {
        return heuristicAnalysis($coin, $agent, 'Fallback local apres erreur Mistral: ' . $e->getMessage());
    }

    return heuristicAnalysis($coin, $agent, 'Fallback local: reponse Mistral non JSON.');
}

function buildMistralPrompt(array $coin, array $agent): string
{
    $sparkline = json_decode((string)($coin['sparkline'] ?? '[]'), true);
    $lastPoints = is_array($sparkline) ? array_slice($sparkline, -12) : [];
    return json_encode([
        'instruction' => 'Analyse cette crypto selon un prisme de finance quantique et retourne uniquement un JSON.',
        'schema' => [
            'signal' => 'BUY|SELL|HOLD|WATCH',
            'score' => 'nombre de 0 a 100',
            'summary' => 'resume executif court en francais',
            'rationale' => 'raisonnement clair en francais',
        ],
        'agent' => [
            'name' => $agent['name'] ?? 'Agent IA',
            'role' => $agent['role'] ?? '',
            'prompt' => $agent['prompt'] ?? '',
        ],
        'coin' => [
            'id' => $coin['id'],
            'symbol' => $coin['symbol'],
            'name' => $coin['name'],
            'price' => (float)$coin['current_price'],
            'market_cap' => (float)$coin['market_cap'],
            'rank' => (int)$coin['market_cap_rank'],
            'volume_24h' => (float)$coin['volume_24h'],
            'change_24h' => (float)$coin['pct_change_24h'],
            'change_7d' => (float)$coin['pct_change_7d'],
            'ath_distance' => (float)$coin['ath_pct'],
            'sparkline_last_points' => $lastPoints,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function heuristicAnalysis(array $coin, array $agent, string $note = ''): array
{
    $change24 = (float)($coin['pct_change_24h'] ?? 0);
    $change7d = (float)($coin['pct_change_7d'] ?? 0);
    $volume = (float)($coin['volume_24h'] ?? 0);
    $marketCap = max(1, (float)($coin['market_cap'] ?? 1));
    $liquidity = min(20, ($volume / $marketCap) * 100);
    $momentum = ($change24 * 2) + $change7d;
    $raw = 50 + ($momentum * 1.2) + $liquidity;
    $score = (int)max(0, min(100, round($raw)));

    if ($score >= 70 && $change24 > 0) {
        $signal = 'BUY';
    } elseif ($score <= 35 && $change24 < 0) {
        $signal = 'SELL';
    } elseif (abs($change24) < 1.5) {
        $signal = 'HOLD';
    } else {
        $signal = 'WATCH';
    }

    $symbol = (string)($coin['symbol'] ?? '?');
    return [
        'signal' => $signal,
        'score' => $score,
        'summary' => "$symbol: signal $signal avec score $score/100.",
        'rationale' => trim("Momentum 24h: " . round($change24, 2) . "%, 7j: " . round($change7d, 2) . "%. Ratio volume/capitalisation simule le niveau d'energie du marche. $note"),
        'source' => 'local',
        'agent_note' => $agent['name'] ?? '',
    ];
}

function normalizeAnalysis(array $data, array $coin, string $source): array
{
    $signal = strtoupper((string)($data['signal'] ?? 'WATCH'));
    if (!in_array($signal, ['BUY', 'SELL', 'HOLD', 'WATCH'], true)) {
        $signal = 'WATCH';
    }
    $score = (int)max(0, min(100, (float)($data['score'] ?? 50)));
    return [
        'signal' => $signal,
        'score' => $score,
        'summary' => (string)($data['summary'] ?? (($coin['symbol'] ?? 'Crypto') . ': analyse generee.')),
        'rationale' => (string)($data['rationale'] ?? 'Raisonnement non fourni.'),
        'source' => $source,
    ];
}

function saveAnalysis(PDO $db, array $coin, array $agent, array $analysis): void
{
    $stmt = $db->prepare('
        INSERT INTO analyses (
            coin_id, symbol, coin_name, agent_id, agent_name, signal, score, summary, rationale, raw_json, created_at
        ) VALUES (
            :coin_id, :symbol, :coin_name, :agent_id, :agent_name, :signal, :score, :summary, :rationale, :raw_json, :created_at
        )
    ');
    $stmt->execute([
        ':coin_id' => $coin['id'],
        ':symbol' => $coin['symbol'],
        ':coin_name' => $coin['name'],
        ':agent_id' => $agent['id'] ?? null,
        ':agent_name' => $agent['name'] ?? 'Agent IA',
        ':signal' => $analysis['signal'],
        ':score' => $analysis['score'],
        ':summary' => $analysis['summary'],
        ':rationale' => $analysis['rationale'],
        ':raw_json' => json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':created_at' => time(),
    ]);

    if (!empty($agent['id'])) {
        $update = $db->prepare('
            UPDATE agents
            SET
                total_analyses = (SELECT COUNT(*) FROM analyses WHERE agent_id = :id),
                avg_score = (SELECT COALESCE(AVG(score), 0) FROM analyses WHERE agent_id = :id)
            WHERE id = :id
        ');
        $update->execute([':id' => (int)$agent['id']]);
    }
}

function getAgents(PDO $db): array
{
    return $db->query('SELECT * FROM agents ORDER BY avg_score DESC, id ASC')->fetchAll();
}

function getAnalyses(PDO $db, int $limit): array
{
    $stmt = $db->prepare('SELECT * FROM analyses ORDER BY created_at DESC, id DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getStats(PDO $db): array
{
    $coins = (int)$db->query('SELECT COUNT(*) FROM coins')->fetchColumn();
    $agents = (int)$db->query('SELECT COUNT(*) FROM agents')->fetchColumn();
    $analyses = (int)$db->query('SELECT COUNT(*) FROM analyses')->fetchColumn();
    $avgScore = (float)$db->query('SELECT COALESCE(AVG(score), 0) FROM analyses WHERE created_at >= ' . (time() - 3600))->fetchColumn();
    $state = $db->query('SELECT * FROM rl_state WHERE id = 1')->fetch() ?: [];

    $signals = $db->query("
        SELECT signal, COUNT(*) AS cnt
        FROM analyses
        WHERE id IN (
            SELECT MAX(id) FROM analyses GROUP BY coin_id
        )
        GROUP BY signal
        ORDER BY cnt DESC
    ")->fetchAll();

    $regime = detectRegime($signals);
    $advice = $state['advice'] ?? buildAdvice($db);

    return [
        'success' => true,
        'coins' => $coins,
        'agents' => $agents,
        'analyses' => $analyses,
        'avg_score' => $avgScore ? round($avgScore, 1) : null,
        'episode' => (int)($state['episode'] ?? 0),
        'epsilon' => (float)($state['epsilon'] ?? 1),
        'best_score' => (float)($state['best_score'] ?? 0),
        'regime' => $regime,
        'advice' => $advice,
        'top_signals' => $signals,
    ];
}

function runRlStep(PDO $db): array
{
    $avg = (float)$db->query('SELECT COALESCE(AVG(score), 0) FROM analyses')->fetchColumn();
    $best = (float)$db->query('SELECT COALESCE(MAX(score), 0) FROM analyses')->fetchColumn();
    $state = $db->query('SELECT * FROM rl_state WHERE id = 1')->fetch() ?: [];
    $episode = ((int)($state['episode'] ?? 0)) + 1;
    $epsilon = max(0.05, ((float)($state['epsilon'] ?? 1)) * 0.96);
    $signals = $db->query('SELECT signal, COUNT(*) AS cnt FROM analyses GROUP BY signal')->fetchAll();
    $regime = detectRegime($signals);
    $advice = buildAdvice($db);

    $stmt = $db->prepare('
        INSERT INTO rl_state (id, episode, epsilon, best_score, total_reward, regime, advice, updated_at)
        VALUES (1, :episode, :epsilon, :best_score, :total_reward, :regime, :advice, :updated_at)
        ON CONFLICT(id) DO UPDATE SET
            episode = excluded.episode,
            epsilon = excluded.epsilon,
            best_score = excluded.best_score,
            total_reward = excluded.total_reward,
            regime = excluded.regime,
            advice = excluded.advice,
            updated_at = excluded.updated_at
    ');
    $stmt->execute([
        ':episode' => $episode,
        ':epsilon' => $epsilon,
        ':best_score' => $best,
        ':total_reward' => $avg,
        ':regime' => $regime,
        ':advice' => $advice,
        ':updated_at' => time(),
    ]);

    $mutations = [];
    if ($episode % 5 === 0) {
        $db->exec("UPDATE agents SET generation = generation + 1 WHERE avg_score < 45 AND total_analyses > 0");
        $mutations[] = 'Agents faibles mutés: generation +1';
    }

    return [
        'success' => true,
        'episode' => $episode,
        'epsilon' => $epsilon,
        'best_score' => $best,
        'total_reward' => $avg,
        'regime' => $regime,
        'advice' => $advice,
        'mutations' => $mutations,
    ];
}

function detectRegime(array $signals): string
{
    $map = [];
    foreach ($signals as $row) {
        $map[(string)$row['signal']] = (int)$row['cnt'];
    }
    $buy = $map['BUY'] ?? 0;
    $sell = $map['SELL'] ?? 0;
    $watch = $map['WATCH'] ?? 0;
    if ($buy >= ($sell + $watch) && $buy >= 3) {
        return 'BULL_STRONG';
    }
    if ($buy > $sell) {
        return 'BULL_MILD';
    }
    if ($sell >= ($buy + $watch) && $sell >= 3) {
        return 'BEAR_STRONG';
    }
    if ($sell > $buy) {
        return 'BEAR_MILD';
    }
    return ($buy + $sell + $watch) > 0 ? 'SIDEWAYS' : 'UNKNOWN';
}

function buildAdvice(PDO $db): string
{
    $latest = $db->query('SELECT signal, score, symbol FROM analyses ORDER BY created_at DESC, id DESC LIMIT 20')->fetchAll();
    if (!$latest) {
        return "Lancez d'abord une aspiration CoinGecko puis une analyse bulk pour obtenir un conseil IA.";
    }
    $buy = count(array_filter($latest, fn($row) => $row['signal'] === 'BUY'));
    $sell = count(array_filter($latest, fn($row) => $row['signal'] === 'SELL'));
    $avg = array_sum(array_map(fn($row) => (float)$row['score'], $latest)) / count($latest);
    if ($buy > $sell && $avg >= 60) {
        return "Regime constructif: privilégier les actifs avec signal BUY et score supérieur a 70, tout en fractionnant les entrees.";
    }
    if ($sell > $buy && $avg < 55) {
        return "Regime defensif: reduire l'exposition, surveiller les signaux SELL et attendre une stabilisation du momentum.";
    }
    return "Regime mixte: conserver les positions fortes, eviter le sur-trading et attendre confirmation sur volume.";
}

function getCoinDetail(PDO $db, string $coinId): array
{
    if ($coinId === '') {
        return ['success' => false, 'error' => 'coin_id manquant'];
    }
    $stmt = $db->prepare('SELECT * FROM coins WHERE id = :id');
    $stmt->execute([':id' => $coinId]);
    $coin = $stmt->fetch();
    if (!$coin) {
        return ['success' => false, 'error' => 'Crypto introuvable'];
    }

    $coin['sparkline'] = json_decode((string)($coin['sparkline'] ?? '[]'), true) ?: [];
    $stmt = $db->prepare('SELECT * FROM analyses WHERE coin_id = :id ORDER BY created_at DESC, id DESC LIMIT 10');
    $stmt->execute([':id' => $coinId]);
    $coin['analyses'] = $stmt->fetchAll();
    return ['success' => true, 'coin' => $coin];
}

function httpGetJson(string $url, int $timeout)
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => "Accept: application/json\r\nUser-Agent: QuantumNexus/1.0\r\n",
        ],
    ]);
    $raw = file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new RuntimeException('Requete HTTP GET echouee');
    }
    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

function httpPostJson(string $url, array $payload, array $headers, int $timeout): array
{
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'Accept: application/json';
    $rawPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $rawPayload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status >= 400) {
            throw new RuntimeException($error ?: 'HTTP ' . $status . ': ' . (string)$raw);
        }
        return json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => $timeout,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $rawPayload,
        ],
    ]);
    $raw = file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new RuntimeException('Requete HTTP POST echouee');
    }
    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

function parseJsonFromText(string $text): ?array
{
    $text = trim($text);
    $text = preg_replace('/^```json\s*|\s*```$/i', '', $text) ?? $text;
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    if (preg_match('/\{.*\}/s', $text, $match)) {
        $decoded = json_decode($match[0], true);
        return is_array($decoded) ? $decoded : null;
    }
    return null;
}

function logError(array $config, string $message): void
{
    $path = (string)($config['log_path'] ?? '');
    if ($path === '') {
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND);
}
