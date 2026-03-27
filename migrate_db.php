<?php


$secret = getenv('MIGRATE_SECRET') ?: 'CFWT_MIGRATE'; // Change ici si tu veux
if (($_GET['secret'] ?? '') !== $secret) {
    http_response_code(403);
    die('<h2>⛔ Accès refusé — ajoute ?secret=<ton_secret> dans l\'URL</h2>');
}


// ⚠️  REMPLACE CES VALEURS par tes vraies DATABASE_URL Railway
$OLD_DATABASE_URL = getenv('OLD_DATABASE_URL') ?: 'mysql://root:JwaAIaqRIRzIGarebfqimmiKHDfnARiE@mysql.railway.internal:3306/railway';
$NEW_DATABASE_URL = getenv('NEW_DATABASE_URL') ?: getenv('DATABASE_URL') ?: '';

if (!$OLD_DATABASE_URL || !$NEW_DATABASE_URL) {
    die('<h2>❌ Configure OLD_DATABASE_URL et NEW_DATABASE_URL</h2>');
}

// ───────────────────────────────────────────────────────────
//  📋 TABLES À MIGRER (dans l'ordre pour respecter les FK)
// ───────────────────────────────────────────────────────────
$TABLES_ORDER = [
    'users',
    'legions',
    'diplomes',
    'members',
    'member_diplomes',
    'member_applications',
    'faction_applications',
    'faction_members',
    'admin_logs',
    'reports',
    'events',
    'event_participants',
    'game_sessions',
    'promotion_requests',
    'diplome_requests',
    'announcements',
    'maintenance_settings',
    'site_content',
    'messages',
    'message_logs',
];

// ───────────────────────────────────────────────────────────
//  🛠️  FONCTIONS UTILITAIRES
// ───────────────────────────────────────────────────────────

function parseDatabaseUrl(string $url): array {
    $p = parse_url($url);
    return [
        'host' => $p['host'],
        'port' => $p['port'] ?? 3306,
        'name' => ltrim($p['path'], '/'),
        'user' => $p['user'],
        'pass' => $p['pass'] ?? '',
    ];
}

function connectDb(array $cfg): PDO {
    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4";
    return new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
}

function log_line(string $msg, string $type = 'info'): void {
    $colors = ['info' => '#60a5fa', 'ok' => '#34d399', 'warn' => '#fbbf24', 'error' => '#f87171', 'title' => '#e879f9'];
    $color  = $colors[$type] ?? '#fff';
    echo "<div style='color:{$color};font-family:monospace;margin:2px 0'>{$msg}</div>";
    ob_flush(); flush();
}

