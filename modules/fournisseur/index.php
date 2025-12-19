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

// Récupérer l'ID du fournisseur connecté AVEC CRÉATION AUTOMATIQUE
$fournisseur_id = null;
try {
    $stmt = $pdo->prepare("SELECT id FROM fournisseurs WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $fournisseur = $stmt->fetch();
    $fournisseur_id = $fournisseur['id'] ?? null;

    // Si aucun fournisseur n'existe, en créer un automatiquement
    if (!$fournisseur_id) {
        // Récupérer les infos de l'utilisateur
        $stmt = $pdo->prepare("SELECT nom, email, telephone FROM utilisateurs WHERE id = :user_id");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();

        // Déterminer le nom de la société
        $nom_societe = $user['nom'] ? $user['nom'] . ' - Société' : 'Fournisseur ' . $_SESSION['user_id'];
        $contact_principal = $user['nom'] ?: 'Contact';


        // Créer le profil fournisseur
        $stmt = $pdo->prepare("
            INSERT INTO fournisseurs (
                user_id, 
                nom_societe, 
                contact_principal,
                note_qualite,
                updated_at
            ) VALUES (
                :user_id, 
                :nom_societe, 
                :contact_principal,
                0.0,
                NOW()
            )
        ");

        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':nom_societe' => $nom_societe,
            ':contact_principal' => $contact_principal,

        ]);

        $fournisseur_id = $pdo->lastInsertId();

        // Message d'information
        $message = "✅ Votre profil fournisseur a été créé automatiquement. Veuillez compléter vos informations dans l'onglet 'Mon profil'.";
    }

} catch (Exception $e) {
    $error = "Erreur lors de la récupération/création du profil fournisseur: " . $e->getMessage();
    error_log("Erreur détaillée: " . $e->getMessage());
}

// Note: On ne tue plus le script si $fournisseur_id est null, car on le crée automatiquement

// ============================================
// FONCTIONS UTILITAIRES
// ============================================

/**
 * Récupère les statistiques du dashboard fournisseur
 */
function getDashboardStats(PDO $pdo, int|string $fournisseur_id): array
{
    // Convertir en int pour les requêtes SQL si nécessaire
    $fournisseur_id_int = (int) $fournisseur_id;
    $stats = [];

    // Nombre de produits fournis
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM produits WHERE fournisseur_id = :fournisseur_id");
    $stmt->execute([':fournisseur_id' => $fournisseur_id_int]);
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
function formatMontant($montant, string $devise = 'CDF'): string
{
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
                    // Vérifier d'abord si la table produits_fournisseur existe
                    $stmt = $pdo->prepare("SHOW TABLES LIKE 'produits_fournisseur'");
                    $stmt->execute();

                    if ($stmt->fetch()) {
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
                    } else {
                        $error = "❌ La table 'produits_fournisseur' n'existe pas.";
                    }

                } catch (Exception $e) {
                    $error = "❌ Erreur lors de la mise à jour: " . $e->getMessage();
                }
                break;

            // ENVOYER UN MESSAGE
            case 'envoyer_message':
                try {
                    // Vérifier si la table messages_fournisseur existe
                    $stmt = $pdo->prepare("SHOW TABLES LIKE 'messages_fournisseur'");
                    $stmt->execute();

                    if ($stmt->fetch()) {
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
                    } else {
                        $error = "❌ La table 'messages_fournisseur' n'existe pas.";
                    }

                } catch (Exception $e) {
                    $error = "❌ Erreur lors de l'envoi du message: " . $e->getMessage();
                }
                break;
        }
    }
}
// ============================================
// GESTION DES REQUÊTES AJAX
// ============================================

if (isset($_GET['action']) && $_GET['action'] == 'get_produit' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.*, c.nom as categorie_nom, 
                pv.prix_fc, pv.prix_usd,
                (SELECT COUNT(*) FROM commande_details cd2 WHERE cd2.produit_id = p.id) as ventes_total
            FROM produits p
            LEFT JOIN categories c ON p.categorie_id = c.id
            LEFT JOIN prix_vente pv ON p.id = pv.produit_id AND pv.date_fin IS NULL
            WHERE p.id = :id AND p.fournisseur_id = :fournisseur_id
        ");
        $stmt->execute([
            ':id' => intval($_GET['id']),
            ':fournisseur_id' => $fournisseur_id
        ]);

        $produit = $stmt->fetch();

        if ($produit) {
            header('Content-Type: application/json');
            echo json_encode($produit);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Produit non trouvé']);
        }
        exit();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit();
    }
}

// ============================================
// TRAITEMENT MODIFICATION PRODUIT (POST)
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        // ... vos autres cas existants ...

        case 'modifier_produit':
            try {
                // Vérifier que le produit appartient bien au fournisseur
                $stmt = $pdo->prepare("SELECT id FROM produits WHERE id = :id AND fournisseur_id = :fournisseur_id");
                $stmt->execute([
                    ':id' => intval($_POST['produit_id'] ?? 0),
                    ':fournisseur_id' => $fournisseur_id
                ]);

                if (!$stmt->fetch()) {
                    throw new Exception("Vous n'avez pas la permission de modifier ce produit.");
                }

                // Mettre à jour le produit
                $stmt = $pdo->prepare("
                    UPDATE produits SET
                        nom = :nom,
                        description = :description,
                        code_barre = :code_barre,
                        categorie_id = :categorie_id,
                        composition = :composition,
                        updated_at = NOW()
                    WHERE id = :id AND fournisseur_id = :fournisseur_id
                ");

                $stmt->execute([
                    ':nom' => $_POST['nom'] ?? '',
                    ':description' => $_POST['description'] ?? '',
                    ':code_barre' => $_POST['code_barre'] ?? '',
                    ':categorie_id' => intval($_POST['categorie_id'] ?? 0),
                    ':composition' => $_POST['composition'] ?? '',
                    ':id' => intval($_POST['produit_id'] ?? 0),
                    ':fournisseur_id' => $fournisseur_id
                ]);

                echo "✅ Produit modifié avec succès! Les changements seront validés par le pharmacien.";

            } catch (Exception $e) {
                echo "❌ Erreur: " . $e->getMessage();
            }
            exit();
            break;
    }
}

// ============================================
// RÉCUPÉRATION DES DONNÉES
// ============================================

