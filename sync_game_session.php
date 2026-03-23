<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Créer/mettre à jour la table si besoin (ajout colonne admin_commands)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS game_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(255) UNIQUE NOT NULL,
            player_name VARCHAR(100) DEFAULT 'Invité',
            difficulty VARCHAR(20) NOT NULL DEFAULT 'medium',
            wave INT DEFAULT 1,
            money INT DEFAULT 600,
            base_health INT DEFAULT 100,
            max_base_health INT DEFAULT 100,
            score INT DEFAULT 0,
            kills INT DEFAULT 0,
            towers_count INT DEFAULT 0,
            enemies_alive INT DEFAULT 0,
            admin_commands LONGTEXT DEFAULT NULL,
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            is_active TINYINT(1) DEFAULT 1,
            INDEX idx_active (is_active),
            INDEX idx_last_update (last_update),
            INDEX idx_session (session_id)
        )
    ");

    // Ajouter la colonne admin_commands si elle n'existe pas encore
    try {
        $pdo->exec("ALTER TABLE game_sessions ADD COLUMN admin_commands LONGTEXT DEFAULT NULL");
    } catch (PDOException $e) {
        // Colonne déjà existante, on ignore
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');

// ──────────────────────────────────────────────
// POST : le jeu synchronise son état
// ──────────────────────────────────────────────
if ($method === 'POST' && $action === 'sync_session') {

    $sessionId   = trim($_POST['session_id'] ?? '');
    $playerName  = trim($_POST['player_name'] ?? 'Invité');
    $difficulty  = trim($_POST['difficulty']  ?? 'medium');
    $wave        = intval($_POST['wave']        ?? 1);
    $money       = intval($_POST['money']       ?? 600);
    $baseHealth  = intval($_POST['base_health'] ?? 100);
    $maxHealth   = intval($_POST['max_base_health'] ?? 100);
    $score       = intval($_POST['score']       ?? 0);
    $kills       = intval($_POST['kills']       ?? 0);
    $towers      = intval($_POST['towers_count'] ?? 0);
    $enemies     = intval($_POST['enemies_alive'] ?? 0);
    $isActive    = intval($_POST['is_active']   ?? 1);

    if (empty($sessionId)) {
        echo json_encode(['success' => false, 'message' => 'session_id requis']);
        exit;
    }

    try {
        // Vérifier si la session existe déjà
        $stmt = $pdo->prepare("SELECT id FROM game_sessions WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $exists = $stmt->fetchColumn();

        if ($exists) {
            $stmt = $pdo->prepare("
                UPDATE game_sessions SET
                    player_name    = ?,
                    difficulty     = ?,
                    wave           = ?,
                    money          = ?,
                    base_health    = ?,
                    max_base_health = ?,
                    score          = ?,
                    kills          = ?,
                    towers_count   = ?,
                    enemies_alive  = ?,
                    is_active      = ?,
                    last_update    = NOW()
                WHERE session_id = ?
            ");
            $stmt->execute([
                $playerName, $difficulty, $wave, $money,
                $baseHealth, $maxHealth, $score, $kills,
                $towers, $enemies, $isActive, $sessionId
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO game_sessions
                    (session_id, player_name, difficulty, wave, money,
                     base_health, max_base_health, score, kills,
                     towers_count, enemies_alive, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $sessionId, $playerName, $difficulty, $wave, $money,
                $baseHealth, $maxHealth, $score, $kills,
                $towers, $enemies, $isActive
            ]);
        }

        echo json_encode(['success' => true]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ──────────────────────────────────────────────
// GET : le jeu récupère les commandes admin
// ──────────────────────────────────────────────
if ($method === 'GET' && $action === 'get_commands') {

    $sessionId = trim($_GET['session_id'] ?? '');

    if (empty($sessionId)) {
        echo json_encode(['success' => false, 'message' => 'session_id requis']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT admin_commands
            FROM game_sessions
            WHERE session_id = ? AND is_active = 1
        ");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();

        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Session introuvable']);
            exit;
        }

        $commands = [];
        if (!empty($row['admin_commands'])) {
            $commands = json_decode($row['admin_commands'], true) ?? [];
        }

        // On retourne les commandes puis on les efface immédiatement
        if (!empty($commands)) {
            $stmt = $pdo->prepare("
                UPDATE game_sessions SET admin_commands = NULL
                WHERE session_id = ?
            ");
            $stmt->execute([$sessionId]);
        }

        echo json_encode(['success' => true, 'commands' => $commands]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action invalide']);
