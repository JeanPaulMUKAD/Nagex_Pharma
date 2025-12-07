<?php
declare(strict_types=1);
// ============================================
// MODULE CLIENT - Système NAGEX Pharma
// ============================================
// Fichier : client_dashboard.php
// Description : Interface complète du client
// ============================================

// Démarrage de session et vérification du rôle
session_start();

// Vérifier si l'utilisateur est connecté et a le rôle client
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'client') {
    header('Location: login.php');
    exit();
}

// Inclure la classe Database
require_once __DIR__ . '/../../config/database.php';

// Initialisation des variables
$message = '';
$error = '';
$current_page = $_GET['page'] ?? 'dashboard';
$user_id = $_SESSION['user_id'];

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
 * Récupère les statistiques du dashboard client
 */
function getDashboardStats(PDO $pdo, int $client_id): array
{
    $stats = [];

    // Commandes totales
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM commandes WHERE client_id = :client_id");
    $stmt->execute([':client_id' => $client_id]);
    $stats['commandes_total'] = $stmt->fetch()['total'] ?? 0;

    // Commandes en cours
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM commandes WHERE client_id = :client_id AND statut = 'en_attente'");
    $stmt->execute([':client_id' => $client_id]);
    $stats['commandes_encours'] = $stmt->fetch()['total'] ?? 0;

    // Dépenses totales
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_total), 0) as total 
        FROM commandes 
        WHERE client_id = :client_id AND statut = 'paye'
    ");
    $stmt->execute([':client_id' => $client_id]);
    $stats['depenses_total'] = $stmt->fetch()['total'] ?? 0;

    // Produits favoris
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT produit_id) as total 
        FROM favoris 
        WHERE client_id = :client_id
    ");
    $stmt->execute([':client_id' => $client_id]);
    $stats['produits_favoris'] = $stmt->fetch()['total'] ?? 0;

    return $stats;
}

