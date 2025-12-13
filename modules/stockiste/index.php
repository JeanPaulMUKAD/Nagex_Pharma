<?php
declare(strict_types=1);
// ============================================
// MODULE STOCKISTE - Système NAGEX Pharma
// ============================================
// Fichier : stockiste_dashboard.php
// Description : Interface complète du stockiste
// ============================================

// Démarrage de session et vérification du rôle
session_start();

// Vérifier si l'utilisateur est connecté et a le rôle stockiste
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'stockiste') {
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
 * Récupère les statistiques du dashboard stockiste
 */
function getDashboardStats(PDO $pdo): array
{
    $stats = [];

    // Total produits en stock
    $stmt = $pdo->query("
        SELECT SUM(l.quantite_actuelle) as total 
        FROM lots l 
        WHERE l.statut = 'en_stock'
    ");
    $stats['total_stock'] = $stmt->fetch()['total'] ?? 0;

    // Produits avec stock bas
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT a.produit_id) as total 
        FROM alertes a 
        WHERE a.type_alerte = 'stock_bas' 
        AND a.statut = 'non_lu'
    ");
    $stats['alertes_stock_bas'] = $stmt->fetch()['total'] ?? 0;

    // Produits proches de péremption (moins de 30 jours)
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM lots 
        WHERE date_expiration <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
        AND statut = 'en_stock'
        AND quantite_actuelle > 0
    ");
    $stats['alertes_peremption'] = $stmt->fetch()['total'] ?? 0;

    // Ruptures de stock
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT a.produit_id) as total 
        FROM alertes a 
        WHERE a.type_alerte = 'rupture_stock' 
        AND a.statut = 'non_lu'
    ");
    $stats['ruptures_stock'] = $stmt->fetch()['total'] ?? 0;

    return $stats;
}

/**
 * Formate la date pour l'affichage
 */
function formatDate(string $date): string
{
    return date('d/m/Y', strtotime($date));
}

/**
 * Calcule les jours avant péremption
 */
function joursAvantPeremption(string $date_expiration): int
{
    $expiration = new DateTime($date_expiration);
    $today = new DateTime();
    $interval = $today->diff($expiration);
    return $interval->days * ($today < $expiration ? 1 : -1);
}

/**
 * Échapper les données pour l'affichage HTML
 */
