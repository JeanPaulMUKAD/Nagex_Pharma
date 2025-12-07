<?php
declare(strict_types=1);
// ============================================
// MODULE PHARMACIEN - Système NAGEX Pharma
// ============================================
// Fichier : pharmacien_dashboard.php
// Description : Interface complète du pharmacien
// ============================================

// Démarrage de session et vérification du rôle
session_start();

// Vérifier si l'utilisateur est connecté et a le rôle pharmacien
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'pharmacien') {
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
    // Créer une instance de la classe Database
    $database = new Database();
    $pdo = $database->getConnection();
} catch (Exception $e) {
    // Log l'erreur
    error_log("Erreur de connexion à la base de données: " . $e->getMessage());
    die("Erreur de connexion à la base de données. Veuillez contacter l'administrateur.");
}

// ============================================
// FONCTIONS UTILITAIRES
// ============================================

/**
 * Récupère les statistiques du dashboard
 */
function getDashboardStats(PDO $pdo): array
{
    $stats = [];

    // Nombre de produits
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM produits");
    $stats['total_produits'] = $stmt->fetch()['total'] ?? 0;

    // Produits en attente de validation
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM produits WHERE statut = 'en_attente'");
    $stats['produits_attente'] = $stmt->fetch()['total'] ?? 0;

    // Alertes de stock
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM alertes WHERE statut = 'non_lu' AND type_alerte IN ('stock_bas', 'rupture_stock')");
    $stats['alertes_stock'] = $stmt->fetch()['total'] ?? 0;

    // Fournisseurs actifs
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM fournisseurs");
    $stats['total_fournisseurs'] = $stmt->fetch()['total'] ?? 0;

    return $stats;
}

/**
 * Formate la date pour l'affichage
 */
function formatDate(string $date): string
{
    return date('d/m/Y H:i', strtotime($date));
}

/**
 * Échapper les données pour l'affichage HTML
 */
function e(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// ============================================
// GESTION DES PRODUITS (Traitement POST)
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {

            // AJOUTER UN PRODUIT
            case 'ajouter_produit':
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("
                        INSERT INTO produits (
                            nom, description, code_barre, categorie_id, 
                            fournisseur_id, necessite_ordonnance, composition, 
                            posologie, contre_indications, statut, created_by
                        ) VALUES (
                            :nom, :description, :code_barre, :categorie_id,
                            :fournisseur_id, :necessite_ordonnance, :composition,
                            :posologie, :contre_indications, 'en_attente', :created_by
                        )
                    ");

                    $stmt->execute([
                        ':nom' => $_POST['nom'] ?? '',
                        ':description' => $_POST['description'] ?? '',
                        ':code_barre' => $_POST['code_barre'] ?? '',
                        ':categorie_id' => intval($_POST['categorie_id'] ?? 0),
                        ':fournisseur_id' => intval($_POST['fournisseur_id'] ?? 0),
                        ':necessite_ordonnance' => isset($_POST['necessite_ordonnance']) ? 1 : 0,
                        ':composition' => $_POST['composition'] ?? '',
                        ':posologie' => $_POST['posologie'] ?? '',
                        ':contre_indications' => $_POST['contre_indications'] ?? '',
                        ':created_by' => $_SESSION['user_id']
                    ]);

                    $pdo->commit();
                    $message = "✅ Produit ajouté avec succès! Il est maintenant en attente de validation.";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "❌ Erreur lors de l'ajout: " . $e->getMessage();
                }
                break;

            // MODIFIER UN PRODUIT
            case 'modifier_produit':
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("
                        UPDATE produits SET
                            nom = :nom,
                            description = :description,
                            code_barre = :code_barre,
                            categorie_id = :categorie_id,
                            fournisseur_id = :fournisseur_id,
                            necessite_ordonnance = :necessite_ordonnance,
                            composition = :composition,
                            posologie = :posologie,
                            contre_indications = :contre_indications,
                            updated_at = NOW()
                        WHERE id = :id
                    ");

                    $stmt->execute([
                        ':nom' => $_POST['nom'] ?? '',
                        ':description' => $_POST['description'] ?? '',
                        ':code_barre' => $_POST['code_barre'] ?? '',
                        ':categorie_id' => intval($_POST['categorie_id'] ?? 0),
                        ':fournisseur_id' => intval($_POST['fournisseur_id'] ?? 0),
                        ':necessite_ordonnance' => isset($_POST['necessite_ordonnance']) ? 1 : 0,
                        ':composition' => $_POST['composition'] ?? '',
                        ':posologie' => $_POST['posologie'] ?? '',
                        ':contre_indications' => $_POST['contre_indications'] ?? '',
                        ':id' => intval($_POST['produit_id'] ?? 0)
                    ]);

                    $pdo->commit();
                    $message = "✅ Produit modifié avec succès!";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "❌ Erreur lors de la modification: " . $e->getMessage();
                }
                break;

            // VALIDER UN PRODUIT POUR LA VENTE
            case 'valider_produit':
                try {
                    $stmt = $pdo->prepare("
                        UPDATE produits 
                        SET statut = 'actif' 
                        WHERE id = :id AND statut = 'en_attente'
                    ");
                    $stmt->execute([':id' => intval($_POST['produit_id'] ?? 0)]);

                    if ($stmt->rowCount() > 0) {
                        $message = "✅ Produit validé pour la vente!";
                    } else {
                        $error = "❌ Le produit ne peut pas être validé (déjà validé ou non trouvé)";
                    }
                } catch (Exception $e) {
                    $error = "❌ Erreur lors de la validation: " . $e->getMessage();
                }
                break;

            // ARCHIVER UN PRODUIT
            case 'archiver_produit':
                try {
                    $stmt = $pdo->prepare("
                        UPDATE produits 
                        SET statut = 'inactif' 
                        WHERE id = :id
                    ");
                    $stmt->execute([':id' => intval($_POST['produit_id'] ?? 0)]);
                    $message = "✅ Produit archivé avec succès!";
                } catch (Exception $e) {
                    $error = "❌ Erreur lors de l'archivage: " . $e->getMessage();
                }
                break;

            // AJOUTER UNE CATÉGORIE
            case 'ajouter_categorie':
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO categories (nom, description, created_by)
                        VALUES (:nom, :description, :created_by)
                    ");
                    $stmt->execute([
                        ':nom' => $_POST['nom_categorie'] ?? '',
                        ':description' => $_POST['description_categorie'] ?? '',
                        ':created_by' => $_SESSION['user_id']
                    ]);
                    $message = "✅ Catégorie ajoutée avec succès!";
                } catch (Exception $e) {
                    $error = "❌ Erreur lors de l'ajout de la catégorie: " . $e->getMessage();
                }
                break;

            // AJOUTER UN FOURNISSEUR
            case 'ajouter_fournisseur':
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO fournisseurs (
                            nom_societe, contact_principal, adresse_siege, 
                            note_qualite, user_id
                        ) VALUES (
                            :nom_societe, :contact, :adresse, :note, :user_id
                        )
                    ");
                    $stmt->execute([
                        ':nom_societe' => $_POST['nom_societe'] ?? '',
                        ':contact' => $_POST['contact_principal'] ?? '',
                        ':adresse' => $_POST['adresse_siege'] ?? '',
                        ':note' => floatval($_POST['note_qualite'] ?? 3.0),
                        ':user_id' => $_SESSION['user_id']
                    ]);
                    $message = "✅ Fournisseur ajouté avec succès!";
                } catch (Exception $e) {
                    $error = "❌ Erreur lors de l'ajout du fournisseur: " . $e->getMessage();
                }
                break;
        }
    }
}

// ============================================
// RÉCUPÉRATION DES DONNÉES
// ============================================

// Récupérer les catégories
$categories = [];
try {
    $stmt = $pdo->query("SELECT id, nom FROM categories WHERE statut = 'actif' ORDER BY nom");
    $categories = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Erreur lors du chargement des catégories: " . $e->getMessage();
}

// Récupérer les fournisseurs
$fournisseurs = [];
try {
    $stmt = $pdo->query("SELECT id, nom_societe, contact_principal, note_qualite FROM fournisseurs ORDER BY nom_societe");
    $fournisseurs = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Erreur lors du chargement des fournisseurs: " . $e->getMessage();
}

// Récupérer les produits selon la page
$produits = [];
$produits_attente = [];
$liste_categories = [];
$liste_fournisseurs = [];