function formatMontant($montant, string $devise = 'CDF'): string
{
    // Convertir en float
    $montant_float = floatval($montant);

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
 * Calcule le prix avec promotion
 */
function calculerPixPromotion(float $prix_original, array $promotion): float
{
    if ($promotion['type_promotion'] === 'pourcentage') {
        return $prix_original * (1 - $promotion['valeur'] / 100);
    } else {
        return max(0, $prix_original - $promotion['valeur']);
    }
}

// ============================================
// GESTION DU PANIER ET COMMANDES
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {

            // AJOUTER AU PANIER
            case 'ajouter_panier':
                try {
                    // Vérifier si le panier existe
                    if (!isset($_SESSION['panier'])) {
                        $_SESSION['panier'] = [];
                    }

                    $produit_id = intval($_POST['produit_id'] ?? 0);
                    $quantite = intval($_POST['quantite'] ?? 1);

                    // Vérifier la disponibilité du stock
                    $stmt = $pdo->prepare("
                        SELECT SUM(l.quantite_actuelle) as stock_disponible
                        FROM produits p
                        JOIN lots l ON p.id = l.produit_id
                        WHERE p.id = :produit_id 
                        AND l.statut = 'en_stock'
                        AND l.date_expiration > CURDATE()
                    ");
                    $stmt->execute([':produit_id' => $produit_id]);
                    $stock = $stmt->fetch()['stock_disponible'] ?? 0;

                    if ($stock < $quantite) {
                        $error = "❌ Stock insuffisant. Stock disponible: $stock";
                        break;
                    }

                    // Ajouter au panier
                    if (isset($_SESSION['panier'][$produit_id])) {
                        $_SESSION['panier'][$produit_id] += $quantite;
                    } else {
                        $_SESSION['panier'][$produit_id] = $quantite;
                    }

                    $message = "✅ Produit ajouté au panier!";

                } catch (Exception $e) {
                    $error = "❌ Erreur lors de l'ajout au panier: " . $e->getMessage();
                }
                break;

            // MODIFIER LA QUANTITÉ DANS LE PANIER
            case 'modifier_quantite':
                try {
                    $produit_id = intval($_POST['produit_id'] ?? 0);
                    $quantite = intval($_POST['quantite'] ?? 1);

                    if ($quantite <= 0) {
                        unset($_SESSION['panier'][$produit_id]);
                        $message = "✅ Produit retiré du panier!";
                    } else {
                        // Vérifier le stock
                        $stmt = $pdo->prepare("
                            SELECT SUM(l.quantite_actuelle) as stock_disponible
                            FROM produits p
                            JOIN lots l ON p.id = l.produit_id
                            WHERE p.id = :produit_id 
                            AND l.statut = 'en_stock'
                            AND l.date_expiration > CURDATE()
                        ");
                        $stmt->execute([':produit_id' => $produit_id]);
                        $stock = $stmt->fetch()['stock_disponible'] ?? 0;

                        if ($stock < $quantite) {
                            $error = "❌ Stock insuffisant. Stock disponible: $stock";
                            break;
                        }

                        $_SESSION['panier'][$produit_id] = $quantite;
                        $message = "✅ Quantité modifiée!";
                    }

                } catch (Exception $e) {
                    $error = "❌ Erreur lors de la modification: " . $e->getMessage();
                }
                break;

            // VIDER LE PANIER
            case 'vider_panier':
                $_SESSION['panier'] = [];
                $message = "✅ Panier vidé avec succès!";
                break;

            // PASSER COMMANDE
            case 'passer_commande':
                try {
                    if (empty($_SESSION['panier'])) {
                        $error = "❌ Votre panier est vide!";
                        break;
                    }

                    $pdo->beginTransaction();

                    // Générer un numéro de commande unique
                    $numero_commande = 'CMD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

                    // Calculer le total
                    $montant_total = 0;
                    $details_commande = [];

                    foreach ($_SESSION['panier'] as $produit_id => $quantite) {
                        // Récupérer le prix actuel
                        $stmt = $pdo->prepare("
                            SELECT p.nom, pv.prix_fc, pv.prix_usd,
                                   pr.type_promotion, pr.valeur as promo_valeur
                            FROM produits p
                            LEFT JOIN prix_vente pv ON p.id = pv.produit_id AND pv.date_fin IS NULL
                            LEFT JOIN promotions pr ON p.id = pr.produit_id 
                                AND (pr.date_fin IS NULL OR pr.date_fin >= CURDATE())
                                AND pr.date_debut <= CURDATE()
                            WHERE p.id = :produit_id AND p.statut = 'actif'
                        ");
                        $stmt->execute([':produit_id' => $produit_id]);
                        $produit = $stmt->fetch();

                        if (!$produit || !$produit['prix_fc']) {
                            throw new Exception("Produit #$produit_id non disponible");
                        }

                        $prix_unitaire = $produit['prix_fc'];

                        // Appliquer promotion si disponible
                        if ($produit['promo_valeur']) {
                            $prix_unitaire = calculerPixPromotion($prix_unitaire, [
                                'type_promotion' => $produit['type_promotion'],
                                'valeur' => $produit['promo_valeur']
                            ]);
                        }

                        // Trouver un lot disponible
                        $stmt = $pdo->prepare("
                            SELECT l.id, l.quantite_actuelle
                            FROM lots l
                            WHERE l.produit_id = :produit_id 
                            AND l.statut = 'en_stock'
                            AND l.date_expiration > CURDATE()
                            AND l.quantite_actuelle >= :quantite
                            ORDER BY l.date_expiration ASC
                            LIMIT 1
                        ");
                        $stmt->execute([
                            ':produit_id' => $produit_id,
                            ':quantite' => $quantite
                        ]);
                        $lot = $stmt->fetch();

                        if (!$lot) {
                            throw new Exception("Stock insuffisant pour le produit #$produit_id");
                        }

                        $sous_total = $prix_unitaire * $quantite;
                        $montant_total += $sous_total;

                        $details_commande[] = [
                            'produit_id' => $produit_id,
                            'lot_id' => $lot['id'],
                            'quantite' => $quantite,
                            'prix_unitaire' => $prix_unitaire,
                            'sous_total' => $sous_total,
                            'produit_nom' => $produit['nom']
                        ];
                    }

                    // Créer la commande
                    $stmt = $pdo->prepare("
                        INSERT INTO commandes (
                            numero_commande, client_id, montant_total, statut
                        ) VALUES (
                            :numero_commande, :client_id, :montant_total, 'en_attente'
                        )
                    ");
                    $stmt->execute([
                        ':numero_commande' => $numero_commande,
                        ':client_id' => $user_id,
                        ':montant_total' => $montant_total
                    ]);

                    $commande_id = $pdo->lastInsertId();

                    // Ajouter les détails de la commande
                    foreach ($details_commande as $detail) {
                        $stmt = $pdo->prepare("
                            INSERT INTO commande_details (
                                commande_id, produit_id, lot_id, quantite, 
                                prix_unitaire, sous_total
                            ) VALUES (
                                :commande_id, :produit_id, :lot_id, :quantite,
                                :prix_unitaire, :sous_total
                            )
                        ");
                        $stmt->execute([
                            ':commande_id' => $commande_id,
                            ':produit_id' => $detail['produit_id'],
                            ':lot_id' => $detail['lot_id'],
                            ':quantite' => $detail['quantite'],
                            ':prix_unitaire' => $detail['prix_unitaire'],
                            ':sous_total' => $detail['sous_total']
                        ]);
                    }

                    // Vider le panier
                    $_SESSION['panier'] = [];

                    $pdo->commit();
                    $message = "✅ Commande passée avec succès! Numéro: $numero_commande";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "❌ Erreur lors de la commande: " . $e->getMessage();
                }
                break;

            // AJOUTER AUX FAVORIS
            case 'ajouter_favori':
                try {
                    $produit_id = intval($_POST['produit_id'] ?? 0);

                    $stmt = $pdo->prepare("
                        INSERT INTO favoris (client_id, produit_id)
                        VALUES (:client_id, :produit_id)
                        ON DUPLICATE KEY UPDATE created_at = NOW()
                    ");
                    $stmt->execute([
                        ':client_id' => $user_id,
                        ':produit_id' => $produit_id
                    ]);

                    $message = "✅ Ajouté aux favoris!";

                } catch (Exception $e) {
                    $error = "❌ Erreur: " . $e->getMessage();
                }
                break;

            // RETIRER DES FAVORIS
            case 'retirer_favori':
                try {
                    $produit_id = intval($_POST['produit_id'] ?? 0);

                    $stmt = $pdo->prepare("
                        DELETE FROM favoris 
                        WHERE client_id = :client_id AND produit_id = :produit_id
                    ");
                    $stmt->execute([
                        ':client_id' => $user_id,
                        ':produit_id' => $produit_id
                    ]);

                    $message = "✅ Retiré des favoris!";

                } catch (Exception $e) {
                    $error = "❌ Erreur: " . $e->getMessage();
                }
                break;

            // METTRE À JOUR LE PROFIL
            case 'mettre_a_jour_profil':
                try {
                    $stmt = $pdo->prepare("
                        UPDATE users SET
                            nom = :nom,
                            email = :email,
                            telephone = :telephone,
                            adresse = :adresse,
                            updated_at = NOW()
                        WHERE id = :id
                    ");

                    $stmt->execute([
                        ':nom' => $_POST['nom'] ?? '',
                        ':email' => $_POST['email'] ?? '',
                        ':telephone' => $_POST['telephone'] ?? '',
                        ':adresse' => $_POST['adresse'] ?? '',
                        ':id' => $user_id
                    ]);

                    // Mettre à jour la session
                    $_SESSION['user_nom'] = $_POST['nom'] ?? $_SESSION['user_nom'];
                    $_SESSION['user_email'] = $_POST['email'] ?? $_SESSION['user_email'];

                    $message = "✅ Profil mis à jour avec succès!";

                } catch (Exception $e) {
                    $error = "❌ Erreur lors de la mise à jour: " . $e->getMessage();
                }
                break;
        }
    }
}

// ============================================
// RÉCUPÉRATION DES DONNÉES
// ============================================

// Récupérer les statistiques
$stats = getDashboardStats($pdo, $user_id);

// Récupérer les produits selon la page
$produits_catalogue = [];
$produits_favoris = [];
$commandes_client = [];
$panier_details = [];
$user_info = [];

// Informations du client
try {
    $stmt = $pdo->prepare("SELECT nom, email, telephone, adresse FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $user_info = $stmt->fetch();
} catch (Exception $e) {
    error_log("Erreur chargement info client: " . $e->getMessage());
}

switch ($current_page) {
    case 'catalogue':
        try {
            // Récupérer les filtres
            $categorie_id = $_GET['categorie'] ?? null;
            $recherche = $_GET['recherche'] ?? '';
            $tri = $_GET['tri'] ?? 'nom';

            $query = "
                SELECT p.*, c.nom as categorie_nom,
                       pv.prix_fc, pv.prix_usd,
                       pr.type_promotion, pr.valeur as promo_valeur,
                       (SELECT COUNT(*) FROM favoris f WHERE f.produit_id = p.id AND f.client_id = :client_id) as est_favori,
                       CASE 
                           WHEN pr.valeur IS NOT NULL THEN
                               CASE 
                                   WHEN pr.type_promotion = 'pourcentage' 
                                   THEN pv.prix_fc * (1 - pr.valeur/100)
                                   ELSE pv.prix_fc - pr.valeur
                               END
                           ELSE pv.prix_fc
                       END as prix_final
                FROM produits p
                LEFT JOIN categories c ON p.categorie_id = c.id
                LEFT JOIN prix_vente pv ON p.id = pv.produit_id AND pv.date_fin IS NULL
                LEFT JOIN promotions pr ON p.id = pr.produit_id 
                    AND (pr.date_fin IS NULL OR pr.date_fin >= CURDATE())
                    AND pr.date_debut <= CURDATE()
                WHERE p.statut = 'actif'
            ";

            $params = [':client_id' => $user_id];

            if ($categorie_id) {
                $query .= " AND p.categorie_id = :categorie_id";
                $params[':categorie_id'] = $categorie_id;
            }

            if ($recherche) {
                $query .= " AND (p.nom LIKE :recherche OR p.description LIKE :recherche OR p.code_barre LIKE :recherche)";
                $params[':recherche'] = "%$recherche%";
            }

            // Ordre de tri
            switch ($tri) {
                case 'prix_croissant':
                    $query .= " ORDER BY prix_final ASC";
                    break;
                case 'prix_decroissant':
                    $query .= " ORDER BY prix_final DESC";
                    break;
                case 'nouveautes':
                    $query .= " ORDER BY p.created_at DESC";
                    break;
                default:
                    $query .= " ORDER BY p.nom ASC";
            }

            $query .= " LIMIT 100";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $produits_catalogue = $stmt->fetchAll();

        } catch (Exception $e) {
            $error = "Erreur lors du chargement du catalogue: " . $e->getMessage();
        }
        break;

    case 'favoris':
        try {
            $stmt = $pdo->prepare("
                SELECT p.*, c.nom as categorie_nom,
                       pv.prix_fc, pv.prix_usd,
                       pr.type_promotion, pr.valeur as promo_valeur,
                       CASE 
                           WHEN pr.valeur IS NOT NULL THEN
                               CASE 
                                   WHEN pr.type_promotion = 'pourcentage' 
                                   THEN pv.prix_fc * (1 - pr.valeur/100)
                                   ELSE pv.prix_fc - pr.valeur
                               END
                           ELSE pv.prix_fc
                       END as prix_final
                FROM favoris f
                JOIN produits p ON f.produit_id = p.id
                LEFT JOIN categories c ON p.categorie_id = c.id
                LEFT JOIN prix_vente pv ON p.id = pv.produit_id AND pv.date_fin IS NULL
                LEFT JOIN promotions pr ON p.id = pr.produit_id 
                    AND (pr.date_fin IS NULL OR pr.date_fin >= CURDATE())
                    AND pr.date_debut <= CURDATE()
                WHERE f.client_id = :client_id AND p.statut = 'actif'
                ORDER BY f.created_at DESC
            ");
            $stmt->execute([':client_id' => $user_id]);
            $produits_favoris = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des favoris: " . $e->getMessage();
        }
        break;

    case 'commandes':
        try {
            $stmt = $pdo->prepare("
                SELECT c.*, 
                       COUNT(cd.id) as nombre_produits,
                       SUM(cd.quantite) as total_articles
                FROM commandes c
                LEFT JOIN commande_details cd ON c.id = cd.commande_id
                WHERE c.client_id = :client_id
                GROUP BY c.id
                ORDER BY c.date_commande DESC
                LIMIT 20
            ");
            $stmt->execute([':client_id' => $user_id]);
            $commandes_client = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des commandes: " . $e->getMessage();
        }
        break;

    case 'panier':
        // Détails du panier
        if (!empty($_SESSION['panier'])) {
            try {
                $produit_ids = array_keys($_SESSION['panier']);
                $placeholders = implode(',', array_fill(0, count($produit_ids), '?'));

                $stmt = $pdo->prepare("
                    SELECT p.*, c.nom as categorie_nom,
                           pv.prix_fc, pv.prix_usd,
                           pr.type_promotion, pr.valeur as promo_valeur,
                           CASE 
                               WHEN pr.valeur IS NOT NULL THEN
                                   CASE 
                                       WHEN pr.type_promotion = 'pourcentage' 
                                       THEN pv.prix_fc * (1 - pr.valeur/100)
                                       ELSE pv.prix_fc - pr.valeur
                                   END
                               ELSE pv.prix_fc
                           END as prix_final
                    FROM produits p
                    LEFT JOIN categories c ON p.categorie_id = c.id
                    LEFT JOIN prix_vente pv ON p.id = pv.produit_id AND pv.date_fin IS NULL
                    LEFT JOIN promotions pr ON p.id = pr.produit_id 
                        AND (pr.date_fin IS NULL OR pr.date_fin >= CURDATE())
                        AND pr.date_debut <= CURDATE()
                    WHERE p.id IN ($placeholders) AND p.statut = 'actif'
                ");
                $stmt->execute($produit_ids);
                $produits_panier = $stmt->fetchAll();

                // Combiner avec les quantités du panier
                foreach ($produits_panier as $produit) {
                    $panier_details[] = [
                        'produit' => $produit,
                        'quantite' => $_SESSION['panier'][$produit['id']],
                        'sous_total' => $produit['prix_final'] * $_SESSION['panier'][$produit['id']]
                    ];
                }
            } catch (Exception $e) {
                $error = "Erreur lors du chargement du panier: " . $e->getMessage();
            }
        }
        break;

    case 'profil':
        // Récupérer les catégories pour le catalogue
        try {
            $stmt = $pdo->query("SELECT id, nom FROM categories WHERE statut = 'actif' ORDER BY nom");
            $categories = $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Erreur chargement catégories: " . $e->getMessage());
        }
        break;
}

// Récupérer les dernières commandes pour le dashboard
$dernieres_commandes = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, COUNT(cd.id) as nombre_produits
        FROM commandes c
        LEFT JOIN commande_details cd ON c.id = cd.commande_id
        WHERE c.client_id = :client_id
        GROUP BY c.id
        ORDER BY c.date_commande DESC
        LIMIT 5
    ");
    $stmt->execute([':client_id' => $user_id]);
    $dernieres_commandes = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Erreur chargement dernières commandes: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NAGEX Pharma - Espace Client</title>
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

        .product-card {
            transition: all 0.3s ease;
        }

        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>

<body class="bg-gray-100">

    <!-- Navigation principale -->
    <nav class="bg-purple-600 text-white shadow-lg">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-user text-2xl"></i>
                    <h1 class="text-xl font-bold">NAGEX Pharma - Espace Client</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="?page=panier" class="relative">
                        <i class="fas fa-shopping-cart text-xl"></i>
                        <?php if (!empty($_SESSION['panier'])): ?>
                            <span
                                class="absolute -top-2 -right-2 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">
                                <?php echo array_sum($_SESSION['panier']); ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <span class="text-sm">
                        <i class="fas fa-user mr-1"></i>
                        <?php echo e($user_info['nom'] ?? 'Client'); ?>
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
                    <i class="fas fa-tachometer-alt mr-2"></i>Mon Espace
                </h2>
            </div>
            <nav class="mt-4">
                <a href="?page=dashboard"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'dashboard' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-home mr-2"></i>Tableau de bord
                </a>

                <div class="px-4 py-2 text-gray-500 text-sm font-semibold mt-4">
                    <i class="fas fa-shopping-bag mr-2"></i>Boutique
                </div>
                <a href="?page=catalogue"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'catalogue' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-store mr-2"></i>Catalogue produits
                </a>
                <a href="?page=favoris"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'favoris' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-heart mr-2"></i>Mes favoris
                    <?php if ($stats['produits_favoris'] > 0): ?>
                        <span class="float-right bg-red-500 text-white text-xs rounded-full px-2">
                            <?php echo $stats['produits_favoris']; ?>
                        </span>
                    <?php endif; ?>
                </a>

                <div class="px-4 py-2 text-gray-500 text-sm font-semibold mt-4">
                    <i class="fas fa-shopping-cart mr-2"></i>Commandes
                </div>
                <a href="?page=panier"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'panier' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-cart-shopping mr-2"></i>Mon panier
                    <?php if (!empty($_SESSION['panier'])): ?>
                        <span class="float-right bg-yellow-500 text-white text-xs rounded-full px-2">
                            <?php echo array_sum($_SESSION['panier']); ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a href="?page=commandes"
                    class="block py-2 px-4 hover:bg-purple-50 <?php echo $current_page == 'commandes' ? 'active-menu text-purple-600' : 'text-gray-700'; ?>">
                    <i class="fas fa-history mr-2"></i>Mes commandes
                    <?php if ($stats['commandes_total'] > 0): ?>
                        <span class="float-right bg-blue-500 text-white text-xs rounded-full px-2">
                            <?php echo $stats['commandes_total']; ?>
                        </span>
                    <?php endif; ?>
                </a>

                <div class="px-4 py-2 text-gray-500 text-sm font-semibold mt-4">
                    <i class="fas fa-user-circle mr-2"></i>Mon compte
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
                <!-- ========== DASHBOARD CLIENT ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Tableau de bord</h1>
                    <p class="text-gray-600">Bienvenue dans votre espace client NAGEX Pharma</p>
                </div>

                <!-- Cartes de statistiques -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="stat-card bg-white p-6 rounded-lg shadow-md border border-gray-200">
                        <div class="flex items-center">
                            <div class="p-3 bg-purple-100 rounded-lg">
                                <i class="fas fa-shopping-bag text-purple-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-gray-500 text-sm">Commandes totales</p>
                                <p class="text-2xl font-bold"><?php echo $stats['commandes_total']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card bg-white p-6 rounded-lg shadow-md border border-gray-200">
                        <div class="flex items-center">
                            <div class="p-3 bg-blue-100 rounded-lg">
                                <i class="fas fa-clock text-blue-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-gray-500 text-sm">En cours</p>
                                <p class="text-2xl font-bold"><?php echo $stats['commandes_encours']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card bg-white p-6 rounded-lg shadow-md border border-gray-200">
                        <div class="flex items-center">
                            <div class="p-3 bg-green-100 rounded-lg">
                                <i class="fas fa-chart-line text-green-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-gray-500 text-sm">Dépenses totales</p>
                                <?php if (isset($commande) && !empty($commande)): ?>
                                    <p class="font-bold text-purple-600">
                                        <?php echo formatMontant(floatval($commande['montant_total'] ?? 0)); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card bg-white p-6 rounded-lg shadow-md border border-gray-200">
                        <div class="flex items-center">
                            <div class="p-3 bg-red-100 rounded-lg">
                                <i class="fas fa-heart text-red-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-gray-500 text-sm">Favoris</p>
                                <p class="text-2xl font-bold"><?php echo $stats['produits_favoris']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dernières commandes et actions rapides -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 class="text-xl font-bold text-gray-800 mb-4">
                            <i class="fas fa-history mr-2"></i>Dernières commandes
                        </h2>
                        <?php if (count($dernieres_commandes) > 0): ?>
                            <div class="space-y-3">
                                <?php foreach ($dernieres_commandes as $commande): ?>
                                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                        <div>
                                            <p class="font-semibold">#<?php echo e($commande['numero_commande']); ?></p>
                                            <p class="text-sm text-gray-600">
                                                <?php echo date('d/m/Y', strtotime($commande['date_commande'])); ?>
                                                • <?php echo $commande['nombre_produits']; ?> produit(s)
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-bold text-purple-600">
                                                <?php echo formatMontant($commande['montant_total']); ?></p>
                                            <span class="text-xs px-2 py-1 rounded <?php
                                            echo $commande['statut'] == 'paye' ? 'bg-green-100 text-green-800' :
                                                ($commande['statut'] == 'en_attente' ? 'bg-yellow-100 text-yellow-800' :
                                                    'bg-red-100 text-red-800');
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $commande['statut'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-4 text-center">
                                <a href="?page=commandes" class="text-purple-600 hover:text-purple-800 text-sm font-semibold">
                                    Voir toutes les commandes →
                                </a>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-500 text-center py-4">Vous n'avez pas encore passé de commande</p>
                            <div class="text-center">
                                <a href="?page=catalogue"
                                    class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg inline-block">
                                    <i class="fas fa-store mr-2"></i>Découvrir le catalogue
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 class="text-xl font-bold text-gray-800 mb-4">
                            <i class="fas fa-bolt mr-2"></i>Actions rapides
                        </h2>
                        <div class="space-y-4">
                            <a href="?page=catalogue"
                                class="flex items-center justify-between p-4 bg-purple-50 hover:bg-purple-100 rounded-lg border border-purple-200">
                                <div class="flex items-center">
                                    <i class="fas fa-store text-purple-600 text-xl mr-3"></i>
                                    <div>
                                        <p class="font-semibold text-purple-700">Parcourir le catalogue</p>
                                        <p class="text-sm text-purple-600">Découvrez nos produits</p>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right text-purple-400"></i>
                            </a>

                            <a href="?page=panier"
                                class="flex items-center justify-between p-4 bg-yellow-50 hover:bg-yellow-100 rounded-lg border border-yellow-200">
                                <div class="flex items-center">
                                    <i class="fas fa-shopping-cart text-yellow-600 text-xl mr-3"></i>
                                    <div>
                                        <p class="font-semibold text-yellow-700">Voir mon panier</p>
                                        <p class="text-sm text-yellow-600">
                                            <?php
                                            $nb_articles = !empty($_SESSION['panier']) ? array_sum($_SESSION['panier']) : 0;
                                            echo "$nb_articles article(s)";
                                            ?>
                                        </p>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right text-yellow-400"></i>
                            </a>

                            <a href="?page=favoris"
                                class="flex items-center justify-between p-4 bg-red-50 hover:bg-red-100 rounded-lg border border-red-200">
                                <div class="flex items-center">
                                    <i class="fas fa-heart text-red-600 text-xl mr-3"></i>
                                    <div>
                                        <p class="font-semibold text-red-700">Mes favoris</p>
                                        <p class="text-sm text-red-600"><?php echo $stats['produits_favoris']; ?> produit(s)
                                        </p>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-right text-red-400"></i>
                            </a>
                        </div>
                    </div>
                </div>

            <?php elseif ($current_page == 'catalogue'): ?>
                <!-- ========== CATALOGUE PRODUITS ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Catalogue des produits</h1>
                    <p class="text-gray-600">Découvrez tous nos produits disponibles</p>
                </div>

                <!-- Filtres et recherche -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <form method="GET" action="" class="space-y-4">
                        <input type="hidden" name="page" value="catalogue">

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <!-- Recherche -->
                            <div>
                                <label class="block text-gray-700 text-sm font-bold mb-2" for="recherche">
                                    <i class="fas fa-search mr-1"></i>Rechercher
                                </label>
                                <input type="text" id="recherche" name="recherche"
                                    value="<?php echo e($_GET['recherche'] ?? ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"
                                    placeholder="Nom, description, code barre...">
                            </div>

                            <!-- Catégorie -->
                            <div>
                                <label class="block text-gray-700 text-sm font-bold mb-2" for="categorie">
                                    <i class="fas fa-tag mr-1"></i>Catégorie
                                </label>
                                <select id="categorie" name="categorie"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                                    <option value="">Toutes les catégories</option>
                                    <?php foreach ($categories ?? [] as $categorie): ?>
                                        <option value="<?php echo $categorie['id']; ?>" <?php echo (isset($_GET['categorie']) && $_GET['categorie'] == $categorie['id']) ? 'selected' : ''; ?>>
                                            <?php echo e($categorie['nom']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Tri -->
                            <div>
                                <label class="block text-gray-700 text-sm font-bold mb-2" for="tri">
                                    <i class="fas fa-sort mr-1"></i>Trier par
                                </label>
                                <select id="tri" name="tri"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                                    <option value="nom" <?php echo ($_GET['tri'] ?? 'nom') == 'nom' ? 'selected' : ''; ?>>Nom
                                        A-Z</option>
                                    <option value="prix_croissant" <?php echo ($_GET['tri'] ?? '') == 'prix_croissant' ? 'selected' : ''; ?>>Prix croissant</option>
                                    <option value="prix_decroissant" <?php echo ($_GET['tri'] ?? '') == 'prix_decroissant' ? 'selected' : ''; ?>>Prix décroissant</option>
                                    <option value="nouveautes" <?php echo ($_GET['tri'] ?? '') == 'nouveautes' ? 'selected' : ''; ?>>Nouveautés</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-4">
                            <a href="?page=catalogue"
                                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                                Réinitialiser
                            </a>
                            <button type="submit" class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                                <i class="fas fa-filter mr-2"></i>Appliquer les filtres
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Liste des produits -->
                <?php if (count($produits_catalogue) > 0): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                        <?php foreach ($produits_catalogue as $produit): ?>
                            <div class="product-card bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                                <!-- En-tête avec badge promotion -->
                                <?php if ($produit['promo_valeur']): ?>
                                    <div class="bg-red-500 text-white px-3 py-1 text-sm font-bold">
                                        <i class="fas fa-percent mr-1"></i>
                                        <?php echo $produit['type_promotion'] == 'pourcentage' ?
                                            '-' . $produit['promo_valeur'] . '%' :
                                            '-' . formatMontant($produit['promo_valeur']);
                                        ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Corps de la carte -->
                                <div class="p-4">
                                    <div class="flex justify-between items-start mb-3">
                                        <div>
                                            <h3 class="font-bold text-gray-800 text-lg"><?php echo e($produit['nom']); ?></h3>
                                            <p class="text-sm text-gray-500"><?php echo e($produit['categorie_nom']); ?></p>
                                        </div>
                                        <button
                                            onclick="toggleFavori(<?php echo $produit['id']; ?>, <?php echo $produit['est_favori'] ? 'true' : 'false'; ?>)"
                                            class="text-<?php echo $produit['est_favori'] ? 'red' : 'gray'; ?>-500 hover:text-red-600">
                                            <i class="fas fa-heart <?php echo $produit['est_favori'] ? 'fas' : 'far'; ?>"></i>
                                        </button>
                                    </div>

                                    <p class="text-gray-600 text-sm mb-4 line-clamp-2">
                                        <?php echo e(substr($produit['description'] ?? 'Aucune description', 0, 100)); ?>...
                                    </p>

                                    <!-- Prix -->
                                    <div class="mb-4">
                                        <div class="flex items-center space-x-2">
                                            <span class="font-bold text-xl text-purple-600">
                                                <?php echo formatMontant($produit['prix_final']); ?>
                                            </span>
                                            <?php if ($produit['promo_valeur']): ?>
                                                <span class="text-sm text-gray-400 line-through">
                                                    <?php echo formatMontant($produit['prix_fc']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($produit['prix_usd']): ?>
                                            <p class="text-sm text-gray-500">
                                                $<?php echo number_format($produit['prix_usd'], 2); ?> USD
                                            </p>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex space-x-2">
                                        <button onclick="voirDetailsProduit(<?php echo $produit['id']; ?>)"
                                            class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm">
                                            <i class="fas fa-eye mr-1"></i>Détails
                                        </button>
                                        <form method="POST" action="" class="flex-1">
                                            <input type="hidden" name="action" value="ajouter_panier">
                                            <input type="hidden" name="produit_id" value="<?php echo $produit['id']; ?>">
                                            <input type="hidden" name="quantite" value="1">
                                            <button type="submit"
                                                class="w-full bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm">
                                                <i class="fas fa-cart-plus mr-1"></i>Ajouter
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-lg shadow-md p-12 text-center">
                        <i class="fas fa-search text-gray-400 text-5xl mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-800 mb-2">Aucun produit trouvé</h3>
                        <p class="text-gray-600">Aucun produit ne correspond à vos critères de recherche.</p>
                    </div>
                <?php endif; ?>

            <?php elseif ($current_page == 'favoris'): ?>
                <!-- ========== MES FAVORIS ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Mes produits favoris</h1>
                    <p class="text-gray-600">Vos produits préférés</p>
                </div>

                <?php if (count($produits_favoris) > 0): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                        <?php foreach ($produits_favoris as $produit): ?>
                            <div class="product-card bg-white rounded-lg shadow-md overflow-hidden border border-gray-200">
                                <!-- En-tête avec badge promotion -->
                                <?php if ($produit['promo_valeur']): ?>
                                    <div class="bg-red-500 text-white px-3 py-1 text-sm font-bold">
                                        <i class="fas fa-percent mr-1"></i>
                                        <?php echo $produit['type_promotion'] == 'pourcentage' ?
                                            '-' . $produit['promo_valeur'] . '%' :
                                            '-' . formatMontant($produit['promo_valeur']);
                                        ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Corps de la carte -->
                                <div class="p-4">
                                    <div class="flex justify-between items-start mb-3">
                                        <div>
                                            <h3 class="font-bold text-gray-800 text-lg"><?php echo e($produit['nom']); ?></h3>
                                            <p class="text-sm text-gray-500"><?php echo e($produit['categorie_nom']); ?></p>
                                        </div>
                                        <form method="POST" action="">
                                            <input type="hidden" name="action" value="retirer_favori">
                                            <input type="hidden" name="produit_id" value="<?php echo $produit['id']; ?>">
                                            <button type="submit" class="text-red-500 hover:text-red-600">
                                                <i class="fas fa-heart"></i>
                                            </button>
                                        </form>
                                    </div>

                                    <p class="text-gray-600 text-sm mb-4 line-clamp-2">
                                        <?php echo e(substr($produit['description'] ?? 'Aucune description', 0, 100)); ?>...
                                    </p>

                                    <!-- Prix -->
                                    <div class="mb-4">
                                        <div class="flex items-center space-x-2">
                                            <span class="font-bold text-xl text-purple-600">
                                                <?php echo formatMontant($produit['prix_final']); ?>
                                            </span>
                                            <?php if ($produit['promo_valeur']): ?>
                                                <span class="text-sm text-gray-400 line-through">
                                                    <?php echo formatMontant($produit['prix_fc']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex space-x-2">
                                        <button onclick="voirDetailsProduit(<?php echo $produit['id']; ?>)"
                                            class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm">
                                            <i class="fas fa-eye mr-1"></i>Détails
                                        </button>
                                        <form method="POST" action="" class="flex-1">
                                            <input type="hidden" name="action" value="ajouter_panier">
                                            <input type="hidden" name="produit_id" value="<?php echo $produit['id']; ?>">
                                            <input type="hidden" name="quantite" value="1">
                                            <button type="submit"
                                                class="w-full bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm">
                                                <i class="fas fa-cart-plus mr-1"></i>Ajouter
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-lg shadow-md p-12 text-center">
                        <i class="fas fa-heart text-gray-400 text-5xl mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-800 mb-2">Aucun favori</h3>
                        <p class="text-gray-600 mb-6">Vous n'avez pas encore ajouté de produit à vos favoris.</p>
                        <a href="?page=catalogue" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-store mr-2"></i>Parcourir le catalogue
                        </a>
                    </div>
                <?php endif; ?>

            <?php elseif ($current_page == 'panier'): ?>
                <!-- ========== MON PANIER ========== -->
                <div class="mb-6 flex justify-between items-center">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Mon panier</h1>
                        <p class="text-gray-600">Vos produits sélectionnés</p>
                    </div>
                    <?php if (!empty($panier_details)): ?>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="vider_panier">
                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                                <i class="fas fa-trash mr-2"></i>Vider le panier
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if (!empty($panier_details)): ?>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Liste des produits -->
                        <div class="lg:col-span-2">
                            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                    Produit</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prix
                                                    unitaire</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                    Quantité</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                    Sous-total</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                                    Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php
                                            $total_panier = 0;
                                            foreach ($panier_details as $item):
                                                $total_panier += $item['sous_total'];
                                                ?>
                                                <tr>
                                                    <td class="px-6 py-4">
                                                        <div class="flex items-center">
                                                            <div class="ml-4">
                                                                <p class="font-semibold text-gray-900">
                                                                    <?php echo e($item['produit']['nom']); ?></p>
                                                                <p class="text-sm text-gray-500">
                                                                    <?php echo e($item['produit']['categorie_nom']); ?></p>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <p class="font-bold text-purple-600">
                                                            <?php echo formatMontant($item['produit']['prix_final']); ?></p>
                                                        <?php if ($item['produit']['promo_valeur']): ?>
                                                            <p class="text-sm text-gray-400 line-through">
                                                                <?php echo formatMontant($item['produit']['prix_fc']); ?></p>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <form method="POST" action="" class="flex items-center space-x-2">
                                                            <input type="hidden" name="action" value="modifier_quantite">
                                                            <input type="hidden" name="produit_id"
                                                                value="<?php echo $item['produit']['id']; ?>">
                                                            <input type="number" name="quantite"
                                                                value="<?php echo $item['quantite']; ?>" min="1" max="99"
                                                                class="w-16 px-2 py-1 border border-gray-300 rounded text-center">
                                                            <button type="submit" class="text-blue-600 hover:text-blue-800">
                                                                <i class="fas fa-sync-alt"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <p class="font-bold text-green-600">
                                                            <?php echo formatMontant($item['sous_total']); ?></p>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <form method="POST" action="">
                                                            <input type="hidden" name="action" value="modifier_quantite">
                                                            <input type="hidden" name="produit_id"
                                                                value="<?php echo $item['produit']['id']; ?>">
                                                            <input type="hidden" name="quantite" value="0">
                                                            <button type="submit" class="text-red-600 hover:text-red-800">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Récapitulatif -->
                        <div>
                            <div class="bg-white rounded-lg shadow-md p-6 sticky top-6">
                                <h3 class="text-lg font-bold text-gray-800 mb-4">Récapitulatif</h3>

                                <div class="space-y-3 mb-6">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Sous-total</span>
                                        <span class="font-semibold"><?php echo formatMontant($total_panier); ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600">Frais de livraison</span>
                                        <span class="font-semibold"><?php echo formatMontant(0); ?></span>
                                    </div>
                                    <div class="border-t pt-3">
                                        <div class="flex justify-between">
                                            <span class="text-gray-800 font-bold">Total</span>
                                            <span
                                                class="text-xl font-bold text-purple-600"><?php echo formatMontant($total_panier); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="passer_commande">
                                    <button type="submit"
                                        class="w-full bg-purple-600 hover:bg-purple-700 text-white py-3 rounded-lg font-bold text-lg">
                                        <i class="fas fa-lock mr-2"></i>Passer la commande
                                    </button>
                                </form>

                                <div class="mt-4 text-center">
                                    <a href="?page=catalogue" class="text-purple-600 hover:text-purple-800 text-sm">
                                        <i class="fas fa-arrow-left mr-1"></i>Continuer mes achats
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-lg shadow-md p-12 text-center">
                        <i class="fas fa-shopping-cart text-gray-400 text-5xl mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-800 mb-2">Votre panier est vide</h3>
                        <p class="text-gray-600 mb-6">Ajoutez des produits pour commencer vos achats.</p>
                        <a href="?page=catalogue" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-store mr-2"></i>Parcourir le catalogue
                        </a>
                    </div>
                <?php endif; ?>

            <?php elseif ($current_page == 'commandes'): ?>
                <!-- ========== MES COMMANDES ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Mes commandes</h1>
                    <p class="text-gray-600">Historique de toutes vos commandes</p>
                </div>

                <?php if (count($commandes_client) > 0): ?>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Commande
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Articles
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Montant</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($commandes_client as $commande): ?>
                                        <tr>
                                            <td class="px-6 py-4">
                                                <p class="font-bold text-gray-900">#<?php echo e($commande['numero_commande']); ?>
                                                </p>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500">
                                                <?php echo date('d/m/Y H:i', strtotime($commande['date_commande'])); ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm">
                                                    <?php echo $commande['nombre_produits']; ?> produit(s)
                                                </span>
                                            </td>
                                            <td class="px-6 py-4">
                                                <p class="font-bold text-purple-600">
                                                    <?php echo formatMontant($commande['montant_total']); ?></p>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php if ($commande['statut'] == 'paye'): ?>
                                                    <span class="badge badge-success">Payée</span>
                                                <?php elseif ($commande['statut'] == 'en_attente'): ?>
                                                    <span class="badge badge-warning">En attente</span>
                                                <?php elseif ($commande['statut'] == 'annule'): ?>
                                                    <span class="badge badge-danger">Annulée</span>
                                                <?php else: ?>
                                                    <span class="badge badge-info"><?php echo ucfirst($commande['statut']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <button onclick="voirDetailsCommande(<?php echo $commande['id']; ?>)"
                                                    class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm">
                                                    <i class="fas fa-eye mr-1"></i>Détails
                                                </button>
                                                <?php if ($commande['statut'] == 'paye'): ?>
                                                    <button onclick="imprimerReçu(<?php echo $commande['id']; ?>)"
                                                        class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-1 rounded text-sm ml-2">
                                                        <i class="fas fa-print mr-1"></i>Reçu
                                                    </button>
                                                <?php endif; ?>
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
                        <h3 class="text-xl font-semibold text-gray-800 mb-2">Aucune commande</h3>
                        <p class="text-gray-600 mb-6">Vous n'avez pas encore passé de commande.</p>
                        <a href="?page=catalogue" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-store mr-2"></i>Découvrir le catalogue
                        </a>
                    </div>
                <?php endif; ?>

            <?php elseif ($current_page == 'profil'): ?>
                <!-- ========== MON PROFIL ========== -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Mon profil</h1>
                    <p class="text-gray-600">Gérez vos informations personnelles</p>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="mettre_a_jour_profil">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="nom">
                                        Nom complet *
                                    </label>
                                    <input type="text" id="nom" name="nom" required
                                        value="<?php echo e($user_info['nom'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                                </div>

                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="email">
                                        Email *
                                    </label>
                                    <input type="email" id="email" name="email" required
                                        value="<?php echo e($user_info['email'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                                </div>
                            </div>

                            <div>
                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="telephone">
                                        Téléphone
                                    </label>
                                    <input type="text" id="telephone" name="telephone"
                                        value="<?php echo e($user_info['telephone'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500">
                                </div>

                                <div class="mb-4">
                                    <label class="block text-gray-700 text-sm font-bold mb-2" for="adresse">
                                        Adresse
                                    </label>
                                    <textarea id="adresse" name="adresse" rows="3"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500"><?php echo e($user_info['adresse'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
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
    <div id="modalProduit" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div id="modalContent"></div>
                <div class="flex justify-end mt-4">
                    <button onclick="fermerModalProduit()"
                        class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                        Fermer
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts JavaScript -->
    <script>
        // Fonction pour voir les détails d'un produit
        function voirDetailsProduit(produitId) {
            // Charger les détails via AJAX
            fetch(`produit_details.php?id=${produitId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('modalContent').innerHTML = html;
                    document.getElementById('modalProduit').classList.remove('hidden');
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur lors du chargement des détails');
                });
        }

        // Fonction pour voir les détails d'une commande
        function voirDetailsCommande(commandeId) {
            window.open(`commande_details.php?id=${commandeId}`, '_blank');
        }

        // Fonction pour imprimer un reçu
        function imprimerReçu(commandeId) {
            window.open(`imprimer_recu.php?id=${commandeId}`, '_blank');
        }

        // Fonction pour ajouter/retirer des favoris
        function toggleFavori(produitId, estFavori) {
            const formData = new FormData();
            formData.append('action', estFavori ? 'retirer_favori' : 'ajouter_favori');
            formData.append('produit_id', produitId);

            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    location.reload();
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur lors de la mise à jour des favoris');
                });
        }

        // Fonction pour fermer les modals
        function fermerModalProduit() {
            document.getElementById('modalProduit').classList.add('hidden');
        }

        // Gestion des quantités dans le panier
        document.querySelectorAll('input[name="quantite"]').forEach(input => {
            input.addEventListener('change', function () {
                const form = this.closest('form');
                const quantite = parseInt(this.value);

                if (quantite < 1) {
                    this.value = 1;
                } else if (quantite > 99) {
                    this.value = 99;
                }
            });
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

        // Confirmation avant de vider le panier
        document.querySelector('form[action*="vider_panier"]')?.addEventListener('submit', function (e) {
            if (!confirm('Êtes-vous sûr de vouloir vider votre panier ?')) {
                e.preventDefault();
            }
        });

        // Confirmation avant de passer commande
        document.querySelector('form[action*="passer_commande"]')?.addEventListener('submit', function (e) {
            if (!confirm('Confirmez-vous votre commande ?')) {
                e.preventDefault();
            }
        });
    </script>
</body>

</html>