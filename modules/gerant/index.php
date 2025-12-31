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

            $stmt = $db->prepare("INSERT INTO categories (nom, description, created_by) VALUES (:nom, :description, :created_by)");
            $stmt->bindParam(':nom', $nom);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':created_by', $user_id);
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

            $stmt = $db->prepare("UPDATE categories SET nom = :nom, description = :description WHERE id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':nom', $nom);
            $stmt->bindParam(':description', $description);
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

// Gestion des requêtes AJAX
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_GET['action'] === 'get_categorie' && isset($_GET['id'])) {
            $stmt = $db->prepare("SELECT * FROM categories WHERE id = :id");
            $stmt->bindParam(':id', $_GET['id']);
            $stmt->execute();
            $categorie = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode($categorie ?: []);
            exit;
        }
        
        if ($_GET['action'] === 'get_produit' && isset($_GET['id'])) {
            $stmt = $db->prepare("
                SELECT p.*, c.nom as categorie_nom, f.nom_societe as fournisseur_nom 
                FROM produits p 
                LEFT JOIN categories c ON p.categorie_id = c.id 
                LEFT JOIN fournisseurs f ON p.fournisseur_id = f.id 
                WHERE p.id = :id
            ");
            $stmt->bindParam(':id', $_GET['id']);
            $stmt->execute();
            $produit = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Ajouter le prix actuel
            if ($produit) {
                $stmt = $db->prepare("
                    SELECT prix_fc, prix_usd 
                    FROM prix_vente 
                    WHERE produit_id = :produit_id 
                    AND (date_fin IS NULL OR date_fin >= CURDATE()) 
                    ORDER BY date_debut DESC LIMIT 1
                ");
                $stmt->bindParam(':produit_id', $_GET['id']);
                $stmt->execute();
                $prix = $stmt->fetch(PDO::FETCH_ASSOC);
                $produit['prix_fc'] = $prix['prix_fc'] ?? 0;
                $produit['prix_usd'] = $prix['prix_usd'] ?? 0;
            }
            
            echo json_encode($produit ?: []);
            exit;
        }
        
        if ($_GET['action'] === 'get_commande' && isset($_GET['id'])) {
            $stmt = $db->prepare("
                SELECT c.*, 
                       u.nom as client_nom, 
                       u.prenom as client_prenom,
                       ca.nom as caissier_nom,
                       ca.prenom as caissier_prenom
                FROM commandes c 
                LEFT JOIN utilisateurs u ON c.client_id = u.id 
                LEFT JOIN utilisateurs ca ON c.caissier_id = ca.id 
                WHERE c.id = :id
            ");
            $stmt->bindParam(':id', $_GET['id']);
            $stmt->execute();
            $commande = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($commande) {
                // Détails de la commande
                $stmt = $db->prepare("
                    SELECT cd.*, p.nom as produit_nom, p.code_barre, l.numero_lot
                    FROM commande_details cd 
                    LEFT JOIN produits p ON cd.produit_id = p.id 
                    LEFT JOIN lots l ON cd.lot_id = l.id 
                    WHERE cd.commande_id = :commande_id
                ");
                $stmt->bindParam(':commande_id', $_GET['id']);
                $stmt->execute();
                $commande['details'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            echo json_encode($commande ?: []);
            exit;
        }
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Récupérer les données pour le dashboard
try {
    // Récupérer les catégories
    $stmt = $db->prepare("SELECT * FROM categories ORDER BY nom");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les produits
    $stmt = $db->prepare("
        SELECT p.*, c.nom as categorie_nom, f.nom_societe as fournisseur_nom 
        FROM produits p 
        LEFT JOIN categories c ON p.categorie_id = c.id 
        LEFT JOIN fournisseurs f ON p.fournisseur_id = f.id 
        ORDER BY p.created_at DESC
    ");
    $stmt->execute();
    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les commandes
    $stmt = $db->prepare("
        SELECT c.*, 
               u.nom as client_nom, 
               u.prenom as client_prenom,
               COUNT(cd.id) as nb_produits
        FROM commandes c 
        LEFT JOIN utilisateurs u ON c.client_id = u.id 
        LEFT JOIN commande_details cd ON c.id = cd.commande_id 
        GROUP BY c.id 
        ORDER BY c.date_commande DESC 
        LIMIT 100
    ");
    $stmt->execute();
    $commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les fournisseurs
    $stmt = $db->prepare("
        SELECT f.*, u.email, u.telephone 
        FROM fournisseurs f 
        LEFT JOIN utilisateurs u ON f.user_id = u.id 
        ORDER BY f.created_at DESC
    ");
    $stmt->execute();
    $fournisseurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les alertes
    $stmt = $db->prepare("
        SELECT a.*, p.nom as produit_nom 
        FROM alertes a 
        LEFT JOIN produits p ON a.produit_id = p.id 
        WHERE a.statut = 'non_lu' 
        ORDER BY a.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $alertes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Statistiques des lots
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_lots,
            SUM(quantite_actuelle) as stock_total,
            SUM(CASE WHEN statut = 'epuise' THEN 1 ELSE 0 END) as lots_epuises,
            SUM(CASE WHEN statut = 'perime' THEN 1 ELSE 0 END) as lots_perimes,
            SUM(CASE WHEN date_expiration <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiration_proche
        FROM lots
    ");
    $stmt->execute();
    $stats_lots = $stmt->fetch(PDO::FETCH_ASSOC);

    // CA Mensuel (basé sur commandes)
    $stmt = $db->prepare("SELECT COALESCE(SUM(montant_total), 0) as ca_mensuel FROM commandes WHERE MONTH(date_commande) = MONTH(CURDATE()) AND YEAR(date_commande) = YEAR(CURDATE())");
    $stmt->execute();
    $ca_mensuel = (float)$stmt->fetchColumn();

    // CA Mois Précédent
    $stmt = $db->prepare("SELECT COALESCE(SUM(montant_total), 0) as ca_mois_precedent FROM commandes WHERE MONTH(date_commande) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(date_commande) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))");
    $stmt->execute();
    $ca_mois_precedent = (float)$stmt->fetchColumn();

    // Évolution CA
    $evolution_ca = $ca_mois_precedent > 0 ? (($ca_mensuel - $ca_mois_precedent) / $ca_mois_precedent) * 100 : 0;

    // Marge Moyenne (approximative)
    $stmt = $db->prepare("
        SELECT COALESCE(AVG(((pv.prix_fc - l.prix_achat) / l.prix_achat) * 100), 0) as marge_moyenne 
        FROM prix_vente pv 
        LEFT JOIN lots l ON pv.produit_id = l.produit_id 
        WHERE pv.date_fin IS NULL AND l.prix_achat > 0
    ");
    $stmt->execute();
    $marge_moyenne = (float)$stmt->fetchColumn();

    // Valeur Stock
    $stmt = $db->prepare("SELECT COALESCE(SUM(l.quantite_actuelle * l.prix_achat), 0) as valeur_stock FROM lots l WHERE l.quantite_actuelle > 0");
    $stmt->execute();
    $valeur_stock = (float)$stmt->fetchColumn();

    // Produits en Rupture
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT p.id) as nb_ruptures 
        FROM produits p 
        LEFT JOIN lots l ON p.id = l.produit_id 
        GROUP BY p.id 
        HAVING COALESCE(SUM(l.quantite_actuelle), 0) = 0
    ");
    $stmt->execute();
    $produits_rupture = (int)$stmt->fetchColumn();

    // Commandes aujourd'hui
    $stmt = $db->prepare("SELECT COUNT(*) as commandes_aujourdhui, COALESCE(SUM(montant_total), 0) as ca_aujourdhui FROM commandes WHERE DATE(date_commande) = CURDATE()");
    $stmt->execute();
    $commandes_aujourdhui_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $commandes_aujourdhui = (int)$commandes_aujourdhui_data['commandes_aujourdhui'];
    $ca_aujourdhui = (float)$commandes_aujourdhui_data['ca_aujourdhui'];

    // Nombre total de produits
    $stmt = $db->prepare("SELECT COUNT(*) FROM produits WHERE statut = 'actif'");
    $stmt->execute();
    $total_produits = (int)$stmt->fetchColumn();

    // Nombre total de catégories
    $stmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE statut = 'actif'");
    $stmt->execute();
    $total_categories = (int)$stmt->fetchColumn();

    // Nombre total de commandes ce mois
    $stmt = $db->prepare("SELECT COUNT(*) FROM commandes WHERE MONTH(date_commande) = MONTH(CURDATE()) AND YEAR(date_commande) = YEAR(CURDATE())");
    $stmt->execute();
    $total_commandes_mois = (int)$stmt->fetchColumn();

    // Nombre total de fournisseurs
    $stmt = $db->prepare("SELECT COUNT(*) FROM fournisseurs");
    $stmt->execute();
    $total_fournisseurs = (int)$stmt->fetchColumn();

    // Produits les plus vendus
    $limit = 5;
    $stmt = $db->prepare("
        SELECT p.nom, SUM(cd.quantite) as total_vendu, SUM(cd.sous_total) as ca_total 
        FROM commande_details cd 
        LEFT JOIN produits p ON cd.produit_id = p.id 
        LEFT JOIN commandes c ON cd.commande_id = c.id 
        WHERE MONTH(c.date_commande) = MONTH(CURDATE()) 
        AND YEAR(c.date_commande) = YEAR(CURDATE()) 
        GROUP BY p.id, p.nom 
        ORDER BY total_vendu DESC 
        LIMIT :limit
    ");
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $top_produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Évolution CA sur 6 mois
    $months = 6;
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(date_commande, '%Y-%m') as mois,
            DATE_FORMAT(date_commande, '%M %Y') as mois_format,
            SUM(montant_total) as ca,
            COUNT(*) as nb_commandes
        FROM commandes 
        WHERE date_commande >= DATE_SUB(CURDATE(), INTERVAL :months MONTH) 
        GROUP BY DATE_FORMAT(date_commande, '%Y-%m'), DATE_FORMAT(date_commande, '%M %Y') 
        ORDER BY mois ASC 
        LIMIT :months
    ");
    $stmt->bindParam(':months', $months, PDO::PARAM_INT);
    $stmt->execute();
    $evolution_ca_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Produits en expiration proche
    $jours = 30;
    $stmt = $db->prepare("
        SELECT p.nom, l.numero_lot, l.date_expiration, l.quantite_actuelle, 
               DATEDIFF(l.date_expiration, CURDATE()) as jours_restants 
        FROM lots l 
        LEFT JOIN produits p ON l.produit_id = p.id 
        WHERE l.date_expiration BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :jours DAY) 
        AND l.quantite_actuelle > 0 
        AND l.statut = 'en_stock'
        ORDER BY l.date_expiration ASC 
        LIMIT 10
    ");
    $stmt->bindParam(':jours', $jours, PDO::PARAM_INT);
    $stmt->execute();
    $produits_expiration = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Erreur chargement données gérant: " . $e->getMessage());
    
    // Valeurs par défaut en cas d'erreur
    $categories = [];
    $produits = [];
    $commandes = [];
    $fournisseurs = [];
    $alertes = [];
    $stats_lots = ['total_lots' => 0, 'stock_total' => 0, 'lots_epuises' => 0, 'lots_perimes' => 0, 'expiration_proche' => 0];
    $ca_mensuel = 0;
    $ca_mois_precedent = 0;
    $evolution_ca = 0;
    $marge_moyenne = 0;
    $valeur_stock = 0;
    $produits_rupture = 0;
    $commandes_aujourdhui = 0;
    $ca_aujourdhui = 0;
    $total_produits = 0;
    $total_categories = 0;
    $total_commandes_mois = 0;
    $total_fournisseurs = 0;
    $top_produits = [];
    $evolution_ca_data = [];
    $produits_expiration = [];
}

// Fonctions utilitaires pour le formatage
function formatMontant($montant) {
    return number_format($montant, 0, ',', ' ') . ' FC';
}

function formatPourcentage($valeur) {
    return number_format($valeur, 1, ',', ' ') . '%';
}

function getEvolutionIcon($valeur) {
    if ($valeur > 0) return ['fas fa-arrow-up', 'text-green-600', 'bg-green-100'];
    if ($valeur < 0) return ['fas fa-arrow-down', 'text-red-600', 'bg-red-100'];
    return ['fas fa-minus', 'text-gray-600', 'bg-gray-100'];
}

function getStatutCommandeClass($statut) {
    switch ($statut) {
        case 'paye': return 'bg-green-100 text-green-800';
        case 'en_attente': return 'bg-yellow-100 text-yellow-800';
        case 'annule': return 'bg-red-100 text-red-800';
        case 'rembourse': return 'bg-blue-100 text-blue-800';
        default: return 'bg-gray-100 text-gray-800';
    }
}

function getStatutProduitClass($statut) {
    switch ($statut) {
        case 'actif': return 'bg-green-100 text-green-800';
        case 'inactif': return 'bg-red-100 text-red-800';
        case 'en_attente': return 'bg-yellow-100 text-yellow-800';
        default: return 'bg-gray-100 text-gray-800';
    }
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

                <!-- Gestion des données -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Gestion des Données</p>
                    
                    <a href="#produits" onclick="showSection('produits')" class="flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-pills w-6 text-blue-500"></i>
                            <span class="ml-3 font-medium">Produits</span>
                        </div>
                        <span class="bg-blue-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $total_produits; ?></span>
                    </a>
                    
                    <a href="#commandes" onclick="showSection('commandes')" class="flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-shopping-cart w-6 text-green-500"></i>
                            <span class="ml-3 font-medium">Commandes</span>
                        </div>
                        <span class="bg-green-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $total_commandes_mois; ?></span>
                    </a>
                    
                    <a href="#categories" onclick="showSection('categories')" class="flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-tags w-6 text-indigo-500"></i>
                            <span class="ml-3 font-medium">Catégories</span>
                        </div>
                        <span class="bg-indigo-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $total_categories; ?></span>
                    </a>
                    
                    <a href="#fournisseurs" onclick="showSection('fournisseurs')" class="flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-truck w-6 text-purple-500"></i>
                            <span class="ml-3 font-medium">Fournisseurs</span>
                        </div>
                        <span class="bg-purple-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $total_fournisseurs; ?></span>
                    </a>
                </div>

                <!-- Surveillance & Alertes -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Surveillance</p>
                    
                    <a href="#alertes" onclick="showSection('alertes')" class="flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-bell w-6 text-red-500"></i>
                            <span class="ml-3 font-medium">Alertes</span>
                        </div>
                        <?php if (count($alertes) > 0): ?>
                        <span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full"><?php echo count($alertes); ?></span>
                        <?php endif; ?>
                    </a>
                    
                    <a href="#lots" onclick="showSection('lots')" class="flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-boxes w-6 text-orange-500"></i>
                            <span class="ml-3 font-medium">Gestion Lots</span>
                        </div>
                        <?php if ($stats_lots['expiration_proche'] > 0): ?>
                        <span class="bg-orange-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $stats_lots['expiration_proche']; ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <!-- Rapports & Analyses -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Rapports & Analyses</p>
                    
                    <a href="#finances" onclick="showSection('finances')" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <i class="fas fa-chart-bar w-6 text-teal-500"></i>
                        <span class="ml-3 font-medium">Analyse Financière</span>
                    </a>
                    
                    <a href="#performance" onclick="showSection('performance')" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <i class="fas fa-trophy w-6 text-yellow-500"></i>
                        <span class="ml-3 font-medium">Performance Commerciale</span>
                    </a>
                </div>

                <!-- Configuration système -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Configuration</p>
                    
                    <a href="#utilisateurs" onclick="showSection('utilisateurs')" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <i class="fas fa-users w-6 text-gray-500"></i>
                        <span class="ml-3 font-medium">Utilisateurs</span>
                    </a>
                    
                    <a href="#parametres" onclick="showSection('parametres')" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <i class="fas fa-cog w-6 text-gray-500"></i>
                        <span class="ml-3 font-medium">Paramètres</span>
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
                <!-- KPI Rapides -->
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

                    <!-- Carte Commandes Aujourd'hui -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-blue-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Commandes Aujourd'hui</p>
                                <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo $commandes_aujourdhui; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-shopping-cart text-blue-600 text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4">
                            <p class="text-xs text-gray-500">CA: <?php echo formatMontant($ca_aujourdhui); ?></p>
                        </div>
                    </div>

                    <!-- Carte Stock Total -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-purple-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Valeur Stock</p>
                                <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo formatMontant($valeur_stock); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-boxes text-purple-600 text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4">
                            <p class="text-xs text-gray-500"><?php echo $total_produits; ?> produits actifs</p>
                        </div>
                    </div>

                    <!-- Carte Marge Moyenne -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-yellow-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Marge Moyenne</p>
                                <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo formatPourcentage($marge_moyenne); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-percent text-yellow-600 text-xl"></i>
                            </div>
                        </div>
                        <div class="mt-4">
                            <p class="text-xs text-gray-500">Sur tous les produits</p>
                        </div>
                    </div>
                </div>

                <!-- Deuxième ligne de KPI -->
                <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-4 gap-6 mb-8">
                    <!-- Produits en rupture -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-red-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Produits en Rupture</p>
                                <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo $produits_rupture; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Lots à expiration -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-orange-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Expiration Proche</p>
                                <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo $stats_lots['expiration_proche']; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clock text-orange-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Alertes non lues -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-pink-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Alertes Non Lues</p>
                                <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo count($alertes); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-pink-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-bell text-pink-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Commandes en attente -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-gray-500">
                        <?php 
                            $commandes_attente = 0;
                            foreach ($commandes as $cmd) {
                                if ($cmd['statut'] == 'en_attente') $commandes_attente++;
                            }
                        ?>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Commandes En Attente</p>
                                <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo $commandes_attente; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                                <i class="fas fa-hourglass-half text-gray-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Graphiques et tableaux -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- Évolution CA -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Évolution du CA (6 derniers mois)</h3>
                        <div class="h-64 flex items-end justify-between">
                            <?php if (!empty($evolution_ca_data)): ?>
                                <?php 
                                    $max_ca = max(array_column($evolution_ca_data, 'ca'));
                                    if ($max_ca == 0) $max_ca = 1;
                                ?>
                                <?php foreach ($evolution_ca_data as $mois): ?>
                                <div class="flex flex-col items-center flex-1">
                                    <div class="text-xs text-gray-500 mb-2">
                                        <?php echo substr($mois['mois_format'], 0, 3); ?>
                                    </div>
                                    <div class="w-3/4">
                                        <div 
                                            class="bg-gradient-to-t from-indigo-500 to-indigo-600 rounded-t hover:from-indigo-600 hover:to-indigo-700 cursor-pointer mx-auto"
                                            style="height: <?php echo ($mois['ca'] / $max_ca) * 100; ?>%; min-height: 5px;"
                                            title="<?php echo $mois['mois_format']; ?>: <?php echo formatMontant($mois['ca']); ?>"
                                        ></div>
                                    </div>
                                    <div class="text-xs text-gray-600 mt-2">
                                        <?php echo formatMontant($mois['ca']); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center w-full py-8 text-gray-500">
                                    <i class="fas fa-chart-line text-2xl mb-2"></i>
                                    <p>Aucune donnée disponible</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Top produits -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Top 5 Produits</h3>
                        <div class="space-y-3">
                            <?php if (!empty($top_produits)): ?>
                                <?php foreach ($top_produits as $index => $produit): ?>
                                <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:bg-gray-50">
                                    <div class="flex items-center">
                                        <span class="w-6 h-6 bg-indigo-100 text-indigo-600 rounded-full flex items-center justify-center text-xs font-bold mr-3">
                                            <?php echo $index + 1; ?>
                                        </span>
                                        <div>
                                            <span class="font-medium text-gray-900"><?php echo htmlspecialchars($produit['nom']); ?></span>
                                            <div class="text-xs text-gray-500">
                                                <?php echo $produit['total_vendu']; ?> unités vendues
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold text-green-600"><?php echo formatMontant($produit['ca_total']); ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-8 text-gray-500">
                                    <i class="fas fa-box text-2xl mb-2"></i>
                                    <p>Aucun produit vendu ce mois</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Produits -->
            <div id="produits" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-900">Gestion des Produits</h3>
                        <button onclick="openModal('createProduitModal')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors">
                            <i class="fas fa-plus mr-2"></i>
                            Nouveau Produit
                        </button>
                    </div>
                    <div class="p-6">
                        <!-- Filtres -->
                        <div class="flex flex-wrap gap-4 mb-6">
                            <input type="text" id="searchProduit" placeholder="Rechercher un produit..." class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 flex-1 min-w-[200px]">
                            <select id="filterCategorie" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Toutes catégories</option>
                                <?php foreach ($categories as $categorie): ?>
                                <option value="<?php echo $categorie['id']; ?>"><?php echo htmlspecialchars($categorie['nom']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="filterStatut" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Tous statuts</option>
                                <option value="actif">Actif</option>
                                <option value="inactif">Inactif</option>
                                <option value="en_attente">En attente</option>
                            </select>
                        </div>

                        <!-- Liste des produits -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produit</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Catégorie</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fournisseur</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code Barre</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ordonnance</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200" id="produitsTable">
                                    <?php if (!empty($produits)): ?>
                                        <?php foreach ($produits as $produit): ?>
                                        <tr class="produit-row hover:bg-gray-50" 
                                            data-id="<?php echo $produit['id']; ?>"
                                            data-nom="<?php echo htmlspecialchars($produit['nom']); ?>"
                                            data-categorie="<?php echo $produit['categorie_id']; ?>"
                                            data-statut="<?php echo $produit['statut']; ?>">
                                            <td class="px-4 py-3">
                                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($produit['nom']); ?></div>
                                                <div class="text-xs text-gray-500 truncate max-w-xs"><?php echo htmlspecialchars(substr($produit['description'] ?: 'Aucune description', 0, 50)); ?>...</div>
                                            </td>
                                            <td class="px-4 py-3 text-gray-500">
                                                <?php echo htmlspecialchars($produit['categorie_nom'] ?? 'Non classé'); ?>
                                            </td>
                                            <td class="px-4 py-3 text-gray-500">
                                                <?php echo htmlspecialchars($produit['fournisseur_nom'] ?? 'Non défini'); ?>
                                            </td>
                                            <td class="px-4 py-3 text-gray-500">
                                                <?php echo htmlspecialchars($produit['code_barre'] ?? 'N/A'); ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <?php if ($produit['necessite_ordonnance']): ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                        <i class="fas fa-prescription-bottle-alt mr-1"></i>
                                                        Oui
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                        <i class="fas fa-check mr-1"></i>
                                                        Non
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo getStatutProduitClass($produit['statut']); ?>">
                                                    <?php echo ucfirst($produit['statut']); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center space-x-2">
                                                    <button onclick="loadProduit(<?php echo $produit['id']; ?>)" class="text-blue-600 hover:text-blue-900 transition-colors" title="Voir détails">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button onclick="editProduit(<?php echo $produit['id']; ?>)" class="text-yellow-600 hover:text-yellow-900 transition-colors" title="Modifier">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button onclick="toggleProduitStatut(<?php echo $produit['id']; ?>, '<?php echo $produit['statut']; ?>')" class="text-<?php echo $produit['statut'] == 'actif' ? 'red' : 'green'; ?>-600 hover:text-<?php echo $produit['statut'] == 'actif' ? 'red' : 'green'; ?>-900 transition-colors" title="<?php echo $produit['statut'] == 'actif' ? 'Désactiver' : 'Activer'; ?>">
                                                        <i class="fas fa-<?php echo $produit['statut'] == 'actif' ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                                <i class="fas fa-pills text-2xl mb-2"></i>
                                                <p>Aucun produit trouvé</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Commandes -->
            <div id="commandes" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Gestion des Commandes</h3>
                    </div>
                    <div class="p-6">
                        <!-- Filtres -->
                        <div class="flex flex-wrap gap-4 mb-6">
                            <input type="text" id="searchCommande" placeholder="Rechercher une commande..." class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 flex-1 min-w-[200px]">
                            <select id="filterStatutCommande" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                                <option value="">Tous statuts</option>
                                <option value="en_attente">En attente</option>
                                <option value="paye">Payé</option>
                                <option value="annule">Annulé</option>
                                <option value="rembourse">Remboursé</option>
                            </select>
                            <input type="date" id="filterDate" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>

                        <!-- Liste des commandes -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">N° Commande</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Montant</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Mode Paiement</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200" id="commandesTable">
                                    <?php if (!empty($commandes)): ?>
                                        <?php foreach ($commandes as $commande): ?>
                                        <tr class="commande-row hover:bg-gray-50"
                                            data-id="<?php echo $commande['id']; ?>"
                                            data-numero="<?php echo htmlspecialchars($commande['numero_commande']); ?>"
                                            data-statut="<?php echo $commande['statut']; ?>"
                                            data-date="<?php echo date('Y-m-d', strtotime($commande['date_commande'])); ?>">
                                            <td class="px-4 py-3 font-mono font-medium text-gray-900">
                                                <?php echo htmlspecialchars($commande['numero_commande']); ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($commande['client_nom'] . ' ' . $commande['client_prenom']); ?>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 text-gray-500">
                                                <?php echo date('d/m/Y H:i', strtotime($commande['date_commande'])); ?>
                                            </td>
                                            <td class="px-4 py-3 font-semibold text-green-600">
                                                <?php echo formatMontant($commande['montant_total']); ?>
                                            </td>
                                            <td class="px-4 py-3 text-gray-500">
                                                <?php echo ucfirst($commande['mode_paiement'] ?? 'especes'); ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo getStatutCommandeClass($commande['statut']); ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $commande['statut'])); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center space-x-2">
                                                    <button onclick="loadCommande(<?php echo $commande['id']; ?>)" class="text-blue-600 hover:text-blue-900 transition-colors" title="Voir détails">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($commande['statut'] == 'en_attente'): ?>
                                                    <button onclick="validerPaiement(<?php echo $commande['id']; ?>)" class="text-green-600 hover:text-green-900 transition-colors" title="Valider paiement">
                                                        <i class="fas fa-check-circle"></i>
                                                    </button>
                                                    <button onclick="annulerCommande(<?php echo $commande['id']; ?>)" class="text-red-600 hover:text-red-900 transition-colors" title="Annuler">
                                                        <i class="fas fa-times-circle"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <button onclick="genererFacture(<?php echo $commande['id']; ?>)" class="text-purple-600 hover:text-purple-900 transition-colors" title="Générer facture">
                                                        <i class="fas fa-file-invoice"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                                <i class="fas fa-shopping-cart text-2xl mb-2"></i>
                                                <p>Aucune commande trouvée</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
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
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Créé le</th>
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
                                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $categorie['statut'] == 'actif' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                        <?php echo ucfirst($categorie['statut']); ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-gray-500">
                                                    <?php echo date('d/m/Y', strtotime($categorie['created_at'])); ?>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div class="flex items-center space-x-2">
                                                        <button onclick="loadCategorie(<?php echo $categorie['id']; ?>)" class="text-blue-600 hover:text-blue-900 transition-colors" title="Modifier">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button onclick="toggleCategorieStatut(<?php echo $categorie['id']; ?>, '<?php echo $categorie['statut']; ?>')" class="text-<?php echo $categorie['statut'] == 'actif' ? 'red' : 'green'; ?>-600 hover:text-<?php echo $categorie['statut'] == 'actif' ? 'red' : 'green'; ?>-900 transition-colors" title="<?php echo $categorie['statut'] == 'actif' ? 'Désactiver' : 'Activer'; ?>">
                                                            <i class="fas fa-<?php echo $categorie['statut'] == 'actif' ? 'toggle-on' : 'toggle-off'; ?>"></i>
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
                                            <td colspan="5" class="px-4 py-8 text-center text-gray-500">
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

            <!-- Section Fournisseurs -->
            <div id="fournisseurs" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-900">Gestion des Fournisseurs</h3>
                        <button onclick="openModal('createFournisseurModal')" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors">
                            <i class="fas fa-plus mr-2"></i>
                            Nouveau Fournisseur
                        </button>
                    </div>
                    <div class="p-6">
                        <!-- Liste des fournisseurs -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Société</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email/Téléphone</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Note Qualité</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (!empty($fournisseurs)): ?>
                                        <?php foreach ($fournisseurs as $fournisseur): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3">
                                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($fournisseur['nom_societe']); ?></div>
                                                <?php if ($fournisseur['adresse_siege']): ?>
                                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($fournisseur['adresse_siege']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-gray-500">
                                                <?php echo htmlspecialchars($fournisseur['contact_principal'] ?? 'Non spécifié'); ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="text-sm text-gray-900"><?php echo htmlspecialchars($fournisseur['email'] ?? 'N/A'); ?></div>
                                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($fournisseur['telephone'] ?? 'N/A'); ?></div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center">
                                                    <div class="w-24 bg-gray-200 rounded-full h-2 mr-2">
                                                        <div class="bg-green-600 h-2 rounded-full" style="width: <?php echo min(100, $fournisseur['note_qualite'] * 20); ?>%"></div>
                                                    </div>
                                                    <span class="text-sm font-medium text-gray-700"><?php echo number_format($fournisseur['note_qualite'], 1); ?>/5</span>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center space-x-2">
                                                    <button onclick="viewFournisseur(<?php echo $fournisseur['id']; ?>)" class="text-blue-600 hover:text-blue-900 transition-colors" title="Voir détails">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button onclick="editFournisseur(<?php echo $fournisseur['id']; ?>)" class="text-yellow-600 hover:text-yellow-900 transition-colors" title="Modifier">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                                <i class="fas fa-truck text-2xl mb-2"></i>
                                                <p>Aucun fournisseur trouvé</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Alertes -->
            <div id="alertes" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-900">Alertes et Notifications</h3>
                        <button onclick="marquerToutesLues()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors">
                            <i class="fas fa-check-double mr-2"></i>
                            Tout marquer comme lu
                        </button>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <?php if (!empty($alertes)): ?>
                                <?php foreach ($alertes as $alerte): ?>
                                <div class="flex items-start p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors <?php echo $alerte['statut'] == 'non_lu' ? 'bg-red-50 border-red-200' : ''; ?>">
                                    <div class="mr-4 mt-1">
                                        <?php switch ($alerte['type_alerte']): 
                                            case 'stock_bas': ?>
                                                <i class="fas fa-exclamation-triangle text-yellow-600 text-xl"></i>
                                                <?php break; ?>
                                            <?php case 'peremption': ?>
                                                <i class="fas fa-clock text-red-600 text-xl"></i>
                                                <?php break; ?>
                                            <?php case 'rupture_stock': ?>
                                                <i class="fas fa-times-circle text-red-600 text-xl"></i>
                                                <?php break; ?>
                                            <?php default: ?>
                                                <i class="fas fa-bell text-blue-600 text-xl"></i>
                                        <?php endswitch; ?>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <h4 class="font-semibold text-gray-900"><?php echo htmlspecialchars($alerte['produit_nom']); ?></h4>
                                                <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($alerte['message']); ?></p>
                                            </div>
                                            <span class="text-xs px-2 py-1 rounded-full 
                                                <?php echo $alerte['niveau'] == 'critique' ? 'bg-red-100 text-red-800' : 
                                                       ($alerte['niveau'] == 'moyen' ? 'bg-orange-100 text-orange-800' : 
                                                        'bg-yellow-100 text-yellow-800'); ?>">
                                                <?php echo ucfirst($alerte['niveau']); ?>
                                            </span>
                                        </div>
                                        <div class="mt-2 flex justify-between items-center">
                                            <span class="text-xs text-gray-500">
                                                <?php echo date('d/m/Y H:i', strtotime($alerte['created_at'])); ?>
                                            </span>
                                            <div class="flex space-x-2">
                                                <?php if ($alerte['statut'] == 'non_lu'): ?>
                                                <button onclick="marquerAlerteLue(<?php echo $alerte['id']; ?>)" class="text-sm text-blue-600 hover:text-blue-800">
                                                    Marquer comme lu
                                                </button>
                                                <?php endif; ?>
                                                <button onclick="traiterAlerte(<?php echo $alerte['id']; ?>)" class="text-sm text-green-600 hover:text-green-800">
                                                    Traiter
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-12 text-gray-500">
                                    <i class="fas fa-bell-slash text-2xl mb-2"></i>
                                    <p class="text-lg">Aucune alerte non lue</p>
                                    <p class="text-sm mt-1">Toutes les alertes sont traitées</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Lots -->
            <div id="lots" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-900">Gestion des Lots</h3>
                        <button onclick="openModal('createLotModal')" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors">
                            <i class="fas fa-plus mr-2"></i>
                            Nouveau Lot
                        </button>
                    </div>
                    <div class="p-6">
                        <!-- Statistiques lots -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                            <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-3">
                                        <i class="fas fa-box text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-blue-900">Total Lots</p>
                                        <p class="text-xl font-bold text-blue-600"><?php echo $stats_lots['total_lots']; ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-green-50 p-4 rounded-lg border border-green-200">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                                        <i class="fas fa-boxes text-green-600"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-green-900">Stock Total</p>
                                        <p class="text-xl font-bold text-green-600"><?php echo $stats_lots['stock_total']; ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-red-50 p-4 rounded-lg border border-red-200">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-red-100 rounded-lg flex items-center justify-center mr-3">
                                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-red-900">Lots Périmés</p>
                                        <p class="text-xl font-bold text-red-600"><?php echo $stats_lots['lots_perimes']; ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-orange-50 p-4 rounded-lg border border-orange-200">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center mr-3">
                                        <i class="fas fa-clock text-orange-600"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-orange-900">Expiration < 30j</p>
                                        <p class="text-xl font-bold text-orange-600"><?php echo $stats_lots['expiration_proche']; ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Produits en expiration proche -->
                        <h4 class="text-lg font-semibold text-gray-900 mb-4">Produits en Expiration Proche</h4>
                        <div class="overflow-x-auto mb-8">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produit</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">N° Lot</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantité</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Expire le</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Jours restants</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (!empty($produits_expiration)): ?>
                                        <?php foreach ($produits_expiration as $produit): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 font-medium text-gray-900">
                                                <?php echo htmlspecialchars($produit['nom']); ?>
                                            </td>
                                            <td class="px-4 py-3 text-gray-500 font-mono">
                                                <?php echo $produit['numero_lot']; ?>
                                            </td>
                                            <td class="px-4 py-3 text-gray-500">
                                                <?php echo $produit['quantite_actuelle']; ?> unités
                                            </td>
                                            <td class="px-4 py-3 text-gray-500">
                                                <?php echo date('d/m/Y', strtotime($produit['date_expiration'])); ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo getJoursRestantsClass($produit['jours_restants']); ?>">
                                                    <i class="fas fa-clock mr-1"></i>
                                                    <?php echo $produit['jours_restants']; ?> jours
                                                </span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <button onclick="promotionExpiration(<?php echo $produit['jours_restants']; ?>, '<?php echo addslashes($produit['nom']); ?>')" class="text-purple-600 hover:text-purple-900 transition-colors" title="Créer promotion">
                                                    <i class="fas fa-tag"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                                <i class="fas fa-check-circle text-2xl mb-2 text-green-500"></i>
                                                <p>Aucun produit en expiration proche</p>
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
                        <h3 class="text-xl font-semibold text-gray-900">Analyse Financière</h3>
                    </div>
                    <div class="p-6">
                        <!-- Le contenu finances reste le même -->
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

                            <!-- Graphique évolution -->
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900 mb-4">Évolution Mensuelle</h4>
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <?php if (!empty($evolution_ca_data)): ?>
                                        <div class="flex items-end justify-between h-48">
                                            <?php foreach ($evolution_ca_data as $mois): ?>
                                            <div class="flex flex-col items-center flex-1">
                                                <div class="text-xs text-gray-500 mb-2">
                                                    <?php echo substr($mois['mois_format'], 0, 3); ?>
                                                </div>
                                                <div class="w-full px-1">
                                                    <div 
                                                        class="bg-gradient-to-t from-teal-500 to-teal-600 rounded-t hover:from-teal-600 hover:to-teal-700 cursor-pointer mx-auto"
                                                        style="height: <?php echo max(5, ($mois['ca'] / max(array_column($evolution_ca_data, 'ca'))) * 90); ?>%;"
                                                        title="<?php echo $mois['mois_format']; ?>: <?php echo formatMontant($mois['ca']); ?>"
                                                    ></div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-8 text-gray-500">
                                            <i class="fas fa-chart-line text-2xl mb-2"></i>
                                            <p>Aucune donnée financière</p>
                                        </div>
                                    <?php endif; ?>
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
                        <!-- Le contenu performance reste le même -->
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
                                    <?php echo $commandes_aujourdhui; ?> commandes
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
                                                round((($total_produits - $produits_rupture) / $total_produits) * 100) : 0; ?>%
                                        </p>
                                    </div>
                                    <i class="fas fa-sync-alt text-2xl opacity-80"></i>
                                </div>
                                <div class="mt-2 text-xs opacity-90">
                                    Produits actifs
                                </div>
                            </div>
                        </div>

                        <!-- Top produits -->
                        <div class="mb-8">
                            <h4 class="text-lg font-semibold text-gray-900 mb-4">Top Produits par Chiffre d'Affaires</h4>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produit</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantité</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">CA Total</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (!empty($top_produits)): ?>
                                            <?php foreach ($top_produits as $produit): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-4 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($produit['nom']); ?></td>
                                                    <td class="px-4 py-3 text-gray-500"><?php echo $produit['total_vendu']; ?> unités</td>
                                                    <td class="px-4 py-3 font-semibold text-green-600"><?php echo formatMontant($produit['ca_total']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" class="px-4 py-8 text-center text-gray-500">
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

            <!-- Sections supplémentaires (utilisateurs et paramètres) -->
            <div id="utilisateurs" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Gestion des Utilisateurs</h3>
                    </div>
                    <div class="p-6">
                        <div class="text-center py-12 text-gray-500">
                            <i class="fas fa-users text-2xl mb-2"></i>
                            <p>Section en cours de développement</p>
                        </div>
                    </div>
                </div>
            </div>

            <div id="parametres" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Paramètres du Système</h3>
                    </div>
                    <div class="p-6">
                        <div class="text-center py-12 text-gray-500">
                            <i class="fas fa-cog text-2xl mb-2"></i>
                            <p>Section en cours de développement</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modales (ajouter les modales pour produits, commandes, fournisseurs, etc.) -->
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
                        </div>
                        <div class="mt-6 flex justify-end space-x-3">
                            <button type="button" onclick="closeModal('editCategorieModal')" class="px-4 py-2 text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Annuler</button>
                            <button type="submit" name="modifier_categorie" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">Modifier la catégorie</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Voir Détails Produit -->
            <div id="viewProduitModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900" id="produitModalTitle">Détails du Produit</h3>
                    </div>
                    <div class="p-6" id="produitModalContent">
                        <!-- Contenu chargé dynamiquement -->
                    </div>
                    <div class="px-6 py-4 border-t">
                        <button type="button" onclick="closeModal('viewProduitModal')" class="px-4 py-2 text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Fermer</button>
                    </div>
                </div>
            </div>

            <!-- Modal Voir Détails Commande -->
            <div id="viewCommandeModal" class="modal fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl mx-4 max-h-[90vh] overflow-y-auto">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900" id="commandeModalTitle">Détails de la Commande</h3>
                    </div>
                    <div class="p-6" id="commandeModalContent">
                        <!-- Contenu chargé dynamiquement -->
                    </div>
                    <div class="px-6 py-4 border-t">
                        <button type="button" onclick="closeModal('viewCommandeModal')" class="px-4 py-2 text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Fermer</button>
                    </div>
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
                'produits': 'Gestion des Produits',
                'commandes': 'Gestion des Commandes',
                'categories': 'Gestion des Catégories',
                'fournisseurs': 'Gestion des Fournisseurs',
                'alertes': 'Alertes et Notifications',
                'lots': 'Gestion des Lots',
                'finances': 'Analyse Financière',
                'performance': 'Performance Commerciale',
                'utilisateurs': 'Gestion des Utilisateurs',
                'parametres': 'Paramètres du Système'
            };
            document.getElementById('pageTitle').textContent = titles[sectionId] || 'Tableau de bord Direction';
        }

        // Gestion des modales
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
                    
                    openModal('editCategorieModal');
                }
            } catch (error) {
                console.error('Erreur:', error);
            }
        }

        // Charger les données d'un produit
        async function loadProduit(id) {
            try {
                const response = await fetch(`?action=get_produit&id=${id}`);
                const produit = await response.json();
                
                if (produit) {
                    document.getElementById('produitModalTitle').textContent = produit.nom;
                    
                    let html = `
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 class="font-semibold text-gray-900 mb-2">Informations Générales</h4>
                                <div class="space-y-2">
                                    <div><span class="font-medium">Nom:</span> ${produit.nom}</div>
                                    <div><span class="font-medium">Catégorie:</span> ${produit.categorie_nom}</div>
                                    <div><span class="font-medium">Fournisseur:</span> ${produit.fournisseur_nom}</div>
                                    <div><span class="font-medium">Code barre:</span> ${produit.code_barre || 'N/A'}</div>
                                    <div><span class="font-medium">Ordonnance requise:</span> ${produit.necessite_ordonnance ? 'Oui' : 'Non'}</div>
                                    <div><span class="font-medium">Statut:</span> ${produit.statut}</div>
                                </div>
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-900 mb-2">Informations Commerciales</h4>
                                <div class="space-y-2">
                                    <div><span class="font-medium">Prix FC:</span> ${produit.prix_fc ? produit.prix_fc.toLocaleString() + ' FC' : 'Non défini'}</div>
                                    <div><span class="font-medium">Prix USD:</span> ${produit.prix_usd ? '$' + produit.prix_usd : 'Non défini'}</div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    if (produit.description) {
                        html += `
                            <div class="mt-6">
                                <h4 class="font-semibold text-gray-900 mb-2">Description</h4>
                                <p class="text-gray-600">${produit.description}</p>
                            </div>
                        `;
                    }
                    
                    document.getElementById('produitModalContent').innerHTML = html;
                    openModal('viewProduitModal');
                }
            } catch (error) {
                console.error('Erreur:', error);
            }
        }

        // Charger les données d'une commande
        async function loadCommande(id) {
            try {
                const response = await fetch(`?action=get_commande&id=${id}`);
                const commande = await response.json();
                
                if (commande) {
                    document.getElementById('commandeModalTitle').textContent = `Commande ${commande.numero_commande}`;
                    
                    let html = `
                        <div class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-2">Informations Client</h4>
                                    <div class="space-y-1">
                                        <div><span class="font-medium">Client:</span> ${commande.client_nom} ${commande.client_prenom}</div>
                                        <div><span class="font-medium">Caissier:</span> ${commande.caissier_nom ? commande.caissier_nom + ' ' + commande.caissier_prenom : 'Non assigné'}</div>
                                    </div>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-900 mb-2">Informations Commande</h4>
                                    <div class="space-y-1">
                                        <div><span class="font-medium">Date:</span> ${new Date(commande.date_commande).toLocaleString()}</div>
                                        <div><span class="font-medium">Statut:</span> ${commande.statut}</div>
                                        <div><span class="font-medium">Mode paiement:</span> ${commande.mode_paiement}</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div>
                                <h4 class="font-semibold text-gray-900 mb-2">Détails des Produits</h4>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-2 text-left">Produit</th>
                                                <th class="px-4 py-2 text-left">Lot</th>
                                                <th class="px-4 py-2 text-left">Quantité</th>
                                                <th class="px-4 py-2 text-left">Prix Unitaire</th>
                                                <th class="px-4 py-2 text-left">Sous-total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                    `;
                    
                    if (commande.details && commande.details.length > 0) {
                        commande.details.forEach(detail => {
                            html += `
                                <tr class="border-t">
                                    <td class="px-4 py-2">${detail.produit_nom}</td>
                                    <td class="px-4 py-2">${detail.numero_lot}</td>
                                    <td class="px-4 py-2">${detail.quantite}</td>
                                    <td class="px-4 py-2">${detail.prix_unitaire.toLocaleString()} FC</td>
                                    <td class="px-4 py-2 font-medium">${detail.sous_total.toLocaleString()} FC</td>
                                </tr>
                            `;
                        });
                    } else {
                        html += `
                            <tr>
                                <td colspan="5" class="px-4 py-4 text-center text-gray-500">Aucun détail trouvé</td>
                            </tr>
                        `;
                    }
                    
                    html += `
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <div class="border-t pt-4">
                                <div class="flex justify-end">
                                    <div class="text-right">
                                        <div class="text-lg font-bold text-gray-900">Total: ${parseFloat(commande.montant_total).toLocaleString()} FC</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    document.getElementById('commandeModalContent').innerHTML = html;
                    openModal('viewCommandeModal');
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

        // Filtrer les produits
        document.addEventListener('DOMContentLoaded', function() {
            // Initialisation
            showSection('dashboard');
            
            // Filtre produits
            const searchProduit = document.getElementById('searchProduit');
            const filterCategorie = document.getElementById('filterCategorie');
            const filterStatut = document.getElementById('filterStatut');
            
            if (searchProduit) {
                searchProduit.addEventListener('input', filterProduits);
            }
            if (filterCategorie) {
                filterCategorie.addEventListener('change', filterProduits);
            }
            if (filterStatut) {
                filterStatut.addEventListener('change', filterProduits);
            }
            
            // Filtre commandes
            const searchCommande = document.getElementById('searchCommande');
            const filterStatutCommande = document.getElementById('filterStatutCommande');
            const filterDate = document.getElementById('filterDate');
            
            if (searchCommande) {
                searchCommande.addEventListener('input', filterCommandes);
            }
            if (filterStatutCommande) {
                filterStatutCommande.addEventListener('change', filterCommandes);
            }
            if (filterDate) {
                filterDate.addEventListener('change', filterCommandes);
            }
            
            // Fermer les modales en cliquant à l'extérieur
            document.querySelectorAll('.modal').forEach(modal => {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeModal(this.id);
                    }
                });
            });
        });

        function filterProduits() {
            const search = document.getElementById('searchProduit').value.toLowerCase();
            const categorie = document.getElementById('filterCategorie').value;
            const statut = document.getElementById('filterStatut').value;
            
            document.querySelectorAll('#produitsTable .produit-row').forEach(row => {
                const nom = row.getAttribute('data-nom').toLowerCase();
                const rowCategorie = row.getAttribute('data-categorie');
                const rowStatut = row.getAttribute('data-statut');
                
                const matchSearch = nom.includes(search);
                const matchCategorie = !categorie || rowCategorie === categorie;
                const matchStatut = !statut || rowStatut === statut;
                
                row.style.display = (matchSearch && matchCategorie && matchStatut) ? '' : 'none';
            });
        }

        function filterCommandes() {
            const search = document.getElementById('searchCommande').value.toLowerCase();
            const statut = document.getElementById('filterStatutCommande').value;
            const date = document.getElementById('filterDate').value;
            
            document.querySelectorAll('#commandesTable .commande-row').forEach(row => {
                const numero = row.getAttribute('data-numero').toLowerCase();
                const rowStatut = row.getAttribute('data-statut');
                const rowDate = row.getAttribute('data-date');
                
                const matchSearch = numero.includes(search);
                const matchStatut = !statut || rowStatut === statut;
                const matchDate = !date || rowDate === date;
                
                row.style.display = (matchSearch && matchStatut && matchDate) ? '' : 'none';
            });
        }

        // Fonctions utilitaires
        function toggleProduitStatut(id, currentStatut) {
            const nouveauStatut = currentStatut === 'actif' ? 'inactif' : 'actif';
            if (confirm(`Voulez-vous ${nouveauStatut === 'actif' ? 'activer' : 'désactiver'} ce produit ?`)) {
                // Envoyer une requête pour changer le statut
                fetch(`/api/produits/${id}/statut`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ statut: nouveauStatut })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                })
                .catch(error => console.error('Erreur:', error));
            }
        }

        function toggleCategorieStatut(id, currentStatut) {
            const nouveauStatut = currentStatut === 'actif' ? 'inactif' : 'actif';
            if (confirm(`Voulez-vous ${nouveauStatut === 'actif' ? 'activer' : 'désactiver'} cette catégorie ?`)) {
                // Envoyer une requête pour changer le statut
                fetch(`/api/categories/${id}/statut`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ statut: nouveauStatut })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                })
                .catch(error => console.error('Erreur:', error));
            }
        }

        function validerPaiement(id) {
            if (confirm('Valider le paiement de cette commande ?')) {
                fetch(`/api/commandes/${id}/payer`, {
                    method: 'PUT'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                })
                .catch(error => console.error('Erreur:', error));
            }
        }

        function annulerCommande(id) {
            if (confirm('Annuler cette commande ?')) {
                fetch(`/api/commandes/${id}/annuler`, {
                    method: 'PUT'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                })
                .catch(error => console.error('Erreur:', error));
            }
        }

        function marquerAlerteLue(id) {
            fetch(`/api/alertes/${id}/lire`, {
                method: 'PUT'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            })
            .catch(error => console.error('Erreur:', error));
        }

        function marquerToutesLues() {
            if (confirm('Marquer toutes les alertes comme lues ?')) {
                fetch(`/api/alertes/tout-lire`, {
                    method: 'PUT'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                })
                .catch(error => console.error('Erreur:', error));
            }
        }

        function traiterAlerte(id) {
            fetch(`/api/alertes/${id}/traiter`, {
                method: 'PUT'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            })
            .catch(error => console.error('Erreur:', error));
        }

        function promotionExpiration(jours, produit) {
            alert(`Créer une promotion pour "${produit}" (${jours} jours avant expiration)`);
        }

        function genererFacture(id) {
            window.open(`/factures/generer.php?commande_id=${id}`, '_blank');
        }
    </script>
</body>
</html>