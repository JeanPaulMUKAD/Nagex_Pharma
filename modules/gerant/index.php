<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/journal_functions.php';

// Activer l'affichage des erreurs pour debug
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Vérifier l'authentification et le rôle
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !in_array($_SESSION['user_role'], ['admin', 'gerant'])) {
    header('Location: /login.php');
    exit;
}

$db = new Database();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_nom'];
$user_role = $_SESSION['user_role'];

// Déterminer quelle section afficher (par défaut dashboard)
$section = $_GET['section'] ?? 'dashboard';

// ============================================================================
// CHARGEMENT DES DONNÉES EN FONCTION DE LA SECTION
// ============================================================================

$data = [];

try {
    switch ($section) {
        case 'dashboard':
            // Charger toutes les données pour le dashboard
            $data = loadDashboardData($db, $user_id);
            break;

        case 'produits':
            $data = loadProduitsData($db);
            break;

        case 'commandes':
            $data = loadCommandesData($db);
            break;

        case 'categories':
            $data = loadCategoriesData($db);
            break;

        case 'fournisseurs':
            $data = loadFournisseursData($db);
            break;

        case 'utilisateurs':
            $data = loadUtilisateursData($db);
            break;

        case 'journal':
            $data = loadJournalData($db, $user_id);
            break;

        default:
            $data = loadDashboardData($db, $user_id);
            $section = 'dashboard';
    }

} catch (Exception $e) {
    error_log("Erreur lors du chargement des données: " . $e->getMessage());
    error_log("Fichier: " . $e->getFile());
    error_log("Ligne: " . $e->getLine());
    error_log("Trace: " . $e->getTraceAsString());

    // Afficher l'erreur complète
    $data = ['error' => 'Erreur: ' . $e->getMessage() . ' dans ' . $e->getFile() . ' à la ligne ' . $e->getLine()];
}

// Afficher l'erreur pour debug (visible sur la page)
if (isset($data['error']) && $section == 'utilisateurs') {
    echo '<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
        <div class="flex items-center">
            <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>
            <span class="text-red-800 font-bold">DEBUG - Erreur détectée:</span>
        </div>
        <div class="mt-2 text-sm text-red-700">
            ' . htmlspecialchars($data['error']) . '
        </div>
    </div>';
}

// ============================================================================
// FONCTIONS DE CHARGEMENT DES DONNÉES
// ============================================================================