switch ($current_page) {
    case 'produits':
        try {
            $stmt = $pdo->prepare("
                SELECT p.*, c.nom as categorie_nom, f.nom_societe as fournisseur_nom
                FROM produits p
                LEFT JOIN categories c ON p.categorie_id = c.id
                LEFT JOIN fournisseurs f ON p.fournisseur_id = f.id
                WHERE p.statut != 'inactif'
                ORDER BY p.created_at DESC
                LIMIT 50
            ");
            $stmt->execute();
            $produits = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des produits: " . $e->getMessage();
        }
        break;

    case 'produits_attente':
        try {
            $stmt = $pdo->prepare("
                SELECT p.*, c.nom as categorie_nom, f.nom_societe as fournisseur_nom
                FROM produits p
                LEFT JOIN categories c ON p.categorie_id = c.id
                LEFT JOIN fournisseurs f ON p.fournisseur_id = f.id
                WHERE p.statut = 'en_attente'
                ORDER BY p.created_at DESC
            ");
            $stmt->execute();
            $produits_attente = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des produits en attente: " . $e->getMessage();
        }
        break;

    case 'categories':
        try {
            $stmt = $pdo->query("
                SELECT c.*, COUNT(p.id) as nombre_produits
                FROM categories c
                LEFT JOIN produits p ON c.id = p.categorie_id AND p.statut != 'inactif'
                GROUP BY c.id
                ORDER BY c.nom
            ");
            $liste_categories = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des catégories: " . $e->getMessage();
        }
        break;

    case 'fournisseurs':
        try {
            $stmt = $pdo->query("
                SELECT f.*, 
                       COUNT(p.id) as nombre_produits,
                       AVG(CASE WHEN p.statut = 'actif' THEN 1 ELSE 0 END) * 100 as taux_activation
                FROM fournisseurs f
                LEFT JOIN produits p ON f.id = p.fournisseur_id
                GROUP BY f.id
                ORDER BY f.nom_societe
            ");
            $liste_fournisseurs = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des fournisseurs: " . $e->getMessage();
        }
        break;
}

// Récupérer les statistiques pour le dashboard
$stats = getDashboardStats($pdo);

// Récupérer les dernières alertes
$alertes = [];
try {
    $stmt = $pdo->query("
        SELECT a.*, p.nom as produit_nom
        FROM alertes a
        LEFT JOIN produits p ON a.produit_id = p.id
        WHERE a.statut = 'non_lu'
        ORDER BY a.created_at DESC
        LIMIT 5
    ");
    $alertes = $stmt->fetchAll();
} catch (Exception $e) {
    // Ne pas bloquer l'affichage si les alertes ne peuvent pas être chargées
    error_log("Erreur chargement alertes: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NAGEX Pharma - Dashboard Pharmacien</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">


</head>

<body class="bg-gray-100" style="font-family: 'Montserrat', sans-serif;">


    <div class="flex">
        <!-- Sidebar -->
        <style>
            .sidebar {
                transition: all 0.3s ease;
                box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.1);
            }

            .active-menu {
                background: linear-gradient(135deg, #10B981 0%, #059669 100%);
                color: white;
                box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
            }

            .stat-card {
                transition: transform 0.2s ease, box-shadow 0.2s ease;
            }

            .stat-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            }

            .badge {
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
            }

            .badge-success {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                color: white;
            }

            .badge-warning {
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                color: white;
            }

            .badge-danger {
                background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                color: white;
            }

            .badge-info {
                background: linear-gradient(135deg, #10B981 0%, #059669 100%);
                color: white;
            }

            .badge-purple {
                background: linear-gradient(135deg, #10B981 0%, #059669 100%);
                color: white;
            }

            .note-etoile {
                color: #f59e0b;
            }

            .menu-item {
                position: relative;
                overflow: hidden;
                border-radius: 8px;
                margin: 2px 8px;
            }

            .menu-item::before {
                content: '';
                position: absolute;
                left: 0;
                top: 0;
                height: 100%;
                width: 4px;
                background: #10B981;
                transform: scaleY(0);
                transition: transform 0.2s ease;
            }

            .menu-item:hover::before {
                transform: scaleY(1);
            }

            .active-menu::before {
                transform: scaleY(1);
            }

            .section-title {
                position: relative;
                padding-left: 20px;
            }

            .section-title::before {
                content: '';
                position: absolute;
                left: 0;
                top: 50%;
                transform: translateY(-50%);
                width: 8px;
                height: 8px;
                background: #10B981;
                border-radius: 50%;
            }
        </style>

        <div class="sidebar w-64 bg-white shadow-xl min-h-screen flex flex-col relative">
            <!-- Logo Section avec fond dégradé -->
            <div class="flex items-center justify-center p-6 border-b bg-gradient-to-r from-emerald-50 to-green-50">
                <div class="flex items-center space-x-3">
                    <div
                        class="w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg">
                        <i class="fas fa-capsules text-white text-xl"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-gray-800">NAGEX Pharma</h1>
                        <p class="text-xs text-green-600 font-medium">Pharmacien Dashboard</p>
                    </div>
                </div>
            </div>

            <!-- Navigation Menu -->
            <nav class="flex-1 p-4 overflow-y-auto">
                <!-- Dashboard -->
                <div class="mb-6">
                    <a href="?page=dashboard"
                        class="menu-item block py-3 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'dashboard' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                        <i class="fas fa-home mr-3 w-5 text-center"></i>
                        <span class="font-medium">Tableau de bord</span>
                    </a>
                </div>

                <!-- Gestion des Produits -->
                <div class="mb-6">
                    <div class="section-title text-xs text-gray-500 font-semibold uppercase tracking-wider mb-2">
                        <i class="fas fa-capsules mr-2"></i>Gestion des Produits
                    </div>
                    <div class="space-y-1">
                        <a href="?page=produits"
                            class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'produits' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                            <i class="fas fa-list mr-3 w-5 text-center"></i>
                            <span>Tous les produits</span>
                        </a>
                        <a href="?page=ajouter_produit"
                            class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'ajouter_produit' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                            <i class="fas fa-plus-circle mr-3 w-5 text-center"></i>
                            <span>Ajouter un produit</span>
                        </a>
                        <a href="?page=produits_attente"
                            class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'produits_attente' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                            <i class="fas fa-clock mr-3 w-5 text-center"></i>
                            <span class="flex-1">En attente</span>
                            <?php if ($stats['produits_attente'] > 0): ?>
                                <span class="badge-danger text-xs font-bold px-2 py-1 min-w-[24px] text-center">
                                    <?php echo $stats['produits_attente']; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>

                <!-- Catégories -->
                <div class="mb-6">
                    <div class="section-title text-xs text-gray-500 font-semibold uppercase tracking-wider mb-2">
                        <i class="fas fa-tags mr-2"></i>Catégories
                    </div>
                    <a href="?page=categories"
                        class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'categories' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                        <i class="fas fa-folder mr-3 w-5 text-center"></i>
                        <span>Gérer les catégories</span>
                    </a>
                </div>

                <!-- Fournisseurs -->
                <div class="mb-6">
                    <div class="section-title text-xs text-gray-500 font-semibold uppercase tracking-wider mb-2">
                        <i class="fas fa-truck mr-2"></i>Fournisseurs
                    </div>
                    <div class="space-y-1">
                        <a href="?page=fournisseurs"
                            class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'fournisseurs' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                            <i class="fas fa-address-book mr-3 w-5 text-center"></i>
                            <span>Liste des fournisseurs</span>
                        </a>
                        <a href="?page=ajouter_fournisseur"
                            class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'ajouter_fournisseur' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                            <i class="fas fa-user-plus mr-3 w-5 text-center"></i>
                            <span>Nouveau fournisseur</span>
                        </a>
                    </div>
                </div>

                <!-- Alertes -->
                <div class="mb-6">
                    <div class="section-title text-xs text-gray-500 font-semibold uppercase tracking-wider mb-2">
                        <i class="fas fa-bell mr-2"></i>Alertes
                    </div>
                    <a href="?page=alertes"
                        class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'alertes' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                        <i class="fas fa-exclamation-triangle mr-3 w-5 text-center"></i>
                        <span class="flex-1">Alertes stock</span>
                        <?php if ($stats['alertes_stock'] > 0): ?>
                            <span class="badge-warning text-xs font-bold px-2 py-1 min-w-[24px] text-center">
                                <?php echo $stats['alertes_stock']; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </div>
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
                    <h1 class="text-2xl font-bold text-gray-800">Tableau de bord Pharmacien</h1>
                    <p class="text-gray-600">Bienvenue dans votre espace de gestion</p>
                </div>

                <!-- Cartes de statistiques -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div
                        class="stat-card group bg-gradient-to-br from-white to-blue-50 p-6 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 border border-blue-100 hover:border-blue-200 relative overflow-hidden">
                        <div class="relative z-10 flex items-center">
                            <div
                                class="stat-icon p-3 bg-gradient-to-br from-blue-100 to-blue-200 rounded-xl shadow-inner group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-capsules text-blue-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-gray-600 text-sm font-medium">Produits totaux</p>
                                <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo $stats['total_produits']; ?></p>
                            </div>
                        </div>
                        <div class="absolute bottom-0 right-0 opacity-10">
                            <i class="fas fa-capsules text-blue-400 text-5xl"></i>
                        </div>
                    </div>

                    <div
                        class="stat-card group bg-gradient-to-br from-white to-yellow-50 p-6 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 border border-yellow-100 hover:border-yellow-200 relative overflow-hidden">
                        <div class="relative z-10 flex items-center">
                            <div
                                class="stat-icon p-3 bg-gradient-to-br from-yellow-100 to-yellow-200 rounded-xl shadow-inner group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-clock text-yellow-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-gray-600 text-sm font-medium">En attente</p>
                                <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo $stats['produits_attente']; ?>
                                </p>
                            </div>
                        </div>
                        <div class="absolute bottom-0 right-0 opacity-10">
                            <i class="fas fa-clock text-yellow-400 text-5xl"></i>
                        </div>
                    </div>

                    <div
                        class="stat-card group bg-gradient-to-br from-white to-red-50 p-6 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 border border-red-100 hover:border-red-200 relative overflow-hidden">
                        <div class="relative z-10 flex items-center">
                            <div
                                class="stat-icon p-3 bg-gradient-to-br from-red-100 to-red-200 rounded-xl shadow-inner group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-gray-600 text-sm font-medium">Alertes stock</p>
                                <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo $stats['alertes_stock']; ?></p>
                            </div>
                        </div>
                        <div class="absolute bottom-0 right-0 opacity-10">
                            <i class="fas fa-exclamation-triangle text-red-400 text-5xl"></i>
                        </div>
                    </div>

                    <div
                        class="stat-card group bg-gradient-to-br from-white to-green-50 p-6 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 border border-green-100 hover:border-green-200 relative overflow-hidden">
                        <div class="relative z-10 flex items-center">
                            <div
                                class="stat-icon p-3 bg-gradient-to-br from-green-100 to-green-200 rounded-xl shadow-inner group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-truck text-green-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-gray-600 text-sm font-medium">Fournisseurs</p>
                                <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo $stats['total_fournisseurs']; ?>
                                </p>
                            </div>
                        </div>
                        <div class="absolute bottom-0 right-0 opacity-10">
                            <i class="fas fa-truck text-green-400 text-5xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Alertes récentes -->
                <div class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg p-6 mb-8 border border-gray-200">
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center">
                            <div class="p-3 bg-gradient-to-br from-red-100 to-red-200 rounded-xl shadow-sm mr-3">
                                <i class="fas fa-bell text-red-600"></i>
                            </div>
                            <h2 class="text-xl font-bold text-gray-800">Alertes récentes</h2>
                        </div>
                        <a href="?page=alertes"
                            class="text-sm text-emerald-600 hover:text-emerald-700 font-medium flex items-center">
                            Voir toutes
                            <i class="fas fa-arrow-right ml-1 text-xs"></i>
                        </a>
                    </div>

                    <?php if (count($alertes) > 0): ?>
                        <div class="overflow-hidden rounded-lg border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                                    <tr>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            Type</th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            Produit</th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            Message</th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            Date</th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($alertes as $alerte): ?>
                                        <tr class="hover:bg-gray-50 transition-colors duration-150">
                                            <td class="px-6 py-4">
                                                <?php if ($alerte['type_alerte'] == 'stock_bas'): ?>
                                                    <span
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-yellow-100 to-yellow-200 text-yellow-800 border border-yellow-200">
                                                        <i class="fas fa-exclamation-circle mr-1"></i> Stock bas
                                                    </span>
                                                <?php elseif ($alerte['type_alerte'] == 'rupture_stock'): ?>
                                                    <span
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-red-100 to-red-200 text-red-800 border border-red-200">
                                                        <i class="fas fa-times-circle mr-1"></i> Rupture
                                                    </span>
                                                <?php else: ?>
                                                    <span
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-blue-100 to-blue-200 text-blue-800 border border-blue-200">
                                                        <i class="fas fa-calendar-exclamation mr-1"></i> Péremption
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($alerte['produit_nom']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">Code:
                                                    <?php echo htmlspecialchars($alerte['produit_code'] ?? 'N/A'); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm text-gray-800">
                                                    <?php echo htmlspecialchars($alerte['message']); ?>
                                                </div>
                                                <?php if (isset($alerte['quantite_restante'])): ?>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        Stock: <?php echo htmlspecialchars($alerte['quantite_restante']); ?> unités
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500">
                                                <div class="flex items-center">
                                                    <i class="far fa-calendar-alt mr-2 text-gray-400"></i>
                                                    <?php echo formatDate($alerte['created_at']); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <a href="?page=produits&id=<?php echo $alerte['produit_id']; ?>"
                                                    class="inline-flex items-center px-3 py-1 bg-gradient-to-r from-emerald-100 to-emerald-50 hover:from-emerald-200 hover:to-emerald-100 text-emerald-700 text-sm font-medium rounded-lg border border-emerald-200 transition-all duration-200">
                                                    <i class="fas fa-eye mr-1 text-xs"></i> Voir
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-10">
                            <div
                                class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gradient-to-r from-green-100 to-emerald-100 mb-4">
                                <i class="fas fa-check-circle text-emerald-600 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-medium text-gray-700 mb-2">Aucune alerte non lue</h3>
                            <p class="text-gray-500">Toutes les alertes sont traitées.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Actions rapides -->
                <div class="bg-gradient-to-br from-white to-emerald-50 rounded-xl shadow-lg p-6 border border-emerald-200">
                    <div class="flex items-center mb-6">
                        <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl shadow-sm mr-3">
                            <i class="fas fa-bolt text-emerald-600"></i>
                        </div>
                        <h2 class="text-xl font-bold text-gray-800">Actions rapides</h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <a href="?page=ajouter_produit"
                            class="group relative bg-gradient-to-br from-blue-50 to-blue-100 hover:from-blue-100 hover:to-blue-200 border border-blue-200 rounded-xl p-6 text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 overflow-hidden">
                            <div
                                class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-blue-400 to-blue-500 transform scale-x-0 group-hover:scale-x-100 transition-transform duration-300">
                            </div>
                            <div
                                class="p-3 bg-gradient-to-br from-white to-blue-50 rounded-lg inline-flex items-center justify-center mb-4 shadow-sm group-hover:shadow transition-shadow duration-300">
                                <i class="fas fa-plus-circle text-blue-600 text-2xl"></i>
                            </div>
                            <h3 class="font-bold text-blue-700 mb-2">Ajouter un produit</h3>
                            <p class="text-sm text-blue-600 opacity-80">Nouvelle entrée en stock</p>
                            <div
                                class="mt-3 text-xs text-blue-500 group-hover:text-blue-600 transition-colors duration-200">
                                <i class="fas fa-arrow-right mr-1"></i> Accéder
                            </div>
                        </a>

                        <a href="?page=produits_attente"
                            class="group relative bg-gradient-to-br from-yellow-50 to-yellow-100 hover:from-yellow-100 hover:to-yellow-200 border border-yellow-200 rounded-xl p-6 text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 overflow-hidden">
                            <div
                                class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-yellow-400 to-yellow-500 transform scale-x-0 group-hover:scale-x-100 transition-transform duration-300">
                            </div>
                            <div
                                class="p-3 bg-gradient-to-br from-white to-yellow-50 rounded-lg inline-flex items-center justify-center mb-4 shadow-sm group-hover:shadow transition-shadow duration-300">
                                <i class="fas fa-check-circle text-yellow-600 text-2xl"></i>
                            </div>
                            <h3 class="font-bold text-yellow-700 mb-2">Valider produits</h3>
                            <p class="text-sm text-yellow-600 opacity-80">Approuver en attente</p>
                            <div
                                class="mt-3 text-xs text-yellow-500 group-hover:text-yellow-600 transition-colors duration-200">
                                <i class="fas fa-arrow-right mr-1"></i> Accéder
                            </div>
                        </a>

                        <a href="?page=ajouter_fournisseur"
                            class="group relative bg-gradient-to-br from-green-50 to-green-100 hover:from-green-100 hover:to-green-200 border border-green-200 rounded-xl p-6 text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 overflow-hidden">
                            <div
                                class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-green-400 to-green-500 transform scale-x-0 group-hover:scale-x-100 transition-transform duration-300">
                            </div>
                            <div
                                class="p-3 bg-gradient-to-br from-white to-green-50 rounded-lg inline-flex items-center justify-center mb-4 shadow-sm group-hover:shadow transition-shadow duration-300">
                                <i class="fas fa-user-plus text-green-600 text-2xl"></i>
                            </div>
                            <h3 class="font-bold text-green-700 mb-2">Nouveau fournisseur</h3>
                            <p class="text-sm text-green-600 opacity-80">Ajouter un partenaire</p>
                            <div
                                class="mt-3 text-xs text-green-500 group-hover:text-green-600 transition-colors duration-200">
                                <i class="fas fa-arrow-right mr-1"></i> Accéder
                            </div>
                        </a>
                    </div>
                </div>

            <?php elseif ($current_page == 'produits'): ?>
                <!-- ========== LISTE DES PRODUITS ========== -->
                <div class="mb-8">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800 flex items-center">
                                <div
                                    class="p-2 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-lg mr-3 shadow-sm">
                                    <i class="fas fa-capsules text-emerald-600"></i>
                                </div>
                                Gestion des Produits
                            </h1>
                            <p class="text-gray-600 mt-1 ml-11">Liste de tous les produits actifs dans le système</p>
                        </div>
                        <a href="?page=ajouter_produit"
                            class="group relative bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-5 py-3 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5">
                            <i class="fas fa-plus mr-2"></i>Ajouter un produit
                            <div
                                class="absolute inset-0 rounded-xl border border-blue-300 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                            </div>
                        </a>
                    </div>

                    <div
                        class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg overflow-hidden border border-gray-200">
                        <!-- Header avec filtres -->
                        <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100">
                            <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                                <div class="flex items-center">
                                    <i class="fas fa-filter text-gray-400 mr-2"></i>
                                    <span class="text-sm text-gray-600"><?php echo count($produits); ?> produit(s)
                                        trouvé(s)</span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <div class="relative">
                                        <input type="text" placeholder="Rechercher un produit..."
                                            class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm">
                                        <i
                                            class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                    </div>
                                    <button
                                        class="p-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                                        <i class="fas fa-sort-amount-down text-gray-600"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Tableau -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr class="bg-gradient-to-r from-gray-50 to-gray-100">
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-hashtag mr-2 text-gray-400"></i> ID
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-tag mr-2 text-gray-400"></i> Nom
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-folder mr-2 text-gray-400"></i> Catégorie
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-truck mr-2 text-gray-400"></i> Fournisseur
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-circle mr-2 text-gray-400"></i> Statut
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-barcode mr-2 text-gray-400"></i> Code barre
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-cogs mr-2 text-gray-400"></i> Actions
                                            </div>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($produits as $produit): ?>
                                        <tr
                                            class="hover:bg-gradient-to-r hover:from-blue-50 hover:to-transparent transition-all duration-200 group">
                                            <td class="px-6 py-4">
                                                <div
                                                    class="text-sm font-medium text-gray-900 bg-gray-50 px-3 py-1 rounded-lg inline-block">
                                                    #<?php echo str_pad($produit['id'], 4, '0', STR_PAD_LEFT); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center">
                                                    <div class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg mr-3">
                                                        <i class="fas fa-pills text-blue-600 text-sm"></i>
                                                    </div>
                                                    <div>
                                                        <div
                                                            class="font-semibold text-gray-900 group-hover:text-blue-600 transition-colors">
                                                            <?php echo htmlspecialchars($produit['nom']); ?>
                                                        </div>
                                                        <?php if ($produit['necessite_ordonnance']): ?>
                                                            <span
                                                                class="inline-flex items-center mt-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gradient-to-r from-purple-100 to-purple-50 text-purple-800 border border-purple-200">
                                                                <i class="fas fa-file-prescription mr-1 text-xs"></i> Ordonnance
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center">
                                                    <span
                                                        class="px-3 py-1 rounded-full text-xs font-medium bg-gradient-to-r from-emerald-50 to-emerald-100 text-emerald-800 border border-emerald-200">
                                                        <?php echo htmlspecialchars($produit['categorie_nom']); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm text-gray-900">
                                                    <?php echo htmlspecialchars($produit['fournisseur_nom']); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php if ($produit['statut'] == 'actif'): ?>
                                                    <span
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-green-100 to-green-50 text-green-800 border border-green-200">
                                                        <i class="fas fa-check-circle mr-1 text-xs"></i> Actif
                                                    </span>
                                                <?php elseif ($produit['statut'] == 'en_attente'): ?>
                                                    <span
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-yellow-100 to-yellow-50 text-yellow-800 border border-yellow-200">
                                                        <i class="fas fa-clock mr-1 text-xs"></i> En attente
                                                    </span>
                                                <?php else: ?>
                                                    <span
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-red-100 to-red-50 text-red-800 border border-red-200">
                                                        <i class="fas fa-times-circle mr-1 text-xs"></i> Inactif
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center">
                                                    <i class="fas fa-barcode text-gray-400 mr-2 text-sm"></i>
                                                    <code class="text-sm text-gray-900 bg-gray-50 px-2 py-1 rounded font-mono">
                                                                                        <?php echo htmlspecialchars($produit['code_barre']); ?>
                                                                                    </code>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center space-x-2">
                                                    <a href="?page=modifier_produit&id=<?php echo $produit['id']; ?>"
                                                        class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 hover:from-blue-100 hover:to-blue-200 text-blue-600 rounded-lg border border-blue-200 hover:shadow-sm transition-all duration-200 group/edit"
                                                        title="Modifier">
                                                        <i
                                                            class="fas fa-edit text-sm group-hover/edit:scale-110 transition-transform"></i>
                                                    </a>

                                                    <button onclick="archiverProduit(<?php echo $produit['id']; ?>)"
                                                        class="p-2 bg-gradient-to-br from-red-50 to-red-100 hover:from-red-100 hover:to-red-200 text-red-600 rounded-lg border border-red-200 hover:shadow-sm transition-all duration-200 group/archive"
                                                        title="Archiver">
                                                        <i
                                                            class="fas fa-archive text-sm group-hover/archive:scale-110 transition-transform"></i>
                                                    </button>

                                                    <?php if ($produit['statut'] == 'en_attente'): ?>
                                                        <button onclick="validerProduit(<?php echo $produit['id']; ?>)"
                                                            class="p-2 bg-gradient-to-br from-green-50 to-green-100 hover:from-green-100 hover:to-green-200 text-green-600 rounded-lg border border-green-200 hover:shadow-sm transition-all duration-200 group/validate"
                                                            title="Valider">
                                                            <i
                                                                class="fas fa-check text-sm group-hover/validate:scale-110 transition-transform"></i>
                                                        </button>
                                                    <?php endif; ?>

                                                    <a href="?page=produit_details&id=<?php echo $produit['id']; ?>"
                                                        class="p-2 bg-gradient-to-br from-gray-50 to-gray-100 hover:from-gray-100 hover:to-gray-200 text-gray-600 rounded-lg border border-gray-200 hover:shadow-sm transition-all duration-200 group/view"
                                                        title="Voir détails">
                                                        <i
                                                            class="fas fa-eye text-sm group-hover/view:scale-110 transition-transform"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if (isset($pagination) && $pagination['total_pages'] > 1): ?>
                            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                                <div class="flex items-center justify-between">
                                    <div class="text-sm text-gray-700">
                                        Page <span class="font-medium"><?php echo $pagination['current_page']; ?></span> sur
                                        <span class="font-medium"><?php echo $pagination['total_pages']; ?></span>
                                    </div>
                                    <div class="flex space-x-2">
                                        <?php if ($pagination['current_page'] > 1): ?>
                                            <a href="?page=produits&p=<?php echo $pagination['current_page'] - 1; ?>"
                                                class="px-3 py-1 border border-gray-300 rounded-lg hover:bg-gray-100 text-sm">
                                                <i class="fas fa-chevron-left mr-1"></i> Précédent
                                            </a>
                                        <?php endif; ?>

                                        <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                                            <a href="?page=produits&p=<?php echo $i; ?>"
                                                class="px-3 py-1 border rounded-lg text-sm <?php echo $i == $pagination['current_page'] ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 hover:bg-gray-100'; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        <?php endfor; ?>

                                        <?php if ($pagination['current_page'] < $pagination['total_pages']): ?>
                                            <a href="?page=produits&p=<?php echo $pagination['current_page'] + 1; ?>"
                                                class="px-3 py-1 border border-gray-300 rounded-lg hover:bg-gray-100 text-sm">
                                                Suivant <i class="fas fa-chevron-right ml-1"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($current_page == 'ajouter_produit'): ?>
                <!-- ========== AJOUTER UN PRODUIT ========== -->
                <div class="mb-8">
                    <div class="flex items-center mb-6">
                        <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl shadow-sm mr-4">
                            <i class="fas fa-plus-circle text-emerald-600 text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Ajouter un nouveau produit</h1>
                            <p class="text-gray-600 mt-1">Remplissez le formulaire pour ajouter un produit au catalogue</p>
                        </div>
                    </div>

                    <div class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg p-8 border border-gray-200">
                        <form method="POST" action="" class="space-y-8">
                            <input type="hidden" name="action" value="ajouter_produit">

                            <!-- Section Informations de base -->
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                <!-- Informations de base -->
                                <div class="space-y-6">
                                    <div class="flex items-center pb-3 border-b border-gray-200">
                                        <div class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg mr-3">
                                            <i class="fas fa-info-circle text-blue-600"></i>
                                        </div>
                                        <h3 class="text-lg font-semibold text-gray-800">Informations de base</h3>
                                    </div>

                                    <div class="space-y-5">
                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-2" for="nom">
                                                <span class="text-red-500">*</span> Nom du produit
                                            </label>
                                            <div class="relative">
                                                <input type="text" id="nom" name="nom" required
                                                    class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow"
                                                    placeholder="Entrez le nom du produit">
                                                <div
                                                    class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                    <i class="fas fa-capsules"></i>
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-2" for="code_barre">
                                                Code barre
                                            </label>
                                            <div class="relative">
                                                <input type="text" id="code_barre" name="code_barre"
                                                    class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow"
                                                    placeholder="Code barre (optionnel)">
                                                <div
                                                    class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                    <i class="fas fa-barcode"></i>
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-2"
                                                for="categorie_id">
                                                <span class="text-red-500">*</span> Catégorie
                                            </label>
                                            <div class="relative">
                                                <select id="categorie_id" name="categorie_id" required
                                                    class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow appearance-none bg-white">
                                                    <option value="" disabled selected>Sélectionnez une catégorie</option>
                                                    <?php foreach ($categories as $categorie): ?>
                                                        <option value="<?php echo $categorie['id']; ?>" class="py-2">
                                                            <?php echo htmlspecialchars($categorie['nom']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div
                                                    class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                    <i class="fas fa-folder"></i>
                                                </div>
                                                <div
                                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                    <i class="fas fa-chevron-down"></i>
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-2"
                                                for="fournisseur_id">
                                                <span class="text-red-500">*</span> Fournisseur
                                            </label>
                                            <div class="relative">
                                                <select id="fournisseur_id" name="fournisseur_id" required
                                                    class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow appearance-none bg-white">
                                                    <option value="" disabled selected>Sélectionnez un fournisseur</option>
                                                    <?php foreach ($fournisseurs as $fournisseur): ?>
                                                        <option value="<?php echo $fournisseur['id']; ?>" class="py-2">
                                                            <span
                                                                class="font-medium"><?php echo htmlspecialchars($fournisseur['nom_societe']); ?></span>
                                                            <span class="text-gray-500 ml-2">
                                                                (Note:
                                                                <span class="inline-flex items-center ml-1">
                                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                        <i
                                                                            class="fas fa-star <?php echo $i <= $fournisseur['note_qualite'] ? 'text-yellow-500' : 'text-gray-300'; ?> text-xs"></i>
                                                                    <?php endfor; ?>
                                                                </span>)
                                                            </span>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div
                                                    class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                    <i class="fas fa-truck"></i>
                                                </div>
                                                <div
                                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                    <i class="fas fa-chevron-down"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Informations médicales -->
                                <div class="space-y-6">
                                    <div class="flex items-center pb-3 border-b border-gray-200">
                                        <div class="p-2 bg-gradient-to-br from-red-50 to-red-100 rounded-lg mr-3">
                                            <i class="fas fa-stethoscope text-red-600"></i>
                                        </div>
                                        <h3 class="text-lg font-semibold text-gray-800">Informations médicales</h3>
                                    </div>

                                    <div class="space-y-5">
                                        <div
                                            class="bg-gradient-to-r from-purple-50 to-purple-100 border border-purple-200 rounded-xl p-4">
                                            <label class="flex items-center space-x-3 cursor-pointer group">
                                                <div class="relative">
                                                    <input type="checkbox" name="necessite_ordonnance" value="1"
                                                        class="sr-only peer">
                                                    <div
                                                        class="w-10 h-5 bg-gray-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-purple-600">
                                                    </div>
                                                </div>
                                                <div class="flex-1">
                                                    <span
                                                        class="font-semibold text-gray-700 group-hover:text-purple-700 transition-colors">Nécessite
                                                        une ordonnance</span>
                                                    <p class="text-xs text-gray-500 mt-1">Produit soumis à prescription
                                                        médicale</p>
                                                </div>
                                                <i class="fas fa-file-prescription text-purple-500 text-lg"></i>
                                            </label>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-2" for="composition">
                                                Composition
                                            </label>
                                            <div class="relative">
                                                <textarea id="composition" name="composition" rows="3"
                                                    class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow resize-none"
                                                    placeholder="Composition chimique du produit"></textarea>
                                                <div class="absolute right-3 bottom-3 text-gray-400">
                                                    <i class="fas fa-flask"></i>
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-2" for="posologie">
                                                Posologie
                                            </label>
                                            <div class="relative">
                                                <textarea id="posologie" name="posologie" rows="3"
                                                    class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow resize-none"
                                                    placeholder="Instructions d'utilisation"></textarea>
                                                <div class="absolute right-3 bottom-3 text-gray-400">
                                                    <i class="fas fa-capsules"></i>
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-2"
                                                for="contre_indications">
                                                Contre-indications
                                            </label>
                                            <div class="relative">
                                                <textarea id="contre_indications" name="contre_indications" rows="3"
                                                    class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow resize-none"
                                                    placeholder="Contre-indications médicales"></textarea>
                                                <div class="absolute right-3 bottom-3 text-gray-400">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Description complète -->
                            <div class="pt-6 border-t border-gray-200">
                                <div class="flex items-center mb-4">
                                    <div class="p-2 bg-gradient-to-br from-green-50 to-green-100 rounded-lg mr-3">
                                        <i class="fas fa-align-left text-green-600"></i>
                                    </div>
                                    <label class="block text-sm font-semibold text-gray-700" for="description">
                                        <span class="text-red-500">*</span> Description complète
                                    </label>
                                </div>
                                <div class="relative">
                                    <textarea id="description" name="description" rows="4" required
                                        class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow resize-none"
                                        placeholder="Description détaillée du produit..."></textarea>
                                    <div class="absolute right-3 bottom-3 text-gray-400">
                                        <i class="fas fa-edit"></i>
                                    </div>
                                </div>
                            </div>

                            <!-- Boutons d'action -->
                            <div class="pt-6 border-t border-gray-200">
                                <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-4">
                                    <a href="?page=produits"
                                        class="group px-6 py-3 border border-gray-300 rounded-xl text-gray-700 hover:bg-gray-50 hover:shadow transition-all duration-200 text-center font-medium">
                                        <i class="fas fa-times mr-2 group-hover:rotate-90 transition-transform"></i>
                                        Annuler
                                    </a>
                                    <button type="submit"
                                        class="group relative bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-8 py-3 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5">
                                        <i class="fas fa-save mr-2 group-hover:rotate-12 transition-transform"></i>
                                        Enregistrer le produit
                                        <div
                                            class="absolute inset-0 rounded-xl border border-green-400 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                        </div>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($current_page == 'produits_attente'): ?>
                <!-- ========== PRODUITS EN ATTENTE ========== -->
                <div class="mb-8">
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center">
                            <div class="p-3 bg-gradient-to-br from-yellow-100 to-yellow-200 rounded-xl shadow-sm mr-4">
                                <i class="fas fa-clock text-yellow-600 text-xl"></i>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold text-gray-800">Produits en attente de validation</h1>
                                <p class="text-gray-600 mt-1">Produits ajoutés mais pas encore validés pour la vente</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <span
                                class="px-3 py-1 bg-gradient-to-r from-yellow-100 to-yellow-50 text-yellow-800 rounded-full text-sm font-semibold border border-yellow-200">
                                <i class="fas fa-hourglass-half mr-1"></i>
                                <?php echo count($produits_attente); ?> en attente
                            </span>
                            <a href="?page=ajouter_produit"
                                class="group bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200">
                                <i class="fas fa-plus mr-2 group-hover:rotate-90 transition-transform"></i>
                                Ajouter
                            </a>
                        </div>
                    </div>

                    <?php if (count($produits_attente) > 0): ?>
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg overflow-hidden border border-gray-200">
                            <!-- Header avec actions groupées -->
                            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-yellow-50 to-yellow-100">
                                <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                                    <div class="flex items-center">
                                        <i class="fas fa-exclamation-circle text-yellow-600 mr-2"></i>
                                        <span class="font-medium text-yellow-800">Validation requise pour
                                            <?php echo count($produits_attente); ?> produit(s)</span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <button onclick="validerTousProduits()"
                                            class="group bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 text-sm">
                                            <i class="fas fa-check-double mr-2 group-hover:scale-110 transition-transform"></i>
                                            Tout valider
                                        </button>
                                        <button
                                            class="p-2 border border-gray-300 rounded-lg hover:bg-white transition-colors text-sm">
                                            <i class="fas fa-filter text-gray-600"></i>
                                            Filtrer
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Tableau -->
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead>
                                        <tr class="bg-gradient-to-r from-gray-50 to-gray-100">
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-hashtag mr-2 text-gray-400"></i> ID
                                                </div>
                                            </th>
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-capsules mr-2 text-gray-400"></i> Produit
                                                </div>
                                            </th>
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-tag mr-2 text-gray-400"></i> Catégorie
                                                </div>
                                            </th>
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-calendar-plus mr-2 text-gray-400"></i> Date d'ajout
                                                </div>
                                            </th>
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-cogs mr-2 text-gray-400"></i> Actions
                                                </div>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($produits_attente as $produit): ?>
                                            <tr
                                                class="hover:bg-gradient-to-r hover:from-yellow-50 hover:to-transparent transition-all duration-200 group">
                                                <td class="px-6 py-4">
                                                    <div
                                                        class="text-sm font-medium text-gray-900 bg-yellow-50 px-3 py-1 rounded-lg inline-block border border-yellow-200">
                                                        #<?php echo str_pad($produit['id'], 4, '0', STR_PAD_LEFT); ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center">
                                                        <div
                                                            class="p-2 bg-gradient-to-br from-yellow-50 to-yellow-100 rounded-lg mr-3 border border-yellow-200">
                                                            <i class="fas fa-clock text-yellow-600 text-sm"></i>
                                                        </div>
                                                        <div>
                                                            <div
                                                                class="font-semibold text-gray-900 group-hover:text-yellow-700 transition-colors">
                                                                <?php echo htmlspecialchars($produit['nom']); ?>
                                                            </div>
                                                            <?php if ($produit['code_barre']): ?>
                                                                <div class="text-xs text-gray-500 mt-1">
                                                                    <i class="fas fa-barcode mr-1"></i>
                                                                    <?php echo htmlspecialchars($produit['code_barre']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center">
                                                        <span
                                                            class="px-3 py-1 rounded-full text-xs font-medium bg-gradient-to-r from-blue-50 to-blue-100 text-blue-800 border border-blue-200">
                                                            <?php echo htmlspecialchars($produit['categorie_nom']); ?>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center text-sm text-gray-900">
                                                        <i class="far fa-clock text-gray-400 mr-2"></i>
                                                        <span><?php echo formatDate($produit['created_at']); ?></span>
                                                        <?php
                                                        $daysDiff = floor((time() - strtotime($produit['created_at'])) / (60 * 60 * 24));
                                                        if ($daysDiff > 3):
                                                            ?>
                                                            <span
                                                                class="ml-2 px-2 py-0.5 bg-gradient-to-r from-red-50 to-red-100 text-red-700 text-xs rounded-full border border-red-200">
                                                                <i class="fas fa-exclamation-circle mr-1"></i>+<?php echo $daysDiff; ?>j
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center space-x-2">
                                                        <button onclick="validerProduit(<?php echo $produit['id']; ?>)"
                                                            class="group/validate relative bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 text-sm">
                                                            <i
                                                                class="fas fa-check mr-2 group-hover/validate:scale-110 transition-transform"></i>
                                                            Valider
                                                        </button>
                                                        <a href="?page=modifier_produit&id=<?php echo $produit['id']; ?>"
                                                            class="group/edit relative bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 text-sm">
                                                            <i
                                                                class="fas fa-edit mr-2 group-hover/edit:scale-110 transition-transform"></i>
                                                            Modifier
                                                        </a>
                                                        <a href="?page=produit_details&id=<?php echo $produit['id']; ?>"
                                                            class="p-2 bg-gradient-to-br from-gray-50 to-gray-100 hover:from-gray-100 hover:to-gray-200 text-gray-600 rounded-lg border border-gray-200 hover:shadow-sm transition-all duration-200 group/view"
                                                            title="Voir détails">
                                                            <i
                                                                class="fas fa-eye text-sm group-hover/view:scale-110 transition-transform"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Actions en masse -->
                            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                                <div class="flex items-center justify-between">
                                    <div class="text-sm text-gray-700">
                                        <i class="fas fa-info-circle mr-2 text-blue-500"></i>
                                        Sélectionnez les produits à valider
                                    </div>
                                    <div class="flex items-center space-x-3">
                                        <button onclick="validerSelectionProduits()"
                                            class="bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 text-sm">
                                            <i class="fas fa-check-circle mr-2"></i>
                                            Valider la sélection
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg p-12 text-center border border-gray-200">
                            <div
                                class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-r from-green-100 to-emerald-100 mb-6 shadow-sm">
                                <i class="fas fa-check-circle text-emerald-600 text-3xl"></i>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-800 mb-3">Aucun produit en attente</h3>
                            <p class="text-gray-600 mb-8 max-w-md mx-auto">
                                Tous les produits sont validés et disponibles pour la vente. Vous pouvez ajouter de nouveaux
                                produits si nécessaire.
                            </p>
                            <div class="flex flex-col sm:flex-row justify-center gap-4">
                                <a href="?page=ajouter_produit"
                                    class="group bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-6 py-3 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5">
                                    <i class="fas fa-plus mr-2 group-hover:rotate-90 transition-transform"></i>
                                    Ajouter un nouveau produit
                                </a>
                                <a href="?page=produits"
                                    class="group bg-gradient-to-r from-gray-100 to-gray-200 hover:from-gray-200 hover:to-gray-300 text-gray-700 px-6 py-3 rounded-xl font-medium shadow hover:shadow-md transition-all duration-200 border border-gray-300">
                                    <i class="fas fa-list mr-2 group-hover:scale-110 transition-transform"></i>
                                    Voir tous les produits
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($current_page == 'categories'): ?>
                <!-- ========== GESTION DES CATÉGORIES ========== -->
                <div class="mb-8">
                    <!-- En-tête avec statistiques -->
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-8">
                        <div class="flex items-center">
                            <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl shadow-sm mr-4">
                                <i class="fas fa-tags text-emerald-600 text-xl"></i>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold text-gray-800">Gestion des catégories</h1>
                                <p class="text-gray-600 mt-1">Organisez vos produits par catégories</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-4">
                            <div
                                class="text-center px-4 py-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl border border-blue-200">
                                <div class="text-2xl font-bold text-blue-700"><?php echo count($liste_categories); ?></div>
                                <div class="text-xs text-blue-600 font-medium">Catégories</div>
                            </div>
                            <button onclick="toggleAjoutCategorie()"
                                class="group relative bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-5 py-3 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5">
                                <i class="fas fa-plus mr-2 group-hover:rotate-90 transition-transform"></i>
                                Nouvelle catégorie
                                <div
                                    class="absolute inset-0 rounded-xl border border-emerald-400 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Formulaire d'ajout de catégorie (caché par défaut) -->
                    <div id="formAjoutCategorie"
                        class="hidden bg-gradient-to-br from-white to-emerald-50 rounded-xl shadow-lg p-8 mb-8 border border-emerald-200">
                        <div class="flex items-center mb-6">
                            <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-lg mr-3">
                                <i class="fas fa-plus-circle text-emerald-600"></i>
                            </div>
                            <h3 class="text-xl font-semibold text-gray-800">Ajouter une nouvelle catégorie</h3>
                        </div>

                        <form method="POST" action="" class="space-y-6">
                            <input type="hidden" name="action" value="ajouter_categorie">

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3" for="nom_categorie">
                                        <span class="text-red-500">*</span> Nom de la catégorie
                                    </label>
                                    <div class="relative">
                                        <input type="text" id="nom_categorie" name="nom_categorie" required
                                            class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow"
                                            placeholder="Ex: Antibiotiques, Antalgiques...">
                                        <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                            <i class="fas fa-tag"></i>
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-2">Nom unique pour identifier la catégorie</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3"
                                        for="description_categorie">
                                        Description
                                    </label>
                                    <div class="relative">
                                        <textarea id="description_categorie" name="description_categorie" rows="2"
                                            class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow resize-none"
                                            placeholder="Description de la catégorie..."></textarea>
                                        <div class="absolute right-3 bottom-3 text-gray-400">
                                            <i class="fas fa-align-left"></i>
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-2">Optionnel - Décrivez le type de produits</p>
                                </div>
                            </div>

                            <div
                                class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-4 pt-4 border-t border-gray-200">
                                <button type="button" onclick="toggleAjoutCategorie()"
                                    class="group px-6 py-3 border border-gray-300 rounded-xl text-gray-700 hover:bg-gray-50 hover:shadow transition-all duration-200 text-center font-medium">
                                    <i class="fas fa-times mr-2 group-hover:rotate-90 transition-transform"></i>
                                    Annuler
                                </button>
                                <button type="submit"
                                    class="group relative bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-8 py-3 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5">
                                    <i class="fas fa-save mr-2 group-hover:rotate-12 transition-transform"></i>
                                    Enregistrer la catégorie
                                    <div
                                        class="absolute inset-0 rounded-xl border border-emerald-400 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                    </div>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Liste des catégories -->
                    <div
                        class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg overflow-hidden border border-gray-200">
                        <!-- En-tête du tableau -->
                        <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100">
                            <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                                <div class="flex items-center">
                                    <i class="fas fa-layer-group text-emerald-600 mr-2"></i>
                                    <span class="font-medium text-gray-700">Liste des catégories disponibles</span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <div class="relative">
                                        <input type="text" placeholder="Rechercher une catégorie..."
                                            class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-transparent text-sm">
                                        <i
                                            class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                    </div>
                                    <button class="p-2 border border-gray-300 rounded-lg hover:bg-white transition-colors">
                                        <i class="fas fa-sliders-h text-gray-600"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Tableau des catégories -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr class="bg-gradient-to-r from-gray-50 to-gray-100">
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-hashtag mr-2 text-gray-400"></i> ID
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-tag mr-2 text-gray-400"></i> Catégorie
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-align-left mr-2 text-gray-400"></i> Description
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-capsules mr-2 text-gray-400"></i> Produits
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-circle mr-2 text-gray-400"></i> Statut
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-calendar-plus mr-2 text-gray-400"></i> Création
                                            </div>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($liste_categories as $categorie): ?>
                                        <tr
                                            class="hover:bg-gradient-to-r hover:from-emerald-50 hover:to-transparent transition-all duration-200 group">
                                            <td class="px-6 py-4">
                                                <div
                                                    class="text-sm font-medium text-gray-900 bg-gray-50 px-3 py-1 rounded-lg inline-block border border-gray-200">
                                                    #<?php echo str_pad((string) $categorie['id'], 3, '0', STR_PAD_LEFT); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center">
                                                    <div
                                                        class="p-2 bg-gradient-to-br from-emerald-50 to-emerald-100 rounded-lg mr-3 border border-emerald-200">
                                                        <i class="fas fa-folder text-emerald-600 text-sm"></i>
                                                    </div>
                                                    <div>
                                                        <div
                                                            class="font-semibold text-gray-900 group-hover:text-emerald-700 transition-colors">
                                                            <?php echo htmlspecialchars($categorie['nom']); ?>
                                                        </div>
                                                        <div class="text-xs text-gray-500 mt-1">
                                                            <i class="fas fa-key mr-1"></i>
                                                            ID:
                                                            CAT<?php echo str_pad((string) $categorie['id'], 3, '0', STR_PAD_LEFT); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm text-gray-600 max-w-xs">
                                                    <?php if (!empty($categorie['description'])): ?>
                                                        <?php echo htmlspecialchars($categorie['description']); ?>
                                                    <?php else: ?>
                                                        <span class="text-gray-400 italic">Aucune description</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center">
                                                    <div class="relative">
                                                        <div
                                                            class="text-2xl font-bold text-gray-800 <?php echo $categorie['nombre_produits'] > 0 ? 'text-emerald-700' : 'text-gray-400'; ?>">
                                                            <?php echo $categorie['nombre_produits']; ?>
                                                        </div>
                                                        <?php if ($categorie['nombre_produits'] > 0): ?>
                                                            <div
                                                                class="absolute -top-1 -right-1 w-2 h-2 bg-emerald-500 rounded-full">
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="text-xs text-gray-500 ml-2">produit(s)</span>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php if ($categorie['statut'] == 'actif'): ?>
                                                    <div
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-emerald-100 to-emerald-50 text-emerald-800 border border-emerald-200">
                                                        <div class="w-2 h-2 bg-emerald-500 rounded-full mr-2"></div>
                                                        Active
                                                    </div>
                                                <?php else: ?>
                                                    <div
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-red-100 to-red-50 text-red-800 border border-red-200">
                                                        <div class="w-2 h-2 bg-red-500 rounded-full mr-2"></div>
                                                        Inactive
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm text-gray-500">
                                                    <div class="flex items-center">
                                                        <i class="far fa-calendar text-gray-400 mr-2"></i>
                                                        <?php echo formatDate($categorie['created_at']); ?>
                                                    </div>
                                                    <?php
                                                    $daysDiff = floor((time() - strtotime($categorie['created_at'])) / (60 * 60 * 24));
                                                    if ($daysDiff < 7):
                                                        ?>
                                                        <div class="text-xs text-blue-600 mt-1">
                                                            <i class="fas fa-leaf mr-1"></i> Nouvelle
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Statistiques en bas -->
                        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="text-center">
                                    <div class="text-lg font-bold text-emerald-700">
                                        <?php echo array_sum(array_column($liste_categories, 'nombre_produits')); ?>
                                    </div>
                                    <div class="text-sm text-gray-600">Total produits</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-lg font-bold text-blue-700">
                                        <?php echo count(array_filter($liste_categories, function ($c) {
                                            return $c['statut'] == 'actif';
                                        })); ?>
                                    </div>
                                    <div class="text-sm text-gray-600">Catégories actives</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-lg font-bold text-gray-700">
                                        <?php
                                        $activeCats = array_filter($liste_categories, function ($c) {
                                            return $c['statut'] == 'actif' && $c['nombre_produits'] > 0;
                                        });
                                        echo count($activeCats);
                                        ?>
                                    </div>
                                    <div class="text-sm text-gray-600">Avec produits</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($current_page == 'fournisseurs'): ?>
                <!-- ========== LISTE DES FOURNISSEURS ========== -->
                <div class="mb-8">
                    <!-- En-tête avec statistiques -->
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-8">
                        <div class="flex items-center">
                            <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl shadow-sm mr-4">
                                <i class="fas fa-truck text-emerald-600 text-xl"></i>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold text-gray-800">Gestion des fournisseurs</h1>
                                <p class="text-gray-600 mt-1">Suivez et évaluez vos fournisseurs partenaires</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-4">
                            <div
                                class="text-center px-4 py-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl border border-blue-200">
                                <div class="text-2xl font-bold text-blue-700"><?php echo count($liste_fournisseurs); ?>
                                </div>
                                <div class="text-xs text-blue-600 font-medium">Fournisseurs</div>
                            </div>
                            <a href="?page=ajouter_fournisseur"
                                class="group relative bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-5 py-3 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5">
                                <i class="fas fa-plus mr-2 group-hover:rotate-90 transition-transform"></i>
                                Nouveau fournisseur
                                <div
                                    class="absolute inset-0 rounded-xl border border-emerald-400 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- Cartes de statistiques fournisseurs -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                        <?php
                        $totalProduits = array_sum(array_column($liste_fournisseurs, 'nombre_produits'));
                        $moyenneNote = $totalFournisseurs = 0;
                        if (count($liste_fournisseurs) > 0) {
                            $moyenneNote = array_sum(array_column($liste_fournisseurs, 'note_qualite')) / count($liste_fournisseurs);
                            $totalFournisseurs = count($liste_fournisseurs);
                        }
                        $excellents = count(array_filter($liste_fournisseurs, function ($f) {
                            return ($f['note_qualite'] ?? 0) >= 4;
                        }));
                        ?>

                        <div
                            class="bg-gradient-to-br from-white to-blue-50 rounded-xl shadow-lg p-6 border border-blue-200">
                            <div class="flex items-center">
                                <div class="p-3 bg-gradient-to-br from-blue-100 to-blue-200 rounded-lg mr-4">
                                    <i class="fas fa-capsules text-blue-600"></i>
                                </div>
                                <div>
                                    <p class="text-gray-600 text-sm font-medium">Total produits</p>
                                    <p class="text-2xl font-bold text-gray-800"><?php echo $totalProduits; ?></p>
                                </div>
                            </div>
                        </div>

                        <div
                            class="bg-gradient-to-br from-white to-yellow-50 rounded-xl shadow-lg p-6 border border-yellow-200">
                            <div class="flex items-center">
                                <div class="p-3 bg-gradient-to-br from-yellow-100 to-yellow-200 rounded-lg mr-4">
                                    <i class="fas fa-star text-yellow-600"></i>
                                </div>
                                <div>
                                    <p class="text-gray-600 text-sm font-medium">Note moyenne</p>
                                    <p class="text-2xl font-bold text-gray-800">
                                        <?php echo number_format($moyenneNote, 1); ?>/5
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div
                            class="bg-gradient-to-br from-white to-emerald-50 rounded-xl shadow-lg p-6 border border-emerald-200">
                            <div class="flex items-center">
                                <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-lg mr-4">
                                    <i class="fas fa-award text-emerald-600"></i>
                                </div>
                                <div>
                                    <p class="text-gray-600 text-sm font-medium">Fournisseurs excellents</p>
                                    <p class="text-2xl font-bold text-gray-800">
                                        <?php echo $excellents; ?>/<?php echo $totalFournisseurs; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Liste des fournisseurs -->
                    <div
                        class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg overflow-hidden border border-gray-200">
                        <!-- En-tête avec filtres -->
                        <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100">
                            <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                                <div class="flex items-center">
                                    <i class="fas fa-list text-emerald-600 mr-2"></i>
                                    <span class="font-medium text-gray-700">Liste des fournisseurs</span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <div class="relative">
                                        <input type="text" placeholder="Rechercher un fournisseur..."
                                            class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-transparent text-sm">
                                        <i
                                            class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                    </div>
                                    <button class="p-2 border border-gray-300 rounded-lg hover:bg-white transition-colors">
                                        <i class="fas fa-sliders-h text-gray-600"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Tableau des fournisseurs -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead>
                                    <tr class="bg-gradient-to-r from-gray-50 to-gray-100">
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-hashtag mr-2 text-gray-400"></i> ID
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-building mr-2 text-gray-400"></i> Société
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-user mr-2 text-gray-400"></i> Contact
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-boxes mr-2 text-gray-400"></i> Produits
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-star mr-2 text-gray-400"></i> Qualité
                                            </div>
                                        </th>
                                        <th
                                            class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                            <div class="flex items-center">
                                                <i class="fas fa-cogs mr-2 text-gray-400"></i> Actions
                                            </div>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($liste_fournisseurs as $fournisseur): ?>
                                        <tr
                                            class="hover:bg-gradient-to-r hover:from-emerald-50 hover:to-transparent transition-all duration-200 group">
                                            <td class="px-6 py-4">
                                                <div
                                                    class="text-sm font-medium text-gray-900 bg-gray-50 px-3 py-1 rounded-lg inline-block border border-gray-200">
                                                    #<?php echo str_pad((string) $fournisseur['id'], 3, '0', STR_PAD_LEFT); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center">
                                                    <div
                                                        class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg mr-3 border border-blue-200">
                                                        <i class="fas fa-warehouse text-blue-600 text-sm"></i>
                                                    </div>
                                                    <div>
                                                        <div
                                                            class="font-semibold text-gray-900 group-hover:text-blue-700 transition-colors">
                                                            <?php echo htmlspecialchars($fournisseur['nom_societe']); ?>
                                                        </div>
                                                        <?php if (!empty($fournisseur['email'])): ?>
                                                            <div class="text-xs text-gray-500 mt-1">
                                                                <i class="fas fa-envelope mr-1"></i>
                                                                <?php echo htmlspecialchars($fournisseur['email']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm text-gray-900">
                                                    <?php echo htmlspecialchars($fournisseur['contact_principal']); ?>
                                                </div>
                                                <?php if (!empty($fournisseur['telephone'])): ?>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        <i class="fas fa-phone mr-1"></i>
                                                        <?php echo htmlspecialchars($fournisseur['telephone']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center">
                                                    <div class="relative">
                                                        <div
                                                            class="text-2xl font-bold text-gray-800 <?php echo $fournisseur['nombre_produits'] > 0 ? 'text-emerald-700' : 'text-gray-400'; ?>">
                                                            <?php echo $fournisseur['nombre_produits']; ?>
                                                        </div>
                                                        <?php if ($fournisseur['nombre_produits'] > 0): ?>
                                                            <div
                                                                class="absolute -top-1 -right-1 w-2 h-2 bg-emerald-500 rounded-full">
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <span class="text-xs text-gray-500 ml-2">produit(s)</span>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php
                                                $note = $fournisseur['note_qualite'] ?? 0;
                                                if ($note >= 4): ?>
                                                    <div
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-emerald-100 to-emerald-50 text-emerald-800 border border-emerald-200">
                                                        <i class="fas fa-star text-emerald-500 mr-1 text-xs"></i>
                                                        <?php echo number_format($note, 1); ?>/5
                                                    </div>
                                                <?php elseif ($note >= 2.5): ?>
                                                    <div
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-yellow-100 to-yellow-50 text-yellow-800 border border-yellow-200">
                                                        <i class="fas fa-star text-yellow-500 mr-1 text-xs"></i>
                                                        <?php echo number_format($note, 1); ?>/5
                                                    </div>
                                                <?php else: ?>
                                                    <div
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-red-100 to-red-50 text-red-800 border border-red-200">
                                                        <i class="fas fa-star text-red-500 mr-1 text-xs"></i>
                                                        <?php echo number_format($note, 1); ?>/5
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Étoiles de notation -->
                                                <div class="flex items-center mt-1">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i
                                                            class="fas fa-star <?php echo $i <= $note ? 'text-yellow-500' : 'text-gray-300'; ?> text-xs"></i>
                                                    <?php endfor; ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center space-x-2">
                                                    <button onclick="modifierFournisseur(<?php echo $fournisseur['id']; ?>)"
                                                        class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 hover:from-blue-100 hover:to-blue-200 text-blue-600 rounded-lg border border-blue-200 hover:shadow-sm transition-all duration-200 group/edit"
                                                        title="Modifier">
                                                        <i
                                                            class="fas fa-edit text-sm group-hover/edit:scale-110 transition-transform"></i>
                                                    </button>

                                                    <button onclick="noterFournisseur(<?php echo $fournisseur['id']; ?>)"
                                                        class="p-2 bg-gradient-to-br from-yellow-50 to-yellow-100 hover:from-yellow-100 hover:to-yellow-200 text-yellow-600 rounded-lg border border-yellow-200 hover:shadow-sm transition-all duration-200 group/rate"
                                                        title="Noter">
                                                        <i
                                                            class="fas fa-star text-sm group-hover/rate:scale-110 transition-transform"></i>
                                                    </button>

                                                    <a href="?page=fournisseur_details&id=<?php echo $fournisseur['id']; ?>"
                                                        class="p-2 bg-gradient-to-br from-gray-50 to-gray-100 hover:from-gray-100 hover:to-gray-200 text-gray-600 rounded-lg border border-gray-200 hover:shadow-sm transition-all duration-200 group/view"
                                                        title="Voir détails">
                                                        <i
                                                            class="fas fa-eye text-sm group-hover/view:scale-110 transition-transform"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Statistiques en bas -->
                        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div class="text-center">
                                    <div class="text-lg font-bold text-blue-700"><?php echo count($liste_fournisseurs); ?>
                                    </div>
                                    <div class="text-sm text-gray-600">Total fournisseurs</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-lg font-bold text-emerald-700"><?php echo $excellents; ?></div>
                                    <div class="text-sm text-gray-600">Excellents (≥4/5)</div>
                                </div>
                                <div class="text-center">
                                    <?php
                                    $moyennes = count(array_filter($liste_fournisseurs, function ($f) {
                                        $note = $f['note_qualite'] ?? 0;
                                        return $note >= 2.5 && $note < 4;
                                    }));
                                    ?>
                                    <div class="text-lg font-bold text-yellow-700"><?php echo $moyennes; ?></div>
                                    <div class="text-sm text-gray-600">Moyens (≥2.5)</div>
                                </div>
                                <div class="text-center">
                                    <?php
                                    $faibles = count(array_filter($liste_fournisseurs, function ($f) {
                                        return ($f['note_qualite'] ?? 0) < 2.5;
                                    }));
                                    ?>
                                    <div class="text-lg font-bold text-red-700"><?php echo $faibles; ?></div>
                                    <div class="text-sm text-gray-600">À améliorer</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($current_page == 'ajouter_fournisseur'): ?>
                <!-- ========== AJOUTER UN FOURNISSEUR ========== -->
                <div class="mb-8">
                    <div class="flex items-center mb-6">
                        <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl shadow-sm mr-4">
                            <i class="fas fa-user-plus text-emerald-600 text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Ajouter un nouveau fournisseur</h1>
                            <p class="text-gray-600 mt-1">Remplissez les informations du fournisseur partenaire</p>
                        </div>
                    </div>

                    <div
                        class="bg-gradient-to-br from-white to-emerald-50 rounded-xl shadow-lg p-8 border border-emerald-200">
                        <form method="POST" action="" class="space-y-8">
                            <input type="hidden" name="action" value="ajouter_fournisseur">

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                <!-- Informations principales -->
                                <div class="space-y-6">
                                    <div class="flex items-center pb-3 border-b border-gray-200">
                                        <div class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg mr-3">
                                            <i class="fas fa-building text-blue-600"></i>
                                        </div>
                                        <h3 class="text-lg font-semibold text-gray-800">Informations principales</h3>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3" for="nom_societe">
                                            <span class="text-red-500">*</span> Nom de la société
                                        </label>
                                        <div class="relative">
                                            <input type="text" id="nom_societe" name="nom_societe" required
                                                class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow"
                                                placeholder="Nom de l'entreprise">
                                            <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                <i class="fas fa-building"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3"
                                            for="contact_principal">
                                            <span class="text-red-500">*</span> Contact principal
                                        </label>
                                        <div class="relative">
                                            <input type="text" id="contact_principal" name="contact_principal" required
                                                class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow"
                                                placeholder="Nom du responsable">
                                            <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                <i class="fas fa-user"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3" for="email">
                                            Adresse email
                                        </label>
                                        <div class="relative">
                                            <input type="email" id="email" name="email"
                                                class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow"
                                                placeholder="contact@entreprise.com">
                                            <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                <i class="fas fa-envelope"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Informations complémentaires -->
                                <div class="space-y-6">
                                    <div class="flex items-center pb-3 border-b border-gray-200">
                                        <div class="p-2 bg-gradient-to-br from-yellow-50 to-yellow-100 rounded-lg mr-3">
                                            <i class="fas fa-star text-yellow-600"></i>
                                        </div>
                                        <h3 class="text-lg font-semibold text-gray-800">Évaluation & Contact</h3>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3" for="note_qualite">
                                            Note de qualité
                                        </label>
                                        <div class="relative">
                                            <input type="number" id="note_qualite" name="note_qualite" min="0" max="5"
                                                step="0.1" value="3.0"
                                                class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow">
                                            <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                <i class="fas fa-star"></i>
                                            </div>
                                            <div class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                                <span class="text-xs text-gray-500">/5</span>
                                            </div>
                                        </div>
                                        <div class="flex items-center mt-2">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star text-gray-300 text-sm mr-1 star-rating"
                                                    data-value="<?php echo $i; ?>"></i>
                                            <?php endfor; ?>
                                            <span class="text-xs text-gray-500 ml-2">(Cliquez pour noter)</span>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3" for="telephone">
                                            Téléphone
                                        </label>
                                        <div class="relative">
                                            <input type="tel" id="telephone" name="telephone"
                                                class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow"
                                                placeholder="+33 1 23 45 67 89">
                                            <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                <i class="fas fa-phone"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3" for="adresse_siege">
                                            Adresse du siège
                                        </label>
                                        <div class="relative">
                                            <textarea id="adresse_siege" name="adresse_siege" rows="4"
                                                class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow resize-none"
                                                placeholder="Adresse complète..."></textarea>
                                            <div class="absolute right-3 bottom-3 text-gray-400">
                                                <i class="fas fa-map-marker-alt"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Boutons d'action -->
                            <div class="pt-6 border-t border-gray-200">
                                <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-4">
                                    <a href="?page=fournisseurs"
                                        class="group px-6 py-3 border border-gray-300 rounded-xl text-gray-700 hover:bg-gray-50 hover:shadow transition-all duration-200 text-center font-medium">
                                        <i class="fas fa-times mr-2 group-hover:rotate-90 transition-transform"></i>
                                        Annuler
                                    </a>
                                    <button type="submit"
                                        class="group relative bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-8 py-3 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5">
                                        <i class="fas fa-save mr-2 group-hover:rotate-12 transition-transform"></i>
                                        Enregistrer le fournisseur
                                        <div
                                            class="absolute inset-0 rounded-xl border border-emerald-400 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                        </div>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($current_page == 'modifier_produit' && isset($_GET['id'])): ?>
                <!-- ========== MODIFIER UN PRODUIT ========== -->
                <?php
                $produit_id = intval($_GET['id']);
                $stmt = $pdo->prepare("
        SELECT p.*, c.nom as categorie_nom, f.nom_societe as fournisseur_nom
        FROM produits p
        LEFT JOIN categories c ON p.categorie_id = c.id
        LEFT JOIN fournisseurs f ON p.fournisseur_id = f.id
        WHERE p.id = :id
    ");
                $stmt->execute([':id' => $produit_id]);
                $produit = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($produit):
                    ?>
                    <div class="mb-8">
                        <div class="flex items-center justify-between mb-8">
                            <div class="flex items-center">
                                <div class="p-3 bg-gradient-to-br from-blue-100 to-blue-200 rounded-xl shadow-sm mr-4">
                                    <i class="fas fa-edit text-blue-600 text-xl"></i>
                                </div>
                                <div>
                                    <h1 class="text-2xl font-bold text-gray-800">Modifier le produit</h1>
                                    <p class="text-gray-600 mt-1">Mettez à jour les informations du produit</p>
                                </div>
                            </div>
                            <div class="flex items-center space-x-3">
                                <span
                                    class="px-3 py-1 bg-gradient-to-r from-blue-50 to-blue-100 text-blue-800 rounded-full text-sm font-semibold border border-blue-200">
                                    <i class="fas fa-hashtag mr-1"></i>
                                    ID: <?php echo str_pad((string) $produit['id'], 4, '0', STR_PAD_LEFT); ?>
                                </span>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-white to-blue-50 rounded-xl shadow-lg p-8 border border-blue-200">
                            <form method="POST" action="" class="space-y-8">
                                <input type="hidden" name="action" value="modifier_produit">
                                <input type="hidden" name="produit_id" value="<?php echo $produit['id']; ?>">

                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                    <!-- Informations de base -->
                                    <div class="space-y-6">
                                        <div class="flex items-center pb-3 border-b border-gray-200">
                                            <div class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg mr-3">
                                                <i class="fas fa-info-circle text-blue-600"></i>
                                            </div>
                                            <h3 class="text-lg font-semibold text-gray-800">Informations de base</h3>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-3" for="nom">
                                                <span class="text-red-500">*</span> Nom du produit
                                            </label>
                                            <div class="relative">
                                                <input type="text" id="nom" name="nom" required
                                                    value="<?php echo htmlspecialchars($produit['nom']); ?>"
                                                    class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow">
                                                <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                    <i class="fas fa-tag"></i>
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-3" for="code_barre">
                                                Code barre
                                            </label>
                                            <div class="relative">
                                                <input type="text" id="code_barre" name="code_barre"
                                                    value="<?php echo htmlspecialchars($produit['code_barre']); ?>"
                                                    class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow">
                                                <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                    <i class="fas fa-barcode"></i>
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-3" for="categorie_id">
                                                <span class="text-red-500">*</span> Catégorie
                                            </label>
                                            <div class="relative">
                                                <select id="categorie_id" name="categorie_id" required
                                                    class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow appearance-none bg-white">
                                                    <?php foreach ($categories as $categorie): ?>
                                                        <option value="<?php echo $categorie['id']; ?>" <?php echo $categorie['id'] == $produit['categorie_id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($categorie['nom']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                    <i class="fas fa-folder"></i>
                                                </div>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                    <i class="fas fa-chevron-down"></i>
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-3" for="fournisseur_id">
                                                <span class="text-red-500">*</span> Fournisseur
                                            </label>
                                            <div class="relative">
                                                <select id="fournisseur_id" name="fournisseur_id" required
                                                    class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow appearance-none bg-white">
                                                    <?php foreach ($fournisseurs as $fournisseur): ?>
                                                        <option value="<?php echo $fournisseur['id']; ?>" <?php echo $fournisseur['id'] == $produit['fournisseur_id'] ? 'selected' : ''; ?>>
                                                            <span
                                                                class="font-medium"><?php echo htmlspecialchars($fournisseur['nom_societe']); ?></span>
                                                            <?php if (isset($fournisseur['note_qualite'])): ?>
                                                                <span class="text-gray-500 ml-2">
                                                                    (<?php echo number_format($fournisseur['note_qualite'], 1); ?>/5)
                                                                </span>
                                                            <?php endif; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                    <i class="fas fa-truck"></i>
                                                </div>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                    <i class="fas fa-chevron-down"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Informations médicales -->
                                    <div class="space-y-6">
                                        <div class="flex items-center pb-3 border-b border-gray-200">
                                            <div class="p-2 bg-gradient-to-br from-red-50 to-red-100 rounded-lg mr-3">
                                                <i class="fas fa-stethoscope text-red-600"></i>
                                            </div>
                                            <h3 class="text-lg font-semibold text-gray-800">Informations médicales</h3>
                                        </div>

                                        <div
                                            class="bg-gradient-to-r from-purple-50 to-purple-100 border border-purple-200 rounded-xl p-4">
                                            <label class="flex items-center space-x-3 cursor-pointer group">
                                                <div class="relative">
                                                    <input type="checkbox" name="necessite_ordonnance" value="1" <?php echo $produit['necessite_ordonnance'] ? 'checked' : ''; ?>
                                                        class="sr-only peer">
                                                    <div
                                                        class="w-10 h-5 bg-gray-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-purple-600">
                                                    </div>
                                                </div>
                                                <div class="flex-1">
                                                    <span
                                                        class="font-semibold text-gray-700 group-hover:text-purple-700 transition-colors">Nécessite
                                                        une ordonnance</span>
                                                    <p class="text-xs text-gray-500 mt-1">Produit soumis à prescription médicale
                                                    </p>
                                                </div>
                                                <i class="fas fa-file-prescription text-purple-500 text-lg"></i>
                                            </label>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-3" for="composition">
                                                Composition
                                            </label>
                                            <div class="relative">
                                                <textarea id="composition" name="composition" rows="3"
                                                    class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow resize-none"><?php echo htmlspecialchars($produit['composition']); ?></textarea>
                                                <div class="absolute right-3 bottom-3 text-gray-400">
                                                    <i class="fas fa-flask"></i>
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 mb-3" for="posologie">
                                                Posologie
                                            </label>
                                            <div class="relative">
                                                <textarea id="posologie" name="posologie" rows="3"
                                                    class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow resize-none"><?php echo htmlspecialchars($produit['posologie']); ?></textarea>
                                                <div class="absolute right-3 bottom-3 text-gray-400">
                                                    <i class="fas fa-capsules"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Description complète -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3" for="description">
                                        <span class="text-red-500">*</span> Description complète
                                    </label>
                                    <div class="relative">
                                        <textarea id="description" name="description" rows="4" required
                                            class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow resize-none"><?php echo htmlspecialchars($produit['description']); ?></textarea>
                                        <div class="absolute right-3 bottom-3 text-gray-400">
                                            <i class="fas fa-align-left"></i>
                                        </div>
                                    </div>
                                </div>

                                <!-- Contre-indications -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-3" for="contre_indications">
                                        Contre-indications
                                    </label>
                                    <div class="relative">
                                        <textarea id="contre_indications" name="contre_indications" rows="3"
                                            class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow resize-none"><?php echo htmlspecialchars($produit['contre_indications']); ?></textarea>
                                        <div class="absolute right-3 bottom-3 text-gray-400">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </div>
                                    </div>
                                </div>

                                <!-- Boutons d'action -->
                                <div class="pt-6 border-t border-gray-200">
                                    <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-4">
                                        <a href="?page=produits"
                                            class="group px-6 py-3 border border-gray-300 rounded-xl text-gray-700 hover:bg-gray-50 hover:shadow transition-all duration-200 text-center font-medium">
                                            <i class="fas fa-times mr-2 group-hover:rotate-90 transition-transform"></i>
                                            Annuler
                                        </a>
                                        <button type="submit"
                                            class="group relative bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-8 py-3 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5">
                                            <i class="fas fa-save mr-2 group-hover:rotate-12 transition-transform"></i>
                                            Mettre à jour le produit
                                            <div
                                                class="absolute inset-0 rounded-xl border border-blue-400 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                            </div>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Produit non trouvé -->
                    <div class="flex items-center justify-center min-h-[60vh]">
                        <div
                            class="bg-gradient-to-br from-white to-yellow-50 rounded-2xl shadow-lg p-12 text-center border border-yellow-200 max-w-lg">
                            <div
                                class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-r from-yellow-100 to-yellow-200 mb-6 shadow-sm">
                                <i class="fas fa-exclamation-triangle text-yellow-600 text-3xl"></i>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-800 mb-3">Produit non trouvé</h3>
                            <p class="text-gray-600 mb-8">
                                Le produit que vous cherchez n'existe pas ou a été supprimé.
                            </p>
                            <div class="flex flex-col sm:flex-row justify-center gap-4">
                                <a href="?page=produits"
                                    class="group bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-6 py-3 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5">
                                    <i class="fas fa-arrow-left mr-2 group-hover:-translate-x-1 transition-transform"></i>
                                    Retour aux produits
                                </a>
                                <a href="?page=dashboard"
                                    class="group bg-gradient-to-r from-gray-100 to-gray-200 hover:from-gray-200 hover:to-gray-300 text-gray-700 px-6 py-3 rounded-xl font-medium shadow hover:shadow-md transition-all duration-200 border border-gray-300">
                                    <i class="fas fa-home mr-2 group-hover:scale-110 transition-transform"></i>
                                    Tableau de bord
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- ========== PAGE NON TROUVÉE ========== -->
                <div class="flex items-center justify-center min-h-[70vh]">
                    <div
                        class="bg-gradient-to-br from-white to-red-50 rounded-2xl shadow-lg p-16 text-center border border-red-200 max-w-2xl">
                        <div
                            class="inline-flex items-center justify-center w-24 h-24 rounded-full bg-gradient-to-r from-red-100 to-red-200 mb-8 shadow-sm">
                            <i class="fas fa-exclamation-circle text-red-600 text-4xl"></i>
                        </div>
                        <h3 class="text-3xl font-bold text-gray-800 mb-4">Page non trouvée</h3>
                        <p class="text-gray-600 text-lg mb-10">
                            La page que vous cherchez n'existe pas ou a été déplacée.
                        </p>
                        <div class="flex flex-col sm:flex-row justify-center gap-6">
                            <a href="?page=dashboard"
                                class="group bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-8 py-4 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5 text-lg">
                                <i class="fas fa-home mr-3 group-hover:scale-110 transition-transform"></i>
                                Retour au tableau de bord
                            </a>
                            <a href="?page=produits"
                                class="group bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-8 py-4 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5 text-lg">
                                <i class="fas fa-capsules mr-3 group-hover:scale-110 transition-transform"></i>
                                Voir les produits
                            </a>
                        </div>
                        <div class="mt-10 pt-6 border-t border-gray-200">
                            <p class="text-gray-500 text-sm">
                                Si vous pensez qu'il s'agit d'une erreur, contactez l'administrateur.
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Scripts JavaScript -->
    <script>
        // Fonction pour valider un produit
        function validerProduit(id) {
            if (confirm('Êtes-vous sûr de vouloir valider ce produit pour la vente ?')) {
                let form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                let inputAction = document.createElement('input');
                inputAction.type = 'hidden';
                inputAction.name = 'action';
                inputAction.value = 'valider_produit';
                form.appendChild(inputAction);

                let inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'produit_id';
                inputId.value = id;
                form.appendChild(inputId);

                document.body.appendChild(form);
                form.submit();
            }
        }

        // Fonction pour archiver un produit
        function archiverProduit(id) {
            if (confirm('Êtes-vous sûr de vouloir archiver ce produit ?\n\nIl ne sera plus disponible pour la vente.')) {
                let form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                let inputAction = document.createElement('input');
                inputAction.type = 'hidden';
                inputAction.name = 'action';
                inputAction.value = 'archiver_produit';
                form.appendChild(inputAction);

                let inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'produit_id';
                inputId.value = id;
                form.appendChild(inputId);

                document.body.appendChild(form);
                form.submit();
            }
        }

        // Fonction pour afficher/cacher le formulaire d'ajout de catégorie
        function toggleAjoutCategorie() {
            let form = document.getElementById('formAjoutCategorie');
            form.classList.toggle('hidden');
        }

        // Fonction pour modifier un fournisseur
        function modifierFournisseur(id) {
            alert('Fonctionnalité de modification du fournisseur #' + id + ' à implémenter');
        }

        // Fonction pour noter un fournisseur
        function noterFournisseur(id) {
            let note = prompt('Donnez une note de qualité (0-5) pour ce fournisseur:');
            if (note !== null && note >= 0 && note <= 5) {
                // Ici, vous pourriez faire une requête AJAX pour mettre à jour la note
                alert('Note enregistrée: ' + note + '/5\n\nCette fonctionnalité sera implémentée avec AJAX.');
            }
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