// ───────────────────────────────────────────────────────────
//  🚀 DÉBUT DU SCRIPT
// ───────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>CFWT - Migration BDD</title>
<style>
  body { background:#0f172a; color:#e2e8f0; font-family:monospace; padding:2rem; }
  h1   { color:#e879f9; }
  h2   { color:#60a5fa; border-bottom:1px solid #334155; padding-bottom:.5rem; }
  .box { background:#1e293b; border-radius:8px; padding:1.5rem; margin:1rem 0; }
  .progress { background:#1e293b; border-radius:8px; padding:1rem; margin:.5rem 0; }
  .warn { background:#451a03; border-left:4px solid #f97316; padding:1rem; border-radius:4px; }
</style>
</head>
<body>
<h1>🚀 CFWT — Migration de base de données</h1>
<div class="warn">
  ⚠️ <strong>Supprime ce fichier dès que la migration est terminée !</strong><br>
  Il contient les identifiants de tes deux bases de données.
</div>
<div class="box">
<?php

$startTime = microtime(true);
$totalRows = 0;
$errors    = [];

// ── Connexion aux deux BDD ──
try {
    log_line('🔌 Connexion à l\'ancienne base de données...', 'info');
    $oldCfg = parseDatabaseUrl($OLD_DATABASE_URL);
    $oldPdo = connectDb($oldCfg);
    log_line("✅ Ancienne BDD connectée → {$oldCfg['host']}:{$oldCfg['port']}/{$oldCfg['name']}", 'ok');
} catch (PDOException $e) {
    log_line('❌ Impossible de se connecter à l\'ancienne BDD : ' . $e->getMessage(), 'error');
    die('</div></body></html>');
}

try {
    log_line('🔌 Connexion à la nouvelle base de données...', 'info');
    $newCfg = parseDatabaseUrl($NEW_DATABASE_URL);
    $newPdo = connectDb($newCfg);
    log_line("✅ Nouvelle BDD connectée → {$newCfg['host']}:{$newCfg['port']}/{$newCfg['name']}", 'ok');
} catch (PDOException $e) {
    log_line('❌ Impossible de se connecter à la nouvelle BDD : ' . $e->getMessage(), 'error');
    die('</div></body></html>');
}

echo '</div><div class="box">';
log_line('📋 Début de la migration...', 'title');
echo '<br>';

// ── Récupère les tables réellement présentes dans l'ancienne BDD ──
$existingTables = $oldPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
log_line('Tables trouvées dans l\'ancienne BDD : ' . implode(', ', $existingTables), 'info');
echo '<br>';

// Désactive les FK dans la nouvelle BDD pour éviter les conflits d'ordre
$newPdo->exec("SET FOREIGN_KEY_CHECKS = 0");

// ── Migration table par table ──
foreach ($TABLES_ORDER as $table) {

    if (!in_array($table, $existingTables)) {
        log_line("⏭️  {$table} — table absente dans l'ancienne BDD, ignorée.", 'warn');
        continue;
    }

    log_line("━━━ Migration de <strong>{$table}</strong> ━━━", 'title');

    // 1. Récupère le CREATE TABLE de l'ancienne BDD
    try {
        $createRow = $oldPdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
        $createSql = $createRow['Create Table'];

        // Supprime la table dans la nouvelle BDD si elle existe, puis recrée
        $newPdo->exec("DROP TABLE IF EXISTS `{$table}`");
        $newPdo->exec($createSql);
        log_line("  🔨 Structure recréée dans la nouvelle BDD.", 'info');
    } catch (PDOException $e) {
        $msg = "  ❌ Impossible de recréer la table {$table} : " . $e->getMessage();
        log_line($msg, 'error');
        $errors[] = $msg;
        continue;
    }

    // 2. Compte les lignes
    $count = (int)$oldPdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    log_line("  📊 {$count} ligne(s) à migrer.", 'info');

    if ($count === 0) {
        log_line("  ✅ Table vide — rien à copier.", 'ok');
        continue;
    }

    // 3. Copie les données par lots (évite les timeouts sur les grosses tables)
    $batchSize = 500;
    $offset    = 0;
    $copied    = 0;

    while ($offset < $count) {
        try {
            $rows = $oldPdo->query("SELECT * FROM `{$table}` LIMIT {$batchSize} OFFSET {$offset}")->fetchAll();
            if (empty($rows)) break;

            // Construit un INSERT multi-lignes
            $columns     = array_keys($rows[0]);
            $columnsList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));

            $insertSql = "INSERT INTO `{$table}` ({$columnsList}) VALUES ({$placeholders})
                          ON DUPLICATE KEY UPDATE " .
                          implode(', ', array_map(fn($c) => "`{$c}` = VALUES(`{$c}`)", $columns));

            $stmt = $newPdo->prepare($insertSql);

            foreach ($rows as $row) {
                $stmt->execute(array_values($row));
                $copied++;
            }

            $offset += $batchSize;
            log_line("  ↳ {$copied}/{$count} lignes copiées...", 'info');

        } catch (PDOException $e) {
            $msg = "  ❌ Erreur lors de la copie de {$table} (offset {$offset}) : " . $e->getMessage();
            log_line($msg, 'error');
            $errors[] = $msg;
            break;
        }
    }

    $totalRows += $copied;
    log_line("  ✅ {$copied} ligne(s) migrée(s) avec succès.", 'ok');
    echo '<br>';
}

// Réactive les FK
$newPdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// ── Résumé ──
$elapsed = round(microtime(true) - $startTime, 2);

echo '</div><div class="box">';
log_line('══════════════════════════════════', 'title');
log_line("🏁 Migration terminée en {$elapsed}s", 'title');
log_line("📦 Total lignes migrées : {$totalRows}", 'ok');

if (!empty($errors)) {
    log_line('⚠️ Erreurs rencontrées :', 'warn');
    foreach ($errors as $err) {
        log_line($err, 'error');
    }
} else {
    log_line('✅ Aucune erreur — migration réussie !', 'ok');
}

log_line('', 'info');
log_line('🗑️  <strong>Pense à supprimer ce fichier de ton serveur maintenant !</strong>', 'warn');
?>
</div>
</body>
</html>
