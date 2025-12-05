<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';

// Vérifier l'authentification et le rôle
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'stockiste') {
    header('Location: ../utilisateurs/login.php');
    exit;
}

$db = new Database();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_nom'];
$user_role = $_SESSION['user_role'];

// Initialiser toutes les variables
$produits_data = $lots_data = $alertes_stock_data = $alertes_expiration_data = $inventaires_data = $mouvements_data = [];
$produits_total = $lots_total = $alertes_stock_count = $alertes_expiration_count = $inventaires_en_cours = 0;

// Fonction helper pour formater les dates
function formatDate($date)
{
    if (empty($date) || $date === '0000-00-00 00:00:00') {
        return 'Non définie';
    }
    return date('d/m/Y H:i', strtotime($date));
}

// Récupérer les données selon le cahier des charges
try {
    // 1. LISTE COMPLÈTE DES PRODUITS AVEC STOCK
    $stmt_produits = $db->prepare("
        SELECT p.*, c.nom as categorie_nom, f.nom_societe as fournisseur_nom,
               COALESCE(SUM(l.quantite_actuelle), 0) as stock_total,
               COUNT(l.id) as nb_lots
        FROM produits p
        JOIN categories c ON p.categorie_id = c.id
        JOIN fournisseurs f ON p.fournisseur_id = f.id
        LEFT JOIN lots l ON p.id = l.produit_id AND l.statut = 'en_stock'
        WHERE p.statut = 'actif'
        GROUP BY p.id
        ORDER BY p.nom
    ");
    $stmt_produits->execute();
    $produits_data = $stmt_produits->fetchAll(PDO::FETCH_ASSOC);
    $produits_total = count($produits_data);

    // 2. GESTION DES LOTS
    $stmt_lots = $db->prepare("
        SELECT l.*, p.nom as produit_nom, p.code_barre, p.description,
               f.nom_societe as fournisseur_nom,
               DATEDIFF(l.date_expiration, CURDATE()) as jours_restants
        FROM lots l
        JOIN produits p ON l.produit_id = p.id
        JOIN fournisseurs f ON p.fournisseur_id = f.id
        WHERE l.quantite_actuelle > 0
        ORDER BY l.date_expiration ASC, l.date_reception DESC
    ");
    $stmt_lots->execute();
    $lots_data = $stmt_lots->fetchAll(PDO::FETCH_ASSOC);
    $lots_total = count($lots_data);

    // 3. ALERTES STOCK BAS (avec seuil fixe de 10 unités)
    $stmt_alertes_stock = $db->prepare("
        SELECT p.*, c.nom as categorie_nom,
               COALESCE(SUM(l.quantite_actuelle), 0) as stock_actuel,
               10 as seuil_alerte,
               (10 - COALESCE(SUM(l.quantite_actuelle), 0)) as deficit
        FROM produits p
        JOIN categories c ON p.categorie_id = c.id
        LEFT JOIN lots l ON p.id = l.produit_id AND l.statut = 'en_stock'
        WHERE p.statut = 'actif'
        GROUP BY p.id
        HAVING stock_actuel > 0 AND stock_actuel <= 10
        ORDER BY deficit DESC
    ");
    $stmt_alertes_stock->execute();
    $alertes_stock_data = $stmt_alertes_stock->fetchAll(PDO::FETCH_ASSOC);
    $alertes_stock_count = count($alertes_stock_data);

    // 4. PRODUITS EN RUPTURE DE STOCK
    $stmt_rupture = $db->prepare("
        SELECT p.*, c.nom as categorie_nom,
               COALESCE(SUM(l.quantite_actuelle), 0) as stock_actuel
        FROM produits p
        JOIN categories c ON p.categorie_id = c.id
        LEFT JOIN lots l ON p.id = l.produit_id AND l.statut = 'en_stock'
        WHERE p.statut = 'actif'
        GROUP BY p.id
        HAVING stock_actuel = 0
        ORDER BY p.nom
    ");
    $stmt_rupture->execute();
    $rupture_data = $stmt_rupture->fetchAll(PDO::FETCH_ASSOC);
    $rupture_count = count($rupture_data);

    // 5. ALERTES EXPIRATION (30 jours)
    $stmt_alertes_expiration = $db->prepare("
        SELECT l.*, p.nom as produit_nom, p.code_barre, p.description,
               DATEDIFF(l.date_expiration, CURDATE()) as jours_restants
        FROM lots l
        JOIN produits p ON l.produit_id = p.id
        WHERE l.date_expiration BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        AND l.quantite_actuelle > 0
        AND l.statut = 'en_stock'
        ORDER BY l.date_expiration ASC
    ");
    $stmt_alertes_expiration->execute();
    $alertes_expiration_data = $stmt_alertes_expiration->fetchAll(PDO::FETCH_ASSOC);
    $alertes_expiration_count = count($alertes_expiration_data);

    // 6. MOUVEMENTS RÉCENTS (7 derniers jours)
    $stmt_mouvements = $db->prepare("
        SELECT m.*, p.nom as produit_nom, l.numero_lot, u.nom as utilisateur_nom,
               DATE_FORMAT(m.created_at, '%d/%m/%Y %H:%i') as date_formatee
        FROM mouvements_stock m
        JOIN produits p ON m.produit_id = p.id
        JOIN lots l ON m.lot_id = l.id
        JOIN utilisateurs u ON m.created_by = u.id
        WHERE m.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY m.created_at DESC
        LIMIT 20
    ");
    $stmt_mouvements->execute();
    $mouvements_data = $stmt_mouvements->fetchAll(PDO::FETCH_ASSOC);

    // 7. STATISTIQUES POUR LES BADGES
    $receptions_attente = 0; // À adapter selon vos besoins
    $produits_rupture = $rupture_count;
    $alertes_stock_bas = $alertes_stock_count;
    $inventaires_cours = 0; // À adapter si vous avez une table inventaires


} catch (PDOException $e) {
    error_log("Erreur base de données stockiste: " . $e->getMessage());
    $_SESSION['error_message'] = "Erreur de chargement des données: " . $e->getMessage();
}

// TRAITEMENT DES FORMULAIRES
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 1. AJOUT/MODIFICATION DE LOT
        if (isset($_POST['ajouter_lot'])) {
            $produit_id = (int) $_POST['produit_id'];
            $numero_lot = trim($_POST['numero_lot']);
            $quantite_initiale = (int) $_POST['quantite_initiale'];
            $statut = $_POST['statut'];
            $date_expiration = $_POST['date_expiration'];
            $prix_achat = (float) $_POST['prix_achat'];
            $date_reception = $_POST['date_reception'] ?? date('Y-m-d');
            // VALIDATION MÉTIER
            $erreurs = [];

            // Validation cohérence statut/quantité
            if (($statut === 'vide' || $statut === 'epuise') && $quantite_initiale > 0) {
                $erreurs[] = "Un lot 'Vide' ou 'Épuisé' ne peut pas avoir une quantité positive.";
            }

            if ($statut === 'en_stock' && $quantite_initiale <= 0) {
                $erreurs[] = "Un lot 'En stock' doit avoir une quantité positive.";
            }

            // Validation date d'expiration
            if (strtotime($date_expiration) < strtotime(date('Y-m-d'))) {
                $erreurs[] = "La date d'expiration ne peut pas être dans le passé.";
            }

            // Si pas d'erreurs, procéder à l'insertion
            if (empty($erreurs)) {
                try {
                    $stmt = $db->prepare("
                INSERT INTO lots (produit_id, numero_lot, quantite_initiale, quantite_actuelle, 
                                date_expiration, prix_achat, date_reception, statut, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
                    $stmt->execute([
                        $produit_id,
                        $numero_lot,
                        $quantite_initiale,
                        $quantite_initiale,
                        $date_expiration,
                        $prix_achat,
                        $date_reception,
                        $statut,
                        $user_id
                    ]);

                    $_SESSION['success_message'] = "Lot ajouté avec succès! Statut: " . $statut;
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;

                } catch (PDOException $e) {
                    $_SESSION['error_message'] = "Erreur lors de l'ajout du lot: " . $e->getMessage();
                }
            } else {
                // Afficher les erreurs
                $_SESSION['error_message'] = "Erreurs de validation:<br>" . implode("<br>", $erreurs);
            }
        }

        // 2. MODIFICATION STATUT LOT
        if (isset($_POST['modifier_statut_lot'])) {
            $lot_id = (int) $_POST['lot_id'];
            $nouveau_statut = $_POST['nouveau_statut'];

            $stmt = $db->prepare("UPDATE lots SET statut = ? WHERE id = ?");
            $stmt->execute([$nouveau_statut, $lot_id]);

            $_SESSION['success_message'] = "Statut du lot mis à jour!";
        }

        // 3. CRÉATION INVENTAIRE
        if (isset($_POST['creer_inventaire'])) {
            $type_inventaire = $_POST['type_inventaire'];
            $reference = 'INV-' . date('Ymd-His');

            $stmt = $db->prepare("
                INSERT INTO inventaires (reference, type_inventaire, created_by, statut)
                VALUES (?, ?, ?, 'en_cours')
            ");
            $stmt->execute([$reference, $type_inventaire, $user_id]);
            $inventaire_id = $db->lastInsertId();

            // Ajouter les lignes d'inventaire selon le type
            if ($type_inventaire === 'complet') {
                $stmt_lignes = $db->prepare("
                    INSERT INTO lignes_inventaire (inventaire_id, produit_id, lot_id, quantite_theorique, quantite_reelle)
                    SELECT ?, l.produit_id, l.id, l.quantite_actuelle, l.quantite_actuelle
                    FROM lots l
                    WHERE l.statut = 'en_stock' AND l.quantite_actuelle > 0
                ");
                $stmt_lignes->execute([$inventaire_id]);
            }

            $_SESSION['success_message'] = "Inventaire #$reference créé avec succès!";
        }

        // 4. AJUSTEMENT STOCK
        if (isset($_POST['ajuster_stock'])) {
            $lot_id = (int) $_POST['lot_id'];
            $nouvelle_quantite = (int) $_POST['nouvelle_quantite'];
            $raison = trim($_POST['raison_ajustement']);

            // Récupérer l'ancienne quantité
            $stmt_ancien = $db->prepare("SELECT quantite_actuelle FROM lots WHERE id = ?");
            $stmt_ancien->execute([$lot_id]);
            $ancienne_quantite = $stmt_ancien->fetchColumn();

            // Récupérer le produit_id du lot
            $stmt_produit = $db->prepare("SELECT produit_id FROM lots WHERE id = ?");
            $stmt_produit->execute([$lot_id]);
            $produit_id = $stmt_produit->fetchColumn();

            // Mettre à jour le lot
            $stmt_update = $db->prepare("UPDATE lots SET quantite_actuelle = ? WHERE id = ?");
            $stmt_update->execute([$nouvelle_quantite, $lot_id]);

            // Enregistrer le mouvement
            $type_mouvement = $nouvelle_quantite > $ancienne_quantite ? 'ajustement' : 'ajustement';
            $quantite_mouvement = abs($nouvelle_quantite - $ancienne_quantite);

            $stmt_mouvement = $db->prepare("
                INSERT INTO mouvements_stock (produit_id, lot_id, type_mouvement, quantite, 
                                           quantite_avant, quantite_apres, raison, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_mouvement->execute([
                $produit_id,
                $lot_id,
                $type_mouvement,
                $quantite_mouvement,
                $ancienne_quantite,
                $nouvelle_quantite,
                $raison,
                $user_id
            ]);

            $_SESSION['success_message'] = "Stock ajusté avec succès!";
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;

    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Erreur lors de l'opération: " . $e->getMessage();
    }
}

// FONCTIONS UTILITAIRES
function getStatutLotBadge($statut)
{
    $classes = [
        'en_stock' => 'bg-green-100 text-green-800',
        'epuise' => 'bg-red-100 text-red-800',
        'vide' => 'bg-gray-100 text-gray-800',
        'perime' => 'bg-red-100 text-red-800',
        'quarantaine' => 'bg-orange-100 text-orange-800',
        'retire' => 'bg-purple-100 text-purple-800'
    ];
    return $classes[$statut] ?? 'bg-gray-100 text-gray-800';
}

function getNiveauAlerte($jours_restants)
{
    if ($jours_restants <= 7)
        return ['bg-red-100 text-red-800', 'Urgent'];
    if ($jours_restants <= 15)
        return ['bg-orange-100 text-orange-800', 'Attention'];
    return ['bg-green-100 text-green-800', 'Normal'];
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Stockiste - Pharma Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 16rem;
            background-color: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            z-index: 40;
            overflow-y: auto;
        }

        .main-content {
            margin-left: 16rem;
            min-height: 100vh;
        }

        .section {
            display: none;
        }

        .section.active {
            display: block;
        }

        .nav-link.active {
            background-color: #10B981;
            color: white;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 50;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }
    </style>
</head>

<body class="bg-gray-100" style="font-family: 'Montserrat', sans-serif;">
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="fixed top-4 right-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded z-50">
            <div class="flex items-center">
                <i class="fas fa-check-circle mr-2"></i>
                <span><?php echo $_SESSION['success_message'];
                unset($_SESSION['success_message']); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="fixed top-4 right-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded z-50">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <span><?php echo $_SESSION['error_message'];
                unset($_SESSION['error_message']); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="flex items-center justify-center p-6 border-b">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-orange-600 rounded-full flex items-center justify-center">
                    <i class="fas fa-boxes text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">NAGEX Pharma</h1>
                    <p class="text-xs text-orange-600">Dashboard Stockiste</p>
                </div>
            </div>
        </div>

        <!-- Sidebar Navigation -->
        <nav class="mt-6">
            <div class="px-4 space-y-2">
                <a href="#dashboard"
                    class="nav-link active flex items-center px-4 py-3 text-gray-700 rounded-lg transition-colors">
                    <i class="fas fa-tachometer-alt w-6"></i>
                    <span class="ml-3 font-medium">Tableau de bord</span>
                </a>

                <!-- Gestion des Stocks -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Gestion des Stocks
                    </p>

                    <a href="#produits"
                        class="nav-link flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-orange-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-pills w-6 text-blue-500"></i>
                            <span class="ml-3 font-medium">Produits & Stocks</span>
                        </div>
                        <span
                            class="bg-blue-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $produits_total; ?></span>
                    </a>

                    <a href="#lots"
                        class="nav-link flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-orange-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-boxes w-6 text-green-500"></i>
                            <span class="ml-3 font-medium">Gestion des Lots</span>
                        </div>
                        <span
                            class="bg-green-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $lots_total; ?></span>
                    </a>

                    <a href="#ajouter-lot"
                        class="nav-link flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-orange-50 transition-colors">
                        <i class="fas fa-plus-circle w-6 text-purple-500"></i>
                        <span class="ml-3 font-medium">Nouveau Lot</span>
                    </a>
                </div>

                <!-- Inventaires -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Inventaires</p>

                    <a href="#inventaires"
                        class="nav-link flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-orange-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-clipboard-check w-6 text-indigo-500"></i>
                            <span class="ml-3 font-medium">Inventaires en cours</span>
                        </div>
                        <span
                            class="bg-indigo-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $inventaires_en_cours; ?></span>
                    </a>

                    <a href="#nouvel-inventaire"
                        class="nav-link flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-orange-50 transition-colors">
                        <i class="fas fa-plus w-6 text-green-500"></i>
                        <span class="ml-3 font-medium">Nouvel Inventaire</span>
                    </a>
                </div>

                <!-- Alertes & Surveillance -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Alertes &
                        Surveillance</p>

                    <a href="#alertes-stock"
                        class="nav-link flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-orange-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-triangle w-6 text-red-500"></i>
                            <span class="ml-3 font-medium">Alertes Stock</span>
                        </div>
                        <span
                            class="bg-red-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $alertes_stock_count; ?></span>
                    </a>

                    <a href="#alertes-expiration"
                        class="nav-link flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-orange-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-clock w-6 text-orange-500"></i>
                            <span class="ml-3 font-medium">Péremptions</span>
                        </div>
                        <span
                            class="bg-orange-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $alertes_expiration_count; ?></span>
                    </a>
                </div>

                <!-- Rapports -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Rapports</p>

                    <a href="#mouvements"
                        class="nav-link flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-orange-50 transition-colors">
                        <i class="fas fa-exchange-alt w-6 text-purple-500"></i>
                        <span class="ml-3 font-medium">Mouvements</span>
                    </a>

                    <a href="#rapports"
                        class="nav-link flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-orange-50 transition-colors">
                        <i class="fas fa-chart-bar w-6 text-teal-500"></i>
                        <span class="ml-3 font-medium">Rapports</span>
                    </a>
                </div>
            </div>
        </nav>

        <div class="absolute bottom-0 left-0 right-0 p-4 border-t">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-orange-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-boxes text-orange-600"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($user_name); ?>
                    </p>
                    <p class="text-xs text-orange-600 truncate">Stockiste</p>
                </div>
                <a href="../utilisateurs/logout.php" class="text-gray-400 hover:text-red-500 transition-colors">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <header class="bg-white shadow-sm">
            <div class="flex items-center justify-between px-8 py-4">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800" id="pageTitle">Tableau de bord Stockiste</h2>
                    <p class="text-gray-600">Gestion physique du stock et des réceptions</p>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-sm text-gray-600">
                        <i class="fas fa-user-circle mr-2"></i>
                        Connecté en tant que <span
                            class="font-semibold"><?php echo htmlspecialchars($user_role); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-8">

            <!-- Section Tableau de bord -->
            <div id="dashboard" class="section active">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Produits Actifs -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-blue-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Produits Actifs</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $produits_total; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-pills text-blue-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Lots en Stock -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-green-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Lots en Stock</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $lots_total; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-boxes text-green-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    <!-- Produits en Rupture -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-red-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Produits en Rupture</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $rupture_count; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-exclamation-circle text-red-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Alertes Stock Bas -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-red-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Alertes Stock Bas</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $alertes_stock_count; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Péremptions Proches -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-orange-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Péremptions (30j)</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $alertes_expiration_count; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-clock text-orange-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions Rapides -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6">Actions Rapides</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <button onclick="showSection('ajouter-lot')"
                                class="flex flex-col items-center p-4 bg-purple-50 rounded-lg hover:bg-purple-100 transition-colors text-center">
                                <i class="fas fa-plus-circle text-purple-600 text-2xl mb-2"></i>
                                <span class="font-medium text-sm">Nouveau Lot</span>
                            </button>

                            <button onclick="showSection('nouvel-inventaire')"
                                class="flex flex-col items-center p-4 bg-green-50 rounded-lg hover:bg-green-100 transition-colors text-center">
                                <i class="fas fa-clipboard-check text-green-600 text-2xl mb-2"></i>
                                <span class="font-medium text-sm">Nouvel Inventaire</span>
                            </button>

                            <button onclick="showSection('alertes-stock')"
                                class="flex flex-col items-center p-4 bg-red-50 rounded-lg hover:bg-red-100 transition-colors text-center">
                                <i class="fas fa-exclamation-triangle text-red-600 text-2xl mb-2"></i>
                                <span class="font-medium text-sm">Vérifier Alertes</span>
                            </button>

                            <button onclick="showSection('rapports')"
                                class="flex flex-col items-center p-4 bg-teal-50 rounded-lg hover:bg-teal-100 transition-colors text-center">
                                <i class="fas fa-chart-bar text-teal-600 text-2xl mb-2"></i>
                                <span class="font-medium text-sm">Générer Rapport</span>
                            </button>
                        </div>
                    </div>

                    <!-- Alertes Urgentes -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-semibold text-gray-900">Alertes Urgentes</h3>
                            <button onclick="showSection('alertes-stock')"
                                class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                Voir tout
                            </button>
                        </div>
                        <div class="space-y-3 max-h-80 overflow-y-auto">
                            <!-- Alertes Stock Bas -->
                            <?php if (!empty($alertes_stock_data)): ?>
                                <?php foreach (array_slice($alertes_stock_data, 0, 3) as $alerte): ?>
                                    <div
                                        class="flex items-center justify-between p-3 bg-red-50 border border-red-200 rounded-lg">
                                        <div>
                                            <div class="font-medium text-red-900 text-sm">
                                                <?php echo htmlspecialchars($alerte['nom']); ?>
                                            </div>
                                            <div class="text-xs text-red-700">Stock: <?php echo $alerte['stock_actuel']; ?> /
                                                Seuil: <?php echo $alerte['seuil_alerte']; ?></div>
                                        </div>
                                        <span class="bg-red-100 text-red-800 text-xs px-2 py-1 rounded-full">
                                            Déficit: <?php echo $alerte['deficit']; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- Alertes Expiration -->
                            <?php if (!empty($alertes_expiration_data)): ?>
                                <?php foreach (array_slice($alertes_expiration_data, 0, 2) as $lot): ?>
                                    <?php list($bg_color, $text) = getNiveauAlerte($lot['jours_restants']); ?>
                                    <div
                                        class="flex items-center justify-between p-3 bg-orange-50 border border-orange-200 rounded-lg">
                                        <div>
                                            <div class="font-medium text-orange-900 text-sm">
                                                <?php echo htmlspecialchars($lot['produit_nom']); ?>
                                            </div>
                                            <div class="text-xs text-orange-700">Lot:
                                                <?php echo htmlspecialchars($lot['numero_lot']); ?>
                                            </div>
                                        </div>
                                        <span
                                            class="inline-flex items-center px-2 py-1 rounded text-xs font-medium <?php echo $bg_color; ?>">
                                            <?php echo $lot['jours_restants']; ?> jours
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if (empty($alertes_stock_data) && empty($alertes_expiration_data)): ?>
                                <div class="text-center py-4 text-gray-500">
                                    <i class="fas fa-check-circle text-green-500 text-xl mb-2"></i>
                                    <p class="text-sm">Aucune alerte urgente</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Derniers Mouvements -->
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">Derniers Mouvements de Stock</h3>
                        <button onclick="showSection('mouvements')"
                            class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                            Voir tout
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date
                                    </th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Produit
                                    </th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Lot</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type
                                    </th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Quantité
                                    </th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Raison
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (!empty($mouvements_data)): ?>
                                    <?php foreach ($mouvements_data as $mouvement): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-2 whitespace-nowrap text-gray-500">
                                                <?php echo $mouvement['date_formatee']; ?>
                                            </td>
                                            <td class="px-4 py-2 whitespace-nowrap font-medium text-gray-900">
                                                <?php echo htmlspecialchars($mouvement['produit_nom']); ?>
                                            </td>
                                            <td class="px-4 py-2 whitespace-nowrap text-gray-500">
                                                <?php echo htmlspecialchars($mouvement['numero_lot']); ?>
                                            </td>
                                            <td class="px-4 py-2 whitespace-nowrap">
                                                <span
                                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium 
                                        <?php echo strpos($mouvement['type_mouvement'], 'entree') !== false ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                    <?php echo strpos($mouvement['type_mouvement'], 'entree') !== false ? 'Entrée' : 'Sortie'; ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 whitespace-nowrap text-gray-500">
                                                <?php echo abs($mouvement['quantite']); ?> unités
                                            </td>
                                            <td class="px-4 py-2 whitespace-nowrap text-gray-500">
                                                <?php echo htmlspecialchars($mouvement['raison'] ?? 'N/A'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-4 py-4 text-center text-gray-500">Aucun mouvement récent
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Section Produits & Stocks -->
            <div id="produits" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Produits & Stocks</h3>
                            <span class="text-sm text-gray-600"><?php echo $produits_total; ?> produit(s)</span>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($produits_data)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Produit</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Catégorie</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Fournisseur</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code
                                                Barre</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Stock Total</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nb
                                                Lots</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Statut</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($produits_data as $produit): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($produit['nom']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($produit['description']); ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($produit['categorie_nom']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($produit['fournisseur_nom']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($produit['code_barre']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                            <?php echo $produit['stock_total'] <= 10 ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'; ?>">
                                                        <?php echo $produit['stock_total']; ?> unités
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $produit['nb_lots']; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                        <?php echo htmlspecialchars($produit['statut']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-pills text-4xl text-gray-300 mb-2"></i>
                                <p>Aucun produit trouvé</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Section Gestion des Lots -->
            <div id="lots" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Gestion des Lots</h3>
                            <span class="text-sm text-gray-600"><?php echo $lots_total; ?> lot(s) en stock</span>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($lots_data)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Produit</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">N°
                                                Lot</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Quantité</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date
                                                Réception</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date
                                                Expiration</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Jours Restants</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Statut</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($lots_data as $lot): ?>
                                            <?php list($bg_color, $text) = getNiveauAlerte($lot['jours_restants']); ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($lot['produit_nom']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($lot['code_barre']); ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($lot['numero_lot']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $lot['quantite_actuelle']; ?> unités
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo formatDate($lot['date_reception']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo date('d/m/Y', strtotime($lot['date_expiration'])); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $bg_color; ?>">
                                                        <?php echo $lot['jours_restants']; ?> jours
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo getStatutLotBadge($lot['statut']); ?>">
                                                        <?php echo $lot['statut']; ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <button onclick="ajusterStock(<?php echo $lot['id']; ?>)"
                                                        class="text-blue-600 hover:text-blue-900 mr-3">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button onclick="changerStatutLot(<?php echo $lot['id']; ?>)"
                                                        class="text-orange-600 hover:text-orange-900">
                                                        <i class="fas fa-exchange-alt"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-boxes text-4xl text-gray-300 mb-2"></i>
                                <p>Aucun lot en stock</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Section Nouveau Lot -->
            <div id="ajouter-lot" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Ajouter un Nouveau Lot</h3>
                            <button onclick="showSection('lots')"
                                class="px-4 py-2 text-gray-600 hover:text-gray-800 text-sm">
                                <i class="fas fa-arrow-left mr-2"></i>Retour aux lots
                            </button>
                        </div>
                    </div>
                    <div class="p-6">
                        <div class="max-w-4xl mx-auto">
                            <form method="POST" id="formAjoutLotDirect">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Produit *</label>
                                        <select name="produit_id" required
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                                            <option value="">Sélectionnez un produit</option>
                                            <?php foreach ($produits_data as $produit): ?>
                                                <option value="<?php echo $produit['id']; ?>">
                                                    <?php echo htmlspecialchars($produit['nom']); ?>
                                                    (<?php echo htmlspecialchars($produit['code_barre']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Numéro de lot
                                            *</label>
                                        <input type="text" name="numero_lot" required
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                            placeholder="Ex: LOT-2024-001">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Quantité initiale
                                            *</label>
                                        <input type="number" name="quantite_initiale" required min="0"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                            placeholder="Nombre d'unités" value="0">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Statut du lot
                                            *</label>
                                        <select name="statut" required
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                            onchange="gererVisibiliteQuantite(this.value)">
                                            <option value="">Sélectionnez un statut</option>
                                            <option value="en_stock" selected>En stock</option>
                                            <option value="epuise">Épuisé</option>
                                            <option value="vide">Vide</option>
                                            <option value="quarantaine">En quarantaine</option>
                                            <option value="retire">Retiré</option>
                                            <option value="perime">Périmé</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Prix d'achat
                                            *</label>
                                        <input type="number" name="prix_achat" required min="0" step="0.01"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                            placeholder="0.00">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Date de
                                            réception</label>
                                        <input type="date" name="date_reception"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                            value="<?php echo date('Y-m-d'); ?>">
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Date d'expiration
                                            *</label>
                                        <input type="date" name="date_expiration" required
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                            min="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>

                                <!-- Aide contextuelle -->
                                <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                                    <div class="flex items-center">
                                        <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                                        <div>
                                            <p class="text-sm font-medium text-blue-800">Guide des statuts</p>
                                            <div class="text-xs text-blue-600 grid grid-cols-2 gap-1 mt-1">
                                                <div>• <span class="font-semibold">En stock</span> : Produit disponible
                                                </div>
                                                <div>• <span class="font-semibold">Épuisé</span> : Stock épuisé</div>
                                                <div>• <span class="font-semibold">Vide</span> : Lot vide (quantité 0)
                                                </div>
                                                <div>• <span class="font-semibold">Quarantaine</span> : En attente de
                                                    contrôle</div>
                                                <div>• <span class="font-semibold">Retiré</span> : Retiré de la vente
                                                </div>
                                                <div>• <span class="font-semibold">Périmé</span> : Date expirée</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex justify-end space-x-3 mt-6">
                                    <button type="button" onclick="showSection('lots')"
                                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm font-medium">
                                        Annuler
                                    </button>
                                    <button type="submit" name="ajouter_lot"
                                        class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 text-sm font-medium">
                                        <i class="fas fa-plus-circle mr-2"></i>Ajouter le lot
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Alertes Stock -->
            <div id="alertes-stock" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Alertes Stock Bas</h3>
                            <span class="text-sm text-gray-600"><?php echo $alertes_stock_count; ?> alerte(s)</span>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($alertes_stock_data)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Produit</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Catégorie</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Stock Actuel</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Seuil Alerte</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Déficit</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($alertes_stock_data as $produit): ?>
                                            <tr class="hover:bg-red-50">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($produit['nom']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($produit['description']); ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($produit['categorie_nom']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-red-600">
                                                    <?php echo $produit['stock_actuel']; ?> unités
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">10 unités</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-red-600">
                                                    -<?php echo $produit['deficit']; ?> unités</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <button onclick="signalerAuPharmacien(<?php echo $produit['id']; ?>)"
                                                        class="text-orange-600 hover:text-orange-900">
                                                        <i class="fas fa-bell mr-1"></i>Signaler
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-check-circle text-green-500 text-4xl mb-2"></i>
                                <p class="text-lg font-medium text-green-600">Aucune alerte stock bas</p>
                                <p class="text-sm">Tous les produits ont un stock suffisant</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Section Alertes Expiration -->
            <div id="alertes-expiration" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Alertes Péremption (30 jours)</h3>
                            <span class="text-sm text-gray-600"><?php echo $alertes_expiration_count; ?> lot(s)
                                concerné(s)</span>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($alertes_expiration_data)): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Produit</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">N°
                                                Lot</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Quantité</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date
                                                Expiration</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Jours Restants</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Niveau Alerte</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($alertes_expiration_data as $lot): ?>
                                            <?php
                                            $jours_restants = $lot['jours_restants'];
                                            $niveau_alerte = $jours_restants <= 7 ? ['bg-red-100 text-red-800', 'URGENT'] :
                                                ($jours_restants <= 15 ? ['bg-orange-100 text-orange-800', 'ATTENTION'] :
                                                    ['bg-yellow-100 text-yellow-800', 'SURVEILLANCE']);
                                            ?>
                                            <tr class="hover:bg-orange-50">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($lot['produit_nom']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($lot['code_barre']); ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($lot['numero_lot']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $lot['quantite_actuelle']; ?> unités
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo date('d/m/Y', strtotime($lot['date_expiration'])); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $niveau_alerte[0]; ?>">
                                                        <?php echo $jours_restants; ?> jours
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $niveau_alerte[0]; ?>">
                                                        <?php echo $niveau_alerte[1]; ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <button onclick="traiterAlertePereemption(<?php echo $lot['id']; ?>)"
                                                        class="text-blue-600 hover:text-blue-900 mr-3" title="Traiter">
                                                        <i class="fas fa-cog"></i>
                                                    </button>
                                                    <button onclick="signalerAuPharmacien(<?php echo $lot['id']; ?>)"
                                                        class="text-orange-600 hover:text-orange-900 mr-3" title="Signaler">
                                                        <i class="fas fa-bell"></i>
                                                    </button>
                                                    <button onclick="deplacerLot(<?php echo $lot['id']; ?>)"
                                                        class="text-green-600 hover:text-green-900" title="Déplacer">
                                                        <i class="fas fa-arrows-alt"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Résumé des alertes -->
                            <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                                    <div class="flex items-center">
                                        <i class="fas fa-exclamation-circle text-red-500 text-xl mr-3"></i>
                                        <div>
                                            <div class="font-semibold text-red-800">Urgent (≤ 7 jours)</div>
                                            <div class="text-red-600 text-sm">
                                                <?php
                                                $urgent_count = count(array_filter($alertes_expiration_data, function ($lot) {
                                                    return $lot['jours_restants'] <= 7;
                                                }));
                                                echo $urgent_count . ' lot(s)';
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-orange-50 border border-orange-200 rounded-lg p-4">
                                    <div class="flex items-center">
                                        <i class="fas fa-exclamation-triangle text-orange-500 text-xl mr-3"></i>
                                        <div>
                                            <div class="font-semibold text-orange-800">Attention (8-15 jours)</div>
                                            <div class="text-orange-600 text-sm">
                                                <?php
                                                $attention_count = count(array_filter($alertes_expiration_data, function ($lot) {
                                                    return $lot['jours_restants'] > 7 && $lot['jours_restants'] <= 15;
                                                }));
                                                echo $attention_count . ' lot(s)';
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                    <div class="flex items-center">
                                        <i class="fas fa-clock text-yellow-500 text-xl mr-3"></i>
                                        <div>
                                            <div class="font-semibold text-yellow-800">Surveillance (16-30 jours)</div>
                                            <div class="text-yellow-600 text-sm">
                                                <?php
                                                $surveillance_count = count(array_filter($alertes_expiration_data, function ($lot) {
                                                    return $lot['jours_restants'] > 15 && $lot['jours_restants'] <= 30;
                                                }));
                                                echo $surveillance_count . ' lot(s)';
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-check-circle text-green-500 text-4xl mb-2"></i>
                                <p class="text-lg font-medium text-green-600">Aucune alerte de péremption</p>
                                <p class="text-sm">Aucun lot n'expire dans les 30 prochains jours</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- Section Inventaires en Cours -->
            <div id="inventaires" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Inventaires en Cours</h3>
                            <div class="flex items-center space-x-4">
                                <span class="text-sm text-gray-600"><?php echo $inventaires_en_cours; ?> inventaire(s)
                                    en cours</span>
                                <button onclick="showSection('nouvel-inventaire')"
                                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-medium">
                                    <i class="fas fa-plus mr-2"></i>Nouvel Inventaire
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($inventaires_data)): ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                <?php foreach ($inventaires_data as $inventaire): ?>
                                    <div
                                        class="bg-indigo-50 border border-indigo-200 rounded-lg p-6 hover:shadow-md transition-shadow">
                                        <!-- En-tête de l'inventaire -->
                                        <div class="flex justify-between items-start mb-4">
                                            <div>
                                                <h4 class="font-semibold text-indigo-900 text-lg">
                                                    <?php echo htmlspecialchars($inventaire['reference']); ?>
                                                </h4>
                                                <p class="text-sm text-indigo-600">
                                                    <?php echo htmlspecialchars($inventaire['type_inventaire'] ?? 'Non spécifié'); ?>
                                                </p>
                                            </div>
                                            <span
                                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                                                <?php echo htmlspecialchars($inventaire['statut']); ?>
                                            </span>
                                        </div>

                                        <!-- Informations détaillées -->
                                        <div class="space-y-3 mb-4">
                                            <div class="flex justify-between text-sm">
                                                <span class="text-indigo-700">Créé par:</span>
                                                <span
                                                    class="font-medium"><?php echo htmlspecialchars($inventaire['createur_nom']); ?></span>
                                            </div>
                                            <div class="flex justify-between text-sm">
                                                <span class="text-indigo-700">Date création:</span>
                                                <span
                                                    class="font-medium"><?php echo formatDate($inventaire['created_at']); ?></span>
                                            </div>
                                            <div class="flex justify-between text-sm">
                                                <span class="text-indigo-700">Lignes:</span>
                                                <span class="font-medium"><?php echo $inventaire['nb_lignes']; ?></span>
                                            </div>
                                            <div class="flex justify-between text-sm">
                                                <span class="text-indigo-700">Écarts:</span>
                                                <span
                                                    class="font-medium <?php echo $inventaire['nb_ecarts'] > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                                    <?php echo $inventaire['nb_ecarts']; ?>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Progression -->
                                        <div class="mb-4">
                                            <div class="flex justify-between text-xs text-indigo-600 mb-1">
                                                <span>Progression</span>
                                                <span>
                                                    <?php
                                                    $pourcentage = $inventaire['nb_lignes'] > 0 ? min(100, ($inventaire['nb_lignes'] - $inventaire['nb_ecarts']) / $inventaire['nb_lignes'] * 100) : 0;
                                                    echo round($pourcentage, 1) . '%';
                                                    ?>
                                                </span>
                                            </div>
                                            <div class="w-full bg-indigo-200 rounded-full h-2">
                                                <div class="bg-indigo-600 h-2 rounded-full"
                                                    style="width: <?php echo $pourcentage; ?>%"></div>
                                            </div>
                                        </div>

                                        <!-- Actions -->
                                        <div class="flex space-x-2">
                                            <button onclick="continuerInventaire(<?php echo $inventaire['id']; ?>)"
                                                class="flex-1 bg-indigo-600 text-white py-2 px-3 rounded text-sm hover:bg-indigo-700 transition-colors text-center">
                                                <i class="fas fa-play mr-1"></i>Continuer
                                            </button>
                                            <button onclick="voirInventaire(<?php echo $inventaire['id']; ?>)"
                                                class="bg-white border border-indigo-300 text-indigo-600 py-2 px-3 rounded text-sm hover:bg-indigo-50 transition-colors">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button onclick="exporterInventaire(<?php echo $inventaire['id']; ?>)"
                                                class="bg-white border border-indigo-300 text-indigo-600 py-2 px-3 rounded text-sm hover:bg-indigo-50 transition-colors">
                                                <i class="fas fa-download"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Statistiques globales des inventaires -->
                            <div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-6">
                                <h4 class="text-lg font-semibold text-blue-800 mb-4">📈 Statistiques des Inventaires</h4>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-blue-600"><?php echo $inventaires_en_cours; ?>
                                        </div>
                                        <div class="text-sm text-blue-700">En cours</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-green-600">
                                            <?php
                                            $total_lignes = array_sum(array_column($inventaires_data, 'nb_lignes'));
                                            echo $total_lignes;
                                            ?>
                                        </div>
                                        <div class="text-sm text-green-700">Lignes totales</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-orange-600">
                                            <?php
                                            $total_ecarts = array_sum(array_column($inventaires_data, 'nb_ecarts'));
                                            echo $total_ecarts;
                                            ?>
                                        </div>
                                        <div class="text-sm text-orange-700">Écarts détectés</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-purple-600">
                                            <?php
                                            $moyenne_ecarts = $total_lignes > 0 ? round(($total_ecarts / $total_lignes) * 100, 1) : 0;
                                            echo $moyenne_ecarts . '%';
                                            ?>
                                        </div>
                                        <div class="text-sm text-purple-700">Taux d'écarts</div>
                                    </div>
                                </div>
                            </div>

                        <?php else: ?>
                            <div class="text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                                <i class="fas fa-clipboard-list text-5xl text-gray-400 mb-4"></i>
                                <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun inventaire en cours</h3>
                                <p class="text-gray-500 mb-6">Commencez un nouvel inventaire pour gérer votre stock</p>
                                <button onclick="showSection('nouvel-inventaire')"
                                    class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium">
                                    <i class="fas fa-plus mr-2"></i>Démarrer un inventaire
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- Section Nouvel Inventaire -->
            <div id="nouvel-inventaire" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Créer un Nouvel Inventaire</h3>
                    </div>
                    <div class="p-6">
                        <div class="max-w-4xl mx-auto">
                            <!-- Types d'inventaire -->
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                                <div class="border-2 border-green-200 bg-green-50 rounded-lg p-6 text-center cursor-pointer hover:bg-green-100 transition-colors"
                                    onclick="selectTypeInventaire('complet')">
                                    <i class="fas fa-clipboard-list text-green-600 text-3xl mb-3"></i>
                                    <h4 class="font-semibold text-green-800 mb-2">Complet</h4>
                                    <p class="text-sm text-green-600">Tous les produits et lots en stock</p>
                                </div>

                                <div class="border-2 border-blue-200 bg-blue-50 rounded-lg p-6 text-center cursor-pointer hover:bg-blue-100 transition-colors"
                                    onclick="selectTypeInventaire('partiel')">
                                    <i class="fas fa-list-alt text-blue-600 text-3xl mb-3"></i>
                                    <h4 class="font-semibold text-blue-800 mb-2">Partiel</h4>
                                    <p class="text-sm text-blue-600">Sélection de produits spécifiques</p>
                                </div>

                                <div class="border-2 border-purple-200 bg-purple-50 rounded-lg p-6 text-center cursor-pointer hover:bg-purple-100 transition-colors"
                                    onclick="selectTypeInventaire('cyclique')">
                                    <i class="fas fa-sync-alt text-purple-600 text-3xl mb-3"></i>
                                    <h4 class="font-semibold text-purple-800 mb-2">Cyclique</h4>
                                    <p class="text-sm text-purple-600">Rotation par zone de stockage</p>
                                </div>

                                <div class="border-2 border-orange-200 bg-orange-50 rounded-lg p-6 text-center cursor-pointer hover:bg-orange-100 transition-colors"
                                    onclick="selectTypeInventaire('cible')">
                                    <i class="fas fa-bullseye text-orange-600 text-3xl mb-3"></i>
                                    <h4 class="font-semibold text-orange-800 mb-2">Ciblé</h4>
                                    <p class="text-sm text-orange-600">Produits à risque ou valeur élevée</p>
                                </div>
                            </div>

                            <!-- Formulaire de création -->
                            <form method="POST" id="formNouvelInventaire" class="bg-gray-50 rounded-lg p-6">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Type d'inventaire
                                            *</label>
                                        <select name="type_inventaire" id="type_inventaire" required
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                            <option value="">Sélectionnez un type</option>
                                            <option value="complet">Inventaire complet</option>
                                            <option value="partiel">Inventaire partiel</option>
                                            <option value="cyclique">Inventaire cyclique</option>
                                            <option value="cible">Inventaire ciblé</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Référence</label>
                                        <input type="text" value="INV-<?php echo date('Ymd-His'); ?>"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-gray-100"
                                            readonly>
                                    </div>

                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                                        <textarea name="description" rows="3"
                                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 focus:border-transparent"
                                            placeholder="Description de l'inventaire (objectifs, zones concernées, etc.)"></textarea>
                                    </div>

                                    <!-- Options spécifiques selon le type -->
                                    <div id="options-inventaire" class="md:col-span-2 hidden">
                                        <div class="bg-white border border-gray-200 rounded-lg p-4">
                                            <h5 class="font-semibold text-gray-800 mb-3">Options spécifiques</h5>
                                            <div id="options-complet" class="inventaire-options hidden">
                                                <p class="text-sm text-gray-600">L'inventaire complet inclura tous les
                                                    produits et lots en stock.</p>
                                                <div class="mt-2 text-xs text-gray-500">
                                                    <i class="fas fa-info-circle mr-1"></i>
                                                    Estimation: <?php echo $produits_total; ?> produits,
                                                    <?php echo $lots_total; ?> lots
                                                </div>
                                            </div>

                                            <div id="options-partiel" class="inventaire-options hidden">
                                                <label class="block text-sm font-medium text-gray-700 mb-2">Sélection
                                                    des produits</label>
                                                <select multiple
                                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm h-32">
                                                    <?php foreach ($produits_data as $produit): ?>
                                                        <option value="<?php echo $produit['id']; ?>">
                                                            <?php echo htmlspecialchars($produit['nom']); ?>
                                                            (<?php echo htmlspecialchars($produit['code_barre']); ?>)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div id="options-cyclique" class="inventaire-options hidden">
                                                <label class="block text-sm font-medium text-gray-700 mb-2">Zone de
                                                    stockage</label>
                                                <select
                                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                                                    <option value="zone_a">Zone A - Médicaments courants</option>
                                                    <option value="zone_b">Zone B - Produits sensibles</option>
                                                    <option value="zone_c">Zone C - Stock de réserve</option>
                                                    <option value="zone_d">Zone D - Produits volumineux</option>
                                                </select>
                                            </div>

                                            <div id="options-cible" class="inventaire-options hidden">
                                                <label class="block text-sm font-medium text-gray-700 mb-2">Critères de
                                                    sélection</label>
                                                <div class="space-y-2">
                                                    <label class="flex items-center">
                                                        <input type="checkbox"
                                                            class="rounded border-gray-300 text-green-600 focus:ring-green-500">
                                                        <span class="ml-2 text-sm text-gray-700">Produits à haute
                                                            valeur</span>
                                                    </label>
                                                    <label class="flex items-center">
                                                        <input type="checkbox"
                                                            class="rounded border-gray-300 text-green-600 focus:ring-green-500">
                                                        <span class="ml-2 text-sm text-gray-700">Produits
                                                            sensibles</span>
                                                    </label>
                                                    <label class="flex items-center">
                                                        <input type="checkbox"
                                                            class="rounded border-gray-300 text-green-600 focus:ring-green-500">
                                                        <span class="ml-2 text-sm text-gray-700">Produits proches
                                                            expiration</span>
                                                    </label>
                                                    <label class="flex items-center">
                                                        <input type="checkbox"
                                                            class="rounded border-gray-300 text-green-600 focus:ring-green-500">
                                                        <span class="ml-2 text-sm text-gray-700">Produits en stock
                                                            bas</span>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex justify-end space-x-3 mt-6">
                                    <button type="button" onclick="showSection('inventaires')"
                                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm font-medium">
                                        Annuler
                                    </button>
                                    <button type="submit" name="creer_inventaire"
                                        class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm font-medium">
                                        <i class="fas fa-plus mr-2"></i>Créer l'inventaire
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Section Mouvements de Stock -->
            <div id="mouvements" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Mouvements de Stock</h3>
                            <div class="flex items-center space-x-3">
                                <button onclick="filtrerMouvements()"
                                    class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm">
                                    <i class="fas fa-filter mr-2"></i>Filtrer
                                </button>
                                <button onclick="exporterMouvements()"
                                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                                    <i class="fas fa-download mr-2"></i>Exporter
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="p-6">
                        <!-- Filtres -->
                        <div class="bg-gray-50 rounded-lg p-4 mb-6">
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Période</label>
                                    <select class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                                        <option value="7">7 derniers jours</option>
                                        <option value="30" selected>30 derniers jours</option>
                                        <option value="90">3 derniers mois</option>
                                        <option value="custom">Période personnalisée</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                                    <select class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                                        <option value="">Tous les types</option>
                                        <option value="entree">Entrées</option>
                                        <option value="sortie">Sorties</option>
                                        <option value="ajustement">Ajustements</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Produit</label>
                                    <select class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                                        <option value="">Tous les produits</option>
                                        <?php foreach ($produits_data as $produit): ?>
                                            <option value="<?php echo $produit['id']; ?>">
                                                <?php echo htmlspecialchars($produit['nom']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Utilisateur</label>
                                    <select class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
                                        <option value="">Tous les utilisateurs</option>
                                        <option value="<?php echo $user_id; ?>">Moi</option>
                                        <!-- Autres utilisateurs -->
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Tableau des mouvements -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date
                                        </th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Produit</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">N°
                                            Lot</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type
                                        </th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Quantité</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Avant/Après</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Raison</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Utilisateur</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (!empty($mouvements_data)): ?>
                                        <?php foreach ($mouvements_data as $mouvement): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-4 py-3 whitespace-nowrap text-gray-500">
                                                    <?php echo $mouvement['date_formatee']; ?>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($mouvement['produit_nom']); ?>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap text-gray-500">
                                                    <?php echo htmlspecialchars($mouvement['numero_lot']); ?>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap">
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                            <?php echo strpos($mouvement['type_mouvement'], 'entree') !== false ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                        <i
                                                            class="fas fa-<?php echo strpos($mouvement['type_mouvement'], 'entree') !== false ? 'arrow-down' : 'arrow-up'; ?> mr-1"></i>
                                                        <?php
                                                        $type_text = strpos($mouvement['type_mouvement'], 'entree') !== false ? 'Entrée' : 'Sortie';
                                                        echo $type_text;
                                                        ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap text-gray-500 font-medium">
                                                    <?php echo abs($mouvement['quantite']); ?> unités
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap text-gray-500 text-xs">
                                                    <?php
                                                    $avant = $mouvement['quantite_avant'] ?? 'N/A';
                                                    $apres = $mouvement['quantite_apres'] ?? 'N/A';
                                                    echo $avant . ' → ' . $apres;
                                                    ?>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap text-gray-500">
                                                    <?php echo htmlspecialchars($mouvement['raison'] ?? 'N/A'); ?>
                                                </td>
                                                <td class="px-4 py-3 whitespace-nowrap text-gray-500">
                                                    <?php echo htmlspecialchars($mouvement['utilisateur_nom']); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                                <i class="fas fa-exchange-alt text-4xl text-gray-300 mb-2"></i>
                                                <p>Aucun mouvement trouvé pour la période sélectionnée</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div class="flex items-center justify-between mt-6">
                            <div class="text-sm text-gray-700">
                                Affichage de <span class="font-medium">1</span> à <span
                                    class="font-medium"><?php echo count($mouvements_data); ?></span> sur <span
                                    class="font-medium"><?php echo count($mouvements_data); ?></span> résultats
                            </div>
                            <div class="flex space-x-2">
                                <button
                                    class="px-3 py-1 border border-gray-300 rounded text-sm text-gray-600 hover:bg-gray-50">
                                    Précédent
                                </button>
                                <button class="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                                    1
                                </button>
                                <button
                                    class="px-3 py-1 border border-gray-300 rounded text-sm text-gray-600 hover:bg-gray-50">
                                    Suivant
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Rapports & Statistiques -->
            <div id="rapports" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Rapports & Statistiques</h3>
                            <button onclick="showModal('modalRapports')"
                                class="px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 text-sm font-medium">
                                <i class="fas fa-download mr-2"></i>Générer Rapports
                            </button>
                        </div>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <!-- Statistiques Rapides -->
                            <div class="bg-blue-50 rounded-lg p-6">
                                <h4 class="text-lg font-semibold text-blue-800 mb-4">📊 Aperçu du Stock</h4>
                                <div class="space-y-2">
                                    <div class="flex justify-between">
                                        <span class="text-blue-700">Produits actifs:</span>
                                        <span class="font-semibold"><?php echo $produits_total; ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-blue-700">Lots en stock:</span>
                                        <span class="font-semibold"><?php echo $lots_total; ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-blue-700">Stock total:</span>
                                        <span class="font-semibold">
                                            <?php
                                            $stock_total = 0;
                                            foreach ($lots_data as $lot) {
                                                $stock_total += $lot['quantite_actuelle'];
                                            }
                                            echo $stock_total;
                                            ?> unités
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Alertes Actives -->
                            <div class="bg-red-50 rounded-lg p-6">
                                <h4 class="text-lg font-semibold text-red-800 mb-4">🚨 Alertes Actives</h4>
                                <div class="space-y-2">
                                    <div class="flex justify-between">
                                        <span class="text-red-700">Stock bas:</span>
                                        <span class="font-semibold"><?php echo $alertes_stock_count; ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-red-700">Péremption (30j):</span>
                                        <span class="font-semibold"><?php echo $alertes_expiration_count; ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-red-700">Inventaires en cours:</span>
                                        <span class="font-semibold"><?php echo $inventaires_en_cours; ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Activité Récente -->
                            <div class="bg-green-50 rounded-lg p-6">
                                <h4 class="text-lg font-semibold text-green-800 mb-4">📈 Activité Récente</h4>
                                <div class="space-y-2">
                                    <div class="flex justify-between">
                                        <span class="text-green-700">Mouvements (7j):</span>
                                        <span class="font-semibold"><?php echo count($mouvements_data); ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-green-700">Dernier inventaire:</span>
                                        <span class="font-semibold">
                                            <?php
                                            if (!empty($inventaires_data)) {
                                                echo formatDate($inventaires_data[0]['created_at']);
                                            } else {
                                                echo 'Aucun';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-green-700">Prochaine expiration:</span>
                                        <span class="font-semibold">
                                            <?php
                                            if (!empty($alertes_expiration_data)) {
                                                echo date('d/m/Y', strtotime($alertes_expiration_data[0]['date_expiration']));
                                            } else {
                                                echo 'Aucune';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Les autres sections (alertes-stock, alertes-expiration, inventaires, etc.) suivent le même pattern -->


        </main>
    </div>

    <!-- Modals -->
    <div id="modalReception" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Encoder une réception</h3>
                <button onclick="closeModal('modalReception')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="formReception">
                <input type="hidden" name="commande_id" id="commande_id">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Sélectionner une commande</label>
                    <select id="select_commande" onchange="chargerLignesCommande(this.value)"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="">Sélectionnez une commande</option>
                        <?php foreach ($receptions_data as $commande): ?>
                            <option value="<?php echo $commande['id']; ?>">
                                <?php echo htmlspecialchars($commande['reference']); ?> -
                                <?php echo htmlspecialchars($commande['fournisseur_nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="lignes_commande" class="space-y-4 max-h-96 overflow-y-auto mb-4">
                    <div class="text-center text-gray-500 py-8">Sélectionnez une commande pour voir les produits</div>
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('modalReception')"
                        class="px-4 py-2 text-gray-600 hover:text-gray-800">Annuler</button>
                    <button type="submit" name="encoder_reception"
                        class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                        <i class="fas fa-clipboard-check mr-2"></i>Encoder la réception
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="modalInventaire" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Créer un nouvel inventaire</h3>
                <button onclick="closeModal('modalInventaire')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Type d'inventaire *</label>
                    <select name="type_inventaire" required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="partiel">Inventaire partiel</option>
                        <option value="complet">Inventaire complet</option>
                        <option value="cyclique">Inventaire cyclique</option>
                    </select>
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('modalInventaire')"
                        class="px-4 py-2 text-gray-600 hover:text-gray-800">Annuler</button>
                    <button type="submit" name="creer_inventaire"
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                        <i class="fas fa-plus mr-2"></i>Créer l'inventaire
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            showSection('dashboard');

            const navLinks = document.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href').substring(1);
                    showSection(targetId);
                });
            });

            window.addEventListener('click', function (event) {
                if (event.target.classList.contains('modal')) {
                    event.target.style.display = 'none';
                }
            });

            setTimeout(() => {
                const messages = document.querySelectorAll('.fixed');
                messages.forEach(msg => msg.style.display = 'none');
            }, 5000);
        });

        function showSection(sectionId) {
            document.querySelectorAll('.section').forEach(section => {
                section.classList.remove('active');
            });

            const targetSection = document.getElementById(sectionId);
            if (targetSection) {
                targetSection.classList.add('active');
            }

            const titles = {
                'dashboard': 'Tableau de bord Stockiste',
                'produits': 'Produits & Stocks',
                'lots': 'Gestion des Lots',
                'ajouter-lot': 'Ajouter un Nouveau Lot',
                'inventaires': 'Inventaires en Cours',
                'nouvel-inventaire': 'Nouvel Inventaire',
                'alertes-stock': 'Alertes Stock Bas',
                'alertes-expiration': 'Alertes Péremption',
                'mouvements': 'Mouvements de Stock',
                'rapports': 'Rapports & Statistiques'
            };
            document.getElementById('pageTitle').textContent = titles[sectionId] || 'Tableau de bord Stockiste';

            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });


            const activeLink = document.querySelector(`.nav-link[href="#${sectionId}"]`);
            if (activeLink) {
                activeLink.classList.add('active');
            }
        }

        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function encoderReception(commandeId) {
            document.getElementById('select_commande').value = commandeId;
            document.getElementById('commande_id').value = commandeId;
            showModal('modalReception');
        }
        // Fonctions pour la gestion des lots
        function ouvrirModalAjoutLot() {
            document.getElementById('modalLotTitre').textContent = 'Ajouter un nouveau lot';
            document.getElementById('modalAjoutLotTitre').textContent = 'Ajouter un nouveau lot';
            document.getElementById('submitLot').name = 'ajouter_lot';
            document.getElementById('submitLot').textContent = 'Ajouter le lot';
            document.getElementById('formAjoutLot').reset();
            document.getElementById('formLot').reset();
            document.getElementById('lot_id').value = '';
            showModal('modalAjoutLot');
            showModal('modalLot');
        }
        function ajusterStock(lotId) {
            document.getElementById('lot_id_ajustement').value = lotId;
            showModal('modalAjustementStock');
        }
        function changerStatutLot(lotId) {
            document.getElementById('lot_id_statut').value = lotId;
            showModal('modalChangerStatut');
        }

        // Fonctions pour les inventaires
        function ouvrirModalNouvelInventaire() {
            document.getElementById('modalNouvelInventaireTitre').textContent = 'Créer un nouvel inventaire';
            document.getElementById('formNouvelInventaire').reset();
            showModal('modalNouvelInventaire');
        }
        // Fonctions d'export
        function exporterRapportStock() {
            const date = new Date().toISOString().split('T')[0];
            const filename = `rapport_stock_${date}.csv`;

            let csvContent = "Produit,Catégorie,Stock Actuel,Seuil Alerte,Statut,Nb Lots\n";

            <?php if (!empty($produits_data)): ?>
                <?php foreach ($produits_data as $produit): ?>
                    csvContent += `"<?php echo htmlspecialchars($produit['nom']); ?>","<?php echo htmlspecialchars($produit['categorie_nom']); ?>","<?php echo $produit['stock_total']; ?>","<?php echo $produit['seuil_alerte']; ?>","<?php echo $produit['stock_total'] <= $produit['seuil_alerte'] ? 'ALERTE' : 'NORMAL'; ?>","<?php echo $produit['nb_lots']; ?>"\n`;
                <?php endforeach; ?>
            <?php endif; ?>

            // Téléchargement du fichier
            downloadCSV(csvContent, filename);
        }
        // Fonctions d'export (suite)
        function exporterRapportPereemption() {
            const date = new Date().toISOString().split('T')[0];
            const filename = `rapport_pereemption_${date}.csv`;

            let csvContent = "Produit,N° Lot,Quantité,Date Expiration,Jours Restants,Statut\n";

            <?php if (!empty($alertes_expiration_data)): ?>
                <?php foreach ($alertes_expiration_data as $lot): ?>
                    csvContent += `"<?php echo htmlspecialchars($lot['produit_nom']); ?>","<?php echo htmlspecialchars($lot['numero_lot']); ?>","<?php echo $lot['quantite_actuelle']; ?>","<?php echo date('d/m/Y', strtotime($lot['date_expiration'])); ?>","<?php echo $lot['jours_restants']; ?>","<?php echo $lot['jours_restants'] <= 7 ? 'URGENT' : ($lot['jours_restants'] <= 15 ? 'ATTENTION' : 'NORMAL'); ?>"\n`;
                <?php endforeach; ?>
            <?php endif; ?>

            downloadCSV(csvContent, filename);
        }
        function exporterInventaireComplet() {
            const date = new Date().toISOString().split('T')[0];
            const filename = `inventaire_complet_${date}.csv`;

            let csvContent = "Produit,Code Barre,Catégorie,Stock Théorique,Stock Réel,Écart\n";

            <?php if (!empty($produits_data)): ?>
                <?php foreach ($produits_data as $produit): ?>
                    csvContent += `"<?php echo htmlspecialchars($produit['nom']); ?>","<?php echo htmlspecialchars($produit['code_barre']); ?>","<?php echo htmlspecialchars($produit['categorie_nom']); ?>","<?php echo $produit['stock_total']; ?>","<?php echo $produit['stock_total']; ?>","0"\n`;
                <?php endforeach; ?>
            <?php endif; ?>

            downloadCSV(csvContent, filename);
        }
        // Fonction utilitaire pour télécharger CSV
        function downloadCSV(csvContent, filename) {
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            // Message de confirmation
            const nbLignes = csvContent.split('\n').length - 1;
            alert(`Export terminé!\nFichier: ${filename}\nLignes exportées: ${nbLignes}`);
        }
        // Fonctions pour les alertes
        function traiterAlerteStock(produitId) {
            // Marquer l'alerte comme traitée ou créer une commande
            if (confirm("Voulez-vous créer une commande de réapprovisionnement pour ce produit ?")) {
                // Redirection vers la création de commande
                window.location.href = `creer_commande.php?produit_id=${produitId}&urgence=stock_bas`;
            }
        }
        function traiterAlertePereemption(lotId) {
            // Actions pour les produits proches de l'expiration
            if (confirm("Que voulez-vous faire avec ce lot ?")) {
                const action = prompt("Options:\n1 - Mettre en promotion\n2 - Déplacer en zone 'à utiliser en premier'\n3 - Signaler au pharmacien\n\nEntrez le numéro de l'action:");

                switch (action) {
                    case '1':
                        // Mettre en promotion
                        window.location.href = `gestion_promotions.php?lot_id=${lotId}`;
                        break;
                    case '2':
                        // Changer la zone de stockage
                        changerZoneStockage(lotId);
                        break;
                    case '3':
                        // Signaler au pharmacien
                        signalerAuPharmacien(lotId);
                        break;
                    default:
                        alert("Action annulée");
                }
            }
        }

        // Fonctions utilitaires
        function changerZoneStockage(lotId) {
            const nouvelleZone = prompt("Entrez la nouvelle zone de stockage:");
            if (nouvelleZone) {
                // Envoyer la requête AJAX pour changer la zone
                fetch('changer_zone_stockage.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        lot_id: lotId,
                        nouvelle_zone: nouvelleZone
                    })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert("Zone de stockage mise à jour avec succès!");
                            location.reload();
                        } else {
                            alert("Erreur: " + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        alert("Erreur lors du changement de zone");
                    });
            }
        }

        function signalerAuPharmacien(lotId) {
            const message = prompt("Message pour le pharmacien:");
            if (message) {
                // Envoyer la notification au pharmacien
                fetch('signaler_pharmacien.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        lot_id: lotId,
                        message: message,
                        type: 'peremption'
                    })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert("Signalement envoyé au pharmacien!");
                        } else {
                            alert("Erreur: " + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        alert("Erreur lors de l'envoi du signalement");
                    });
            }
        }
        // Fonctions pour les alertes
        function signalerAuPharmacien(produitId) {
            const message = prompt("Message pour le pharmacien concernant le réapprovisionnement:");
            if (message) {
                // Simulation d'envoi au pharmacien
                alert("Signalement envoyé au pharmacien pour le produit #" + produitId);
            }
        }

        // Génération de rapports statistiques
        function genererRapportHebdomadaire() {
            const dateDebut = new Date();
            dateDebut.setDate(dateDebut.getDate() - 7);
            const dateFin = new Date();

            const rapportData = {
                periode: 'hebdomadaire',
                date_debut: dateDebut.toISOString().split('T')[0],
                date_fin: dateFin.toISOString().split('T')[0],
                mouvements: <?php echo count($mouvements_data); ?>,
                alertes_traitees: 0, // À calculer
                inventaires_realises: <?php echo $inventaires_en_cours; ?>,
                produits_verifies: <?php echo $produits_total; ?>
            };

            // Générer le rapport PDF (simulation)
            alert("Rapport hebdomadaire généré!\nPériode: " + rapportData.date_debut + " à " + rapportData.date_fin +
                "\nMouvements: " + rapportData.mouvements +
                "\nInventaires: " + rapportData.inventaires_realises);
        }
        // Fonctions pour la gestion des inventaires
        function selectTypeInventaire(type) {
            document.getElementById('type_inventaire').value = type;
            document.getElementById('options-inventaire').classList.remove('hidden');

            // Masquer toutes les options
            document.querySelectorAll('.inventaire-options').forEach(opt => {
                opt.classList.add('hidden');
            });

            // Afficher les options du type sélectionné
            document.getElementById('options-' + type).classList.remove('hidden');

            // Mettre en surbrillance la carte sélectionnée
            document.querySelectorAll('[onclick^="selectTypeInventaire"]').forEach(card => {
                card.classList.remove('border-green-400', 'bg-green-100');
                card.classList.add('border-gray-200', 'bg-gray-50');
            });

            event.currentTarget.classList.remove('border-gray-200', 'bg-gray-50');
            event.currentTarget.classList.add('border-green-400', 'bg-green-100');
        }

        // Fonctions pour les mouvements
        function filtrerMouvements() {
            // Implémentation du filtrage des mouvements
            alert('Fonction de filtrage des mouvements - À implémenter');
        }

        function exporterMouvements() {
            const date = new Date().toISOString().split('T')[0];
            const filename = `mouvements_stock_${date}.csv`;

            let csvContent = "Date,Produit,N° Lot,Type,Quantité,Avant,Après,Raison,Utilisateur\n";

            <?php if (!empty($mouvements_data)): ?>
                <?php foreach ($mouvements_data as $mouvement): ?>
                    csvContent += `"<?php echo $mouvement['date_formatee']; ?>","<?php echo htmlspecialchars($mouvement['produit_nom']); ?>","<?php echo htmlspecialchars($mouvement['numero_lot']); ?>","<?php echo strpos($mouvement['type_mouvement'], 'entree') !== false ? 'Entrée' : 'Sortie'; ?>","<?php echo abs($mouvement['quantite']); ?>","<?php echo $mouvement['quantite_avant'] ?? 'N/A'; ?>","<?php echo $mouvement['quantite_apres'] ?? 'N/A'; ?>","<?php echo htmlspecialchars($mouvement['raison'] ?? 'N/A'); ?>","<?php echo htmlspecialchars($mouvement['utilisateur_nom']); ?>"\n`;
                <?php endforeach; ?>
            <?php endif; ?>

            downloadCSV(csvContent, filename);
        }

        // Fonctions pour les lots
        function deplacerLot(lotId) {
            const nouvelleZone = prompt("Entrez la nouvelle zone de stockage pour ce lot:");
            if (nouvelleZone) {
                // Envoyer la requête AJAX pour déplacer le lot
                fetch('deplacer_lot.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        lot_id: lotId,
                        nouvelle_zone: nouvelleZone
                    })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert("Lot déplacé avec succès vers: " + nouvelleZone);
                            location.reload();
                        } else {
                            alert("Erreur: " + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        alert("Erreur lors du déplacement du lot");
                    });
            }
        }

        function exporterInventaire(inventaireId) {
            // Exporter un inventaire spécifique
            const date = new Date().toISOString().split('T')[0];
            const filename = `inventaire_${inventaireId}_${date}.csv`;

            // Simulation d'export
            alert(`Export de l'inventaire ${inventaireId} - Fichier: ${filename}`);
        }

        // Initialisation au chargement
        document.addEventListener('DOMContentLoaded', function () {
            // Initialiser les tooltips
            const tooltips = document.querySelectorAll('[data-tooltip]');
            tooltips.forEach(tooltip => {
                tooltip.addEventListener('mouseenter', function () {
                    // Implémentation des tooltips
                });
            });
        });

        function genererRapportMensuel() {
            const date = new Date();
            const mois = date.toLocaleString('fr-FR', { month: 'long', year: 'numeric' });

            const rapportData = {
                periode: 'mensuel',
                mois: mois,
                produits_gestion: <?php echo $produits_total; ?>,
                lots_gestion: <?php echo $lots_total; ?>,
                alertes_stock: <?php echo $alertes_stock_count; ?>,
                alertes_peremption: <?php echo $alertes_expiration_count; ?>,
                inventaires_complets: Math.floor(<?php echo $inventaires_en_cours; ?> / 2) // Simulation
            };

            // Générer le rapport PDF (simulation)
            alert("Rapport mensuel généré!\nPériode: " + rapportData.mois +
                "\nProduits gérés: " + rapportData.produits_gestion +
                "\nLots gérés: " + rapportData.lots_gestion +
                "\nAlertes stock: " + rapportData.alertes_stock +
                "\nInventaires complets: " + rapportData.inventaires_complets);
        }


        // Fonctions pour les rapports
        function genererRapportStock() {
            const date = new Date().toISOString().split('T')[0];
            const filename = `rapport_stock_${date}.csv`;

            let csvContent = "Produit,Catégorie,Code Barre,Stock Total,Seuil Alerte,Statut\n";

            <?php if (!empty($produits_data)): ?>
                <?php foreach ($produits_data as $produit): ?>
                    csvContent += `"<?php echo htmlspecialchars($produit['nom']); ?>","<?php echo htmlspecialchars($produit['categorie_nom']); ?>","<?php echo htmlspecialchars($produit['code_barre']); ?>","<?php echo $produit['stock_total']; ?>","<?php echo $produit['seuil_alerte']; ?>","<?php echo $produit['stock_total'] == 0 ? 'Rupture' : ($produit['stock_total'] <= $produit['seuil_alerte'] ? 'Stock bas' : 'Normal'); ?>"\n`;
                <?php endforeach; ?>
            <?php endif; ?>

            // Téléchargement du fichier
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            alert(`Rapport généré: ${filename}`);
        }
        function genererRapportInventaire() {
            const date = new Date().toISOString().split('T')[0];
            const filename = `rapport_inventaire_${date}.csv`;

            let csvContent = "Référence,Type,Statut,Créé par,Lignes totales,Lignes avec écart,Date\n";

            <?php if (!empty($inventaires_data)): ?>
                <?php foreach ($inventaires_data as $inventaire): ?>
                    csvContent += `"<?php echo htmlspecialchars($inventaire['reference']); ?>","<?php echo htmlspecialchars($inventaire['type_inventaire']); ?>","<?php echo htmlspecialchars($inventaire['statut']); ?>","<?php echo htmlspecialchars($inventaire['createur_nom']); ?>","<?php echo $inventaire['total_lignes']; ?>","<?php echo $inventaire['lignes_ecart']; ?>","<?php echo formatDate($inventaire['date_inventaire']); ?>"\n`;
                <?php endforeach; ?>
            <?php endif; ?>

            // Téléchargement du fichier
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            alert(`Rapport inventaire généré: ${filename}`);
        }


        function chargerLignesCommande(commandeId) {
            const lignesContainer = document.getElementById('lignes_commande');
            if (!commandeId) {
                lignesContainer.innerHTML = '<div class="text-center text-gray-500 py-8">Sélectionnez une commande pour voir les produits</div>';
                return;
            }

            lignesContainer.innerHTML = `
                <div class="text-center text-gray-500 py-8">
                    <i class="fas fa-spinner fa-spin mr-2"></i>
                    Chargement des produits...
                </div>
            `;

            setTimeout(() => {
                const lignesExemple = [
                    { id: 1, produit_nom: 'Paracétamol 500mg', quantite_commandee: 100 },
                    { id: 2, produit_nom: 'Ibuprofène 400mg', quantite_commandee: 50 },
                    { id: 3, produit_nom: 'Vitamine C 1000mg', quantite_commandee: 30 }
                ];

                let html = '';
                lignesExemple.forEach(ligne => {
                    html += `
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="flex justify-between items-center mb-2">
                                <div class="font-medium">${ligne.produit_nom}</div>
                                <div class="text-sm text-gray-500">Commandé: ${ligne.quantite_commandee}</div>
                            </div>
                            <div class="flex items-center space-x-4">
                                <label class="text-sm text-gray-700">Quantité reçue:</label>
                                <input type="number" name="produits[${ligne.id}]" 
                                       min="0" max="${ligne.quantite_commandee}"
                                       class="w-20 border border-gray-300 rounded px-2 py-1 text-sm"
                                       value="${ligne.quantite_commandee}">
                            </div>
                        </div>
                    `;
                });
                lignesContainer.innerHTML = html;
            }, 1000);
        }
        // Fonctions pour les inventaires
        function continuerInventaire(inventaireId) {
            // Redirection vers la page de continuation d'inventaire
            window.location.href = `inventaire_continuation.php?id=${inventaireId}`;
        }

        function voirInventaire(inventaireId) {
            // Redirection vers la page de détail d'inventaire
            window.location.href = `detail_inventaire.php?id=${inventaireId}`;
        }

        // Fonctions pour les alertes
        function signalerRupture(produitId) {
            document.querySelector('select[name="produit_id_anomalie"]').value = produitId;
            document.querySelector('select[name="type_anomalie"]').value = 'quantite';
            document.querySelector('textarea[name="description_anomalie"]').value = 'Produit en rupture de stock - réapprovisionnement urgent nécessaire';
            showSection('alertes');
        }

        function signalerStockBas(produitId) {
            document.querySelector('select[name="produit_id_anomalie"]').value = produitId;
            document.querySelector('select[name="type_anomalie"]').value = 'quantite';
            document.querySelector('textarea[name="description_anomalie"]').value = 'Stock bas - attention au seuil d\'alerte';
            showSection('alertes');
        }

        function commanderProduit(produitId) {
            // Redirection vers la page de création de commande
            window.location.href = `creer_commande.php?produit_id=${produitId}`;
        }

        // Fonctions utilitaires
        function exporterMouvements() {
            // Fonctionnalité d'export des mouvements
            const date = new Date().toISOString().split('T')[0];
            const filename = `mouvements_stock_${date}.csv`;

            // Simulation d'export CSV
            let csvContent = "Date,Produit,Lot,Type,Quantité,Motif,Utilisateur\n";

            <?php if (!empty($mouvements_data)): ?>
                <?php foreach ($mouvements_data as $mouvement): ?>
                    csvContent += `"<?php echo date('d/m/Y H:i', strtotime($mouvement['date_mouvement'])); ?>","<?php echo htmlspecialchars($mouvement['produit_nom']); ?>","<?php echo htmlspecialchars($mouvement['numero_lot']); ?>","<?php echo $mouvement['type_mouvement'] === 'entree' ? 'Entrée' : 'Sortie'; ?>","<?php echo $mouvement['quantite']; ?>","<?php echo htmlspecialchars($mouvement['motif'] ?? 'N/A'); ?>","<?php echo htmlspecialchars($mouvement['utilisateur_nom']); ?>"\n`;
                <?php endforeach; ?>
            <?php endif; ?>

            // Création et téléchargement du fichier
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            alert(`Export terminé: ${filename}`);
        }
        // Fonction pour exporter les inventaires
        function exporterInventaires() {
            const date = new Date().toISOString().split('T')[0];
            const filename = `inventaires_${date}.csv`;

            // En-têtes CSV
            let csvContent = "Référence,Type,Statut,Créé par,Lignes,Date\n";

            // Données des inventaires
            <?php if (!empty($inventaires_data)): ?>
                <?php foreach ($inventaires_data as $inventaire): ?>
                    csvContent += `"<?php echo htmlspecialchars($inventaire['reference']); ?>","<?php echo htmlspecialchars($inventaire['type_inventaire'] ?? ''); ?>","<?php echo htmlspecialchars($inventaire['statut']); ?>","<?php echo htmlspecialchars($inventaire['createur_nom']); ?>","<?php echo intval($inventaire['total_lignes']); ?>","<?php echo !empty($inventaire['date_inventaire']) ? date('d/m/Y H:i', strtotime($inventaire['date_inventaire'])) : ''; ?>"\n`;
                <?php endforeach; ?>
            <?php endif; ?>

            // Création et téléchargement du fichier
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            // Message de confirmation
            alert(`Export terminé!\nFichier: ${filename}\nInventaires exportés: <?php echo count($inventaires_data); ?>`);
        }

        // Gestion intelligente de la quantité selon le statut
        function gererVisibiliteQuantite(statut) {
            const champQuantite = document.querySelector('input[name="quantite_initiale"]');
            const labelQuantite = document.querySelector('label[for="quantite_initiale"]');

            if (statut === 'vide' || statut === 'epuise') {
                // Forcer la quantité à 0 pour les statuts "vide" et "épuisé"
                champQuantite.value = 0;
                champQuantite.disabled = true;
                champQuantite.classList.add('bg-gray-100');
                labelQuantite.innerHTML = 'Quantité initiale <span class="text-gray-500">(automatique: 0)</span>';
            } else if (statut === 'perime' || statut === 'retire') {
                // Quantité possible mais généralement 0
                champQuantite.disabled = false;
                champQuantite.classList.remove('bg-gray-100');
                labelQuantite.innerHTML = 'Quantité initiale <span class="text-orange-500">(attention: produit périmé/retiré)</span>';
            } else {
                // Statuts normaux
                champQuantite.disabled = false;
                champQuantite.classList.remove('bg-gray-100');
                labelQuantite.innerHTML = 'Quantité initiale *';

                if (statut === 'en_stock' && champQuantite.value == 0) {
                    champQuantite.value = 1; // Valeur par défaut pour "en stock"
                }
            }
        }

        // Initialisation au chargement
        document.addEventListener('DOMContentLoaded', function () {
            const statutSelect = document.querySelector('select[name="statut"]');
            if (statutSelect) {
                gererVisibiliteQuantite(statutSelect.value);
            }
        });

        // Fonctions pour les inventaires
        function continuerInventaire(inventaireId) {
            // Simulation - À remplacer par votre logique
            if (confirm("Voulez-vous continuer cet inventaire ?")) {
                // Redirection vers la page de continuation
                window.location.href = `inventaire_continuation.php?id=${inventaireId}`;
            }
        }

        function voirInventaire(inventaireId) {
            // Simulation - À remplacer par votre logique
            if (confirm("Voulez-vous voir les détails de cet inventaire ?")) {
                // Redirection vers la page de détail
                window.location.href = `detail_inventaire.php?id=${inventaireId}`;
            }
        }
    </script>

    <!-- Modal Ajout de Lot -->
    <div id="modalAjoutLot" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold" id="modalAjoutLotTitre">Ajouter un nouveau lot</h3>
                <button onclick="closeModal('modalAjoutLot')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="formAjoutLot">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Produit *</label>
                        <select name="produit_id" required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            <option value="">Sélectionnez un produit</option>
                            <?php foreach ($produits_data as $produit): ?>
                                <option value="<?php echo $produit['id']; ?>">
                                    <?php echo htmlspecialchars($produit['nom']); ?>
                                    (<?php echo htmlspecialchars($produit['code_barre']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Numéro de lot *</label>
                        <input type="text" name="numero_lot" required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                            placeholder="Ex: LOT-2024-001">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Quantité initiale *</label>
                        <input type="number" name="quantite_initiale" required min="1"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                            placeholder="Nombre d'unités">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Prix d'achat *</label>
                        <input type="number" name="prix_achat" required min="0" step="0.01"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="0.00">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date de réception</label>
                        <input type="date" name="date_reception"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                            value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date d'expiration *</label>
                        <input type="date" name="date_expiration" required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                            min="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeModal('modalAjoutLot')"
                        class="px-4 py-2 text-gray-600 hover:text-gray-800">
                        Annuler
                    </button>
                    <button type="submit" name="ajouter_lot"
                        class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700">
                        <i class="fas fa-save mr-2"></i>Enregistrer le lot
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Ajustement Stock -->
    <div id="modalAjustementStock" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Ajuster le stock</h3>
                <button onclick="closeModal('modalAjustementStock')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="lot_id" id="lot_id_ajustement">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Nouvelle quantité</label>
                    <input type="number" name="nouvelle_quantite" required min="0"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                        placeholder="Quantité actuelle">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Raison de l'ajustement</label>
                    <select name="raison_ajustement" required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="">Sélectionnez une raison</option>
                        <option value="inventaire">Correction d'inventaire</option>
                        <option value="casse">Casse/Perte</option>
                        <option value="don">Don/Échantillon</option>
                        <option value="retour">Retour fournisseur</option>
                        <option value="autre">Autre</option>
                    </select>
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('modalAjustementStock')"
                        class="px-4 py-2 text-gray-600 hover:text-gray-800">
                        Annuler
                    </button>
                    <button type="submit" name="ajuster_stock"
                        class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                        <i class="fas fa-sync-alt mr-2"></i>Ajuster le stock
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Changer Statut Lot -->
    <div id="modalChangerStatut" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Changer le statut du lot</h3>
                <button onclick="closeModal('modalChangerStatut')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="lot_id" id="lot_id_statut">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Nouveau statut</label>
                    <select name="nouveau_statut" required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="en_stock">En stock</option>
                        <option value="epuise">Épuisé</option>
                        <option value="perime">Périmé</option>
                        <option value="retire">Retiré</option>
                    </select>
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('modalChangerStatut')"
                        class="px-4 py-2 text-gray-600 hover:text-gray-800">
                        Annuler
                    </button>
                    <button type="submit" name="modifier_statut_lot"
                        class="px-4 py-2 bg-orange-600 text-white rounded-md hover:bg-orange-700">
                        <i class="fas fa-exchange-alt mr-2"></i>Changer le statut
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Nouvel Inventaire -->
    <div id="modalNouvelInventaire" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold" id="modalNouvelInventaireTitre">Créer un nouvel inventaire</h3>
                <button onclick="closeModal('modalNouvelInventaire')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Type d'inventaire *</label>
                    <select name="type_inventaire" required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="complet">Inventaire complet</option>
                        <option value="partiel">Inventaire partiel</option>
                        <option value="cyclique">Inventaire cyclique</option>
                        <option value="cible">Inventaire ciblé</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Description (optionnel)</label>
                    <textarea name="description" rows="3"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                        placeholder="Description de l'inventaire..."></textarea>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                    <div class="flex items-center">
                        <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                        <span class="text-sm text-blue-700">
                            <strong>Inventaire complet:</strong> Tous les produits en stock<br>
                            <strong>Inventaire partiel:</strong> Sélection de produits<br>
                            <strong>Inventaire cyclique:</strong> Rotation par zone<br>
                            <strong>Inventaire ciblé:</strong> Produits spécifiques
                        </span>
                    </div>
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('modalNouvelInventaire')"
                        class="px-4 py-2 text-gray-600 hover:text-gray-800">
                        Annuler
                    </button>
                    <button type="submit" name="creer_inventaire"
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                        <i class="fas fa-plus mr-2"></i>Créer l'inventaire
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Rapports -->
    <div id="modalRapports" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Générer un rapport</h3>
                <button onclick="closeModal('modalRapports')" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <button onclick="exporterRapportStock()"
                        class="flex items-center p-4 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 transition-colors text-left">
                        <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                            <i class="fas fa-boxes text-blue-600"></i>
                        </div>
                        <div>
                            <div class="font-medium text-blue-900">Rapport Stock</div>
                            <div class="text-xs text-blue-600">État complet du stock</div>
                        </div>
                    </button>

                    <button onclick="exporterRapportPereemption()"
                        class="flex items-center p-4 bg-orange-50 border border-orange-200 rounded-lg hover:bg-orange-100 transition-colors text-left">
                        <div class="w-10 h-10 bg-orange-100 rounded-full flex items-center justify-center mr-3">
                            <i class="fas fa-clock text-orange-600"></i>
                        </div>
                        <div>
                            <div class="font-medium text-orange-900">Rapport Péremption</div>
                            <div class="text-xs text-orange-600">Produits à expiration</div>
                        </div>
                    </button>

                    <button onclick="exporterInventaireComplet()" <div
                        class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-clipboard-list text-green-600"></i>
                </div>
                <div>
                    <div class="font-medium text-green-900">Fiche Inventaire</div>
                    <div class="text-xs text-green-600">Template d'inventaire</div>
                </div>
                </button>

                <button onclick="genererRapportHebdomadaire()"
                    class="flex items-center p-4 bg-purple-50 border border-purple-200 rounded-lg hover:bg-purple-100 transition-colors text-left">
                    <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-chart-line text-purple-600"></i>
                    </div>
                    <div>
                        <div class="font-medium text-purple-900">Rapport Hebdo</div>
                        <div class="text-xs text-purple-600">Activité de la semaine</div>
                    </div>
                </button>
            </div>

            <div class="border-t pt-4">
                <button onclick="genererRapportMensuel()"
                    class="w-full flex items-center justify-center p-4 bg-teal-50 border border-teal-200 rounded-lg hover:bg-teal-100 transition-colors">
                    <div class="w-10 h-10 bg-teal-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-chart-bar text-teal-600"></i>
                    </div>
                    <div class="text-left">
                        <div class="font-medium text-teal-900">Rapport Mensuel Complet</div>
                        <div class="text-xs text-teal-600">Statistiques et indicateurs du mois</div>
                    </div>
                </button>
            </div>
        </div>

        <div class="flex justify-end space-x-3 mt-6">
            <button type="button" onclick="closeModal('modalRapports')"
                class="px-4 py-2 text-gray-600 hover:text-gray-800">
                Fermer
            </button>
        </div>
    </div>
    </div>
    </div>
</body>

</html>