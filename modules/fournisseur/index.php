<?php
declare(strict_types=1);
// ============================================
// MODULE FOURNISSEUR - Système NAGEX Pharma
// ============================================
// Fichier : fournisseur_dashboard.php
// Description : Interface complète du fournisseur
// ============================================

// Démarrage de session et vérification du rôle
session_start();

// Vérifier si l'utilisateur est connecté et a le rôle fournisseur
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'fournisseur') {
    header('Location: login.php');
    exit();
}

// Inclure la classe Database
require_once __DIR__ . '/../../config/database.php';

// Initialisation des variables
$message = '';
$error = '';
$current_page = $_GET['page'] ?? 'dashboard';

// ============================================
// CONNEXION À LA BASE DE DONNÉES
// ============================================

try {
    $database = new Database();
    $pdo = $database->getConnection();
} catch (Exception $e) {
    error_log("Erreur de connexion à la base de données: " . $e->getMessage());
    die("Erreur de connexion à la base de données. Veuillez contacter l'administrateur.");
}

// Récupérer l'ID du fournisseur connecté
$fournisseur_id = null;
try {
    $stmt = $pdo->prepare("SELECT id FROM fournisseurs WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $fournisseur = $stmt->fetch();
    $fournisseur_id = $fournisseur['id'] ?? null;
} catch (Exception $e) {
    $error = "Erreur lors de la récupération du profil fournisseur: " . $e->getMessage();
}

if (!$fournisseur_id) {
    die("Erreur: Aucun profil fournisseur associé à votre compte.");
}

// ============================================
// FONCTIONS UTILITAIRES
// ============================================

/**
 * Récupère les statistiques du dashboard fournisseur
 */
function getDashboardStats(PDO $pdo, int $fournisseur_id): array
{
    $stats = [];

    // Nombre de produits fournis
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM produits WHERE fournisseur_id = :fournisseur_id");
    $stmt->execute([':fournisseur_id' => $fournisseur_id]);
    $stats['total_produits'] = $stmt->fetch()['total'] ?? 0;

    // Produits actifs
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM produits WHERE fournisseur_id = :fournisseur_id AND statut = 'actif'");
    $stmt->execute([':fournisseur_id' => $fournisseur_id]);
    $stats['produits_actifs'] = $stmt->fetch()['total'] ?? 0;

    // Commandes en attente
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT c.id) as total 
        FROM commandes c
        JOIN commande_details cd ON c.id = cd.commande_id
        JOIN produits p ON cd.produit_id = p.id
        WHERE p.fournisseur_id = :fournisseur_id 
        AND c.statut = 'en_attente'
    ");
    $stmt->execute([':fournisseur_id' => $fournisseur_id]);
    $stats['commandes_attente'] = $stmt->fetch()['total'] ?? 0;

    // Ventes totales
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(cd.sous_total), 0) as total
        FROM commande_details cd
        JOIN produits p ON cd.produit_id = p.id
        JOIN commandes c ON cd.commande_id = c.id
        WHERE p.fournisseur_id = :fournisseur_id 
        AND c.statut = 'paye'
    ");
    $stmt->execute([':fournisseur_id' => $fournisseur_id]);
    $stats['ventes_totales'] = $stmt->fetch()['total'] ?? 0;

    // Note moyenne
    $stmt = $pdo->prepare("SELECT note_qualite FROM fournisseurs WHERE id = :fournisseur_id");
    $stmt->execute([':fournisseur_id' => $fournisseur_id]);
    $stats['note_qualite'] = $stmt->fetch()['note_qualite'] ?? 0;

    return $stats;
}

/**
 * Formate le montant avec devise
 */

function formatMontant($montant, string $devise = 'CDF'): string {
    // Convertir en float si c'est une chaîne
    $montant_float = is_numeric($montant) ? floatval($montant) : 0.0;
    
    if ($devise === 'USD') {
        return '$' . number_format($montant_float, 2, '.', ',');
    }
    return number_format($montant_float, 0, '.', ',') . ' FC';
}

/**
 * Échapper les données pour l'affichage HTML
 */
