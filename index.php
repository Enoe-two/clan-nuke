<?php 
require_once 'config.php';

// Récupérer les paramètres d'apparence
$appearance = getAppearanceSettings($pdo);


$stats = [];
if ($appearance['show_stats_home'] == '1') {
    $stats = [
        'total_members' => $pdo->query("SELECT COUNT(*) FROM members")->fetchColumn(),
        'total_legions' => $pdo->query("SELECT COUNT(*) FROM legions")->fetchColumn(),
        'total_diplomes' => $pdo->query("SELECT COUNT(*) FROM diplomes")->fetchColumn()
    ];
}

// Derniers membres (si activés)
$latest_members = [];
if ($appearance['show_latest_members'] == '1') {
    $latest_members = $pdo->query("SELECT discord_pseudo, grade, created_at FROM members ORDER BY created_at DESC LIMIT 5")->fetchAll();
}

// Classe de fond selon le style choisi
$background_class = 'bg-gray-900';
if ($appearance['background_style'] == 'gradient') {
    $background_class = 'bg-gradient-to-b from-gray-900 to-gray-800';
} elseif ($appearance['background_style'] == 'pattern') {
    $background_class = 'bg-gray-900';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($appearance['site_title']); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($appearance['site_description']); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="css/all.min.css">
</head>
<body class="<?php echo $background_class; ?>">
    <?php include 'includes/header.php'; ?>
    
    <div class="min-h-screen">
        <div class="max-w-4xl mx-auto px-4 py-16 text-center">
            <!-- Logo et titre -->
            <div class="mb-8">
                <?php if ($appearance['logo_path']): ?>
                    <img src="uploads/<?php echo htmlspecialchars($appearance['logo_path']); ?>" 
                         alt="Logo CFWT" class="h-36 mx-auto mb-6">
                <?php else: ?>
                    <i class="fas fa-shield-alt text-9xl mb-6" style="color: <?php echo htmlspecialchars($appearance['primary_color']); ?>"></i>
                <?php endif; ?>
                
                <h1 class="text-6xl font-bold text-white mb-4">CFWT</h1>
                <h2 class="text-3xl mb-8" style="color: <?php echo htmlspecialchars($appearance['primary_color']); ?>">
                    Coalition Française de Wars Tycoon
                </h2>
                <p class="text-xl text-gray-300 mb-12 max-w-2xl mx-auto">
                    <?php echo htmlspecialchars($appearance['site_description']); ?>
                </p>
            </div>

            <!-- Discord -->
            <div class="bg-gray-800 rounded-lg p-6 mb-8 border-2" style="border-color: <?php echo htmlspecialchars($appearance['primary_color']); ?>">
                <p class="text-white text-lg mb-2">Rejoignez notre Discord :</p>
                <a href="<?php echo DISCORD_INVITE; ?>" target="_blank" rel="noopener noreferrer"
                   class="text-xl font-semibold underline hover:no-underline"
                   style="color: <?php echo htmlspecialchars($appearance['secondary_color']); ?>">
                    <?php echo DISCORD_INVITE; ?>
                </a>
            </div>

            <!-- Bouton principal -->
            <a href="recruitment.php" 
               class="inline-block text-white px-12 py-6 rounded-lg text-2xl font-bold transform hover:scale-105 transition shadow-2xl"
               style="background: linear-gradient(135deg, 
                   <?php echo htmlspecialchars($appearance['primary_color']); ?>, 
                   <?php echo htmlspecialchars($appearance['secondary_color']); ?>)">
                Nous Rejoindre
            </a>

            <!-- Statistiques (si activées) -->
            <?php if ($appearance['show_stats_home'] == '1' && !empty($stats)): ?>
                <div class="grid md:grid-cols-3 gap-8 mt-16">
                    <div class="bg-gray-800 p-6 rounded-lg">
                        <i class="fas fa-trophy text-6xl mb-4" style="color: <?php echo htmlspecialchars($appearance['accent_color']); ?>"></i>
                        <p class="text-4xl font-bold text-white mb-2"><?php echo $stats['total_members']; ?></p>
                        <p class="text-gray-400">Membres Actifs</p>
                    </div>
                    <div class="bg-gray-800 p-6 rounded-lg">
                        <i class="fas fa-shield-alt text-6xl mb-4" style="color: <?php echo htmlspecialchars($appearance['primary_color']); ?>"></i>
                        <p class="text-4xl font-bold text-white mb-2"><?php echo $stats['total_legions']; ?></p>
                        <p class="text-gray-400">Légions</p>
                    </div>
                    <div class="bg-gray-800 p-6 rounded-lg">
                        <i class="fas fa-graduation-cap text-6xl mb-4" style="color: <?php echo htmlspecialchars($appearance['secondary_color']); ?>"></i>
                        <p class="text-4xl font-bold text-white mb-2"><?php echo $stats['total_diplomes']; ?></p>
                        <p class="text-gray-400">Diplômes Disponibles</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Derniers membres (si activés) -->
            <?php if ($appearance['show_latest_members'] == '1' && !empty($latest_members)): ?>
                <div class="mt-16 bg-gray-800 p-8 rounded-lg">
                    <h3 class="text-3xl font-bold text-white mb-6">
                        <i class="fas fa-users mr-2" style="color: <?php echo htmlspecialchars($appearance['accent_color']); ?>"></i>
                        Derniers Membres
                    </h3>
                    <div class="grid md:grid-cols-5 gap-4">
                        <?php foreach ($latest_members as $member): ?>
                            <div class="bg-gray-700 p-4 rounded-lg">
                                <i class="fas fa-user-circle text-4xl mb-2" style="color: <?php echo htmlspecialchars($appearance['secondary_color']); ?>"></i>
                                <p class="text-white font-semibold"><?php echo htmlspecialchars($member['discord_pseudo']); ?></p>
                                <p class="text-gray-400 text-sm"><?php echo htmlspecialchars($member['grade']); ?></p>
                                <p class="text-gray-500 text-xs"><?php echo date('d/m/Y', strtotime($member['created_at'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Cartes d'informations -->
            <div class="grid md:grid-cols-3 gap-8 mt-16">
                <div class="bg-gray-800 p-6 rounded-lg">
                    <i class="fas fa-trophy text-6xl mb-4" style="color: <?php echo htmlspecialchars($appearance['accent_color']); ?>"></i>
                    <h3 class="text-xl font-bold text-white mb-2">Excellence</h3>
                    <p class="text-gray-400">Formation tactique et progression garantie</p>
                </div>
                <div class="bg-gray-800 p-6 rounded-lg">
                    <i class="fas fa-users text-6xl mb-4" style="color: <?php echo htmlspecialchars($appearance['secondary_color']); ?>"></i>
                    <h3 class="text-xl font-bold text-white mb-2">Communauté</h3>
                    <p class="text-gray-400">Une équipe soudée et organisée</p>
                </div>
                <div class="bg-gray-800 p-6 rounded-lg">
                    <i class="fas fa-shield-alt text-6xl mb-4" style="color: <?php echo htmlspecialchars($appearance['primary_color']); ?>"></i>
                    <h3 class="text-xl font-bold text-white mb-2">Stratégie</h3>
                    <p class="text-gray-400">Attaques coordonnées et défenses efficaces</p>
                </div>
            </div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
</body>
</html>