// Récupérer les informations du fournisseur
$infos_fournisseur = [];
try {
    $stmt = $pdo->prepare("
        SELECT f.*, u.email as user_email, u.telephone as user_telephone
        FROM fournisseurs f
        LEFT JOIN utilisateurs u ON f.user_id = u.id
        WHERE f.id = :id
    ");
    $stmt->execute([':id' => $fournisseur_id]);
    $infos_fournisseur = $stmt->fetch();
} catch (Exception $e) {
    $error = "Erreur lors du chargement des informations: " . $e->getMessage();
}

// Récupérer les statistiques (si $fournisseur_id existe)
if ($fournisseur_id) {
    $stats = getDashboardStats($pdo, $fournisseur_id);
} else {
    $stats = [
        'total_produits' => 0,
        'produits_actifs' => 0,
        'commandes_attente' => 0,
        'ventes_totales' => 0,
        'note_qualite' => 0
    ];
}

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

if ($fournisseur_id) {
    switch ($current_page) {
        case 'produits':
            try {
                $stmt = $pdo->prepare("
                    SELECT p.*, c.nom as categorie_nom, 
                        pv.prix_fc, pv.prix_usd,
                        (SELECT COUNT(*) FROM commande_details cd2 WHERE cd2.produit_id = p.id) as ventes_total
                    FROM produits p
                    LEFT JOIN categories c ON p.categorie_id = c.id
                    LEFT JOIN prix_vente pv ON p.id = pv.produit_id AND pv.date_fin IS NULL
                    WHERE p.fournisseur_id = :fournisseur_id
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
}

// Récupérer les commandes récentes pour le dashboard
$commandes_recentes = [];
if ($fournisseur_id) {
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
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap"
        rel="stylesheet">
    <!-- Styles spécifiques au sidebar -->
    <style>
        .sidebar {
            background: linear-gradient(165deg, #ffffff 0%, #f8fafc 100%);
            border-right: 1px solid rgba(16, 185, 129, 0.1);
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            backdrop-filter: blur(10px);
        }

        /* Style des éléments de menu */
        .menu-item {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }

        .menu-item:hover {
            border-color: rgba(16, 185, 129, 0.2);
            transform: translateX(4px);
        }

        .menu-item.active-menu {
            border-color: rgba(16, 185, 129, 0.3);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.25);

        }

        /* Indicateur visuel actif */
        .menu-item::after {
            content: '';
            position: absolute;
            left: -100%;
            top: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(16, 185, 129, 0.1), transparent);
            transition: left 0.6s ease;
        }

        .menu-item:hover::after {
            left: 100%;
        }

        /* Badges améliorés */
        .badge-danger,
        .badge-warning,
        .badge-info {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            min-width: 24px;
            height: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
        }

        .badge-danger {
            box-shadow: 0 2px 10px rgba(239, 68, 68, 0.4);
        }

        .badge-warning {
            box-shadow: 0 2px 10px rgba(245, 158, 11, 0.4);
        }

        .badge-info {
            box-shadow: 0 2px 10px rgba(59, 130, 246, 0.4);
        }

        /* Animation des icônes */
        .menu-item i {
            transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        .menu-item:hover i {
            transform: scale(1.2) rotate(5deg);
        }

        .menu-item.active-menu i {
            transform: scale(1.15);
            animation: iconBounce 0.5s ease;
        }

        @keyframes iconBounce {

            0%,
            100% {
                transform: scale(1.15);
            }

            50% {
                transform: scale(1.25);
            }
        }

        /* Titres de section */
        .section-title {
            position: relative;
            margin: 1.5rem 0 0.5rem;
        }

        .section-title div {
            letter-spacing: 0.05em;
            background: rgba(240, 253, 244, 0.7);
            border-radius: 10px;
            backdrop-filter: blur(5px);
        }

        /* Animation au chargement */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .menu-item {
            animation: slideIn 0.5s ease-out forwards;
            opacity: 0;
        }

        .menu-item:nth-child(1) {
            animation-delay: 0.1s;
        }

        .menu-item:nth-child(2) {
            animation-delay: 0.15s;
        }

        .menu-item:nth-child(3) {
            animation-delay: 0.2s;
        }

        .menu-item:nth-child(4) {
            animation-delay: 0.25s;
        }

        .menu-item:nth-child(5) {
            animation-delay: 0.3s;
        }

        .menu-item:nth-child(6) {
            animation-delay: 0.35s;
        }

        .menu-item:nth-child(7) {
            animation-delay: 0.4s;
        }

        .menu-item:nth-child(8) {
            animation-delay: 0.45s;
        }

        /* Effet de brillance sur les éléments actifs */
        .active-menu::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg,
                    rgba(255, 255, 255, 0.3) 0%,
                    rgba(255, 255, 255, 0.1) 50%,
                    rgba(255, 255, 255, 0) 100%);
            border-radius: inherit;
            pointer-events: none;
        }

        /* Responsive design amélioré */
        @media (max-width: 768px) {
            .sidebar {
                border-right: none;
                border-bottom: 1px solid rgba(16, 185, 129, 0.1);
                box-shadow: 0 4px 20px rgba(16, 185, 129, 0.1);
            }

            .menu-item {
                padding: 0.875rem 1rem;
                margin: 0.125rem 0;
            }

            .menu-item:hover {
                transform: translateY(-2px);
            }

            .section-title div {
                padding: 0.75rem 1rem;
            }

            .badge-danger,
            .badge-warning,
            .badge-info {
                font-size: 0.65rem;
                padding: 0.125rem 0.5rem;
                min-width: 20px;
                height: 18px;
            }
        }

        /* Dark mode support (optionnel) */
        @media (prefers-color-scheme: dark) {
            .sidebar {
                background: linear-gradient(165deg, #1a1a2e 0%, #16213e 100%);
                border-right-color: rgba(16, 185, 129, 0.2);
            }

            .menu-item:not(.active-menu) {
                color: #cbd5e1;
            }

            .menu-item:hover:not(.active-menu) {
                background: linear-gradient(to right, rgba(16, 185, 129, 0.15), transparent);
                color: #ffffff;
            }

            .section-title div {
                background: rgba(16, 185, 129, 0.1);
                color: #10b981;
            }
        }
    </style>


</head>

<body class="bg-gray-100" style="font-family: 'Montserrat', sans-serif;">

    <div class="flex">
        <!-- ========== SIDEBAR FOURNISSEUR - THÈME BLEU DE NUIT ========== -->
        <div class="sidebar w-64 shadow-xl min-h-screen">
            <!-- Logo Section avec effet premium nocturne -->
            <div class="p-6 border-b border-green-800 bg-gradient-to-r from-green-900 via-green-800 to-green-900">
                <div class="flex items-center space-x-3">
                    <div class="relative">
                        <div
                            class="w-12 h-12 bg-gradient-to-br from-green-800 to-green-900 rounded-xl flex items-center justify-center shadow-lg border border-green-700">
                            <i class="fas fa-capsules text-green-300 text-xl"></i>
                        </div>
                        <div
                            class="absolute -top-1 -right-1 w-5 h-5 bg-gradient-to-br from-cyan-400 to-green-500 rounded-full flex items-center justify-center shadow-md border border-green-300">
                            <i class="fas fa-check text-white text-xs"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-white tracking-tight">NAGEX Pharma</h1>
                        <p class="text-xs text-green-200 font-medium opacity-90">Espace Fournisseur</p>
                    </div>
                </div>


            </div>

            <!-- Navigation -->
            <nav class="mt-4 space-y-1 px-2">
                <!-- Tableau de bord -->
                <a href="?page=dashboard"
                    class="menu-item group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-300 ease-out <?php echo $current_page == 'dashboard' ? 'active-menu text-white' : 'text-gray-300 hover:text-white'; ?>">
                    <div class="relative">
                        <i
                            class="fas fa-home w-5 h-5 mr-3 <?php echo $current_page == 'dashboard' ? 'text-white' : 'text-gray-400 group-hover:text-blue-300'; ?>"></i>
                    </div>
                    <span class="flex-1">Tableau de bord</span>
                    <?php if ($current_page == 'dashboard'): ?>
                        <div class="absolute -right-2 top-1/2 -translate-y-1/2 w-2 h-8 bg-blue-500 rounded-l-lg"></div>
                    <?php endif; ?>
                </a>

                <!-- Section Catalogue -->
                <div class="section-title">
                    <div
                        class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-blue-300 flex items-center">
                        <div class="w-8 h-px bg-blue-700 mr-3"></div>
                        <i class="fas fa-boxes text-blue-400 mr-2"></i>
                        <span>Catalogue</span>
                        <div class="flex-1 h-px bg-blue-700 ml-3"></div>
                    </div>
                </div>

                <!-- Mes produits -->
                <a href="?page=produits"
                    class="menu-item group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-300 ease-out <?php echo $current_page == 'produits' ? 'active-menu text-white' : 'text-gray-300 hover:text-white'; ?>">
                    <div class="relative">
                        <i
                            class="fas fa-list w-5 h-5 mr-3 <?php echo $current_page == 'produits' ? 'text-white' : 'text-gray-400 group-hover:text-blue-300'; ?>"></i>
                    </div>
                    <span class="flex-1">Mes produits</span>
                    <?php if (isset($stats['produits_total']) && $stats['produits_total'] > 0): ?>
                        <span class="badge-success ml-auto text-white text-xs font-bold py-1 px-2.5 rounded-full shadow-sm">
                            <?php echo $stats['produits_total']; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($current_page == 'produits'): ?>
                        <div class="absolute -right-2 top-1/2 -translate-y-1/2 w-2 h-8 bg-blue-500 rounded-l-lg"></div>
                    <?php endif; ?>
                </a>

                <!-- Ajouter produit -->
                <a href="?page=ajouter_produit"
                    class="menu-item group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-300 ease-out <?php echo $current_page == 'ajouter_produit' ? 'active-menu text-white' : 'text-gray-300 hover:text-white'; ?>">
                    <div class="relative">
                        <i
                            class="fas fa-plus-circle w-5 h-5 mr-3 <?php echo $current_page == 'ajouter_produit' ? 'text-white' : 'text-gray-400 group-hover:text-blue-300'; ?>"></i>
                    </div>
                    <span class="flex-1">Ajouter produit</span>
                    <div class="w-2 h-2 bg-blue-400 rounded-full opacity-0 group-hover:opacity-100 transition-opacity">
                    </div>
                    <?php if ($current_page == 'ajouter_produit'): ?>
                        <div class="absolute -right-2 top-1/2 -translate-y-1/2 w-2 h-8 bg-blue-500 rounded-l-lg"></div>
                    <?php endif; ?>
                </a>

                <!-- Section Commandes -->
                <div class="section-title">
                    <div
                        class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-blue-300 flex items-center">
                        <div class="w-8 h-px bg-blue-700 mr-3"></div>
                        <i class="fas fa-shopping-cart text-blue-400 mr-2"></i>
                        <span>Commandes</span>
                        <div class="flex-1 h-px bg-blue-700 ml-3"></div>
                    </div>
                </div>

                <!-- Commandes en cours -->
                <a href="?page=commandes"
                    class="menu-item group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-300 ease-out <?php echo $current_page == 'commandes' ? 'active-menu text-white' : 'text-gray-300 hover:text-white'; ?>">
                    <div class="relative">
                        <i
                            class="fas fa-clipboard-list w-5 h-5 mr-3 <?php echo $current_page == 'commandes' ? 'text-white' : 'text-gray-400 group-hover:text-blue-300'; ?>"></i>
                    </div>
                    <span class="flex-1">Commandes en cours</span>
                    <?php if (isset($stats['commandes_encours']) && $stats['commandes_encours'] > 0): ?>
                        <span
                            class="badge-warning ml-auto text-white text-xs font-bold py-1 px-2.5 rounded-full shadow-sm animate-pulse">
                            <?php echo $stats['commandes_encours']; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($current_page == 'commandes'): ?>
                        <div class="absolute -right-2 top-1/2 -translate-y-1/2 w-2 h-8 bg-blue-500 rounded-l-lg"></div>
                    <?php endif; ?>
                </a>

                <!-- Historique -->
                <a href="?page=historique"
                    class="menu-item group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-300 ease-out <?php echo $current_page == 'historique' ? 'active-menu text-white' : 'text-gray-300 hover:text-white'; ?>">
                    <div class="relative">
                        <i
                            class="fas fa-history w-5 h-5 mr-3 <?php echo $current_page == 'historique' ? 'text-white' : 'text-gray-400 group-hover:text-blue-300'; ?>"></i>
                    </div>
                    <span class="flex-1">Historique</span>
                    <?php if ($current_page == 'historique'): ?>
                        <div class="absolute -right-2 top-1/2 -translate-y-1/2 w-2 h-8 bg-blue-500 rounded-l-lg"></div>
                    <?php endif; ?>
                </a>

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
                <!-- ========== DASHBOARD FOURNISSEUR ========== -->
                <div class="mb-8">
                    <!-- En-tête avec identité fournisseur -->
                    <div class="mb-8">
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between mb-6">
                            <div class="mb-6 lg:mb-0">
                                <div
                                    class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-amber-50 to-orange-50 rounded-2xl border border-amber-100 mb-4">
                                    <i class="fas fa-truck text-amber-500 mr-2"></i>
                                    <span class="text-sm font-medium text-amber-700">Espace Fournisseur Pro</span>
                                </div>
                                <h1 class="text-3xl lg:text-4xl font-bold text-gray-900 tracking-tight mb-2">Tableau de bord
                                    Fournisseur</h1>
                                <p class="text-gray-600 text-lg">
                                    Bienvenue, <span class="font-semibold text-amber-600">
                                        <?php
                                        // Corrigé: Vérifier si $user_info existe et a les clés nécessaires
                                        if (isset($user_info['nom_societe']) && !empty($user_info['nom_societe'])) {
                                            echo htmlspecialchars($user_info['nom_societe'], ENT_QUOTES, 'UTF-8');
                                        } elseif (isset($user_info['nom']) && !empty($user_info['nom'])) {
                                            echo htmlspecialchars($user_info['nom'], ENT_QUOTES, 'UTF-8');
                                        } elseif (isset($_SESSION['user_nom']) && !empty($_SESSION['user_nom'])) {
                                            echo htmlspecialchars($_SESSION['user_nom'], ENT_QUOTES, 'UTF-8');
                                        } else {
                                            echo 'Fournisseur';
                                        }
                                        ?>
                                    </span>
                                    • Gestion et analyse de vos produits
                                </p>
                            </div>


                        </div>
                    </div>
                </div>

                <!-- Bandeau note de qualité premium -->
                <div class="mb-8 bg-gradient-to-r from-green-500 to-green-500 rounded-2xl shadow-xl overflow-hidden">
                    <div class="relative p-6 lg:p-8">
                        <!-- Effet de brillance -->
                        <div
                            class="absolute top-0 right-0 w-64 h-64 bg-gradient-to-bl from-white/10 to-transparent rounded-full -translate-y-32 translate-x-32">
                        </div>

                        <div class="relative grid grid-cols-1 lg:grid-cols-3 gap-6 items-center">
                            <!-- Note et étoiles -->
                            <div class="text-center lg:text-left">
                                <h2 class="text-xl font-bold text-white mb-3">Votre note de qualité</h2>
                                <div class="flex items-center justify-center lg:justify-start space-x-2 mb-2">
                                    <?php
                                    // Corrigé: Vérifier l'existence de $stats
                                    $note_qualite = isset($stats['note_qualite']) ? floatval($stats['note_qualite']) : 0;
                                    $note_arrondie = floor($note_qualite);
                                    $has_demi = fmod($note_qualite, 1) > 0;

                                    for ($i = 1; $i <= 5; $i++): ?>
                                        <?php if ($i <= $note_arrondie): ?>
                                            <i class="fas fa-star text-yellow-300 text-3xl"></i>
                                        <?php elseif ($i == $note_arrondie + 1 && $has_demi): ?>
                                            <i class="fas fa-star-half-alt text-yellow-300 text-3xl"></i>
                                        <?php else: ?>
                                            <i class="far fa-star text-white text-3xl"></i>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </div>
                                <div class="flex items-center justify-center lg:justify-start">
                                    <span
                                        class="text-4xl font-bold text-white mr-3"><?php echo number_format($note_qualite, 1); ?></span>
                                    <span class="text-white">/5</span>
                                </div>
                                <p class="text-white text-sm mt-2">
                                    <?php
                                    $appreciation = '';
                                    if ($note_qualite >= 4.5)
                                        $appreciation = 'Excellent ⭐';
                                    elseif ($note_qualite >= 4.0)
                                        $appreciation = 'Très bon';
                                    elseif ($note_qualite >= 3.5)
                                        $appreciation = 'Bon';
                                    elseif ($note_qualite >= 3.0)
                                        $appreciation = 'Satisfaisant';
                                    else
                                        $appreciation = 'À améliorer';
                                    echo htmlspecialchars($appreciation, ENT_QUOTES, 'UTF-8');
                                    ?>
                                </p>
                            </div>

                            <!-- Statistique produits -->
                            <div class="text-center lg:text-center">
                                <div
                                    class="inline-block bg-white/20 backdrop-blur-sm rounded-2xl p-6 border border-white/30">
                                    <p class="text-5xl font-bold text-white mb-1">
                                        <?php echo isset($stats['total_produits']) ? $stats['total_produits'] : 0; ?>
                                    </p>
                                    <p class="text-white text-sm font-medium">Produits fournis</p>
                                    <div class="mt-3 flex justify-center space-x-2">
                                        <span
                                            class="inline-flex items-center px-2 py-1 bg-emerald-500/30 text-emerald-100 text-xs rounded-full">
                                            <i class="fas fa-check-circle mr-1"></i>
                                            <?php echo isset($stats['produits_actifs']) ? $stats['produits_actifs'] : 0; ?>
                                            actifs
                                        </span>
                                        <?php if (isset($stats['produits_attente']) && $stats['produits_attente'] > 0): ?>
                                            <span
                                                class="inline-flex items-center px-2 py-1 bg-amber-500/30 text-amber-100 text-xs rounded-full">
                                                <i class="fas fa-clock mr-1"></i>
                                                <?php echo $stats['produits_attente']; ?> en attente
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Progression et objectif -->
                            <div class="text-center lg:text-right">
                                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-5 border border-white/20">
                                    <p class="text-white font-semibold mb-2">Objectif mensuel</p>
                                    <div class="w-full bg-white/20 rounded-full h-3 mb-2 overflow-hidden">
                                        <?php
                                        $objectif = 1000000; // 1 million FC
                                        $ventes_totales = isset($stats['ventes_totales']) ? $stats['ventes_totales'] : 0;
                                        $progress = $objectif > 0 ? min(100, ($ventes_totales / $objectif) * 100) : 0;
                                        ?>
                                        <div class="h-3 bg-gradient-to-r from-emerald-400 to-cyan-400 rounded-full transition-all duration-1000"
                                            style="width: <?php echo $progress; ?>%"></div>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span
                                            class="text-amber-100/80"><?php echo isset($stats['ventes_totales']) ? formatMontant($stats['ventes_totales']) : formatMontant(0); ?></span>
                                        <span
                                            class="text-white font-semibold"><?php echo number_format($progress, 1); ?>%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cartes de statistiques premium -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Produits actifs -->
                    <div
                        class="stat-card group relative bg-gradient-to-br from-white to-gray-50 p-6 rounded-2xl shadow-lg border border-emerald-200 hover:shadow-xl transition-all duration-300 hover:-translate-y-1">
                        <div
                            class="absolute inset-0 bg-gradient-to-br from-emerald-500/5 to-transparent rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                        </div>
                        <div class="relative flex items-center">
                            <div class="p-4 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-xl shadow-lg">
                                <i class="fas fa-check-circle text-white text-2xl"></i>
                            </div>
                            <div class="ml-5">
                                <p class="text-gray-500 text-sm font-medium mb-1">Produits actifs</p>
                                <p class="text-3xl font-bold text-gray-900">
                                    <?php echo isset($stats['produits_actifs']) ? $stats['produits_actifs'] : 0; ?>
                                </p>
                                <div class="mt-2 text-xs text-emerald-600 font-semibold flex items-center">
                                    <?php
                                    $total_produits = isset($stats['total_produits']) ? $stats['total_produits'] : 1;
                                    $pourcentage_actifs = $total_produits > 0 ? (isset($stats['produits_actifs']) ? ($stats['produits_actifs'] / $total_produits * 100) : 0) : 0;
                                    ?>
                                    <i
                                        class="fas <?php echo $pourcentage_actifs >= 90 ? 'fa-trend-up' : 'fa-trend-down'; ?> mr-1"></i>
                                    <?php echo round($pourcentage_actifs, 1); ?>% du catalogue
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Commandes en attente -->
                    <div
                        class="stat-card group relative bg-gradient-to-br from-white to-gray-50 p-6 rounded-2xl shadow-lg border border-blue-200 hover:shadow-xl transition-all duration-300 hover:-translate-y-1">
                        <div
                            class="absolute inset-0 bg-gradient-to-br from-blue-500/5 to-transparent rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                        </div>
                        <div class="relative flex items-center">
                            <div class="p-4 bg-gradient-to-br from-blue-500 to-cyan-600 rounded-xl shadow-lg">
                                <i class="fas fa-shopping-cart text-white text-2xl"></i>
                            </div>
                            <div class="ml-5">
                                <p class="text-gray-500 text-sm font-medium mb-1">Commandes en attente</p>
                                <p class="text-3xl font-bold text-gray-900">
                                    <?php echo isset($stats['commandes_attente']) ? $stats['commandes_attente'] : 0; ?>
                                </p>
                                <div class="mt-2 text-xs text-blue-600 font-semibold flex items-center">
                                    <?php if (isset($stats['commandes_attente']) && $stats['commandes_attente'] > 0): ?>
                                        <i class="fas fa-clock mr-1 animate-pulse"></i>
                                        Nécessite votre attention
                                    <?php else: ?>
                                        <i class="fas fa-check mr-1"></i>
                                        À jour
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ventes totales -->
                    <div
                        class="stat-card group relative bg-gradient-to-br from-white to-gray-50 p-6 rounded-2xl shadow-lg border border-purple-200 hover:shadow-xl transition-all duration-300 hover:-translate-y-1">
                        <div
                            class="absolute inset-0 bg-gradient-to-br from-purple-500/5 to-transparent rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                        </div>
                        <div class="relative flex items-center">
                            <div class="p-4 bg-gradient-to-br from-purple-500 to-violet-600 rounded-xl shadow-lg">
                                <i class="fas fa-chart-line text-white text-2xl"></i>
                            </div>
                            <div class="ml-5">
                                <p class="text-gray-500 text-sm font-medium mb-1">Ventes totales</p>
                                <p class="text-3xl font-bold text-gray-900">
                                    <?php echo isset($stats['ventes_totales']) ? formatMontant($stats['ventes_totales']) : formatMontant(0); ?>
                                </p>
                                <div class="mt-2 text-xs text-purple-600 font-semibold flex items-center">
                                    <i class="fas fa-calendar-alt mr-1"></i>
                                    <?php echo date('M Y'); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Taux de conversion -->
                    <div
                        class="stat-card group relative bg-gradient-to-br from-white to-gray-50 p-6 rounded-2xl shadow-lg border border-amber-200 hover:shadow-xl transition-all duration-300 hover:-translate-y-1">
                        <div
                            class="absolute inset-0 bg-gradient-to-br from-amber-500/5 to-transparent rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                        </div>
                        <div class="relative flex items-center">
                            <div class="p-4 bg-gradient-to-br from-amber-500 to-orange-600 rounded-xl shadow-lg">
                                <i class="fas fa-percentage text-white text-2xl"></i>
                            </div>
                            <div class="ml-5">
                                <p class="text-gray-500 text-sm font-medium mb-1">Taux de conversion</p>
                                <p class="text-3xl font-bold text-gray-900">
                                    <?php
                                    $commandes_traitees = isset($stats['commandes_traitees']) ? $stats['commandes_traitees'] : 0;
                                    $total_commandes = isset($stats['total_commandes']) ? $stats['total_commandes'] : 1;
                                    $taux = $total_commandes > 0 ? ($commandes_traitees / $total_commandes * 100) : 0;
                                    echo number_format($taux, 1);
                                    ?>%
                                </p>
                                <div class="mt-2 text-xs text-amber-600 font-semibold flex items-center">
                                    <i class="fas fa-chart-pie mr-1"></i>
                                    Commandes traitées
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Grille principale : Commandes + Actions -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                    <!-- Commandes récentes -->
                    <div
                        class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
                        <div class="p-6 border-b border-gray-100">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <div class="p-3 bg-gradient-to-br from-blue-100 to-cyan-100 rounded-xl mr-4">
                                        <i class="fas fa-history text-blue-600 text-xl"></i>
                                    </div>
                                    <div>
                                        <h2 class="text-xl font-bold text-gray-900">Commandes récentes</h2>
                                        <p class="text-sm text-gray-500">Les 5 dernières commandes à traiter</p>
                                    </div>
                                </div>
                                <div class="text-xs font-semibold text-blue-600 bg-blue-50 px-3 py-1 rounded-full">
                                    <?php echo isset($commandes_recentes) ? count($commandes_recentes) : 0; ?> commandes
                                </div>
                            </div>
                        </div>

                        <div class="p-6">
                            <?php if (isset($commandes_recentes) && count($commandes_recentes) > 0): ?>
                                <div class="space-y-4">
                                    <?php foreach ($commandes_recentes as $commande): ?>
                                        <div
                                            class="group relative bg-white p-4 rounded-xl border border-gray-200 hover:border-blue-200 hover:shadow-md transition-all duration-200">
                                            <div class="flex items-center justify-between">
                                                <div class="flex-1">
                                                    <div class="flex items-center mb-2">
                                                        <span
                                                            class="font-bold text-gray-900 mr-3">#<?php echo htmlspecialchars($commande['numero_commande'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <span class="text-xs px-2.5 py-1 rounded-full font-semibold <?php
                                                        $statut = $commande['statut'] ?? 'en_attente';
                                                        echo $statut == 'paye' ? 'bg-emerald-100 text-emerald-800' :
                                                            ($statut == 'en_attente' ? 'bg-amber-100 text-amber-800' :
                                                                ($statut == 'expedie' ? 'bg-blue-100 text-blue-800' :
                                                                    'bg-rose-100 text-rose-800'));
                                                        ?>">
                                                            <i class="fas <?php
                                                            echo $statut == 'paye' ? 'fa-check-circle' :
                                                                ($statut == 'en_attente' ? 'fa-clock' :
                                                                    ($statut == 'expedie' ? 'fa-shipping-fast' :
                                                                        'fa-times-circle'));
                                                            ?> mr-1.5 text-xs"></i>
                                                            <?php echo htmlspecialchars(ucfirst($statut), ENT_QUOTES, 'UTF-8'); ?>
                                                        </span>
                                                    </div>
                                                    <div class="text-sm text-gray-600">
                                                        <div class="flex items-center mb-1">
                                                            <i class="fas fa-user mr-2 text-gray-400"></i>
                                                            <?php echo htmlspecialchars($commande['client_nom'] ?? 'Client', ENT_QUOTES, 'UTF-8'); ?>
                                                        </div>
                                                        <div class="flex items-center">
                                                            <i class="fas fa-calendar-day mr-2 text-gray-400"></i>
                                                            <?php echo isset($commande['date_commande']) ? date('d/m/Y H:i', strtotime($commande['date_commande'])) : 'Date non disponible'; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="text-right min-w-28">
                                                    <p class="text-lg font-bold text-emerald-600 mb-1">
                                                        <?php echo isset($commande['montant_total']) ? formatMontant($commande['montant_total']) : formatMontant(0); ?>
                                                    </p>
                                                    <button onclick="gererCommande(<?php echo $commande['id'] ?? 0; ?>)"
                                                        class="text-sm text-blue-600 hover:text-blue-800 font-medium flex items-center justify-end group">
                                                        Gérer
                                                        <i
                                                            class="fas fa-chevron-right ml-1 text-xs group-hover:translate-x-1 transition-transform"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="mt-6 pt-6 border-t border-gray-100 text-center">
                                    <a href="?page=commandes"
                                        class="inline-flex items-center text-blue-600 hover:text-blue-800 font-semibold text-sm group">
                                        <span>Voir toutes les commandes</span>
                                        <i
                                            class="fas fa-arrow-right ml-2 transform group-hover:translate-x-1 transition-transform duration-200"></i>
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-10">
                                    <div
                                        class="w-20 h-20 mx-auto mb-6 bg-gray-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-clipboard-list text-gray-400 text-3xl"></i>
                                    </div>
                                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Aucune commande récente</h3>
                                    <p class="text-gray-600 mb-6 max-w-sm mx-auto">
                                        Vous n'avez pas de commandes en attente. Vos produits sont en ligne et prêts à être
                                        commandés.
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Actions rapides -->
                    <div
                        class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
                        <div class="p-6 border-b border-gray-100">
                            <div class="flex items-center">
                                <div class="p-3 bg-gradient-to-br from-amber-100 to-orange-100 rounded-xl mr-4">
                                    <i class="fas fa-bolt text-amber-600 text-xl"></i>
                                </div>
                                <div>
                                    <h2 class="text-xl font-bold text-gray-900">Actions rapides</h2>
                                    <p class="text-sm text-gray-500">Accès direct aux fonctions essentielles</p>
                                </div>
                            </div>
                        </div>

                        <div class="p-6">
                            <div class="space-y-5">
                                <!-- Ajouter produit -->
                                <a href="?page=ajouter_produit"
                                    class="group relative flex items-center justify-between p-5 bg-gradient-to-r from-purple-50 to-violet-50 rounded-xl border border-purple-200 hover:border-purple-300 hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5">
                                    <div class="flex items-center">
                                        <div
                                            class="p-3 bg-gradient-to-br from-purple-500 to-violet-600 rounded-lg shadow-lg mr-4 group-hover:scale-110 transition-transform duration-300">
                                            <i class="fas fa-plus-circle text-white text-xl"></i>
                                        </div>
                                        <div>
                                            <p class="font-bold text-gray-900 text-lg mb-1">Ajouter un produit</p>
                                            <p class="text-sm text-gray-600">Étendez votre catalogue avec de nouveaux
                                                produits</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center">
                                        <span
                                            class="text-purple-600 font-semibold text-sm mr-3 hidden md:inline">Accéder</span>
                                        <i
                                            class="fas fa-chevron-right text-purple-400 text-lg group-hover:translate-x-2 transition-transform duration-300"></i>
                                    </div>
                                </a>

                                <!-- Voir commandes -->
                                <a href="?page=commandes"
                                    class="group relative flex items-center justify-between p-5 bg-gradient-to-r from-blue-50 to-cyan-50 rounded-xl border border-blue-200 hover:border-blue-300 hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5">
                                    <div class="flex items-center">
                                        <div
                                            class="p-3 bg-gradient-to-br from-blue-500 to-cyan-600 rounded-lg shadow-lg mr-4 group-hover:scale-110 transition-transform duration-300">
                                            <i class="fas fa-clipboard-list text-white text-xl"></i>
                                        </div>
                                        <div>
                                            <p class="font-bold text-gray-900 text-lg mb-1">Gérer les commandes</p>
                                            <p class="text-sm text-gray-600">
                                                <?php echo isset($stats['commandes_attente']) ? $stats['commandes_attente'] : 0; ?>
                                                commande(s) en attente de traitement
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex items-center">
                                        <?php if (isset($stats['commandes_attente']) && $stats['commandes_attente'] > 0): ?>
                                            <span
                                                class="bg-gradient-to-r from-blue-500 to-cyan-600 text-white text-xs font-bold py-1 px-2.5 rounded-full shadow-sm mr-3">
                                                <?php echo $stats['commandes_attente']; ?>
                                            </span>
                                        <?php endif; ?>
                                        <span class="text-blue-600 font-semibold text-sm mr-3 hidden md:inline">Voir</span>
                                        <i
                                            class="fas fa-chevron-right text-blue-400 text-lg group-hover:translate-x-2 transition-transform duration-300"></i>
                                    </div>
                                </a>

                                <!-- Messages -->
                                <a href="?page=messages"
                                    class="group relative flex items-center justify-between p-5 bg-gradient-to-r from-emerald-50 to-teal-50 rounded-xl border border-emerald-200 hover:border-emerald-300 hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5">
                                    <div class="flex items-center">
                                        <div
                                            class="p-3 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-lg shadow-lg mr-4 group-hover:scale-110 transition-transform duration-300">
                                            <i class="fas fa-envelope text-white text-xl"></i>
                                        </div>
                                        <div>
                                            <p class="font-bold text-gray-900 text-lg mb-1">Contacter la pharmacie</p>
                                            <p class="text-sm text-gray-600">Envoyez un message à l'administration NAGEX</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center">
                                        <span
                                            class="text-emerald-600 font-semibold text-sm mr-3 hidden md:inline">Contacter</span>
                                        <i
                                            class="fas fa-chevron-right text-emerald-400 text-lg group-hover:translate-x-2 transition-transform duration-300"></i>
                                    </div>
                                </a>

                                <!-- Statistiques -->
                                <a href="?page=statistiques"
                                    class="group relative flex items-center justify-between p-5 bg-gradient-to-r from-amber-50 to-orange-50 rounded-xl border border-amber-200 hover:border-amber-300 hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5">
                                    <div class="flex items-center">
                                        <div
                                            class="p-3 bg-gradient-to-br from-amber-500 to-orange-600 rounded-lg shadow-lg mr-4 group-hover:scale-110 transition-transform duration-300">
                                            <i class="fas fa-chart-bar text-white text-xl"></i>
                                        </div>
                                        <div>
                                            <p class="font-bold text-gray-900 text-lg mb-1">Analyses détaillées</p>
                                            <p class="text-sm text-gray-600">Consultez vos performances et tendances de
                                                vente</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center">
                                        <span
                                            class="text-amber-600 font-semibold text-sm mr-3 hidden md:inline">Analyser</span>
                                        <i
                                            class="fas fa-chevron-right text-amber-400 text-lg group-hover:translate-x-2 transition-transform duration-300"></i>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section aperçu stocks -->
                <?php if (isset($produits_stock) && !empty($produits_stock)): ?>
                    <div class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 p-6">
                        <div class="flex items-center justify-between mb-6">
                            <div class="flex items-center">
                                <div class="p-3 bg-gradient-to-br from-rose-100 to-pink-100 rounded-xl mr-4">
                                    <i class="fas fa-boxes text-rose-600 text-xl"></i>
                                </div>
                                <div>
                                    <h2 class="text-xl font-bold text-gray-900">Aperçu des stocks</h2>
                                    <p class="text-sm text-gray-500">Niveaux de stock de vos produits les plus vendus</p>
                                </div>
                            </div>
                            <a href="?page=stocks"
                                class="text-sm text-blue-600 hover:text-blue-800 font-semibold flex items-center group">
                                Gérer les stocks
                                <i class="fas fa-arrow-right ml-1 text-xs group-hover:translate-x-1 transition-transform"></i>
                            </a>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                            <?php foreach ($produits_stock as $produit):
                                if (isset($produit['stock']) && isset($produit['stock_min'])) {
                                    $pourcentage = min(100, ($produit['stock'] / ($produit['stock_min'] * 2)) * 100);
                                    $couleur = $produit['stock'] > $produit['stock_min'] * 1.5 ? 'emerald' :
                                        ($produit['stock'] > $produit['stock_min'] ? 'amber' : 'rose');
                                } else {
                                    // Valeurs par défaut si les données ne sont pas définies
                                    $pourcentage = 0;
                                    $couleur = 'gray';
                                }
                                ?>
                                <div class="bg-white p-4 rounded-xl border border-gray-200 hover:shadow-md transition-shadow">
                                    <div class="flex justify-between items-start mb-3">
                                        <h4 class="font-semibold text-gray-900 text-sm">
                                            <?php echo htmlspecialchars($produit['nom'] ?? 'Produit', ENT_QUOTES, 'UTF-8'); ?>
                                        </h4>
                                        <span
                                            class="text-xs px-2 py-1 rounded-full font-semibold bg-<?php echo $couleur; ?>-100 text-<?php echo $couleur; ?>-800">
                                            <?php echo isset($produit['stock']) ? $produit['stock'] : 0; ?> unités
                                        </span>
                                    </div>
                                    <div class="mb-2">
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="h-2 bg-<?php echo $couleur; ?>-500 rounded-full transition-all duration-500"
                                                style="width: <?php echo $pourcentage; ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="flex justify-between text-xs text-gray-500">
                                        <span>Stock min:
                                            <?php echo isset($produit['stock_min']) ? $produit['stock_min'] : 0; ?></span>
                                        <?php if (isset($produit['tendance'])): ?>
                                            <span class="flex items-center">
                                                <i
                                                    class="fas fa-arrow-<?php echo $produit['tendance']; ?> text-<?php
                                                        echo $produit['tendance'] == 'up' ? 'emerald' :
                                                            ($produit['tendance'] == 'down' ? 'rose' : 'gray'); ?>-500 mr-1"></i>
                                                <?php echo $produit['tendance'] == 'up' ? 'En hausse' :
                                                    ($produit['tendance'] == 'down' ? 'En baisse' : 'Stable'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <script>
                function actualiserDashboard() {
                    // Animation de rotation sur le bouton
                    const btn = event.currentTarget;
                    const icon = btn.querySelector('i');

                    // Désactiver temporairement le bouton
                    btn.disabled = true;

                    // Animation
                    icon.classList.add('animate-spin');

                    // Simuler un rafraîchissement
                    setTimeout(() => {
                        window.location.reload();
                    }, 800);
                }

                function gererCommande(commandeId) {
                    // Redirection vers la page de gestion de commande
                    window.location.href = `?page=commande&id=${commandeId}`;
                }
            </script>

        <?php elseif ($current_page == 'produits'): ?>
            <!-- ========== MES PRODUITS ========== -->
            <!-- ========== PAGE MES PRODUITS (FOURNISSEUR) ========== -->
            <div class="mb-8">
                <!-- En-tête avec statistiques -->
                <div class="mb-8">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between mb-6">
                        <div class="mb-6 lg:mb-0">
                            <div
                                class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-emerald-50 to-teal-50 rounded-2xl border border-emerald-100 mb-4">
                                <i class="fas fa-industry text-emerald-500 mr-2"></i>
                                <span class="text-sm font-medium text-emerald-700">Espace Fournisseur</span>
                            </div>
                            <h1 class="text-3xl lg:text-4xl font-bold text-gray-900 tracking-tight mb-2">Mes produits</h1>
                            <p class="text-gray-600 text-lg">Gérez votre catalogue de produits fournis à NAGEX Pharma</p>
                        </div>

                        <div class="flex items-center space-x-4">
                            <!-- Statistiques rapides -->
                            <div
                                class="text-center px-4 py-2 bg-gradient-to-br from-blue-50 to-cyan-50 rounded-xl border border-blue-200">
                                <p class="text-sm text-gray-600 mb-1">Produits actifs</p>
                                <p class="text-xl font-bold text-blue-600">
                                    <?php
                                    $produits_actifs = array_filter($produits_fournis, function ($p) {
                                        return $p['statut'] == 'actif';
                                    });
                                    echo count($produits_actifs);
                                    ?>
                                </p>
                            </div>

                            <!-- Bouton ajouter produit -->
                            <a href="?page=ajouter_produit"
                                class="inline-flex items-center px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-semibold rounded-xl hover:from-emerald-600 hover:to-teal-700 shadow-lg hover:shadow-xl transition-all group">
                                <i class="fas fa-plus-circle mr-2 group-hover:scale-110 transition-transform"></i>
                                Ajouter un produit
                            </a>
                        </div>
                    </div>

                    <!-- Statistiques détaillées -->
                    <?php if (count($produits_fournis) > 0): ?>
                        <?php
                        $total_ventes = array_sum(array_column($produits_fournis, 'ventes_total'));
                        $revenu_total = array_sum(array_map(function ($p) {
                            return $p['prix_fc'] * $p['ventes_total'];
                        }, $produits_fournis));
                        ?>

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                            <div class="bg-gradient-to-br from-blue-50 to-cyan-50 rounded-2xl border border-blue-200 p-5">
                                <div class="flex items-center">
                                    <div class="p-3 bg-white rounded-xl shadow-sm mr-4">
                                        <i class="fas fa-cubes text-blue-500 text-xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600 mb-1">Total produits</p>
                                        <p class="text-2xl font-bold text-gray-900"><?php echo count($produits_fournis); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-gradient-to-br from-emerald-50 to-teal-50 rounded-2xl border border-emerald-200 p-5">
                                <div class="flex items-center">
                                    <div class="p-3 bg-white rounded-xl shadow-sm mr-4">
                                        <i class="fas fa-chart-line text-emerald-500 text-xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600 mb-1">Ventes totales</p>
                                        <p class="text-2xl font-bold text-gray-900"><?php echo $total_ventes; ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-gradient-to-br from-purple-50 to-violet-50 rounded-2xl border border-purple-200 p-5">
                                <div class="flex items-center">
                                    <div class="p-3 bg-white rounded-xl shadow-sm mr-4">
                                        <i class="fas fa-sack-dollar text-purple-500 text-xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600 mb-1">Revenu généré</p>
                                        <p class="text-2xl font-bold text-gray-900"><?php echo formatMontant($revenu_total); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-gradient-to-br from-amber-50 to-orange-50 rounded-2xl border border-amber-200 p-5">
                                <div class="flex items-center">
                                    <div class="p-3 bg-white rounded-xl shadow-sm mr-4">
                                        <i class="fas fa-star text-amber-500 text-xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600 mb-1">Taux d'activation</p>
                                        <p class="text-2xl font-bold text-gray-900">
                                            <?php echo round(count($produits_actifs) / max(1, count($produits_fournis)) * 100); ?>%
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Filtres et recherche -->
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-sm border border-gray-200 p-4 mb-6">
                            <div class="flex flex-col md:flex-row md:items-center justify-between">
                                <div class="mb-4 md:mb-0">
                                    <div class="flex items-center">
                                        <i class="fas fa-filter text-blue-500 mr-3"></i>
                                        <h3 class="font-semibold text-gray-900 text-lg">Filtrer votre catalogue</h3>
                                    </div>
                                </div>
                                <div class="flex space-x-3">
                                    <!-- Barre de recherche -->
                                    <div class="relative">
                                        <input type="text" placeholder="Rechercher un produit..."
                                            class="pl-10 pr-4 py-2 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm">
                                        <i
                                            class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                    </div>

                                    <!-- Filtre par statut -->
                                    <select
                                        class="px-4 py-2 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent shadow-sm appearance-none">
                                        <option value="">Tous les statuts</option>
                                        <option value="actif">Actif</option>
                                        <option value="en_attente">En attente</option>
                                        <option value="inactif">Inactif</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Contenu principal -->
                <?php if (count($produits_fournis) > 0): ?>
                    <!-- Navigation par statut -->
                    <div class="mb-6 overflow-x-auto">
                        <div class="flex space-x-2 pb-2">
                            <a href="?page=mes_produits"
                                class="inline-flex items-center px-4 py-2 rounded-xl <?php echo empty($_GET['statut']) ? 'bg-gradient-to-r from-blue-600 to-cyan-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> transition-colors whitespace-nowrap">
                                <i class="fas fa-layer-group mr-2"></i>
                                Tous (<?php echo count($produits_fournis); ?>)
                            </a>
                            <a href="?page=mes_produits&statut=actif"
                                class="inline-flex items-center px-4 py-2 rounded-xl <?php echo ($_GET['statut'] ?? '') == 'actif' ? 'bg-gradient-to-r from-emerald-600 to-teal-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> transition-colors whitespace-nowrap">
                                <i class="fas fa-check-circle mr-2"></i>
                                Actifs (<?php echo count($produits_actifs); ?>)
                            </a>
                            <a href="?page=mes_produits&statut=en_attente"
                                class="inline-flex items-center px-4 py-2 rounded-xl <?php echo ($_GET['statut'] ?? '') == 'en_attente' ? 'bg-gradient-to-r from-amber-600 to-orange-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> transition-colors whitespace-nowrap">
                                <i class="fas fa-clock mr-2"></i>
                                En attente
                            </a>
                            <a href="?page=mes_produits&statut=inactif"
                                class="inline-flex items-center px-4 py-2 rounded-xl <?php echo ($_GET['statut'] ?? '') == 'inactif' ? 'bg-gradient-to-r from-rose-600 to-pink-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> transition-colors whitespace-nowrap">
                                <i class="fas fa-times-circle mr-2"></i>
                                Inactifs
                            </a>
                        </div>
                    </div>

                    <!-- Liste des produits en cartes -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                        <?php foreach ($produits_fournis as $produit): ?>
                            <div
                                class="product-card group relative bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-200 hover:border-emerald-300 overflow-hidden transition-all duration-300 hover:-translate-y-2 hover:shadow-xl">
                                <!-- Badge statut -->
                                <div class="absolute top-4 right-4 z-10">
                                    <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-bold <?php
                                    echo $produit['statut'] == 'actif' ? 'bg-emerald-100 text-emerald-800' :
                                        ($produit['statut'] == 'en_attente' ? 'bg-amber-100 text-amber-800' :
                                            'bg-rose-100 text-rose-800');
                                    ?>">
                                        <i class="fas <?php
                                        echo $produit['statut'] == 'actif' ? 'fa-check-circle' :
                                            ($produit['statut'] == 'en_attente' ? 'fa-clock' :
                                                'fa-times-circle');
                                        ?> mr-1.5"></i>
                                        <?php echo ucfirst(str_replace('_', ' ', $produit['statut'])); ?>
                                    </span>
                                </div>

                                <!-- Image produit -->
                                <div
                                    class="h-48 bg-gradient-to-br from-blue-50 to-cyan-50 flex items-center justify-center overflow-hidden">
                                    <div class="text-center p-6">
                                        <i class="fas fa-pills text-4xl text-blue-400 mb-3"></i>
                                        <p class="text-xs text-blue-600 font-semibold">NAGEX Pharma</p>
                                    </div>
                                </div>

                                <!-- Corps de la carte -->
                                <div class="p-5">
                                    <!-- Catégorie -->
                                    <div class="mb-3">
                                        <span
                                            class="inline-flex items-center px-3 py-1 bg-emerald-100 text-emerald-800 text-xs font-semibold rounded-full">
                                            <i class="fas fa-tag mr-1.5 text-xs"></i>
                                            <?php echo e($produit['categorie_nom']); ?>
                                        </span>
                                    </div>

                                    <!-- Nom produit -->
                                    <h3
                                        class="font-bold text-gray-900 text-lg mb-2 group-hover:text-emerald-700 transition-colors line-clamp-1">
                                        <?php echo e($produit['nom']); ?>
                                    </h3>

                                    <!-- Description -->
                                    <p class="text-gray-600 text-sm mb-4 line-clamp-2 leading-relaxed">
                                        <?php echo e(substr($produit['description'] ?? 'Produit fourni à NAGEX Pharma', 0, 80)); ?>...
                                    </p>

                                    <!-- Code barre -->
                                    <div class="mb-4 p-3 bg-gray-50 rounded-xl">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <p class="text-xs text-gray-500 mb-1">Code barre</p>
                                                <p class="font-mono font-bold text-gray-900 text-sm">
                                                    <?php echo e($produit['code_barre']); ?>
                                                </p>
                                            </div>
                                            <i class="fas fa-barcode text-gray-400 text-lg"></i>
                                        </div>
                                    </div>

                                    <!-- Prix et ventes -->
                                    <div class="grid grid-cols-2 gap-4 mb-5">
                                        <!-- Prix -->
                                        <div class="bg-gradient-to-br from-blue-50 to-cyan-50 rounded-xl p-3">
                                            <p class="text-xs text-gray-600 mb-1">Prix de vente</p>
                                            <?php if ($produit['prix_fc']): ?>
                                                <p class="font-bold text-emerald-600 text-lg">
                                                    <?php echo formatMontant($produit['prix_fc']); ?>
                                                </p>
                                                <p class="text-xs text-blue-600">
                                                    $<?php
                                                    $prix = $produit['prix_usd'] ?? 0;
                                                    $prix_numerique = is_numeric($prix) ? (float) $prix : 0;
                                                    echo number_format($prix_numerique, 2);
                                                    ?> USD
                                                </p>
                                            <?php else: ?>
                                                <p class="text-sm text-amber-600 font-semibold">À définir</p>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Ventes -->
                                        <div class="bg-gradient-to-br from-purple-50 to-violet-50 rounded-xl p-3">
                                            <p class="text-xs text-gray-600 mb-1">Ventes totales</p>
                                            <p class="font-bold text-purple-600 text-lg">
                                                <?php echo $produit['ventes_total']; ?>
                                            </p>
                                            <p class="text-xs text-gray-500">unités vendues</p>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex space-x-3">
                                        <!-- Bouton Voir -->
                                        <button onclick="ouvrirModalDetails(<?php echo $produit['id']; ?>)"
                                            class="flex-1 inline-flex items-center justify-center px-4 py-2.5 bg-gradient-to-r from-blue-50 to-cyan-50 border border-blue-200 text-blue-700 font-semibold rounded-xl hover:bg-gradient-to-r hover:from-blue-100 hover:to-cyan-100 hover:border-blue-300 hover:text-blue-800 transition-all group">
                                            <i class="fas fa-eye mr-2 group-hover:scale-110 transition-transform"></i>
                                            Voir
                                        </button>

                                        <!-- Bouton Modifier -->
                                        <button onclick="ouvrirModalModifier(<?php echo $produit['id']; ?>)"
                                            class="flex-1 inline-flex items-center justify-center px-4 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-semibold rounded-xl hover:from-emerald-600 hover:to-teal-700 shadow-md hover:shadow-lg transition-all group">
                                            <i class="fas fa-edit mr-2 group-hover:scale-110 transition-transform"></i>
                                            Modifier
                                        </button>
                                    </div>
                                </div>

                                <!-- Indicateur de performance -->
                                <div class="px-5 py-3 bg-gray-50 border-t border-gray-100">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs text-gray-600">Performance</span>
                                        <div class="flex items-center">
                                            <?php if ($produit['ventes_total'] > 0): ?>
                                                <div class="w-24 h-1.5 bg-gray-200 rounded-full overflow-hidden mr-2">
                                                    <?php
                                                    $max_ventes = max(array_column($produits_fournis, 'ventes_total'));
                                                    $pourcentage = $max_ventes > 0 ? ($produit['ventes_total'] / $max_ventes) * 100 : 0;
                                                    ?>
                                                    <div class="h-full bg-emerald-500 rounded-full"
                                                        style="width: <?php echo $pourcentage; ?>%"></div>
                                                </div>
                                                <span class="text-xs font-semibold text-emerald-600">
                                                    Top <?php echo round(100 - $pourcentage); ?>%
                                                </span>
                                            <?php else: ?>
                                                <span class="text-xs text-gray-500">Aucune vente</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Résumé et actions -->
                    <div class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-200 p-6">
                        <div class="flex flex-col md:flex-row justify-between items-center">
                            <div class="mb-6 md:mb-0">
                                <h3 class="text-lg font-bold text-gray-900 mb-2">Résumé de votre catalogue</h3>
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="text-center">
                                        <p class="text-sm text-gray-600">Revenu moyen/produit</p>
                                        <p class="text-xl font-bold text-emerald-600">
                                            <?php echo formatMontant($revenu_total / max(1, count($produits_fournis))); ?>
                                        </p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-sm text-gray-600">Ventes moyennes/produit</p>
                                        <p class="text-xl font-bold text-purple-600">
                                            <?php echo round($total_ventes / max(1, count($produits_fournis)), 1); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-3">
                                <button onclick="exporterCatalogue()"
                                    class="inline-flex items-center px-5 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-xl hover:bg-gray-50 transition-colors">
                                    <i class="fas fa-file-export mr-2"></i>
                                    Exporter catalogue
                                </button>
                                <a href="?page=ajouter_produit"
                                    class="inline-flex items-center px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-semibold rounded-xl hover:from-emerald-600 hover:to-teal-700 shadow-lg hover:shadow-xl transition-all">
                                    <i class="fas fa-plus-circle mr-2"></i>
                                    Ajouter un autre produit
                                </a>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- État vide amélioré -->
                    <div
                        class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 p-16 text-center">
                        <div class="max-w-md mx-auto">
                            <div
                                class="w-28 h-28 mx-auto mb-8 bg-gradient-to-br from-blue-100 to-cyan-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-boxes text-blue-400 text-5xl"></i>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-900 mb-3">Votre catalogue est vide</h3>
                            <p class="text-gray-600 mb-8 leading-relaxed">
                                Vous n'avez pas encore ajouté de produits à votre catalogue fournisseur. Commencez par ajouter
                                votre premier produit pour le proposer aux clients NAGEX Pharma.
                            </p>
                            <div class="space-y-4">
                                <a href="?page=ajouter_produit"
                                    class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-semibold rounded-xl hover:from-emerald-600 hover:to-teal-700 shadow-lg hover:shadow-xl transition-all">
                                    <i class="fas fa-plus-circle mr-3"></i>
                                    Ajouter mon premier produit
                                </a>
                                <div class="pt-4 border-t border-gray-100">
                                    <p class="text-sm text-gray-500">
                                        <i class="fas fa-lightbulb text-amber-500 mr-2"></i>
                                        Les produits bien décrits avec photos se vendent 3x plus
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <script>
                // Fonction pour exporter le catalogue
                function exporterCatalogue() {
                    if (confirm('Voulez-vous exporter votre catalogue au format CSV ?')) {
                        // Simulation d'export
                        const exportBtn = event.target.closest('button');
                        const originalHTML = exportBtn.innerHTML;

                        exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Export en cours...';
                        exportBtn.disabled = true;

                        setTimeout(() => {
                            exportBtn.innerHTML = '<i class="fas fa-check mr-2"></i>Exporté !';
                            setTimeout(() => {
                                exportBtn.innerHTML = originalHTML;
                                exportBtn.disabled = false;
                            }, 2000);

                            // Téléchargement simulé
                            alert('Votre catalogue a été exporté. Le fichier sera téléchargé automatiquement.');
                        }, 1500);
                    }
                }


            </script>

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
                                            <p class="font-bold text-purple-600">
                                                <?php echo formatMontant(floatval($commande['montant_fournisseur'])); ?>
                                            </p>
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
                                                <?php echo formatMontant($commande['montant_total']); ?>
                                            </p>
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
                                <strong><?php echo number_format($stats['note_qualite'], 1); ?>/5</strong>
                            </li>
                            <li>• Produits fournis: <strong><?php echo $stats['total_produits']; ?></strong></li>
                            <li>• Ventes totales:
                                <strong><?php echo formatMontant($stats['ventes_totales']); ?></strong>
                            </li>
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

    <!-- Modal Détails Produit -->
    <div id="modalDetails" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-10 mx-auto p-5 border w-11/12 md:max-w-2xl shadow-lg rounded-2xl bg-white">
            <!-- En-tête -->
            <div class="flex justify-between items-center mb-6 pb-4 border-b">
                <div>
                    <h3 class="text-2xl font-bold text-gray-900">Détails du produit</h3>
                    <p class="text-gray-600 text-sm">Informations complètes du produit</p>
                </div>
                <button onclick="fermerModalDetails()" class="text-gray-400 hover:text-gray-600 text-2xl">
                    &times;
                </button>
            </div>

            <!-- Contenu -->
            <div class="space-y-6">
                <!-- Ligne 1: Nom et Statut -->
                <div class="flex justify-between items-start">
                    <div>
                        <h4 class="text-xl font-bold text-gray-900" id="detailNom"></h4>
                        <p class="text-sm text-gray-600 mt-1">
                            Catégorie: <span id="detailCategorie" class="font-semibold"></span>
                        </p>
                    </div>
                    <div id="detailStatutBadge"></div>
                </div>

                <!-- Code barre -->
                <div class="bg-gray-50 p-4 rounded-xl">
                    <p class="text-xs text-gray-500 mb-1">Code barre</p>
                    <p class="font-mono font-bold text-gray-900 text-lg" id="detailCodeBarre"></p>
                </div>

                <!-- Description et Composition -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h5 class="font-semibold text-gray-900 mb-2">Description</h5>
                        <p class="text-gray-700 bg-gray-50 p-4 rounded-lg" id="detailDescription"></p>
                    </div>
                    <div>
                        <h5 class="font-semibold text-gray-900 mb-2">Composition</h5>
                        <p class="text-gray-700 bg-gray-50 p-4 rounded-lg" id="detailComposition"></p>
                    </div>
                </div>

                <!-- Prix et Ventes -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-blue-50 p-4 rounded-xl">
                        <p class="text-xs text-gray-600 mb-1">Prix FC</p>
                        <p class="font-bold text-emerald-600 text-lg" id="detailPrix"></p>
                    </div>
                    <div class="bg-blue-50 p-4 rounded-xl">
                        <p class="text-xs text-gray-600 mb-1">Prix USD</p>
                        <p class="font-bold text-blue-600 text-lg" id="detailPrixUSD"></p>
                    </div>
                    <div class="bg-purple-50 p-4 rounded-xl">
                        <p class="text-xs text-gray-600 mb-1">Ventes totales</p>
                        <p class="font-bold text-purple-600 text-lg" id="detailVentes"></p>
                        <p class="text-xs text-gray-500">unités vendues</p>
                    </div>
                </div>

                <!-- Date de création -->
                <div class="pt-4 border-t border-gray-200">
                    <p class="text-sm text-gray-500">
                        <i class="fas fa-calendar mr-2"></i>
                        Ajouté le: <span id="detailDate"></span>
                    </p>
                </div>
            </div>

            <!-- Pied de modal -->
            <div class="mt-8 flex justify-end space-x-3">
                <button onclick="fermerModalDetails()"
                    class="px-5 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-xl hover:bg-gray-50 transition-colors">
                    Fermer
                </button>
                <button onclick="ouvrirModalModifier(produitActuel.id)"
                    class="px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-semibold rounded-xl hover:from-emerald-600 hover:to-teal-700 shadow-md">
                    <i class="fas fa-edit mr-2"></i>Modifier ce produit
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Modifier Produit -->
    <div id="modalModifier" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-10 mx-auto p-5 border w-11/12 md:max-w-2xl shadow-lg rounded-2xl bg-white">
            <!-- En-tête -->
            <div class="flex justify-between items-center mb-6 pb-4 border-b">
                <div>
                    <h3 class="text-2xl font-bold text-gray-900">Modifier le produit</h3>
                    <p class="text-gray-600 text-sm">Mettez à jour les informations</p>
                </div>
                <button onclick="fermerModalModifier()" class="text-gray-400 hover:text-gray-600 text-2xl">
                    &times;
                </button>
            </div>

            <!-- Formulaire -->
            <form id="formModifier" onsubmit="soumettreModification(event)">
                <input type="hidden" id="modifierProduitId" name="produit_id">

                <div class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2">
                                Nom du produit *
                            </label>
                            <input type="text" id="modifierNom" name="nom" required
                                class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2">
                                Catégorie
                            </label>
                            <select id="modifierCategorie" name="categorie_id"
                                class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500">
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
                        <label class="block text-gray-700 text-sm font-bold mb-2">
                            Code barre *
                        </label>
                        <input type="text" id="modifierCodeBarre" name="code_barre" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2">
                            Description
                        </label>
                        <textarea id="modifierDescription" name="description" rows="3"
                            class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>

                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2">
                            Composition
                        </label>
                        <textarea id="modifierComposition" name="composition" rows="3"
                            class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="Liste des principes actifs et excipients"></textarea>
                    </div>

                    <div class="p-4 bg-amber-50 border border-amber-200 rounded-xl">
                        <p class="text-sm text-amber-800">
                            <i class="fas fa-info-circle mr-2"></i>
                            Les modifications seront soumises à validation par le pharmacien.
                        </p>
                    </div>
                </div>

                <!-- Boutons -->
                <div class="mt-8 flex justify-end space-x-3">
                    <button type="button" onclick="fermerModalModifier()"
                        class="px-5 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-xl hover:bg-gray-50 transition-colors">
                        Annuler
                    </button>
                    <button type="submit"
                        class="px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-semibold rounded-xl hover:from-emerald-600 hover:to-teal-700 shadow-md">
                        <i class="fas fa-save mr-2"></i>Enregistrer les modifications
                    </button>
                </div>
            </form>
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

        // Variables globales pour stocker les données du produit
        let produitActuel = null;

        // Fonction pour ouvrir le modal de détails
        async function ouvrirModalDetails(produitId) {
            try {
                console.log('Chargement du produit ID:', produitId);

                // Récupérer les données du produit via AJAX
                const response = await fetch(`?action=get_produit&id=${produitId}`);

                if (!response.ok) {
                    throw new Error(`Erreur HTTP: ${response.status}`);
                }

                const produit = await response.json();
                console.log('Données reçues:', produit);

                if (produit.error) {
                    throw new Error(produit.error);
                }

                produitActuel = produit;

                // Remplir le modal de détails (ATTENTION aux noms de clés)
                document.getElementById('detailNom').textContent = produit.nom || 'Non spécifié';

                // La clé est 'categorie_nom' dans votre réponse JSON
                document.getElementById('detailCategorie').textContent = produit.categorie_nom || 'Non catégorisé';

                document.getElementById('detailCodeBarre').textContent = produit.code_barre || 'Non spécifié';
                document.getElementById('detailDescription').textContent = produit.description || 'Aucune description';
                document.getElementById('detailComposition').textContent = produit.composition || 'Non spécifiée';

                // Statut
                const statutText = produit.statut ? produit.statut.replace('_', ' ') : 'Inconnu';

                // Prix (attention aux noms de clés)
                const prixFc = produit.prix_fc || produit.prix_fc;
                const prixUsd = produit.prix_usd || produit.prix_usd;

                document.getElementById('detailPrix').textContent = prixFc ?
                    formatMontant(prixFc) : 'À définir';
                document.getElementById('detailPrixUSD').textContent = prixUsd ?
                    '$' + parseFloat(prixUsd).toFixed(2) + ' USD' : 'Non défini';

                // Ventes
                document.getElementById('detailVentes').textContent = produit.ventes_total || 0;

                // Date
                if (produit.created_at) {
                    document.getElementById('detailDate').textContent =
                        new Date(produit.created_at).toLocaleDateString('fr-FR', {
                            day: '2-digit',
                            month: '2-digit',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                } else {
                    document.getElementById('detailDate').textContent = 'Non disponible';
                }

                // Mettre à jour le badge de statut
                const statutBadge = document.getElementById('detailStatutBadge');
                let statutClass, statutIcon;

                if (produit.statut == 'actif') {
                    statutClass = 'bg-emerald-100 text-emerald-800';
                    statutIcon = 'fa-check-circle';
                } else if (produit.statut == 'en_attente') {
                    statutClass = 'bg-amber-100 text-amber-800';
                    statutIcon = 'fa-clock';
                } else {
                    statutClass = 'bg-rose-100 text-rose-800';
                    statutIcon = 'fa-times-circle';
                }

                statutBadge.className = `inline-flex items-center px-3 py-1 rounded-full text-xs font-bold ${statutClass}`;
                statutBadge.innerHTML = `<i class="fas ${statutIcon} mr-1.5"></i>${statutText}`;

                // Afficher le modal
                document.getElementById('modalDetails').classList.remove('hidden');

                console.log('Modal ouvert avec succès');

            } catch (error) {
                console.error('Erreur complète:', error);
                alert('Impossible de charger les détails du produit. Erreur: ' + error.message);
            }
        }
        // Fonction pour ouvrir le modal de modification
        async function ouvrirModalModifier(produitId) {
            try {
                console.log('Ouverture modification pour ID:', produitId);

                // Récupérer les données du produit via AJAX
                const response = await fetch(`?action=get_produit&id=${produitId}`);

                if (!response.ok) {
                    throw new Error(`Erreur HTTP: ${response.status}`);
                }

                const produit = await response.json();
                console.log('Données pour modification:', produit);

                if (produit.error) {
                    throw new Error(produit.error);
                }

                produitActuel = produit;

                // Remplir le formulaire de modification
                document.getElementById('modifierProduitId').value = produit.id;
                document.getElementById('modifierNom').value = produit.nom || '';
                document.getElementById('modifierDescription').value = produit.description || '';
                document.getElementById('modifierCodeBarre').value = produit.code_barre || '';
                document.getElementById('modifierCategorie').value = produit.categorie_id || '';
                document.getElementById('modifierComposition').value = produit.composition || '';

                console.log('Formulaire rempli');

                // Afficher le modal
                document.getElementById('modalModifier').classList.remove('hidden');

            } catch (error) {
                console.error('Erreur:', error);
                alert('Impossible de charger les données du produit. Erreur: ' + error.message);
            }
        }

        // Fonction pour fermer les modals
        function fermerModalDetails() {
            document.getElementById('modalDetails').classList.add('hidden');
        }

        function fermerModalModifier() {
            document.getElementById('modalModifier').classList.add('hidden');
        }

        // Fonction pour soumettre le formulaire de modification
        async function soumettreModification(event) {
            event.preventDefault();

            const formData = new FormData(document.getElementById('formModifier'));
            formData.append('action', 'modifier_produit');

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.text();
                if (result.includes('✅')) {
                    alert('Produit modifié avec succès!');
                    fermerModalModifier();
                    location.reload(); // Recharger la page pour voir les modifications
                } else {
                    alert('Erreur lors de la modification: ' + result);
                }
            } catch (error) {
                console.error('Erreur:', error);
                alert('Erreur réseau');
            }
        }

        // Fonction utilitaire pour formater les montants (copié de PHP)
        function formatMontant(montant, devise = 'CDF') {
            const montantFloat = parseFloat(montant) || 0;
            if (devise === 'USD') {
                return '$' + montantFloat.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
            }
            return montantFloat.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ",") + ' FC';
        }
    </script>


</body>

</html>