<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';

// Vérifier l'authentification et le rôle
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !in_array($_SESSION['user_role'], ['admin', 'gerant'])) {
    header('Location: /login.php');
    exit;
}

$db = new Database();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_nom'];
$user_role = $_SESSION['user_role'];

// Traitement des actions sur les catégories
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Créer une catégorie
        if (isset($_POST['creer_categorie'])) {
            $nom = trim($_POST['nom']);
            $description = trim($_POST['description'] ?? '');
            $est_sensible = isset($_POST['est_sensible']) ? 1 : 0;

            if (empty($nom)) {
                throw new Exception("Le nom de la catégorie est obligatoire.");
            }

            $stmt = $db->prepare("INSERT INTO categories (nom, description, est_sensible) VALUES (:nom, :description, :est_sensible)");
            $stmt->bindParam(':nom', $nom);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':est_sensible', $est_sensible);
            $stmt->execute();

            $_SESSION['success_message'] = "Catégorie créée avec succès!";
            header('Location: gerant.php');
            exit;
        }

        // Modifier une catégorie
        if (isset($_POST['modifier_categorie'])) {
            $id = $_POST['id'];
            $nom = trim($_POST['nom']);
            $description = trim($_POST['description'] ?? '');
            $est_sensible = isset($_POST['est_sensible']) ? 1 : 0;

            if (empty($nom)) {
                throw new Exception("Le nom de la catégorie est obligatoire.");
            }

            $stmt = $db->prepare("UPDATE categories SET nom = :nom, description = :description, est_sensible = :est_sensible WHERE id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':nom', $nom);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':est_sensible', $est_sensible);
            $stmt->execute();

            $_SESSION['success_message'] = "Catégorie modifiée avec succès!";
            header('Location: gerant.php');
            exit;
        }

        // Supprimer une catégorie
        if (isset($_POST['supprimer_categorie'])) {
            $id = $_POST['id'];

            // Vérifier si la catégorie est utilisée
            $stmt = $db->prepare("SELECT COUNT(*) FROM produits WHERE categorie_id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $count = $stmt->fetchColumn();

            if ($count > 0) {
                throw new Exception("Impossible de supprimer cette catégorie car elle est utilisée par des produits.");
            }

            $stmt = $db->prepare("DELETE FROM categories WHERE id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->execute();

            $_SESSION['success_message'] = "Catégorie supprimée avec succès!";
            header('Location: gerant.php');
            exit;
        }

    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
        header('Location: gerant.php');
        exit;
    }
}

// Gestion des requêtes AJAX pour les catégories
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'get_categorie' && isset($_GET['id'])) {
        header('Content-Type: application/json');
        $stmt = $db->prepare("SELECT * FROM categories WHERE id = :id");
        $stmt->bindParam(':id', $_GET['id']);
        $stmt->execute();
        $categorie = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($categorie ?: []);
        exit;
    }
}