function loadDashboardData($db, $current_user_id = null)
{
    $data = [];

    // 1. Statistiques générales
    $data['stats'] = [
        'total_produits' => 0,
        'total_categories' => 0,
        'total_commandes_mois' => 0,
        'total_fournisseurs' => 0,
        'ca_mensuel' => 0,
        'ca_mois_precedent' => 0,
        'evolution_ca' => 0,
        'marge_moyenne' => 15.5,
        'valeur_stock' => 0,
        'produits_rupture' => 0,
        'commandes_aujourdhui' => 0,
        'ca_aujourdhui' => 0,
        'commandes_attente' => 0
    ];

    // 2. Récupérer les catégories (pour les counts)
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM categories");
    $stmt->execute();
    $data['stats']['total_categories'] = (int) $stmt->fetchColumn();

    // 3. Récupérer les produits
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM produits");
    $stmt->execute();
    $data['stats']['total_produits'] = (int) $stmt->fetchColumn();

    // 4. Récupérer les fournisseurs
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM fournisseurs");
    $stmt->execute();
    $data['stats']['total_fournisseurs'] = (int) $stmt->fetchColumn();

    // 5. Commandes ce mois
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM commandes WHERE MONTH(date_commande) = MONTH(CURDATE()) AND YEAR(date_commande) = YEAR(CURDATE())");
    $stmt->execute();
    $data['stats']['total_commandes_mois'] = (int) $stmt->fetchColumn();

    // 6. CA Mensuel
    $stmt = $db->prepare("SELECT COALESCE(SUM(montant_total), 0) as ca FROM commandes WHERE MONTH(date_commande) = MONTH(CURDATE()) AND YEAR(date_commande) = YEAR(CURDATE())");
    $stmt->execute();
    $data['stats']['ca_mensuel'] = (float) $stmt->fetchColumn();

    // 7. CA Mois précédent
    $stmt = $db->prepare("SELECT COALESCE(SUM(montant_total), 0) as ca FROM commandes WHERE MONTH(date_commande) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(date_commande) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))");
    $stmt->execute();
    $data['stats']['ca_mois_precedent'] = (float) $stmt->fetchColumn();

    // 8. Calcul évolution CA
    if ($data['stats']['ca_mois_precedent'] > 0) {
        $data['stats']['evolution_ca'] = (($data['stats']['ca_mensuel'] - $data['stats']['ca_mois_precedent']) / $data['stats']['ca_mois_precedent']) * 100;
    } else {
        $data['stats']['evolution_ca'] = ($data['stats']['ca_mensuel'] > 0) ? 100 : 0;
    }

    // 9. Commandes aujourd'hui
    $stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(montant_total), 0) as ca FROM commandes WHERE DATE(date_commande) = CURDATE()");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $data['stats']['commandes_aujourdhui'] = (int) $result['count'];
    $data['stats']['ca_aujourdhui'] = (float) $result['ca'];

    // 10. Commandes en attente
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM commandes WHERE statut = 'en_attente'");
    $stmt->execute();
    $data['stats']['commandes_attente'] = (int) $stmt->fetchColumn();

    // 11. Valeur du stock
    $stmt = $db->prepare("SELECT COALESCE(SUM(quantite_actuelle * prix_achat), 0) as valeur FROM lots WHERE quantite_actuelle > 0");
    $stmt->execute();
    $data['stats']['valeur_stock'] = (float) $stmt->fetchColumn();

    // 12. Produits en rupture
    $stmt = $db->prepare("SELECT COUNT(DISTINCT produit_id) as count FROM lots GROUP BY produit_id HAVING SUM(quantite_actuelle) = 0");
    $stmt->execute();
    $data['stats']['produits_rupture'] = (int) $stmt->fetchColumn();

    // 13. Statistiques des lots
    $data['stats_lots'] = [
        'total_lots' => 0,
        'stock_total' => 0,
        'lots_epuises' => 0,
        'lots_perimes' => 0,
        'expiration_proche' => 0
    ];

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM lots");
    $stmt->execute();
    $data['stats_lots']['total_lots'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(quantite_actuelle), 0) as total FROM lots");
    $stmt->execute();
    $data['stats_lots']['stock_total'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM lots WHERE statut = 'epuise'");
    $stmt->execute();
    $data['stats_lots']['lots_epuises'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM lots WHERE date_expiration < CURDATE()");
    $stmt->execute();
    $data['stats_lots']['lots_perimes'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM lots WHERE date_expiration BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $stmt->execute();
    $data['stats_lots']['expiration_proche'] = (int) $stmt->fetchColumn();

    // 14. Top produits
    $stmt = $db->prepare("
        SELECT p.nom, 
               COUNT(cd.id) as total_vendu,
               COALESCE(SUM(cd.sous_total), 0) as ca_total 
        FROM produits p 
        LEFT JOIN commande_details cd ON p.id = cd.produit_id 
        GROUP BY p.id 
        ORDER BY total_vendu DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $data['top_produits'] = $stmt->fetchAll(PDO::FETCH_ASSOC);



    // 15. Évolution CA 6 mois
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(date_commande, '%Y-%m') as mois,
            DATE_FORMAT(date_commande, '%M %Y') as mois_format,
            COALESCE(SUM(montant_total), 0) as ca,
            COUNT(*) as nb_commandes
        FROM commandes 
        WHERE date_commande >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) 
        GROUP BY DATE_FORMAT(date_commande, '%Y-%m'), DATE_FORMAT(date_commande, '%M %Y') 
        ORDER BY mois ASC
    ");
    $stmt->execute();
    $data['evolution_ca'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 16. Produits expirant bientôt
    $stmt = $db->prepare("
        SELECT p.nom, l.numero_lot, l.date_expiration, l.quantite_actuelle, 
               DATEDIFF(l.date_expiration, CURDATE()) as jours_restants 
        FROM lots l 
        LEFT JOIN produits p ON l.produit_id = p.id 
        WHERE l.date_expiration BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
        AND l.quantite_actuelle > 0 
        ORDER BY l.date_expiration ASC 
        LIMIT 10
    ");
    $stmt->execute();
    $data['produits_expiration'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 18. Dernières activités
    try {
        $stmt = $db->prepare("
            SELECT ja.*, u.nom as utilisateur_nom_complet 
            FROM journal_activites ja
            LEFT JOIN utilisateurs u ON ja.utilisateur_id = u.id
            ORDER BY ja.created_at DESC 
            LIMIT 10
        ");
        $stmt->execute();
        $data['dernieres_activites'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Erreur chargement activités: " . $e->getMessage());
        $data['dernieres_activites'] = [];
    }

    return $data;
}

function loadProduitsData($db)
{
    $data = [];

    // Récupérer tous les produits avec leurs catégories et fournisseurs
    $stmt = $db->prepare("
        SELECT p.*, 
               c.nom as categorie_nom,
               f.nom_societe as fournisseur_nom
        FROM produits p 
        LEFT JOIN categories c ON p.categorie_id = c.id 
        LEFT JOIN fournisseurs f ON p.fournisseur_id = f.id
        ORDER BY p.created_at DESC
    ");
    $stmt->execute();
    $data['produits'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Récupérer les catégories pour le filtre
    $stmt = $db->prepare("SELECT id, nom FROM categories ORDER BY nom");
    $stmt->execute();
    $data['categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Récupérer les fournisseurs pour le filtre
    $stmt = $db->prepare("SELECT id, nom_societe FROM fournisseurs ORDER BY nom_societe");
    $stmt->execute();
    $data['fournisseurs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $data;
}

function loadCommandesData($db)
{
    $data = [];

    // Récupérer les commandes avec leurs clients
    $stmt = $db->prepare("
        SELECT c.*, u.nom as client_nom, u.prenom as client_prenom
        FROM commandes c 
        LEFT JOIN utilisateurs u ON c.client_id = u.id 
        ORDER BY c.date_commande DESC 
        LIMIT 100
    ");
    $stmt->execute();
    $data['commandes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return $data;
}

function loadCategoriesData($db)
{
    $data = [];

    // Récupérer toutes les catégories
    $stmt = $db->prepare("SELECT * FROM categories ORDER BY nom");
    $stmt->execute();
    $data['categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compter les produits par catégorie
    $stmt = $db->prepare("
        SELECT c.id, COUNT(p.id) as nb_produits
        FROM categories c
        LEFT JOIN produits p ON c.id = p.categorie_id
        GROUP BY c.id
    ");
    $stmt->execute();
    $produits_par_categorie = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Ajouter le nombre de produits à chaque catégorie
    foreach ($data['categories'] as &$categorie) {
        $categorie['nb_produits'] = $produits_par_categorie[$categorie['id']] ?? 0;
    }

    return $data;
}

function loadFournisseursData($db)
{
    $data = [];

    // Récupérer tous les fournisseurs
    $stmt = $db->prepare("SELECT * FROM fournisseurs ORDER BY nom_societe");
    $stmt->execute();
    $data['fournisseurs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compter les produits par fournisseur
    $stmt = $db->prepare("
        SELECT f.id, COUNT(p.id) as nb_produits
        FROM fournisseurs f
        LEFT JOIN produits p ON f.id = p.fournisseur_id
        GROUP BY f.id
    ");
    $stmt->execute();
    $produits_par_fournisseur = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Ajouter le nombre de produits à chaque fournisseur
    foreach ($data['fournisseurs'] as &$fournisseur) {
        $fournisseur['nb_produits'] = $produits_par_fournisseur[$fournisseur['id']] ?? 0;
    }

    return $data;
}

function loadUtilisateursData($db)
{
    $data = [];

    try {
        // Récupérer les utilisateurs avec les bonnes colonnes
        $sql = "SELECT id, nom, email, role, telephone, adresse, date_creation, statut 
                FROM utilisateurs 
                WHERE statut = 'actif' 
                ORDER BY date_creation DESC";

        error_log("SQL utilisateurs: " . $sql);

        $stmt = $db->prepare($sql);
        $stmt->execute();
        $data['utilisateurs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Compter par rôle
        $stmt = $db->prepare("SELECT role, COUNT(*) as count FROM utilisateurs WHERE statut = 'actif' GROUP BY role");
        $stmt->execute();
        $data['stats_roles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Log pour debug
        error_log("Nombre d'utilisateurs récupérés: " . count($data['utilisateurs']));
        if (!empty($data['utilisateurs'])) {
            error_log("Premier utilisateur: " . json_encode($data['utilisateurs'][0]));
        }

    } catch (Exception $e) {
        error_log("Erreur loadUtilisateursData: " . $e->getMessage());
        $data['utilisateurs'] = [];
        $data['stats_roles'] = [];
    }

    return $data;
}

function loadJournalData($db, $current_user_id = null)
{
    $data = [];

    try {
        // Récupérer TOUTES les activités (y compris celles du gérant)
        $sql = "
            SELECT ja.*, u.nom as utilisateur_nom_complet 
            FROM journal_activites ja
            LEFT JOIN utilisateurs u ON ja.utilisateur_id = u.id
            ORDER BY ja.created_at DESC 
            LIMIT 100
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute();
        $data['activites'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Statistiques par jour
        $stmt = $db->prepare("
            SELECT DATE(created_at) as date,
                   COUNT(*) as total,
                   SUM(CASE WHEN action LIKE 'connexion_%' THEN 1 ELSE 0 END) as connexions,
                   SUM(CASE WHEN action LIKE 'creation_%' THEN 1 ELSE 0 END) as creations,
                   SUM(CASE WHEN action LIKE 'modification_%' THEN 1 ELSE 0 END) as modifications,
                   SUM(CASE WHEN action LIKE 'suppression_%' THEN 1 ELSE 0 END) as suppressions
            FROM journal_activites 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        $stmt->execute();
        $data['stats_journal'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("Erreur chargement journal: " . $e->getMessage());
        $data['activites'] = [];
        $data['stats_journal'] = [];
    }

    return $data;
}

// ============================================================================
// FONCTIONS UTILITAIRES POUR LE FORMATAGE
// ============================================================================

function formatMontant($montant)
{
    if ($montant == 0)
        return '0 FC';
    return number_format($montant, 0, ',', ' ') . ' FC';
}

function formatPourcentage($valeur)
{
    return number_format($valeur, 1, ',', ' ') . '%';
}

function getEvolutionIcon($valeur)
{
    if ($valeur > 0)
        return ['fas fa-arrow-up', 'text-green-600', 'bg-green-100'];
    if ($valeur < 0)
        return ['fas fa-arrow-down', 'text-red-600', 'bg-red-100'];
    return ['fas fa-minus', 'text-gray-600', 'bg-gray-100'];
}

function getStatutCommandeClass($statut)
{
    switch ($statut) {
        case 'paye':
            return 'bg-green-100 text-green-800';
        case 'en_attente':
            return 'bg-yellow-100 text-yellow-800';
        case 'annule':
            return 'bg-red-100 text-red-800';
        case 'rembourse':
            return 'bg-blue-100 text-blue-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}

function getStatutProduitClass($statut)
{
    switch ($statut) {
        case 'actif':
            return 'bg-green-100 text-green-800';
        case 'inactif':
            return 'bg-red-100 text-red-800';
        case 'en_attente':
            return 'bg-yellow-100 text-yellow-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}

function getJoursRestantsClass($jours)
{
    if ($jours <= 7)
        return 'bg-red-100 text-red-800';
    if ($jours <= 15)
        return 'bg-orange-100 text-orange-800';
    return 'bg-yellow-100 text-yellow-800';
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Gérant - Pharma Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap"
        rel="stylesheet">
    <style>
        .sidebar {
            transition: all 0.3s ease;
        }

        .active {
            background-color: #10B981;
            color: white;
        }

        .sidebar-item:hover {
            background-color: #D1FAE5;
        }

        .chart-bar {
            transition: all 0.3s ease;
        }

        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
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
                    <p class="text-xs text-indigo-600">Dashboard Gérant</p>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="mt-6">
            <div class="px-4 space-y-2">
                <a href="?section=dashboard"
                    class="<?php echo $section == 'dashboard' ? 'active' : ''; ?> flex items-center px-4 py-3 text-gray-700 rounded-lg transition-colors">
                    <i class="fas fa-tachometer-alt w-6"></i>
                    <span class="ml-3 font-medium">Tableau de bord</span>
                </a>

                <!-- Gestion des données -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Gestion des
                        Données</p>

                    <a href="?section=produits"
                        class="<?php echo $section == 'produits' ? 'active' : ''; ?> flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-pills w-6 text-blue-500"></i>
                            <span class="ml-3 font-medium">Produits</span>
                        </div>
                        <span class="bg-blue-500 text-white text-xs px-2 py-1 rounded-full">
                            <?php echo $data['stats']['total_produits'] ?? 0; ?>
                        </span>
                    </a>

                    <a href="?section=commandes"
                        class="<?php echo $section == 'commandes' ? 'active' : ''; ?> flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-shopping-cart w-6 text-green-500"></i>
                            <span class="ml-3 font-medium">Commandes</span>
                        </div>
                        <span class="bg-green-500 text-white text-xs px-2 py-1 rounded-full">
                            <?php echo $data['stats']['total_commandes_mois'] ?? 0; ?>
                        </span>
                    </a>

                    <a href="?section=categories"
                        class="<?php echo $section == 'categories' ? 'active' : ''; ?> flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-tags w-6 text-indigo-500"></i>
                            <span class="ml-3 font-medium">Catégories</span>
                        </div>
                        <span class="bg-indigo-500 text-white text-xs px-2 py-1 rounded-full">
                            <?php echo $data['stats']['total_categories'] ?? 0; ?>
                        </span>
                    </a>

                    <a href="?section=fournisseurs"
                        class="<?php echo $section == 'fournisseurs' ? 'active' : ''; ?> flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-truck w-6 text-purple-500"></i>
                            <span class="ml-3 font-medium">Fournisseurs</span>
                        </div>
                        <span class="bg-purple-500 text-white text-xs px-2 py-1 rounded-full">
                            <?php echo $data['stats']['total_fournisseurs'] ?? 0; ?>
                        </span>
                    </a>

                    <!-- Ajoutez ce lien après le lien Fournisseurs -->
                    <a href="?section=utilisateurs"
                        class="<?php echo $section == 'utilisateurs' ? 'active' : ''; ?> flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-users w-6 text-pink-500"></i>
                            <span class="ml-3 font-medium">Utilisateurs</span>
                        </div>
                        <span class="bg-pink-500 text-white text-xs px-2 py-1 rounded-full">
                            <?php
                            // Toujours compter depuis la base de données
                            try {
                                $stmt = $db->prepare("SELECT COUNT(*) FROM utilisateurs WHERE statut = 'actif'");
                                $stmt->execute();
                                echo $stmt->fetchColumn();
                            } catch (Exception $e) {
                                echo count($data['utilisateurs'] ?? []);
                            }
                            ?>
                        </span>
                    </a>

                    <a href="?section=journal"
                        class="<?php echo $section == 'journal' ? 'active' : ''; ?> flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-indigo-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-history w-6 text-orange-500"></i>
                            <span class="ml-3 font-medium">Journal des activités</span>
                        </div>
                        <span class="bg-orange-500 text-white text-xs px-2 py-1 rounded-full">
                            <?php
                            try {
                                // Compter TOUTES les activités d'aujourd'hui
                                $stmt = $db->prepare("SELECT COUNT(*) FROM journal_activites WHERE DATE(created_at) = CURDATE()");
                                $stmt->execute();
                                echo $stmt->fetchColumn();
                            } catch (Exception $e) {
                                echo '0';
                            }
                            ?>
                        </span>
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
                    <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($user_name); ?>
                    </p>
                    <p class="text-xs text-indigo-600 truncate"><?php echo htmlspecialchars(ucfirst($user_role)); ?></p>
                </div>
                <a href="../utilisateurs/logout.php" class="text-gray-400 hover:text-red-500 transition-colors"
                    title="Déconnexion">
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
                    <h2 class="text-2xl font-bold text-gray-800" id="pageTitle">
                        <?php
                        $titles = [
                            'dashboard' => 'Tableau de bord Gérant',
                            'produits' => 'Liste des Produits',
                            'commandes' => 'Liste des Commandes',
                            'categories' => 'Liste des Catégories',
                            'fournisseurs' => 'Liste des Fournisseurs',
                            'utilisateurs' => 'Liste des Utilisateurs',
                            'journal' => 'Journal des Activités'
                        ];
                        echo $titles[$section] ?? 'Tableau de bord Gérant';
                        ?>
                    </h2>
                    <p class="text-gray-600">Vue d'ensemble et rapports</p>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-sm text-gray-600">
                        <i class="fas fa-user-circle mr-2"></i>
                        Connecté en tant que <span
                            class="font-semibold"><?php echo htmlspecialchars(ucfirst($user_role)); ?></span>
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
            <?php if ($section == 'dashboard'): ?>
                <div id="dashboard" class="section">
                    <!-- KPI Rapides -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-4 gap-6 mb-8">
                        <!-- Carte CA Mensuel -->
                        <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-green-500">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-600">CA Mensuel</p>
                                    <p class="text-2xl font-bold text-gray-900 mt-1">
                                        <?php echo formatMontant($data['stats']['ca_mensuel'] ?? 0); ?>
                                    </p>
                                </div>
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-euro-sign text-green-600 text-xl"></i>
                                </div>
                            </div>
                            <div class="mt-4 flex items-center">
                                <?php list($icon, $textColor, $bgColor) = getEvolutionIcon($data['stats']['evolution_ca'] ?? 0); ?>
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $bgColor . ' ' . $textColor; ?>">
                                    <i class="<?php echo $icon; ?> mr-1"></i>
                                    <?php echo formatPourcentage(abs($data['stats']['evolution_ca'] ?? 0)); ?>
                                </span>
                                <span class="text-xs text-gray-500 ml-2">vs mois précédent</span>
                            </div>
                        </div>

                        <!-- Carte Commandes Aujourd'hui -->
                        <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-blue-500">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-600">Commandes Aujourd'hui</p>
                                    <p class="text-2xl font-bold text-gray-900 mt-1">
                                        <?php echo $data['stats']['commandes_aujourdhui'] ?? 0; ?>
                                    </p>
                                </div>
                                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-shopping-cart text-blue-600 text-xl"></i>
                                </div>
                            </div>
                            <div class="mt-4">
                                <p class="text-xs text-gray-500">CA:
                                    <?php echo formatMontant($data['stats']['ca_aujourdhui'] ?? 0); ?>
                                </p>
                            </div>
                        </div>

                        <!-- Carte Stock Total -->
                        <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-purple-500">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-600">Valeur Stock</p>
                                    <p class="text-2xl font-bold text-gray-900 mt-1">
                                        <?php echo formatMontant($data['stats']['valeur_stock'] ?? 0); ?>
                                    </p>
                                </div>
                                <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-boxes text-purple-600 text-xl"></i>
                                </div>
                            </div>
                            <div class="mt-4">
                                <p class="text-xs text-gray-500"><?php echo $data['stats']['total_produits'] ?? 0; ?>
                                    produits actifs</p>
                            </div>
                        </div>

                        <!-- Carte Marge Moyenne -->
                        <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-yellow-500">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-600">Marge Moyenne</p>
                                    <p class="text-2xl font-bold text-gray-900 mt-1">
                                        <?php echo formatPourcentage($data['stats']['marge_moyenne'] ?? 0); ?>
                                    </p>
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
                                    <p class="text-2xl font-bold text-gray-900 mt-1">
                                        <?php echo $data['stats']['produits_rupture'] ?? 0; ?>
                                    </p>
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
                                    <p class="text-2xl font-bold text-gray-900 mt-1">
                                        <?php echo $data['stats_lots']['expiration_proche'] ?? 0; ?>
                                    </p>
                                </div>
                                <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-clock text-orange-600 text-xl"></i>
                                </div>
                            </div>
                        </div>

                        <!-- Produits en stock -->
                        <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-pink-500">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-600">Stock Total</p>
                                    <p class="text-2xl font-bold text-gray-900 mt-1">
                                        <?php echo $data['stats_lots']['stock_total'] ?? 0; ?>
                                    </p>
                                </div>
                                <div class="w-12 h-12 bg-pink-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-box text-pink-600 text-xl"></i>
                                </div>
                            </div>
                        </div>

                        <!-- Commandes en attente -->
                        <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-gray-500">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-600">Commandes En Attente</p>
                                    <p class="text-2xl font-bold text-gray-900 mt-1">
                                        <?php echo $data['stats']['commandes_attente'] ?? 0; ?>
                                    </p>
                                </div>
                                <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-hourglass-half text-gray-600 text-xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Troisième ligne de KPI (Utilisateurs et Stats) -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-4 gap-6 mb-8">
                        <!-- Total Utilisateurs -->
                        <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-pink-500">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-600">Total Utilisateurs</p>
                                    <p class="text-2xl font-bold text-gray-900 mt-1">
                                        <?php
                                        $total_utilisateurs = 0;
                                        try {
                                            $stmt = $db->prepare("SELECT COUNT(*) FROM utilisateurs WHERE statut = 'actif'");
                                            $stmt->execute();
                                            $total_utilisateurs = (int) $stmt->fetchColumn();
                                        } catch (Exception $e) {
                                            error_log("Erreur comptage utilisateurs: " . $e->getMessage());
                                        }
                                        echo $total_utilisateurs;
                                        ?>
                                    </p>
                                </div>
                                <div class="w-12 h-12 bg-pink-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-users text-pink-600 text-xl"></i>
                                </div>
                            </div>
                            <div class="mt-4">
                                <p class="text-xs text-gray-500">Utilisateurs actifs</p>
                            </div>
                        </div>

                        <!-- Admins -->
                        <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-red-500">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-600">Administrateurs</p>
                                    <p class="text-2xl font-bold text-gray-900 mt-1">
                                        <?php
                                        $total_admins = 0;
                                        try {
                                            $stmt = $db->prepare("SELECT COUNT(*) FROM utilisateurs WHERE role = 'admin' AND statut = 'actif'");
                                            $stmt->execute();
                                            $total_admins = (int) $stmt->fetchColumn();
                                        } catch (Exception $e) {
                                            error_log("Erreur comptage admins: " . $e->getMessage());
                                        }
                                        echo $total_admins;
                                        ?>
                                    </p>
                                </div>
                                <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-user-shield text-red-600 text-xl"></i>
                                </div>
                            </div>
                        </div>

                        <!-- Gérants -->
                        <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-blue-500">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-600">Gérants</p>
                                    <p class="text-2xl font-bold text-gray-900 mt-1">
                                        <?php
                                        $total_gerants = 0;
                                        try {
                                            $stmt = $db->prepare("SELECT COUNT(*) FROM utilisateurs WHERE role = 'gerant' AND statut = 'actif'");
                                            $stmt->execute();
                                            $total_gerants = (int) $stmt->fetchColumn();
                                        } catch (Exception $e) {
                                            error_log("Erreur comptage gérants: " . $e->getMessage());
                                        }
                                        echo $total_gerants;
                                        ?>
                                    </p>
                                </div>
                                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-user-tie text-blue-600 text-xl"></i>
                                </div>
                            </div>
                        </div>

                        <!-- Caissiers/Pharmaciens -->
                        <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-green-500">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-600">Personnel</p>
                                    <p class="text-2xl font-bold text-gray-900 mt-1">
                                        <?php
                                        $total_personnel = 0;
                                        try {
                                            $stmt = $db->prepare("SELECT COUNT(*) FROM utilisateurs WHERE role IN ('caissier', 'pharmacien') AND statut = 'actif'");
                                            $stmt->execute();
                                            $total_personnel = (int) $stmt->fetchColumn();
                                        } catch (Exception $e) {
                                            error_log("Erreur comptage personnel: " . $e->getMessage());
                                        }
                                        echo $total_personnel;
                                        ?>
                                    </p>
                                </div>
                                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-user-md text-green-600 text-xl"></i>
                                </div>
                            </div>
                            <div class="mt-4">
                                <p class="text-xs text-gray-500">Caissiers & Pharmaciens</p>
                            </div>
                        </div>
                    </div>

                    <!-- Graphiques et tableaux -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                        <!-- Évolution CA -->
                        <div class="bg-white rounded-2xl shadow-sm p-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Évolution du CA (6 derniers mois)</h3>
                            <div class="h-64 flex items-end justify-between">
                                <?php if (!empty($data['evolution_ca'])): ?>
                                    <?php
                                    $max_ca = max(array_column($data['evolution_ca'], 'ca'));
                                    if ($max_ca == 0)
                                        $max_ca = 1;
                                    ?>
                                    <?php foreach ($data['evolution_ca'] as $mois): ?>
                                        <div class="flex flex-col items-center flex-1">
                                            <div class="text-xs text-gray-500 mb-2">
                                                <?php echo substr($mois['mois_format'], 0, 3); ?>
                                            </div>
                                            <div class="w-3/4">
                                                <div class="bg-gradient-to-t from-indigo-500 to-indigo-600 rounded-t hover:from-indigo-600 hover:to-indigo-700 cursor-pointer mx-auto"
                                                    style="height: <?php echo ($mois['ca'] / $max_ca) * 100; ?>%; min-height: 5px;"
                                                    title="<?php echo $mois['mois_format']; ?>: <?php echo formatMontant($mois['ca']); ?>">
                                                </div>
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
                                <?php if (!empty($data['top_produits'])): ?>
                                    <?php foreach ($data['top_produits'] as $index => $produit): ?>
                                        <div
                                            class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:bg-gray-50">
                                            <div class="flex items-center">
                                                <span
                                                    class="w-6 h-6 bg-indigo-100 text-indigo-600 rounded-full flex items-center justify-center text-xs font-bold mr-3">
                                                    <?php echo $index + 1; ?>
                                                </span>
                                                <div>
                                                    <span
                                                        class="font-medium text-gray-900"><?php echo htmlspecialchars($produit['nom']); ?></span>
                                                    <div class="text-xs text-gray-500">
                                                        <?php echo $produit['total_vendu'] ?? 0; ?> unités vendues
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <div class="font-semibold text-green-600">
                                                    <?php echo formatMontant($produit['ca_total'] ?? 0); ?>
                                                </div>
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

                        <!-- Journal des activités -->
                        <div class="bg-white rounded-2xl shadow-sm p-6 mb-8">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Journal des activités récentes</h3>
                            <div class="space-y-4">
                                <?php if (!empty($data['dernieres_activites'])): ?>
                                    <?php foreach ($data['dernieres_activites'] as $activite): ?>
                                        <div class="border-l-4 <?php
                                        $colors = [
                                            'connexion_reussie' => 'border-green-500 bg-green-50',
                                            'connexion_echouee' => 'border-red-500 bg-red-50',
                                            'deconnexion' => 'border-gray-500 bg-gray-50',
                                            'creation_' => 'border-blue-500 bg-blue-50',
                                            'modification_' => 'border-yellow-500 bg-yellow-50',
                                            'suppression_' => 'border-red-500 bg-red-50',
                                            'visualisation_' => 'border-purple-500 bg-purple-50'
                                        ];

                                        $color_class = 'border-gray-300 bg-gray-50';
                                        foreach ($colors as $key => $class) {
                                            if (strpos($activite['action'], $key) === 0) {
                                                $color_class = $class;
                                                break;
                                            }
                                        }
                                        echo $color_class;
                                        ?> pl-4 py-3 rounded-r">
                                            <div class="flex justify-between items-start">
                                                <div class="flex-1">
                                                    <div class="flex items-center mb-1">
                                                        <span class="font-medium text-gray-900 mr-2">
                                                            <?php echo htmlspecialchars($activite['utilisateur_nom_complet'] ?? $activite['utilisateur_nom']); ?>
                                                        </span>
                                                        <span class="text-xs px-2 py-1 rounded-full <?php
                                                        $role_colors = [
                                                            'admin' => 'bg-red-100 text-red-800',
                                                            'gerant' => 'bg-blue-100 text-blue-800',
                                                            'caissier' => 'bg-green-100 text-green-800',
                                                            'pharmacien' => 'bg-purple-100 text-purple-800'
                                                        ];
                                                        echo $role_colors[$activite['utilisateur_role']] ?? 'bg-gray-100 text-gray-800';
                                                        ?>">
                                                            <?php echo ucfirst($activite['utilisateur_role']); ?>
                                                        </span>
                                                    </div>
                                                    <p class="text-sm text-gray-700">
                                                        <?php
                                                        // Traduire l'action en français
                                                        $actions_fr = [
                                                            'connexion_reussie' => 's\'est connecté',
                                                            'connexion_echouee' => 'tentative de connexion échouée',
                                                            'deconnexion' => 's\'est déconnecté',
                                                            'creation_' => 'a créé un élément',
                                                            'modification_' => 'a modifié un élément',
                                                            'suppression_' => 'a supprimé un élément',
                                                            'visualisation_' => 'a consulté'
                                                        ];

                                                        $action_fr = $activite['action'];
                                                        foreach ($actions_fr as $key => $fr) {
                                                            if (strpos($activite['action'], $key) === 0) {
                                                                $action_fr = $fr;
                                                                break;
                                                            }
                                                        }

                                                        echo htmlspecialchars($action_fr);
                                                        ?>

                                                        <?php if ($activite['table_concernee']): ?>
                                                            dans <span
                                                                class="font-medium"><?php echo htmlspecialchars($activite['table_concernee']); ?></span>
                                                        <?php endif; ?>

                                                        <?php if ($activite['element_id']): ?>
                                                            (ID: <?php echo $activite['element_id']; ?>)
                                                        <?php endif; ?>
                                                    </p>

                                                    <?php if ($activite['details']): ?>
                                                        <p class="text-xs text-gray-500 mt-1">
                                                            <?php echo htmlspecialchars(substr($activite['details'], 0, 100)); ?>
                                                            <?php if (strlen($activite['details']) > 100): ?>...<?php endif; ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-xs text-gray-500 ml-4">
                                                    <?php echo date('H:i', strtotime($activite['created_at'])); ?>
                                                    <br>
                                                    <?php echo date('d/m/Y', strtotime($activite['created_at'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-8 text-gray-500">
                                        <i class="fas fa-history text-2xl mb-2"></i>
                                        <p>Aucune activité récente</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($data['dernieres_activites'])): ?>
                                <div class="mt-4 text-center">
                                    <a href="?section=journal"
                                        class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                                        <i class="fas fa-list mr-1"></i> Voir tout le journal
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Section Produits -->
            <?php elseif ($section == 'produits'): ?>
                <div id="produits" class="section">
                    <div class="bg-white rounded-2xl shadow-sm">
                        <div class="px-6 py-4 border-b flex justify-between items-center">
                            <h3 class="text-xl font-semibold text-gray-900">Liste des Produits
                                (<?php echo count($data['produits'] ?? []); ?> produits)</h3>
                            <button onclick="exportToExcel('produits')"
                                class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors">
                                <i class="fas fa-file-excel mr-2"></i>
                                Exporter Excel
                            </button>
                        </div>
                        <div class="p-6">
                            <!-- Filtres -->
                            <div class="flex flex-wrap gap-4 mb-6">
                                <input type="text" id="searchProduit" placeholder="Rechercher un produit..."
                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 flex-1 min-w-[200px]">
                                <select id="filterCategorie"
                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">Toutes catégories</option>
                                    <?php foreach ($data['categories'] ?? [] as $categorie): ?>
                                        <option value="<?php echo $categorie['id']; ?>">
                                            <?php echo htmlspecialchars($categorie['nom']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select id="filterFournisseur"
                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">Tous fournisseurs</option>
                                    <?php foreach ($data['fournisseurs'] ?? [] as $fournisseur): ?>
                                        <option value="<?php echo $fournisseur['id']; ?>">
                                            <?php echo htmlspecialchars($fournisseur['nom_societe']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select id="filterStatut"
                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="">Tous statuts</option>
                                    <option value="actif">Actif</option>
                                    <option value="inactif">Inactif</option>
                                    <option value="en_attente">En attente</option>
                                </select>
                            </div>

                            <!-- Liste des produits -->
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm" id="produitsTable">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Produit</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Catégorie</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Fournisseur</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code
                                                Barre</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Ordonnance</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Statut</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Créé
                                                le</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (!empty($data['produits'])): ?>
                                            <?php foreach ($data['produits'] as $produit): ?>
                                                <tr class="produit-row hover:bg-gray-50" data-id="<?php echo $produit['id']; ?>"
                                                    data-nom="<?php echo htmlspecialchars($produit['nom']); ?>"
                                                    data-categorie="<?php echo $produit['categorie_id']; ?>"
                                                    data-fournisseur="<?php echo $produit['fournisseur_id']; ?>"
                                                    data-statut="<?php echo $produit['statut']; ?>">
                                                    <td class="px-4 py-3 font-mono text-gray-500">
                                                        <?php echo $produit['id']; ?>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <div class="font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($produit['nom']); ?>
                                                        </div>
                                                        <div class="text-xs text-gray-500 truncate max-w-xs">
                                                            <?php echo htmlspecialchars(substr($produit['description'] ?: 'Aucune description', 0, 50)); ?>...
                                                        </div>
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
                                                            <span
                                                                class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                                <i class="fas fa-prescription-bottle-alt mr-1"></i>
                                                                Oui
                                                            </span>
                                                        <?php else: ?>
                                                            <span
                                                                class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                                <i class="fas fa-check mr-1"></i>
                                                                Non
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <span
                                                            class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo getStatutProduitClass($produit['statut']); ?>">
                                                            <?php echo ucfirst($produit['statut']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-500">
                                                        <?php echo date('d/m/Y', strtotime($produit['created_at'])); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="px-4 py-8 text-center text-gray-500">
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
            <?php elseif ($section == 'commandes'): ?>
                <div id="commandes" class="section">
                    <div class="bg-white rounded-2xl shadow-sm">
                        <div class="px-6 py-4 border-b flex justify-between items-center">
                            <h3 class="text-xl font-semibold text-gray-900">Liste des Commandes
                                (<?php echo count($data['commandes'] ?? []); ?> commandes)</h3>
                            <button onclick="exportToExcel('commandes')"
                                class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors">
                                <i class="fas fa-file-excel mr-2"></i>
                                Exporter Excel
                            </button>
                        </div>
                        <div class="p-6">
                            <!-- Filtres -->
                            <div class="flex flex-wrap gap-4 mb-6">
                                <input type="text" id="searchCommande" placeholder="Rechercher une commande..."
                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 flex-1 min-w-[200px]">
                                <select id="filterStatutCommande"
                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                                    <option value="">Tous statuts</option>
                                    <option value="en_attente">En attente</option>
                                    <option value="paye">Payé</option>
                                    <option value="annule">Annulé</option>
                                    <option value="rembourse">Remboursé</option>
                                </select>
                                <input type="date" id="filterDate"
                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                            </div>

                            <!-- Liste des commandes -->
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm" id="commandesTable">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">N°
                                                Commande</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Client</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Montant</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Mode
                                                Paiement</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Statut</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (!empty($data['commandes'])): ?>
                                            <?php foreach ($data['commandes'] as $commande): ?>
                                                <tr class="commande-row hover:bg-gray-50" data-id="<?php echo $commande['id']; ?>"
                                                    data-numero="<?php echo htmlspecialchars($commande['id']); ?>"
                                                    data-statut="<?php echo htmlspecialchars($commande['statut'] ?? ''); ?>"
                                                    data-date="<?php echo date('Y-m-d', strtotime($commande['date_commande'] ?? 'now')); ?>">
                                                    <td class="px-4 py-3 font-mono text-gray-900 font-medium">
                                                        #<?php echo $commande['id']; ?>
                                                        <?php if (!empty($commande['numero_commande'])): ?>
                                                            <div class="text-xs text-gray-500">
                                                                <?php echo htmlspecialchars($commande['numero_commande']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <div class="font-medium text-gray-900">
                                                            <?php
                                                            $client_nom = trim(($commande['client_nom'] ?? '') . ' ' . ($commande['client_prenom'] ?? ''));
                                                            echo !empty($client_nom) ? htmlspecialchars($client_nom) : 'Client #' . ($commande['client_id'] ?? 'N/A');
                                                            ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-500">
                                                        <?php echo date('d/m/Y H:i', strtotime($commande['date_commande'] ?? 'now')); ?>
                                                    </td>
                                                    <td class="px-4 py-3 font-semibold text-gray-900">
                                                        <?php echo formatMontant($commande['montant_total'] ?? 0); ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-500">
                                                        <?php
                                                        $mode_paiement = $commande['mode_paiement'] ?? 'non spécifié';
                                                        echo ucfirst(str_replace('_', ' ', $mode_paiement));
                                                        ?>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <span
                                                            class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo getStatutCommandeClass($commande['statut'] ?? ''); ?>">
                                                            <?php echo ucfirst(str_replace('_', ' ', $commande['statut'] ?? 'inconnu')); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">
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
            <?php elseif ($section == 'categories'): ?>
                <div id="categories" class="section">
                    <div class="bg-white rounded-2xl shadow-sm">
                        <div class="px-6 py-4 border-b flex justify-between items-center">
                            <h3 class="text-xl font-semibold text-gray-900">Liste des Catégories
                                (<?php echo count($data['categories'] ?? []); ?> catégories)</h3>
                            <button onclick="exportToExcel('categories')"
                                class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors">
                                <i class="fas fa-file-excel mr-2"></i>
                                Exporter Excel
                            </button>
                        </div>
                        <div class="p-6">
                            <!-- Liste des catégories -->
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm" id="categoriesTable">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Description</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Produits</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Statut</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Créé
                                                le</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (!empty($data['categories'])): ?>
                                            <?php foreach ($data['categories'] as $categorie): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-4 py-3 font-mono text-gray-500">
                                                        <?php echo $categorie['id']; ?>
                                                    </td>
                                                    <td class="px-4 py-3 font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($categorie['nom']); ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-500 max-w-md truncate">
                                                        <?php echo htmlspecialchars($categorie['description'] ?: 'Aucune description'); ?>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <span
                                                            class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                            <?php echo $categorie['nb_produits'] ?? 0; ?> produits
                                                        </span>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <span
                                                            class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $categorie['statut'] == 'actif' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                            <?php echo ucfirst($categorie['statut']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-500">
                                                        <?php echo date('d/m/Y', strtotime($categorie['created_at'])); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">
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
            <?php elseif ($section == 'fournisseurs'): ?>
                <div id="fournisseurs" class="section">
                    <div class="bg-white rounded-2xl shadow-sm">
                        <div class="px-6 py-4 border-b flex justify-between items-center">
                            <h3 class="text-xl font-semibold text-gray-900">Liste des Fournisseurs
                                (<?php echo count($data['fournisseurs'] ?? []); ?> fournisseurs)</h3>
                            <button onclick="exportToExcel('fournisseurs')"
                                class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors">
                                <i class="fas fa-file-excel mr-2"></i>
                                Exporter Excel
                            </button>
                        </div>
                        <div class="p-6">
                            <!-- Liste des fournisseurs -->
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm" id="fournisseursTable">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Société</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Contact</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Email/Téléphone</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Produits</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Note
                                                Qualité</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Créé
                                                le</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (!empty($data['fournisseurs'])): ?>
                                            <?php foreach ($data['fournisseurs'] as $fournisseur): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-4 py-3 font-mono text-gray-500">
                                                        <?php echo $fournisseur['id']; ?>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <div class="font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($fournisseur['nom_societe']); ?>
                                                        </div>
                                                        <?php if ($fournisseur['adresse_siege']): ?>
                                                            <div class="text-xs text-gray-500">
                                                                <?php echo htmlspecialchars($fournisseur['adresse_siege']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-500">
                                                        <?php echo htmlspecialchars($fournisseur['contact_principal'] ?? 'Non spécifié'); ?>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <div class="text-sm text-gray-900">
                                                            <?php echo htmlspecialchars($fournisseur['email'] ?? 'N/A'); ?>
                                                        </div>
                                                        <div class="text-xs text-gray-500">
                                                            <?php echo htmlspecialchars($fournisseur['telephone'] ?? 'N/A'); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <span
                                                            class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                                            <?php echo $fournisseur['nb_produits'] ?? 0; ?> produits
                                                        </span>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <div class="flex items-center">
                                                            <div class="w-24 bg-gray-200 rounded-full h-2 mr-2">
                                                                <div class="bg-green-600 h-2 rounded-full"
                                                                    style="width: <?php echo min(100, ($fournisseur['note_qualite'] ?? 0) * 20); ?>%">
                                                                </div>
                                                            </div>
                                                            <span
                                                                class="text-sm font-medium text-gray-700"><?php echo number_format($fournisseur['note_qualite'] ?? 0, 1); ?>/5</span>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-500">
                                                        <?php echo date('d/m/Y', strtotime($fournisseur['created_at'])); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="px-4 py-8 text-center text-gray-500">
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

                <!-- Section Utilisateurs -->
            <?php elseif ($section == 'utilisateurs'): ?>
                <div id="utilisateurs" class="section">
                    <div class="bg-white rounded-2xl shadow-sm">
                        <div class="px-6 py-4 border-b flex justify-between items-center">
                            <h3 class="text-xl font-semibold text-gray-900">Liste des Utilisateurs
                                (<?php echo count($data['utilisateurs'] ?? []); ?> utilisateurs actifs)</h3>
                            <div class="flex gap-2">
                                <button onclick="exportToExcel('utilisateurs')"
                                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors">
                                    <i class="fas fa-file-excel mr-2"></i>
                                    Exporter Excel
                                </button>
                            </div>
                        </div>
                        <div class="p-6">
                            <!-- Statistiques par rôle -->
                            <?php if (!empty($data['stats_roles'])): ?>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                                    <?php foreach ($data['stats_roles'] as $stat): ?>
                                        <div class="bg-gray-50 rounded-lg p-4 border">
                                            <div class="flex items-center justify-between">
                                                <div>
                                                    <p class="text-sm font-medium text-gray-600">
                                                        <?php echo ucfirst($stat['role']); ?>s
                                                    </p>
                                                    <p class="text-2xl font-bold text-gray-900 mt-1">
                                                        <?php echo $stat['count']; ?>
                                                    </p>
                                                </div>
                                                <div class="w-10 h-10 rounded-full flex items-center justify-center 
                                        <?php
                                        $colors = [
                                            'admin' => 'bg-red-100 text-red-600',
                                            'gerant' => 'bg-blue-100 text-blue-600',
                                            'caissier' => 'bg-green-100 text-green-600',
                                            'pharmacien' => 'bg-purple-100 text-purple-600'
                                        ];
                                        echo $colors[$stat['role']] ?? 'bg-gray-100 text-gray-600';
                                        ?>">
                                                    <i class="fas fa-user"></i>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Filtres -->
                            <div class="flex flex-wrap gap-4 mb-6">
                                <input type="text" id="searchUtilisateur" placeholder="Rechercher un utilisateur..."
                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-pink-500 flex-1 min-w-[200px]">
                                <select id="filterRole"
                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-pink-500">
                                    <option value="">Tous les rôles</option>
                                    <option value="admin">Administrateur</option>
                                    <option value="gerant">Gérant</option>
                                    <option value="caissier">Caissier</option>
                                    <option value="pharmacien">Pharmacien</option>
                                    <option value="fournisseur">Fournisseur</option>
                                    <option value="client">Client</option>
                                    <option value="stockiste">Stockiste</option>
                                </select>
                            </div>

                            <!-- Liste des utilisateurs -->
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm" id="utilisateursTable">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Email</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Téléphone</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Adresse</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rôle
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Statut</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Créé
                                                le</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (!empty($data['utilisateurs'])): ?>
                                            <?php foreach ($data['utilisateurs'] as $utilisateur): ?>
                                                <tr class="utilisateur-row hover:bg-gray-50"
                                                    data-id="<?php echo $utilisateur['id']; ?>"
                                                    data-nom="<?php echo htmlspecialchars($utilisateur['nom']); ?>"
                                                    data-role="<?php echo htmlspecialchars($utilisateur['role']); ?>">
                                                    <td class="px-4 py-3 font-mono text-gray-500">
                                                        <?php echo $utilisateur['id']; ?>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <div class="font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($utilisateur['nom']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-500">
                                                        <?php echo htmlspecialchars($utilisateur['email']); ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-500">
                                                        <?php echo htmlspecialchars($utilisateur['telephone'] ?? 'N/A'); ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-500">
                                                        <?php echo htmlspecialchars($utilisateur['adresse'] ?? 'N/A'); ?>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <?php
                                                        $role_colors = [
                                                            'admin' => 'bg-red-100 text-red-800',
                                                            'gerant' => 'bg-blue-100 text-blue-800',
                                                            'caissier' => 'bg-green-100 text-green-800',
                                                            'pharmacien' => 'bg-purple-100 text-purple-800'
                                                        ];
                                                        $userRole = $utilisateur['role'] ?? 'inconnu';
                                                        $role_class = $role_colors[$userRole] ?? 'bg-gray-100 text-gray-800';
                                                        ?>
                                                        <span
                                                            class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $role_class; ?>">
                                                            <i class="fas fa-user-tag mr-1"></i>
                                                            <?php echo ucfirst($userRole); ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <span
                                                            class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo ($utilisateur['statut'] ?? 'inactif') == 'actif' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                            <?php echo ucfirst($utilisateur['statut'] ?? 'inactif'); ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-500">
                                                        <?php
                                                        if (!empty($utilisateur['date_creation'])) {
                                                            try {
                                                                echo date('d/m/Y', strtotime($utilisateur['date_creation']));
                                                            } catch (Exception $e) {
                                                                echo 'Date invalide';
                                                            }
                                                        } else {
                                                            echo 'N/A';
                                                        }
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                                    <i class="fas fa-users text-2xl mb-2"></i>
                                                    <p>Aucun utilisateur trouvé</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($section == 'journal'): ?>
                <div id="journal" class="section">
                    <div class="bg-white rounded-2xl shadow-sm">
                        <div class="px-6 py-4 border-b flex justify-between items-center">
                            <h3 class="text-xl font-semibold text-gray-900">Journal des activités</h3>
                            <div class="flex gap-2">
                                <button onclick="exportToExcel('journal')"
                                    class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors">
                                    <i class="fas fa-file-excel mr-2"></i>
                                    Exporter Excel
                                </button>
                            </div>
                        </div>
                        <div class="p-6">
                            <!-- Filtres -->
                            <div class="flex flex-wrap gap-4 mb-6">
                                <input type="date" id="filterDateJournal"
                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500">
                                <select id="filterUtilisateurJournal"
                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500">
                                    <option value="">Tous les utilisateurs</option>
                                    <?php
                                    $stmt = $db->prepare("SELECT DISTINCT ja.utilisateur_id, ja.utilisateur_nom FROM journal_activites ja WHERE ja.utilisateur_id != ? ORDER BY ja.utilisateur_nom");
                                    $stmt->execute([$user_id]);
                                    $utilisateurs_journal = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($utilisateurs_journal as $u): ?>
                                        <option value="<?php echo $u['utilisateur_id']; ?>">
                                            <?php echo htmlspecialchars($u['utilisateur_nom']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select id="filterActionJournal"
                                    class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500">
                                    <option value="">Toutes les actions</option>
                                    <option value="connexion">Connexions</option>
                                    <option value="creation">Créations</option>
                                    <option value="modification">Modifications</option>
                                    <option value="suppression">Suppressions</option>
                                    <option value="visualisation">Consultations</option>
                                </select>
                                <button onclick="clearFiltersJournal()"
                                    class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                                    <i class="fas fa-times mr-1"></i> Effacer
                                </button>
                            </div>

                            <!-- Statistiques -->
                            <?php if (!empty($data['stats_journal'])): ?>
                                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                                    <?php foreach ($data['stats_journal'] as $stat): ?>
                                        <div class="bg-gray-50 rounded-lg p-4 border">
                                            <div class="text-sm font-medium text-gray-600">
                                                <?php echo date('d/m/Y', strtotime($stat['date'])); ?>
                                            </div>
                                            <div class="mt-2 flex items-center justify-between">
                                                <div>
                                                    <div class="text-lg font-bold text-gray-900"><?php echo $stat['total']; ?></div>
                                                    <div class="text-xs text-gray-500">activités</div>
                                                </div>
                                                <div class="text-right">
                                                    <div class="text-sm text-blue-600"><?php echo $stat['connexions']; ?> connexions
                                                    </div>
                                                    <div class="text-sm text-green-600"><?php echo $stat['creations']; ?> créations
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Liste des activités -->
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm" id="journalTable">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Date/Heure</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Utilisateur</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rôle
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Action</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Table/Élément</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                Détails</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (!empty($data['activites'])): ?>
                                            <?php foreach ($data['activites'] as $activite): ?>
                                                <tr class="journal-row hover:bg-gray-50"
                                                    data-date="<?php echo date('Y-m-d', strtotime($activite['created_at'])); ?>"
                                                    data-utilisateur="<?php echo $activite['utilisateur_id']; ?>"
                                                    data-action="<?php echo $activite['action']; ?>">
                                                    <td class="px-4 py-3 text-gray-500">
                                                        <div class="font-medium">
                                                            <?php echo date('d/m/Y', strtotime($activite['created_at'])); ?>
                                                        </div>
                                                        <div class="text-xs">
                                                            <?php echo date('H:i:s', strtotime($activite['created_at'])); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <div class="font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($activite['utilisateur_nom_complet'] ?? $activite['utilisateur_nom']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php
                                                        $role_colors = [
                                                            'admin' => 'bg-red-100 text-red-800',
                                                            'gerant' => 'bg-blue-100 text-blue-800',
                                                            'caissier' => 'bg-green-100 text-green-800',
                                                            'pharmacien' => 'bg-purple-100 text-purple-800'
                                                        ];
                                                        echo $role_colors[$activite['utilisateur_role']] ?? 'bg-gray-100 text-gray-800';
                                                        ?>">
                                                            <?php echo ucfirst($activite['utilisateur_role']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php
                                                        $action_colors = [
                                                            'connexion_reussie' => 'bg-green-100 text-green-800',
                                                            'connexion_echouee' => 'bg-red-100 text-red-800',
                                                            'deconnexion' => 'bg-gray-100 text-gray-800',
                                                            'creation_' => 'bg-blue-100 text-blue-800',
                                                            'modification_' => 'bg-yellow-100 text-yellow-800',
                                                            'suppression_' => 'bg-red-100 text-red-800',
                                                            'visualisation_' => 'bg-purple-100 text-purple-800'
                                                        ];

                                                        $action_class = 'bg-gray-100 text-gray-800';
                                                        foreach ($action_colors as $key => $class) {
                                                            if (strpos($activite['action'], $key) === 0) {
                                                                $action_class = $class;
                                                                break;
                                                            }
                                                        }
                                                        echo $action_class;
                                                        ?>">
                                                            <?php
                                                            $action_traductions = [
                                                                'connexion_reussie' => 'Connexion',
                                                                'connexion_echouee' => 'Échec connexion',
                                                                'deconnexion' => 'Déconnexion',
                                                                'creation_' => 'Création',
                                                                'modification_' => 'Modification',
                                                                'suppression_' => 'Suppression',
                                                                'visualisation_' => 'Consultation'
                                                            ];

                                                            $action_affichee = $activite['action'];
                                                            foreach ($action_traductions as $key => $traduction) {
                                                                if (strpos($activite['action'], $key) === 0) {
                                                                    $action_affichee = $traduction;
                                                                    break;
                                                                }
                                                            }
                                                            echo htmlspecialchars($action_affichee);
                                                            ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-500">
                                                        <?php if ($activite['table_concernee']): ?>
                                                            <div class="font-medium">
                                                                <?php echo htmlspecialchars($activite['table_concernee']); ?>
                                                            </div>
                                                            <?php if ($activite['element_id']): ?>
                                                                <div class="text-xs">ID: <?php echo $activite['element_id']; ?></div>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            -
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-500 max-w-xs">
                                                        <?php echo htmlspecialchars(substr($activite['details'] ?? '', 0, 100)); ?>
                                                        <?php if (strlen($activite['details'] ?? '') > 100): ?>...<?php endif; ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-500 text-xs font-mono">
                                                        <?php echo htmlspecialchars($activite['ip_adresse'] ?? 'N/A'); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                                    <i class="fas fa-history text-2xl mb-2"></i>
                                                    <p>Aucune activité enregistrée</p>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>



        </main>
    </div>

    <!-- Scripts JavaScript -->
    <script>
        // Fonction pour exporter en Excel
        function exportToExcel(type) {
            console.log('exportToExcel appelée avec:', type);
            let tableId, filename;

            switch (type) {
                case 'produits':
                    tableId = 'produitsTable';
                    filename = 'produits_' + new Date().toISOString().split('T')[0] + '.xls';
                    break;
                case 'commandes':
                    tableId = 'commandesTable';
                    filename = 'commandes_' + new Date().toISOString().split('T')[0] + '.xls';
                    break;
                case 'categories':
                    tableId = 'categoriesTable';
                    filename = 'categories_' + new Date().toISOString().split('T')[0] + '.xls';
                    break;
                case 'fournisseurs':
                    tableId = 'fournisseursTable';
                    filename = 'fournisseurs_' + new Date().toISOString().split('T')[0] + '.xls';
                    break;
                case 'utilisateurs':
                    tableId = 'utilisateursTable';
                    filename = 'utilisateurs_' + new Date().toISOString().split('T')[0] + '.xls';
                    break;
                case 'journal':
                    tableId = 'journalTable';
                    filename = 'journal_' + new Date().toISOString().split('T')[0] + '.xls';
                    break;
                default:
                    console.error('Type inconnu:', type);
                    return;
            }

            const table = document.getElementById(tableId);
            if (!table) {
                console.error('Tableau non trouvé:', tableId);
                return;
            }

            let html = table.outerHTML;

            // Créer un blob et télécharger
            const blob = new Blob(['\ufeff', html], {
                type: 'application/vnd.ms-excel'
            });

            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            URL.revokeObjectURL(link.href);
        }

        // Filtrer les produits
        function filterProduits() {
            const search = document.getElementById('searchProduit').value.toLowerCase();
            const categorie = document.getElementById('filterCategorie').value;
            const fournisseur = document.getElementById('filterFournisseur').value;
            const statut = document.getElementById('filterStatut').value;

            document.querySelectorAll('#produitsTable .produit-row').forEach(row => {
                const nom = row.getAttribute('data-nom').toLowerCase();
                const rowCategorie = row.getAttribute('data-categorie');
                const rowFournisseur = row.getAttribute('data-fournisseur');
                const rowStatut = row.getAttribute('data-statut');

                const matchSearch = nom.includes(search);
                const matchCategorie = !categorie || rowCategorie === categorie;
                const matchFournisseur = !fournisseur || rowFournisseur === fournisseur;
                const matchStatut = !statut || rowStatut === statut;

                row.style.display = (matchSearch && matchCategorie && matchFournisseur && matchStatut) ? '' : 'none';
            });
        }

        // Filtrer les commandes
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

        // Filtrer les utilisateurs
        function filterUtilisateurs() {
            const search = document.getElementById('searchUtilisateur').value.toLowerCase();
            const role = document.getElementById('filterRole').value;

            document.querySelectorAll('#utilisateursTable .utilisateur-row').forEach(row => {
                const nom = row.getAttribute('data-nom').toLowerCase();
                const rowRole = row.getAttribute('data-role');

                const matchSearch = nom.includes(search);
                const matchRole = !role || rowRole === role;

                row.style.display = (matchSearch && matchRole) ? '' : 'none';
            });
        }

        // Filtrer le journal
        function filterJournal() {
            const date = document.getElementById('filterDateJournal').value;
            const utilisateur = document.getElementById('filterUtilisateurJournal').value;
            const action = document.getElementById('filterActionJournal').value;

            document.querySelectorAll('#journalTable .journal-row').forEach(row => {
                const rowDate = row.getAttribute('data-date');
                const rowUtilisateur = row.getAttribute('data-utilisateur');
                const rowAction = row.getAttribute('data-action');

                const matchDate = !date || rowDate === date;
                const matchUtilisateur = !utilisateur || rowUtilisateur === utilisateur;
                const matchAction = !action || rowAction.includes(action);

                row.style.display = (matchDate && matchUtilisateur && matchAction) ? '' : 'none';
            });
        }

        function clearFiltersJournal() {
            document.getElementById('filterDateJournal').value = '';
            document.getElementById('filterUtilisateurJournal').value = '';
            document.getElementById('filterActionJournal').value = '';
            filterJournal();
        }

        // Fonction pour enregistrer une activité côté client (pour afficher des confirmations)
        function logClientActivity(action, details = null) {
            console.log('Activité client:', action, details);

            // Vous pouvez ici ajouter des notifications ou confirmations visuelles
            // Exemple: afficher une petite notification
            showNotification('Activité enregistrée: ' + action, 'success');
        }

        // Fonction pour afficher des notifications
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg transition-opacity duration-300 ${type === 'success' ? 'bg-green-100 text-green-800 border border-green-200' :
                type === 'error' ? 'bg-red-100 text-red-800 border border-red-200' :
                    'bg-blue-100 text-blue-800 border border-blue-200'
                }`;

            notification.innerHTML = `
            <div class="flex items-center">
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle'} mr-2"></i>
                <span>${message}</span>
            </div>
        `;

            document.body.appendChild(notification);

            // Supprimer la notification après 3 secondes
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Initialisation lorsque le DOM est chargé
        document.addEventListener('DOMContentLoaded', function () {
            console.log('DOM chargé, initialisation...');

            // Filtre produits
            const searchProduit = document.getElementById('searchProduit');
            const filterCategorie = document.getElementById('filterCategorie');
            const filterFournisseur = document.getElementById('filterFournisseur');
            const filterStatut = document.getElementById('filterStatut');

            if (searchProduit) {
                searchProduit.addEventListener('input', filterProduits);
            }
            if (filterCategorie) {
                filterCategorie.addEventListener('change', filterProduits);
            }
            if (filterFournisseur) {
                filterFournisseur.addEventListener('change', filterProduits);
            }
            if (filterStatut) {
                filterStatut.addEventListener('change', filterProduits);
            }

            // Filtre utilisateurs
            const searchUtilisateur = document.getElementById('searchUtilisateur');
            const filterRole = document.getElementById('filterRole');

            if (searchUtilisateur) {
                searchUtilisateur.addEventListener('input', filterUtilisateurs);
            }
            if (filterRole) {
                filterRole.addEventListener('change', filterUtilisateurs);
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

            // Filtre journal
            const filterDateJournal = document.getElementById('filterDateJournal');
            const filterUtilisateurJournal = document.getElementById('filterUtilisateurJournal');
            const filterActionJournal = document.getElementById('filterActionJournal');

            if (filterDateJournal) {
                filterDateJournal.addEventListener('change', filterJournal);
            }
            if (filterUtilisateurJournal) {
                filterUtilisateurJournal.addEventListener('change', filterJournal);
            }
            if (filterActionJournal) {
                filterActionJournal.addEventListener('change', filterJournal);
            }

            // Bouton effacer filtres journal
            const clearFiltersBtn = document.querySelector('button[onclick="clearFiltersJournal()"]');
            if (clearFiltersBtn) {
                clearFiltersBtn.addEventListener('click', clearFiltersJournal);
            }

            // Logguer la consultation de la page actuelle
            const currentSection = window.location.search.includes('section=')
                ? window.location.search.split('section=')[1].split('&')[0]
                : 'dashboard';

            logClientActivity('Consultation de la section: ' + currentSection);

            console.log('Initialisation terminée');
        });

        // Fonction pour confirmer les actions importantes
        function confirmAction(action, callback) {
            const confirmed = confirm(`Êtes-vous sûr de vouloir ${action} ?`);
            if (confirmed && callback) {
                callback();

                // Logguer l'action côté client
                logClientAction('confirmation', `Action confirmée: ${action}`);
            }
            return confirmed;
        }

        // Exemple d'utilisation pour les boutons d'export
        function exportWithConfirmation(type) {
            confirmAction(`exporter les ${type}`, () => {
                exportToExcel(type);

                // Logguer l'export côté client
                logClientActivity('export_excel', `Exportation des ${type}`);
            });
        }

        // Remplacer les appels directs d'export par des versions avec confirmation
        document.addEventListener('DOMContentLoaded', function () {
            // Attacher les événements aux boutons d'export
            document.querySelectorAll('button[onclick^="exportToExcel"]').forEach(button => {
                const oldOnclick = button.getAttribute('onclick');
                if (oldOnclick) {
                    const match = oldOnclick.match(/exportToExcel\('([^']+)'\)/);
                    if (match) {
                        const type = match[1];
                        button.removeAttribute('onclick');
                        button.addEventListener('click', () => exportWithConfirmation(type));
                    }
                }
            });
        });
    </script>
</body>

</html>