function e(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Formate la date pour l'affichage
 */
function formatDate(string $date): string
{
    return date('d/m/Y H:i', strtotime($date));
}

// ============================================
// GESTION FOURNISSEUR (Traitement POST)
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {

            // METTRE À JOUR LE PROFIL
            case 'update_profile':
                try {
                    $stmt = $pdo->prepare("
                        UPDATE fournisseurs SET
                            nom_societe = :nom_societe,
                            contact_principal = :contact_principal,
                            adresse_siege = :adresse_siege,
                            telephone = :telephone,
                            email = :email
                        WHERE id = :id
                    ");

                    $stmt->execute([
                        ':nom_societe' => $_POST['nom_societe'] ?? '',
                        ':contact_principal' => $_POST['contact_principal'] ?? '',
                        ':adresse_siege' => $_POST['adresse_siege'] ?? '',
                        ':telephone' => $_POST['telephone'] ?? '',
                        ':email' => $_POST['email'] ?? '',
                        ':id' => $fournisseur_id
                    ]);

                    $message = "✅ Profil mis à jour avec succès!";

                } catch (Exception $e) {
                    $error = "❌ Erreur lors de la mise à jour du profil: " . $e->getMessage();
                }
                break;

            // AJOUTER UN PRODUIT AU CATALOGUE
            case 'ajouter_produit_catalogue':
                try {
                    $pdo->beginTransaction();

                    // Vérifier si le produit existe déjà
                    $stmt = $pdo->prepare("SELECT id FROM produits WHERE code_barre = :code_barre AND fournisseur_id = :fournisseur_id");
                    $stmt->execute([
                        ':code_barre' => $_POST['code_barre'] ?? '',
                        ':fournisseur_id' => $fournisseur_id
                    ]);

                    if ($stmt->fetch()) {
                        throw new Exception("Un produit avec ce code barre existe déjà dans votre catalogue.");
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO produits (
                            nom, description, code_barre, categorie_id, 
                            fournisseur_id, composition, statut, created_by
                        ) VALUES (
                            :nom, :description, :code_barre, :categorie_id,
                            :fournisseur_id, :composition, 'en_attente', :created_by
                        )
                    ");

                    $stmt->execute([
                        ':nom' => $_POST['nom'] ?? '',
                        ':description' => $_POST['description'] ?? '',
                        ':code_barre' => $_POST['code_barre'] ?? '',
                        ':categorie_id' => intval($_POST['categorie_id'] ?? 0),
                        ':fournisseur_id' => $fournisseur_id,
                        ':composition' => $_POST['composition'] ?? '',
                        ':created_by' => $_SESSION['user_id']
                    ]);

                    $pdo->commit();
                    $message = "✅ Produit ajouté au catalogue! En attente de validation par le pharmacien.";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "❌ Erreur lors de l'ajout du produit: " . $e->getMessage();
                }
                break;

            // METTRE À JOUR LA DISPONIBILITÉ
            case 'update_disponibilite':
                try {
                    $stmt = $pdo->prepare("
                        UPDATE produits_fournisseur 
                        SET disponible = :disponible, 
                            delai_livraison = :delai_livraison,
                            prix_achat = :prix_achat
                        WHERE id = :id AND fournisseur_id = :fournisseur_id
                    ");

                    $stmt->execute([
                        ':disponible' => isset($_POST['disponible']) ? 1 : 0,
                        ':delai_livraison' => intval($_POST['delai_livraison'] ?? 0),
                        ':prix_achat' => floatval($_POST['prix_achat'] ?? 0),
                        ':id' => intval($_POST['produit_id'] ?? 0),
                        ':fournisseur_id' => $fournisseur_id
                    ]);

                    $message = "✅ Disponibilité mise à jour avec succès!";

                } catch (Exception $e) {
                    $error = "❌ Erreur lors de la mise à jour: " . $e->getMessage();
                }
                break;

            // ENVOYER UN MESSAGE
            case 'envoyer_message':
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO messages_fournisseur (
                            fournisseur_id, sujet, message, type, statut
                        ) VALUES (
                            :fournisseur_id, :sujet, :message, :type, 'envoye'
                        )
                    ");

                    $stmt->execute([
                        ':fournisseur_id' => $fournisseur_id,
                        ':sujet' => $_POST['sujet'] ?? '',
                        ':message' => $_POST['message'] ?? '',
                        ':type' => $_POST['type'] ?? 'information'
                    ]);

                    $message = "✅ Message envoyé avec succès!";

                } catch (Exception $e) {
                    $error = "❌ Erreur lors de l'envoi du message: " . $e->getMessage();
                }
                break;
        }
    }
}

// ============================================
// RÉCUPÉRATION DES DONNÉES
// ============================================

// Récupérer les informations du fournisseur
$infos_fournisseur = [];
try {
    $stmt = $pdo->prepare("
        SELECT f.*, u.email, u.telephone
        FROM fournisseurs f
        LEFT JOIN utilisateurs u ON f.user_id = u.id
        WHERE f.id = :id
    ");
    $stmt->execute([':id' => $fournisseur_id]);
    $infos_fournisseur = $stmt->fetch();
} catch (Exception $e) {
    $error = "Erreur lors du chargement des informations: " . $e->getMessage();
}

// Récupérer les statistiques
$stats = getDashboardStats($pdo, $fournisseur_id);

// Récupérer les catégories
$categories = [];
try {
    $stmt = $pdo->query("SELECT id, nom FROM categories WHERE statut = 'actif' ORDER BY nom");
    $categories = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Erreur lors du chargement des catégories: " . $e->getMessage();
}

// Récupérer les données selon la page
$produits_fournis = [];
$commandes_en_cours = [];
$historique_commandes = [];
$evaluations = [];
$messages = [];

switch ($current_page) {
    case 'produits':
        try {
            $stmt = $pdo->prepare("
                SELECT p.*, c.nom as categorie_nom, 
                       pv.prix_fc, pv.prix_usd,
                       COUNT(cd.id) as ventes_total
                FROM produits p
                LEFT JOIN categories c ON p.categorie_id = c.id
                LEFT JOIN prix_vente pv ON p.id = pv.produit_id AND pv.date_fin IS NULL
                LEFT JOIN commande_details cd ON p.id = cd.produit_id
                WHERE p.fournisseur_id = :fournisseur_id
                GROUP BY p.id
                ORDER BY p.created_at DESC
            ");
            $stmt->execute([':fournisseur_id' => $fournisseur_id]);
            $produits_fournis = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des produits: " . $e->getMessage();
        }
        break;

    case 'commandes':
        try {
            $stmt = $pdo->prepare("
                SELECT c.*, u.nom as client_nom, 
                       COUNT(cd.id) as nombre_produits,
                       SUM(CASE WHEN p.fournisseur_id = :fournisseur_id THEN cd.sous_total ELSE 0 END) as montant_fournisseur
                FROM commandes c
                JOIN utilisateurs u ON c.client_id = u.id
                JOIN commande_details cd ON c.id = cd.commande_id
                JOIN produits p ON cd.produit_id = p.id
                WHERE p.fournisseur_id = :fournisseur_id2
                AND c.statut IN ('en_attente', 'paye')
                GROUP BY c.id
                ORDER BY c.date_commande DESC
                LIMIT 50
            ");
            $stmt->execute([
                ':fournisseur_id' => $fournisseur_id,
                ':fournisseur_id2' => $fournisseur_id
            ]);
            $commandes_en_cours = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des commandes: " . $e->getMessage();
        }
        break;

    case 'historique':
        try {
            $stmt = $pdo->prepare("
                SELECT c.*, u.nom as client_nom,
                       COUNT(cd.id) as nombre_produits,
                       SUM(cd.sous_total) as montant_total
                FROM commandes c
                JOIN utilisateurs u ON c.client_id = u.id
                JOIN commande_details cd ON c.id = cd.commande_id
                JOIN produits p ON cd.produit_id = p.id
                WHERE p.fournisseur_id = :fournisseur_id
                AND c.statut = 'paye'
                AND c.date_commande >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY c.id
                ORDER BY c.date_commande DESC
            ");
            $stmt->execute([':fournisseur_id' => $fournisseur_id]);
            $historique_commandes = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement de l'historique: " . $e->getMessage();
        }
        break;

    case 'evaluations':
        try {
            $stmt = $pdo->prepare("
                SELECT e.*, p.nom as produit_nom,
                       u.nom as evaluateur_nom
                FROM evaluations_fournisseur e
                JOIN produits p ON e.produit_id = p.id
                JOIN utilisateurs u ON e.evaluateur_id = u.id
                WHERE p.fournisseur_id = :fournisseur_id
                ORDER BY e.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([':fournisseur_id' => $fournisseur_id]);
            $evaluations = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des évaluations: " . $e->getMessage();
        }
        break;

    case 'messages':
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM messages_fournisseur
                WHERE fournisseur_id = :fournisseur_id
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $stmt->execute([':fournisseur_id' => $fournisseur_id]);
            $messages = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des messages: " . $e->getMessage();
        }
        break;
}

// Récupérer les commandes récentes pour le dashboard
$commandes_recentes = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.numero_commande, c.montant_total, c.statut,
               c.date_commande, u.nom as client_nom,
               COUNT(cd.id) as nombre_produits
        FROM commandes c
        JOIN utilisateurs u ON c.client_id = u.id
        JOIN commande_details cd ON c.id = cd.commande_id
        JOIN produits p ON cd.produit_id = p.id
        WHERE p.fournisseur_id = :fournisseur_id
        AND c.date_commande >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY c.id
        ORDER BY c.date_commande DESC
        LIMIT 10
    ");
    $stmt->execute([':fournisseur_id' => $fournisseur_id]);
    $commandes_recentes = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Erreur chargement commandes récentes: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NAGEX Pharma - Portail Fournisseur</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .sidebar {
            transition: all 0.3s ease;
        }

        .active-menu {
            background-color: #8b5cf6;
            color: white;
        }

        .stat-card {
            transition: transform 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success {
            background-color: #10b981;
            color: white;
        }

        .badge-warning {
            background-color: #f59e0b;
            color: white;
        }

        .badge-danger {
            background-color: #ef4444;
            color: white;
        }

        .badge-info {
            background-color: #3b82f6;
            color: white;
        }

        .badge-purple {
            background-color: #8b5cf6;
            color: white;
        }

        .note-etoile {
            color: #f59e0b;
        }
    </style>
</head>

<body class="bg-gray-100">

    <!-- Navigation principale -->
    <nav class="bg-purple-700 text-white shadow-lg">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-truck text-2xl"></i>
                    <h1 class="text-xl font-bold">NAGEX Pharma - Portail Fournisseur</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">
                        <i class="fas fa-building mr-1"></i>
                        <?php echo e($infos_fournisseur['nom_societe'] ?? 'Fournisseur'); ?>
                    </span>
                    <a href="logout.php" class="bg-red-500 hover:bg-red-600 px-3 py-1 rounded text-sm">
                        <i class="fas fa-sign-out-alt mr-1"></i>Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="flex">
        <!-- Sidebar -->
        <div class="sidebar w-64 bg-white shadow-lg min-h-screen">
            <div class="p-4 border-b">
                <h2 class="font-bold text-lg text-gray-700">
                    <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                </h2>
            </div>
            <nav class="mt-4">
                <a href="?page=dashboard"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'dashboard' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-home mr-2"></i>Tableau de bord
                </a>

                <div class="px-4 py-2 text-gray-500 text-sm font-semibold mt-4">
                    <i class="fas fa-boxes mr-2"></i>Catalogue
                </div>
                <a href="?page=produits"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'produits' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-list mr-2"></i>Mes produits
                </a>
                <a href="?page=ajouter_produit"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'ajouter_produit' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-plus-circle mr-2"></i>Ajouter produit
                </a>

                <div class="px-4 py-2 text-gray-500 text-sm font-semibold mt-4">
                    <i class="fas fa-shopping-cart mr-2"></i>Commandes
                </div>
                <a href="?page=commandes"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'commandes' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-clipboard-list mr-2"></i>Commandes en cours
                </a>
                <a href="?page=historique"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'historique' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-history mr-2"></i>Historique
                </a>

                <div class="px-4 py-2 text-gray-500 text-sm font-semibold mt-4">
                    <i class="fas fa-chart-line mr-2"></i>Performance
                </div>
                <a href="?page=evaluations"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'evaluations' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-star mr-2"></i>Évaluations
                </a>
                <a href="?page=statistiques"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'statistiques' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-chart-bar mr-2"></i>Statistiques
                </a>

                <div class="px-4 py-2 text-gray-500 text-sm font-semibold mt-4">
                    <i class="fas fa-comments mr-2"></i>Communication
                </div>
                <a href="?page=messages"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'messages' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-envelope mr-2"></i>Messages
                    <?php if (false): // Vous pouvez ajouter une logique pour compter les nouveaux messages ?>
                        <span class="float-right bg-blue-500 text-white text-xs rounded-full px-2">
                            3
                        </span>
                    <?php endif; ?>
                </a>
                <a href="?page=contact"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'contact' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-phone-alt mr-2"></i>Contact
                </a>

                <div class="px-4 py-2 text-gray-500 text-sm font-semibold mt-4">
                    <i class="fas fa-user-circle mr-2"></i>Compte
                </div>
                <a href="?page=profil"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'profil' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-user-edit mr-2"></i>Mon profil
                </a>
            </nav>
        </div>

        <!-- Contenu principal -->
        <div class="flex-1 p-6">
            <!-- Messages d'alerte -->
            <?php if ($message): ?>
                <div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg border border-green-300">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mb-4 p-4 bg-red-100 text-red-700 rounded-lg border border-red-300">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Contenu selon la page -->
            <?php if ($current_page == 'dashboard'): ?>
                <!-- ========== DASHBOARD ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Tableau de bord Fournisseur</h1>
                    <p class="text-gray-600">Bienvenue dans votre espace fournisseur</p>
                </div>

                <!-- Note de qualité -->
                <div
                    class="mb-8 bg-gradient-to-r from-purple-100 to-pink-100 rounded-lg shadow-md p-6 border border-purple-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-xl font-bold text-gray-800 mb-2">Votre note de qualité</h2>
                            <div class="flex items-center">
                                <?php
                                // Convertir la note en float pour les calculs
                                $note_qualite = floatval($stats['note_qualite'] ?? 0);
                                $note_arrondie = floor($note_qualite);
                                $has_demi = fmod($note_qualite, 1) > 0;

                                for ($i = 1; $i <= 5; $i++): ?>
                                    <?php if ($i <= $note_arrondie): ?>
                                        <i class="fas fa-star text-yellow-500 text-2xl mr-1"></i>
                                    <?php elseif ($i == $note_arrondie + 1 && $has_demi): ?>
                                        <i class="fas fa-star-half-alt text-yellow-500 text-2xl mr-1"></i>
                                    <?php else: ?>
                                        <i class="far fa-star text-gray-400 text-2xl mr-1"></i>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <span class="ml-3 text-2xl font-bold text-gray-800">
                                    <?php echo number_format($note_qualite, 1); ?>/5
                                </span>
                            </div>
                            <p class="text-gray-600 mt-2">Évaluation basée sur la qualité de vos produits et services</p>
                        </div>
                        <div class="text-right">
                            <p class="text-4xl font-bold text-purple-600"><?php echo $stats['total_produits']; ?></p>
                            <p class="text-gray-600">Produits fournis</p>
                        </div>
                    </div>
                </div>

                <!-- Cartes de statistiques -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="stat-card bg-white p-6 rounded-lg shadow-md border border-gray-200">
                        <div class="flex items-center">
                            <div class="p-3 bg-green-100 rounded-lg">
                                <i class="fas fa-check-circle text-green-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-gray-500 text-sm">Produits actifs</p>
                                <p class="text-2xl font-bold"><?php echo $stats['produits_actifs']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card bg-white p-6 rounded-lg shadow-md border border-gray-200">
                        <div class="flex items-center">
                            <div class="p-3 bg-blue-100 rounded-lg">
                                <i class="fas fa-shopping-cart text-blue-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-gray-500 text-sm">Commandes en attente</p>
                                <p class="text-2xl font-bold"><?php echo $stats['commandes_attente']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card bg-white p-6 rounded-lg shadow-md border border-gray-200">
                        <div class="flex items-center">
                            <div class="p-3 bg-purple-100 rounded-lg">
                                <i class="fas fa-chart-line text-purple-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-gray-500 text-sm">Ventes totales</p>
                                <p class="text-2xl font-bold"><?php echo formatMontant($stats['ventes_totales']); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card bg-white p-6 rounded-lg shadow-md border border-gray-200">
                        <div class="flex items-center">
                            <div class="p-3 bg-yellow-100 rounded-lg">
                                <i class="fas fa-truck-loading text-yellow-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-gray-500 text-sm">Livraisons</p>
                                <p class="text-2xl font-bold">-</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Commandes récentes -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 class="text-xl font-bold text-gray-800 mb-4">
                            <i class="fas fa-history mr-2"></i>Commandes récentes
                        </h2>
                        <?php if (count($commandes_recentes) > 0): ?>
                            <div class="space-y-3">
                                <?php foreach ($commandes_recentes as $commande): ?>
                                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                        <div>
                                            <p class="font-semibold">#<?php echo e($commande['numero_commande']); ?></p>
                                            <p class="text-sm text-gray-600"><?php echo e($commande['client_nom']); ?></p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-bold text-green-600">
                                                <?php echo formatMontant($commande['montant_total']); ?></p>
                                            <p class="text-sm">
                                                <?php if ($commande['statut'] == 'paye'): ?>
                                                    <span class="badge badge-success">Payé</span>
                                                <?php elseif ($commande['statut'] == 'en_attente'): ?>
                                                    <span class="badge badge-warning">En attente</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger"><?php echo e($commande['statut']); ?></span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-500 text-center py-4">Aucune commande récente</p>
                        <?php endif; ?>
                    </div>

                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 class="text-xl font-bold text-gray-800 mb-4">
                            <i class="fas fa-bolt mr-2"></i>Actions rapides
                        </h2>
                        <div class="space-y-4">
                            <a href="?page=ajouter_produit"
                                class="flex items-center justify-between p-4 bg-purple-50 hover:bg-purple-100 rounded-lg border border-purple-200">
                                <div class="flex items-center">
                                    <i class="fas fa-plus-circle text-purple-600 text-xl mr-3"></i>
                                    <div>
                                        <p class="font-semibold text-purple-700">Ajouter un produit</p>
                                        <p class="text-sm text-purple-600">Étendre votre catalogue</p>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right text-purple-400"></i>
                            </a>

                            <a href="?page=commandes"
                                class="flex items-center justify-between p-4 bg-blue-50 hover:bg-blue-100 rounded-lg border border-blue-200">
                                <div class="flex items-center">
                                    <i class="fas fa-clipboard-list text-blue-600 text-xl mr-3"></i>
                                    <div>
                                        <p class="font-semibold text-blue-700">Voir commandes</p>
                                        <p class="text-sm text-blue-600"><?php echo $stats['commandes_attente']; ?>
                                            commandes en attente</p>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right text-blue-400"></i>
                            </a>

                            <a href="?page=messages"
                                class="flex items-center justify-between p-4 bg-green-50 hover:bg-green-100 rounded-lg border border-green-200">
                                <div class="flex items-center">
                                    <i class="fas fa-envelope text-green-600 text-xl mr-3"></i>
                                    <div>
                                        <p class="font-semibold text-green-700">Envoyer message</p>
                                        <p class="text-sm text-green-600">Contacter la pharmacie</p>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right text-green-400"></i>
                            </a>
                        </div>
                    </div>
                </div>

            <?php elseif ($current_page == 'produits'): ?>
                <!-- ========== MES PRODUITS ========== -->
                <div class="mb-6 flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Mes produits</h1>
                        <p class="text-gray-600">Catalogue des produits que vous fournissez</p>
                    </div>
                    <a href="?page=ajouter_produit"
                        class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Ajouter un produit
                    </a>
                </div>

                <?php if (count($produits_fournis) > 0): ?>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produit</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Catégorie
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code barre
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prix de
                                            vente</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ventes</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($produits_fournis as $produit): ?>
                                        <tr>
                                            <td class="px-6 py-4">
                                                <p class="font-semibold text-gray-900"><?php echo e($produit['nom']); ?></p>
                                                <p class="text-sm text-gray-500 truncate max-w-xs">
                                                    <?php echo e(substr($produit['description'] ?? '', 0, 50)); ?>...</p>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500"><?php echo e($produit['categorie_nom']); ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm font-mono text-gray-500">
                                                <?php echo e($produit['code_barre']); ?></td>
                                            <td class="px-6 py-4">
                                                <?php if ($produit['prix_fc']): ?>
                                                    <p class="text-green-600 font-bold">
                                                        <?php echo formatMontant($produit['prix_fc']); ?></p>
                                                    <p class="text-sm text-blue-600">
                                                        $<?php echo number_format($produit['prix_usd'] ?? 0, 2); ?></p>
                                                <?php else: ?>
                                                    <span class="text-yellow-500 text-sm">Prix non défini</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php if ($produit['statut'] == 'actif'): ?>
                                                    <span class="badge badge-success">Actif</span>
                                                <?php elseif ($produit['statut'] == 'en_attente'): ?>
                                                    <span class="badge badge-warning">En attente</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger">Inactif</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm">
                                                    <?php echo $produit['ventes_total']; ?> ventes
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-sm space-x-2">
                                                <button onclick="voirDetailsProduit(<?php echo $produit['id']; ?>)"
                                                    class="text-blue-600 hover:text-blue-900">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button onclick="modifierProduit(<?php echo $produit['id']; ?>)"
                                                    class="text-purple-600 hover:text-purple-900">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-lg shadow-md p-12 text-center">
                        <i class="fas fa-box-open text-gray-400 text-5xl mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-800 mb-2">Aucun produit</h3>
                        <p class="text-gray-600 mb-6">Vous n'avez pas encore ajouté de produits à votre catalogue.</p>
                        <a href="?page=ajouter_produit"
                            class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>Ajouter votre premier produit
                        </a>
                    </div>
                <?php endif; ?>

            <?php elseif ($current_page == 'ajouter_produit'): ?>
                <!-- ========== AJOUTER UN PRODUIT ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Ajouter un produit au catalogue</h1>
                    <p class="text-gray-600">Remplissez les informations du produit que vous souhaitez fournir</p>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="ajouter_produit_catalogue">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">
                                    <i class="fas fa-info-circle mr-2"></i>Informations du produit
                                </h3>

                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="nom">
                                        Nom du produit *
                                    </label>
                                    <input type="text" id="nom" name="nom" required
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                                        placeholder="Ex: Paracétamol 500mg">
                                </div>

                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="code_barre">
                                        Code barre (EAN/UPC) *
                                    </label>
                                    <input type="text" id="code_barre" name="code_barre" required
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                                        placeholder="Ex: 1234567890123">
                                </div>

                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="categorie_id">
                                        Catégorie *
                                    </label>
                                    <select id="categorie_id" name="categorie_id" required
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                                        <option value="">Sélectionnez une catégorie</option>
                                        <?php foreach ($categories as $categorie): ?>
                                            <option value="<?php echo $categorie['id']; ?>">
                                                <?php echo e($categorie['nom']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">
                                    <i class="fas fa-flask mr-2"></i>Informations techniques
                                </h3>

                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="composition">
                                        Composition
                                    </label>
                                    <textarea id="composition" name="composition" rows="4"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                                        placeholder="Liste des principes actifs et excipients"></textarea>
                                </div>

                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="description">
                                        Description complète *
                                    </label>
                                    <textarea id="description" name="description" rows="4" required
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                                        placeholder="Description détaillée du produit"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                            <p class="text-sm text-yellow-800">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Important:</strong> Votre produit sera soumis à validation par le pharmacien.
                                Une fois validé, il apparaîtra dans le catalogue de la pharmacie.
                            </p>
                        </div>

                        <div class="flex justify-end space-x-4">
                            <a href="?page=produits"
                                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                                Annuler
                            </a>
                            <button type="submit" class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                                <i class="fas fa-paper-plane mr-2"></i>Soumettre le produit
                            </button>
                        </div>
                    </form>
                </div>

            <?php elseif ($current_page == 'commandes'): ?>
                <!-- ========== COMMANDES EN COURS ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Commandes en cours</h1>
                    <p class="text-gray-600">Suivez les commandes contenant vos produits</p>
                </div>

                <?php if (count($commandes_en_cours) > 0): ?>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Commande
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Montant (vos
                                            produits)</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produits
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($commandes_en_cours as $commande): ?>
                                        <tr>
                                            <td class="px-6 py-4">
                                                <p class="font-bold text-gray-900">#<?php echo e($commande['numero_commande']); ?>
                                                </p>
                                            </td>
                                            <td class="px-6 py-4">
                                                <p class="font-semibold"><?php echo e($commande['client_nom']); ?></p>
                                            </td>
                                            <td class="px-6 py-4">
                                               <!-- Ligne ~652 dans le code -->
<p class="font-bold text-purple-600"><?php echo formatMontant(floatval($commande['montant_fournisseur'])); ?></p>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500">
                                                <?php echo date('d/m/Y H:i', strtotime($commande['date_commande'])); ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php if ($commande['statut'] == 'paye'): ?>
                                                    <span class="badge badge-success">Payé</span>
                                                <?php elseif ($commande['statut'] == 'en_attente'): ?>
                                                    <span class="badge badge-warning">En attente</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger"><?php echo e($commande['statut']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm">
                                                    <?php echo $commande['nombre_produits']; ?> produits
                                                </span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <button onclick="voirDetailsCommande(<?php echo $commande['id']; ?>)"
                                                    class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm">
                                                    <i class="fas fa-eye mr-1"></i>Détails
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-lg shadow-md p-12 text-center">
                        <i class="fas fa-shopping-cart text-gray-400 text-5xl mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-800 mb-2">Aucune commande</h3>
                        <p class="text-gray-600">Aucune commande ne contient actuellement vos produits.</p>
                    </div>
                <?php endif; ?>

            <?php elseif ($current_page == 'historique'): ?>
                <!-- ========== HISTORIQUE DES COMMANDES ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Historique des commandes</h1>
                    <p class="text-gray-600">Historique des 30 derniers jours</p>
                </div>

                <?php if (count($historique_commandes) > 0): ?>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Commande
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Montant
                                            total</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produits
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($historique_commandes as $commande): ?>
                                        <tr>
                                            <td class="px-6 py-4">
                                                <p class="font-bold text-gray-900">#<?php echo e($commande['numero_commande']); ?>
                                                </p>
                                            </td>
                                            <td class="px-6 py-4">
                                                <p class="font-semibold"><?php echo e($commande['client_nom']); ?></p>
                                            </td>
                                            <td class="px-6 py-4">
                                                <p class="font-bold text-green-600">
                                                    <?php echo formatMontant($commande['montant_total']); ?></p>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500">
                                                <?php echo date('d/m/Y H:i', strtotime($commande['date_commande'])); ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-sm">
                                                    <?php echo $commande['nombre_produits']; ?> produits
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-lg shadow-md p-12 text-center">
                        <i class="fas fa-history text-gray-400 text-5xl mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-800 mb-2">Aucun historique</h3>
                        <p class="text-gray-600">Aucune commande payée dans les 30 derniers jours.</p>
                    </div>
                <?php endif; ?>

            <?php elseif ($current_page == 'evaluations'): ?>
                <!-- ========== ÉVALUATIONS ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Évaluations et retours</h1>
                    <p class="text-gray-600">Feedback sur vos produits et services</p>
                </div>

                <?php if (count($evaluations) > 0): ?>
                    <div class="space-y-6">
                        <?php foreach ($evaluations as $evaluation): ?>
                            <div class="bg-white rounded-lg shadow-md p-6">
                                <div class="flex justify-between items-start mb-4">
                                    <div>
                                        <h3 class="font-bold text-gray-800"><?php echo e($evaluation['produit_nom']); ?></h3>
                                        <p class="text-sm text-gray-600">Évalué par <?php echo e($evaluation['evaluateur_nom']); ?>
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <div class="flex items-center">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <?php if ($i <= $evaluation['note']): ?>
                                                    <i class="fas fa-star text-yellow-500"></i>
                                                <?php else: ?>
                                                    <i class="far fa-star text-gray-300"></i>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                            <span class="ml-2 font-bold"><?php echo $evaluation['note']; ?>/5</span>
                                        </div>
                                        <p class="text-sm text-gray-500 mt-1">
                                            <?php echo formatDate($evaluation['created_at']); ?>
                                        </p>
                                    </div>
                                </div>

                                <?php if (!empty($evaluation['commentaire'])): ?>
                                    <div class="mt-4 p-4 bg-gray-50 rounded-lg">
                                        <p class="text-gray-700">"<?php echo e($evaluation['commentaire']); ?>"</p>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($evaluation['type_evaluation'])): ?>
                                    <div class="mt-4">
                                        <span
                                            class="inline-block bg-<?php echo $evaluation['type_evaluation'] == 'qualite' ? 'green' : 'blue'; ?>-100 text-<?php echo $evaluation['type_evaluation'] == 'qualite' ? 'green' : 'blue'; ?>-800 px-3 py-1 rounded text-sm">
                                            <?php echo $evaluation['type_evaluation'] == 'qualite' ? 'Qualité' : 'Service'; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-lg shadow-md p-12 text-center">
                        <i class="fas fa-star text-gray-400 text-5xl mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-800 mb-2">Aucune évaluation</h3>
                        <p class="text-gray-600">Vous n'avez pas encore reçu d'évaluations.</p>
                    </div>
                <?php endif; ?>

            <?php elseif ($current_page == 'profil'): ?>
                <!-- ========== MON PROFIL ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Mon profil</h1>
                    <p class="text-gray-600">Gérez les informations de votre société</p>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="nom_societe">
                                        Nom de la société *
                                    </label>
                                    <input type="text" id="nom_societe" name="nom_societe" required
                                        value="<?php echo e($infos_fournisseur['nom_societe'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                                </div>

                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="contact_principal">
                                        Contact principal *
                                    </label>
                                    <input type="text" id="contact_principal" name="contact_principal" required
                                        value="<?php echo e($infos_fournisseur['contact_principal'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                                </div>

                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="telephone">
                                        Téléphone
                                    </label>
                                    <input type="tel" id="telephone" name="telephone"
                                        value="<?php echo e($infos_fournisseur['telephone'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                                </div>
                            </div>

                            <div>
                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="email">
                                        Email de contact *
                                    </label>
                                    <input type="email" id="email" name="email" required
                                        value="<?php echo e($infos_fournisseur['email'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                                </div>

                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="adresse_siege">
                                        Adresse du siège
                                    </label>
                                    <textarea id="adresse_siege" name="adresse_siege" rows="4"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"><?php echo e($infos_fournisseur['adresse_siege'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                            <h4 class="font-semibold text-blue-800 mb-2">
                                <i class="fas fa-info-circle mr-2"></i>Informations complémentaires
                            </h4>
                            <ul class="text-sm text-blue-700 space-y-1">
                                <li>• Note de qualité:
                                    <strong><?php echo number_format($stats['note_qualite'], 1); ?>/5</strong></li>
                                <li>• Produits fournis: <strong><?php echo $stats['total_produits']; ?></strong></li>
                                <li>• Ventes totales:
                                    <strong><?php echo formatMontant($stats['ventes_totales']); ?></strong></li>
                            </ul>
                        </div>

                        <div class="flex justify-end space-x-4">
                            <button type="submit" class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                                <i class="fas fa-save mr-2"></i>Mettre à jour
                            </button>
                        </div>
                    </form>
                </div>

            <?php else: ?>
                <!-- ========== PAGE NON TROUVÉE ========== -->
                <div class="bg-white rounded-lg shadow-md p-12 text-center">
                    <i class="fas fa-exclamation-circle text-red-500 text-5xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-800 mb-2">Page non trouvée</h3>
                    <p class="text-gray-600 mb-6">La page que vous cherchez n'existe pas.</p>
                    <a href="?page=dashboard" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-home mr-2"></i>Retour au dashboard
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modals -->
    <div id="modalMessage" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Envoyer un message</h3>
                <form method="POST" action="" id="formMessage">
                    <input type="hidden" name="action" value="envoyer_message">

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="sujet">
                            Sujet *
                        </label>
                        <input type="text" id="sujet" name="sujet" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="type">
                            Type de message
                        </label>
                        <select id="type" name="type"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="information">Information</option>
                            <option value="question">Question</option>
                            <option value="probleme">Problème</option>
                            <option value="suggestion">Suggestion</option>
                        </select>
                    </div>

                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="message">
                            Message *
                        </label>
                        <textarea id="message" name="message" rows="6" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>

                    <div class="flex justify-end space-x-4">
                        <button type="button" onclick="fermerModalMessage()"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            Annuler
                        </button>
                        <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <i class="fas fa-paper-plane mr-2"></i>Envoyer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts JavaScript -->
    <script>
        // Fonction pour ouvrir le modal d'envoi de message
        function ouvrirModalMessage() {
            document.getElementById('modalMessage').classList.remove('hidden');
        }

        // Fonction pour fermer les modals
        function fermerModalMessage() {
            document.getElementById('modalMessage').classList.add('hidden');
        }

        // Fonction pour voir les détails d'un produit
        function voirDetailsProduit(produitId) {
            window.open('produit_details.php?id=' + produitId, '_blank');
        }

        // Fonction pour modifier un produit
        function modifierProduit(produitId) {
            alert('Modification du produit #' + produitId + ' - Fonctionnalité à implémenter');
        }

        // Fonction pour voir les détails d'une commande
        function voirDetailsCommande(commandeId) {
            window.open('commande_details_fournisseur.php?id=' + commandeId, '_blank');
        }

        // Auto-dismiss les messages d'alerte après 5 secondes
        setTimeout(function () {
            let alerts = document.querySelectorAll('.bg-green-100, .bg-red-100');
            alerts.forEach(function (alert) {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>

</html>