// Récupérer les données pour le dashboard
try {
    // Récupérer les catégories
    $stmt = $db->prepare("SELECT * FROM categories ORDER BY nom");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // CA Mensuel
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_ttc), 0) as ca_mensuel FROM Ventes WHERE MONTH(date_vente) = MONTH(CURDATE()) AND YEAR(date_vente) = YEAR(CURDATE())");
    $stmt->execute();
    $ca_mensuel = (float)$stmt->fetchColumn();

    // CA Mois Précédent
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_ttc), 0) as ca_mois_precedent FROM Ventes WHERE MONTH(date_vente) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(date_vente) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))");
    $stmt->execute();
    $ca_mois_precedent = (float)$stmt->fetchColumn();

    // Évolution CA
    $evolution_ca = $ca_mois_precedent > 0 ? (($ca_mensuel - $ca_mois_precedent) / $ca_mois_precedent) * 100 : 0;

    // Marge Moyenne
    $stmt = $db->prepare("SELECT COALESCE(AVG(((prix_vente - prix_achat) / prix_achat) * 100), 0) as marge_moyenne FROM Produits WHERE prix_achat > 0");
    $stmt->execute();
    $marge_moyenne = (float)$stmt->fetchColumn();

    // Valeur Stock
    $stmt = $db->prepare("SELECT COALESCE(SUM(s.quantite_actuelle * p.prix_achat), 0) as valeur_stock FROM Stocks s JOIN Produits p ON s.produit_id = p.id WHERE s.quantite_actuelle > 0");
    $stmt->execute();
    $valeur_stock = (float)$stmt->fetchColumn();

    // Produits en Rupture
    $stmt = $db->prepare("SELECT COUNT(DISTINCT p.id) as nb_ruptures FROM Produits p LEFT JOIN Stocks s ON p.id = s.produit_id GROUP BY p.id HAVING COALESCE(SUM(s.quantite_actuelle), 0) = 0");
    $stmt->execute();
    $produits_rupture = (int)$stmt->fetchColumn();

    // Ventes aujourd'hui
    $stmt = $db->prepare("SELECT COUNT(*) as ventes_aujourdhui, COALESCE(SUM(total_ttc), 0) as ca_aujourdhui FROM Ventes WHERE DATE(date_vente) = CURDATE()");
    $stmt->execute();
    $ventes_aujourdhui_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $ventes_aujourdhui = (int)$ventes_aujourdhui_data['ventes_aujourdhui'];
    $ca_aujourdhui = (float)$ventes_aujourdhui_data['ca_aujourdhui'];

    // Produits à rotation lente
    $stmt = $db->prepare("SELECT COUNT(*) as rotation_lente FROM Produits p WHERE p.id NOT IN (SELECT DISTINCT vd.produit_id FROM VenteDetails vd JOIN Ventes v ON vd.vente_id = v.id WHERE v.date_vente >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))");
    $stmt->execute();
    $rotation_lente = (int)$stmt->fetchColumn();

    // Nombre total de produits
    $stmt = $db->prepare("SELECT COUNT(*) FROM Produits WHERE actif = 1");
    $stmt->execute();
    $total_produits = (int)$stmt->fetchColumn();

    // Nombre total de catégories
    $stmt = $db->prepare("SELECT COUNT(*) FROM categories");
    $stmt->execute();
    $total_categories = (int)$stmt->fetchColumn();

    // Ventes par catégorie
    $limit = 5;
    $stmt = $db->prepare("SELECT c.id, c.nom, SUM(v.total_ttc) as total, COUNT(DISTINCT v.id) as nb_ventes FROM Ventes v JOIN VenteDetails vd ON v.id = vd.vente_id JOIN Produits p ON vd.produit_id = p.id JOIN categories c ON p.categorie_id = c.id WHERE MONTH(v.date_vente) = MONTH(CURDATE()) AND YEAR(v.date_vente) = YEAR(CURDATE()) GROUP BY c.id, c.nom ORDER BY total DESC LIMIT :limit");
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $ventes_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top produits
    $stmt = $db->prepare("SELECT p.id, p.nom, p.reference, SUM(vd.quantite) as total_vendu, SUM(vd.quantite * vd.prix_vente_ht) as ca_total FROM VenteDetails vd JOIN Ventes v ON vd.vente_id = v.id JOIN Produits p ON vd.produit_id = p.id WHERE MONTH(v.date_vente) = MONTH(CURDATE()) AND YEAR(v.date_vente) = YEAR(CURDATE()) GROUP BY p.id, p.nom, p.reference ORDER BY total_vendu DESC LIMIT :limit");
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $top_produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Évolution CA
    $months = 6;
    $stmt = $db->prepare("SELECT DATE_FORMAT(date_vente, '%Y-%m') as mois, DATE_FORMAT(date_vente, '%M %Y') as mois_format, SUM(total_ttc) as ca, COUNT(*) as nb_ventes FROM Ventes WHERE date_vente >= DATE_SUB(CURDATE(), INTERVAL :months MONTH) GROUP BY DATE_FORMAT(date_vente, '%Y-%m'), DATE_FORMAT(date_vente, '%M %Y') ORDER BY mois ASC LIMIT :months");
    $stmt->bindParam(':months', $months, PDO::PARAM_INT);
    $stmt->execute();
    $evolution_ca_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Produits à rotation lente (détails)
    $limit_rotation = 10;
    $stmt = $db->prepare("SELECT p.id, p.nom, p.reference, COALESCE(SUM(s.quantite_actuelle), 0) as stock_actuel, MAX(v.date_vente) as derniere_vente FROM Produits p LEFT JOIN Stocks s ON p.id = s.produit_id LEFT JOIN VenteDetails vd ON p.id = vd.produit_id LEFT JOIN Ventes v ON vd.vente_id = v.id GROUP BY p.id, p.nom, p.reference HAVING derniere_vente IS NULL OR derniere_vente < DATE_SUB(CURDATE(), INTERVAL 30 DAY) ORDER BY derniere_vente ASC LIMIT :limit");
    $stmt->bindParam(':limit', $limit_rotation, PDO::PARAM_INT);
    $stmt->execute();
    $produits_rotation_lente = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Produits en expiration proche
    $jours = 30;
    $stmt = $db->prepare("SELECT p.nom, p.reference, s.numero_lot, s.date_expiration, s.quantite_actuelle, DATEDIFF(s.date_expiration, CURDATE()) as jours_restants FROM Stocks s JOIN Produits p ON s.produit_id = p.id WHERE s.date_expiration BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :jours DAY) AND s.quantite_actuelle > 0 ORDER BY s.date_expiration ASC LIMIT 10");
    $stmt->bindParam(':jours', $jours, PDO::PARAM_INT);
    $stmt->execute();
    $produits_expiration = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Produits performants pour recommandations
    $stmt = $db->prepare("SELECT p.nom, SUM(vd.quantite) as total_vendu FROM VenteDetails vd JOIN Produits p ON vd.produit_id = p.id JOIN Ventes v ON vd.vente_id = v.id WHERE v.date_vente >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY p.id, p.nom ORDER BY total_vendu DESC LIMIT 3");
    $stmt->execute();
    $produits_performants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Catégories en croissance
    $stmt = $db->prepare("SELECT c.nom, SUM(CASE WHEN v.date_vente >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN v.total_ttc ELSE 0 END) as ca_mois, SUM(CASE WHEN v.date_vente >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND v.date_vente < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN v.total_ttc ELSE 0 END) as ca_mois_precedent FROM Ventes v JOIN VenteDetails vd ON v.id = vd.vente_id JOIN Produits p ON vd.produit_id = p.id JOIN categories c ON p.categorie_id = c.id WHERE v.date_vente >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) GROUP BY c.id, c.nom HAVING ca_mois_precedent > 0 ORDER BY (ca_mois - ca_mois_precedent) / ca_mois_precedent DESC LIMIT 3");
    $stmt->execute();
    $categories_croissance = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erreur chargement données gérant: " . $e->getMessage());
    
    // Valeurs par défaut en cas d'erreur
    $categories = [];
    $ca_mensuel = 0;
    $ca_mois_precedent = 0;
    $evolution_ca = 0;
    $marge_moyenne = 0;
    $valeur_stock = 0;
    $produits_rupture = 0;
    $ventes_aujourdhui = 0;
    $ca_aujourdhui = 0;
    $rotation_lente = 0;
    $total_produits = 0;
    $total_categories = 0;
    $ventes_categories = [];
    $top_produits = [];
    $evolution_ca_data = [];
    $produits_rotation_lente = [];
    $produits_expiration = [];
    $produits_performants = [];
    $categories_croissance = [];
}

