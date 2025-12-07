<?php
declare(strict_types=1);
// ============================================
// MODULE CAISSIER - Système NAGEX Pharma
// ============================================
// Fichier : caissier_dashboard.php
// Description : Interface complète du caissier
// ============================================

// Démarrage de session et vérification du rôle
session_start();

// Vérifier si l'utilisateur est connecté et a le rôle caissier
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'caissier') {
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

// ============================================
// FONCTIONS UTILITAIRES
// ============================================

/**
 * Récupère les statistiques du dashboard caissier
 */
function getDashboardStats(PDO $pdo, int $caissier_id): array
{
    $stats = [];

    // Commandes en attente
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM commandes WHERE statut = 'en_attente'");
    $stmt->execute();
    $stats['commandes_attente'] = $stmt->fetch()['total'] ?? 0;

    // Transactions aujourd'hui
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total, COALESCE(SUM(montant_total), 0) as chiffre_affaires 
        FROM commandes 
        WHERE caissier_id = :caissier_id 
        AND DATE(date_commande) = CURDATE()
        AND statut = 'paye'
    ");
    $stmt->execute([':caissier_id' => $caissier_id]);
    $row = $stmt->fetch();
    $stats['transactions_aujourdhui'] = $row['total'] ?? 0;
    $stats['chiffre_affaires'] = $row['chiffre_affaires'] ?? 0;

    // Produits à gérer les prix
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM produits p
        LEFT JOIN prix_vente pv ON p.id = pv.produit_id AND pv.date_fin IS NULL
        WHERE p.statut = 'actif' AND pv.id IS NULL
    ");
    $stats['produits_sans_prix'] = $stmt->fetch()['total'] ?? 0;

    // Retours à traiter
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM commandes WHERE statut = 'rembourse'");
    $stats['retours_a_traiter'] = $stmt->fetch()['total'] ?? 0;

    return $stats;
}

/**
 * Formate le montant avec devise
 */
function formatMontant($montant, string $devise = 'CDF'): string
{
    // Convertir en float si c'est une string
    $montantFloat = is_string($montant) ? (float) $montant : $montant;

    if ($devise === 'USD') {
        return '$' . number_format($montantFloat, 2, '.', ',');
    }
    return number_format($montantFloat, 0, '.', ',') . ' FC';
}

/**
 * Échapper les données pour l'affichage HTML
 */
function e(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// ============================================
// GESTION DES TRANSACTIONS (Traitement POST)
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {

            // DÉFINIR LE PRIX D'UN PRODUIT
            case 'definir_prix':
                try {
                    $pdo->beginTransaction();

                    // Désactiver les anciens prix
                    $stmt = $pdo->prepare("
                        UPDATE prix_vente 
                        SET date_fin = CURDATE() 
                        WHERE produit_id = :produit_id AND date_fin IS NULL
                    ");
                    $stmt->execute([':produit_id' => intval($_POST['produit_id'] ?? 0)]);

                    // Ajouter le nouveau prix
                    $stmt = $pdo->prepare("
                        INSERT INTO prix_vente (
                            produit_id, prix_fc, prix_usd, taux_conversion, 
                            date_debut, created_by
                        ) VALUES (
                            :produit_id, :prix_fc, :prix_usd, :taux_conversion,
                            CURDATE(), :created_by
                        )
                    ");

                    $stmt->execute([
                        ':produit_id' => intval($_POST['produit_id'] ?? 0),
                        ':prix_fc' => floatval($_POST['prix_fc'] ?? 0),
                        ':prix_usd' => floatval($_POST['prix_usd'] ?? 0),
                        ':taux_conversion' => floatval($_POST['taux_conversion'] ?? 1),
                        ':created_by' => $_SESSION['user_id']
                    ]);

                    $pdo->commit();
                    $message = "✅ Prix défini avec succès!";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "❌ Erreur lors de la définition du prix: " . $e->getMessage();
                }
                break;

            // VALIDER UN PAIEMENT
            case 'valider_paiement':
                try {
                    $pdo->beginTransaction();

                    // Mettre à jour le statut de la commande
                    $stmt = $pdo->prepare("
                        UPDATE commandes 
                        SET statut = 'paye', 
                            mode_paiement = :mode_paiement,
                            date_paiement = NOW(),
                            caissier_id = :caissier_id
                        WHERE id = :commande_id AND statut = 'en_attente'
                    ");

                    $stmt->execute([
                        ':mode_paiement' => $_POST['mode_paiement'] ?? 'especes',
                        ':caissier_id' => $_SESSION['user_id'],
                        ':commande_id' => intval($_POST['commande_id'] ?? 0)
                    ]);

                    if ($stmt->rowCount() > 0) {
                        // Mettre à jour les stocks
                        $stmt = $pdo->prepare("
                            UPDATE lots l
                            JOIN commande_details cd ON l.id = cd.lot_id
                            SET l.quantite_actuelle = l.quantite_actuelle - cd.quantite,
                                l.statut = CASE 
                                    WHEN (l.quantite_actuelle - cd.quantite) <= 0 THEN 'epuise'
                                    ELSE l.statut
                                END
                            WHERE cd.commande_id = :commande_id
                        ");
                        $stmt->execute([':commande_id' => intval($_POST['commande_id'] ?? 0)]);

                        // Enregistrer les mouvements de stock
                        $stmt = $pdo->prepare("
                            INSERT INTO mouvements_stock (
                                produit_id, lot_id, type_mouvement, quantite,
                                quantite_avant, quantite_apres, raison, created_by
                            )
                            SELECT 
                                cd.produit_id,
                                cd.lot_id,
                                'sortie',
                                cd.quantite,
                                l.quantite_actuelle + cd.quantite,
                                l.quantite_actuelle,
                                'Vente commande #' || :commande_id,
                                :created_by
                            FROM commande_details cd
                            JOIN lots l ON cd.lot_id = l.id
                            WHERE cd.commande_id = :commande_id2
                        ");
                        $stmt->execute([
                            ':commande_id' => intval($_POST['commande_id'] ?? 0),
                            ':created_by' => $_SESSION['user_id'],
                            ':commande_id2' => intval($_POST['commande_id'] ?? 0)
                        ]);

                        $pdo->commit();
                        $message = "✅ Paiement validé avec succès!";
                    } else {
                        $pdo->rollBack();
                        $error = "❌ La commande ne peut pas être payée (déjà payée ou non trouvée)";
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "❌ Erreur lors de la validation du paiement: " . $e->getMessage();
                }
                break;

            // APPLIQUER UNE PROMOTION
            case 'appliquer_promotion':
                try {
                    $pdo->beginTransaction();

                    // Enregistrer la promotion
                    $stmt = $pdo->prepare("
                        INSERT INTO promotions (
                            produit_id, type_promotion, valeur, date_debut, 
                            date_fin, created_by
                        ) VALUES (
                            :produit_id, :type_promotion, :valeur, 
                            :date_debut, :date_fin, :created_by
                        )
                    ");

                    $stmt->execute([
                        ':produit_id' => intval($_POST['produit_id'] ?? 0),
                        ':type_promotion' => $_POST['type_promotion'] ?? 'pourcentage',
                        ':valeur' => floatval($_POST['valeur'] ?? 0),
                        ':date_debut' => $_POST['date_debut'] ?? date('Y-m-d'),
                        ':date_fin' => $_POST['date_fin'] ?? null,
                        ':created_by' => $_SESSION['user_id']
                    ]);

                    $pdo->commit();
                    $message = "✅ Promotion appliquée avec succès!";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "❌ Erreur lors de l'application de la promotion: " . $e->getMessage();
                }
                break;

            // GÉRER UN RETOUR
            case 'gerer_retour':
                try {
                    $pdo->beginTransaction();

                    // Mettre à jour le statut de la commande
                    $stmt = $pdo->prepare("
                        UPDATE commandes 
                        SET statut = 'rembourse'
                        WHERE id = :commande_id AND statut = 'paye'
                    ");
                    $stmt->execute([':commande_id' => intval($_POST['commande_id'] ?? 0)]);

                    if ($stmt->rowCount() > 0) {
                        // Restaurer les stocks
                        $stmt = $pdo->prepare("
                            UPDATE lots l
                            JOIN commande_details cd ON l.id = cd.lot_id
                            SET l.quantite_actuelle = l.quantite_actuelle + cd.quantite,
                                l.statut = CASE 
                                    WHEN l.quantite_actuelle > 0 THEN 'en_stock'
                                    ELSE l.statut
                                END
                            WHERE cd.commande_id = :commande_id
                        ");
                        $stmt->execute([':commande_id' => intval($_POST['commande_id'] ?? 0)]);

                        // Enregistrer le mouvement de stock
                        $stmt = $pdo->prepare("
                            INSERT INTO mouvements_stock (
                                produit_id, lot_id, type_mouvement, quantite,
                                quantite_avant, quantite_apres, raison, created_by
                            )
                            SELECT 
                                cd.produit_id,
                                cd.lot_id,
                                'entree',
                                cd.quantite,
                                l.quantite_actuelle - cd.quantite,
                                l.quantite_actuelle,
                                'Retour commande #' || :commande_id,
                                :created_by
                            FROM commande_details cd
                            JOIN lots l ON cd.lot_id = l.id
                            WHERE cd.commande_id = :commande_id2
                        ");
                        $stmt->execute([
                            ':commande_id' => intval($_POST['commande_id'] ?? 0),
                            ':created_by' => $_SESSION['user_id'],
                            ':commande_id2' => intval($_POST['commande_id'] ?? 0)
                        ]);

                        $pdo->commit();
                        $message = "✅ Retour traité avec succès!";
                    } else {
                        $pdo->rollBack();
                        $error = "❌ Le retour ne peut pas être traité (commande non payée ou non trouvée)";
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "❌ Erreur lors du traitement du retour: " . $e->getMessage();
                }
                break;

            // AJUSTER LE TAUX DE CONVERSION
            case 'ajuster_taux_conversion':
                try {
                    // Désactiver les anciens taux
                    $stmt = $pdo->prepare("
                        UPDATE taux_conversion 
                        SET actif = 0 
                        WHERE actif = 1
                    ");
                    $stmt->execute();

                    // Ajouter le nouveau taux
                    $stmt = $pdo->prepare("
                        INSERT INTO taux_conversion (taux, created_by)
                        VALUES (:taux, :created_by)
                    ");

                    $stmt->execute([
                        ':taux' => floatval($_POST['taux'] ?? 1),
                        ':created_by' => $_SESSION['user_id']
                    ]);

                    $message = "✅ Taux de conversion mis à jour avec succès!";

                } catch (Exception $e) {
                    $error = "❌ Erreur lors de la mise à jour du taux: " . $e->getMessage();
                }
                break;
        }
    }
}

// ============================================
// RÉCUPÉRATION DES DONNÉES
// ============================================

// Récupérer les statistiques
$stats = getDashboardStats($pdo, $_SESSION['user_id']);

// Récupérer les produits actifs
$produits = [];
try {
    $stmt = $pdo->query("
        SELECT p.id, p.nom, p.code_barre, c.nom as categorie_nom,
               pv.prix_fc, pv.prix_usd, pv.taux_conversion
        FROM produits p
        LEFT JOIN categories c ON p.categorie_id = c.id
        LEFT JOIN prix_vente pv ON p.id = pv.produit_id AND pv.date_fin IS NULL
        WHERE p.statut = 'actif'
        ORDER BY p.nom
        LIMIT 100
    ");
    $produits = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Erreur lors du chargement des produits: " . $e->getMessage();
}

// Récupérer les commandes selon la page
$commandes_attente = [];
$transactions_recentes = [];
$liste_promotions = [];
$retours_en_attente = [];

switch ($current_page) {
    case 'commandes_attente':
        try {
            $stmt = $pdo->prepare("
                SELECT c.*, u.nom as client_nom, u.telephone,
                       COUNT(cd.id) as nombre_produits
                FROM commandes c
                JOIN utilisateurs u ON c.client_id = u.id
                LEFT JOIN commande_details cd ON c.id = cd.commande_id
                WHERE c.statut = 'en_attente'
                GROUP BY c.id
                ORDER BY c.date_commande ASC
                LIMIT 50
            ");
            $stmt->execute();
            $commandes_attente = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des commandes: " . $e->getMessage();
        }
        break;

    case 'transactions':
        try {
            $stmt = $pdo->prepare("
                SELECT c.*, u.nom as client_nom,
                       COUNT(cd.id) as nombre_produits
                FROM commandes c
                JOIN utilisateurs u ON c.client_id = u.id
                LEFT JOIN commande_details cd ON c.id = cd.commande_id
                WHERE c.caissier_id = :caissier_id 
                AND c.statut = 'paye'
                GROUP BY c.id
                ORDER BY c.date_paiement DESC
                LIMIT 30
            ");
            $stmt->execute([':caissier_id' => $_SESSION['user_id']]);
            $transactions_recentes = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des transactions: " . $e->getMessage();
        }
        break;

    case 'promotions':
        try {
            $stmt = $pdo->query("
                SELECT p.*, pr.nom as produit_nom,
                       pr.prix_fc as prix_original_fc,
                       CASE 
                           WHEN p.type_promotion = 'pourcentage' 
                           THEN pr.prix_fc * (1 - p.valeur/100)
                           ELSE pr.prix_fc - p.valeur
                       END as prix_promotion_fc
                FROM promotions p
                JOIN produits pr ON p.produit_id = pr.id
                WHERE (p.date_fin IS NULL OR p.date_fin >= CURDATE())
                AND p.date_debut <= CURDATE()
                ORDER BY p.date_debut DESC
            ");
            $liste_promotions = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des promotions: " . $e->getMessage();
        }
        break;

    case 'retours':
        try {
            $stmt = $pdo->query("
                SELECT c.*, u.nom as client_nom, u.telephone,
                       COUNT(cd.id) as nombre_produits
                FROM commandes c
                JOIN utilisateurs u ON c.client_id = u.id
                LEFT JOIN commande_details cd ON c.id = cd.commande_id
                WHERE c.statut = 'rembourse'
                GROUP BY c.id
                ORDER BY c.date_commande DESC
                LIMIT 20
            ");
            $retours_en_attente = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des retours: " . $e->getMessage();
        }
        break;

    case 'produits_sans_prix':
        try {
            $stmt = $pdo->query("
                SELECT p.*, c.nom as categorie_nom, f.nom_societe as fournisseur_nom
                FROM produits p
                LEFT JOIN categories c ON p.categorie_id = c.id
                LEFT JOIN fournisseurs f ON p.fournisseur_id = f.id
                LEFT JOIN prix_vente pv ON p.id = pv.produit_id AND pv.date_fin IS NULL
                WHERE p.statut = 'actif' AND pv.id IS NULL
                ORDER BY p.nom
            ");
            $produits_sans_prix = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des produits sans prix: " . $e->getMessage();
        }
        break;
}

// Récupérer les transactions en temps réel
$transactions_temps_reel = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.numero_commande, c.montant_total, c.mode_paiement,
               c.date_commande, u.nom as client_nom
        FROM commandes c
        JOIN utilisateurs u ON c.client_id = u.id
        WHERE c.caissier_id = :caissier_id 
        AND DATE(c.date_commande) = CURDATE()
        AND c.statut = 'paye'
        ORDER BY c.date_commande DESC
        LIMIT 10
    ");
    $stmt->execute([':caissier_id' => $_SESSION['user_id']]);
    $transactions_temps_reel = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Erreur chargement transactions temps réel: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NAGEX Pharma - Dashboard Caissier</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap"
        rel="stylesheet">
    <!-- Styles CSS pour le sidebar -->
    <style>
        .menu-item {
            position: relative;
            overflow: hidden;
            border-radius: 8px;
            margin: 2px 0;
            transition: all 0.2s ease;
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

        .active-menu {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
            color: white !important;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .section-title {
            position: relative;
            padding-left: 20px;
            margin-top: 1rem;
            margin-bottom: 0.5rem;
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

        .badge-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            min-width: 20px;
            text-align: center;
            display: inline-block;
        }

        .badge-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            min-width: 20px;
            text-align: center;
            display: inline-block;
        }

        .menu-item:hover {
            transform: translateX(2px);
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.15);
        }

        .badge-danger,
        .badge-warning {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }

            100% {
                transform: scale(1);
            }
        }
    </style>
</head>

<body class="bg-gray-100" style="font-family: 'Montserrat', sans-serif;">



    <div class="flex">
        <!-- Sidebar -->
        <div class="sidebar w-64 bg-white shadow-lg min-h-screen">
            <!-- Logo Section avec fond dégradé -->
            <div class="flex items-center justify-center p-6 border-b bg-gradient-to-r from-emerald-50 to-green-50">
                <div class="flex items-center space-x-3">
                    <div
                        class="w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg">
                        <i class="fas fa-capsules text-white text-xl"></i>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-gray-800">NAGEX Pharma</h1>
                        <p class="text-xs text-green-600 font-medium">Caissier Dashboard</p>
                    </div>
                </div>
            </div>

            <nav class="flex-1 p-4 overflow-y-auto">
                <!-- Dashboard -->
                <div class="mb-6">
                    <a href="?page=dashboard"
                        class="menu-item block py-3 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'dashboard' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                        <i class="fas fa-home mr-3 w-5 text-center"></i>
                        <span class="font-medium">Tableau de bord</span>
                    </a>
                </div>

                <!-- Commandes -->
                <div class="mb-6">
                    <div class="section-title text-xs text-gray-500 font-semibold uppercase tracking-wider mb-2">
                        <i class="fas fa-shopping-cart mr-2"></i>Commandes
                    </div>
                    <div class="space-y-1">
                        <a href="?page=commandes_attente"
                            class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'commandes_attente' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                            <i class="fas fa-clock mr-3 w-5 text-center"></i>
                            <span class="flex-1">En attente</span>
                            <?php if (isset($stats['commandes_attente']) && $stats['commandes_attente'] > 0): ?>
                                <span class="badge-danger text-xs font-bold px-2 py-1 min-w-[24px] text-center">
                                    <?php echo $stats['commandes_attente']; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <a href="?page=transactions"
                            class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'transactions' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                            <i class="fas fa-history mr-3 w-5 text-center"></i>
                            <span>Historique</span>
                        </a>
                    </div>
                </div>

                <!-- Prix & Promotions -->
                <div class="mb-6">
                    <div class="section-title text-xs text-gray-500 font-semibold uppercase tracking-wider mb-2">
                        <i class="fas fa-tags mr-2"></i>Prix & Promotions
                    </div>
                    <div class="space-y-1">
                        <a href="?page=gestion_prix"
                            class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'gestion_prix' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                            <i class="fas fa-dollar-sign mr-3 w-5 text-center"></i>
                            <span>Gestion des prix</span>
                        </a>
                        <a href="?page=produits_sans_prix"
                            class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'produits_sans_prix' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                            <i class="fas fa-exclamation-circle mr-3 w-5 text-center"></i>
                            <span class="flex-1">Sans prix</span>
                            <?php if (isset($stats['produits_sans_prix']) && $stats['produits_sans_prix'] > 0): ?>
                                <span class="badge-warning text-xs font-bold px-2 py-1 min-w-[24px] text-center">
                                    <?php echo $stats['produits_sans_prix']; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <a href="?page=promotions"
                            class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'promotions' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                            <i class="fas fa-percent mr-3 w-5 text-center"></i>
                            <span>Promotions</span>
                        </a>
                        <a href="?page=taux_conversion"
                            class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'taux_conversion' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                            <i class="fas fa-exchange-alt mr-3 w-5 text-center"></i>
                            <span>Taux de change</span>
                        </a>
                    </div>
                </div>

                <!-- Retours -->
                <div class="mb-6">
                    <div class="section-title text-xs text-gray-500 font-semibold uppercase tracking-wider mb-2">
                        <i class="fas fa-undo mr-2"></i>Retours
                    </div>
                    <a href="?page=retours"
                        class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'retours' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                        <i class="fas fa-undo-alt mr-3 w-5 text-center"></i>
                        <span>Gestion retours</span>
                    </a>
                </div>

                <!-- Dashboard -->
                <div class="mb-6">
                    <div class="section-title text-xs text-gray-500 font-semibold uppercase tracking-wider mb-2">
                        <i class="fas fa-chart-line mr-2"></i>Dashboard
                    </div>
                    <a href="?page=temps_reel"
                        class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'temps_reel' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                        <i class="fas fa-chart-bar mr-3 w-5 text-center"></i>
                        <span>Temps réel</span>
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
                <!-- ========== DASHBOARD CAISSIER ========== -->
                <div class="mb-8">
                    <!-- En-tête -->
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center">
                            <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl shadow-sm mr-4">
                                <i class="fas fa-cash-register text-emerald-600 text-xl"></i>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold text-gray-800">Tableau de bord Caissier</h1>
                                <p class="text-gray-600 mt-1">Gestion des transactions et des prix</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span
                                class="px-3 py-1 bg-gradient-to-r from-emerald-100 to-emerald-50 text-emerald-800 rounded-full text-sm font-semibold border border-emerald-200">
                                <i class="fas fa-sync-alt mr-1"></i>Mis à jour à l'instant
                            </span>
                        </div>
                    </div>

                    <!-- Cartes de statistiques -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                        <!-- Commandes en attente -->
                        <div
                            class="stat-card group bg-gradient-to-br from-white to-blue-50 p-6 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 border border-blue-100 hover:border-blue-200 relative overflow-hidden">
                            <div class="relative z-10">
                                <div class="flex items-center mb-2">
                                    <div
                                        class="stat-icon p-3 bg-gradient-to-br from-blue-100 to-blue-200 rounded-xl shadow-inner group-hover:scale-110 transition-transform duration-300">
                                        <i class="fas fa-clock text-blue-600 text-xl"></i>
                                    </div>
                                    <div class="ml-4">
                                        <p class="text-gray-600 text-sm font-medium">Commandes en attente</p>
                                        <p class="text-2xl font-bold text-gray-800 mt-1">
                                            <?php echo $stats['commandes_attente'] ?? 0; ?></p>
                                    </div>
                                </div>
                                <p class="text-xs text-blue-600 font-medium mt-2">
                                    <i class="fas fa-hourglass-half mr-1"></i>À traiter
                                </p>
                            </div>
                            <div class="absolute bottom-0 right-0 opacity-10">
                                <i class="fas fa-clock text-blue-400 text-5xl"></i>
                            </div>
                        </div>

                        <!-- Chiffre d'affaires -->
                        <div
                            class="stat-card group bg-gradient-to-br from-white to-green-50 p-6 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 border border-green-100 hover:border-green-200 relative overflow-hidden">
                            <div class="relative z-10">
                                <div class="flex items-center mb-2">
                                    <div
                                        class="stat-icon p-3 bg-gradient-to-br from-green-100 to-green-200 rounded-xl shadow-inner group-hover:scale-110 transition-transform duration-300">
                                        <i class="fas fa-chart-line text-green-600 text-xl"></i>
                                    </div>
                                    <div class="ml-4">
                                        <p class="text-gray-600 text-sm font-medium">Chiffre d'affaires</p>
                                        <p class="text-2xl font-bold text-gray-800 mt-1">
                                            <?php echo formatMontant((float) ($stats['chiffre_affaires'] ?? 0)); ?>
                                        </p>
                                    </div>
                                </div>
                                <p class="text-xs text-green-600 font-medium mt-2">
                                    <i class="fas fa-money-bill-wave mr-1"></i>Aujourd'hui
                                </p>
                            </div>
                            <div class="absolute bottom-0 right-0 opacity-10">
                                <i class="fas fa-chart-line text-green-400 text-5xl"></i>
                            </div>
                        </div>

                        <!-- Produits sans prix -->
                        <div
                            class="stat-card group bg-gradient-to-br from-white to-yellow-50 p-6 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 border border-yellow-100 hover:border-yellow-200 relative overflow-hidden">
                            <div class="relative z-10">
                                <div class="flex items-center mb-2">
                                    <div
                                        class="stat-icon p-3 bg-gradient-to-br from-yellow-100 to-yellow-200 rounded-xl shadow-inner group-hover:scale-110 transition-transform duration-300">
                                        <i class="fas fa-exclamation-circle text-yellow-600 text-xl"></i>
                                    </div>
                                    <div class="ml-4">
                                        <p class="text-gray-600 text-sm font-medium">Produits sans prix</p>
                                        <p class="text-2xl font-bold text-gray-800 mt-1">
                                            <?php echo $stats['produits_sans_prix'] ?? 0; ?></p>
                                    </div>
                                </div>
                                <p class="text-xs text-yellow-600 font-medium mt-2">
                                    <i class="fas fa-dollar-sign mr-1"></i>À définir
                                </p>
                            </div>
                            <div class="absolute bottom-0 right-0 opacity-10">
                                <i class="fas fa-exclamation-circle text-yellow-400 text-5xl"></i>
                            </div>
                        </div>

                        <!-- Transactions aujourd'hui -->
                        <div
                            class="stat-card group bg-gradient-to-br from-white to-purple-50 p-6 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 border border-purple-100 hover:border-purple-200 relative overflow-hidden">
                            <div class="relative z-10">
                                <div class="flex items-center mb-2">
                                    <div
                                        class="stat-icon p-3 bg-gradient-to-br from-purple-100 to-purple-200 rounded-xl shadow-inner group-hover:scale-110 transition-transform duration-300">
                                        <i class="fas fa-sync-alt text-purple-600 text-xl"></i>
                                    </div>
                                    <div class="ml-4">
                                        <p class="text-gray-600 text-sm font-medium">Transactions</p>
                                        <p class="text-2xl font-bold text-gray-800 mt-1">
                                            <?php echo $stats['transactions_aujourdhui'] ?? 0; ?></p>
                                    </div>
                                </div>
                                <p class="text-xs text-purple-600 font-medium mt-2">
                                    <i class="fas fa-calendar-day mr-1"></i>Aujourd'hui
                                </p>
                            </div>
                            <div class="absolute bottom-0 right-0 opacity-10">
                                <i class="fas fa-sync-alt text-purple-400 text-5xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Transactions récentes & Actions rapides -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                        <!-- Transactions récentes -->
                        <div
                            class="bg-gradient-to-br from-white to-blue-50 rounded-xl shadow-lg p-6 border border-blue-200">
                            <div class="flex items-center justify-between mb-6">
                                <div class="flex items-center">
                                    <div class="p-3 bg-gradient-to-br from-blue-100 to-blue-200 rounded-xl shadow-sm mr-3">
                                        <i class="fas fa-history text-blue-600"></i>
                                    </div>
                                    <h2 class="text-xl font-bold text-gray-800">Transactions récentes</h2>
                                </div>
                                <a href="?page=transactions"
                                    class="text-sm text-emerald-600 hover:text-emerald-700 font-medium flex items-center">
                                    Voir toutes
                                    <i class="fas fa-arrow-right ml-1 text-xs"></i>
                                </a>
                            </div>

                            <?php if (isset($transactions_temps_reel) && count($transactions_temps_reel) > 0): ?>
                                <div class="space-y-4">
                                    <?php foreach ($transactions_temps_reel as $transaction): ?>
                                        <div
                                            class="group flex justify-between items-center p-4 bg-white rounded-xl border border-gray-200 hover:shadow-lg hover:border-blue-300 transition-all duration-200">
                                            <div class="flex items-center">
                                                <div
                                                    class="p-2 bg-gradient-to-br from-green-50 to-green-100 rounded-lg mr-3 border border-green-200">
                                                    <i class="fas fa-receipt text-green-600 text-sm"></i>
                                                </div>
                                                <div>
                                                    <div
                                                        class="font-semibold text-gray-900 group-hover:text-blue-700 transition-colors">
                                                        #<?php echo htmlspecialchars($transaction['numero_commande']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-600 mt-1">
                                                        <i class="fas fa-user mr-1 text-xs"></i>
                                                        <?php echo htmlspecialchars($transaction['client_nom']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-lg font-bold text-green-700">
                                                    <?php echo formatMontant($transaction['montant_total']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500 mt-1">
                                                    <i class="fas fa-clock mr-1 text-xs"></i>
                                                    <?php echo date('H:i', strtotime($transaction['date_commande'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-10">
                                    <div
                                        class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gradient-to-r from-gray-100 to-gray-200 mb-4 shadow-sm">
                                        <i class="fas fa-receipt text-gray-400 text-2xl"></i>
                                    </div>
                                    <h3 class="text-lg font-medium text-gray-700 mb-2">Aucune transaction</h3>
                                    <p class="text-gray-500 text-sm">Aucune transaction aujourd'hui</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Actions rapides -->
                        <div
                            class="bg-gradient-to-br from-white to-emerald-50 rounded-xl shadow-lg p-6 border border-emerald-200">
                            <div class="flex items-center mb-6">
                                <div
                                    class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl shadow-sm mr-3">
                                    <i class="fas fa-bolt text-emerald-600"></i>
                                </div>
                                <h2 class="text-xl font-bold text-gray-800">Actions rapides</h2>
                            </div>

                            <div class="space-y-4">
                                <!-- Valider les paiements -->
                                <a href="?page=commandes_attente"
                                    class="group flex items-center justify-between p-5 bg-gradient-to-br from-blue-50 to-blue-100 hover:from-blue-100 hover:to-blue-200 rounded-xl border border-blue-200 hover:shadow-lg transition-all duration-200">
                                    <div class="flex items-center">
                                        <div
                                            class="p-3 bg-gradient-to-br from-white to-blue-50 rounded-lg mr-4 shadow-sm group-hover:shadow transition-shadow">
                                            <i class="fas fa-check-circle text-blue-600 text-xl"></i>
                                        </div>
                                        <div>
                                            <h3 class="font-semibold text-blue-700">Valider les paiements</h3>
                                            <p class="text-sm text-blue-600 opacity-80 mt-1">
                                                <?php echo $stats['commandes_attente'] ?? 0; ?> commandes en attente
                                            </p>
                                        </div>
                                    </div>
                                    <i
                                        class="fas fa-chevron-right text-blue-400 text-lg group-hover:translate-x-1 transition-transform"></i>
                                </a>

                                <!-- Définir les prix -->
                                <a href="?page=produits_sans_prix"
                                    class="group flex items-center justify-between p-5 bg-gradient-to-br from-yellow-50 to-yellow-100 hover:from-yellow-100 hover:to-yellow-200 rounded-xl border border-yellow-200 hover:shadow-lg transition-all duration-200">
                                    <div class="flex items-center">
                                        <div
                                            class="p-3 bg-gradient-to-br from-white to-yellow-50 rounded-lg mr-4 shadow-sm group-hover:shadow transition-shadow">
                                            <i class="fas fa-dollar-sign text-yellow-600 text-xl"></i>
                                        </div>
                                        <div>
                                            <h3 class="font-semibold text-yellow-700">Définir les prix</h3>
                                            <p class="text-sm text-yellow-600 opacity-80 mt-1">
                                                <?php echo $stats['produits_sans_prix'] ?? 0; ?> produits sans prix
                                            </p>
                                        </div>
                                    </div>
                                    <i
                                        class="fas fa-chevron-right text-yellow-400 text-lg group-hover:translate-x-1 transition-transform"></i>
                                </a>

                                <!-- Mettre à jour taux -->
                                <a href="?page=taux_conversion"
                                    class="group flex items-center justify-between p-5 bg-gradient-to-br from-purple-50 to-purple-100 hover:from-purple-100 hover:to-purple-200 rounded-xl border border-purple-200 hover:shadow-lg transition-all duration-200">
                                    <div class="flex items-center">
                                        <div
                                            class="p-3 bg-gradient-to-br from-white to-purple-50 rounded-lg mr-4 shadow-sm group-hover:shadow transition-shadow">
                                            <i class="fas fa-exchange-alt text-purple-600 text-xl"></i>
                                        </div>
                                        <div>
                                            <h3 class="font-semibold text-purple-700">Mettre à jour taux</h3>
                                            <p class="text-sm text-purple-600 opacity-80 mt-1">
                                                Taux de conversion FC/USD
                                            </p>
                                        </div>
                                    </div>
                                    <i
                                        class="fas fa-chevron-right text-purple-400 text-lg group-hover:translate-x-1 transition-transform"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($current_page == 'commandes_attente'): ?>
                <!-- ========== COMMANDES EN ATTENTE ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Commandes en attente de paiement</h1>
                    <p class="text-gray-600">Validez les paiements des clients</p>
                </div>

                <?php if (count($commandes_attente) > 0): ?>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Commande
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Montant</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produits
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($commandes_attente as $commande): ?>
                                        <tr>
                                            <td class="px-6 py-4">
                                                <p class="font-bold text-gray-900">#<?php echo e($commande['numero_commande']); ?>
                                                </p>
                                            </td>
                                            <td class="px-6 py-4">
                                                <p class="font-semibold"><?php echo e($commande['client_nom']); ?></p>
                                                <p class="text-sm text-gray-500"><?php echo e($commande['telephone']); ?></p>
                                            </td>
                                            <td class="px-6 py-4">
                                                <p class="font-bold text-green-600">
                                                    <?php echo formatMontant($commande['montant_total']); ?>
                                                </p>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500">
                                                <?php echo date('d/m/Y H:i', strtotime($commande['date_commande'])); ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm">
                                                    <?php echo $commande['nombre_produits']; ?> produits
                                                </span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <button onclick="validerPaiement(<?php echo $commande['id']; ?>)"
                                                    class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-sm mr-2">
                                                    <i class="fas fa-check mr-1"></i>Valider
                                                </button>
                                                <button onclick="voirDetails(<?php echo $commande['id']; ?>)"
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
                        <i class="fas fa-check-circle text-green-500 text-5xl mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-800 mb-2">Aucune commande en attente</h3>
                        <p class="text-gray-600">Toutes les commandes sont traitées.</p>
                    </div>
                <?php endif; ?>

            <?php elseif ($current_page == 'gestion_prix'): ?>
                <!-- ========== GESTION DES PRIX ========== -->
                <div class="mb-6 flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Gestion des prix</h1>
                        <p class="text-gray-600">Définissez les prix des produits en FC et USD</p>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produit</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code barre
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Catégorie
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prix FC</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prix USD
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Taux</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($produits as $produit): ?>
                                    <tr>
                                        <td class="px-6 py-4 font-semibold"><?php echo e($produit['nom']); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-500"><?php echo e($produit['code_barre']); ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500"><?php echo e($produit['categorie_nom']); ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($produit['prix_fc']): ?>
                                                <span
                                                    class="font-bold text-green-600"><?php echo formatMontant($produit['prix_fc']); ?></span>
                                            <?php else: ?>
                                                <span class="text-red-500 text-sm">Non défini</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($produit['prix_usd']): ?>
                                                <span
                                                    class="font-bold text-blue-600">$<?php echo number_format($produit['prix_usd'], 2); ?></span>
                                            <?php else: ?>
                                                <span class="text-red-500 text-sm">Non défini</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            <?php echo number_format($produit['taux_conversion'] ?? 1, 4); ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <button
                                                onclick="modifierPrix(<?php echo $produit['id']; ?>, '<?php echo e($produit['nom']); ?>', <?php echo $produit['prix_fc'] ?? 0; ?>, <?php echo $produit['prix_usd'] ?? 0; ?>)"
                                                class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm">
                                                <i class="fas fa-edit mr-1"></i>Modifier
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ($current_page == 'produits_sans_prix'): ?>
                <!-- ========== PRODUITS SANS PRIX ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Produits sans prix défini</h1>
                    <p class="text-gray-600">Ces produits ne peuvent pas être vendus sans prix</p>
                </div>

                <?php if (isset($produits_sans_prix) && count($produits_sans_prix) > 0): ?>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produit</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Catégorie
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fournisseur
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($produits_sans_prix as $produit): ?>
                                        <tr>
                                            <td class="px-6 py-4 text-sm font-medium text-gray-900">#<?php echo $produit['id']; ?>
                                            </td>
                                            <td class="px-6 py-4 font-semibold"><?php echo e($produit['nom']); ?></td>
                                            <td class="px-6 py-4 text-sm text-gray-500"><?php echo e($produit['categorie_nom']); ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500">
                                                <?php echo e($produit['fournisseur_nom']); ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <button
                                                    onclick="definirPrix(<?php echo $produit['id']; ?>, '<?php echo e($produit['nom']); ?>')"
                                                    class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-sm">
                                                    <i class="fas fa-dollar-sign mr-1"></i>Définir prix
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
                        <i class="fas fa-check-circle text-green-500 text-5xl mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-800 mb-2">Tous les produits ont un prix</h3>
                        <p class="text-gray-600">Aucun produit sans prix défini.</p>
                    </div>
                <?php endif; ?>

            <?php elseif ($current_page == 'transactions'): ?>
                <!-- ========== HISTORIQUE DES TRANSACTIONS ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Historique des transactions</h1>
                    <p class="text-gray-600">Vos transactions validées</p>
                </div>

                <?php if (count($transactions_recentes) > 0): ?>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Commande
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Montant</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Paiement
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produits
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($transactions_recentes as $transaction): ?>
                                        <tr>
                                            <td class="px-6 py-4">
                                                <p class="font-bold text-gray-900">
                                                    #<?php echo e($transaction['numero_commande']); ?></p>
                                            </td>
                                            <td class="px-6 py-4">
                                                <p class="font-semibold"><?php echo e($transaction['client_nom']); ?></p>
                                            </td>
                                            <td class="px-6 py-4">
                                                <p class="font-bold text-green-600">
                                                    <?php echo formatMontant($transaction['montant_total']); ?>
                                                </p>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php if ($transaction['mode_paiement'] == 'especes'): ?>
                                                    <span class="badge badge-success">Espèces</span>
                                                <?php elseif ($transaction['mode_paiement'] == 'carte'): ?>
                                                    <span class="badge badge-info">Carte</span>
                                                <?php else: ?>
                                                    <span class="badge badge-purple">Mobile</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500">
                                                <?php echo date('d/m/Y H:i', strtotime($transaction['date_commande'])); ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm">
                                                    <?php echo $transaction['nombre_produits']; ?> produits
                                                </span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <button onclick="voirDetails(<?php echo $transaction['id']; ?>)"
                                                    class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm mr-2">
                                                    <i class="fas fa-eye mr-1"></i>Détails
                                                </button>
                                                <button onclick="imprimerTicket(<?php echo $transaction['id']; ?>)"
                                                    class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-1 rounded text-sm">
                                                    <i class="fas fa-print mr-1"></i>Ticket
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
                        <i class="fas fa-history text-gray-400 text-5xl mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-800 mb-2">Aucune transaction</h3>
                        <p class="text-gray-600">Vous n'avez pas encore effectué de transactions.</p>
                    </div>
                <?php endif; ?>

            <?php elseif ($current_page == 'taux_conversion'): ?>
                <!-- ========== TAUX DE CONVERSION ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Taux de conversion FC/USD</h1>
                    <p class="text-gray-600">Mettez à jour le taux de change</p>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6 max-w-md mx-auto">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Modifier le taux de conversion</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="ajuster_taux_conversion">

                        <div class="mb-6">
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="taux">
                                Taux actuel (1 USD = ? FC)
                            </label>
                            <input type="number" id="taux" name="taux" step="0.0001" min="0.0001" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                                placeholder="Ex: 2500.75">
                            <p class="text-sm text-gray-500 mt-1">Le taux utilisé pour convertir USD en Francs Congolais</p>
                        </div>

                        <div class="flex justify-end space-x-4">
                            <button type="submit" class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                                <i class="fas fa-sync-alt mr-2"></i>Mettre à jour
                            </button>
                        </div>
                    </form>

                    <div class="mt-8 p-4 bg-gray-50 rounded-lg">
                        <h4 class="font-semibold text-gray-700 mb-2">
                            <i class="fas fa-info-circle mr-2"></i>Comment ça marche
                        </h4>
                        <ul class="text-sm text-gray-600 space-y-1">
                            <li>• Le taux définit la valeur de 1 USD en Francs Congolais</li>
                            <li>• Les prix en USD sont automatiquement convertis en FC</li>
                            <li>• Tous les nouveaux prix utiliseront ce taux</li>
                            <li>• Les anciennes transactions conservent leur taux original</li>
                        </ul>
                    </div>
                </div>

            <?php else: ?>
                <!-- ========== PAGE NON TROUVÉE ========== -->
                <div class="bg-white rounded-lg shadow-md p-12 text-center">
                    <i class="fas fa-exclamation-circle text-red-500 text-5xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-800 mb-2">Page non trouvée</h3>
                    <p class="text-gray-600 mb-6">La page que vous cherchez n'existe pas.</p>
                    <a href="?page=dashboard" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-home mr-2"></i>Retour au dashboard
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modals -->
    <div id="modalPrix" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 id="modalTitre" class="text-lg font-semibold text-gray-800 mb-4"></h3>
                <form method="POST" action="" id="formPrix">
                    <input type="hidden" name="action" value="definir_prix">
                    <input type="hidden" id="modalProduitId" name="produit_id">

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="prix_fc">
                            Prix en Francs Congolais (FC) *
                        </label>
                        <input type="number" id="prix_fc" name="prix_fc" step="0.01" min="0" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="prix_usd">
                            Prix en USD *
                        </label>
                        <input type="number" id="prix_usd" name="prix_usd" step="0.01" min="0" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>

                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="taux_conversion">
                            Taux de conversion (1 USD = ? FC)
                        </label>
                        <input type="number" id="taux_conversion" name="taux_conversion" step="0.0001" min="0.0001"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                        <p class="text-sm text-gray-500 mt-1">Laissé vide pour calcul automatique</p>
                    </div>

                    <div class="flex justify-end space-x-4">
                        <button type="button" onclick="fermerModal()"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            Annuler
                        </button>
                        <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                            <i class="fas fa-save mr-2"></i>Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="modalPaiement" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Valider le paiement</h3>
                <form method="POST" action="" id="formPaiement">
                    <input type="hidden" name="action" value="valider_paiement">
                    <input type="hidden" id="modalCommandeId" name="commande_id">

                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2">
                            Mode de paiement *
                        </label>
                        <div class="space-y-2">
                            <label class="flex items-center">
                                <input type="radio" name="mode_paiement" value="especes" checked class="mr-2">
                                <span>Espèces</span>
                            </label>
                            <label class="flex items-center">
                                <input type="radio" name="mode_paiement" value="carte" class="mr-2">
                                <span>Carte bancaire</span>
                            </label>
                            <label class="flex items-center">
                                <input type="radio" name="mode_paiement" value="mobile" class="mr-2">
                                <span>Paiement mobile</span>
                            </label>
                        </div>
                    </div>

                    <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <p class="text-sm text-yellow-800">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            Cette action va valider le paiement et mettre à jour les stocks automatiquement.
                        </p>
                    </div>

                    <div class="flex justify-end space-x-4">
                        <button type="button" onclick="fermerModalPaiement()"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            Annuler
                        </button>
                        <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                            <i class="fas fa-check mr-2"></i>Valider le paiement
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts JavaScript -->
    <script>
        // Fonction pour ouvrir le modal de définition de prix
        function definirPrix(produitId, produitNom) {
            document.getElementById('modalTitre').textContent = 'Définir prix pour: ' + produitNom;
            document.getElementById('modalProduitId').value = produitId;
            document.getElementById('prix_fc').value = '';
            document.getElementById('prix_usd').value = '';
            document.getElementById('taux_conversion').value = '';
            document.getElementById('modalPrix').classList.remove('hidden');
        }

        // Fonction pour modifier le prix existant
        function modifierPrix(produitId, produitNom, prixFc, prixUsd) {
            document.getElementById('modalTitre').textContent = 'Modifier prix: ' + produitNom;
            document.getElementById('modalProduitId').value = produitId;
            document.getElementById('prix_fc').value = prixFc || '';
            document.getElementById('prix_usd').value = prixUsd || '';
            document.getElementById('modalPrix').classList.remove('hidden');
        }

        // Fonction pour valider un paiement
        function validerPaiement(commandeId) {
            document.getElementById('modalCommandeId').value = commandeId;
            document.getElementById('modalPaiement').classList.remove('hidden');
        }

        // Fonction pour voir les détails d'une commande
        function voirDetails(commandeId) {
            window.open('commande_details.php?id=' + commandeId, '_blank');
        }

        // Fonction pour imprimer un ticket
        function imprimerTicket(commandeId) {
            alert('Impression du ticket pour la commande #' + commandeId + ' - Fonctionnalité à implémenter');
        }

        // Fonction pour fermer les modals
        function fermerModal() {
            document.getElementById('modalPrix').classList.add('hidden');
        }

        function fermerModalPaiement() {
            document.getElementById('modalPaiement').classList.add('hidden');
        }

        // Calcul automatique du taux de conversion
        document.getElementById('prix_fc')?.addEventListener('input', function () {
            const prixFc = parseFloat(this.value) || 0;
            const prixUsd = parseFloat(document.getElementById('prix_usd').value) || 0;

            if (prixFc > 0 && prixUsd > 0) {
                const taux = prixFc / prixUsd;
                document.getElementById('taux_conversion').value = taux.toFixed(4);
            }
        });

        document.getElementById('prix_usd')?.addEventListener('input', function () {
            const prixUsd = parseFloat(this.value) || 0;
            const prixFc = parseFloat(document.getElementById('prix_fc').value) || 0;

            if (prixFc > 0 && prixUsd > 0) {
                const taux = prixFc / prixUsd;
                document.getElementById('taux_conversion').value = taux.toFixed(4);
            }
        });

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