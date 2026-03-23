<?php
require_once '../config.php';

// Sécurité : réservé à Enoe
if (!isLoggedIn() || strtolower($_SESSION['username']) !== 'enoe') {
    header('Location: ../login.php');
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action    = $_POST['action'];
    $sessionId = trim($_POST['session_id'] ?? '');

    if (empty($sessionId)) {
        echo json_encode(['success' => false, 'message' => 'session_id manquant']);
        exit;
    }

    try {
        // Vérifier que la session existe
        $stmt = $pdo->prepare("SELECT id, admin_commands FROM game_sessions WHERE session_id = ? AND is_active = 1");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();

        if (!$session) {
            echo json_encode(['success' => false, 'message' => 'Session introuvable ou inactive']);
            exit;
        }

        // Charger les commandes existantes (on les empile)
        $commands = [];
        if (!empty($session['admin_commands'])) {
            $commands = json_decode($session['admin_commands'], true) ?? [];
        }

        $label = '';

        switch ($action) {

            case 'add_money':
                $amount = intval($_POST['amount'] ?? 1000);
                $commands[] = ['type' => 'add_money', 'amount' => $amount];
                $label = "+{$amount} 💰 envoyé";
                break;

            case 'spawn_enemy':
                $enemyType = $_POST['enemy_type'] ?? 'normal';
                $count     = intval($_POST['count'] ?? 1);
                for ($i = 0; $i < $count; $i++) {
                    $commands[] = ['type' => 'spawn_enemy', 'enemy_type' => $enemyType];
                }
                $label = "{$count}x {$enemyType} spawné(s)";
                break;

            case 'kill_all':
                $commands[] = ['type' => 'kill_all'];
                $label = "Kill All envoyé";
                break;

            case 'heal_base':
                $commands[] = ['type' => 'heal_base'];
                $label = "Heal Base envoyé";
                break;

            case 'skip_wave':
                $commands[] = ['type' => 'skip_wave'];
                $label = "Skip Vague envoyé";
                break;

            case 'god_mode':
                $commands[] = ['type' => 'god_mode'];
                $label = "God Mode envoyé";
                break;

            case 'nuke':
                $commands[] = ['type' => 'nuke'];
                $label = "☢️ NUKE envoyé";
                break;

            case 'end_session':
                $stmt = $pdo->prepare("UPDATE game_sessions SET is_active = 0 WHERE session_id = ?");
                $stmt->execute([$sessionId]);
                echo json_encode(['success' => true, 'message' => 'Session terminée']);
                exit;

            default:
                echo json_encode(['success' => false, 'message' => 'Action inconnue']);
                exit;
        }

        // Sauvegarder les commandes
        $stmt = $pdo->prepare("UPDATE game_sessions SET admin_commands = ? WHERE session_id = ?");
        $stmt->execute([json_encode($commands), $sessionId]);

        echo json_encode(['success' => true, 'message' => $label]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ──────────────────────────────────────────────
// API JSON — polling GET depuis le panel
// ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'poll') {
    header('Content-Type: application/json');

    try {
        // Marquer inactives les sessions sans activité depuis 30s
        $pdo->exec("UPDATE game_sessions SET is_active = 0 WHERE last_update < DATE_SUB(NOW(), INTERVAL 30 SECOND) AND is_active = 1");

        $stmt = $pdo->query("
            SELECT session_id, player_name, difficulty, wave, money,
                   base_health, max_base_health, score, kills,
                   towers_count, enemies_alive, started_at, last_update
            FROM game_sessions
            WHERE is_active = 1
            ORDER BY last_update DESC
        ");
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'sessions' => $sessions, 'time' => date('H:i:s')]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

logAdminAction($pdo, $_SESSION['user_id'], 'Ouverture panel sessions de jeu');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Sessions de Jeu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/all.min.css">
    <style>
        body { background: #0f172a; }

        @keyframes pulse-border {
            0%, 100% { box-shadow: 0 0 0 2px rgba(34,197,94,0.4); }
            50%       { box-shadow: 0 0 0 4px rgba(34,197,94,0.8); }
        }
        .online { animation: pulse-border 2s infinite; }

        .session-card {
            transition: transform 0.2s, opacity 0.3s;
        }
        .session-card:hover { transform: translateY(-2px); }
        .session-card.removing { opacity: 0; transform: scale(0.95); }

        .diff-easy   { border-left: 4px solid #22c55e; }
        .diff-medium { border-left: 4px solid #eab308; }
        .diff-hard   { border-left: 4px solid #ef4444; }

        .hp-bar-fill { transition: width 0.5s ease; }

        #status-dot {
            width: 10px; height: 10px; border-radius: 50%;
            display: inline-block; margin-right: 6px;
            background: #22c55e;
            animation: pulse-border 1.5s infinite;
        }
        #status-dot.error { background: #ef4444; animation: none; }

        .toast {
            position: fixed; bottom: 24px; right: 24px;
            padding: 12px 20px; border-radius: 8px;
            font-weight: bold; color: white;
            z-index: 9999;
            animation: fadeInUp 0.3s ease;
        }
        @keyframes fadeInUp {
            from { opacity:0; transform:translateY(20px); }
            to   { opacity:1; transform:translateY(0); }
        }
    </style>
</head>
<body class="min-h-screen text-white">
    <?php include '../includes/header.php'; ?>

    <div class="max-w-7xl mx-auto px-4 py-10">

        <!-- En-tête -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-4xl font-bold flex items-center gap-3">
                    <i class="fas fa-crown text-yellow-400"></i>
                    Panel Admin — Sessions de Jeu
                </h1>
                <p class="text-gray-400 mt-1">
                    <span id="status-dot"></span>
                    <span id="status-text">Connexion en cours...</span>
                </p>
            </div>
            <a href="dashboard.php" class="bg-gray-700 hover:bg-gray-600 px-5 py-2 rounded-lg transition">
                <i class="fas fa-arrow-left mr-2"></i>Retour
            </a>
        </div>

        <!-- Stats globales -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-gray-800 rounded-xl p-5 text-center">
                <p class="text-4xl font-bold text-green-400" id="stat-sessions">—</p>
                <p class="text-gray-400 text-sm mt-1">Sessions actives</p>
            </div>
            <div class="bg-gray-800 rounded-xl p-5 text-center">
                <p class="text-4xl font-bold text-blue-400" id="stat-players">—</p>
                <p class="text-gray-400 text-sm mt-1">Joueurs uniques</p>
            </div>
            <div class="bg-gray-800 rounded-xl p-5 text-center">
                <p class="text-4xl font-bold text-purple-400" id="stat-score">—</p>
                <p class="text-gray-400 text-sm mt-1">Meilleur score</p>
            </div>
            <div class="bg-gray-800 rounded-xl p-5 text-center">
                <p class="text-4xl font-bold text-red-400" id="stat-wave">—</p>
                <p class="text-gray-400 text-sm mt-1">Vague max</p>
            </div>
        </div>

        <!-- Liste des sessions -->
        <div id="sessions-container">
            <!-- Rempli dynamiquement -->
            <div id="empty-state" class="hidden bg-gray-800 rounded-xl p-16 text-center">
                <i class="fas fa-ghost text-gray-600 text-8xl mb-6 block"></i>
                <h2 class="text-2xl font-bold text-white mb-2">Aucune session active</h2>
                <p class="text-gray-400">En attente de joueurs...</p>
            </div>
        </div>
    </div>

    <!-- Modal Spawn -->
    <div id="spawn-modal" class="hidden fixed inset-0 bg-black bg-opacity-80 z-50 flex items-center justify-center p-4">
        <div class="bg-gray-800 border border-gray-700 rounded-xl p-6 max-w-lg w-full">
            <h3 class="text-xl font-bold text-white mb-4">
                <i class="fas fa-user-ninja text-red-400 mr-2"></i>Spawner des ennemis
            </h3>
            <div class="grid grid-cols-2 gap-3 mb-4">
                <?php
                $enemies = [
                    ['normal',  'fa-user-ninja',      'text-red-400',    'Normal',   'bg-red-700'],
                    ['runner',  'fa-running',          'text-yellow-400', 'Runner',   'bg-yellow-700'],
                    ['tank',    'fa-shield',           'text-blue-400',   'Tank',     'bg-blue-700'],
                    ['aerial',  'fa-plane',            'text-cyan-400',   'Aérien',   'bg-cyan-700'],
                    ['boss',    'fa-dragon',           'text-purple-400', 'Boss',     'bg-purple-700'],
                    ['airboss', 'fa-plane-departure',  'text-indigo-400', 'Air Boss', 'bg-indigo-700'],
                    ['panda',   'fa-paw',              'text-pink-400',   'Panda 🐼', 'bg-pink-700'],
                ];
                foreach ($enemies as [$type, $icon, $color, $label, $bg]):
                ?>
                <button onclick="pickEnemy('<?php echo $type; ?>')"
                        class="<?php echo $bg; ?> hover:brightness-125 text-white p-4 rounded-lg transition flex items-center gap-3 font-bold">
                    <i class="fas <?php echo $icon; ?> <?php echo $color; ?> text-2xl"></i>
                    <?php echo $label; ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="flex items-center gap-3 mb-4">
                <label class="text-gray-400 text-sm whitespace-nowrap">Quantité :</label>
                <input type="range" id="spawn-count" min="1" max="20" value="1"
                       class="flex-1 accent-red-500"
                       oninput="document.getElementById('spawn-count-val').textContent = this.value">
                <span id="spawn-count-val" class="text-white font-bold w-6 text-center">1</span>
            </div>
            <button onclick="closeSpawnModal()"
                    class="w-full bg-gray-700 hover:bg-gray-600 text-white py-2 rounded-lg transition mt-2">
                Annuler
            </button>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
    // ── Config ───────────────────────────────
    const POLL_INTERVAL = 2000; // ms
    let currentSessionId = null;
    let pollTimer = null;
    let knownSessions = {};

    // ── Template d'une carte session ─────────
    function buildCard(s) {
        const hp       = Math.max(0, parseInt(s.base_health));
        const maxHp    = Math.max(1, parseInt(s.max_base_health));
        const hpPct    = Math.round((hp / maxHp) * 100);
        const hpColor  = hpPct > 60 ? 'bg-green-500' : hpPct > 30 ? 'bg-yellow-500' : 'bg-red-500';
        const diffMap  = { easy: ['bg-green-600','Facile'], medium: ['bg-yellow-600','Normal'], hard: ['bg-red-600','HARDCORE'] };
        const [diffBg, diffLabel] = diffMap[s.difficulty] || ['bg-gray-600', s.difficulty];

        const since    = timeSince(s.last_update);

        return `
        <div class="session-card bg-gray-800 rounded-xl overflow-hidden diff-${s.difficulty} online mb-6"
             id="card-${s.session_id}">

            <!-- Header -->
            <div class="flex justify-between items-center px-6 pt-5 pb-3">
                <div class="flex items-center gap-3">
                    <div class="bg-gray-700 rounded-lg p-3">
                        <i class="fas fa-user text-blue-400 text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-white flex items-center gap-2">
                            ${escHtml(s.player_name || 'Anonyme')}
                            <span class="text-xs font-normal bg-green-700 px-2 py-0.5 rounded-full flex items-center gap-1">
                                <span class="w-1.5 h-1.5 bg-white rounded-full animate-pulse inline-block"></span>
                                EN JEU
                            </span>
                        </h3>
                        <p class="text-gray-500 text-xs">Mise à jour il y a ${since}</p>
                    </div>
                </div>
                <span class="text-xs font-bold text-white px-3 py-1 rounded-full ${diffBg}">${diffLabel}</span>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-3 md:grid-cols-6 gap-2 px-6 pb-4">
                ${statBox(s.wave,          'Vague',   'text-blue-400')}
                ${statBox('💰 ' + s.money, 'Argent',  'text-yellow-400')}
                ${statBox(s.score,         'Score',   'text-purple-400')}
                ${statBox(s.kills,         'Kills',   'text-red-400')}
                ${statBox(s.towers_count,  'Tours',   'text-cyan-400')}
                ${statBox(s.enemies_alive, 'Ennemis', 'text-orange-400')}
            </div>

            <!-- Barre de vie -->
            <div class="px-6 pb-4">
                <div class="flex justify-between text-xs text-gray-400 mb-1">
                    <span>Vie de la base</span>
                    <span>${hp} / ${maxHp}</span>
                </div>
                <div class="w-full h-3 bg-gray-700 rounded-full overflow-hidden">
                    <div class="hp-bar-fill h-full ${hpColor} rounded-full" style="width:${hpPct}%"></div>
                </div>
            </div>

            <!-- Actions admin -->
            <div class="bg-gray-900 px-6 py-4">
                <p class="text-xs text-yellow-400 font-bold mb-3">
                    <i class="fas fa-crown mr-1"></i>COMMANDES ADMIN
                </p>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                    <button onclick="sendCommand('${s.session_id}', 'add_money', {amount:1000})"
                            class="bg-green-700 hover:bg-green-600 text-white px-3 py-2 rounded text-sm font-bold transition">
                        <i class="fas fa-money-bill-wave mr-1"></i>+1000 💰
                    </button>
                    <button onclick="openSpawnModal('${s.session_id}')"
                            class="bg-red-700 hover:bg-red-600 text-white px-3 py-2 rounded text-sm font-bold transition">
                        <i class="fas fa-user-ninja mr-1"></i>Spawn Ennemi
                    </button>
                    <button onclick="sendCommand('${s.session_id}', 'kill_all')"
                            class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded text-sm font-bold transition">
                        <i class="fas fa-skull mr-1"></i>Kill All
                    </button>
                    <button onclick="sendCommand('${s.session_id}', 'heal_base')"
                            class="bg-purple-700 hover:bg-purple-600 text-white px-3 py-2 rounded text-sm font-bold transition">
                        <i class="fas fa-heart mr-1"></i>Heal Base
                    </button>
                    <button onclick="sendCommand('${s.session_id}', 'skip_wave')"
                            class="bg-yellow-700 hover:bg-yellow-600 text-white px-3 py-2 rounded text-sm font-bold transition">
                        <i class="fas fa-forward mr-1"></i>Skip Vague
                    </button>
                    <button onclick="sendCommand('${s.session_id}', 'god_mode')"
                            class="bg-blue-700 hover:bg-blue-600 text-white px-3 py-2 rounded text-sm font-bold transition">
                        <i class="fas fa-shield-alt mr-1"></i>God Mode
                    </button>
                    <button onclick="sendCommand('${s.session_id}', 'nuke')"
                            class="bg-orange-700 hover:bg-orange-600 text-white px-3 py-2 rounded text-sm font-bold transition">
                        <i class="fas fa-radiation mr-1"></i>NUKE ☢️
                    </button>
                    <button onclick="confirmEnd('${s.session_id}')"
                            class="bg-gray-600 hover:bg-gray-500 text-white px-3 py-2 rounded text-sm font-bold transition">
                        <i class="fas fa-times-circle mr-1"></i>Terminer
                    </button>
                </div>
            </div>
        </div>`;
    }

    function statBox(val, label, color) {
        return `<div class="bg-gray-700 rounded-lg p-3 text-center">
            <p class="text-xl font-bold ${color}">${val}</p>
            <p class="text-xs text-gray-400">${label}</p>
        </div>`;
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function timeSince(dateStr) {
        const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000);
        if (diff < 5)  return 'à l\'instant';
        if (diff < 60) return diff + 's';
        return Math.floor(diff / 60) + 'min';
    }

    // ── Polling ───────────────────────────────
    async function poll() {
        try {
            const res  = await fetch('manage_game_sessions.php?action=poll&_=' + Date.now());
            const data = await res.json();

            if (!data.success) throw new Error(data.message);

            setStatus(true, `Actif — ${data.time}`);
            renderSessions(data.sessions);

        } catch (err) {
            setStatus(false, 'Erreur de connexion');
            console.error('Poll error:', err);
        }
    }

    function renderSessions(sessions) {
        const container = document.getElementById('sessions-container');
        const empty     = document.getElementById('empty-state');

        // Stats globales
        document.getElementById('stat-sessions').textContent = sessions.length;
        document.getElementById('stat-players').textContent  = new Set(sessions.map(s => s.player_name)).size;
        document.getElementById('stat-score').textContent    = sessions.length ? Math.max(...sessions.map(s => parseInt(s.score))) : 0;
        document.getElementById('stat-wave').textContent     = sessions.length ? Math.max(...sessions.map(s => parseInt(s.wave)))  : 0;

        if (sessions.length === 0) {
            empty.classList.remove('hidden');
            // Supprimer toutes les cartes existantes
            document.querySelectorAll('.session-card').forEach(el => el.remove());
            knownSessions = {};
            return;
        }
        empty.classList.add('hidden');

        const incomingIds = new Set(sessions.map(s => s.session_id));

        // Supprimer les sessions disparues
        for (const id of Object.keys(knownSessions)) {
            if (!incomingIds.has(id)) {
                const card = document.getElementById('card-' + id);
                if (card) {
                    card.classList.add('removing');
                    setTimeout(() => card.remove(), 300);
                }
                delete knownSessions[id];
            }
        }

        // Ajouter ou mettre à jour
        sessions.forEach(s => {
            const existing = document.getElementById('card-' + s.session_id);
            if (existing) {
                // Mise à jour légère : juste les valeurs numériques sans reconstruire toute la carte
                updateCardStats(s);
            } else {
                // Nouvelle carte
                const wrapper = document.createElement('div');
                wrapper.innerHTML = buildCard(s);
                container.appendChild(wrapper.firstElementChild);
            }
            knownSessions[s.session_id] = s;
        });
    }

    function updateCardStats(s) {
        const card = document.getElementById('card-' + s.session_id);
        if (!card) return;

        // Mise à jour des stats via les positions dans la grille
        const statVals = card.querySelectorAll('.text-xl.font-bold');
        const vals = [s.wave, '💰 ' + s.money, s.score, s.kills, s.towers_count, s.enemies_alive];
        statVals.forEach((el, i) => { if (vals[i] !== undefined) el.textContent = vals[i]; });

        // Barre de vie
        const hp    = Math.max(0, parseInt(s.base_health));
        const maxHp = Math.max(1, parseInt(s.max_base_health));
        const hpPct = Math.round((hp / maxHp) * 100);
        const bar   = card.querySelector('.hp-bar-fill');
        if (bar) {
            bar.style.width = hpPct + '%';
            bar.className = bar.className.replace(/bg-\w+-500/g, '');
            const col = hpPct > 60 ? 'bg-green-500' : hpPct > 30 ? 'bg-yellow-500' : 'bg-red-500';
            bar.classList.add(col);
        }

        // Timestamp
        const ts = card.querySelector('p.text-gray-500');
        if (ts) ts.textContent = 'Mise à jour il y a ' + timeSince(s.last_update);
    }

    function setStatus(ok, msg) {
        const dot  = document.getElementById('status-dot');
        const text = document.getElementById('status-text');
        dot.className = ok ? '' : 'error';
        // Réappliquer les styles inline car on écrase className
        dot.style.cssText = 'width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;background:' + (ok ? '#22c55e' : '#ef4444');
        text.textContent = msg;
    }

    // ── Commandes ─────────────────────────────
    async function sendCommand(sessionId, action, extra = {}) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('session_id', sessionId);
        for (const [k, v] of Object.entries(extra)) fd.append(k, v);

        try {
            const res  = await fetch('manage_game_sessions.php', { method: 'POST', body: fd });
            const data = await res.json();
            toast(data.success ? data.message : '❌ ' + data.message, data.success ? 'green' : 'red');
        } catch (err) {
            toast('❌ Erreur réseau', 'red');
        }
    }

    function confirmEnd(sessionId) {
        if (confirm('Terminer cette session de jeu ?')) {
            sendCommand(sessionId, 'end_session');
        }
    }

    // ── Modal Spawn ───────────────────────────
    function openSpawnModal(sessionId) {
        currentSessionId = sessionId;
        document.getElementById('spawn-modal').classList.remove('hidden');
    }

    function closeSpawnModal() {
        document.getElementById('spawn-modal').classList.add('hidden');
        currentSessionId = null;
    }

    function pickEnemy(type) {
        if (!currentSessionId) return;
        const count = parseInt(document.getElementById('spawn-count').value);
        sendCommand(currentSessionId, 'spawn_enemy', { enemy_type: type, count });
        closeSpawnModal();
    }

    document.getElementById('spawn-modal').addEventListener('click', e => {
        if (e.target === document.getElementById('spawn-modal')) closeSpawnModal();
    });

    // ── Toast ─────────────────────────────────
    function toast(msg, color = 'green') {
        const colors = { green: '#16a34a', red: '#dc2626', yellow: '#ca8a04', blue: '#2563eb' };
        const el = document.createElement('div');
        el.className = 'toast';
        el.style.background = colors[color] || colors.green;
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity 0.4s'; setTimeout(() => el.remove(), 400); }, 3000);
    }

    // ── Démarrage ─────────────────────────────
    poll();
    pollTimer = setInterval(poll, <?php echo POLL_INTERVAL ?? 2000; ?>);
    </script>
</body>
</html>