// Fonctions utilitaires pour le formatage
function formatMontant($montant) {
    return number_format($montant, 0, ',', ' ') . ' €';
}

function formatPourcentage($valeur) {
    return number_format($valeur, 1, ',', ' ') . '%';
}

function getEvolutionIcon($valeur) {
    if ($valeur > 0) return ['fas fa-arrow-up', 'text-green-600', 'bg-green-100'];
    if ($valeur < 0) return ['fas fa-arrow-down', 'text-red-600', 'bg-red-100'];
    return ['fas fa-minus', 'text-gray-600', 'bg-gray-100'];
}

function getJoursRestantsClass($jours) {
    if ($jours <= 7) return 'bg-red-100 text-red-800';
    if ($jours <= 15) return 'bg-orange-100 text-orange-800';
    return 'bg-yellow-100 text-yellow-800';
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Direction - Pharma Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <style>
        .sidebar { transition: all 0.3s ease; }
        .active { background-color: #10B981; color: white; }
        .sidebar-item:hover { background-color: #D1FAE5; }
        .chart-bar { transition: all 0.3s ease; }
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>

<body class="bg-gray-100" style="font-family: 'Montserrat', sans-serif;">
    <!-- Sidebar -->
    <div class="sidebar fixed inset-y-0 left-0 z-40 w-64 bg-white shadow-lg">
        <!-- Logo -->
        <div class="flex items-center justify-center p-6 border-b">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-indigo-600 rounded-full flex items-center justify-center">
                    <i class="fas fa-chart-line text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">NAGEX Pharma</h1>
                    <p class="text-xs text-indigo-600">Dashboard Direction</p>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="mt-6">
            <div class="px-4 space-y-2">
                <a href="#dashboard" onclick="showSection('dashboard')" class="active flex items-center px-4 py-3 text-gray-700 rounded-lg transition-colors">
                    <i class="fas fa-tachometer-alt w-6"></i>
                    <span class="ml-3 font-medium">Tableau de bord</span>
                </a>

                <!-- Configuration -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Configuration</p>
                    <a href="#categories" onclick="showSection('categories')" class="flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-tags w-6 text-indigo-500"></i>
                            <span class="ml-3 font-medium">Catégories</span>
                        </div>
                        <span class="bg-indigo-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $total_categories; ?></span>
                    </a>
                </div>

                <!-- Rapports financiers -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Rapports Financiers</p>
                    <a href="#finances" onclick="showSection('finances')" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <i class="fas fa-chart-bar w-6 text-blue-500"></i>
                        <span class="ml-3 font-medium">Analyse Financière</span>
                    </a>
                </div>

                <!-- Analyse stock -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Analyse Stock</p>
                    <a href="#stock" onclick="showSection('stock')" class="flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-boxes w-6 text-purple-500"></i>
                            <span class="ml-3 font-medium">Performance Stock</span>
                        </div>
                        <span class="bg-purple-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $rotation_lente; ?></span>
                    </a>
                </div>

                <!-- Décisions stratégiques -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Stratégie</p>
                    <a href="#strategie" onclick="showSection('strategie')" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <i class="fas fa-chess-queen w-6 text-orange-500"></i>
                        <span class="ml-3 font-medium">Décisions Stratégiques</span>
                    </a>
                </div>

                <!-- Performance commerciale -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Performance</p>
                    <a href="#performance" onclick="showSection('performance')" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <i class="fas fa-trophy w-6 text-green-500"></i>
                        <span class="ml-3 font-medium">Performance Commerciale</span>
                    </a>
                </div>
            </div>
        </nav>

        <!-- User Profile -->
        <div class="absolute bottom-0 left-0 right-0 p-4 border-t">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-chart-line text-indigo-600"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($user_name); ?></p>
                    <p class="text-xs text-indigo-600 truncate"><?php echo htmlspecialchars(ucfirst($user_role)); ?></p>
                </div>
                <a href="../utilisateurs/logout.php" class="text-gray-400 hover:text-red-500 transition-colors" title="Déconnexion">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="ml-64">
        <!-- Header -->
        <header class="bg-white shadow-sm">
            <div class="flex items-center justify-between px-8 py-4">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800" id="pageTitle">Tableau de bord Direction</h2>
                    <p class="text-gray-600">Analyse stratégique et prise de décision</p>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-sm text-gray-600">
                        <i class="fas fa-user-circle mr-2"></i>
                        Connecté en tant que <span class="font-semibold"><?php echo htmlspecialchars(ucfirst($user_role)); ?></span>
                    </div>
                    <div class="text-xs text-gray-500">
                        <?php echo date('d/m/Y H:i'); ?>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="p-8">
            <!-- Messages d'alerte -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6 fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-600 mr-2"></i>
                        <span class="text-green-800"><?php echo $_SESSION['success_message']; ?></span>
                    </div>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6 fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>
                        <span class="text-red-800"><?php echo $_SESSION['error_message']; ?></span>
                    </div>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Section Tableau de bord -->
            <div id="dashboard" class="section">
                <!-- Le contenu du dashboard reste le même -->
                <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-4 gap-6 mb-8">
                    <!-- Carte CA Mensuel -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-green-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">CA Mensuel</p>
                                <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo formatMontant($ca_mensuel); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-euro-sign text-green-600 text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4 flex items-center">
                            <?php list($icon, $textColor, $bgColor) = getEvolutionIcon($evolution_ca); ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $bgColor . ' ' . $textColor; ?>">
                                <i class="<?php echo $icon; ?> mr-1"></i>
                                <?php echo formatPourcentage(abs($evolution_ca)); ?>
                            </span>
                            <span class="text-xs text-gray-500 ml-2">vs mois précédent</span>
                        </div>
                    </div>

                    <!-- Les autres cartes du dashboard -->
                    <!-- ... (reprendre le contenu du dashboard précédent) ... -->
                </div>
            </div>

            <!-- Section Catégories -->
            <div id="categories" class="section hidden">
                <!-- Le contenu des catégories reste le même -->
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-900">Gestion des Catégories</h3>
                        <button onclick="openModal('createCategorieModal')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors">
                            <i class="fas fa-plus mr-2"></i>
                            Nouvelle Catégorie
                        </button>
                    </div>
                    <div class="p-6">
                        <!-- Liste des catégories -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sensible</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (!empty($categories)): ?>
                                        <?php foreach ($categories as $categorie): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-4 py-3 font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($categorie['nom']); ?>
                                                </td>
                                                <td class="px-4 py-3 text-gray-500 max-w-md truncate">
                                                    <?php echo htmlspecialchars($categorie['description'] ?: 'Aucune description'); ?>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <?php if ($categorie['est_sensible'] == 1): ?>
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                            <i class="fas fa-exclamation-triangle mr-1"></i>
                                                            Sensible
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                            <i class="fas fa-check mr-1"></i>
                                                            Standard
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div class="flex items-center space-x-2">
                                                        <button onclick="loadCategorie(<?php echo $categorie['id']; ?>)" class="text-blue-600 hover:text-blue-900 transition-colors" title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button onclick="confirmDelete(<?php echo $categorie['id']; ?>, '<?php echo addslashes($categorie['nom']); ?>')" class="text-red-600 hover:text-red-900 transition-colors" title="Supprimer">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                                <i class="fas fa-tags text-2xl mb-2"></i>
                                                <p>Aucune catégorie trouvée</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Finances -->
            <div id="finances" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Analyse Financière Détaillée</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <!-- Indicateurs financiers -->
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Indicateurs Clés</h4>
                                <div class="space-y-4">
                                    <div class="flex justify-between items-center p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <span class="font-medium text-gray-700">CA Mensuel</span>
                                            <p class="text-sm text-gray-500">Mois en cours</p>
                                        </div>
                                        <span class="text-lg font-bold text-green-600"><?php echo formatMontant($ca_mensuel); ?></span>
                                    </div>
                                    <div class="flex justify-between items-center p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <span class="font-medium text-gray-700">Marge Moyenne</span>
                                            <p class="text-sm text-gray-500">Sur tous les produits</p>
                                        </div>
                                        <span class="text-lg font-bold text-blue-600"><?php echo formatPourcentage($marge_moyenne); ?></span>
                                    </div>
                                    <div class="flex justify-between items-center p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <span class="font-medium text-gray-700">Valeur Stock</span>
                                            <p class="text-sm text-gray-500">Coût d'achat total</p>
                                        </div>
                                        <span class="text-lg font-bold text-purple-600"><?php echo formatMontant($valeur_stock); ?></span>
                                    </div>
                                    <div class="flex justify-between items-center p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <span class="font-medium text-gray-700">Évolution CA</span>
                                            <p class="text-sm text-gray-500">Vs mois précédent</p>
                                        </div>
                                        <span class="text-lg font-bold <?php echo $evolution_ca >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                            <?php echo formatPourcentage($evolution_ca); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Performances par catégorie -->
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Performance par Catégorie</h4>
                                <div class="space-y-3">
                                    <?php if (!empty($ventes_categories)): ?>
                                        <?php foreach ($ventes_categories as $cat): ?>
                                            <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg">
                                                <div class="flex items-center">
                                                    <div class="w-3 h-3 bg-green-500 rounded-full mr-3"></div>
                                                    <span class="font-medium text-gray-700"><?php echo htmlspecialchars($cat['nom']); ?></span>
                                                </div>
                                                <div class="text-right">
                                                    <div class="font-semibold text-gray-900"><?php echo formatMontant($cat['total']); ?></div>
                                                    <div class="text-xs text-gray-500"><?php echo $cat['nb_ventes']; ?> ventes</div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center py-4 text-gray-500">
                                            <i class="fas fa-chart-bar text-2xl mb-2"></i>
                                            <p>Aucune donnée financière</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Top produits financiers -->
                        <div class="mt-8">
                            <h4 class="text-lg font-semibold text-gray-900 mb-4">Top Produits par Chiffre d'Affaires</h4>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produit</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Référence</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantité</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">CA Total</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (!empty($top_produits)): ?>
                                            <?php foreach ($top_produits as $produit): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-4 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($produit['nom']); ?></td>
                                                    <td class="px-4 py-3 text-gray-500"><?php echo $produit['reference']; ?></td>
                                                    <td class="px-4 py-3 text-gray-500"><?php echo $produit['total_vendu']; ?> unités</td>
                                                    <td class="px-4 py-3 font-semibold text-green-600"><?php echo formatMontant($produit['ca_total']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                                    Aucun produit vendu ce mois
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Stock -->
            <div id="stock" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Analyse du Stock</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <!-- Produits à rotation lente -->
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Produits à Rotation Lente</h4>
                                <div class="space-y-3 max-h-96 overflow-y-auto">
                                    <?php if (!empty($produits_rotation_lente)): ?>
                                        <?php foreach ($produits_rotation_lente as $produit): ?>
                                            <div class="p-3 border border-gray-200 rounded-lg hover:bg-gray-50">
                                                <div class="flex justify-between items-start mb-2">
                                                    <span class="font-medium text-gray-900"><?php echo htmlspecialchars($produit['nom']); ?></span>
                                                    <span class="text-xs bg-yellow-100 text-yellow-800 px-2 py-1 rounded-full">
                                                        Stock: <?php echo $produit['stock_actuel']; ?>
                                                    </span>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    Réf: <?php echo $produit['reference']; ?>
                                                </div>
                                                <div class="text-xs text-red-500 mt-1">
                                                    <i class="fas fa-clock mr-1"></i>
                                                    <?php echo $produit['derniere_vente'] ? 'Dernière vente: ' . date('d/m/Y', strtotime($produit['derniere_vente'])) : 'Jamais vendu'; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center py-8 text-gray-500">
                                            <i class="fas fa-check-circle text-2xl mb-2 text-green-500"></i>
                                            <p>Aucun produit à rotation lente</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Produits en expiration proche -->
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Produits en Expiration Proche</h4>
                                <div class="space-y-3 max-h-96 overflow-y-auto">
                                    <?php if (!empty($produits_expiration)): ?>
                                        <?php foreach ($produits_expiration as $produit): ?>
                                            <div class="p-3 border border-gray-200 rounded-lg hover:bg-gray-50">
                                                <div class="flex justify-between items-start mb-2">
                                                    <span class="font-medium text-gray-900"><?php echo htmlspecialchars($produit['nom']); ?></span>
                                                    <span class="text-xs <?php echo getJoursRestantsClass($produit['jours_restants']); ?> px-2 py-1 rounded-full">
                                                        <?php echo $produit['jours_restants']; ?> jours
                                                    </span>
                                                </div>
                                                <div class="text-xs text-gray-500 space-y-1">
                                                    <div>Réf: <?php echo $produit['reference']; ?></div>
                                                    <div>Lot: <?php echo $produit['numero_lot']; ?></div>
                                                    <div>Stock: <?php echo $produit['quantite_actuelle']; ?> unités</div>
                                                    <div>Expire le: <?php echo date('d/m/Y', strtotime($produit['date_expiration'])); ?></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center py-8 text-gray-500">
                                            <i class="fas fa-check-circle text-2xl mb-2 text-green-500"></i>
                                            <p>Aucun produit en expiration proche</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Statistiques stock -->
                        <div class="mt-8 grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                                <div class="flex items-center">
                                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mr-4">
                                        <i class="fas fa-boxes text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-blue-900">Valeur Stock Total</p>
                                        <p class="text-2xl font-bold text-blue-600"><?php echo formatMontant($valeur_stock); ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-red-50 p-4 rounded-lg border border-red-200">
                                <div class="flex items-center">
                                    <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center mr-4">
                                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-red-900">Produits en Rupture</p>
                                        <p class="text-2xl font-bold text-red-600"><?php echo $produits_rupture; ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-orange-50 p-4 rounded-lg border border-orange-200">
                                <div class="flex items-center">
                                    <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center mr-4">
                                        <i class="fas fa-tachometer-alt text-orange-600"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-orange-900">Rotation Lente</p>
                                        <p class="text-2xl font-bold text-orange-600"><?php echo $rotation_lente; ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Stratégie -->
            <div id="strategie" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Décisions Stratégiques</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <!-- Produits performants -->
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Produits Stars</h4>
                                <div class="space-y-4">
                                    <?php if (!empty($produits_performants)): ?>
                                        <?php foreach ($produits_performants as $index => $produit): ?>
                                        <div class="p-4 border border-green-200 rounded-lg bg-green-50">
                                            <div class="flex items-center mb-2">
                                                <div class="w-8 h-8 bg-green-500 rounded-full flex items-center justify-center text-white font-bold mr-3">
                                                    <?php echo $index + 1; ?>
                                                </div>
                                                <span class="font-semibold text-green-900"><?php echo htmlspecialchars($produit['nom']); ?></span>
                                            </div>
                                            <div class="text-sm text-green-700">
                                                <i class="fas fa-chart-line mr-1"></i>
                                                <?php echo $produit['total_vendu']; ?> ventes (30 jours)
                                            </div>
                                            <div class="mt-2 text-xs text-green-600">
                                                <i class="fas fa-lightbulb mr-1"></i>
                                                Augmenter le stock et la visibilité
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center py-8 text-gray-500">
                                            <i class="fas fa-chart-line text-2xl mb-2"></i>
                                            <p>Données insuffisantes pour l'analyse</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Catégories en croissance -->
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Catégories Émergentes</h4>
                                <div class="space-y-4">
                                    <?php if (!empty($categories_croissance)): ?>
                                        <?php foreach ($categories_croissance as $categorie): 
                                            $croissance = $categorie['ca_mois_precedent'] > 0 ? 
                                                (($categorie['ca_mois'] - $categorie['ca_mois_precedent']) / $categorie['ca_mois_precedent']) * 100 : 0;
                                        ?>
                                        <div class="p-4 border border-blue-200 rounded-lg bg-blue-50">
                                            <div class="flex justify-between items-center mb-2">
                                                <span class="font-semibold text-blue-900"><?php echo htmlspecialchars($categorie['nom']); ?></span>
                                                <span class="text-sm font-bold <?php echo $croissance >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                                    <?php echo formatPourcentage($croissance); ?>
                                                </span>
                                            </div>
                                            <div class="text-sm text-blue-700">
                                                <i class="fas fa-euro-sign mr-1"></i>
                                                CA: <?php echo formatMontant($categorie['ca_mois']); ?>
                                            </div>
                                            <div class="mt-2 text-xs text-blue-600">
                                                <i class="fas fa-bullhorn mr-1"></i>
                                                Potentiel de croissance identifié
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center py-8 text-gray-500">
                                            <i class="fas fa-tags text-2xl mb-2"></i>
                                            <p>Données insuffisantes pour l'analyse</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Recommandations stratégiques -->
                        <div class="mt-8">
                            <h4 class="text-lg font-semibold text-gray-900 mb-4">Recommandations Stratégiques</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <!-- Recommandation 1 -->
                                <div class="p-4 bg-purple-50 border border-purple-200 rounded-lg">
                                    <div class="flex items-center mb-2">
                                        <i class="fas fa-box text-purple-600 mr-2"></i>
                                        <span class="font-semibold text-purple-900">Optimisation Stock</span>
                                    </div>
                                    <p class="text-sm text-purple-700">
                                        Réduire le stock des <?php echo $rotation_lente; ?> produits à rotation lente
                                    </p>
                                </div>

                                <!-- Recommandation 2 -->
                                <div class="p-4 bg-indigo-50 border border-indigo-200 rounded-lg">
                                    <div class="flex items-center mb-2">
                                        <i class="fas fa-chart-line text-indigo-600 mr-2"></i>
                                        <span class="font-semibold text-indigo-900">Focus Commercial</span>
                                    </div>
                                    <p class="text-sm text-indigo-700">
                                        Concentrer les efforts sur les produits performants
                                    </p>
                                </div>

                                <!-- Recommandation 3 -->
                                <div class="p-4 bg-teal-50 border border-teal-200 rounded-lg">
                                    <div class="flex items-center mb-2">
                                        <i class="fas fa-exclamation-triangle text-teal-600 mr-2"></i>
                                        <span class="font-semibold text-teal-900">Gestion Risques</span>
                                    </div>
                                    <p class="text-sm text-teal-700">
                                        <?php echo count($produits_expiration); ?> produits à surveiller pour expiration
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Performance -->
            <div id="performance" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Performance Commerciale</h3>
                    </div>
                    <div class="p-6">
                        <!-- KPI Performance -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                            <div class="bg-gradient-to-r from-green-500 to-green-600 p-4 rounded-lg text-white">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm opacity-90">CA Aujourd'hui</p>
                                        <p class="text-2xl font-bold"><?php echo formatMontant($ca_aujourdhui); ?></p>
                                    </div>
                                    <i class="fas fa-euro-sign text-2xl opacity-80"></i>
                                </div>
                                <div class="mt-2 text-xs opacity-90">
                                    <?php echo $ventes_aujourdhui; ?> ventes
                                </div>
                            </div>

                            <div class="bg-gradient-to-r from-blue-500 to-blue-600 p-4 rounded-lg text-white">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm opacity-90">Marge Moyenne</p>
                                        <p class="text-2xl font-bold"><?php echo formatPourcentage($marge_moyenne); ?></p>
                                    </div>
                                    <i class="fas fa-percent text-2xl opacity-80"></i>
                                </div>
                                <div class="mt-2 text-xs opacity-90">
                                    Sur tous les produits
                                </div>
                            </div>

                            <div class="bg-gradient-to-r from-purple-500 to-purple-600 p-4 rounded-lg text-white">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm opacity-90">Total Produits</p>
                                        <p class="text-2xl font-bold"><?php echo $total_produits; ?></p>
                                    </div>
                                    <i class="fas fa-boxes text-2xl opacity-80"></i>
                                </div>
                                <div class="mt-2 text-xs opacity-90">
                                    En catalogue actif
                                </div>
                            </div>

                            <div class="bg-gradient-to-r from-orange-500 to-orange-600 p-4 rounded-lg text-white">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm opacity-90">Taux Rotation</p>
                                        <p class="text-2xl font-bold">
                                            <?php echo $total_produits > 0 ? 
                                                round((($total_produits - $rotation_lente) / $total_produits) * 100) : 0; ?>%
                                        </p>
                                    </div>
                                    <i class="fas fa-sync-alt text-2xl opacity-80"></i>
                                </div>
                                <div class="mt-2 text-xs opacity-90">
                                    Produits actifs
                                </div>
                            </div>
                        </div>

                        <!-- Évolution du CA -->
                        <div class="mb-8">
                            <h4 class="text-lg font-semibold text-gray-900 mb-4">Évolution du Chiffre d'Affaires (6 derniers mois)</h4>
                            <div class="bg-gray-50 p-6 rounded-lg">
                                <?php if (!empty($evolution_ca_data)): ?>
                                    <div class="flex items-end justify-between h-48">
                                        <?php foreach ($evolution_ca_data as $mois): ?>
                                        <div class="flex flex-col items-center flex-1">
                                            <div class="text-xs text-gray-500 mb-2 text-center">
                                                <?php echo substr($mois['mois_format'], 0, 3); ?>
                                            </div>
                                            <div class="relative flex-1 w-full flex items-end">
                                                <div 
                                                    class="chart-bar bg-gradient-to-t from-indigo-500 to-indigo-600 rounded-t w-3/4 mx-auto hover:from-indigo-600 hover:to-indigo-700 cursor-pointer"
                                                    style="height: <?php echo max(10, ($mois['ca'] / max(array_column($evolution_ca_data, 'ca'))) * 90); ?>%"
                                                    title="<?php echo $mois['mois_format']; ?>: <?php echo formatMontant($mois['ca']); ?>"
                                                ></div>
                                            </div>
                                            <div class="text-xs text-gray-600 mt-2 text-center">
                                                <?php echo formatMontant($mois['ca']); ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-8 text-gray-500">
                                        <i class="fas fa-chart-line text-2xl mb-2"></i>
                                        <p>Aucune donnée d'évolution disponible</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Alertes et Actions -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- Alertes prioritaires -->
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Alertes Prioritaires</h4>
                                <div class="space-y-3">
                                    <?php if ($produits_rupture > 0): ?>
                                    <div class="flex items-center p-3 bg-red-50 border border-red-200 rounded-lg">
                                        <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                                        <div>
                                            <p class="font-medium text-red-900"><?php echo $produits_rupture; ?> produits en rupture</p>
                                            <p class="text-sm text-red-700">Réapprovisionnement urgent nécessaire</p>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($produits_expiration)): ?>
                                    <div class="flex items-center p-3 bg-orange-50 border border-orange-200 rounded-lg">
                                        <i class="fas fa-clock text-orange-500 mr-3"></i>
                                        <div>
                                            <p class="font-medium text-orange-900"><?php echo count($produits_expiration); ?> produits proches expiration</p>
                                            <p class="text-sm text-orange-700">Mettre en place des actions commerciales</p>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($rotation_lente > 0): ?>
                                    <div class="flex items-center p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                                        <i class="fas fa-tachometer-alt text-yellow-500 mr-3"></i>
                                        <div>
                                            <p class="font-medium text-yellow-900"><?php echo $rotation_lente; ?> produits à rotation lente</p>
                                            <p class="text-sm text-yellow-700">Envisager des promotions ou retrait</p>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($produits_rupture == 0 && empty($produits_expiration) && $rotation_lente == 0): ?>
                                    <div class="flex items-center p-3 bg-green-50 border border-green-200 rounded-lg">
                                        <i class="fas fa-check-circle text-green-500 mr-3"></i>
                                        <div>
                                            <p class="font-medium text-green-900">Aucune alerte prioritaire</p>
                                            <p class="text-sm text-green-700">Toutes les performances sont optimales</p>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Actions recommandées -->
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Actions Recommandées</h4>
                                <div class="space-y-3">
                                    <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                        <div class="flex items-center mb-2">
                                            <i class="fas fa-chart-line text-blue-500 mr-2"></i>
                                            <span class="font-medium text-blue-900">Analyser les tops vendeurs</span>
                                        </div>
                                        <p class="text-sm text-blue-700">
                                            Identifier les patterns des produits performants pour réplication
                                        </p>
                                    </div>

                                    <div class="p-3 bg-purple-50 border border-purple-200 rounded-lg">
                                        <div class="flex items-center mb-2">
                                            <i class="fas fa-users text-purple-500 mr-2"></i>
                                            <span class="font-medium text-purple-900">Former l'équipe</span>
                                        </div>
                                        <p class="text-sm text-purple-700">
                                            Partager les meilleures pratiques commerciales identifiées
                                        </p>
                                    </div>

                                    <div class="p-3 bg-teal-50 border border-teal-200 rounded-lg">
                                        <div class="flex items-center mb-2">
                                            <i class="fas fa-cogs text-teal-500 mr-2"></i>
                                            <span class="font-medium text-teal-900">Optimiser processus</span>
                                        </div>
                                        <p class="text-sm text-teal-700">
                                            Automatiser le suivi des indicateurs clés de performance
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Création Catégorie -->
            <div id="createCategorieModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Nouvelle Catégorie</h3>
                    </div>
                    <form method="POST" class="p-6">
                        <div class="space-y-4">
                            <div>
                                <label for="nom" class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                                <input type="text" id="nom" name="nom" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Nom de la catégorie">
                            </div>
                            <div>
                                <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                <textarea id="description" name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="Description de la catégorie (optionnel)"></textarea>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="est_sensible" name="est_sensible" class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                                <label for="est_sensible" class="ml-2 text-sm text-gray-700">Catégorie sensible (médicaments contrôlés)</label>
                            </div>
                        </div>
                        <div class="mt-6 flex justify-end space-x-3">
                            <button type="button" onclick="closeModal('createCategorieModal')" class="px-4 py-2 text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Annuler</button>
                            <button type="submit" name="creer_categorie" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">Créer la catégorie</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Modification Catégorie -->
            <div id="editCategorieModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Modifier la Catégorie</h3>
                    </div>
                    <form method="POST" class="p-6">
                        <input type="hidden" id="edit_id" name="id">
                        <div class="space-y-4">
                            <div>
                                <label for="edit_nom" class="block text-sm font-medium text-gray-700 mb-1">Nom *</label>
                                <input type="text" id="edit_nom" name="nom" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            <div>
                                <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                <textarea id="edit_description" name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"></textarea>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="edit_est_sensible" name="est_sensible" class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                                <label for="edit_est_sensible" class="ml-2 text-sm text-gray-700">Catégorie sensible (médicaments contrôlés)</label>
                            </div>
                        </div>
                        <div class="mt-6 flex justify-end space-x-3">
                            <button type="button" onclick="closeModal('editCategorieModal')" class="px-4 py-2 text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Annuler</button>
                            <button type="submit" name="modifier_categorie" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">Modifier la catégorie</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Formulaire de suppression caché -->
            <form id="deleteForm" method="POST" class="hidden">
                <input type="hidden" id="delete_id" name="id">
                <input type="hidden" name="supprimer_categorie" value="1">
            </form>

        </main>
    </div>

    <!-- Scripts JavaScript -->
    <script>
        // Navigation entre sections
        function showSection(sectionId) {
            // Masquer toutes les sections
            document.querySelectorAll('.section').forEach(section => {
                section.classList.add('hidden');
            });
            
            // Afficher la section sélectionnée
            document.getElementById(sectionId).classList.remove('hidden');
            
            // Mettre à jour la navigation active
            document.querySelectorAll('nav a').forEach(link => {
                link.classList.remove('active');
            });
            event.currentTarget.classList.add('active');
            
            // Mettre à jour le titre de la page
            const titles = {
                'dashboard': 'Tableau de bord Direction',
                'categories': 'Gestion des Catégories',
                'finances': 'Analyse Financière',
                'stock': 'Analyse du Stock',
                'strategie': 'Décisions Stratégiques',
                'performance': 'Performance Commerciale'
            };
            document.getElementById('pageTitle').textContent = titles[sectionId] || 'Tableau de bord Direction';
        }

        // Gestion des modales catégories
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        // Charger les données d'une catégorie pour modification
        async function loadCategorie(id) {
            try {
                const response = await fetch(`?action=get_categorie&id=${id}`);
                const categorie = await response.json();
                
                if (categorie) {
                    document.getElementById('edit_id').value = categorie.id;
                    document.getElementById('edit_nom').value = categorie.nom;
                    document.getElementById('edit_description').value = categorie.description || '';
                    document.getElementById('edit_est_sensible').checked = categorie.est_sensible == 1;
                    
                    openModal('editCategorieModal');
                }
            } catch (error) {
                console.error('Erreur:', error);
            }
        }

        // Confirmation de suppression
        function confirmDelete(id, nom) {
            if (confirm(`Êtes-vous sûr de vouloir supprimer la catégorie "${nom}" ?`)) {
                document.getElementById('delete_id').value = id;
                document.getElementById('deleteForm').submit();
            }
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            // Afficher la première section par défaut
            showSection('dashboard');
            
            // Fermer les modales en cliquant à l'extérieur
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeModal(this.id);
                    }
                });
            });
        });

        // Animation des graphiques
        function animateCharts() {
            document.querySelectorAll('.chart-bar').forEach((bar, index) => {
                setTimeout(() => {
                    bar.style.transform = 'scaleY(1)';
                }, index * 100);
            });
        }

        // Réanimer les graphiques quand on change de section
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    if (!mutation.target.classList.contains('hidden')) {
                        setTimeout(animateCharts, 100);
                    }
                }
            });
        });

        document.querySelectorAll('.section').forEach(section => {
            observer.observe(section, { attributes: true });
        });
    </script>
</body>
</html>