function e(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// ============================================
// GESTION DES STOCKS (Traitement POST)
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {

            // AJOUTER UN LOT
            case 'ajouter_lot':
                try {
                    $pdo->beginTransaction();

                    // Insérer le lot
                    $stmt = $pdo->prepare("
                        INSERT INTO lots (
                            produit_id, numero_lot, quantite_initiale, 
                            quantite_actuelle, date_expiration, prix_achat,
                            date_reception, statut, created_by
                        ) VALUES (
                            :produit_id, :numero_lot, :quantite_initiale,
                            :quantite_actuelle, :date_expiration, :prix_achat,
                            :date_reception, 'en_stock', :created_by
                        )
                    ");

                    $stmt->execute([
                        ':produit_id' => intval($_POST['produit_id'] ?? 0),
                        ':numero_lot' => $_POST['numero_lot'] ?? '',
                        ':quantite_initiale' => intval($_POST['quantite_initiale'] ?? 0),
                        ':quantite_actuelle' => intval($_POST['quantite_initiale'] ?? 0),
                        ':date_expiration' => $_POST['date_expiration'] ?? '',
                        ':prix_achat' => floatval($_POST['prix_achat'] ?? 0),
                        ':date_reception' => $_POST['date_reception'] ?? date('Y-m-d'),
                        ':created_by' => $user_id
                    ]);

                    $lot_id = $pdo->lastInsertId();

                    // Enregistrer le mouvement de stock
                    $stmt = $pdo->prepare("
                        INSERT INTO mouvements_stock (
                            produit_id, lot_id, type_mouvement, quantite,
                            quantite_avant, quantite_apres, raison, created_by
                        ) VALUES (
                            :produit_id, :lot_id, 'entree', :quantite,
                            0, :quantite_apres, 'Réception nouveau lot', :created_by
                        )
                    ");

                    $stmt->execute([
                        ':produit_id' => intval($_POST['produit_id'] ?? 0),
                        ':lot_id' => $lot_id,
                        ':quantite' => intval($_POST['quantite_initiale'] ?? 0),
                        ':quantite_apres' => intval($_POST['quantite_initiale'] ?? 0),
                        ':created_by' => $user_id
                    ]);

                    // Vérifier et créer une alerte si nécessaire
                    $quantite_totale = intval($_POST['quantite_initiale'] ?? 0);
                    if ($quantite_totale <= 10) {
                        $stmt = $pdo->prepare("
                            INSERT INTO alertes (
                                type_alerte, produit_id, lot_id, message, niveau, statut
                            ) VALUES (
                                'stock_bas', :produit_id, :lot_id, 
                                'Stock bas après réception du lot', 'faible', 'non_lu'
                            )
                        ");
                        $stmt->execute([
                            ':produit_id' => intval($_POST['produit_id'] ?? 0),
                            ':lot_id' => $lot_id
                        ]);
                    }

                    $pdo->commit();
                    $message = "✅ Lot ajouté avec succès!";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "❌ Erreur lors de l'ajout du lot: " . $e->getMessage();
                }
                break;

            // MODIFIER UN LOT
            case 'modifier_lot':
                try {
                    $pdo->beginTransaction();

                    // Récupérer les anciennes données
                    $stmt = $pdo->prepare("SELECT * FROM lots WHERE id = :id");
                    $stmt->execute([':id' => intval($_POST['lot_id'] ?? 0)]);
                    $ancien_lot = $stmt->fetch();

                    if (!$ancien_lot) {
                        throw new Exception("Lot non trouvé");
                    }

                    // Mettre à jour le lot
                    $stmt = $pdo->prepare("
                        UPDATE lots SET
                            numero_lot = :numero_lot,
                            quantite_initiale = :quantite_initiale,
                            quantite_actuelle = :quantite_actuelle,
                            date_expiration = :date_expiration,
                            prix_achat = :prix_achat,
                            date_reception = :date_reception
                        WHERE id = :id
                    ");

                    $stmt->execute([
                        ':numero_lot' => $_POST['numero_lot'] ?? '',
                        ':quantite_initiale' => intval($_POST['quantite_initiale'] ?? 0),
                        ':quantite_actuelle' => intval($_POST['quantite_actuelle'] ?? 0),
                        ':date_expiration' => $_POST['date_expiration'] ?? '',
                        ':prix_achat' => floatval($_POST['prix_achat'] ?? 0),
                        ':date_reception' => $_POST['date_reception'] ?? '',
                        ':id' => intval($_POST['lot_id'] ?? 0)
                    ]);

                    // Enregistrer le mouvement d'ajustement si la quantité a changé
                    $difference = intval($_POST['quantite_actuelle'] ?? 0) - $ancien_lot['quantite_actuelle'];
                    if ($difference != 0) {
                        $stmt = $pdo->prepare("
                            INSERT INTO mouvements_stock (
                                produit_id, lot_id, type_mouvement, quantite,
                                quantite_avant, quantite_apres, raison, created_by
                            ) VALUES (
                                :produit_id, :lot_id, 'ajustement', :quantite,
                                :quantite_avant, :quantite_apres, 
                                'Ajustement manuel', :created_by
                            )
                        ");

                        $stmt->execute([
                            ':produit_id' => $ancien_lot['produit_id'],
                            ':lot_id' => $ancien_lot['id'],
                            ':quantite' => abs($difference),
                            ':quantite_avant' => $ancien_lot['quantite_actuelle'],
                            ':quantite_apres' => intval($_POST['quantite_actuelle'] ?? 0),
                            ':created_by' => $user_id
                        ]);
                    }

                    $pdo->commit();
                    $message = "✅ Lot modifié avec succès!";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "❌ Erreur lors de la modification: " . $e->getMessage();
                }
                break;

            // AJUSTER LE STOCK
            case 'ajuster_stock':
                try {
                    $pdo->beginTransaction();

                    $lot_id = intval($_POST['lot_id'] ?? 0);
                    $nouvelle_quantite = intval($_POST['nouvelle_quantite'] ?? 0);
                    $raison = $_POST['raison'] ?? 'Ajustement manuel';

                    // Récupérer l'ancienne quantité
                    $stmt = $pdo->prepare("SELECT * FROM lots WHERE id = :id");
                    $stmt->execute([':id' => $lot_id]);
                    $lot = $stmt->fetch();

                    if (!$lot) {
                        throw new Exception("Lot non trouvé");
                    }

                    $difference = $nouvelle_quantite - $lot['quantite_actuelle'];
                    $type_mouvement = $difference > 0 ? 'entree' : 'sortie';

                    // Mettre à jour le lot
                    $stmt = $pdo->prepare("
                        UPDATE lots 
                        SET quantite_actuelle = :quantite 
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':quantite' => $nouvelle_quantite,
                        ':id' => $lot_id
                    ]);

                    // Enregistrer le mouvement
                    $stmt = $pdo->prepare("
                        INSERT INTO mouvements_stock (
                            produit_id, lot_id, type_mouvement, quantite,
                            quantite_avant, quantite_apres, raison, created_by
                        ) VALUES (
                            :produit_id, :lot_id, :type_mouvement, :quantite,
                            :quantite_avant, :quantite_apres, :raison, :created_by
                        )
                    ");

                    $stmt->execute([
                        ':produit_id' => $lot['produit_id'],
                        ':lot_id' => $lot_id,
                        ':type_mouvement' => $type_mouvement,
                        ':quantite' => abs($difference),
                        ':quantite_avant' => $lot['quantite_actuelle'],
                        ':quantite_apres' => $nouvelle_quantite,
                        ':raison' => $raison,
                        ':created_by' => $user_id
                    ]);

                    $pdo->commit();
                    $message = "✅ Stock ajusté avec succès!";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "❌ Erreur lors de l'ajustement: " . $e->getMessage();
                }
                break;

            // MARQUER UNE ALERTE COMME LUE
            case 'marquer_lu':
                try {
                    $stmt = $pdo->prepare("
                        UPDATE alertes 
                        SET statut = 'lu' 
                        WHERE id = :id
                    ");
                    $stmt->execute([':id' => intval($_POST['alerte_id'] ?? 0)]);
                    $message = "✅ Alerte marquée comme lue!";
                } catch (Exception $e) {
                    $error = "❌ Erreur: " . $e->getMessage();
                }
                break;

            // RÉALISER UN INVENTAIRE
            case 'realiser_inventaire':
                try {
                    $pdo->beginTransaction();

                    $produit_id = intval($_POST['produit_id'] ?? 0);
                    $quantite_reelle = intval($_POST['quantite_reelle'] ?? 0);

                    // Récupérer tous les lots du produit
                    $stmt = $pdo->prepare("
                        SELECT id, quantite_actuelle 
                        FROM lots 
                        WHERE produit_id = :produit_id 
                        AND statut = 'en_stock'
                    ");
                    $stmt->execute([':produit_id' => $produit_id]);
                    $lots = $stmt->fetchAll();

                    $quantite_totale = 0;
                    foreach ($lots as $lot) {
                        $quantite_totale += $lot['quantite_actuelle'];
                    }

                    $difference = $quantite_reelle - $quantite_totale;

                    if ($difference != 0) {
                        // Créer un lot d'ajustement
                        $stmt = $pdo->prepare("
                            INSERT INTO lots (
                                produit_id, numero_lot, quantite_initiale, 
                                quantite_actuelle, date_expiration, prix_achat,
                                date_reception, statut, created_by
                            ) VALUES (
                                :produit_id, :numero_lot, :quantite_initiale,
                                :quantite_actuelle, :date_expiration, 0,
                                CURDATE(), 'en_stock', :created_by
                            )
                        ");

                        $numero_lot = 'INV-' . date('Ymd-His');
                        $quantite_ajustement = abs($difference);

                        $stmt->execute([
                            ':produit_id' => $produit_id,
                            ':numero_lot' => $numero_lot,
                            ':quantite_initiale' => $quantite_ajustement,
                            ':quantite_actuelle' => $quantite_ajustement,
                            ':date_expiration' => date('Y-m-d', strtotime('+5 years')),
                            ':created_by' => $user_id
                        ]);

                        $lot_id = $pdo->lastInsertId();

                        // Enregistrer le mouvement
                        $type_mouvement = $difference > 0 ? 'entree' : 'sortie';
                        $stmt = $pdo->prepare("
                            INSERT INTO mouvements_stock (
                                produit_id, lot_id, type_mouvement, quantite,
                                quantite_avant, quantite_apres, raison, created_by
                            ) VALUES (
                                :produit_id, :lot_id, :type_mouvement, :quantite,
                                :quantite_avant, :quantite_apres, 
                                'Ajustement inventaire', :created_by
                            )
                        ");

                        $stmt->execute([
                            ':produit_id' => $produit_id,
                            ':lot_id' => $lot_id,
                            ':type_mouvement' => $type_mouvement,
                            ':quantite' => $quantite_ajustement,
                            ':quantite_avant' => $quantite_totale,
                            ':quantite_apres' => $quantite_reelle,
                            ':created_by' => $user_id
                        ]);
                    }

                    $pdo->commit();
                    $message = "✅ Inventaire réalisé avec succès!";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "❌ Erreur lors de l'inventaire: " . $e->getMessage();
                }
                break;
        }
    }
}

// ============================================
// RÉCUPÉRATION DES DONNÉES
// ============================================

// Récupérer les statistiques
$stats = getDashboardStats($pdo);

// Récupérer les produits pour les dropdowns
$produits = [];
try {
    $stmt = $pdo->query("
        SELECT p.id, p.nom, p.code_barre, c.nom as categorie
        FROM produits p
        LEFT JOIN categories c ON p.categorie_id = c.id
        WHERE p.statut = 'actif'
        ORDER BY p.nom
    ");
    $produits = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Erreur chargement produits: " . $e->getMessage();
}

// Initialiser les variables de données selon la page
$lots = [];
$alertes = [];
$mouvements = [];
$inventaire_data = [];

switch ($current_page) {
    case 'lots':
        try {
            $stmt = $pdo->prepare("
                SELECT l.*, p.nom as produit_nom, p.code_barre,
                       DATEDIFF(l.date_expiration, CURDATE()) as jours_restants
                FROM lots l
                LEFT JOIN produits p ON l.produit_id = p.id
                WHERE l.statut = 'en_stock'
                ORDER BY l.date_expiration ASC
            ");
            $stmt->execute();
            $lots = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur chargement lots: " . $e->getMessage();
        }
        break;

    case 'alertes':
        try {
            $stmt = $pdo->prepare("
                SELECT a.*, p.nom as produit_nom, l.numero_lot,
                       CASE 
                         WHEN a.type_alerte = 'stock_bas' THEN 'Stock bas'
                         WHEN a.type_alerte = 'peremption' THEN 'Péremption proche'
                         WHEN a.type_alerte = 'rupture_stock' THEN 'Rupture de stock'
                       END as type_alerte_text
                FROM alertes a
                LEFT JOIN produits p ON a.produit_id = p.id
                LEFT JOIN lots l ON a.lot_id = l.id
                WHERE a.statut IN ('non_lu', 'lu')
                ORDER BY 
                    CASE a.niveau 
                        WHEN 'critique' THEN 1
                        WHEN 'moyen' THEN 2
                        WHEN 'faible' THEN 3
                    END,
                    a.created_at DESC
            ");
            $stmt->execute();
            $alertes = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur chargement alertes: " . $e->getMessage();
        }
        break;

    case 'mouvements':
        $date_debut = $_GET['date_debut'] ?? date('Y-m-01');
        $date_fin = $_GET['date_fin'] ?? date('Y-m-t');

        try {
            $stmt = $pdo->prepare("
                SELECT m.*, p.nom as produit_nom, l.numero_lot,
                       u.nom as utilisateur_nom
                FROM mouvements_stock m
                LEFT JOIN produits p ON m.produit_id = p.id
                LEFT JOIN lots l ON m.lot_id = l.id
                LEFT JOIN utilisateurs u ON m.created_by = u.id
                WHERE DATE(m.created_at) BETWEEN :date_debut AND :date_fin
                ORDER BY m.created_at DESC
            ");
            $stmt->execute([
                ':date_debut' => $date_debut,
                ':date_fin' => $date_fin
            ]);
            $mouvements = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur chargement mouvements: " . $e->getMessage();
        }
        break;

    case 'inventaire':
        try {
            // Produits avec stock faible
            $stmt = $pdo->prepare("
                SELECT p.id, p.nom, p.code_barre, c.nom as categorie,
                       COALESCE(SUM(l.quantite_actuelle), 0) as quantite_stock
                FROM produits p
                LEFT JOIN categories c ON p.categorie_id = c.id
                LEFT JOIN lots l ON p.id = l.produit_id AND l.statut = 'en_stock'
                WHERE p.statut = 'actif'
                GROUP BY p.id
                HAVING quantite_stock <= 20
                ORDER BY quantite_stock ASC
            ");
            $stmt->execute();
            $inventaire_data = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur chargement inventaire: " . $e->getMessage();
        }
        break;

    case 'rapports':
        $periode = $_GET['periode'] ?? 'semaine';
        $rapports_data = []; // Initialiser la variable

        try {
            switch ($periode) {
                case 'jour':
                    $sql = "
                    SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as nombre_mouvements,
                        SUM(CASE WHEN type_mouvement = 'entree' THEN quantite ELSE 0 END) as entrees,
                        SUM(CASE WHEN type_mouvement = 'sortie' THEN quantite ELSE 0 END) as sorties,
                        COUNT(DISTINCT produit_id) as produit_count
                    FROM mouvements_stock
                    WHERE DATE(created_at) = CURDATE()
                    GROUP BY DATE(created_at)
                    ORDER BY date
                ";
                    break;

                case 'semaine':
                    $sql = "
                    SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as nombre_mouvements,
                        SUM(CASE WHEN type_mouvement = 'entree' THEN quantite ELSE 0 END) as entrees,
                        SUM(CASE WHEN type_mouvement = 'sortie' THEN quantite ELSE 0 END) as sorties,
                        COUNT(DISTINCT produit_id) as produit_count
                    FROM mouvements_stock
                    WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)
                    GROUP BY DATE(created_at)
                    ORDER BY date
                ";
                    break;

                case 'mois':
                    $sql = "
                    SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as nombre_mouvements,
                        SUM(CASE WHEN type_mouvement = 'entree' THEN quantite ELSE 0 END) as entrees,
                        SUM(CASE WHEN type_mouvement = 'sortie' THEN quantite ELSE 0 END) as sorties,
                        COUNT(DISTINCT produit_id) as produit_count
                    FROM mouvements_stock
                    WHERE MONTH(created_at) = MONTH(CURDATE())
                    AND YEAR(created_at) = YEAR(CURDATE())
                    GROUP BY DATE(created_at)
                    ORDER BY date
                ";
                    break;

                default:
                    // Par défaut, semaine
                    $sql = "
                    SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as nombre_mouvements,
                        SUM(CASE WHEN type_mouvement = 'entree' THEN quantite ELSE 0 END) as entrees,
                        SUM(CASE WHEN type_mouvement = 'sortie' THEN quantite ELSE 0 END) as sorties,
                        COUNT(DISTINCT produit_id) as produit_count
                    FROM mouvements_stock
                    WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)
                    GROUP BY DATE(created_at)
                    ORDER BY date
                ";
            }

            $stmt = $pdo->query($sql);
            $rapports_data = $stmt->fetchAll();

        } catch (Exception $e) {
            $error = "Erreur chargement rapports: " . $e->getMessage();
            $rapports_data = []; // Assurer que c'est toujours un tableau
        }
        break;
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NAGEX Pharma - Dashboard Stockiste</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap"
        rel="stylesheet">
    <!-- Styles spécifiques au sidebar -->
    <style>
        .sidebar {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.1);
        }

        .active-menu {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
            color: white !important;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

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

        /* Animation des icônes actives */
        .active-menu i {
            transform: scale(1.1);
            transition: transform 0.2s ease;
        }

        /* Styles pour les pop-ups */
        .popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .popup-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .popup-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 500px;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
            overflow: hidden;
        }

        .popup-overlay.active .popup-container {
            transform: translateY(0);
        }

        .popup-header {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
            color: white;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .popup-header h3 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
        }

        .popup-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s;
        }

        .popup-close:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }

        .popup-content {
            padding: 30px;
        }

        .popup-form-group {
            margin-bottom: 20px;
        }

        .popup-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #374151;
            font-size: 0.875rem;
        }

        .popup-form-group input,
        .popup-form-group select,
        .popup-form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #D1D5DB;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .popup-form-group input:focus,
        .popup-form-group select:focus,
        .popup-form-group textarea:focus {
            outline: none;
            border-color: #10B981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }

        .popup-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #E5E7EB;
        }

        .popup-btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .popup-btn-cancel {
            background-color: #F3F4F6;
            color: #374151;
            border: 1px solid #D1D5DB;
        }

        .popup-btn-cancel:hover {
            background-color: #E5E7EB;
        }

        .popup-btn-submit {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
            color: white;
            border: none;
        }

        .popup-btn-submit:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        /* Styles pour les boutons d'action dans le tableau */
        .btn-action-group {
            display: flex;
            gap: 8px;
        }

        .btn-action {
            position: relative;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 100px;
        }

        .btn-action:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-action-adjust {
            background: linear-gradient(135deg, #3B82F6 0%, #1D4ED8 100%);
            color: white;
        }

        .btn-action-adjust:hover:not(:disabled) {
            background: linear-gradient(135deg, #2563EB 0%, #1E40AF 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-action-modify {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
            color: white;
        }

        .btn-action-modify:hover:not(:disabled) {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-action-details {
            background: linear-gradient(135deg, #6B7280 0%, #4B5563 100%);
            color: white;
        }

        .btn-action-details:hover:not(:disabled) {
            background: linear-gradient(135deg, #4B5563 0%, #374151 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);
        }

        .btn-action i {
            font-size: 0.875rem;
        }

        /* Animation de chargement */
        .loading-spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Effet de profondeur au survol */
        .menu-item:hover {
            transform: translateX(2px);
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.15);
        }

        .menu-item:active {
            transform: translateX(1px);
        }

        /* Animation du badge d'alerte */
        .badge-danger {
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

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: fixed;
                z-index: 1000;
            }

            .sidebar nav {
                max-height: 60vh;
                overflow-y: auto;
            }
        }
    </style>
</head>

<body class="bg-gray-100" style="font-family: 'Montserrat', sans-serif;">



    <div class="flex">
        <!-- Sidebar -->
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
                        <p class="text-xs text-green-600 font-medium">Stockiste Dashboard</p>
                    </div>
                </div>
            </div>

            <!-- Navigation Menu -->
            <nav class="flex-1 p-4 overflow-y-auto">
                <!-- Dashboard -->
                <div class="mb-6">
                    <a href="?page=dashboard"
                        class="menu-item block py-3 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'dashboard' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                        <i class="fas fa-tachometer-alt mr-3 w-5 text-center"></i>
                        <span class="font-medium">Tableau de bord</span>
                    </a>
                </div>

                <!-- Gestion des Lots -->
                <div class="mb-6">
                    <div class="section-title text-xs text-gray-500 font-semibold uppercase tracking-wider mb-2">
                        <i class="fas fa-boxes mr-2"></i>Gestion des Lots
                    </div>
                    <div class="space-y-1">
                        <a href="?page=lots"
                            class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'lots' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                            <i class="fas fa-box mr-3 w-5 text-center"></i>
                            <span>Tous les lots</span>
                        </a>
                        <a href="?page=ajouter_lot"
                            class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'ajouter_lot' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                            <i class="fas fa-plus-circle mr-3 w-5 text-center"></i>
                            <span>Ajouter un lot</span>
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
                        <?php if ($stats['alertes_stock_bas'] > 0): ?>
                            <span class="badge-danger text-xs font-bold px-2 py-1 min-w-[24px] text-center">
                                <?php echo $stats['alertes_stock_bas']; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </div>

                <!-- Mouvements -->
                <div class="mb-6">
                    <div class="section-title text-xs text-gray-500 font-semibold uppercase tracking-wider mb-2">
                        <i class="fas fa-exchange-alt mr-2"></i>Mouvements
                    </div>
                    <a href="?page=mouvements"
                        class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'mouvements' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                        <i class="fas fa-history mr-3 w-5 text-center"></i>
                        <span>Historique</span>
                    </a>
                </div>

                <!-- Inventaire -->
                <div class="mb-6">
                    <div class="section-title text-xs text-gray-500 font-semibold uppercase tracking-wider mb-2">
                        <i class="fas fa-clipboard-check mr-2"></i>Inventaire
                    </div>
                    <a href="?page=inventaire"
                        class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'inventaire' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                        <i class="fas fa-clipboard-list mr-3 w-5 text-center"></i>
                        <span>Inventaire rapide</span>
                    </a>
                </div>

                <!-- Rapports -->
                <div class="mb-6">
                    <div class="section-title text-xs text-gray-500 font-semibold uppercase tracking-wider mb-2">
                        <i class="fas fa-chart-bar mr-2"></i>Rapports
                    </div>
                    <a href="?page=rapports"
                        class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'rapports' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                        <i class="fas fa-file-alt mr-3 w-5 text-center"></i>
                        <span>Rapports stock</span>
                    </a>
                </div>

                <!-- Section Sécurité -->
                <div class="section-title">
                    <div
                        class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-emerald-600 flex items-center">
                        <div class="w-8 h-px bg-emerald-200 mr-3"></div>
                        <i class="fas fa-shield-alt text-emerald-500 mr-2"></i>
                        <span>Sécurité</span>
                        <div class="flex-1 h-px bg-emerald-200 ml-3"></div>
                    </div>
                </div>

                <!-- Déconnexion -->
                <a href="../utilisateurs/logout.php"
                    class="menu-item group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-300 ease-out text-gray-600 hover:bg-gradient-to-r hover:from-red-50 hover:to-rose-50 hover:text-red-700 hover:shadow-md">
                    <div class="relative">
                        <i class="fas fa-sign-out-alt w-5 h-5 mr-3 text-gray-400 group-hover:text-red-500"></i>
                    </div>
                    <span class="flex-1">Déconnexion</span>
                    <i
                        class="fas fa-chevron-right text-xs text-gray-400 group-hover:text-red-400 group-hover:translate-x-1 transition-transform"></i>
                </a>

                <!-- Ligne de séparation -->
                <div class="px-4 py-2">
                    <div class="border-t border-gray-200"></div>
                </div>

                <!-- Informations de session -->
                <div class="px-4 py-3">
                    <div class="text-xs text-gray-500">
                        <div class="flex items-center mb-1">
                            <i class="fas fa-user-circle mr-2 text-gray-400"></i>
                            <span
                                class="font-medium text-gray-600"><?php echo e($_SESSION['user_nom'] ?? 'Client'); ?></span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-clock mr-2 text-gray-400"></i>
                            <span>Connecté depuis <?php echo date('H:i'); ?></span>
                        </div>
                    </div>
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
                <!-- ========== DASHBOARD STOCKISTE ========== -->
                <div class="mb-8">
                    <!-- En-tête -->
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center">
                            <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl shadow-sm mr-4">
                                <i class="fas fa-tachometer-alt text-emerald-600 text-xl"></i>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold text-gray-800">Tableau de bord Stockiste</h1>
                                <p class="text-gray-600 mt-1">Vue d'ensemble du stock et des alertes</p>
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
                        <!-- Total en stock -->
                        <div
                            class="stat-card group bg-gradient-to-br from-white to-blue-50 p-6 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 border border-blue-100 hover:border-blue-200 relative overflow-hidden">
                            <div class="relative z-10">
                                <div class="flex items-center mb-2">
                                    <div
                                        class="stat-icon p-3 bg-gradient-to-br from-blue-100 to-blue-200 rounded-xl shadow-inner group-hover:scale-110 transition-transform duration-300">
                                        <i class="fas fa-boxes text-blue-600 text-xl"></i>
                                    </div>
                                    <div class="ml-4">
                                        <p class="text-gray-600 text-sm font-medium">Total en stock</p>
                                        <p class="text-2xl font-bold text-gray-800 mt-1">
                                            <?php
                                            $total = isset($stats['total_stock']) ? $stats['total_stock'] : 0;
                                            echo is_numeric($total) ? number_format(floatval($total), 0) : '0';
                                            ?>
                                        </p>
                                    </div>
                                </div>
                                <p class="text-xs text-blue-600 font-medium mt-2">
                                    <i class="fas fa-cube mr-1"></i>unités disponibles
                                </p>
                            </div>
                            <div class="absolute bottom-0 right-0 opacity-10">
                                <i class="fas fa-boxes text-blue-400 text-5xl"></i>
                            </div>
                        </div>

                        <!-- Stocks bas -->
                        <div
                            class="stat-card group bg-gradient-to-br from-white to-yellow-50 p-6 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 border border-yellow-100 hover:border-yellow-200 relative overflow-hidden">
                            <div class="relative z-10">
                                <div class="flex items-center mb-2">
                                    <div
                                        class="stat-icon p-3 bg-gradient-to-br from-yellow-100 to-yellow-200 rounded-xl shadow-inner group-hover:scale-110 transition-transform duration-300">
                                        <i class="fas fa-exclamation-circle text-yellow-600 text-xl"></i>
                                    </div>
                                    <div class="ml-4">
                                        <p class="text-gray-600 text-sm font-medium">Stocks bas</p>
                                        <p class="text-2xl font-bold text-gray-800 mt-1">
                                            <?php echo $stats['alertes_stock_bas']; ?>
                                        </p>
                                    </div>
                                </div>
                                <p class="text-xs text-yellow-600 font-medium mt-2">
                                    <i class="fas fa-arrow-down mr-1"></i>besoin de réapprovisionnement
                                </p>
                            </div>
                            <div class="absolute bottom-0 right-0 opacity-10">
                                <i class="fas fa-exclamation-circle text-yellow-400 text-5xl"></i>
                            </div>
                        </div>

                        <!-- Péremption proche -->
                        <div
                            class="stat-card group bg-gradient-to-br from-white to-orange-50 p-6 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 border border-orange-100 hover:border-orange-200 relative overflow-hidden">
                            <div class="relative z-10">
                                <div class="flex items-center mb-2">
                                    <div
                                        class="stat-icon p-3 bg-gradient-to-br from-orange-100 to-orange-200 rounded-xl shadow-inner group-hover:scale-110 transition-transform duration-300">
                                        <i class="fas fa-calendar-times text-orange-600 text-xl"></i>
                                    </div>
                                    <div class="ml-4">
                                        <p class="text-gray-600 text-sm font-medium">Péremption proche</p>
                                        <p class="text-2xl font-bold text-gray-800 mt-1">
                                            <?php echo $stats['alertes_peremption']; ?>
                                        </p>
                                    </div>
                                </div>
                                <p class="text-xs text-orange-600 font-medium mt-2">
                                    <i class="fas fa-clock mr-1"></i>à surveiller
                                </p>
                            </div>
                            <div class="absolute bottom-0 right-0 opacity-10">
                                <i class="fas fa-calendar-times text-orange-400 text-5xl"></i>
                            </div>
                        </div>

                        <!-- Ruptures -->
                        <div
                            class="stat-card group bg-gradient-to-br from-white to-red-50 p-6 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 border border-red-100 hover:border-red-200 relative overflow-hidden">
                            <div class="relative z-10">
                                <div class="flex items-center mb-2">
                                    <div
                                        class="stat-icon p-3 bg-gradient-to-br from-red-100 to-red-200 rounded-xl shadow-inner group-hover:scale-110 transition-transform duration-300">
                                        <i class="fas fa-ban text-red-600 text-xl"></i>
                                    </div>
                                    <div class="ml-4">
                                        <p class="text-gray-600 text-sm font-medium">Ruptures</p>
                                        <p class="text-2xl font-bold text-gray-800 mt-1">
                                            <?php echo $stats['ruptures_stock']; ?>
                                        </p>
                                    </div>
                                </div>
                                <p class="text-xs text-red-600 font-medium mt-2">
                                    <i class="fas fa-times-circle mr-1"></i>réappro urgent
                                </p>
                            </div>
                            <div class="absolute bottom-0 right-0 opacity-10">
                                <i class="fas fa-ban text-red-400 text-5xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Actions rapides -->
                    <div
                        class="bg-gradient-to-br from-white to-emerald-50 rounded-xl shadow-lg p-6 mb-8 border border-emerald-200">
                        <div class="flex items-center mb-6">
                            <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl shadow-sm mr-3">
                                <i class="fas fa-bolt text-emerald-600"></i>
                            </div>
                            <h2 class="text-xl font-bold text-gray-800">Actions rapides</h2>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            <a href="?page=ajouter_lot"
                                class="group relative bg-gradient-to-br from-emerald-50 to-emerald-100 hover:from-emerald-100 hover:to-emerald-200 border border-emerald-200 rounded-xl p-6 text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 overflow-hidden">
                                <div
                                    class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-emerald-400 to-emerald-500 transform scale-x-0 group-hover:scale-x-100 transition-transform duration-300">
                                </div>
                                <div
                                    class="p-3 bg-gradient-to-br from-white to-emerald-50 rounded-lg inline-flex items-center justify-center mb-4 shadow-sm group-hover:shadow transition-shadow duration-300">
                                    <i class="fas fa-plus-circle text-emerald-600 text-2xl"></i>
                                </div>
                                <h3 class="font-bold text-emerald-700 mb-2">Nouveau lot</h3>
                                <p class="text-sm text-emerald-600 opacity-80">Ajouter un lot de stock</p>
                                <div
                                    class="mt-3 text-xs text-emerald-500 group-hover:text-emerald-600 transition-colors duration-200">
                                    <i class="fas fa-arrow-right mr-1"></i> Commencer
                                </div>
                            </a>

                            <a href="?page=inventaire"
                                class="group relative bg-gradient-to-br from-blue-50 to-blue-100 hover:from-blue-100 hover:to-blue-200 border border-blue-200 rounded-xl p-6 text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 overflow-hidden">
                                <div
                                    class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-blue-400 to-blue-500 transform scale-x-0 group-hover:scale-x-100 transition-transform duration-300">
                                </div>
                                <div
                                    class="p-3 bg-gradient-to-br from-white to-blue-50 rounded-lg inline-flex items-center justify-center mb-4 shadow-sm group-hover:shadow transition-shadow duration-300">
                                    <i class="fas fa-clipboard-check text-blue-600 text-2xl"></i>
                                </div>
                                <h3 class="font-bold text-blue-700 mb-2">Inventaire</h3>
                                <p class="text-sm text-blue-600 opacity-80">Vérifier le stock</p>
                                <div
                                    class="mt-3 text-xs text-blue-500 group-hover:text-blue-600 transition-colors duration-200">
                                    <i class="fas fa-arrow-right mr-1"></i> Lancer
                                </div>
                            </a>

                            <a href="?page=alertes"
                                class="group relative bg-gradient-to-br from-yellow-50 to-yellow-100 hover:from-yellow-100 hover:to-yellow-200 border border-yellow-200 rounded-xl p-6 text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 overflow-hidden">
                                <div
                                    class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-yellow-400 to-yellow-500 transform scale-x-0 group-hover:scale-x-100 transition-transform duration-300">
                                </div>
                                <div
                                    class="p-3 bg-gradient-to-br from-white to-yellow-50 rounded-lg inline-flex items-center justify-center mb-4 shadow-sm group-hover:shadow transition-shadow duration-300">
                                    <i class="fas fa-exclamation-triangle text-yellow-600 text-2xl"></i>
                                </div>
                                <h3 class="font-bold text-yellow-700 mb-2">Voir alertes</h3>
                                <p class="text-sm text-yellow-600 opacity-80">Gérer les alertes</p>
                                <div
                                    class="mt-3 text-xs text-yellow-500 group-hover:text-yellow-600 transition-colors duration-200">
                                    <i class="fas fa-arrow-right mr-1"></i> Consulter
                                </div>
                            </a>

                            <a href="?page=rapports&periode=semaine"
                                class="group relative bg-gradient-to-br from-purple-50 to-purple-100 hover:from-purple-100 hover:to-purple-200 border border-purple-200 rounded-xl p-6 text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 overflow-hidden">
                                <div
                                    class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-purple-400 to-purple-500 transform scale-x-0 group-hover:scale-x-100 transition-transform duration-300">
                                </div>
                                <div
                                    class="p-3 bg-gradient-to-br from-white to-purple-50 rounded-lg inline-flex items-center justify-center mb-4 shadow-sm group-hover:shadow transition-shadow duration-300">
                                    <i class="fas fa-chart-bar text-purple-600 text-2xl"></i>
                                </div>
                                <h3 class="font-bold text-purple-700 mb-2">Rapports</h3>
                                <p class="text-sm text-purple-600 opacity-80">Analyser le stock</p>
                                <div
                                    class="mt-3 text-xs text-purple-500 group-hover:text-purple-600 transition-colors duration-200">
                                    <i class="fas fa-arrow-right mr-1"></i> Générer
                                </div>
                            </a>
                        </div>
                    </div>

                    <!-- Produits avec stock bas -->
                    <div
                        class="bg-gradient-to-br from-white to-yellow-50 rounded-xl shadow-lg p-6 border border-yellow-200">
                        <div class="flex items-center justify-between mb-6">
                            <div class="flex items-center">
                                <div class="p-3 bg-gradient-to-br from-yellow-100 to-yellow-200 rounded-xl shadow-sm mr-3">
                                    <i class="fas fa-exclamation-triangle text-yellow-600"></i>
                                </div>
                                <div>
                                    <h2 class="text-xl font-bold text-gray-800">Produits nécessitant attention</h2>
                                    <p class="text-sm text-yellow-600">Stock ≤ 10 unités</p>
                                </div>
                            </div>
                            <a href="?page=alertes"
                                class="text-sm text-emerald-600 hover:text-emerald-700 font-medium flex items-center">
                                Voir toutes les alertes
                                <i class="fas fa-arrow-right ml-1 text-xs"></i>
                            </a>
                        </div>

                        <?php
                        try {
                            $stmt = $pdo->query("
                SELECT p.id, p.nom, p.code_barre, 
                       SUM(l.quantite_actuelle) as quantite_totale,
                       GROUP_CONCAT(DISTINCT l.numero_lot SEPARATOR ', ') as lots
                FROM produits p
                LEFT JOIN lots l ON p.id = l.produit_id AND l.statut = 'en_stock'
                WHERE p.statut = 'actif'
                GROUP BY p.id
                HAVING quantite_totale <= 10 OR quantite_totale IS NULL
                ORDER BY quantite_totale ASC
                LIMIT 10
            ");
                            $produits_bas = $stmt->fetchAll();

                            if (count($produits_bas) > 0):
                                ?>
                                <div class="overflow-hidden rounded-lg border border-yellow-200">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gradient-to-r from-yellow-50 to-yellow-100">
                                            <tr>
                                                <th
                                                    class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                    <div class="flex items-center">
                                                        <i class="fas fa-capsules mr-2 text-gray-400"></i> Produit
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
                                                        <i class="fas fa-box mr-2 text-gray-400"></i> Quantité
                                                    </div>
                                                </th>
                                                <th
                                                    class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                    <div class="flex items-center">
                                                        <i class="fas fa-layer-group mr-2 text-gray-400"></i> Lots
                                                    </div>
                                                </th>
                                                <th
                                                    class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                    <div class="flex items-center">
                                                        <i class="fas fa-cogs mr-2 text-gray-400"></i> Action
                                                    </div>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($produits_bas as $produit): ?>
                                                <tr
                                                    class="hover:bg-gradient-to-r hover:from-yellow-50 hover:to-transparent transition-all duration-200 group">
                                                    <td class="px-6 py-4">
                                                        <div class="flex items-center">
                                                            <div
                                                                class="p-2 bg-gradient-to-br from-yellow-50 to-yellow-100 rounded-lg mr-3 border border-yellow-200">
                                                                <i class="fas fa-pills text-yellow-600 text-sm"></i>
                                                            </div>
                                                            <div
                                                                class="font-medium text-gray-900 group-hover:text-yellow-700 transition-colors">
                                                                <?php echo htmlspecialchars($produit['nom']); ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <code
                                                            class="text-sm text-gray-900 bg-gray-50 px-2 py-1 rounded font-mono border border-gray-200">
                                                                                                                                                                                                                                                                                                            <?php echo htmlspecialchars($produit['code_barre']); ?>
                                                                                                                                                                                                                                                                                                        </code>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <div class="flex items-center">
                                                            <div class="relative">
                                                                <span
                                                                    class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?php echo ($produit['quantite_totale'] ?? 0) <= 3 ? 'bg-gradient-to-r from-red-100 to-red-50 text-red-800 border border-red-200' : 'bg-gradient-to-r from-yellow-100 to-yellow-50 text-yellow-800 border border-yellow-200'; ?>">
                                                                    <i class="fas fa-box mr-1"></i>
                                                                    <?php echo $produit['quantite_totale'] ?? 0; ?> unités
                                                                </span>
                                                                <?php if (($produit['quantite_totale'] ?? 0) <= 3): ?>
                                                                    <div
                                                                        class="absolute -top-1 -right-1 w-2 h-2 bg-red-500 rounded-full animate-pulse">
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <div class="text-sm text-gray-600 max-w-xs">
                                                            <?php if (!empty($produit['lots'])): ?>
                                                                <?php
                                                                $lots = explode(', ', $produit['lots']);
                                                                foreach (array_slice($lots, 0, 2) as $lot):
                                                                    ?>
                                                                    <span
                                                                        class="inline-block px-2 py-1 bg-gray-100 rounded text-xs mr-1 mb-1 border border-gray-200">
                                                                        <?php echo htmlspecialchars($lot); ?>
                                                                    </span>
                                                                <?php endforeach; ?>
                                                                <?php if (count($lots) > 2): ?>
                                                                    <span class="text-xs text-gray-500">+<?php echo count($lots) - 2; ?>
                                                                        autres</span>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="text-gray-400 italic">Aucun lot</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <a href="?page=ajouter_lot&produit_id=<?php echo $produit['id']; ?>"
                                                            class="inline-flex items-center px-3 py-1 bg-gradient-to-r from-emerald-100 to-emerald-50 hover:from-emerald-200 hover:to-emerald-100 text-emerald-700 text-sm font-medium rounded-lg border border-emerald-200 transition-all duration-200 group/action">
                                                            <i
                                                                class="fas fa-plus-circle mr-2 text-xs group-hover/action:scale-110 transition-transform"></i>
                                                            Ajouter lot
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
                                        class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gradient-to-r from-emerald-100 to-emerald-200 mb-4 shadow-sm">
                                        <i class="fas fa-check-circle text-emerald-600 text-2xl"></i>
                                    </div>
                                    <h3 class="text-lg font-medium text-gray-700 mb-2">✅ Aucun produit en stock bas</h3>
                                    <p class="text-gray-500">Tous les stocks sont à un niveau satisfaisant.</p>
                                </div>
                            <?php endif;
                        } catch (Exception $e) {
                            echo '<div class="text-center py-10">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gradient-to-r from-red-100 to-red-200 mb-4 shadow-sm">
                        <i class="fas fa-exclamation-circle text-red-600 text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-700 mb-2">Erreur de chargement</h3>
                    <p class="text-gray-500">Impossible de charger les données des stocks bas.</p>
                </div>';
                        }
                        ?>
                    </div>
                </div>

            <?php elseif ($current_page == 'lots'): ?>
                <!-- ========== LISTE DES LOTS ========== -->
                <div class="mb-8">
                    <!-- En-tête avec statistiques -->
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-8">
                        <div class="flex items-center">
                            <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl shadow-sm mr-4">
                                <i class="fas fa-boxes text-emerald-600 text-xl"></i>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold text-gray-800">Gestion des Lots</h1>
                                <p class="text-gray-600 mt-1">Liste de tous les lots en stock</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-4">
                            <div
                                class="text-center px-4 py-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl border border-blue-200">
                                <div class="text-2xl font-bold text-blue-700"><?php echo count($lots); ?></div>
                                <div class="text-xs text-blue-600 font-medium">Lots</div>
                            </div>
                            <a href="?page=ajouter_lot"
                                class="group relative bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-5 py-3 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5">
                                <i class="fas fa-plus mr-2 group-hover:rotate-90 transition-transform"></i>
                                Nouveau lot
                                <div
                                    class="absolute inset-0 rounded-xl border border-emerald-400 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                </div>
                            </a>
                        </div>
                    </div>

                    <?php if (count($lots) > 0): ?>
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg overflow-hidden border border-gray-200">
                            <!-- Filtres et actions -->
                            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100">
                                <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                                    <div class="flex items-center space-x-4">
                                        <div class="flex items-center">
                                            <i class="fas fa-filter text-emerald-600 mr-2"></i>
                                            <span class="font-medium text-gray-700"><?php echo count($lots); ?> lot(s)
                                                trouvé(s)</span>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <span
                                                class="px-2 py-1 bg-gradient-to-r from-emerald-100 to-emerald-50 text-emerald-800 rounded-full text-xs font-medium border border-emerald-200">
                                                <?php echo array_sum(array_column($lots, 'quantite_actuelle')); ?> unités total
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <div class="relative">
                                            <input type="text" placeholder="Rechercher un lot..."
                                                class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-transparent text-sm">
                                            <i
                                                class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                        </div>
                                        <button class="p-2 border border-gray-300 rounded-lg hover:bg-white transition-colors">
                                            <i class="fas fa-sort-amount-down text-gray-600"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Tableau des lots -->
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead>
                                        <tr class="bg-gradient-to-r from-gray-50 to-gray-100">
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-hashtag mr-2 text-gray-400"></i> Lot
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
                                                    <i class="fas fa-box mr-2 text-gray-400"></i> Quantité
                                                </div>
                                            </th>
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-calendar-times mr-2 text-gray-400"></i> Expiration
                                                </div>
                                            </th>
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-clock mr-2 text-gray-400"></i> Jours restants
                                                </div>
                                            </th>
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-dollar-sign mr-2 text-gray-400"></i> Prix d'achat
                                                </div>
                                            </th>
                                           
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($lots as $lot):
                                            $jours_restants = $lot['jours_restants'] ?? joursAvantPeremption($lot['date_expiration']);
                                            $jours_class = '';
                                            if ($jours_restants <= 0) {
                                                $jours_class = 'bg-gradient-to-r from-red-100 to-red-50 text-red-800 border-red-200';
                                                $texte_jours = 'Expiré';
                                                $jours_icon = 'fas fa-times-circle';
                                            } elseif ($jours_restants <= 30) {
                                                $jours_class = 'bg-gradient-to-r from-orange-100 to-orange-50 text-orange-800 border-orange-200';
                                                $texte_jours = $jours_restants . ' jours';
                                                $jours_icon = 'fas fa-exclamation-triangle';
                                            } else {
                                                $jours_class = 'bg-gradient-to-r from-green-100 to-green-50 text-green-800 border-green-200';
                                                $texte_jours = $jours_restants . ' jours';
                                                $jours_icon = 'fas fa-check-circle';
                                            }

                                            // Classe quantité
                                            $quantite_class = $lot['quantite_actuelle'] <= 10 ?
                                                'bg-gradient-to-r from-red-100 to-red-50 text-red-800 border-red-200' :
                                                'bg-gradient-to-r from-green-100 to-green-50 text-green-800 border-green-200';
                                            ?>
                                            <tr
                                                class="hover:bg-gradient-to-r hover:from-emerald-50 hover:to-transparent transition-all duration-200 group">
                                                <!-- Numéro de lot -->
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center">
                                                        <div
                                                            class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg mr-3 border border-blue-200">
                                                            <i class="fas fa-tag text-blue-600 text-sm"></i>
                                                        </div>
                                                        <div>
                                                            <code class="text-sm font-medium text-gray-900 font-mono">
                                                                                                                                                                                                                                    <?php echo htmlspecialchars($lot['numero_lot']); ?>
                                                                                                                                                                                                                                </code>
                                                            <div class="text-xs text-gray-500 mt-1">
                                                                <i class="fas fa-calendar-plus mr-1"></i>
                                                                <?php echo formatDate($lot['date_reception']); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                <!-- Produit -->
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center">
                                                        <div
                                                            class="p-2 bg-gradient-to-br from-emerald-50 to-emerald-100 rounded-lg mr-3 border border-emerald-200">
                                                            <i class="fas fa-pills text-emerald-600 text-sm"></i>
                                                        </div>
                                                        <div>
                                                            <div
                                                                class="font-semibold text-gray-900 group-hover:text-emerald-700 transition-colors">
                                                                <?php echo htmlspecialchars($lot['produit_nom']); ?>
                                                            </div>
                                                            <div class="text-xs text-gray-500 mt-1">
                                                                <i class="fas fa-barcode mr-1"></i>
                                                                <?php echo htmlspecialchars($lot['code_barre']); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                <!-- Quantité -->
                                                <td class="px-6 py-4">
                                                    <div class="flex flex-col space-y-2">
                                                        <div class="flex items-center space-x-2">
                                                            <span
                                                                class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?php echo $quantite_class; ?> border">
                                                                <i class="fas fa-box mr-1 text-xs"></i>
                                                                <?php echo $lot['quantite_actuelle']; ?> actuel
                                                            </span>
                                                            <span class="text-xs text-gray-500">
                                                                / <?php echo $lot['quantite_initiale']; ?> initial
                                                            </span>
                                                        </div>
                                                        <!-- Barre de progression -->
                                                        <div class="w-full bg-gray-200 rounded-full h-1.5">
                                                            <?php
                                                            $pourcentage = ($lot['quantite_actuelle'] / $lot['quantite_initiale']) * 100;
                                                            $couleur_barre = $pourcentage <= 25 ? 'bg-red-500' : ($pourcentage <= 50 ? 'bg-yellow-500' : 'bg-green-500');
                                                            ?>
                                                            <div class="h-1.5 rounded-full <?php echo $couleur_barre; ?>"
                                                                style="width: <?php echo $pourcentage; ?>%"></div>
                                                        </div>
                                                    </div>
                                                </td>

                                                <!-- Date d'expiration -->
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center text-sm text-gray-900">
                                                        <i class="far fa-calendar-alt text-gray-400 mr-2"></i>
                                                        <?php echo formatDate($lot['date_expiration']); ?>
                                                    </div>
                                                    <?php if ($jours_restants <= 30): ?>
                                                        <div
                                                            class="text-xs <?php echo $jours_restants <= 0 ? 'text-red-600' : 'text-orange-600'; ?> mt-1">
                                                            <i class="fas fa-clock mr-1"></i>
                                                            <?php echo $jours_restants <= 0 ? 'Expiré' : 'À surveiller'; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Jours restants -->
                                                <td class="px-6 py-4">
                                                    <div
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?php echo $jours_class; ?> border">
                                                        <i class="<?php echo $jours_icon; ?> mr-1 text-xs"></i>
                                                        <?php echo $texte_jours; ?>
                                                    </div>
                                                    <?php if ($jours_restants <= 15): ?>
                                                        <div class="text-xs text-gray-500 mt-1">
                                                            <i class="fas fa-running mr-1"></i>
                                                            Action requise
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Prix d'achat -->
                                                <td class="px-6 py-4">
                                                    <div class="text-sm font-bold text-gray-900">
                                                        <?php
                                                        $prix = $lot['prix_achat'];
                                                        // Nettoyer la valeur (enlever les espaces, virgules, etc.)
                                                        $prix = str_replace(',', '.', $prix);
                                                        $prix = preg_replace('/[^0-9.]/', '', $prix);
                                                        echo number_format(floatval($prix), 2);
                                                        ?> $
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        Unitaire:
                                                        <?php echo number_format($lot['prix_achat'] / $lot['quantite_initiale'], 2); ?>
                                                        $
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
                                    <?php
                                    $lots_expires = count(array_filter($lots, function ($lot) {
                                        $jours = joursAvantPeremption($lot['date_expiration']);
                                        return $jours <= 0;
                                    }));
                                    $lots_proche = count(array_filter($lots, function ($lot) {
                                        $jours = joursAvantPeremption($lot['date_expiration']);
                                        return $jours > 0 && $jours <= 30;
                                    }));
                                    $lots_bas = count(array_filter($lots, function ($lot) {
                                        return $lot['quantite_actuelle'] <= 10;
                                    }));
                                    ?>
                                    <div class="text-center">
                                        <div class="text-lg font-bold text-emerald-700"><?php echo count($lots); ?></div>
                                        <div class="text-sm text-gray-600">Lots total</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-lg font-bold text-orange-700"><?php echo $lots_proche; ?></div>
                                        <div class="text-sm text-gray-600">Expire bientôt</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-lg font-bold text-red-700"><?php echo $lots_expires; ?></div>
                                        <div class="text-sm text-gray-600">Expirés</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-lg font-bold text-yellow-700"><?php echo $lots_bas; ?></div>
                                        <div class="text-sm text-gray-600">Stock bas</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Aucun lot -->
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg p-16 text-center border border-gray-200">
                            <div
                                class="inline-flex items-center justify-center w-24 h-24 rounded-full bg-gradient-to-r from-gray-100 to-gray-200 mb-8 shadow-sm">
                                <i class="fas fa-box-open text-gray-600 text-4xl"></i>
                            </div>
                            <h3 class="text-3xl font-bold text-gray-800 mb-4">Aucun lot en stock</h3>
                            <p class="text-gray-600 text-lg mb-10 max-w-md mx-auto">
                                Commencez par ajouter un nouveau lot pour gérer votre stock de produits.
                            </p>
                            <div class="flex flex-col sm:flex-row justify-center gap-6">
                                <a href="?page=ajouter_lot"
                                    class="group bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-8 py-4 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5 text-lg">
                                    <i class="fas fa-plus mr-3 group-hover:rotate-90 transition-transform"></i>
                                    Ajouter un premier lot
                                </a>
                                <a href="?page=dashboard"
                                    class="group bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-8 py-4 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5 text-lg">
                                    <i class="fas fa-tachometer-alt mr-3 group-hover:scale-110 transition-transform"></i>
                                    Tableau de bord
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($current_page == 'ajouter_lot'): ?>
                <!-- ========== AJOUTER UN LOT ========== -->
                <div class="mb-8">
                    <!-- En-tête -->
                    <div class="flex items-center mb-8">
                        <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl shadow-sm mr-4">
                            <i class="fas fa-plus-circle text-emerald-600 text-xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Ajouter un nouveau lot</h1>
                            <p class="text-gray-600 mt-1">Renseignez les informations du lot de stock</p>
                        </div>
                    </div>

                    <div
                        class="bg-gradient-to-br from-white to-emerald-50 rounded-xl shadow-lg p-8 border border-emerald-200">
                        <form method="POST" action="" class="space-y-8">
                            <input type="hidden" name="action" value="ajouter_lot">

                            <!-- Section Informations du lot -->
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                                <!-- Informations principales -->
                                <div class="space-y-6">
                                    <div class="flex items-center pb-3 border-b border-gray-200">
                                        <div class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg mr-3">
                                            <i class="fas fa-box text-blue-600"></i>
                                        </div>
                                        <h3 class="text-lg font-semibold text-gray-800">Informations principales</h3>
                                    </div>

                                    <!-- Produit -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3" for="produit_id">
                                            <span class="text-red-500">*</span> Produit
                                        </label>
                                        <div class="relative">
                                            <select id="produit_id" name="produit_id" required
                                                class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow appearance-none bg-white">
                                                <option value="" disabled selected>Sélectionnez un produit</option>
                                                <?php foreach ($produits as $produit): ?>
                                                    <option value="<?php echo $produit['id']; ?>" class="py-2">
                                                        <span
                                                            class="font-medium"><?php echo htmlspecialchars($produit['nom']); ?></span>
                                                        <span class="text-gray-500 ml-2">
                                                            (<?php echo htmlspecialchars($produit['code_barre']); ?>)
                                                        </span>
                                                        <?php if (!empty($produit['categorie_nom'])): ?>
                                                            <span
                                                                class="inline-block ml-2 px-2 py-0.5 bg-gray-100 text-gray-700 text-xs rounded border border-gray-300">
                                                                <?php echo htmlspecialchars($produit['categorie_nom']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                <i class="fas fa-capsules"></i>
                                            </div>
                                            <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                <i class="fas fa-chevron-down"></i>
                                            </div>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-2">Produit auquel ce lot sera rattaché</p>
                                    </div>

                                    <!-- Numéro de lot -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3" for="numero_lot">
                                            <span class="text-red-500">*</span> Numéro de lot
                                        </label>
                                        <div class="relative">
                                            <input type="text" id="numero_lot" name="numero_lot" required
                                                placeholder="Ex: LOT-2024-001"
                                                class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow">
                                            <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                <i class="fas fa-hashtag"></i>
                                            </div>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-2">Identifiant unique du lot</p>
                                    </div>

                                    <!-- Quantité initiale -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3"
                                            for="quantite_initiale">
                                            <span class="text-red-500">*</span> Quantité initiale
                                        </label>
                                        <div class="relative">
                                            <input type="number" id="quantite_initiale" name="quantite_initiale" required
                                                min="1" value="100"
                                                class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow">
                                            <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                <i class="fas fa-boxes"></i>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-4 mt-2">
                                            <button type="button"
                                                onclick="document.getElementById('quantite_initiale').value = 50"
                                                class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded border border-gray-300">
                                                50
                                            </button>
                                            <button type="button"
                                                onclick="document.getElementById('quantite_initiale').value = 100"
                                                class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded border border-gray-300">
                                                100
                                            </button>
                                            <button type="button"
                                                onclick="document.getElementById('quantite_initiale').value = 500"
                                                class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded border border-gray-300">
                                                500
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Informations complémentaires -->
                                <div class="space-y-6">
                                    <div class="flex items-center pb-3 border-b border-gray-200">
                                        <div class="p-2 bg-gradient-to-br from-orange-50 to-orange-100 rounded-lg mr-3">
                                            <i class="fas fa-calendar-alt text-orange-600"></i>
                                        </div>
                                        <h3 class="text-lg font-semibold text-gray-800">Dates & Prix</h3>
                                    </div>

                                    <!-- Date d'expiration -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3" for="date_expiration">
                                            <span class="text-red-500">*</span> Date d'expiration
                                        </label>
                                        <div class="relative">
                                            <input type="date" id="date_expiration" name="date_expiration" required
                                                min="<?php echo date('Y-m-d'); ?>"
                                                class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow">
                                            <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                <i class="fas fa-calendar-times"></i>
                                            </div>
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            Minimum: <?php echo date('d/m/Y', strtotime('+30 days')); ?> (30 jours)
                                        </div>
                                    </div>

                                    <!-- Prix d'achat -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3" for="prix_achat">
                                            <span class="text-red-500">*</span> Prix d'achat unitaire
                                        </label>
                                        <div class="relative">
                                            <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                <i class="fas fa-dollar-sign"></i>
                                            </div>
                                            <input type="number" id="prix_achat" name="prix_achat" required min="0"
                                                step="0.01" value="0.00"
                                                class="w-full px-4 py-3 pl-11 pr-16 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow">
                                            <div class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                                <span class="text-gray-500 font-medium">$</span>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-4 mt-2">
                                            <button type="button"
                                                onclick="document.getElementById('prix_achat').value = 1.50"
                                                class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded border border-gray-300">
                                                1.50 $
                                            </button>
                                            <button type="button"
                                                onclick="document.getElementById('prix_achat').value = 2.99"
                                                class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded border border-gray-300">
                                                2.99 $
                                            </button>
                                            <button type="button"
                                                onclick="document.getElementById('prix_achat').value = 5.50"
                                                class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded border border-gray-300">
                                                5.50 $
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Date de réception -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 mb-3" for="date_reception">
                                            Date de réception
                                        </label>
                                        <div class="relative">
                                            <input type="date" id="date_reception" name="date_reception"
                                                value="<?php echo date('Y-m-d'); ?>"
                                                class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow">
                                            <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                <i class="fas fa-calendar-check"></i>
                                            </div>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-2">Date à laquelle le lot est reçu en stock</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Aperçu du lot -->
                            <div
                                class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl p-6 border border-gray-300 mt-6">
                                <div class="flex items-center mb-4">
                                    <div class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg mr-3">
                                        <i class="fas fa-eye text-blue-600"></i>
                                    </div>
                                    <h4 class="text-lg font-semibold text-gray-800">Aperçu du lot</h4>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="bg-white p-4 rounded-lg border border-gray-200">
                                        <div class="text-sm text-gray-600 mb-1">Identifiant</div>
                                        <div id="lot-preview-number" class="font-medium text-gray-900">-</div>
                                    </div>
                                    <div class="bg-white p-4 rounded-lg border border-gray-200">
                                        <div class="text-sm text-gray-600 mb-1">Produit</div>
                                        <div id="lot-preview-product" class="font-medium text-gray-900">Non sélectionné
                                        </div>
                                    </div>
                                    <div class="bg-white p-4 rounded-lg border border-gray-200">
                                        <div class="text-sm text-gray-600 mb-1">Valeur totale</div>
                                        <div id="lot-preview-value" class="font-medium text-gray-900">0.00 $</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Boutons d'action -->
                            <div class="pt-6 border-t border-gray-200">
                                <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-4">
                                    <a href="?page=lots"
                                        class="group px-6 py-3 border border-gray-300 rounded-xl text-gray-700 hover:bg-gray-50 hover:shadow transition-all duration-200 text-center font-medium">
                                        <i class="fas fa-times mr-2 group-hover:rotate-90 transition-transform"></i>
                                        Annuler
                                    </a>
                                    <button type="submit"
                                        class="group relative bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-8 py-3 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5">
                                        <i class="fas fa-save mr-2 group-hover:rotate-12 transition-transform"></i>
                                        Enregistrer le lot
                                        <div
                                            class="absolute inset-0 rounded-xl border border-emerald-400 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                        </div>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Script pour l'aperçu en temps réel -->
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        const numeroLotInput = document.getElementById('numero_lot');
                        const produitSelect = document.getElementById('produit_id');
                        const quantiteInput = document.getElementById('quantite_initiale');
                        const prixInput = document.getElementById('prix_achat');

                        const updateLotPreview = () => {
                            // Aperçu numéro de lot
                            document.getElementById('lot-preview-number').textContent =
                                numeroLotInput.value || '-';

                            // Aperçu produit
                            const selectedOption = produitSelect.options[produitSelect.selectedIndex];
                            document.getElementById('lot-preview-product').textContent =
                                selectedOption.textContent || 'Non sélectionné';

                            // Aperçu valeur totale
                            const quantite = parseFloat(quantiteInput.value) || 0;
                            const prix = parseFloat(prixInput.value) || 0;
                            const totalValue = quantite * prix;
                            document.getElementById('lot-preview-value').textContent =
                                totalValue.toFixed(2) + ' $';
                        };

                        // Écouteurs d'événements
                        [numeroLotInput, produitSelect, quantiteInput, prixInput].forEach(input => {
                            input.addEventListener('input', updateLotPreview);
                            input.addEventListener('change', updateLotPreview);
                        });

                        // Initialiser l'aperçu
                        updateLotPreview();

                        // Générer un numéro de lot automatique
                        const generateLotNumber = () => {
                            const today = new Date();
                            const year = today.getFullYear();
                            const month = String(today.getMonth() + 1).padStart(2, '0');
                            const random = Math.floor(Math.random() * 999).toString().padStart(3, '0');
                            const lotNumber = `LOT-${year}-${month}-${random}`;
                            numeroLotInput.value = lotNumber;
                            updateLotPreview();
                        };

                        // Bouton pour générer un numéro de lot
                        const generateButton = document.createElement('button');
                        generateButton.type = 'button';
                        generateButton.innerHTML = '<i class="fas fa-magic mr-1"></i>Générer';
                        generateButton.className = 'text-xs px-2 py-1 bg-emerald-100 hover:bg-emerald-200 text-emerald-700 rounded border border-emerald-300 mt-1';
                        generateButton.addEventListener('click', generateLotNumber);

                        // Ajouter le bouton après le champ numéro de lot
                        numeroLotInput.parentNode.appendChild(generateButton);
                    });
                </script>

            <?php elseif ($current_page == 'alertes'): ?>
                <!-- ========== ALERTES STOCK ========== -->
                <div class="mb-8">
                    <!-- En-tête avec statistiques -->
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center">
                            <div class="p-3 bg-gradient-to-br from-red-100 to-red-200 rounded-xl shadow-sm mr-4">
                                <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold text-gray-800">Alertes Stock</h1>
                                <p class="text-gray-600 mt-1">Gérez les alertes de stock et de péremption</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-4">
                            <?php
                            $alertes_non_lues = count(array_filter($alertes, fn($a) => $a['statut'] == 'non_lu'));
                            $alertes_critiques = count(array_filter($alertes, fn($a) => $a['niveau'] == 'critique'));
                            ?>
                            <?php if ($alertes_non_lues > 0): ?>
                                <span
                                    class="px-3 py-1 bg-gradient-to-r from-red-100 to-red-50 text-red-800 rounded-full text-sm font-semibold border border-red-200 animate-pulse">
                                    <i class="fas fa-bell mr-1"></i>
                                    <?php echo $alertes_non_lues; ?> non lue(s)
                                </span>
                            <?php endif; ?>
                            <div class="flex items-center space-x-2">
                                <button onclick="markAllAsRead()"
                                    class="group bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 text-sm">
                                    <i class="fas fa-check-double mr-2 group-hover:scale-110 transition-transform"></i>
                                    Tout marquer comme lu
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Statistiques des alertes -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                        <?php
                        $stock_bas = count(array_filter($alertes, fn($a) => $a['type_alerte'] == 'stock_bas'));
                        $peremption = count(array_filter($alertes, fn($a) => $a['type_alerte'] == 'peremption'));
                        $ruptures = count(array_filter($alertes, fn($a) => $a['type_alerte'] == 'rupture_stock'));
                        ?>

                        <div class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow p-5 border border-gray-200">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-lg font-bold text-gray-800"><?php echo count($alertes); ?></div>
                                    <div class="text-sm text-gray-600">Total alertes</div>
                                </div>
                                <div class="p-2 bg-gradient-to-br from-gray-100 to-gray-200 rounded-lg">
                                    <i class="fas fa-bell text-gray-600"></i>
                                </div>
                            </div>
                        </div>

                        <div
                            class="bg-gradient-to-br from-white to-yellow-50 rounded-xl shadow p-5 border border-yellow-200">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-lg font-bold text-yellow-800"><?php echo $stock_bas; ?></div>
                                    <div class="text-sm text-yellow-600">Stocks bas</div>
                                </div>
                                <div class="p-2 bg-gradient-to-br from-yellow-100 to-yellow-200 rounded-lg">
                                    <i class="fas fa-exclamation-circle text-yellow-600"></i>
                                </div>
                            </div>
                        </div>

                        <div
                            class="bg-gradient-to-br from-white to-orange-50 rounded-xl shadow p-5 border border-orange-200">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-lg font-bold text-orange-800"><?php echo $peremption; ?></div>
                                    <div class="text-sm text-orange-600">Péremption</div>
                                </div>
                                <div class="p-2 bg-gradient-to-br from-orange-100 to-orange-200 rounded-lg">
                                    <i class="fas fa-calendar-times text-orange-600"></i>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-white to-red-50 rounded-xl shadow p-5 border border-red-200">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-lg font-bold text-red-800"><?php echo $ruptures; ?></div>
                                    <div class="text-sm text-red-600">Ruptures</div>
                                </div>
                                <div class="p-2 bg-gradient-to-br from-red-100 to-red-200 rounded-lg">
                                    <i class="fas fa-ban text-red-600"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (count($alertes) > 0): ?>
                        <!-- Filtres des alertes -->
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg p-4 mb-6 border border-gray-200">
                            <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                                <div class="flex items-center space-x-4">
                                    <div class="flex items-center">
                                        <i class="fas fa-filter text-emerald-600 mr-2"></i>
                                        <span class="font-medium text-gray-700">Filtrer par :</span>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <button onclick="filterAlerts('all')"
                                            class="px-3 py-1 bg-gradient-to-r from-emerald-100 to-emerald-50 text-emerald-800 rounded-full text-sm font-medium border border-emerald-200 hover:shadow transition-all">
                                            Toutes
                                        </button>
                                        <button onclick="filterAlerts('stock_bas')"
                                            class="px-3 py-1 bg-gradient-to-r from-yellow-100 to-yellow-50 text-yellow-800 rounded-full text-sm font-medium border border-yellow-200 hover:shadow transition-all">
                                            Stocks bas
                                        </button>
                                        <button onclick="filterAlerts('peremption')"
                                            class="px-3 py-1 bg-gradient-to-r from-orange-100 to-orange-50 text-orange-800 rounded-full text-sm font-medium border border-orange-200 hover:shadow transition-all">
                                            Péremption
                                        </button>
                                        <button onclick="filterAlerts('rupture_stock')"
                                            class="px-3 py-1 bg-gradient-to-r from-red-100 to-red-50 text-red-800 rounded-full text-sm font-medium border border-red-200 hover:shadow transition-all">
                                            Ruptures
                                        </button>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <button onclick="filterAlerts('non_lu')"
                                        class="px-3 py-1 bg-gradient-to-r from-red-100 to-red-50 text-red-800 rounded-full text-sm font-medium border border-red-200 hover:shadow transition-all flex items-center">
                                        <i class="fas fa-circle text-red-500 mr-1 text-xs"></i>
                                        Non lues
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Liste des alertes -->
                        <div class="space-y-4" id="alerts-container">
                            <?php foreach ($alertes as $alerte):
                                $bg_class = '';
                                $icon_class = '';
                                $border_class = '';

                                if ($alerte['niveau'] == 'critique') {
                                    $bg_class = 'bg-gradient-to-br from-red-50 to-red-100';
                                    $border_class = 'border-red-200';
                                    $icon_class = 'fas fa-fire text-red-600';
                                } elseif ($alerte['niveau'] == 'moyen') {
                                    $bg_class = 'bg-gradient-to-br from-orange-50 to-orange-100';
                                    $border_class = 'border-orange-200';
                                    $icon_class = 'fas fa-exclamation-triangle text-orange-600';
                                } else {
                                    $bg_class = 'bg-gradient-to-br from-yellow-50 to-yellow-100';
                                    $border_class = 'border-yellow-200';
                                    $icon_class = 'fas fa-exclamation-circle text-yellow-600';
                                }

                                $type_icon = '';
                                if ($alerte['type_alerte'] == 'stock_bas') {
                                    $type_icon = 'fas fa-arrow-down text-yellow-600';
                                } elseif ($alerte['type_alerte'] == 'peremption') {
                                    $type_icon = 'fas fa-clock text-orange-600';
                                } else {
                                    $type_icon = 'fas fa-times-circle text-red-600';
                                }
                                ?>
                                <div class="<?php echo $bg_class; ?> rounded-xl shadow-lg p-5 border <?php echo $border_class; ?> alert-card"
                                    data-type="<?php echo $alerte['type_alerte']; ?>"
                                    data-status="<?php echo $alerte['statut']; ?>">
                                    <div class="flex flex-col md:flex-row justify-between items-start gap-4">
                                        <div class="flex-1">
                                            <!-- En-tête de l'alerte -->
                                            <div class="flex items-center mb-3">
                                                <div
                                                    class="p-2 bg-gradient-to-br from-white to-white/80 rounded-lg mr-3 border border-gray-300">
                                                    <i class="<?php echo $icon_class; ?>"></i>
                                                </div>
                                                <div class="flex-1">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <h3 class="font-bold text-gray-800 text-lg">
                                                            <?php echo htmlspecialchars($alerte['type_alerte_text']); ?>
                                                        </h3>
                                                        <span
                                                            class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold 
                                            <?php echo $alerte['statut'] == 'non_lu' ?
                                                'bg-gradient-to-r from-red-100 to-red-50 text-red-800 border border-red-200' :
                                                'bg-gradient-to-r from-green-100 to-green-50 text-green-800 border border-green-200'; ?>">
                                                            <i
                                                                class="fas fa-circle <?php echo $alerte['statut'] == 'non_lu' ? 'text-red-500' : 'text-green-500'; ?> mr-1 text-xs"></i>
                                                            <?php echo $alerte['statut'] == 'non_lu' ? 'NON LU' : 'LU'; ?>
                                                        </span>
                                                        <?php if ($alerte['niveau'] == 'critique'): ?>
                                                            <span
                                                                class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-red-100 to-red-50 text-red-800 border border-red-200 animate-pulse">
                                                                <i class="fas fa-exclamation mr-1"></i> CRITIQUE
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="flex items-center mt-1 text-sm text-gray-600">
                                                        <i class="<?php echo $type_icon; ?> mr-1"></i>
                                                        <?php echo $alerte['type_alerte'] == 'stock_bas' ? 'Stock insuffisant' :
                                                            ($alerte['type_alerte'] == 'peremption' ? 'Péremption proche' : 'Rupture de stock'); ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Détails de l'alerte -->
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                                <div class="bg-white/50 p-3 rounded-lg border border-gray-200">
                                                    <div class="text-sm text-gray-600 mb-1">
                                                        <i class="fas fa-capsules mr-1"></i> Produit concerné
                                                    </div>
                                                    <div class="font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($alerte['produit_nom']); ?>
                                                    </div>
                                                    <?php if (!empty($alerte['code_barre'])): ?>
                                                        <div class="text-xs text-gray-500 mt-1">
                                                            <i class="fas fa-barcode mr-1"></i>
                                                            <?php echo htmlspecialchars($alerte['code_barre']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="bg-white/50 p-3 rounded-lg border border-gray-200">
                                                    <div class="text-sm text-gray-600 mb-1">
                                                        <i class="fas fa-tag mr-1"></i> Informations lot
                                                    </div>
                                                    <div class="font-medium text-gray-900">
                                                        <?php echo !empty($alerte['numero_lot']) ? htmlspecialchars($alerte['numero_lot']) : 'N/A'; ?>
                                                    </div>
                                                    <?php if (!empty($alerte['quantite_restante'])): ?>
                                                        <div class="text-xs text-gray-500 mt-1">
                                                            <i class="fas fa-box mr-1"></i>
                                                            <?php echo $alerte['quantite_restante']; ?> unités restantes
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Message détaillé -->
                                            <div class="bg-white/70 p-4 rounded-lg border border-gray-200 mb-4">
                                                <div class="text-sm text-gray-600 mb-2">
                                                    <i class="fas fa-comment-alt mr-1"></i> Message d'alerte
                                                </div>
                                                <p class="text-gray-800">
                                                    <?php echo htmlspecialchars($alerte['message']); ?>
                                                </p>
                                            </div>

                                            <!-- Date et info -->
                                            <div class="flex items-center justify-between text-sm text-gray-500">
                                                <div class="flex items-center">
                                                    <i class="far fa-clock mr-2"></i>
                                                    <?php echo formatDate($alerte['created_at']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    ID: #<?php echo str_pad((string) $alerte['id'], 4, '0', STR_PAD_LEFT); ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Actions -->
                                        <div class="flex flex-col space-y-2 min-w-[160px]">
                                            <?php if ($alerte['statut'] == 'non_lu'): ?>
                                                <form method="POST" action="" class="w-full">
                                                    <input type="hidden" name="action" value="marquer_lu">
                                                    <input type="hidden" name="alerte_id" value="<?php echo $alerte['id']; ?>">
                                                    <button type="submit"
                                                        class="group w-full bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 text-sm flex items-center justify-center">
                                                        <i
                                                            class="fas fa-check-circle mr-2 group-hover:scale-110 transition-transform"></i>
                                                        Marquer lu
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($alerte['produit_id']): ?>
                                                <a href="?page=ajouter_lot&produit_id=<?php echo $alerte['produit_id']; ?>"
                                                    class="group w-full bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 text-sm flex items-center justify-center">
                                                    <i class="fas fa-plus-circle mr-2 group-hover:scale-110 transition-transform"></i>
                                                    Ajouter lot
                                                </a>
                                            <?php endif; ?>

                                            <a href="?page=lots"
                                                class="group w-full bg-gradient-to-r from-gray-100 to-gray-200 hover:from-gray-200 hover:to-gray-300 text-gray-700 px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 text-sm flex items-center justify-center border border-gray-300">
                                                <i class="fas fa-boxes mr-2 group-hover:scale-110 transition-transform"></i>
                                                Voir lots
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <!-- Aucune alerte -->
                        <div
                            class="bg-gradient-to-br from-white to-emerald-50 rounded-2xl shadow-lg p-16 text-center border border-emerald-200">
                            <div
                                class="inline-flex items-center justify-center w-24 h-24 rounded-full bg-gradient-to-r from-emerald-100 to-emerald-200 mb-8 shadow-sm">
                                <i class="fas fa-check-circle text-emerald-600 text-4xl"></i>
                            </div>
                            <h3 class="text-3xl font-bold text-gray-800 mb-4">Aucune alerte active</h3>
                            <p class="text-gray-600 text-lg mb-10 max-w-md mx-auto">
                                Toutes les alertes sont traitées et votre stock est optimal.
                            </p>
                            <div class="flex flex-col sm:flex-row justify-center gap-6">
                                <a href="?page=dashboard"
                                    class="group bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-8 py-4 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5 text-lg">
                                    <i class="fas fa-tachometer-alt mr-3 group-hover:scale-110 transition-transform"></i>
                                    Tableau de bord
                                </a>
                                <a href="?page=lots"
                                    class="group bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-8 py-4 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5 text-lg">
                                    <i class="fas fa-boxes mr-3 group-hover:scale-110 transition-transform"></i>
                                    Voir les lots
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Script de filtrage -->
                <script>
                    function filterAlerts(filter) {
                        const alerts = document.querySelectorAll('.alert-card');
                        alerts.forEach(alert => {
                            const type = alert.getAttribute('data-type');
                            const status = alert.getAttribute('data-status');

                            let show = false;

                            switch (filter) {
                                case 'all':
                                    show = true;
                                    break;
                                case 'stock_bas':
                                    show = type === 'stock_bas';
                                    break;
                                case 'peremption':
                                    show = type === 'peremption';
                                    break;
                                case 'rupture_stock':
                                    show = type === 'rupture_stock';
                                    break;
                                case 'non_lu':
                                    show = status === 'non_lu';
                                    break;
                                default:
                                    show = true;
                            }

                            alert.style.display = show ? 'block' : 'none';
                            alert.style.opacity = show ? '1' : '0';
                            alert.style.transform = show ? 'translateY(0)' : 'translateY(-10px)';
                            alert.style.transition = 'all 0.3s ease';
                        });
                    }

                    function markAllAsRead() {
                        if (confirm('Marquer toutes les alertes comme lues ?')) {
                            const forms = document.querySelectorAll('form[action*="marquer_lu"]');
                            forms.forEach(form => form.submit());
                        }
                    }
                </script>

            <?php elseif ($current_page == 'mouvements'): ?>
                <!-- ========== HISTORIQUE DES MOUVEMENTS ========== -->
                <div class="mb-8">
                    <!-- En-tête avec statistiques -->
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center">
                            <div class="p-3 bg-gradient-to-br from-blue-100 to-blue-200 rounded-xl shadow-sm mr-4">
                                <i class="fas fa-history text-blue-600 text-xl"></i>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold text-gray-800">Historique des Mouvements</h1>
                                <p class="text-gray-600 mt-1">Suivi des entrées et sorties de stock</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-4">
                            <div
                                class="text-center px-4 py-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl border border-blue-200">
                                <div class="text-2xl font-bold text-blue-700"><?php echo count($mouvements); ?></div>
                                <div class="text-xs text-blue-600 font-medium">Mouvements</div>
                            </div>
                            <button onclick="exportToCSV()"
                                class="group bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200">
                                <i class="fas fa-download mr-2 group-hover:scale-110 transition-transform"></i>
                                Exporter CSV
                            </button>
                        </div>
                    </div>

                    <!-- Statistiques des mouvements -->
                    <?php if (count($mouvements) > 0): ?>
                        <?php
                        $entrees = array_sum(array_map(function ($m) {
                            return $m['type_mouvement'] == 'entree' ? $m['quantite'] : 0;
                        }, $mouvements));
                        $sorties = array_sum(array_map(function ($m) {
                            return $m['type_mouvement'] == 'sortie' ? $m['quantite'] : 0;
                        }, $mouvements));
                        $ajustements = array_filter($mouvements, fn($m) => $m['type_mouvement'] == 'ajustement');
                        ?>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                            <div class="bg-gradient-to-br from-white to-green-50 rounded-xl shadow p-5 border border-green-200">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-lg font-bold text-green-700"><?php echo $entrees; ?></div>
                                        <div class="text-sm text-green-600">Entrées totales</div>
                                    </div>
                                    <div class="p-2 bg-gradient-to-br from-green-100 to-green-200 rounded-lg">
                                        <i class="fas fa-arrow-down text-green-600"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-gradient-to-br from-white to-red-50 rounded-xl shadow p-5 border border-red-200">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-lg font-bold text-red-700"><?php echo $sorties; ?></div>
                                        <div class="text-sm text-red-600">Sorties totales</div>
                                    </div>
                                    <div class="p-2 bg-gradient-to-br from-red-100 to-red-200 rounded-lg">
                                        <i class="fas fa-arrow-up text-red-600"></i>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="bg-gradient-to-br from-white to-orange-50 rounded-xl shadow p-5 border border-orange-200">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-lg font-bold text-orange-700"><?php echo count($ajustements); ?></div>
                                        <div class="text-sm text-orange-600">Ajustements</div>
                                    </div>
                                    <div class="p-2 bg-gradient-to-br from-orange-100 to-orange-200 rounded-lg">
                                        <i class="fas fa-sliders-h text-orange-600"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-gradient-to-br from-white to-blue-50 rounded-xl shadow p-5 border border-blue-200">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <?php
                                        $unique_produits = count(array_unique(array_column($mouvements, 'produit_nom')));
                                        ?>
                                        <div class="text-lg font-bold text-blue-700"><?php echo $unique_produits; ?></div>
                                        <div class="text-sm text-blue-600">Produits distincts</div>
                                    </div>
                                    <div class="p-2 bg-gradient-to-br from-blue-100 to-blue-200 rounded-lg">
                                        <i class="fas fa-capsules text-blue-600"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Filtres avancés -->
                    <div
                        class="bg-gradient-to-br from-white to-blue-50 rounded-xl shadow-lg p-6 mb-8 border border-blue-200">
                        <div class="flex items-center mb-4">
                            <div class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg mr-3">
                                <i class="fas fa-filter text-blue-600"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800">Filtres de recherche</h3>
                        </div>

                        <form method="GET" action="" class="space-y-4">
                            <input type="hidden" name="page" value="mouvements">

                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <!-- Dates -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2" for="date_debut">
                                        <i class="fas fa-calendar-start mr-1 text-gray-500"></i> Date début
                                    </label>
                                    <div class="relative">
                                        <input type="date" id="date_debut" name="date_debut"
                                            value="<?php echo htmlspecialchars($_GET['date_debut'] ?? date('Y-m-01')); ?>"
                                            class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow">
                                        <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                            <i class="fas fa-calendar-day"></i>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2" for="date_fin">
                                        <i class="fas fa-calendar-end mr-1 text-gray-500"></i> Date fin
                                    </label>
                                    <div class="relative">
                                        <input type="date" id="date_fin" name="date_fin"
                                            value="<?php echo htmlspecialchars($_GET['date_fin'] ?? date('Y-m-t')); ?>"
                                            class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow">
                                        <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                            <i class="fas fa-calendar-day"></i>
                                        </div>
                                    </div>
                                </div>

                                <!-- Type de mouvement -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2" for="type_mouvement">
                                        <i class="fas fa-exchange-alt mr-1 text-gray-500"></i> Type
                                    </label>
                                    <div class="relative">
                                        <select id="type_mouvement" name="type_mouvement"
                                            class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow appearance-none bg-white">
                                            <option value="">Tous les types</option>
                                            <option value="entree" <?php echo (isset($_GET['type_mouvement']) && $_GET['type_mouvement'] == 'entree') ? 'selected' : ''; ?>>
                                                Entrée
                                            </option>
                                            <option value="sortie" <?php echo (isset($_GET['type_mouvement']) && $_GET['type_mouvement'] == 'sortie') ? 'selected' : ''; ?>>
                                                Sortie
                                            </option>
                                            <option value="ajustement" <?php echo (isset($_GET['type_mouvement']) && $_GET['type_mouvement'] == 'ajustement') ? 'selected' : ''; ?>>
                                                Ajustement
                                            </option>
                                        </select>
                                        <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                            <i class="fas fa-filter"></i>
                                        </div>
                                        <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                </div>

                                <!-- Produit -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2" for="produit_id">
                                        <i class="fas fa-capsules mr-1 text-gray-500"></i> Produit
                                    </label>
                                    <div class="relative">
                                        <select id="produit_id" name="produit_id"
                                            class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow appearance-none bg-white">
                                            <option value="">Tous les produits</option>
                                            <?php
                                            $produits_uniques = array_unique(array_map(function ($m) {
                                                return ['id' => $m['produit_id'], 'nom' => $m['produit_nom']];
                                            }, $mouvements), SORT_REGULAR);
                                            foreach ($produits_uniques as $produit):
                                                ?>
                                                <option value="<?php echo $produit['id']; ?>" <?php echo (isset($_GET['produit_id']) && $_GET['produit_id'] == $produit['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($produit['nom']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                            <i class="fas fa-pills"></i>
                                        </div>
                                        <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="flex flex-col sm:flex-row justify-between items-center gap-4 pt-4 border-t border-gray-200">
                                <div class="text-sm text-gray-600">
                                    <i class="fas fa-info-circle mr-1 text-blue-500"></i>
                                    <?php echo count($mouvements); ?> mouvement(s) trouvé(s)
                                </div>
                                <div class="flex items-center space-x-3">
                                    <a href="?page=mouvements"
                                        class="group px-5 py-2 border border-gray-300 rounded-xl text-gray-700 hover:bg-gray-50 hover:shadow transition-all duration-200 font-medium">
                                        <i class="fas fa-redo mr-2 group-hover:rotate-180 transition-transform"></i>
                                        Réinitialiser
                                    </a>
                                    <button type="submit"
                                        class="group bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-6 py-2.5 rounded-xl font-medium shadow hover:shadow-md transition-all duration-200 flex items-center">
                                        <i class="fas fa-search mr-2 group-hover:scale-110 transition-transform"></i>
                                        Appliquer les filtres
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <?php if (count($mouvements) > 0): ?>
                        <!-- Tableau des mouvements -->
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg overflow-hidden border border-gray-200">
                            <!-- En-tête du tableau -->
                            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100">
                                <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                                    <div class="flex items-center">
                                        <i class="fas fa-list text-blue-600 mr-2"></i>
                                        <span class="font-medium text-gray-700">Liste des mouvements</span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <div class="text-sm text-gray-600">
                                            Période: <?php echo formatDate($_GET['date_debut'] ?? date('Y-m-01')); ?>
                                            au <?php echo formatDate($_GET['date_fin'] ?? date('Y-m-t')); ?>
                                        </div>
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
                                                    <i class="fas fa-calendar mr-2 text-gray-400"></i> Date
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
                                                    <i class="fas fa-tag mr-2 text-gray-400"></i> Lot
                                                </div>
                                            </th>
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-exchange-alt mr-2 text-gray-400"></i> Type
                                                </div>
                                            </th>
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-box mr-2 text-gray-400"></i> Quantité
                                                </div>
                                            </th>
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-comment mr-2 text-gray-400"></i> Raison
                                                </div>
                                            </th>
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-user mr-2 text-gray-400"></i> Par
                                                </div>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($mouvements as $mouvement): ?>
                                            <tr
                                                class="hover:bg-gradient-to-r hover:from-blue-50 hover:to-transparent transition-all duration-200 group">
                                                <!-- Date -->
                                                <td class="px-6 py-4">
                                                    <div class="text-sm text-gray-900">
                                                        <?php echo formatDate($mouvement['created_at']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        <?php echo date('H:i', strtotime($mouvement['created_at'])); ?>
                                                    </div>
                                                </td>

                                                <!-- Produit -->
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center">
                                                        <div
                                                            class="p-2 bg-gradient-to-br from-emerald-50 to-emerald-100 rounded-lg mr-3 border border-emerald-200">
                                                            <i class="fas fa-pills text-emerald-600 text-sm"></i>
                                                        </div>
                                                        <div>
                                                            <div
                                                                class="font-semibold text-gray-900 group-hover:text-blue-700 transition-colors">
                                                                <?php echo htmlspecialchars($mouvement['produit_nom']); ?>
                                                            </div>
                                                            <?php if (!empty($mouvement['code_barre'])): ?>
                                                                <div class="text-xs text-gray-500 mt-1">
                                                                    <i class="fas fa-barcode mr-1"></i>
                                                                    <?php echo htmlspecialchars($mouvement['code_barre']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>

                                                <!-- Lot -->
                                                <td class="px-6 py-4">
                                                    <code
                                                        class="text-sm text-gray-900 bg-gray-50 px-2 py-1 rounded font-mono border border-gray-200">
                                                                                                                                                                            <?php echo htmlspecialchars($mouvement['numero_lot']); ?>
                                                                                                                                                                        </code>
                                                </td>

                                                <!-- Type -->
                                                <td class="px-6 py-4">
                                                    <?php if ($mouvement['type_mouvement'] == 'entree'): ?>
                                                        <div
                                                            class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-green-100 to-green-50 text-green-800 border border-green-200">
                                                            <i class="fas fa-arrow-down text-green-500 mr-1 text-xs"></i>
                                                            Entrée
                                                        </div>
                                                    <?php elseif ($mouvement['type_mouvement'] == 'sortie'): ?>
                                                        <div
                                                            class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-red-100 to-red-50 text-red-800 border border-red-200">
                                                            <i class="fas fa-arrow-up text-red-500 mr-1 text-xs"></i>
                                                            Sortie
                                                        </div>
                                                    <?php else: ?>
                                                        <div
                                                            class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-orange-100 to-orange-50 text-orange-800 border border-orange-200">
                                                            <i class="fas fa-sliders-h text-orange-500 mr-1 text-xs"></i>
                                                            Ajustement
                                                        </div>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Quantité -->
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center">
                                                        <div
                                                            class="text-2xl font-bold <?php echo $mouvement['type_mouvement'] == 'entree' ? 'text-green-700' : ($mouvement['type_mouvement'] == 'sortie' ? 'text-red-700' : 'text-orange-700'); ?>">
                                                            <?php echo $mouvement['type_mouvement'] == 'entree' ? '+' : ($mouvement['type_mouvement'] == 'sortie' ? '-' : '±'); ?>
                                                            <?php echo $mouvement['quantite']; ?>
                                                        </div>
                                                        <span class="text-xs text-gray-500 ml-2">unités</span>
                                                    </div>
                                                </td>

                                                <!-- Raison -->
                                                <td class="px-6 py-4">
                                                    <div class="text-sm text-gray-800 max-w-xs">
                                                        <?php echo htmlspecialchars($mouvement['raison']); ?>
                                                    </div>
                                                </td>

                                                <!-- Utilisateur -->
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center">
                                                        <div
                                                            class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg mr-2 border border-blue-200">
                                                            <i class="fas fa-user text-blue-600 text-xs"></i>
                                                        </div>
                                                        <div class="text-sm text-gray-900">
                                                            <?php echo htmlspecialchars($mouvement['utilisateur_nom']); ?>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Aucun mouvement -->
                        <div
                            class="bg-gradient-to-br from-white to-blue-50 rounded-2xl shadow-lg p-16 text-center border border-blue-200">
                            <div
                                class="inline-flex items-center justify-center w-24 h-24 rounded-full bg-gradient-to-r from-blue-100 to-blue-200 mb-8 shadow-sm">
                                <i class="fas fa-history text-blue-600 text-4xl"></i>
                            </div>
                            <h3 class="text-3xl font-bold text-gray-800 mb-4">Aucun mouvement trouvé</h3>
                            <p class="text-gray-600 text-lg mb-10 max-w-md mx-auto">
                                Aucun mouvement de stock n'a été enregistré pour la période ou les filtres sélectionnés.
                            </p>
                            <div class="flex flex-col sm:flex-row justify-center gap-6">
                                <a href="?page=mouvements"
                                    class="group bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-8 py-4 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5 text-lg">
                                    <i class="fas fa-redo mr-3 group-hover:rotate-180 transition-transform"></i>
                                    Réinitialiser les filtres
                                </a>
                                <a href="?page=dashboard"
                                    class="group bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-8 py-4 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5 text-lg">
                                    <i class="fas fa-tachometer-alt mr-3 group-hover:scale-110 transition-transform"></i>
                                    Tableau de bord
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Script d'exportation CSV -->
                <script>
                    function exportToCSV() {
                        const dateDebut = document.getElementById('date_debut').value || '<?php echo date("Y-m-01"); ?>';
                        const dateFin = document.getElementById('date_fin').value || '<?php echo date("Y-m-t"); ?>';
                        const typeMouvement = document.getElementById('type_mouvement').value || '';
                        const produitId = document.getElementById('produit_id').value || '';

                        const params = new URLSearchParams({
                            action: 'export_csv',
                            date_debut: dateDebut,
                            date_fin: dateFin,
                            type_mouvement: typeMouvement,
                            produit_id: produitId
                        });

                        window.location.href = `?page=mouvements&${params.toString()}`;
                    }
                </script>

            <?php elseif ($current_page == 'inventaire'): ?>
                <!-- ========== INVENTAIRE RAPIDE ========== -->
                <div class="mb-8">
                    <!-- En-tête avec instructions -->
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center">
                            <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl shadow-sm mr-4">
                                <i class="fas fa-clipboard-check text-emerald-600 text-xl"></i>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold text-gray-800">Inventaire Rapide</h1>
                                <p class="text-gray-600 mt-1">Ajustez les quantités de stock selon l'inventaire physique</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-4">
                            <?php if (count($inventaire_data) > 0): ?>
                                <span
                                    class="px-3 py-1 bg-gradient-to-r from-orange-100 to-orange-50 text-orange-800 rounded-full text-sm font-semibold border border-orange-200">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    <?php echo count($inventaire_data); ?> produit(s) à vérifier
                                </span>
                            <?php endif; ?>
                            <button onclick="validateAllInventory()"
                                class="group bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200">
                                <i class="fas fa-check-double mr-2 group-hover:scale-110 transition-transform"></i>
                                Valider tout
                            </button>
                        </div>
                    </div>

                    <!-- Instructions et conseils -->
                    <div
                        class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl shadow-lg p-6 mb-8 border border-blue-200">
                        <div class="flex items-start">
                            <div class="p-3 bg-gradient-to-br from-blue-100 to-blue-200 rounded-lg mr-4">
                                <i class="fas fa-info-circle text-blue-600 text-lg"></i>
                            </div>
                            <div class="flex-1">
                                <h3 class="text-lg font-semibold text-gray-800 mb-2">Comment procéder à l'inventaire ?</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="flex items-start">
                                        <div class="p-1 bg-white rounded mr-3">
                                            <i class="fas fa-1 text-blue-500 text-xs"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-700">Comptez physiquement les unités disponibles</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start">
                                        <div class="p-1 bg-white rounded mr-3">
                                            <i class="fas fa-2 text-blue-500 text-xs"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-700">Saisissez le nombre réel dans la colonne
                                                "Inventaire réel"</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start">
                                        <div class="p-1 bg-white rounded mr-3">
                                            <i class="fas fa-3 text-blue-500 text-xs"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-700">Cliquez sur ✓ pour valider ou utilisez "Valider
                                                tout"</p>
                                        </div>
                                    </div>
                                    <div class="flex items-start">
                                        <div class="p-1 bg-white rounded mr-3">
                                            <i class="fas fa-4 text-blue-500 text-xs"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-700">Les écarts seront automatiquement ajustés</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (count($inventaire_data) > 0): ?>
                        <!-- Résumé des écarts -->
                        <?php
                        $total_ecart = 0;
                        $produits_ecart = 0;
                        foreach ($inventaire_data as $produit) {
                            if ($produit['quantite_stock'] != $produit['quantite_stock']) { // Cette condition semble incorrecte, devrait être comparée à une quantité réelle
                                $produits_ecart++;
                                // $total_ecart += abs($produit['quantite_stock'] - $produit['quantite_reelle']);
                            }
                        }
                        ?>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                            <div class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow p-5 border border-gray-200">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-lg font-bold text-gray-800"><?php echo count($inventaire_data); ?>
                                        </div>
                                        <div class="text-sm text-gray-600">Produits à vérifier</div>
                                    </div>
                                    <div class="p-2 bg-gradient-to-br from-gray-100 to-gray-200 rounded-lg">
                                        <i class="fas fa-list-check text-gray-600"></i>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="bg-gradient-to-br from-white to-yellow-50 rounded-xl shadow p-5 border border-yellow-200">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-lg font-bold text-yellow-800" id="ecart-count">0</div>
                                        <div class="text-sm text-yellow-600">Écarts détectés</div>
                                    </div>
                                    <div class="p-2 bg-gradient-to-br from-yellow-100 to-yellow-200 rounded-lg">
                                        <i class="fas fa-balance-scale text-yellow-600"></i>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="bg-gradient-to-br from-white to-emerald-50 rounded-xl shadow p-5 border border-emerald-200">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-lg font-bold text-emerald-800" id="validated-count">0</div>
                                        <div class="text-sm text-emerald-600">Validés</div>
                                    </div>
                                    <div class="p-2 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-lg">
                                        <i class="fas fa-check-circle text-emerald-600"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tableau d'inventaire -->
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg overflow-hidden border border-gray-200">
                            <!-- En-tête du tableau -->
                            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100">
                                <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                                    <div class="flex items-center">
                                        <i class="fas fa-clipboard-list text-emerald-600 mr-2"></i>
                                        <span class="font-medium text-gray-700">Inventaire en cours</span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <div class="text-sm text-gray-600">
                                            <i class="fas fa-clock mr-1"></i>
                                            Session: <?php echo date('d/m/Y H:i'); ?>
                                        </div>
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
                                                    <i class="fas fa-barcode mr-2 text-gray-400"></i> Code barre
                                                </div>
                                            </th>
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-database mr-2 text-gray-400"></i> Stock système
                                                </div>
                                            </th>
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-hand-sparkles mr-2 text-gray-400"></i> Inventaire réel
                                                </div>
                                            </th>
                                            <th
                                                class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                <div class="flex items-center">
                                                    <i class="fas fa-exchange-alt mr-2 text-gray-400"></i> Écart
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
                                    <tbody class="bg-white divide-y divide-gray-200" id="inventory-table">
                                        <?php foreach ($inventaire_data as $produit): ?>
                                            <tr class="inventory-row hover:bg-gradient-to-r hover:from-emerald-50 hover:to-transparent transition-all duration-200"
                                                data-id="<?php echo $produit['id']; ?>"
                                                data-stock="<?php echo $produit['quantite_stock']; ?>">
                                                <!-- Produit -->
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center">
                                                        <div
                                                            class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg mr-3 border border-blue-200">
                                                            <i class="fas fa-pills text-blue-600 text-sm"></i>
                                                        </div>
                                                        <div>
                                                            <div class="font-semibold text-gray-900">
                                                                <?php echo htmlspecialchars($produit['nom']); ?>
                                                            </div>
                                                            <?php if (isset($produit['fournisseur'])): ?>
                                                                <div class="text-xs text-gray-500 mt-1">
                                                                    <i class="fas fa-truck mr-1"></i>
                                                                    <?php echo htmlspecialchars($produit['fournisseur']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>

                                                <!-- Catégorie -->
                                                <td class="px-6 py-4">
                                                    <span
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gradient-to-r from-gray-50 to-gray-100 text-gray-800 border border-gray-200">
                                                        <?php echo htmlspecialchars($produit['categorie']); ?>
                                                    </span>
                                                </td>

                                                <!-- Code barre -->
                                                <td class="px-6 py-4">
                                                    <code
                                                        class="text-sm text-gray-900 bg-gray-50 px-2 py-1 rounded font-mono border border-gray-200">
                                                                                                                                                                <?php echo htmlspecialchars($produit['code_barre']); ?>
                                                                                                                                                            </code>
                                                </td>

                                                <!-- Stock système -->
                                                <td class="px-6 py-4">
                                                    <div class="flex flex-col items-start">
                                                        <span
                                                            class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold 
                                            <?php echo $produit['quantite_stock'] <= 10 ?
                                                'bg-gradient-to-r from-red-100 to-red-50 text-red-800 border border-red-200' :
                                                'bg-gradient-to-r from-green-100 to-green-50 text-green-800 border border-green-200'; ?>">
                                                            <i class="fas fa-box mr-1 text-xs"></i>
                                                            <?php echo $produit['quantite_stock']; ?> unités
                                                        </span>
                                                        <?php if ($produit['quantite_stock'] <= 10): ?>
                                                            <div class="text-xs text-red-600 mt-1">
                                                                <i class="fas fa-exclamation-circle mr-1"></i> Stock bas
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>

                                                <!-- Inventaire réel -->
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center space-x-3">
                                                        <form method="POST" action="" class="inventory-form">
                                                            <input type="hidden" name="action" value="realiser_inventaire">
                                                            <input type="hidden" name="produit_id"
                                                                value="<?php echo $produit['id']; ?>">
                                                            <div class="relative">
                                                                <input type="number" name="quantite_reelle" min="0"
                                                                    value="<?php echo $produit['quantite_stock']; ?>"
                                                                    class="w-32 px-4 py-2 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all duration-200 shadow-sm"
                                                                    onchange="updateEcart(this)">
                                                                <div
                                                                    class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                                    <i class="fas fa-hashtag"></i>
                                                                </div>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </td>

                                                <!-- Écart -->
                                                <td class="px-6 py-4">
                                                    <div class="text-center">
                                                        <span class="ecart-display text-sm font-semibold text-gray-900">0</span>
                                                        <div class="ecart-badge hidden"></div>
                                                    </div>
                                                </td>

                                                <!-- Actions -->
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center space-x-2">
                                                        <button type="button" onclick="validateInventoryRow(this)"
                                                            class="p-2 bg-gradient-to-br from-emerald-50 to-emerald-100 hover:from-emerald-100 hover:to-emerald-200 text-emerald-600 rounded-lg border border-emerald-200 hover:shadow-sm transition-all duration-200 group/validate"
                                                            title="Valider l'inventaire">
                                                            <i
                                                                class="fas fa-check text-sm group-hover/validate:scale-110 transition-transform"></i>
                                                        </button>

                                                        <a href="?page=ajouter_lot&produit_id=<?php echo $produit['id']; ?>"
                                                            class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 hover:from-blue-100 hover:to-blue-200 text-blue-600 rounded-lg border border-blue-200 hover:shadow-sm transition-all duration-200 group/add"
                                                            title="Ajouter un lot">
                                                            <i
                                                                class="fas fa-plus-circle text-sm group-hover/add:scale-110 transition-transform"></i>
                                                        </a>

                                                        <button type="button" onclick="copyStockValue(this)"
                                                            class="p-2 bg-gradient-to-br from-gray-50 to-gray-100 hover:from-gray-100 hover:to-gray-200 text-gray-600 rounded-lg border border-gray-200 hover:shadow-sm transition-all duration-200 group/copy"
                                                            title="Copier le stock système">
                                                            <i
                                                                class="fas fa-copy text-sm group-hover/copy:scale-110 transition-transform"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Actions en masse -->
                            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                                <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                                    <div class="text-sm text-gray-700">
                                        <i class="fas fa-info-circle mr-2 text-blue-500"></i>
                                        Cliquez sur "Valider tout" pour ajuster tous les écarts en une seule fois
                                    </div>
                                    <div class="flex items-center space-x-3">
                                        <button onclick="resetAllInventories()"
                                            class="group px-5 py-2 border border-gray-300 rounded-xl text-gray-700 hover:bg-gray-50 hover:shadow transition-all duration-200 font-medium">
                                            <i class="fas fa-redo mr-2 group-hover:rotate-180 transition-transform"></i>
                                            Réinitialiser
                                        </button>
                                        <button onclick="validateAllInventory()"
                                            class="group bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-6 py-2.5 rounded-xl font-medium shadow hover:shadow-md transition-all duration-200 flex items-center">
                                            <i class="fas fa-check-double mr-2 group-hover:scale-110 transition-transform"></i>
                                            Valider tout l'inventaire
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Inventaire à jour -->
                        <div
                            class="bg-gradient-to-br from-white to-emerald-50 rounded-2xl shadow-lg p-16 text-center border border-emerald-200">
                            <div
                                class="inline-flex items-center justify-center w-24 h-24 rounded-full bg-gradient-to-r from-emerald-100 to-emerald-200 mb-8 shadow-sm">
                                <i class="fas fa-clipboard-check text-emerald-600 text-4xl"></i>
                            </div>
                            <h3 class="text-3xl font-bold text-gray-800 mb-4">Inventaire à jour</h3>
                            <p class="text-gray-600 text-lg mb-10 max-w-md mx-auto">
                                Aucun produit ne nécessite actuellement de vérification d'inventaire.
                                Tous les stocks semblent être à jour.
                            </p>
                            <div class="flex flex-col sm:flex-row justify-center gap-6">
                                <a href="?page=alertes"
                                    class="group bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-8 py-4 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5 text-lg">
                                    <i class="fas fa-exclamation-triangle mr-3 group-hover:scale-110 transition-transform"></i>
                                    Vérifier les alertes
                                </a>
                                <a href="?page=dashboard"
                                    class="group bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-8 py-4 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5 text-lg">
                                    <i class="fas fa-tachometer-alt mr-3 group-hover:scale-110 transition-transform"></i>
                                    Tableau de bord
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Scripts pour l'inventaire -->
                <script>

                    document.addEventListener('DOMContentLoaded', function () {
                        // Initialiser les écarts
                        updateAllEcarts();
                        updateStats();

                        // Ajouter un événement de saisie sur tous les champs d'inventaire
                        document.querySelectorAll('input[name="quantite_reelle"]').forEach(input => {
                            input.addEventListener('input', function () {
                                updateEcart(this);
                                updateStats();
                            });
                        });
                    });

                    function updateEcart(input) {
                        const row = input.closest('.inventory-row');
                        const stockValue = parseInt(row.getAttribute('data-stock'));
                        const realValue = parseInt(input.value) || 0;
                        const ecart = realValue - stockValue;

                        const ecartDisplay = row.querySelector('.ecart-display');
                        const ecartBadge = row.querySelector('.ecart-badge');

                        ecartDisplay.textContent = ecart;

                        // Mettre à jour le style de l'écart
                        ecartDisplay.className = 'ecart-display text-sm font-bold ' +
                            (ecart > 0 ? 'text-green-700' :
                                ecart < 0 ? 'text-red-700' : 'text-gray-900');

                        ecartDisplay.textContent = (ecart > 0 ? '+' : '') + ecart;

                        // Mettre à jour ou créer le badge
                        if (ecart !== 0) {
                            ecartBadge.className = 'ecart-badge inline-flex items-center px-2 py-1 rounded-full text-xs font-medium mt-1 ' +
                                (ecart > 0 ? 'bg-gradient-to-r from-green-100 to-green-50 text-green-800 border border-green-200' :
                                    'bg-gradient-to-r from-red-100 to-red-50 text-red-800 border border-red-200');
                            ecartBadge.innerHTML = (ecart > 0 ? '<i class="fas fa-plus mr-1"></i>Surplus' :
                                '<i class="fas fa-minus mr-1"></i>Manquant');
                            ecartBadge.style.display = 'inline-flex';
                        } else {
                            ecartBadge.style.display = 'none';
                        }
                    }

                    function updateAllEcarts() {
                        document.querySelectorAll('input[name="quantite_reelle"]').forEach(input => {
                            updateEcart(input);
                        });
                    }

                    function updateStats() {
                        let ecartCount = 0;
                        let validatedCount = 0;

                        document.querySelectorAll('.inventory-row').forEach(row => {
                            const realInput = row.querySelector('input[name="quantite_reelle"]');
                            const stockValue = parseInt(row.getAttribute('data-stock'));
                            const realValue = parseInt(realInput.value) || 0;

                            if (realValue !== stockValue) {
                                ecartCount++;
                            }

                            // Compter les lignes validées (pourrait être étendu avec des données de validation)
                            if (realInput.value !== '') {
                                validatedCount++;
                            }
                        });

                        document.getElementById('ecart-count').textContent = ecartCount;
                        document.getElementById('validated-count').textContent = validatedCount;
                    }

                    function validateInventoryRow(button) {
                        const form = button.closest('tr').querySelector('.inventory-form');
                        if (form) {
                            // Ajouter un indicateur visuel de validation
                            const row = button.closest('tr');
                            row.style.backgroundColor = 'rgba(16, 185, 129, 0.1)';
                            button.disabled = true;
                            button.innerHTML = '<i class="fas fa-check text-green-600"></i>';

                            // Soumettre le formulaire après un court délai pour l'animation
                            setTimeout(() => {
                                form.submit();
                            }, 500);
                        }
                    }

                    function validateAllInventory() {
                        if (confirm('Valider tout l\'inventaire ? Cette action ajustera tous les écarts détectés.')) {
                            // Marquer toutes les lignes comme validées visuellement
                            document.querySelectorAll('.inventory-row').forEach(row => {
                                const button = row.querySelector('[title="Valider l\'inventaire"]');
                                if (button) {
                                    row.style.backgroundColor = 'rgba(16, 185, 129, 0.1)';
                                    button.disabled = true;
                                    button.innerHTML = '<i class="fas fa-check text-green-600"></i>';
                                }
                            });

                            // Soumettre tous les formulaires
                            setTimeout(() => {
                                document.querySelectorAll('.inventory-form').forEach(form => {
                                    form.submit();
                                });
                            }, 1000);
                        }
                    }

                    function resetAllInventories() {
                        if (confirm('Réinitialiser toutes les valeurs d\'inventaire aux valeurs système ?')) {
                            document.querySelectorAll('.inventory-row').forEach(row => {
                                const stockValue = parseInt(row.getAttribute('data-stock'));
                                const input = row.querySelector('input[name="quantite_reelle"]');
                                input.value = stockValue;
                                updateEcart(input);
                            });
                            updateStats();
                        }
                    }

                    function copyStockValue(button) {
                        const row = button.closest('tr');
                        const stockValue = row.getAttribute('data-stock');
                        const input = row.querySelector('input[name="quantite_reelle"]');

                        input.value = stockValue;
                        updateEcart(input);
                        updateStats();

                        // Animation de feedback
                        const originalHTML = button.innerHTML;
                        button.innerHTML = '<i class="fas fa-check text-green-600"></i>';
                        button.classList.add('bg-green-50', 'border-green-300');

                        setTimeout(() => {
                            button.innerHTML = originalHTML;
                            button.classList.remove('bg-green-50', 'border-green-300');
                        }, 1000);
                    }
                </script>

            <?php elseif ($current_page == 'rapports'): ?>
                <!-- ========== RAPPORTS STOCK ========== -->
                <div class="mb-8">
                    <!-- En-tête avec options d'export -->
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center">
                            <div class="p-3 bg-gradient-to-br from-purple-100 to-purple-200 rounded-xl shadow-sm mr-4">
                                <i class="fas fa-chart-bar text-purple-600 text-xl"></i>
                            </div>
                            <div>
                                <h1 class="text-2xl font-bold text-gray-800">Rapports Stock</h1>
                                <p class="text-gray-600 mt-1">Statistiques et analyses détaillées du stock</p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-4">
                            <div
                                class="text-center px-4 py-2 bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl border border-purple-200">
                                <div class="text-2xl font-bold text-purple-700" id="total-mouvements">
                                    <?php echo count($rapports_data); ?>
                                </div>
                                <div class="text-xs text-purple-600 font-medium">Période</div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <button onclick="exportReportPDF()"
                                    class="group bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200">
                                    <i class="fas fa-file-pdf mr-2 group-hover:scale-110 transition-transform"></i>
                                    PDF
                                </button>
                                <button onclick="exportReportExcel()"
                                    class="group bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200">
                                    <i class="fas fa-file-excel mr-2 group-hover:scale-110 transition-transform"></i>
                                    Excel
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Filtres période -->
                    <div
                        class="bg-gradient-to-br from-white to-purple-50 rounded-xl shadow-lg p-6 mb-8 border border-purple-200">
                        <div class="flex items-center mb-4">
                            <div class="p-2 bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg mr-3">
                                <i class="fas fa-calendar-alt text-purple-600"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800">Période d'analyse</h3>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <a href="?page=rapports&periode=jour"
                                class="group px-5 py-3 rounded-xl font-medium transition-all duration-200 flex items-center
                    <?php echo ($_GET['periode'] ?? '') == 'jour' ?
                        'bg-gradient-to-r from-emerald-500 to-emerald-600 text-white shadow-lg' :
                        'bg-gradient-to-r from-gray-100 to-gray-200 text-gray-700 hover:from-gray-200 hover:to-gray-300 border border-gray-300'; ?>">
                                <i
                                    class="fas fa-sun mr-2 <?php echo ($_GET['periode'] ?? '') == 'jour' ? 'text-white' : 'text-yellow-500'; ?>"></i>
                                Journalier
                                <?php if (($_GET['periode'] ?? '') == 'jour'): ?>
                                    <div class="ml-2 w-2 h-2 bg-white rounded-full animate-pulse"></div>
                                <?php endif; ?>
                            </a>

                            <a href="?page=rapports&periode=semaine"
                                class="group px-5 py-3 rounded-xl font-medium transition-all duration-200 flex items-center
                    <?php echo ($_GET['periode'] ?? '') == 'semaine' ?
                        'bg-gradient-to-r from-blue-500 to-blue-600 text-white shadow-lg' :
                        'bg-gradient-to-r from-gray-100 to-gray-200 text-gray-700 hover:from-gray-200 hover:to-gray-300 border border-gray-300'; ?>">
                                <i
                                    class="fas fa-calendar-week mr-2 <?php echo ($_GET['periode'] ?? '') == 'semaine' ? 'text-white' : 'text-blue-500'; ?>"></i>
                                Hebdomadaire
                                <?php if (($_GET['periode'] ?? '') == 'semaine'): ?>
                                    <div class="ml-2 w-2 h-2 bg-white rounded-full animate-pulse"></div>
                                <?php endif; ?>
                            </a>

                            <a href="?page=rapports&periode=mois"
                                class="group px-5 py-3 rounded-xl font-medium transition-all duration-200 flex items-center
                    <?php echo ($_GET['periode'] ?? '') == 'mois' ?
                        'bg-gradient-to-r from-purple-500 to-purple-600 text-white shadow-lg' :
                        'bg-gradient-to-r from-gray-100 to-gray-200 text-gray-700 hover:from-gray-200 hover:to-gray-300 border border-gray-300'; ?>">
                                <i
                                    class="fas fa-calendar mr-2 <?php echo ($_GET['periode'] ?? '') == 'mois' ? 'text-white' : 'text-purple-500'; ?>"></i>
                                Mensuel
                                <?php if (($_GET['periode'] ?? '') == 'mois'): ?>
                                    <div class="ml-2 w-2 h-2 bg-white rounded-full animate-pulse"></div>
                                <?php endif; ?>
                            </a>

                            <!-- Période personnalisée -->
                            <div class="relative group-custom">
                                <a href="?page=rapports&periode=personnalisee"
                                    class="group px-5 py-3 rounded-xl font-medium transition-all duration-200 flex items-center
                        bg-gradient-to-r from-gray-100 to-gray-200 text-gray-700 hover:from-gray-200 hover:to-gray-300 border border-gray-300">
                                    <i class="fas fa-calendar-day mr-2 text-orange-500"></i>
                                    Personnalisée
                                </a>
                                <!-- Menu déroulant personnalisé -->
                                <div
                                    class="absolute hidden group-hover-custom:block bg-white shadow-2xl rounded-xl p-4 mt-2 w-64 z-10 border border-gray-200">
                                    <form method="GET" action="" class="space-y-3">
                                        <input type="hidden" name="page" value="rapports">
                                        <input type="hidden" name="periode" value="personnalisee">

                                        <div>
                                            <label class="block text-xs font-medium text-gray-700 mb-1">Date début</label>
                                            <input type="date" name="date_debut"
                                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                                value="<?php echo date('Y-m-01'); ?>">
                                        </div>

                                        <div>
                                            <label class="block text-xs font-medium text-gray-700 mb-1">Date fin</label>
                                            <input type="date" name="date_fin"
                                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                                value="<?php echo date('Y-m-t'); ?>">
                                        </div>

                                        <button type="submit"
                                            class="w-full bg-gradient-to-r from-orange-500 to-orange-600 text-white px-3 py-2 rounded-lg text-sm font-medium hover:shadow transition-all">
                                            Appliquer
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Indicateur de période active -->
                        <div class="mt-4 text-sm text-gray-600 flex items-center">
                            <i class="fas fa-clock mr-2 text-gray-400"></i>
                            <?php
                            $periode_text = 'Période : ';
                            switch ($_GET['periode'] ?? '') {
                                case 'jour':
                                    $periode_text .= 'Aujourd\'hui - ' . date('d/m/Y');
                                    break;
                                case 'semaine':
                                    $periode_text .= 'Cette semaine - ' . date('W/Y');
                                    break;
                                case 'mois':
                                    $periode_text .= 'Ce mois - ' . date('m/Y');
                                    break;
                                default:
                                    $periode_text .= 'Période sélectionnée';
                            }
                            echo $periode_text;
                            ?>
                        </div>
                    </div>

                    <!-- Cartes de synthèse -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                        <?php
                        // Calcul des totaux pour la période
                        $total_entrees = 0;
                        $total_sorties = 0;
                        $total_mouvements = 0;
                        $produits_mouvants = 0;

                        if (isset($rapports_data) && count($rapports_data) > 0) {
                            foreach ($rapports_data as $rapport) {
                                $total_entrees += $rapport['entrees'];
                                $total_sorties += $rapport['sorties'];
                                $total_mouvements += $rapport['entrees'] + $rapport['sorties'];
                            }
                            $produits_mouvants = count(array_unique(array_column($rapports_data, 'produit_count')));
                        }
                        ?>

                        <div
                            class="bg-gradient-to-br from-white to-green-50 rounded-xl shadow-lg p-6 border border-green-200">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-2xl font-bold text-green-700"><?php echo $total_entrees; ?></div>
                                    <div class="text-sm text-green-600">Entrées totales</div>
                                </div>
                                <div class="p-3 bg-gradient-to-br from-green-100 to-green-200 rounded-lg">
                                    <i class="fas fa-arrow-down text-green-600"></i>
                                </div>
                            </div>
                            <div class="mt-3 text-xs text-gray-500">
                                <i class="fas fa-chart-line mr-1"></i>
                                Stock entrant
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-white to-red-50 rounded-xl shadow-lg p-6 border border-red-200">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-2xl font-bold text-red-700"><?php echo $total_sorties; ?></div>
                                    <div class="text-sm text-red-600">Sorties totales</div>
                                </div>
                                <div class="p-3 bg-gradient-to-br from-red-100 to-red-200 rounded-lg">
                                    <i class="fas fa-arrow-up text-red-600"></i>
                                </div>
                            </div>
                            <div class="mt-3 text-xs text-gray-500">
                                <i class="fas fa-chart-line mr-1"></i>
                                Stock sortant
                            </div>
                        </div>

                        <div
                            class="bg-gradient-to-br from-white to-blue-50 rounded-xl shadow-lg p-6 border border-blue-200">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-2xl font-bold text-blue-700"><?php echo $total_mouvements; ?></div>
                                    <div class="text-sm text-blue-600">Mouvements totaux</div>
                                </div>
                                <div class="p-3 bg-gradient-to-br from-blue-100 to-blue-200 rounded-lg">
                                    <i class="fas fa-exchange-alt text-blue-600"></i>
                                </div>
                            </div>
                            <div class="mt-3 text-xs text-gray-500">
                                <i class="fas fa-chart-bar mr-1"></i>
                                Activité totale
                            </div>
                        </div>

                        <div
                            class="bg-gradient-to-br from-white to-purple-50 rounded-xl shadow-lg p-6 border border-purple-200">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-2xl font-bold text-purple-700"><?php echo $produits_mouvants; ?></div>
                                    <div class="text-sm text-purple-600">Produits actifs</div>
                                </div>
                                <div class="p-3 bg-gradient-to-br from-purple-100 to-purple-200 rounded-lg">
                                    <i class="fas fa-capsules text-purple-600"></i>
                                </div>
                            </div>
                            <div class="mt-3 text-xs text-gray-500">
                                <i class="fas fa-boxes mr-1"></i>
                                Avec mouvement
                            </div>
                        </div>
                    </div>

                    <!-- Tableau détaillé et statistiques -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                        <!-- Tableau des mouvements -->
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg p-6 border border-gray-200">
                            <div class="flex items-center justify-between mb-6">
                                <div class="flex items-center">
                                    <div class="p-2 bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg mr-3">
                                        <i class="fas fa-exchange-alt text-blue-600"></i>
                                    </div>
                                    <h3 class="text-lg font-semibold text-gray-800">Mouvements détaillés</h3>
                                </div>
                                <div class="text-sm text-gray-600">
                                    <?php echo count($rapports_data); ?> jour(s)
                                </div>
                            </div>

                            <?php if (isset($rapports_data) && count($rapports_data) > 0): ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full">
                                        <thead>
                                            <tr class="border-b border-gray-300">
                                                <th
                                                    class="text-left py-3 text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                    <div class="flex items-center">
                                                        <i class="fas fa-calendar mr-2 text-gray-400"></i> Date
                                                    </div>
                                                </th>
                                                <th
                                                    class="text-left py-3 text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                    <div class="flex items-center">
                                                        <i class="fas fa-arrow-down mr-2 text-gray-400"></i> Entrées
                                                    </div>
                                                </th>
                                                <th
                                                    class="text-left py-3 text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                    <div class="flex items-center">
                                                        <i class="fas fa-arrow-up mr-2 text-gray-400"></i> Sorties
                                                    </div>
                                                </th>
                                                <th
                                                    class="text-left py-3 text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                                    <div class="flex items-center">
                                                        <i class="fas fa-balance-scale mr-2 text-gray-400"></i> Solde
                                                    </div>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $total_entrees = 0;
                                            $total_sorties = 0;
                                            foreach ($rapports_data as $rapport):
                                                $total_entrees += $rapport['entrees'];
                                                $total_sorties += $rapport['sorties'];
                                                $solde = $rapport['entrees'] - $rapport['sorties'];
                                                ?>
                                                <tr class="border-b border-gray-200 hover:bg-gray-50 transition-colors">
                                                    <td class="py-3 text-sm text-gray-900">
                                                        <?php echo formatDate($rapport['date']); ?>
                                                    </td>
                                                    <td class="py-3">
                                                        <span
                                                            class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-green-100 to-green-50 text-green-800 border border-green-200">
                                                            <i class="fas fa-plus mr-1 text-xs"></i>
                                                            <?php echo $rapport['entrees']; ?>
                                                        </span>
                                                    </td>
                                                    <td class="py-3">
                                                        <span
                                                            class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-red-100 to-red-50 text-red-800 border border-red-200">
                                                            <i class="fas fa-minus mr-1 text-xs"></i>
                                                            <?php echo $rapport['sorties']; ?>
                                                        </span>
                                                    </td>
                                                    <td class="py-3">
                                                        <span
                                                            class="text-sm font-bold <?php echo $solde >= 0 ? 'text-green-700' : 'text-red-700'; ?>">
                                                            <?php echo $solde >= 0 ? '+' : ''; ?>             <?php echo $solde; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="bg-gradient-to-r from-gray-50 to-gray-100">
                                            <tr>
                                                <td class="py-3 text-sm font-semibold text-gray-900">Total</td>
                                                <td class="py-3">
                                                    <span
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-sm font-bold bg-gradient-to-r from-green-100 to-green-50 text-green-800 border border-green-200">
                                                        +<?php echo $total_entrees; ?>
                                                    </span>
                                                </td>
                                                <td class="py-3">
                                                    <span
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-sm font-bold bg-gradient-to-r from-red-100 to-red-50 text-red-800 border border-red-200">
                                                        -<?php echo $total_sorties; ?>
                                                    </span>
                                                </td>
                                                <td class="py-3">
                                                    <span
                                                        class="text-lg font-bold <?php echo ($total_entrees - $total_sorties) >= 0 ? 'text-green-700' : 'text-red-700'; ?>">
                                                        <?php echo ($total_entrees - $total_sorties) >= 0 ? '+' : ''; ?>
                                                        <?php echo $total_entrees - $total_sorties; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-10">
                                    <div
                                        class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gradient-to-r from-gray-100 to-gray-200 mb-4">
                                        <i class="fas fa-exchange-alt text-gray-400 text-xl"></i>
                                    </div>
                                    <h4 class="text-lg font-medium text-gray-700 mb-2">Aucun mouvement</h4>
                                    <p class="text-gray-500 text-sm">Aucun mouvement de stock pour cette période</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Statistiques avancées -->
                        <div
                            class="bg-gradient-to-br from-white to-purple-50 rounded-xl shadow-lg p-6 border border-purple-200">
                            <div class="flex items-center mb-6">
                                <div class="p-2 bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg mr-3">
                                    <i class="fas fa-chart-pie text-purple-600"></i>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-800">Statistiques avancées</h3>
                            </div>

                            <?php
                            try {
                                // Produits les plus en stock
                                $stmt = $pdo->query("
                    SELECT p.nom, SUM(l.quantite_actuelle) as quantite
                    FROM produits p
                    LEFT JOIN lots l ON p.id = l.produit_id AND l.statut = 'en_stock'
                    WHERE p.statut = 'actif'
                    GROUP BY p.id
                    ORDER BY quantite DESC
                    LIMIT 5
                ");
                                $top_produits = $stmt->fetchAll();
                                ?>

                                <!-- Top produits -->
                                <div class="mb-8">
                                    <h4 class="font-bold text-gray-700 mb-4 flex items-center">
                                        <i class="fas fa-medal mr-2 text-yellow-500"></i>
                                        Top 5 produits en stock
                                    </h4>
                                    <div class="space-y-3">
                                        <?php foreach ($top_produits as $index => $produit): ?>
                                            <div
                                                class="flex items-center justify-between p-3 bg-gradient-to-r from-gray-50 to-white rounded-lg border border-gray-200 hover:shadow-sm transition-shadow">
                                                <div class="flex items-center">
                                                    <div class="flex items-center justify-center w-6 h-6 rounded-full bg-gradient-to-br 
                                        <?php echo $index == 0 ? 'from-yellow-100 to-yellow-200 text-yellow-700' :
                                            ($index == 1 ? 'from-gray-100 to-gray-200 text-gray-700' :
                                                ($index == 2 ? 'from-orange-100 to-orange-200 text-orange-700' :
                                                    'from-blue-100 to-blue-200 text-blue-700')); ?> 
                                        text-xs font-bold mr-3">
                                                        <?php echo $index + 1; ?>
                                                    </div>
                                                    <div>
                                                        <div class="font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($produit['nom']); ?>
                                                        </div>
                                                        <?php if ($produit['quantite'] <= 10): ?>
                                                            <div class="text-xs text-red-600">
                                                                <i class="fas fa-exclamation-circle mr-1"></i> Stock bas
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="text-lg font-bold text-blue-700">
                                                    <?php echo $produit['quantite'] ?? 0; ?>
                                                    <span class="text-xs text-gray-500">unités</span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Alertes actives -->
                                <div>
                                    <h4 class="font-bold text-gray-700 mb-4 flex items-center">
                                        <i class="fas fa-bell mr-2 text-red-500"></i>
                                        Alertes actives
                                    </h4>
                                    <div class="grid grid-cols-3 gap-3">
                                        <div
                                            class="bg-gradient-to-br from-red-50 to-red-100 rounded-lg p-4 border border-red-200 text-center">
                                            <div class="text-2xl font-bold text-red-700"><?php echo $stats['ruptures_stock']; ?>
                                            </div>
                                            <div class="text-xs text-red-600 font-medium mt-1">Ruptures</div>
                                            <div class="text-xs text-red-500 mt-2">
                                                <i class="fas fa-times-circle"></i> Stock nul
                                            </div>
                                        </div>
                                        <div
                                            class="bg-gradient-to-br from-yellow-50 to-yellow-100 rounded-lg p-4 border border-yellow-200 text-center">
                                            <div class="text-2xl font-bold text-yellow-700">
                                                <?php echo $stats['alertes_stock_bas']; ?>
                                            </div>
                                            <div class="text-xs text-yellow-600 font-medium mt-1">Stocks bas</div>
                                            <div class="text-xs text-yellow-500 mt-2">
                                                <i class="fas fa-exclamation-circle"></i> ≤ 10 unités
                                            </div>
                                        </div>
                                        <div
                                            class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-lg p-4 border border-orange-200 text-center">
                                            <div class="text-2xl font-bold text-orange-700">
                                                <?php echo $stats['alertes_peremption']; ?>
                                            </div>
                                            <div class="text-xs text-orange-600 font-medium mt-1">Péremption</div>
                                            <div class="text-xs text-orange-500 mt-2">
                                                <i class="fas fa-clock"></i> ≤ 30 jours
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            <?php } catch (Exception $e) { ?>
                                <div class="text-center py-8">
                                    <div
                                        class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gradient-to-r from-red-100 to-red-200 mb-4">
                                        <i class="fas fa-exclamation-circle text-red-600 text-xl"></i>
                                    </div>
                                    <h4 class="text-lg font-medium text-gray-700 mb-2">Erreur de chargement</h4>
                                    <p class="text-gray-500 text-sm">Impossible de charger les statistiques</p>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>

                <!-- Styles pour le menu déroulant personnalisé -->
                <style>
                    .group-custom:hover .group-hover-custom\:block {
                        display: block !important;
                    }
                </style>

                <!-- Scripts pour l'exportation -->
                <script>
                    function exportReportPDF() {
                        const periode = getCurrentPeriod();
                        const url = `?page=rapports&export=pdf&periode=${periode}&date_debut=<?php echo $_GET['date_debut'] ?? ''; ?>&date_fin=<?php echo $_GET['date_fin'] ?? ''; ?>`;
                        window.open(url, '_blank');
                    }

                    function exportReportExcel() {
                        const periode = getCurrentPeriod();
                        const url = `?page=rapports&export=excel&periode=${periode}&date_debut=<?php echo $_GET['date_debut'] ?? ''; ?>&date_fin=<?php echo $_GET['date_fin'] ?? ''; ?>`;
                        window.open(url, '_blank');
                    }

                    function getCurrentPeriod() {
                        const params = new URLSearchParams(window.location.search);
                        return params.get('periode') || 'jour';
                    }

                    // Mettre à jour le compteur total
                    document.addEventListener('DOMContentLoaded', function () {
                        const totalEntrees = <?php echo $total_entrees; ?>;
                        const totalSorties = <?php echo $total_sorties; ?>;
                        const totalMouvements = totalEntrees + totalSorties;
                        document.getElementById('total-mouvements').textContent = totalMouvements;
                    });
                </script>

            <?php endif; ?>
        </div>
    </div>
    <!-- Pop-up pour ajuster le stock -->
    <div id="adjustStockPopup" class="popup-overlay">
        <div class="popup-container">
            <div class="popup-header">
                <h3>Ajuster le stock</h3>
                <button class="popup-close" onclick="closePopup('adjustStockPopup')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="popup-content">
                <form id="adjustStockForm" method="POST" action="">
                    <input type="hidden" name="action" value="ajuster_stock">
                    <input type="hidden" id="adjust_lot_id" name="lot_id" value="">

                    <div class="popup-form-group">
                        <label>Produit</label>
                        <input type="text" id="adjust_product_name" class="bg-gray-50" readonly>
                    </div>

                    <div class="popup-form-group">
                        <label>Numéro de lot</label>
                        <input type="text" id="adjust_lot_number" class="bg-gray-50" readonly>
                    </div>

                    <div class="popup-form-group">
                        <label>Quantité actuelle</label>
                        <input type="number" id="adjust_current_qty" class="bg-gray-50" readonly>
                    </div>

                    <div class="popup-form-group">
                        <label for="adjust_new_qty">Nouvelle quantité *</label>
                        <input type="number" id="adjust_new_qty" name="nouvelle_quantite" min="0" required
                            class="border-emerald-300">
                        <div class="text-xs text-gray-500 mt-1">Entrez la quantité réelle après comptage physique</div>
                    </div>

                    <div class="popup-form-group">
                        <label for="adjust_reason">Raison de l'ajustement *</label>
                        <select id="adjust_reason" name="raison" required class="border-emerald-300">
                            <option value="">Sélectionnez une raison</option>
                            <option value="Ajustement inventaire">Ajustement inventaire</option>
                            <option value="Correction d'erreur">Correction d'erreur</option>
                            <option value="Perte/dégât">Perte/dégât</option>
                            <option value="Vol">Vol</option>
                            <option value="Autre">Autre</option>
                        </select>
                    </div>

                    <div class="popup-form-group">
                        <label for="adjust_notes">Notes supplémentaires</label>
                        <textarea id="adjust_notes" name="notes" rows="3" placeholder="Informations complémentaires..."
                            class="border-emerald-300"></textarea>
                    </div>

                    <div class="popup-actions">
                        <button type="button" class="popup-btn popup-btn-cancel"
                            onclick="closePopup('adjustStockPopup')">
                            Annuler
                        </button>
                        <button type="submit" class="popup-btn popup-btn-submit">
                            Enregistrer l'ajustement
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Pop-up pour modifier un lot -->
    <div id="modifyLotPopup" class="popup-overlay">
        <div class="popup-container">
            <div class="popup-header">
                <h3>Modifier le lot</h3>
                <button class="popup-close" onclick="closePopup('modifyLotPopup')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="popup-content">
                <form id="modifyLotForm" method="POST" action="">
                    <input type="hidden" name="action" value="modifier_lot">
                    <input type="hidden" id="modify_lot_id" name="lot_id" value="">

                    <!-- Le contenu de ce formulaire sera chargé dynamiquement -->
                    <div id="modifyLotContent" class="text-center py-8">
                        <div class="loading-spinner mx-auto mb-4"></div>
                        <p>Chargement des informations du lot...</p>
                    </div>

                    <div class="popup-actions" style="display: none;" id="modifyLotActions">
                        <button type="button" class="popup-btn popup-btn-cancel" onclick="closePopup('modifyLotPopup')">
                            Annuler
                        </button>
                        <button type="submit" class="popup-btn popup-btn-submit">
                            Enregistrer les modifications
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Pop-up pour les détails du lot -->
    <div id="lotDetailsPopup" class="popup-overlay">
        <div class="popup-container" style="max-width: 600px;">
            <div class="popup-header">
                <h3>Détails du lot</h3>
                <button class="popup-close" onclick="closePopup('lotDetailsPopup')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="popup-content">
                <div id="lotDetailsContent" class="text-center py-8">
                    <div class="loading-spinner mx-auto mb-4"></div>
                    <p>Chargement des détails...</p>
                </div>

                <div class="popup-actions">
                    <button type="button" class="popup-btn popup-btn-cancel" onclick="closePopup('lotDetailsPopup')">
                        Fermer
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts JavaScript -->
    <script>
        // Fonction pour ajuster le stock
        function ajusterStock(lotId, quantiteActuelle, produitNom) {
            let nouvelleQuantite = prompt('Ajuster le stock pour "' + produitNom + '"\nQuantité actuelle: ' + quantiteActuelle + '\n\nNouvelle quantité:', quantiteActuelle);

            if (nouvelleQuantite !== null && !isNaN(nouvelleQuantite)) {
                let raison = prompt('Raison de l\'ajustement:', 'Ajustement manuel');

                if (raison !== null) {
                    let form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';

                    let inputAction = document.createElement('input');
                    inputAction.type = 'hidden';
                    inputAction.name = 'action';
                    inputAction.value = 'ajuster_stock';
                    form.appendChild(inputAction);

                    let inputLotId = document.createElement('input');
                    inputLotId.type = 'hidden';
                    inputLotId.name = 'lot_id';
                    inputLotId.value = lotId;
                    form.appendChild(inputLotId);

                    let inputQuantite = document.createElement('input');
                    inputQuantite.type = 'hidden';
                    inputQuantite.name = 'nouvelle_quantite';
                    inputQuantite.value = nouvelleQuantite;
                    form.appendChild(inputQuantite);

                    let inputRaison = document.createElement('input');
                    inputRaison.type = 'hidden';
                    inputRaison.name = 'raison';
                    inputRaison.value = raison;
                    form.appendChild(inputRaison);

                    document.body.appendChild(form);
                    form.submit();
                }
            }
        }

        // Fonctions pour gérer les pop-ups
        function openPopup(popupId) {
            const popup = document.getElementById(popupId);
            popup.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closePopup(popupId) {
            const popup = document.getElementById(popupId);
            popup.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Fermer les pop-ups en cliquant à l'extérieur
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.popup-overlay').forEach(popup => {
                popup.addEventListener('click', function (e) {
                    if (e.target === this) {
                        this.classList.remove('active');
                        document.body.style.overflow = 'auto';
                    }
                });
            });

            // Empêcher la fermeture en cliquant à l'intérieur du contenu
            document.querySelectorAll('.popup-container').forEach(container => {
                container.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            });
        });

        // Fonction pour ajuster le stock avec pop-up
        function openAdjustStockPopup(lotId, productName, lotNumber, currentQty) {
            document.getElementById('adjust_lot_id').value = lotId;
            document.getElementById('adjust_product_name').value = productName;
            document.getElementById('adjust_lot_number').value = lotNumber;
            document.getElementById('adjust_current_qty').value = currentQty;
            document.getElementById('adjust_new_qty').value = currentQty;
            document.getElementById('adjust_new_qty').focus();
            document.getElementById('adjust_reason').value = '';
            document.getElementById('adjust_notes').value = '';

            openPopup('adjustStockPopup');
        }

        // Fonction pour modifier un lot
        function openModifyLotPopup(lotId) {
            document.getElementById('modify_lot_id').value = lotId;
            openPopup('modifyLotPopup');

            // Charger les données du lot via AJAX
            fetch(`get_lot_details.php?id=${lotId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('modifyLotContent').innerHTML = html;
                    document.getElementById('modifyLotActions').style.display = 'flex';
                })
                .catch(error => {
                    document.getElementById('modifyLotContent').innerHTML =
                        '<div class="text-red-600"><i class="fas fa-exclamation-circle"></i> Erreur de chargement</div>';
                });
        }

        // Fonction pour voir les détails du lot
        function openLotDetailsPopup(lotId) {
            openPopup('lotDetailsPopup');

            // Charger les détails via AJAX
            fetch(`get_lot_full_details.php?id=${lotId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('lotDetailsContent').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('lotDetailsContent').innerHTML =
                        '<div class="text-red-600"><i class="fas fa-exclamation-circle"></i> Erreur de chargement</div>';
                });
        }

        // Gérer la soumission du formulaire d'ajustement
        document.getElementById('adjustStockForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const formData = new FormData(this);
            const submitBtn = this.querySelector('.popup-btn-submit');
            const originalText = submitBtn.innerHTML;

            // Afficher l'indicateur de chargement
            submitBtn.innerHTML = '<div class="loading-spinner mr-2"></div> Traitement...';
            submitBtn.disabled = true;

            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(response => response.text())
                .then(html => {
                    // Traiter la réponse (vous devrez adapter cela selon votre structure)
                    closePopup('adjustStockPopup');
                    location.reload(); // Rafraîchir la page pour voir les changements
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                    alert('Une erreur est survenue. Veuillez réessayer.');
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
    </script>
</body>

</html>