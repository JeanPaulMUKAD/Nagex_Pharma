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

// Inclure la classe Database
require_once __DIR__ . '/../../config/database.php';

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
// POINT D'ENTRÉE API POUR LES DONNÉES AJAX
// ============================================

// Vérifier si c'est une requête API
if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    // Vérifier si l'utilisateur est connecté
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'pharmacien') {
        echo json_encode(['success' => false, 'message' => 'Non autorisé']);
        exit;
    }

    // GESTION DES FOURNISSEURS
    if ($_GET['api'] === 'fournisseur_details' && isset($_GET['id'])) {
        try {
            $fournisseur_id = intval($_GET['id']);

            // Récupérer les informations détaillées du fournisseur
            $stmt = $pdo->prepare("
                SELECT 
                    f.*, 
                    COUNT(p.id) as nombre_produits,
                    GROUP_CONCAT(DISTINCT p.nom ORDER BY p.nom SEPARATOR '; ') as produits_liste
                FROM fournisseurs f
                LEFT JOIN produits p ON f.id = p.fournisseur_id AND p.statut != 'inactif'
                WHERE f.id = :id
                GROUP BY f.id
            ");
            $stmt->execute([':id' => $fournisseur_id]);
            $fournisseur = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($fournisseur) {
                // Formater les données pour l'affichage
                $data = [
                    'id' => $fournisseur['id'],
                    'nom_societe' => htmlspecialchars($fournisseur['nom_societe']),
                    'contact_principal' => htmlspecialchars($fournisseur['contact_principal']),
                    'email' => !empty($fournisseur['email']) ? htmlspecialchars($fournisseur['email']) : 'Non renseigné',
                    'telephone' => !empty($fournisseur['telephone']) ? htmlspecialchars($fournisseur['telephone']) : 'Non renseigné',
                    'adresse_siege' => !empty($fournisseur['adresse_siege']) ? htmlspecialchars($fournisseur['adresse_siege']) : 'Non renseigné',
                    'note_qualite' => number_format(floatval($fournisseur['note_qualite']), 1),
                    'nombre_produits' => intval($fournisseur['nombre_produits']),
                    'produits_liste' => !empty($fournisseur['produits_liste']) ? htmlspecialchars($fournisseur['produits_liste']) : 'Aucun produit',
                    'created_at' => !empty($fournisseur['created_at']) ? date('d/m/Y', strtotime($fournisseur['created_at'])) : 'Date inconnue'
                ];

                echo json_encode(['success' => true, 'data' => $data]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Fournisseur non trouvé']);
            }
        } catch (Exception $e) {
            error_log("Erreur API fournisseur: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()]);
        }
        exit;
    }

    // GESTION DES PRODUITS
    if ($_GET['api'] === 'produit_details' && isset($_GET['id'])) {
        try {
            $produit_id = intval($_GET['id']);

            // Récupérer les informations détaillées du produit
            $stmt = $pdo->prepare("
                SELECT 
                    p.*,
                    c.nom as categorie_nom,
                    f.nom_societe as fournisseur_nom,
                    f.contact_principal as fournisseur_contact
                FROM produits p
                LEFT JOIN categories c ON p.categorie_id = c.id
                LEFT JOIN fournisseurs f ON p.fournisseur_id = f.id
                WHERE p.id = :id
            ");
            $stmt->execute([':id' => $produit_id]);
            $produit = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($produit) {
                // Formater les données pour l'affichage
                $data = [
                    'id' => $produit['id'],
                    'nom' => htmlspecialchars($produit['nom']),
                    'description' => !empty($produit['description']) ? htmlspecialchars($produit['description']) : '',
                    'code_barre' => !empty($produit['code_barre']) ? htmlspecialchars($produit['code_barre']) : '',
                    'categorie_nom' => !empty($produit['categorie_nom']) ? htmlspecialchars($produit['categorie_nom']) : 'Non spécifiée',
                    'fournisseur_nom' => !empty($produit['fournisseur_nom']) ? htmlspecialchars($produit['fournisseur_nom']) : 'Non spécifié',
                    'necessite_ordonnance' => (bool) $produit['necessite_ordonnance'],
                    'composition' => !empty($produit['composition']) ? htmlspecialchars($produit['composition']) : '',
                    'posologie' => !empty($produit['posologie']) ? htmlspecialchars($produit['posologie']) : '',
                    'contre_indications' => !empty($produit['contre_indications']) ? htmlspecialchars($produit['contre_indications']) : '',
                    'statut' => $produit['statut'],
                    'created_at' => !empty($produit['created_at']) ? $produit['created_at'] : '',
                    'updated_at' => !empty($produit['updated_at']) ? $produit['updated_at'] : ''
                ];

                echo json_encode(['success' => true, 'data' => $data]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Produit non trouvé']);
            }
        } catch (Exception $e) {
            error_log("Erreur API produit: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()]);
        }
        exit;
    }

    // Si aucun endpoint API valide
    echo json_encode(['success' => false, 'message' => 'Endpoint API non valide']);
    exit;
}

// ============================================
// INITIALISATION DES VARIABLES
// ============================================

$message = '';
$error = '';
$current_page = $_GET['page'] ?? 'dashboard';

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

            // Après votre switch existant, ajoutez ces cas :

            case 'modifier_fournisseur':
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("
            UPDATE fournisseurs SET
                nom_societe = :nom_societe,
                contact_principal = :contact_principal,
                adresse_siege = :adresse_siege,
                note_qualite = :note_qualite
            WHERE id = :id
        ");

                    $stmt->execute([
                        ':nom_societe' => $_POST['nom_societe'] ?? '',
                        ':contact_principal' => $_POST['contact_principal'] ?? '',
                        ':adresse_siege' => $_POST['adresse_siege'] ?? '',
                        ':note_qualite' => floatval($_POST['note_qualite'] ?? 3.0),
                        ':id' => intval($_POST['fournisseur_id'] ?? 0)
                    ]);

                    $pdo->commit();
                    $message = "✅ Fournisseur modifié avec succès!";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "❌ Erreur lors de la modification: " . $e->getMessage();
                }
                break;

            case 'noter_fournisseur':
                try {
                    $stmt = $pdo->prepare("
            UPDATE fournisseurs 
            SET note_qualite = :note_qualite,
                updated_at = NOW()
            WHERE id = :id
        ");

                    $stmt->execute([
                        ':note_qualite' => floatval($_POST['note_qualite'] ?? 3.0),
                        ':id' => intval($_POST['fournisseur_id'] ?? 0)
                    ]);

                    // Optionnel : enregistrer le commentaire dans une table de logs
                    if (!empty($_POST['commentaire'])) {
                        $stmtLog = $pdo->prepare("
                INSERT INTO logs_evaluation (
                    fournisseur_id, 
                    note, 
                    commentaire, 
                    created_by, 
                    created_at
                ) VALUES (
                    :fournisseur_id, 
                    :note, 
                    :commentaire, 
                    :created_by, 
                    NOW()
                )
            ");

                        $stmtLog->execute([
                            ':fournisseur_id' => intval($_POST['fournisseur_id'] ?? 0),
                            ':note' => floatval($_POST['note_qualite'] ?? 3.0),
                            ':commentaire' => $_POST['commentaire'] ?? '',
                            ':created_by' => $_SESSION['user_id']
                        ]);
                    }

                    $message = "✅ Note enregistrée avec succès!";

                } catch (Exception $e) {
                    $error = "❌ Erreur lors de l'enregistrement de la note: " . $e->getMessage();
                }
                break;
            // Dans la section POST, après 'noter_fournisseur', ajoutez :
            case 'desarchiver_produit':
                try {
                    $stmt = $pdo->prepare("
            UPDATE produits 
            SET statut = 'actif' 
            WHERE id = :id AND statut = 'inactif'
        ");
                    $stmt->execute([':id' => intval($_POST['produit_id'] ?? 0)]);

                    if ($stmt->rowCount() > 0) {
                        $message = "✅ Produit désarchivé avec succès! Il est maintenant actif.";
                    } else {
                        $error = "❌ Le produit ne peut pas être désarchivé (déjà actif ou non trouvé)";
                    }
                } catch (Exception $e) {
                    $error = "❌ Erreur lors du désarchivage: " . $e->getMessage();
                }
                break;
            case 'desarchiver_tous_produits':
                try {
                    $stmt = $pdo->prepare("
                     UPDATE produits 
                     SET statut = 'actif' 
                     WHERE statut = 'inactif'
                 ");
                    $stmt->execute();
                    $message = "✅ Tous les produits ont été désarchivés avec succès!";
                } catch (Exception $e) {
                    $error = "❌ Erreur lors du désarchivage en masse: " . $e->getMessage();
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

    case 'produits_archives':
        try {
            $stmt = $pdo->prepare("
            SELECT p.*, c.nom as categorie_nom, f.nom_societe as fournisseur_nom
            FROM produits p
            LEFT JOIN categories c ON p.categorie_id = c.id
            LEFT JOIN fournisseurs f ON p.fournisseur_id = f.id
            WHERE p.statut = 'inactif'
            ORDER BY p.updated_at DESC
        ");
            $stmt->execute();
            $produits_archives = $stmt->fetchAll();
        } catch (Exception $e) {
            $error = "Erreur lors du chargement des produits archivés: " . $e->getMessage();
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

                <a href="?page=produits_archives"
                    class="menu-item block py-2.5 px-4 hover:bg-emerald-50 rounded-lg transition-all duration-200 <?php echo $current_page == 'produits_archives' ? 'active-menu shadow-md' : 'text-gray-700'; ?>">
                    <i class="fas fa-archive mr-3 w-5 text-center"></i>
                    <span>Produits archivés</span>
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
            <?php elseif ($current_page == 'produits_archives'): ?>
                <!-- ========== PRODUITS ARCHIVÉS ========== -->
                <div class="mb-8">
                    <!-- En-tête -->
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800 flex items-center">
                                <div class="p-2 bg-gradient-to-br from-gray-100 to-gray-200 rounded-lg mr-3 shadow-sm">
                                    <i class="fas fa-archive text-gray-600"></i>
                                </div>
                                Produits archivés
                            </h1>
                            <p class="text-gray-600 mt-1 ml-11">Liste de tous les produits désactivés du système</p>
                        </div>
                        <div class="flex items-center space-x-4">
                            <span
                                class="px-4 py-2 bg-gradient-to-r from-gray-200 to-gray-300 text-gray-800 rounded-xl font-medium">
                                <i class="fas fa-box mr-2"></i>
                                <?php echo count($produits_archives ?? []); ?> produit(s)
                            </span>
                            <a href="?page=produits"
                                class="group relative bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-5 py-3 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5">
                                <i class="fas fa-arrow-left mr-2 group-hover:-translate-x-1 transition-transform"></i>
                                Retour aux produits
                            </a>
                        </div>
                    </div>

                    <!-- Modal Désarchiver Produit -->
                    <div id="modalDesarchiverProduit"
                        class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                        <div
                            class="bg-gradient-to-br from-white to-emerald-50 rounded-2xl shadow-2xl w-full max-w-md transform transition-all duration-300 scale-95 opacity-0">
                            <div class="p-6 border-b border-emerald-200">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl mr-3">
                                            <i class="fas fa-box-open text-emerald-600"></i>
                                        </div>
                                        <h3 class="text-xl font-bold text-gray-800">Désarchiver le produit</h3>
                                    </div>
                                    <button onclick="fermerModal('modalDesarchiverProduit')"
                                        class="p-2 hover:bg-emerald-100 rounded-full transition-colors">
                                        <i class="fas fa-times text-gray-500 hover:text-gray-700"></i>
                                    </button>
                                </div>
                                <p class="text-gray-600 mt-2">Confirmez-vous le désarchivage de ce produit ?</p>
                            </div>
                            <div class="p-6">
                                <div class="space-y-4">
                                    <div
                                        class="flex items-center p-4 bg-gradient-to-r from-emerald-50 to-emerald-100 rounded-lg border border-emerald-200">
                                        <div class="p-3 bg-white rounded-lg shadow-sm mr-4">
                                            <i class="fas fa-capsules text-emerald-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-semibold text-gray-800" id="desarchiverProduitNom"></h4>
                                            <p class="text-sm text-gray-600" id="desarchiverProduitDetails"></p>
                                        </div>
                                    </div>
                                    <div
                                        class="p-4 bg-gradient-to-r from-green-50 to-green-100 rounded-lg border border-green-200">
                                        <div class="flex items-center">
                                            <i class="fas fa-info-circle text-green-600 mr-3"></i>
                                            <p class="text-sm text-green-800">
                                                Le produit sera marqué comme <span class="font-semibold">"actif"</span> et
                                                réapparaîtra dans la liste des produits.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex justify-end space-x-3 mt-8 pt-6 border-t border-gray-200">
                                    <button onclick="fermerModal('modalDesarchiverProduit')"
                                        class="px-5 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors font-medium">
                                        Annuler
                                    </button>
                                    <button onclick="confirmerDesarchivage()"
                                        class="px-5 py-2.5 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white rounded-lg shadow hover:shadow-md transition-all duration-200 font-medium">
                                        <i class="fas fa-box-open mr-2"></i>Désarchiver
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (count($produits_archives ?? []) > 0): ?>
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-xl shadow-lg overflow-hidden border border-gray-200">
                            <!-- Header avec filtres -->
                            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-gray-50 to-gray-100">
                                <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                                    <div class="flex items-center">
                                        <i class="fas fa-filter text-gray-400 mr-2"></i>
                                        <span class="text-sm text-gray-600"><?php echo count($produits_archives); ?> produit(s)
                                            archivé(s)</span>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <button onclick="desarchiverTousProduits()"
                                            class="group bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 text-sm">
                                            <i class="fas fa-boxes mr-2 group-hover:scale-110 transition-transform"></i>
                                            Tout désarchiver
                                        </button>
                                        <div class="relative">
                                            <input type="text" placeholder="Rechercher un produit..."
                                                class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-transparent text-sm">
                                            <i
                                                class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
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
                                                    <i class="fas fa-calendar mr-2 text-gray-400"></i> Archivé le
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
                                        <?php foreach ($produits_archives as $produit): ?>
                                            <?php
                                            // Calculer l'âge de l'archivage
                                            $dateArchivage = new DateTime($produit['updated_at']);
                                            $maintenant = new DateTime();
                                            $difference = $maintenant->diff($dateArchivage);
                                            $joursArchive = $difference->days;
                                            ?>
                                            <tr
                                                class="hover:bg-gradient-to-r hover:from-gray-50 hover:to-transparent transition-all duration-200 group">
                                                <td class="px-6 py-4">
                                                    <div
                                                        class="text-sm font-medium text-gray-900 bg-gray-100 px-3 py-1 rounded-lg inline-block border border-gray-300">
                                                        #<?php echo str_pad((string) $produit['id'], 4, '0', STR_PAD_LEFT); ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center">
                                                        <div
                                                            class="p-2 bg-gradient-to-br from-gray-100 to-gray-200 rounded-lg mr-3 border border-gray-300">
                                                            <i class="fas fa-archive text-gray-600 text-sm"></i>
                                                        </div>
                                                        <div>
                                                            <div
                                                                class="font-semibold text-gray-900 group-hover:text-gray-700 transition-colors">
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
                                                            class="px-3 py-1 rounded-full text-xs font-medium bg-gradient-to-r from-gray-100 to-gray-200 text-gray-800 border border-gray-300">
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
                                                    <div class="flex flex-col">
                                                        <div class="text-sm text-gray-900">
                                                            <i class="far fa-calendar text-gray-400 mr-1"></i>
                                                            <?php echo formatDate($produit['updated_at']); ?>
                                                        </div>
                                                        <div
                                                            class="text-xs <?php echo $joursArchive > 30 ? 'text-red-600' : 'text-gray-500'; ?> mt-1">
                                                            <i class="fas fa-clock mr-1 text-xs"></i>
                                                            Il y a <?php echo $joursArchive; ?> jour(s)
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <div class="flex items-center space-x-2">
                                                        <button
                                                            onclick="ouvrirModalDesarchiver(<?php echo $produit['id']; ?>, '<?php echo addslashes(htmlspecialchars($produit['nom'])); ?>', '<?php echo addslashes(htmlspecialchars($produit['categorie_nom'])); ?>')"
                                                            class="group/restore relative bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white px-4 py-2 rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 text-sm">
                                                            <i
                                                                class="fas fa-box-open mr-2 group-hover/restore:scale-110 transition-transform"></i>
                                                            Désarchiver
                                                        </button>

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
                                    <?php
                                    // Statistiques sur les archives
                                    $anciensArchives = array_filter($produits_archives, function ($p) {
                                        $jours = (new DateTime())->diff(new DateTime($p['updated_at']))->days;
                                        return $jours > 30;
                                    });

                                    $recentsArchives = array_filter($produits_archives, function ($p) {
                                        $jours = (new DateTime())->diff(new DateTime($p['updated_at']))->days;
                                        return $jours <= 30;
                                    });
                                    ?>
                                    <div class="text-center">
                                        <div class="text-lg font-bold text-gray-800"><?php echo count($recentsArchives); ?>
                                        </div>
                                        <div class="text-sm text-gray-600">Archivés ≤ 30 jours</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-lg font-bold text-red-700"><?php echo count($anciensArchives); ?></div>
                                        <div class="text-sm text-gray-600">Archivés > 30 jours</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-lg font-bold text-blue-700">
                                            <?php
                                            $ordonnanceArchives = count(array_filter($produits_archives, function ($p) {
                                                return $p['necessite_ordonnance'];
                                            }));
                                            echo $ordonnanceArchives;
                                            ?>
                                        </div>
                                        <div class="text-sm text-gray-600">Sous ordonnance</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Message si pas d'archives -->
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg p-12 text-center border border-gray-200">
                            <div
                                class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-r from-gray-100 to-gray-200 mb-6 shadow-sm">
                                <i class="fas fa-inbox text-gray-600 text-3xl"></i>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-800 mb-3">Aucun produit archivé</h3>
                            <p class="text-gray-600 mb-8 max-w-md mx-auto">
                                Aucun produit n'a été archivé pour le moment. Les produits archivés apparaîtront ici.
                            </p>
                            <div class="flex flex-col sm:flex-row justify-center gap-4">
                                <a href="?page=produits"
                                    class="group bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-6 py-3 rounded-xl font-medium shadow-lg hover:shadow-xl transition-all duration-300 transform hover:-translate-y-0.5">
                                    <i class="fas fa-list mr-2 group-hover:scale-110 transition-transform"></i>
                                    Voir les produits actifs
                                </a>
                                <a href="?page=dashboard"
                                    class="group bg-gradient-to-r from-gray-100 to-gray-200 hover:from-gray-200 hover:to-gray-300 text-gray-700 px-6 py-3 rounded-xl font-medium shadow hover:shadow-md transition-all duration-200 border border-gray-300">
                                    <i class="fas fa-home mr-2 group-hover:scale-110 transition-transform"></i>
                                    Tableau de bord
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <script>
                    // Variables pour le désarchivage
                    let produitADesarchiverId = null;
                    let produitADesarchiverNom = '';
                    let produitADesarchiverCategorie = '';

                    // Fonction pour ouvrir le modal de désarchivage
                    function ouvrirModalDesarchiver(id, nom, categorie) {
                        produitADesarchiverId = id;
                        produitADesarchiverNom = nom;
                        produitADesarchiverCategorie = categorie;

                        document.getElementById('desarchiverProduitNom').textContent = nom;
                        document.getElementById('desarchiverProduitDetails').textContent = `Catégorie: ${categorie} | ID: ${id.toString().padStart(4, '0')}`;

                        ouvrirModal('modalDesarchiverProduit');
                    }

                    // Fonction pour confirmer le désarchivage
                    function confirmerDesarchivage() {
                        if (produitADesarchiverId) {
                            let form = document.createElement('form');
                            form.method = 'POST';
                            form.action = '';
                            form.style.display = 'none';

                            form.innerHTML = `
            <input type="hidden" name="action" value="desarchiver_produit">
            <input type="hidden" name="produit_id" value="${produitADesarchiverId}">
        `;

                            document.body.appendChild(form);
                            form.submit();
                        }
                    }

                    // Fonction pour désarchiver tous les produits
                    function desarchiverTousProduits() {
                        if (confirm('Êtes-vous sûr de vouloir désarchiver TOUS les produits ?\n\nCette action est irréversible.')) {
                            let form = document.createElement('form');
                            form.method = 'POST';
                            form.action = '';
                            form.style.display = 'none';

                            form.innerHTML = `
                    <input type="hidden" name="action" value="desarchiver_tous_produits">
                `;

                            document.body.appendChild(form);
                            form.submit();
                        }
                    }


                </script>

                <style>
                    /* Styles spécifiques pour la page archives */
                    .archived-row {
                        background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
                    }

                    .archived-row:hover {
                        background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
                    }

                    .archive-badge {
                        background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
                        color: white;
                    }

                    .old-archive {
                        border-left: 4px solid #ef4444;
                    }

                    .recent-archive {
                        border-left: 4px solid #10b981;
                    }
                </style>

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

                    <!-- Pop-up Modals -->
                    <!-- Modal Valider Produit -->
                    <div id="modalValiderProduit"
                        class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                        <div
                            class="bg-gradient-to-br from-white to-emerald-50 rounded-2xl shadow-2xl w-full max-w-md transform transition-all duration-300 scale-95 opacity-0">
                            <div class="p-6 border-b border-emerald-200">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl mr-3">
                                            <i class="fas fa-check-circle text-emerald-600"></i>
                                        </div>
                                        <h3 class="text-xl font-bold text-gray-800">Valider le produit</h3>
                                    </div>
                                    <button onclick="fermerModal('modalValiderProduit')"
                                        class="p-2 hover:bg-emerald-100 rounded-full transition-colors">
                                        <i class="fas fa-times text-gray-500 hover:text-gray-700"></i>
                                    </button>
                                </div>
                                <p class="text-gray-600 mt-2">Confirmez-vous la validation de ce produit ?</p>
                            </div>
                            <div class="p-6">
                                <div class="space-y-4">
                                    <div
                                        class="flex items-center p-4 bg-gradient-to-r from-emerald-50 to-emerald-100 rounded-lg border border-emerald-200">
                                        <div class="p-3 bg-white rounded-lg shadow-sm mr-4">
                                            <i class="fas fa-capsules text-emerald-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-semibold text-gray-800" id="validerProduitNom"></h4>
                                            <p class="text-sm text-gray-600" id="validerProduitDetails"></p>
                                        </div>
                                    </div>
                                    <div
                                        class="p-4 bg-gradient-to-r from-yellow-50 to-yellow-100 rounded-lg border border-yellow-200">
                                        <div class="flex items-center">
                                            <i class="fas fa-info-circle text-yellow-600 mr-3"></i>
                                            <p class="text-sm text-yellow-800">
                                                Le produit sera marqué comme <span class="font-semibold">"actif"</span> et
                                                disponible pour la vente.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex justify-end space-x-3 mt-8 pt-6 border-t border-gray-200">
                                    <button onclick="fermerModal('modalValiderProduit')"
                                        class="px-5 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors font-medium">
                                        Annuler
                                    </button>
                                    <button onclick="confirmerValidation()"
                                        class="px-5 py-2.5 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white rounded-lg shadow hover:shadow-md transition-all duration-200 font-medium">
                                        <i class="fas fa-check mr-2"></i>Confirmer la validation
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal Archiver Produit -->
                    <div id="modalArchiverProduit"
                        class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                        <div
                            class="bg-gradient-to-br from-white to-red-50 rounded-2xl shadow-2xl w-full max-w-md transform transition-all duration-300 scale-95 opacity-0">
                            <div class="p-6 border-b border-red-200">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <div class="p-3 bg-gradient-to-br from-red-100 to-red-200 rounded-xl mr-3">
                                            <i class="fas fa-archive text-red-600"></i>
                                        </div>
                                        <h3 class="text-xl font-bold text-gray-800">Archiver le produit</h3>
                                    </div>
                                    <button onclick="fermerModal('modalArchiverProduit')"
                                        class="p-2 hover:bg-red-100 rounded-full transition-colors">
                                        <i class="fas fa-times text-gray-500 hover:text-gray-700"></i>
                                    </button>
                                </div>
                                <p class="text-gray-600 mt-2">Cette action est irréversible</p>
                            </div>
                            <div class="p-6">
                                <div class="space-y-4">
                                    <div
                                        class="flex items-center p-4 bg-gradient-to-r from-red-50 to-red-100 rounded-lg border border-red-200">
                                        <div class="p-3 bg-white rounded-lg shadow-sm mr-4">
                                            <i class="fas fa-exclamation-triangle text-red-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-semibold text-gray-800" id="archiverProduitNom"></h4>
                                            <p class="text-sm text-gray-600" id="archiverProduitDetails"></p>
                                        </div>
                                    </div>
                                    <div
                                        class="p-4 bg-gradient-to-r from-red-50 to-red-100 rounded-lg border border-red-200">
                                        <div class="flex items-start">
                                            <i class="fas fa-exclamation-circle text-red-600 mr-3 mt-0.5"></i>
                                            <div>
                                                <p class="text-sm text-red-800 font-semibold mb-1">Attention !</p>
                                                <ul class="text-sm text-red-700 space-y-1">
                                                    <li class="flex items-center"><i class="fas fa-times text-xs mr-2"></i>
                                                        Le produit ne sera plus disponible pour la vente</li>
                                                    <li class="flex items-center"><i class="fas fa-times text-xs mr-2"></i>
                                                        Il disparaîtra des listes de recherche</li>
                                                    <li class="flex items-center"><i class="fas fa-times text-xs mr-2"></i>
                                                        Les statistiques ne seront plus mises à jour</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    <div
                                        class="p-4 bg-gradient-to-r from-yellow-50 to-yellow-100 rounded-lg border border-yellow-200">
                                        <label class="flex items-start space-x-3 cursor-pointer">
                                            <input type="checkbox" id="confirmationArchivage" class="mt-1">
                                            <span class="text-sm text-gray-700">Je confirme vouloir archiver ce produit de
                                                manière permanente</span>
                                        </label>
                                    </div>
                                </div>
                                <div class="flex justify-end space-x-3 mt-8 pt-6 border-t border-gray-200">
                                    <button onclick="fermerModal('modalArchiverProduit')"
                                        class="px-5 py-2.5 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors font-medium">
                                        Annuler
                                    </button>
                                    <button onclick="confirmerArchivage()" id="btnConfirmerArchivage" disabled
                                        class="px-5 py-2.5 bg-gradient-to-r from-gray-300 to-gray-400 text-white rounded-lg font-medium cursor-not-allowed">
                                        <i class="fas fa-archive mr-2"></i>Archiver
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal Voir Détails Produit -->
                    <div id="modalDetailsProduit"
                        class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                        <div
                            class="bg-gradient-to-br from-white to-blue-50 rounded-2xl shadow-2xl w-full max-w-2xl transform transition-all duration-300 scale-95 opacity-0">
                            <div class="p-6 border-b border-blue-200">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <div class="p-3 bg-gradient-to-br from-blue-100 to-blue-200 rounded-xl mr-3">
                                            <i class="fas fa-eye text-blue-600"></i>
                                        </div>
                                        <h3 class="text-xl font-bold text-gray-800">Détails du produit</h3>
                                    </div>
                                    <button onclick="fermerModal('modalDetailsProduit')"
                                        class="p-2 hover:bg-blue-100 rounded-full transition-colors">
                                        <i class="fas fa-times text-gray-500 hover:text-gray-700"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="p-6" id="detailsProduitContent">
                                <!-- Les détails seront chargés ici dynamiquement -->
                                <div class="text-center py-12">
                                    <div
                                        class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-r from-blue-100 to-blue-200 mb-6">
                                        <i class="fas fa-spinner fa-spin text-blue-600 text-2xl"></i>
                                    </div>
                                    <p class="text-gray-600">Chargement des détails...</p>
                                </div>
                            </div>
                        </div>
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
                                                    #<?php echo str_pad((string) $produit['id'], 4, '0', STR_PAD_LEFT); ?>
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

                                                    <button
                                                        onclick="ouvrirModalArchiver(<?php echo $produit['id']; ?>, '<?php echo addslashes(htmlspecialchars($produit['nom'])); ?>', '<?php echo addslashes(htmlspecialchars($produit['categorie_nom'])); ?>')"
                                                        class="p-2 bg-gradient-to-br from-red-50 to-red-100 hover:from-red-100 hover:to-red-200 text-red-600 rounded-lg border border-red-200 hover:shadow-sm transition-all duration-200 group/archive"
                                                        title="Archiver">
                                                        <i
                                                            class="fas fa-archive text-sm group-hover/archive:scale-110 transition-transform"></i>
                                                    </button>

                                                    <?php if ($produit['statut'] == 'en_attente'): ?>
                                                        <button
                                                            onclick="ouvrirModalValider(<?php echo $produit['id']; ?>, '<?php echo addslashes(htmlspecialchars($produit['nom'])); ?>', '<?php echo addslashes(htmlspecialchars($produit['categorie_nom'])); ?>')"
                                                            class="p-2 bg-gradient-to-br from-green-50 to-green-100 hover:from-green-100 hover:to-green-200 text-green-600 rounded-lg border border-green-200 hover:shadow-sm transition-all duration-200 group/validate"
                                                            title="Valider">
                                                            <i
                                                                class="fas fa-check text-sm group-hover/validate:scale-110 transition-transform"></i>
                                                        </button>
                                                    <?php endif; ?>

                                                    <button onclick="voirDetailsProduit(<?php echo $produit['id']; ?>)"
                                                        class="p-2 bg-gradient-to-br from-gray-50 to-gray-100 hover:from-gray-100 hover:to-gray-200 text-gray-600 rounded-lg border border-gray-200 hover:shadow-sm transition-all duration-200 group/view"
                                                        title="Voir détails">
                                                        <i
                                                            class="fas fa-eye text-sm group-hover/view:scale-110 transition-transform"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Produits archives -->


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

                <script>
                    // Variables globales pour les produits
                    let currentProduitId = null;
                    let currentProduitNom = '';
                    let currentProduitCategorie = '';

                    // Fonctions pour ouvrir les modals
                    function ouvrirModalValider(id, nom, categorie) {
                        currentProduitId = id;
                        currentProduitNom = nom;
                        currentProduitCategorie = categorie;

                        document.getElementById('validerProduitNom').textContent = nom;
                        document.getElementById('validerProduitDetails').textContent = `Catégorie: ${categorie} | ID: ${id.toString().padStart(4, '0')}`;

                        ouvrirModal('modalValiderProduit');
                    }

                    function ouvrirModalArchiver(id, nom, categorie) {
                        currentProduitId = id;
                        currentProduitNom = nom;
                        currentProduitCategorie = categorie;

                        document.getElementById('archiverProduitNom').textContent = nom;
                        document.getElementById('archiverProduitDetails').textContent = `Catégorie: ${categorie} | ID: ${id.toString().padStart(4, '0')}`;

                        // Réinitialiser la confirmation
                        document.getElementById('confirmationArchivage').checked = false;
                        document.getElementById('btnConfirmerArchivage').disabled = true;
                        document.getElementById('btnConfirmerArchivage').className = 'px-5 py-2.5 bg-gradient-to-r from-gray-300 to-gray-400 text-white rounded-lg font-medium cursor-not-allowed';

                        ouvrirModal('modalArchiverProduit');
                    }

                    async function voirDetailsProduit(id) {
                        currentProduitId = id;
                        ouvrirModal('modalDetailsProduit');
                        await chargerDetailsProduit(id);
                    }

                    // Fonctions de gestion des modals
                    function ouvrirModal(modalId) {
                        const modal = document.getElementById(modalId);
                        const modalContent = modal.querySelector('div > div');

                        modal.classList.remove('hidden');
                        setTimeout(() => {
                            modalContent.style.opacity = '1';
                            modalContent.style.transform = 'scale(1)';
                        }, 10);

                        // Fermer la modal avec ESC
                        document.addEventListener('keydown', function fermerEsc(e) {
                            if (e.key === 'Escape') {
                                fermerModal(modalId);
                                document.removeEventListener('keydown', fermerEsc);
                            }
                        });
                    }

                    function fermerModal(modalId) {
                        const modal = document.getElementById(modalId);
                        const modalContent = modal.querySelector('div > div');

                        modalContent.style.opacity = '0';
                        modalContent.style.transform = 'scale(0.95)';

                        setTimeout(() => {
                            modal.classList.add('hidden');
                            currentProduitId = null;
                            currentProduitNom = '';
                            currentProduitCategorie = '';
                        }, 300);
                    }

                    // Fonctions de confirmation
                    function confirmerValidation() {
                        if (currentProduitId) {
                            let form = document.createElement('form');
                            form.method = 'POST';
                            form.action = '';
                            form.style.display = 'none';

                            form.innerHTML = `
            <input type="hidden" name="action" value="valider_produit">
            <input type="hidden" name="produit_id" value="${currentProduitId}">
        `;

                            document.body.appendChild(form);
                            form.submit();
                        }
                    }

                    function confirmerArchivage() {
                        if (currentProduitId) {
                            let form = document.createElement('form');
                            form.method = 'POST';
                            form.action = '';
                            form.style.display = 'none';

                            form.innerHTML = `
            <input type="hidden" name="action" value="archiver_produit">
            <input type="hidden" name="produit_id" value="${currentProduitId}">
        `;

                            document.body.appendChild(form);
                            form.submit();
                        }
                    }

                    // Fonction pour charger les détails du produit
                    async function chargerDetailsProduit(id) {
                        const contentElement = document.getElementById('detailsProduitContent');

                        // Afficher un indicateur de chargement
                        contentElement.innerHTML = `
        <div class="text-center py-12">
            <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-r from-blue-100 to-blue-200 mb-6">
                <i class="fas fa-spinner fa-spin text-blue-600 text-2xl"></i>
            </div>
            <h4 class="text-lg font-bold text-gray-800 mb-2">Chargement en cours</h4>
            <p class="text-gray-600">Récupération des informations du produit...</p>
        </div>
    `;

                        // Simuler une requête AJAX (à adapter avec votre backend)
                        setTimeout(() => {
                            // Pour l'exemple, on utilise les données PHP disponibles
                            // Dans la vraie vie, vous feriez une requête AJAX vers un endpoint API
                            contentElement.innerHTML = genererDetailsProduit(id);
                        }, 500);
                    }

                    // Générer les détails du produit (exemple)
                    function genererDetailsProduit(id) {
                        // Ici, vous devriez faire une vraie requête AJAX
                        // Pour l'exemple, on retourne un template HTML

                        return `
        <div class="space-y-6">
            <!-- En-tête -->
            <div class="flex items-start">
                <div class="p-4 bg-gradient-to-br from-blue-100 to-blue-200 rounded-xl mr-4 shadow-sm">
                    <i class="fas fa-capsules text-blue-600 text-2xl"></i>
                </div>
                <div class="flex-1">
                    <h4 class="text-xl font-bold text-gray-800 mb-1">Produit #${id.toString().padStart(4, '0')}</h4>
                    <div class="flex items-center text-blue-600 font-medium mb-2">
                        <i class="fas fa-tag mr-2 text-sm"></i>
                        Nom du produit chargé...
                    </div>
                </div>
            </div>
            
            <!-- Informations principales -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-gradient-to-br from-gray-50 to-gray-100 p-4 rounded-xl border border-gray-200">
                    <div class="text-sm text-gray-600 mb-1">Catégorie</div>
                    <div class="font-semibold text-gray-800">Chargement...</div>
                </div>
                <div class="bg-gradient-to-br from-gray-50 to-gray-100 p-4 rounded-xl border border-gray-200">
                    <div class="text-sm text-gray-600 mb-1">Statut</div>
                    <div class="font-semibold text-emerald-600">Chargement...</div>
                </div>
            </div>
            
            <!-- Code barre -->
            <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-xl border border-blue-200">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-sm text-blue-600 mb-1">Code barre</div>
                        <div class="font-mono font-bold text-gray-800">Chargement...</div>
                    </div>
                    <i class="fas fa-barcode text-blue-400 text-2xl"></i>
                </div>
            </div>
            
            <!-- Description -->
            <div class="bg-gradient-to-br from-gray-50 to-gray-100 p-4 rounded-xl border border-gray-200">
                <div class="text-sm text-gray-600 mb-2">Description</div>
                <div class="text-gray-700">Les détails du produit seront affichés ici...</div>
            </div>
            
            <!-- Actions -->
            <div class="flex justify-center space-x-4 pt-6 border-t border-gray-200">
                <button onclick="ouvrirModalValider(${id}, 'Produit #${id}', 'Catégorie')"
                    class="group relative px-5 py-2.5 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 overflow-hidden flex items-center">
                    <div class="absolute inset-0 bg-white opacity-0 group-hover:opacity-10 transition-opacity duration-200"></div>
                    <i class="fas fa-check mr-2"></i>
                    Valider
                </button>
                <button onclick="ouvrirModalArchiver(${id}, 'Produit #${id}', 'Catégorie')"
                    class="group relative px-5 py-2.5 bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 overflow-hidden flex items-center">
                    <div class="absolute inset-0 bg-white opacity-0 group-hover:opacity-10 transition-opacity duration-200"></div>
                    <i class="fas fa-archive mr-2"></i>
                    Archiver
                </button>
            </div>
        </div>
    `;
                    }

                    // Initialisation
                    document.addEventListener('DOMContentLoaded', function () {
                        // Gestion de la confirmation d'archivage
                        const checkbox = document.getElementById('confirmationArchivage');
                        const btnArchiver = document.getElementById('btnConfirmerArchivage');

                        if (checkbox && btnArchiver) {
                            checkbox.addEventListener('change', function () {
                                if (this.checked) {
                                    btnArchiver.disabled = false;
                                    btnArchiver.className = 'px-5 py-2.5 bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white rounded-lg shadow hover:shadow-md transition-all duration-200 font-medium';
                                } else {
                                    btnArchiver.disabled = true;
                                    btnArchiver.className = 'px-5 py-2.5 bg-gradient-to-r from-gray-300 to-gray-400 text-white rounded-lg font-medium cursor-not-allowed';
                                }
                            });
                        }

                        // Fermer les modals en cliquant en dehors
                        document.querySelectorAll('[id^="modal"]').forEach(modal => {
                            modal.addEventListener('click', function (e) {
                                if (e.target === this) {
                                    fermerModal(this.id);
                                }
                            });
                        });
                    });
                </script>

                <style>
                    /* Animation pour les modals */
                    @keyframes modalFadeIn {
                        from {
                            opacity: 0;
                            transform: scale(0.95) translateY(-10px);
                        }

                        to {
                            opacity: 1;
                            transform: scale(1) translateY(0);
                        }
                    }

                    .modal-enter {
                        animation: modalFadeIn 0.3s ease-out forwards;
                    }

                    /* Amélioration des boutons d'action */
                    .actions-container {
                        display: flex;
                        gap: 0.5rem;
                        flex-wrap: wrap;
                    }

                    .action-btn {
                        transition: all 0.2s ease;
                        position: relative;
                        overflow: hidden;
                    }

                    .action-btn::after {
                        content: '';
                        position: absolute;
                        top: 50%;
                        left: 50%;
                        width: 0;
                        height: 0;
                        background: rgba(255, 255, 255, 0.2);
                        border-radius: 50%;
                        transform: translate(-50%, -50%);
                        transition: width 0.3s, height 0.3s;
                    }

                    .action-btn:hover::after {
                        width: 200px;
                        height: 200px;
                    }

                    /* Animation pour les icônes */
                    .action-btn i {
                        transition: transform 0.2s ease;
                    }

                    .action-btn:hover i {
                        transform: scale(1.2);
                    }

                    /* Styles pour les états de produits */
                    .status-badge {
                        font-size: 0.75rem;
                        padding: 0.25rem 0.75rem;
                        border-radius: 9999px;
                        font-weight: 600;
                        display: inline-flex;
                        align-items: center;
                        gap: 0.25rem;
                    }

                    .status-active {
                        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                        color: white;
                    }

                    .status-pending {
                        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                        color: white;
                    }

                    .status-inactive {
                        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
                        color: white;
                    }
                </style>

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
                                                <select id="nom" name="nom" required
                                                    class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow appearance-none bg-white">
                                                    <option value="" disabled selected>Sélectionnez un produit</option>
                                                    <option value="A- DEM POUDRE 100GM">A- DEM POUDRE 100GM</option>
                                                    <option value="A-DEM CREAM 30GM">A-DEM CREAM 30GM</option>
                                                    <option value="A-DEM CREME 15 GM">A-DEM CREME 15 GM</option>
                                                    <option value="A-DEM FEMINA">A-DEM FEMINA</option>
                                                    <option value="A-DEM SAVON 125G">A-DEM SAVON 125G</option>
                                                    <option value="A-DEM SAVON 75 GM">A-DEM SAVON 75 GM</option>
                                                    <option value="ABBOCATH G20">ABBOCATH G20</option>
                                                    <option value="ABBOCATH G22">ABBOCATH G22</option>
                                                    <option value="ABBOCATH G24">ABBOCATH G24</option>
                                                    <option value="ACIDE FOLICE CES">ACIDE FOLICE CES</option>
                                                    <option value="ACTION CES MEDICO">ACTION CES MEDICO</option>
                                                    <option value="ADRENALINE INJ">ADRENALINE INJ</option>
                                                    <option value="ALBENDAZOL SP 10 ML">ALBENDAZOL SP 10 ML</option>
                                                    <option value="ALBENDAZOLE 400MG CES">ALBENDAZOLE 400MG CES</option>
                                                    <option value="ALCEF 1G INJ">ALCEF 1G INJ</option>
                                                    <option value="ALCOOL ANTISEPTIQUE SPRAY 100 ML">ALCOOL ANTISEPTIQUE
                                                        SPRAY 100 ML</option>
                                                    <option value="ALCOOL DENATURE 5 L">ALCOOL DENATURE 5 L</option>
                                                    <option value="ALCOOL DENATURE 60 ML">ALCOOL DENATURE 60 ML</option>
                                                    <option value="ALCOOL IODE 60 ML">ALCOOL IODE 60 ML</option>
                                                    <option value="ALDACTON 100MG CES 50 CES">ALDACTON 100MG CES 50 CES
                                                    </option>
                                                    <option value="ALDACTON 50MG CES (EUROP)">ALDACTON 50MG CES (EUROP)
                                                    </option>
                                                    <option value="ALDACTONE 25 MG 50 CES">ALDACTONE 25 MG 50 CES</option>
                                                    <option value="ALDOMET 250 MG EUR">ALDOMET 250 MG EUR</option>
                                                    <option value="ALDOMET 250MG">ALDOMET 250MG</option>
                                                    <option value="ALEZE CES 1 CES">ALEZE CES 1 CES</option>
                                                    <option value="ALEZE M3">ALEZE M3</option>
                                                    <option value="ALEZZ CES 28 CES">ALEZZ CES 28 CES</option>
                                                    <option value="ALGESAL BAUME SIMPLE 40GM (EUROP)">ALGESAL BAUME SIMPLE
                                                        40GM (EUROP)</option>
                                                    <option value="ALGESAL SURACTIVE PDE 40 GR (EUROP)">ALGESAL SURACTIVE
                                                        PDE 40 GR (EUROP)</option>
                                                    <option value="ALIVIO TABLETS B/10">ALIVIO TABLETS B/10</option>
                                                    <option value="ALLERGINE CES">ALLERGINE CES</option>
                                                    <option value="ALUGEL SP">ALUGEL SP</option>
                                                    <option value="ALVITYL CES EFF B/10">ALVITYL CES EFF B/10</option>
                                                    <option value="ALVITYL PLUS CAPS (EUROP)">ALVITYL PLUS CAPS (EUROP)
                                                    </option>
                                                    <option value="ALVYTIL 150 ML SP (EUROP)">ALVYTIL 150 ML SP (EUROP)
                                                    </option>
                                                    <option value="AMBROXE SP 15MG ENF">AMBROXE SP 15MG ENF</option>
                                                    <option value="AMBROXE SP 30 MG ADULT">AMBROXE SP 30 MG ADULT</option>
                                                    <option value="AMBROXOL 15 MG 100 ML ENF LOCAL">AMBROXOL 15 MG 100 ML
                                                        ENF LOCAL</option>
                                                    <option value="AMBROXOL 30 MG SIROP ADULLT LOCAL">AMBROXOL 30 MG SIROP
                                                        ADULLT LOCAL</option>
                                                    <option value="AMICEF INJ">AMICEF INJ</option>
                                                    <option value="AMIDOL 25*10 CES">AMIDOL 25*10 CES</option>
                                                    <option value="AMIFER SP ADULT">AMIFER SP ADULT</option>
                                                    <option value="AMIFER SP JUNIOR 150 ML">AMIFER SP JUNIOR 150 ML</option>
                                                    <option value="AMIGYN 500 MG CES">AMIGYN 500 MG CES</option>
                                                    <option value="AMINOPHYLLINE 10 ML INJ">AMINOPHYLLINE 10 ML INJ</option>
                                                    <option value="AMINOPHYLLINE CES">AMINOPHYLLINE CES</option>
                                                    <option value="AMLO DENK 10MG B/50">AMLO DENK 10MG B/50</option>
                                                    <option value="AMLO DENK 5 MG B/50">AMLO DENK 5 MG B/50</option>
                                                    <option value="AMLOR 10MG CES (EUROP)">AMLOR 10MG CES (EUROP)</option>
                                                    <option value="AMLOX 10 MG CES">AMLOX 10 MG CES</option>
                                                    <option value="AMLOX 5 MG 30 CES">AMLOX 5 MG 30 CES</option>
                                                    <option value="AMOXICILINE 1 GR">AMOXICILINE 1 GR</option>
                                                    <option value="AMOXICILLINE 125 SP">AMOXICILLINE 125 SP</option>
                                                    <option value="AMOXICILLINE 250 SP">AMOXICILLINE 250 SP</option>
                                                    <option value="AMOXYCILLINE 250MG CAPS">AMOXYCILLINE 250MG CAPS</option>
                                                    <option value="AMOXYCILLINE 500MG CAPS">AMOXYCILLINE 500MG CAPS</option>
                                                    <option value="AMPICILLINE 1GR">AMPICILLINE 1GR</option>
                                                    <option value="AMYCEF 1GR INJ B/1 AMP">AMYCEF 1GR INJ B/1 AMP</option>
                                                    <option value="ANAFLAM 60ML SP">ANAFLAM 60ML SP</option>
                                                    <option value="ANAFLAM CES">ANAFLAM CES</option>
                                                    <option value="ANSET SP 30 ML">ANSET SP 30 ML</option>
                                                    <option value="ANSET CES 4 CES">ANSET CES 4 CES</option>
                                                    <option value="ANSET INJ 1 AMP">ANSET INJ 1 AMP</option>
                                                    <option value="ANSET SUPPO">ANSET SUPPO</option>
                                                    <option value="ANTIBIOPUR 0.3% COLLYRE 5ML">ANTIBIOPUR 0.3% COLLYRE 5ML
                                                    </option>
                                                    <option value="ANTIDOUL CES P/10CES">ANTIDOUL CES P/10CES</option>
                                                    <option value="ANTIGRIPE SIROP 100 ML">ANTIGRIPE SIROP 100 ML</option>
                                                    <option value="ANTITOUSSIF 100 ML SP">ANTITOUSSIF 100 ML SP</option>
                                                    <option value="ANUSOL SUPPO (EUROP)">ANUSOL SUPPO (EUROP)</option>
                                                    <option value="APETAMIN SP 200 ML">APETAMIN SP 200 ML</option>
                                                    <option value="APHEROL CAP 3*10">APHEROL CAP 3*10</option>
                                                    <option value="APPETIVIT 200 SP">APPETIVIT 200 SP</option>
                                                    <option value="ARAUBACT INJ 100ML">ARAUBACT INJ 100ML</option>
                                                    <option value="ARAUBACT(10*1) CES">ARAUBACT(10*1) CES</option>
                                                    <option value="ARAUFIX 200 MG 10 CES">ARAUFIX 200 MG 10 CES</option>
                                                    <option value="ARAUFIX OX CES">ARAUFIX OX CES</option>
                                                    <option value="ARAUFLOX IV 100 ML">ARAUFLOX IV 100 ML</option>
                                                    <option value="ARGYROL 0.5% 15 ML">ARGYROL 0.5% 15 ML</option>
                                                    <option value="ARGYROL 1 % 15ML">ARGYROL 1 % 15ML</option>
                                                    <option value="ARODERME CREME 40 GR">ARODERME CREME 40 GR</option>
                                                    <option value="ARODERME POUDRE BABY">ARODERME POUDRE BABY</option>
                                                    <option value="ARODERME POUDRE MEDICAL 50GM">ARODERME POUDRE MEDICAL
                                                        50GM</option>
                                                    <option value="ARODERME POUDRE MEDICALE 100GM">ARODERME POUDRE MEDICALE
                                                        100GM</option>
                                                    <option value="AROGYL B/7 OVULE">AROGYL B/7 OVULE</option>
                                                    <option value="ARTE+LUME 20/120 B/24">ARTE+LUME 20/120 B/24</option>
                                                    <option value="ARTE-PED CES">ARTE-PED CES</option>
                                                    <option value="ARTE-PED 60 ML SP">ARTE-PED 60 ML SP</option>
                                                    <option value="ARTE-PED DT 20/120 MG B/12 CES">ARTE-PED DT 20/120 MG
                                                        B/12 CES</option>
                                                    <option value="ARTE-PED DT 20/120 MG B/6 CES">ARTE-PED DT 20/120 MG B/6
                                                        CES</option>
                                                    <option value="ARTE-PED DT 80/480 MG B/6 CES">ARTE-PED DT 80/480 MG B/6
                                                        CES</option>
                                                    <option value="ARTE-PLUS KIT PACK CAPS">ARTE-PLUS KIT PACK CAPS</option>
                                                    <option value="ARTEMETHER INJ">ARTEMETHER INJ</option>
                                                    <option value="ARTEQUICK CES">ARTEQUICK CES</option>
                                                    <option value="ASPIRINE 500MG CES">ASPIRINE 500MG CES</option>
                                                    <option value="ASPIRINE BAYER JUNIOR 100MG CES (EUROP)">ASPIRINE BAYER
                                                        JUNIOR 100MG CES (EUROP)</option>
                                                    <option value="ATEMAL 120 MG INJ">ATEMAL 120 MG INJ</option>
                                                    <option value="ATEMAL 180 MG INJ">ATEMAL 180 MG INJ</option>
                                                    <option value="ATEMAL 240 MG INJ">ATEMAL 240 MG INJ</option>
                                                    <option value="ATEMAL 30 MG INJ">ATEMAL 30 MG INJ</option>
                                                    <option value="ATEMAL 40/240 MG CES">ATEMAL 40/240 MG CES</option>
                                                    <option value="ATEMAL 60 MG INJ">ATEMAL 60 MG INJ</option>
                                                    <option value="ATEMAL 80/480 6 CES">ATEMAL 80/480 6 CES</option>
                                                    <option value="ATEMAL SUSPENSION 60 ML SP">ATEMAL SUSPENSION 60 ML SP
                                                    </option>
                                                    <option value="ATEMAL150 MG INJ">ATEMAL150 MG INJ</option>
                                                    <option value="ATENOLOL DENK 50 MG 10*10 CES">ATENOLOL DENK 50 MG 10*10
                                                        CES</option>
                                                    <option value="ATROPINE INJ">ATROPINE INJ</option>
                                                    <option value="AUGMENTIN BEBE 30ML PDRE SP">AUGMENTIN BEBE 30ML PDRE SP
                                                    </option>
                                                    <option value="AUGMENTIN ENFT 60 ML PDRE SP (EUROP)">AUGMENTIN ENFT 60
                                                        ML PDRE SP (EUROP)</option>
                                                    <option value="AUVAX 10 MG CES 20 CES">AUVAX 10 MG CES 20 CES</option>
                                                    <option value="AVAMYS 120DOSES 27.5MG (EUROP)">AVAMYS 120DOSES 27.5MG
                                                        (EUROP)</option>
                                                    <option value="AZIPRO 30 ML SP">AZIPRO 30 ML SP</option>
                                                    <option value="AZIPRO 500 MG CES">AZIPRO 500 MG CES</option>
                                                    <option value="BACTRIM 400 MG/80MG 20 CES (ROCHE) (EUROP)">BACTRIM 400
                                                        MG/80MG 20 CES (ROCHE) (EUROP)</option>
                                                    <option value="BACTRIM SUSP PED FL/100ML (EUROP)">BACTRIM SUSP PED
                                                        FL/100ML (EUROP)</option>
                                                    <option value="BANDE CAMBRIC 10CM">BANDE CAMBRIC 10CM</option>
                                                    <option value="BANDE CAMBRIC 5CM">BANDE CAMBRIC 5CM</option>
                                                    <option value="BANDE CAMBRIC 7CM">BANDE CAMBRIC 7CM</option>
                                                    <option value="BANDE ELASTIQUE 10CM">BANDE ELASTIQUE 10CM</option>
                                                    <option value="BANDE ELASTIQUE 15CM">BANDE ELASTIQUE 15CM</option>
                                                    <option value="BANDE ELASTIQUE 5CM">BANDE ELASTIQUE 5CM</option>
                                                    <option value="BANDE ELASTIQUE 7.5CM">BANDE ELASTIQUE 7.5CM</option>
                                                    <option value="BANDE PLATREE 10 CM">BANDE PLATREE 10 CM</option>
                                                    <option value="BANDE PLATREE 15 CM">BANDE PLATREE 15 CM</option>
                                                    <option value="BANDE PLATREE 20 CM">BANDE PLATREE 20 CM</option>
                                                    <option value="BANEOCIN 20 GR PDE (EUROP)">BANEOCIN 20 GR PDE (EUROP)
                                                    </option>
                                                    <option value="BAUM DE NERF (P M)">BAUM DE NERF (P M)</option>
                                                    <option value="BAUME DE NERF (G M)">BAUME DE NERF (G M)</option>
                                                    <option value="BAUME DE NERF SMALL 38 GM">BAUME DE NERF SMALL 38 GM
                                                    </option>
                                                    <option value="BENERVA 300 MG CES (EUROP)">BENERVA 300 MG CES (EUROP)
                                                    </option>
                                                    <option value="BENZOATE DE BENZYL">BENZOATE DE BENZYL</option>
                                                    <option value="BETASOL 15 GR CREAM">BETASOL 15 GR CREAM</option>
                                                    <option value="BETASOL 30 GR CREAM">BETASOL 30 GR CREAM</option>
                                                    <option value="BETASOL LOTION 30 ML">BETASOL LOTION 30 ML</option>
                                                    <option value="BETATOUX 100 ML SP (AFRI PHARMA)">BETATOUX 100 ML SP
                                                        (AFRI PHARMA)</option>
                                                    <option value="BICARBONATE DE SODIUM 100 GM">BICARBONATE DE SODIUM 100
                                                        GM</option>
                                                    <option value="BIEN MANGER 10*10 CES">BIEN MANGER 10*10 CES</option>
                                                    <option value="BIEN MANGER 100 ML SIROP">BIEN MANGER 100 ML SIROP
                                                    </option>
                                                    <option value="BIEN MANGER PLUS 10*10 CES">BIEN MANGER PLUS 10*10 CES
                                                    </option>
                                                    <option value="BIEN MANGER PLUS SP 200 ML">BIEN MANGER PLUS SP 200 ML
                                                    </option>
                                                    <option value="BIOFORCE SP200 ML">BIOFORCE SP200 ML</option>
                                                    <option value="BISTOURI 21">BISTOURI 21</option>
                                                    <option value="BLEU DE METHELINE 60 ML">BLEU DE METHELINE 60 ML</option>
                                                    <option value="BLOUSES USAGE UNIQUE">BLOUSES USAGE UNIQUE</option>
                                                    <option value="BRONCATHIOL SIROP ENF 125 ML">BRONCATHIOL SIROP ENF 125
                                                        ML</option>
                                                    <option value="BRONCATHIOL SUSP EXP 100 ML">BRONCATHIOL SUSP EXP 100 ML
                                                    </option>
                                                    <option value="BRONCHALENE ADULTE 150 ML SP (EUROP)">BRONCHALENE ADULTE
                                                        150 ML SP (EUROP)</option>
                                                    <option value="BRONCHALENE ENF 150 ML SP (EUROP)">BRONCHALENE ENF 150 ML
                                                        SP (EUROP)</option>
                                                    <option value="BUCOLAX (BISACODYL)5MG CES">BUCOLAX (BISACODYL)5MG CES
                                                    </option>
                                                    <option value="BUSCOPAN 1ML INJ">BUSCOPAN 1ML INJ</option>
                                                    <option value="BUSCOPAN CES">BUSCOPAN CES</option>
                                                    <option value="BUTHOL 100ML SIROP">BUTHOL 100ML SIROP</option>
                                                    <option value="C 4 SP">C 4 SP</option>
                                                    <option value="C-XONE 1.5 INJ">C-XONE 1.5 INJ</option>
                                                    <option value="C-XONE INJ (CEFRIAXONE) INJ 1 GM">C-XONE INJ (CEFRIAXONE)
                                                        INJ 1 GM</option>
                                                    <option value="C-XONE S 750 MG INJ">C-XONE S 750 MG INJ</option>
                                                    <option value="C4 CES">C4 CES</option>
                                                    <option value="CAL-D CES B/15">CAL-D CES B/15</option>
                                                    <option value="CALCI DENK CES">CALCI DENK CES</option>
                                                    <option value="CALCIUM GLUCONAT 10 ML INJ">CALCIUM GLUCONAT 10 ML INJ
                                                    </option>
                                                    <option value="CALCUL STOP SP">CALCUL STOP SP</option>
                                                    <option value="CANDEREL CES (EUROP)">CANDEREL CES (EUROP)</option>
                                                    <option value="CANDERM POWDER 50 GM">CANDERM POWDER 50 GM</option>
                                                    <option value="CANDERM PUDRE 100 GM">CANDERM PUDRE 100 GM</option>
                                                    <option value="CANDISTEN OVULE">CANDISTEN OVULE</option>
                                                    <option value="CANDISTEN CREME 30 GR">CANDISTEN CREME 30 GR</option>
                                                    <option value="CANDISTEN POUDRE">CANDISTEN POUDRE</option>
                                                    <option value="CAPTOPRIL 25MG CES">CAPTOPRIL 25MG CES</option>
                                                    <option value="CARBOKINA CES">CARBOKINA CES</option>
                                                    <option value="CAT GUTTE VICRYL NO 1">CAT GUTTE VICRYL NO 1</option>
                                                    <option value="CATGUT CHROMIC 0">CATGUT CHROMIC 0</option>
                                                    <option value="CATGUT CHROMIC 1">CATGUT CHROMIC 1</option>
                                                    <option value="CATGUT CHROMIC 1/0">CATGUT CHROMIC 1/0</option>
                                                    <option value="CATGUT CHROMIC 2">CATGUT CHROMIC 2</option>
                                                    <option value="CATGUT CHROMIC 2/0">CATGUT CHROMIC 2/0</option>
                                                    <option value="CATGUT MERSILENE 1">CATGUT MERSILENE 1</option>
                                                    <option value="CATGUT MERSILENE 2">CATGUT MERSILENE 2</option>
                                                    <option value="CATGUT MERSILENE 2/0">CATGUT MERSILENE 2/0</option>
                                                    <option value="CATGUT VICRYL 0">CATGUT VICRYL 0</option>
                                                    <option value="CATGUT VICRYL 2">CATGUT VICRYL 2</option>
                                                    <option value="CATGUT VICRYL 2/0">CATGUT VICRYL 2/0</option>
                                                    <option value="CEDOCARD 20 MG 100 CES">CEDOCARD 20 MG 100 CES</option>
                                                    <option value="CEFALAXIN 250 MG SP">CEFALAXIN 250 MG SP</option>
                                                    <option value="CEFALEXIN 125 MG SP">CEFALEXIN 125 MG SP</option>
                                                    <option value="CEFALEXINE CAP">CEFALEXINE CAP</option>
                                                    <option value="CEFAM CAPS">CEFAM CAPS</option>
                                                    <option value="CEFATAX INJ SHALINA 1GR">CEFATAX INJ SHALINA 1GR</option>
                                                    <option value="CEFAZONE 1 GR">CEFAZONE 1 GR</option>
                                                    <option value="CEFIXIME 100 SUSP">CEFIXIME 100 SUSP</option>
                                                    <option value="CEFIXIME 400 MG CES">CEFIXIME 400 MG CES</option>
                                                    <option value="CEFIXIME 200 CES">CEFIXIME 200 CES</option>
                                                    <option value="CEFOTAXIM INJ 1GR">CEFOTAXIM INJ 1GR</option>
                                                    <option value="CEFPODOX 200 MG CES">CEFPODOX 200 MG CES</option>
                                                    <option value="CEFPODOX(CEFPODIXINE) 100ML SP">CEFPODOX(CEFPODIXINE)
                                                        100ML SP</option>
                                                    <option value="CEFTRIAXONE 1GR">CEFTRIAXONE 1GR</option>
                                                    <option value="CEFTRIN PLUS 1 G (ALISONS)">CEFTRIN PLUS 1 G (ALISONS)
                                                    </option>
                                                    <option value="CELEBREX 100 MG 20 GELULES">CELEBREX 100 MG 20 GELULES
                                                    </option>
                                                    <option value="CELEBREX 200 MG 10 GELULES">CELEBREX 200 MG 10 GELULES
                                                    </option>
                                                    <option value="CELESTENE GOUTTES 30 ML(EUROP)">CELESTENE GOUTTES 30
                                                        ML(EUROP)</option>
                                                    <option value="CEMYCINE 500MG CES">CEMYCINE 500MG CES</option>
                                                    <option value="CEMYCINE(AZYTHROMYCINE)30ML">CEMYCINE(AZYTHROMYCINE)30ML
                                                    </option>
                                                    <option value="CEPIME 1000 MG INJ">CEPIME 1000 MG INJ</option>
                                                    <option value="CEPRA S 1 GM INJ">CEPRA S 1 GM INJ</option>
                                                    <option value="CEPRA S PLUS 1.5 INJ">CEPRA S PLUS 1.5 INJ</option>
                                                    <option value="CERULYSE SOL 5% AURIC 10 ML (EUROP)">CERULYSE SOL 5%
                                                        AURIC 10 ML (EUROP)</option>
                                                    <option value="CESADOX CES">CESADOX CES</option>
                                                    <option value="CESADOX SP">CESADOX SP</option>
                                                    <option value="CESAFLAM 1*10 CES">CESAFLAM 1*10 CES</option>
                                                    <option value="CESAKROL ADULT SP 30 MG">CESAKROL ADULT SP 30 MG</option>
                                                    <option value="CESAKROL PEDIATRIC 15MG SP">CESAKROL PEDIATRIC 15MG SP
                                                    </option>
                                                    <option value="CESATOUX 100 ML SP">CESATOUX 100 ML SP</option>
                                                    <option value="CETHER L DISPERSIBLE 12 CES">CETHER L DISPERSIBLE 12 CES
                                                    </option>
                                                    <option value="CETHER L DT 6 CES ENF">CETHER L DT 6 CES ENF</option>
                                                    <option value="CETHER-L CES AD">CETHER-L CES AD</option>
                                                    <option value="CETHER-L SP 60ML">CETHER-L SP 60ML</option>
                                                    <option value="CEZINC 20 MG 60 ML">CEZINC 20 MG 60 ML</option>
                                                    <option value="CEZINC 20 MG CES 10 TAB">CEZINC 20 MG CES 10 TAB</option>
                                                    <option value="CHEVAL FORCE 10 CES">CHEVAL FORCE 10 CES</option>
                                                    <option value="CHEWAL FORCE GEL 30 GM">CHEWAL FORCE GEL 30 GM</option>
                                                    <option value="CHLORA OTIQUE DROPS">CHLORA OTIQUE DROPS</option>
                                                    <option value="CHLORAMIN-T 500MG VRAC">CHLORAMIN-T 500MG VRAC</option>
                                                    <option value="CHLORAMPHENICOL PDE OPHTALMIQUE">CHLORAMPHENICOL PDE
                                                        OPHTALMIQUE</option>
                                                    <option value="CHLORAPHENICOL 1GR">CHLORAPHENICOL 1GR</option>
                                                    <option value="CHLORAPHENICOL COLLYRE (EYE DROPS)">CHLORAPHENICOL
                                                        COLLYRE (EYE DROPS)</option>
                                                    <option value="CHLORPHENIRAMINE 4MG CES">CHLORPHENIRAMINE 4MG CES
                                                    </option>
                                                    <option value="CHLORPROMAZINE 50 MG INJ">CHLORPROMAZINE 50 MG INJ
                                                    </option>
                                                    <option value="CHYMAR CES">CHYMAR CES</option>
                                                    <option value="CHYMAR INJ">CHYMAR INJ</option>
                                                    <option value="CIFIN 0.3% COLLYRE 10 ML">CIFIN 0.3% COLLYRE 10 ML
                                                    </option>
                                                    <option value="CIFIN 500 MG CES">CIFIN 500 MG CES</option>
                                                    <option value="CIFIN-DX COLLYRE">CIFIN-DX COLLYRE</option>
                                                    <option value="CIFIN-TZ CES">CIFIN-TZ CES</option>
                                                    <option value="CIMETIDINE 200 MG(TAGA) INJ">CIMETIDINE 200 MG(TAGA) INJ
                                                    </option>
                                                    <option value="CIMETIDINE 200MG CES">CIMETIDINE 200MG CES</option>
                                                    <option value="CIMETIDINE 400MG CES">CIMETIDINE 400MG CES</option>
                                                    <option value="CIPRO-TZ">CIPRO-TZ</option>
                                                    <option value="CIPROFLOXACINE 200 MG INJ">CIPROFLOXACINE 200 MG INJ
                                                    </option>
                                                    <option value="CIPROFLOXACINE 500MG CES">CIPROFLOXACINE 500MG CES
                                                    </option>
                                                    <option value="CIPRONAT 250MG CES (EUROP)">CIPRONAT 250MG CES (EUROP)
                                                    </option>
                                                    <option value="CIPRONAT 500MG CES (EUROP)">CIPRONAT 500MG CES (EUROP)
                                                    </option>
                                                    <option value="CIPRONAT 750 MG CES (EUROP)">CIPRONAT 750 MG CES (EUROP)
                                                    </option>
                                                    <option value="CITRIMEX -DT CES">CITRIMEX -DT CES</option>
                                                    <option value="CITRIMEX SP">CITRIMEX SP</option>
                                                    <option value="CLAMOXYL 125 MG 60 ML SP (EUROP)">CLAMOXYL 125 MG 60 ML
                                                        SP (EUROP)</option>
                                                    <option value="CLAMOXYL 250 MG 60 ML SP (EUROP)">CLAMOXYL 250 MG 60 ML
                                                        SP (EUROP)</option>
                                                    <option value="CLAMOXYL 500 MG CAPS (EUROP)">CLAMOXYL 500 MG CAPS
                                                        (EUROP)</option>
                                                    <option value="CLAMOXYL 500 MG SP 12 DOSES (EUROP)">CLAMOXYL 500 MG SP
                                                        12 DOSES (EUROP)</option>
                                                    <option value="CLAMPCORDON">CLAMPCORDON</option>
                                                    <option value="CLARINEZ 1% AD GOUTT">CLARINEZ 1% AD GOUTT</option>
                                                    <option value="CLARINEZ 0.5% (PED)">CLARINEZ 0.5% (PED)</option>
                                                    <option value="CLIFA 150 MG CAPS">CLIFA 150 MG CAPS</option>
                                                    <option value="CLIFA 150MG INJ">CLIFA 150MG INJ</option>
                                                    <option value="CLIFA 300 MG 2*10CES">CLIFA 300 MG 2*10CES</option>
                                                    <option value="CLIFA 300MG INJ">CLIFA 300MG INJ</option>
                                                    <option value="CLIFA 600 MG CAP">CLIFA 600 MG CAP</option>
                                                    <option value="CLIFA 600 MG INJ">CLIFA 600 MG INJ</option>
                                                    <option value="CLOMID BLEU (EUROP)">CLOMID BLEU (EUROP)</option>
                                                    <option value="CLOTRISAN POUDRE 50 GM">CLOTRISAN POUDRE 50 GM</option>
                                                    <option value="CLOXACILINA INJ">CLOXACILINA INJ</option>
                                                    <option value="CLOXACILLINE 500MG">CLOXACILLINE 500MG</option>
                                                    <option value="CLOZIDERM POWDER 50GR">CLOZIDERM POWDER 50GR</option>
                                                    <option value="CO ARINATE AD FDC 500MG (EUROP)">CO ARINATE AD FDC 500MG
                                                        (EUROP)</option>
                                                    <option value="CO ARINATE FDC JUNIOR 250MG (EUROP)">CO ARINATE FDC
                                                        JUNIOR 250MG (EUROP)</option>
                                                    <option value="CO ARTESIANE 60ML SP (EUROP)">CO ARTESIANE 60ML SP
                                                        (EUROP)</option>
                                                    <option value="CO TRIMOXAZOL 100 ML SP">CO TRIMOXAZOL 100 ML SP</option>
                                                    <option value="CO TRIMOXAZOLE 480MG CES">CO TRIMOXAZOLE 480MG CES
                                                    </option>
                                                    <option value="CO TRIMOXAZOLE FORTE CES">CO TRIMOXAZOLE FORTE CES
                                                    </option>
                                                    <option value="COARTEM 80/480 B/6 CES">COARTEM 80/480 B/6 CES</option>
                                                    <option value="COARTEM DISPERSIBLE 20/120 MG">COARTEM DISPERSIBLE 20/120
                                                        MG</option>
                                                    <option value="COLCHICINE HOUDE CES (EUROP)">COLCHICINE HOUDE CES
                                                        (EUROP)</option>
                                                    <option value="COLD CES">COLD CES</option>
                                                    <option value="COLD EXPRESS 0.05% NASAL SPRAY">COLD EXPRESS 0.05% NASAL
                                                        SPRAY</option>
                                                    <option value="COLD EXPRESS 0.1% NASAL SPRAY">COLD EXPRESS 0.1% NASAL
                                                        SPRAY</option>
                                                    <option value="COLD EXPRESS 25*4 CES">COLD EXPRESS 25*4 CES</option>
                                                    <option value="COLD-EXPRESS INHELAR">COLD-EXPRESS INHELAR</option>
                                                    <option value="COLDRIL SP 100 ML">COLDRIL SP 100 ML</option>
                                                    <option value="COLDTEC SP 100 ML">COLDTEC SP 100 ML</option>
                                                    <option value="COMBAVERS 15 ML SP">COMBAVERS 15 ML SP</option>
                                                    <option value="COMBAVERS CES">COMBAVERS CES</option>
                                                    <option value="COMBO PAIN CES">COMBO PAIN CES</option>
                                                    <option value="COMPRESSED STERILE 30*30 CM">COMPRESSED STERILE 30*30 CM
                                                    </option>
                                                    <option value="COMPRESSED STERILE 40*40 CM">COMPRESSED STERILE 40*40 CM
                                                    </option>
                                                    <option value="CONDOM CHIC 50 PES">CONDOM CHIC 50 PES</option>
                                                    <option value="CONDOM OK">CONDOM OK</option>
                                                    <option value="CONDOM OK 24*3">CONDOM OK 24*3</option>
                                                    <option value="CONFO VICKS LIO">CONFO VICKS LIO</option>
                                                    <option value="CORTI SPRAY">CORTI SPRAY</option>
                                                    <option value="CORTI-STENE 30 ML GOUTTE">CORTI-STENE 30 ML GOUTTE
                                                    </option>
                                                    <option value="CREAM MONGANGA BEBE">CREAM MONGANGA BEBE</option>
                                                    <option value="CYCLOMAX OVULES">CYCLOMAX OVULES</option>
                                                    <option value="CYPLA 4 CES">CYPLA 4 CES</option>
                                                    <option value="CYPLA 4 SP">CYPLA 4 SP</option>
                                                    <option value="CYPOMEX CES">CYPOMEX CES</option>
                                                    <option value="CYPOMEX SP">CYPOMEX SP</option>
                                                    <option value="DAFRACLAVE 400/57MG SP (EUROP)">DAFRACLAVE 400/57MG SP
                                                        (EUROP)</option>
                                                    <option value="DAVIGRA 100 MG CES B/2">DAVIGRA 100 MG CES B/2</option>
                                                    <option value="DAVIGRA 50MG CES">DAVIGRA 50MG CES</option>
                                                    <option value="DECONTRACTYL 50 DRA (EUROP)">DECONTRACTYL 50 DRA (EUROP)
                                                    </option>
                                                    <option value="DECONTRACTYL 80 GR PDE (EUROP)">DECONTRACTYL 80 GR PDE
                                                        (EUROP)</option>
                                                    <option value="DELTAVAGINE OV">DELTAVAGINE OV</option>
                                                    <option value="DEPAKINE 200MG CES (EUROP)">DEPAKINE 200MG CES (EUROP)
                                                    </option>
                                                    <option value="DEPAKINE 500MG CES (EUROP)">DEPAKINE 500MG CES (EUROP)
                                                    </option>
                                                    <option value="DEPAKINE GTTES 40ML">DEPAKINE GTTES 40ML</option>
                                                    <option value="DERMATOL POUDRE 50 GM">DERMATOL POUDRE 50 GM</option>
                                                    <option value="DERMOBACTER SOL 125 ML (EUROP)">DERMOBACTER SOL 125 ML
                                                        (EUROP)</option>
                                                    <option value="DETTOL 50 ML. LIQUIDE">DETTOL 50 ML. LIQUIDE</option>
                                                    <option value="DETTOL 125ML LIQUIDE">DETTOL 125ML LIQUIDE</option>
                                                    <option value="DETTOL 250ML LIQUIDE">DETTOL 250ML LIQUIDE</option>
                                                    <option value="DETTOL SOAP 175 GM">DETTOL SOAP 175 GM</option>
                                                    <option value="DEXACORT CES">DEXACORT CES</option>
                                                    <option value="DEXAMETHASON COLLYRE 5ML">DEXAMETHASON COLLYRE 5ML
                                                    </option>
                                                    <option value="DEXAMETHASONE 0.5MG CES">DEXAMETHASONE 0.5MG CES</option>
                                                    <option value="DEXAMETHAZONE 1ML INJ">DEXAMETHAZONE 1ML INJ</option>
                                                    <option value="DEXAROSE 0.50 MG CES 10*10">DEXAROSE 0.50 MG CES 10*10
                                                    </option>
                                                    <option value="DEXOL CAPS">DEXOL CAPS</option>
                                                    <option value="DEXOL SP 100 ML">DEXOL SP 100 ML</option>
                                                    <option value="DIAZEPAM CES">DIAZEPAM CES</option>
                                                    <option value="DIAZEPAM INJ">DIAZEPAM INJ</option>
                                                    <option value="DICLO DENK 100 MG SUPPO">DICLO DENK 100 MG SUPPO</option>
                                                    <option value="DICLO-DENK 100 MG 10 SUPPO">DICLO-DENK 100 MG 10 SUPPO
                                                    </option>
                                                    <option value="DICLOBIS COLLYRE 0.1% 5ML">DICLOBIS COLLYRE 0.1% 5ML
                                                    </option>
                                                    <option value="DICLOFENAC 100 MG CES B/10X10">DICLOFENAC 100 MG CES
                                                        B/10X10</option>
                                                    <option value="DICLOFENAC 100MG SUPPO">DICLOFENAC 100MG SUPPO</option>
                                                    <option value="DICLOFENAC 50 MG CES">DICLOFENAC 50 MG CES</option>
                                                    <option value="DICLOFENAC INJ 75 MG INJ">DICLOFENAC INJ 75 MG INJ
                                                    </option>
                                                    <option value="DICYNONE 250 MG CAPS (EUROP)">DICYNONE 250 MG CAPS
                                                        (EUROP)</option>
                                                    <option value="DICYNONE 250MG AMP (EUROP)">DICYNONE 250MG AMP (EUROP)
                                                    </option>
                                                    <option value="DIGEST SACHET B/1 15ML">DIGEST SACHET B/1 15ML</option>
                                                    <option value="DIGEST SP">DIGEST SP</option>
                                                    <option value="DIGOXINE AMP INJ B/6 AMP">DIGOXINE AMP INJ B/6 AMP
                                                    </option>
                                                    <option value="DIGOXINE NATIVELLE CES (EUROP)">DIGOXINE NATIVELLE CES
                                                        (EUROP)</option>
                                                    <option value="DIMAGEL SP">DIMAGEL SP</option>
                                                    <option value="DIPERAKINES CES">DIPERAKINES CES</option>
                                                    <option value="DIPROSON 15 GR CREAM">DIPROSON 15 GR CREAM</option>
                                                    <option value="DIPROSON LOTION 30 ML">DIPROSON LOTION 30 ML</option>
                                                    <option value="DIVA NORMAL">DIVA NORMAL</option>
                                                    <option value="DOLA CR CAPSULES">DOLA CR CAPSULES</option>
                                                    <option value="DOLAREN GEL">DOLAREN GEL</option>
                                                    <option value="DOLAREN PLUS GEL">DOLAREN PLUS GEL</option>
                                                    <option value="DOLAREN PLUS SPRAY">DOLAREN PLUS SPRAY</option>
                                                    <option value="DOLIPRANE 1GR EFF CES (EUROP)">DOLIPRANE 1GR EFF CES
                                                        (EUROP)</option>
                                                    <option value="DOLIPRANE 200MG SUPPO (EUROP)">DOLIPRANE 200MG SUPPO
                                                        (EUROP)</option>
                                                    <option value="DOLIPRANE 300MG SUPPO (EUROP)">DOLIPRANE 300MG SUPPO
                                                        (EUROP)</option>
                                                    <option value="DOLIPRANE 500MG EFF CES (EUROP)">DOLIPRANE 500MG EFF CES
                                                        (EUROP)</option>
                                                    <option value="DOLIPRANE SUPPO 150MG (EUROP)">DOLIPRANE SUPPO 150MG
                                                        (EUROP)</option>
                                                    <option value="DOXYCILLINE 100MG CAPS">DOXYCILLINE 100MG CAPS</option>
                                                    <option value="DOZAR INJ">DOZAR INJ</option>
                                                    <option value="DOZAR INJ 4.5 MG">DOZAR INJ 4.5 MG</option>
                                                    <option value="DR CALM BAUM 6GM">DR CALM BAUM 6GM</option>
                                                    <option value="DR CALM BAUM 9GM">DR CALM BAUM 9GM</option>
                                                    <option value="DR COLD CES">DR COLD CES</option>
                                                    <option value="DR MAISON">DR MAISON</option>
                                                    <option value="DR MUTU P/10 CES">DR MUTU P/10 CES</option>
                                                    <option value="DR ZINC 10 CES">DR ZINC 10 CES</option>
                                                    <option value="DR.COLD SP 125MG 100ML">DR.COLD SP 125MG 100ML</option>
                                                    <option value="DUPHALAC 200 ML SUSP (EUROP)">DUPHALAC 200 ML SUSP
                                                        (EUROP)</option>
                                                    <option value="DUPHALAC SACHET (EUROP)">DUPHALAC SACHET (EUROP)</option>
                                                    <option value="DUPHALGEL SP">DUPHALGEL SP</option>
                                                    <option value="DUPHASTON 10MG CES (EUROP)">DUPHASTON 10MG CES (EUROP)
                                                    </option>
                                                    <option value="DUSPATALIN 200 MG 30 CES">DUSPATALIN 200 MG 30 CES
                                                    </option>
                                                    <option value="EAU DIGESTIVE 100 ML SP">EAU DIGESTIVE 100 ML SP</option>
                                                    <option value="EAU DISTILLEE 10 ML">EAU DISTILLEE 10 ML</option>
                                                    <option value="EAU OXYGENE 10 VOL 60 ML(LOCAL)">EAU OXYGENE 10 VOL 60
                                                        ML(LOCAL)</option>
                                                    <option value="EAU OXYGENEE 10 VOL 120 ML (EUROP)">EAU OXYGENEE 10 VOL
                                                        120 ML (EUROP)</option>
                                                    <option value="EFFERALGAN (VIT C) CES EFF (EUROP)">EFFERALGAN (VIT C)
                                                        CES EFF (EUROP)</option>
                                                    <option value="EFFERALGAN 500MG CES EFF (EUROP)">EFFERALGAN 500MG CES
                                                        EFF (EUROP)</option>
                                                    <option value="EFFERALGAN CES EFF + VIT C EFF CES">EFFERALGAN CES EFF +
                                                        VIT C EFF CES</option>
                                                    <option value="EFFERALGAN CODEINE CES (EUROP)">EFFERALGAN CODEINE CES
                                                        (EUROP)</option>
                                                    <option value="EFFERALGAN PED 90ML SP (EUROP)">EFFERALGAN PED 90ML SP
                                                        (EUROP)</option>
                                                    <option value="EFFORTIL GOUTTE 30 ML (EUROP)">EFFORTIL GOUTTE 30 ML
                                                        (EUROP)</option>
                                                    <option value="EFICEF 200 MG CES">EFICEF 200 MG CES</option>
                                                    <option value="EFISHAL (VITE) 200 CAPS">EFISHAL (VITE) 200 CAPS</option>
                                                    <option value="ENA DENK 10 MG">ENA DENK 10 MG</option>
                                                    <option value="EPIDERM 15 GR CREAM">EPIDERM 15 GR CREAM</option>
                                                    <option value="EPIDERM 30 GR CREAM">EPIDERM 30 GR CREAM</option>
                                                    <option value="EPIDERM 30 ML LOTION">EPIDERM 30 ML LOTION</option>
                                                    <option value="EPIGRANIEN G21">EPIGRANIEN G21</option>
                                                    <option value="EPIGRANIEN G23">EPIGRANIEN G23</option>
                                                    <option value="ERGOGINE 50 ML GTTE">ERGOGINE 50 ML GTTE</option>
                                                    <option value="ERGOMETHRINE INJ">ERGOMETHRINE INJ</option>
                                                    <option value="ERYTHROMYCINE 125 ML SP">ERYTHROMYCINE 125 ML SP</option>
                                                    <option value="ERYTHROMYCINE 250 MG CES">ERYTHROMYCINE 250 MG CES
                                                    </option>
                                                    <option value="ERYTHROMYCINE 250 ML SP">ERYTHROMYCINE 250 ML SP</option>
                                                    <option value="ERYTHROMYCINE 500 MG CES">ERYTHROMYCINE 500 MG CES
                                                    </option>
                                                    <option value="ESIDREX 25MG CES (EUROP)">ESIDREX 25MG CES (EUROP)
                                                    </option>
                                                    <option value="ESMODOM(ESOMO+DOMPERIDON) CAPS">ESMODOM(ESOMO+DOMPERIDON)
                                                        CAPS</option>
                                                    <option value="ESOMEX 40 INJ">ESOMEX 40 INJ</option>
                                                    <option value="ESOMEX 40 MG CAP">ESOMEX 40 MG CAP</option>
                                                    <option value="ESTOWASH 100ML SOLUTION">ESTOWASH 100ML SOLUTION</option>
                                                    <option value="ESTRADIOL 2 ML INJ">ESTRADIOL 2 ML INJ</option>
                                                    <option value="EUCALYPTOL 100 ML SP (AFRI PHARMA)">EUCALYPTOL 100 ML SP
                                                        (AFRI PHARMA)</option>
                                                    <option value="EXOFEN 160 GR POUDRE (EUROP)">EXOFEN 160 GR POUDRE
                                                        (EUROP)</option>
                                                    <option value="EXOZOL CREAM PLUS 50GM">EXOZOL CREAM PLUS 50GM</option>
                                                    <option value="EXOZOL POWDER 75GM">EXOZOL POWDER 75GM</option>
                                                    <option value="EXTRAPOWER 10CES">EXTRAPOWER 10CES</option>
                                                    <option value="FALCIDOX CES">FALCIDOX CES</option>
                                                    <option value="FALOR 100 ML VAGINAL SOL">FALOR 100 ML VAGINAL SOL
                                                    </option>
                                                    <option value="FANSIDAR CES (EUROP)">FANSIDAR CES (EUROP)</option>
                                                    <option value="FAST QUICK CES B/1">FAST QUICK CES B/1</option>
                                                    <option value="FEMACLIN 3 OV (CLINDAMYCINE+CLOTRIMAZOLE)">FEMACLIN 3 OV
                                                        (CLINDAMYCINE+CLOTRIMAZOLE)</option>
                                                    <option value="FENZAR 18MG 1*10 CES">FENZAR 18MG 1*10 CES</option>
                                                    <option value="FENZAR 6MG 1*10 CES">FENZAR 6MG 1*10 CES</option>
                                                    <option value="FERDROP PLUS GOUTTES 30 ML">FERDROP PLUS GOUTTES 30 ML
                                                    </option>
                                                    <option value="FERRO VITAL DENK 30 CES">FERRO VITAL DENK 30 CES</option>
                                                    <option value="FERVEX ADULTES (EUROP)">FERVEX ADULTES (EUROP)</option>
                                                    <option value="FIXALEN 100MG SUSP">FIXALEN 100MG SUSP</option>
                                                    <option value="FIXALEN 200 MG CES">FIXALEN 200 MG CES</option>
                                                    <option value="FIXALEN 400 MG CES">FIXALEN 400 MG CES</option>
                                                    <option value="FLAGYL 4% SP 120ML (EUROP)">FLAGYL 4% SP 120ML (EUROP)
                                                    </option>
                                                    <option value="FLAGYL 500 MG CES (EUROP)">FLAGYL 500 MG CES (EUROP)
                                                    </option>
                                                    <option value="FLAMASIF 1*10CES">FLAMASIF 1*10CES</option>
                                                    <option value="FLAMO CES">FLAMO CES</option>
                                                    <option value="FLAZINE 50 GR PDE">FLAZINE 50 GR PDE</option>
                                                    <option value="FLODENT 100 GM (SHALINA)">FLODENT 100 GM (SHALINA)
                                                    </option>
                                                    <option value="FLODENT 50GM ( SHALINA)">FLODENT 50GM ( SHALINA)</option>
                                                    <option value="FLODENT CHARCOAL TOOTHPAST 100 GR">FLODENT CHARCOAL
                                                        TOOTHPAST 100 GR</option>
                                                    <option value="FLODENT GINGER MINT TOOTHPASTE 100 GR">FLODENT GINGER
                                                        MINT TOOTHPASTE 100 GR</option>
                                                    <option value="FLODENT KIDS TOOTHPASTE 50 GR">FLODENT KIDS TOOTHPASTE 50
                                                        GR</option>
                                                    <option value="FLUCONAZOLE INFUSION IV">FLUCONAZOLE INFUSION IV</option>
                                                    <option value="FLUCONAZOLE- 150MG B/1 CES">FLUCONAZOLE- 150MG B/1 CES
                                                    </option>
                                                    <option value="FLUDITEC AD 5% 125ML SP (EUROP)">FLUDITEC AD 5% 125ML SP
                                                        (EUROP)</option>
                                                    <option value="FLUDITEC ENF 2% 125ML SP (EUROP)">FLUDITEC ENF 2% 125ML
                                                        SP (EUROP)</option>
                                                    <option value="FLUOMIZIN 10 MG 6CES VAG">FLUOMIZIN 10 MG 6CES VAG
                                                    </option>
                                                    <option value="FORTALINE 20 CAPS (EUROP)">FORTALINE 20 CAPS (EUROP)
                                                    </option>
                                                    <option value="FUNBACT 75 GM SOAP">FUNBACT 75 GM SOAP</option>
                                                    <option value="FUNBACT A CREME 15 GM">FUNBACT A CREME 15 GM</option>
                                                    <option value="FUNBACT CREME 30GM TUBE">FUNBACT CREME 30GM TUBE</option>
                                                    <option value="FUNBACT POUDRE 50 GM">FUNBACT POUDRE 50 GM</option>
                                                    <option value="FUNBACT SOAP 125 GM">FUNBACT SOAP 125 GM</option>
                                                    <option value="FUNOTRIP CES">FUNOTRIP CES</option>
                                                    <option value="FU">FU</option>
                                                    <option value="FURADANTINE CES">FURADANTINE CES</option>
                                                    <option value="FUROSEMIDE CES (LASIX)">FUROSEMIDE CES (LASIX)</option>
                                                    <option value="FUROSEMIDE INJ (LASIX)">FUROSEMIDE INJ (LASIX)</option>
                                                    <option value="G-FLAM SP 60 ML">G-FLAM SP 60 ML</option>
                                                    <option value="GANT # 7.5">GANT # 7.5</option>
                                                    <option value="GANT EXAMEN">GANT EXAMEN</option>
                                                    <option value="GARANTI 3 CES">GARANTI 3 CES</option>
                                                    <option value="GASTROMEX (10X10) CES">GASTROMEX (10X10) CES</option>
                                                    <option value="GASTROMEX(ANTIACID) 100 ML SP">GASTROMEX(ANTIACID) 100 ML
                                                        SP</option>
                                                    <option value="GAVISCON SUSP BUV FL/250 ML (EUROP)">GAVISCON SUSP BUV
                                                        FL/250 ML (EUROP)</option>
                                                    <option value="GAVISCON SUSP BUV SACH B/24">GAVISCON SUSP BUV SACH B/24
                                                    </option>
                                                    <option value="GAZ HYDROPHYLE PM (NIKITA)">GAZ HYDROPHYLE PM (NIKITA)
                                                    </option>
                                                    <option value="GAZ HYDROPHYLLE ( G M )">GAZ HYDROPHYLLE ( G M )</option>
                                                    <option value="GAZ RULLO">GAZ RULLO</option>
                                                    <option value="GENTAMYCINE 80 MG 2 ML INJ">GENTAMYCINE 80 MG 2 ML INJ
                                                    </option>
                                                    <option value="GENTAMYCINE COLLYRE">GENTAMYCINE COLLYRE</option>
                                                    <option value="GERMOL LIQUID 1000 ML">GERMOL LIQUID 1000 ML</option>
                                                    <option value="GERMOL LIQUID 100 ML">GERMOL LIQUID 100 ML</option>
                                                    <option value="GERMOL LIQUID 50 ML">GERMOL LIQUID 50 ML</option>
                                                    <option value="GERMOL SOAP 125 GM">GERMOL SOAP 125 GM</option>
                                                    <option value="GERMOL SOAP 75GM">GERMOL SOAP 75GM</option>
                                                    <option value="GET BACT PLUS CREAM 50 GM">GET BACT PLUS CREAM 50 GM
                                                    </option>
                                                    <option value="GET-SPASS CES 10*10">GET-SPASS CES 10*10</option>
                                                    <option value="GET-Z+ SP 200ML">GET-Z+ SP 200ML</option>
                                                    <option value="GETOMOL 10CES">GETOMOL 10CES</option>
                                                    <option value="GETSTATINE SP 30 ML">GETSTATINE SP 30 ML</option>
                                                    <option value="GLIBEN CES">GLIBEN CES</option>
                                                    <option value="GLIBEN M CES">GLIBEN M CES</option>
                                                    <option value="GLUCOPHAGE 500MG CES (EUROP)">GLUCOPHAGE 500MG CES
                                                        (EUROP)</option>
                                                    <option value="GLUCOPHAGE 500MG CES B/60 (EUROP)">GLUCOPHAGE 500MG CES
                                                        B/60 (EUROP)</option>
                                                    <option value="GLUCOPHAGE 850 MG B/30 CES">GLUCOPHAGE 850 MG B/30 CES
                                                    </option>
                                                    <option value="GLUCOSE HYPERTONIQUE 50% 50ML">GLUCOSE HYPERTONIQUE 50%
                                                        50ML</option>
                                                    <option value="GOGYNAX 30 GR CREME">GOGYNAX 30 GR CREME</option>
                                                    <option value="GOGYNAX VAGINAL">GOGYNAX VAGINAL</option>
                                                    <option value="GRIMOL SP 50 ML">GRIMOL SP 50 ML</option>
                                                    <option value="GRIPAL ADULT SP 100 ML">GRIPAL ADULT SP 100 ML</option>
                                                    <option value="GRIPAL ENFANT 100ML SP">GRIPAL ENFANT 100ML SP</option>
                                                    <option value="GRIPAL NOURRISSON 100ML SP">GRIPAL NOURRISSON 100ML SP
                                                    </option>
                                                    <option value="GRIPE WATERE 200 ML">GRIPE WATERE 200 ML</option>
                                                    <option value="GRISEOFULVINE 500 MG CES">GRISEOFULVINE 500 MG CES
                                                    </option>
                                                    <option value="GY-TEC TABLET 4 CES">GY-TEC TABLET 4 CES</option>
                                                    <option value="H+ METRO TAB 10 CES">H+ METRO TAB 10 CES</option>
                                                    <option value="H.Q NORMEL">H.Q NORMEL</option>
                                                    <option value="HELMINTOX ADULT SACHET(EUROPE)">HELMINTOX ADULT
                                                        SACHET(EUROPE)</option>
                                                    <option value="HEMAMEX SIROP 100 ML">HEMAMEX SIROP 100 ML</option>
                                                    <option value="HEMOBIN 100ML SIROP">HEMOBIN 100ML SIROP</option>
                                                    <option value="HEMOFORCE 200ML SP">HEMOFORCE 200ML SP</option>
                                                    <option value="HEMOFORCE PLUS ZINC 200ML SP">HEMOFORCE PLUS ZINC 200ML
                                                        SP</option>
                                                    <option value="HEMOFORCE PREGA SP">HEMOFORCE PREGA SP</option>
                                                    <option value="HEMOGLOBINE B12 100 ML SP">HEMOGLOBINE B12 100 ML SP
                                                    </option>
                                                    <option value="HEMOREX CAPS">HEMOREX CAPS</option>
                                                    <option value="HEMORRIDAL HC">HEMORRIDAL HC</option>
                                                    <option value="HEMORRIDAL SUPPO">HEMORRIDAL SUPPO</option>
                                                    <option value="HEPTIN SP 2 MG 100 ML">HEPTIN SP 2 MG 100 ML</option>
                                                    <option value="HERCULES CAP B/30">HERCULES CAP B/30</option>
                                                    <option value="HIFER CES">HIFER CES</option>
                                                    <option value="HIFER SP 150ML">HIFER SP 150ML</option>
                                                    <option value="HIFER-Z 200 SP ML">HIFER-Z 200 SP ML</option>
                                                    <option value="HISTAMINIC 100 ML SP">HISTAMINIC 100 ML SP</option>
                                                    <option value="HP-3 KIT 1*7">HP-3 KIT 1*7</option>
                                                    <option value="HUILE DE RICIN 100 ML">HUILE DE RICIN 100 ML</option>
                                                    <option value="HYDROCORTISONE 1 GR">HYDROCORTISONE 1 GR</option>
                                                    <option value="IBU FORTE CAPS">IBU FORTE CAPS</option>
                                                    <option value="IBUCAP CAPS 25*10">IBUCAP CAPS 25*10</option>
                                                    <option value="IBUCAP FORTE CAPS 10*10">IBUCAP FORTE CAPS 10*10</option>
                                                    <option value="IBUCAP SP">IBUCAP SP</option>
                                                    <option value="IBUCAP SUSP 100/125MG 60ML">IBUCAP SUSP 100/125MG 60ML
                                                    </option>
                                                    <option value="IBUPROFEN 400MG CES">IBUPROFEN 400MG CES</option>
                                                    <option value="IBUSIF (IBUPROFEN)100MG 60ML">IBUSIF (IBUPROFEN)100MG
                                                        60ML</option>
                                                    <option value="IBUTEC 10*10">IBUTEC 10*10</option>
                                                    <option value="IBUTEC CAP 25*10">IBUTEC CAP 25*10</option>
                                                    <option value="IDEOS CES (EUROP)">IDEOS CES (EUROP)</option>
                                                    <option value="INDOBIS 0.1% COLLYRE">INDOBIS 0.1% COLLYRE</option>
                                                    <option value="INDOMETHACINE 100MG SUPP">INDOMETHACINE 100MG SUPP
                                                    </option>
                                                    <option value="INDOMETHACINE 25MG CAPS">INDOMETHACINE 25MG CAPS</option>
                                                    <option value="INFERON 5 ML INJ">INFERON 5 ML INJ</option>
                                                    <option value="INSULINE ACTRAPID 40UI/10ML (EUROP)">INSULINE ACTRAPID
                                                        40UI/10ML (EUROP)</option>
                                                    <option value="INSULINE INSULATARD 40UI 10 ML-EUROP">INSULINE INSULATARD
                                                        40UI 10 ML-EUROP</option>
                                                    <option value="IPPROSEC INJ">IPPROSEC INJ</option>
                                                    <option value="IPPROSEC(OMEPRAZOLE) 10CES">IPPROSEC(OMEPRAZOLE) 10CES
                                                    </option>
                                                    <option value="KAMACILONE 4 MG CES">KAMACILONE 4 MG CES</option>
                                                    <option value="KAMASUTRA CONDOM 3'S CHOCOLATE 24*3">KAMASUTRA CONDOM 3'S
                                                        CHOCOLATE 24*3</option>
                                                    <option value="KAMASUTRA CONDOM 3'S DOTTED 24*3">KAMASUTRA CONDOM 3'S
                                                        DOTTED 24*3</option>
                                                    <option value="KAMASUTRA CONDOM 3'S LONGLAST 24*3">KAMASUTRA CONDOM 3'S
                                                        LONGLAST 24*3</option>
                                                    <option value="KANAMYCINE 1 GR">KANAMYCINE 1 GR</option>
                                                    <option value="KAOLIN PLUS SP">KAOLIN PLUS SP</option>
                                                    <option value="KAPTOL LOZENGES 2*8 CES">KAPTOL LOZENGES 2*8 CES</option>
                                                    <option value="KAPTOL SP 100 ML">KAPTOL SP 100 ML</option>
                                                    <option value="KAPTOL SUPPO BEBE">KAPTOL SUPPO BEBE</option>
                                                    <option value="KAPTOL SUPPO ENF">KAPTOL SUPPO ENF</option>
                                                    <option value="KENAKORT 40 MG INJ (EUROP)">KENAKORT 40 MG INJ (EUROP)
                                                    </option>
                                                    <option value="KETAMINE HCL INJ">KETAMINE HCL INJ</option>
                                                    <option value="KETAZOL 15 GR CREAM">KETAZOL 15 GR CREAM</option>
                                                    <option value="KETAZOL 30 GR CREAM">KETAZOL 30 GR CREAM</option>
                                                    <option value="KETAZOL CES 10 CES">KETAZOL CES 10 CES</option>
                                                    <option value="KETAZOL SHAMPOO 100ML">KETAZOL SHAMPOO 100ML</option>
                                                    <option value="KIDA PLUS CES">KIDA PLUS CES</option>
                                                    <option value="KIDA PLUS SUSP 10ML">KIDA PLUS SUSP 10ML</option>
                                                    <option value="KIFARU 100MG CES">KIFARU 100MG CES</option>
                                                    <option value="KIFARU 50MG CES">KIFARU 50MG CES</option>
                                                    <option value="KINFORCE 100 MG/4 CES">KINFORCE 100 MG/4 CES</option>
                                                    <option value="KITOKO 4 CES 50 MG">KITOKO 4 CES 50 MG</option>
                                                    <option value="KITOKO PLUS 4 CES 100 MG">KITOKO PLUS 4 CES 100 MG
                                                    </option>
                                                    <option value="KOFLYN ROUGE 100 ML SP(MUCOLYTIQUE)">KOFLYN ROUGE 100 ML
                                                        SP(MUCOLYTIQUE)</option>
                                                    <option value="KOFSIL (COUGH SIROP) 100 ML SP">KOFSIL (COUGH SIROP) 100
                                                        ML SP</option>
                                                    <option value="KOFSIL PASTILLES">KOFSIL PASTILLES</option>
                                                    <option value="KULA MUZURI 100 ML SP">KULA MUZURI 100 ML SP</option>
                                                    <option value="L-ARTEM FORTE CES">L-ARTEM FORTE CES</option>
                                                    <option value="LACTOLASE 100 ML FLC">LACTOLASE 100 ML FLC</option>
                                                    <option value="LACTOLASE 15ML SACHET B/20">LACTOLASE 15ML SACHET B/20
                                                    </option>
                                                    <option value="LARGACTIL 25 MG 5 ML INJ (EUROP)">LARGACTIL 25 MG 5 ML
                                                        INJ (EUROP)</option>
                                                    <option value="LAROXYL 50 MG CES">LAROXYL 50 MG CES</option>
                                                    <option value="LEMOTIN CES">LEMOTIN CES</option>
                                                    <option value="LEOPARD BALM 6GR">LEOPARD BALM 6GR</option>
                                                    <option value="LEOPARD TUBE BALM 25 GR">LEOPARD TUBE BALM 25 GR</option>
                                                    <option value="LEVAMISOLE 150 MG CES">LEVAMISOLE 150 MG CES</option>
                                                    <option value="LEVAMISOLE 50 MG CES">LEVAMISOLE 50 MG CES</option>
                                                    <option value="LEVOCIZINE 10 CES">LEVOCIZINE 10 CES</option>
                                                    <option value="LEVOCIZINE SP">LEVOCIZINE SP</option>
                                                    <option value="LEXOTAN 3MG CES (EUROP)">LEXOTAN 3MG CES (EUROP)</option>
                                                    <option value="LEXOTAN 6MG CES (EUROP)">LEXOTAN 6MG CES (EUROP)</option>
                                                    <option value="LIDOCAINE 2%(ANESTHESIQUE LOCAL) 20 ML">LIDOCAINE
                                                        2%(ANESTHESIQUE LOCAL) 20 ML</option>
                                                    <option value="LINCOCIN 500 MG CAPS (EUROP)">LINCOCIN 500 MG CAPS
                                                        (EUROP)</option>
                                                    <option value="LINCOMYCIN 600 MG AMP">LINCOMYCIN 600 MG AMP</option>
                                                    <option value="LINCOMYCINE 250 MG/5ML SP">LINCOMYCINE 250 MG/5ML SP
                                                    </option>
                                                    <option value="LINCOMYCINE 500MG">LINCOMYCINE 500MG</option>
                                                    <option value="LOPRADE 2MG CES">LOPRADE 2MG CES</option>
                                                    <option value="LOSACAED-H CES">LOSACAED-H CES</option>
                                                    <option value="LOTION MONGANGA BEBE">LOTION MONGANGA BEBE</option>
                                                    <option value="LUAGRA CES 2 CES">LUAGRA CES 2 CES</option>
                                                    <option value="LUAGRA JELLY">LUAGRA JELLY</option>
                                                    <option value="LUMEART 20/120 B/12 CES">LUMEART 20/120 B/12 CES</option>
                                                    <option value="LUMEART 40/240MG CES">LUMEART 40/240MG CES</option>
                                                    <option value="LUMEART 80/480 MG CES">LUMEART 80/480 MG CES</option>
                                                    <option value="LUTHER DP CES">LUTHER DP CES</option>
                                                    <option value="LUTHER FORTE CES">LUTHER FORTE CES</option>
                                                    <option value="LUTHER SP 60ML">LUTHER SP 60ML</option>
                                                    <option value="LYNEVIT CAP">LYNEVIT CAP</option>
                                                    <option value="LYNEVIT SYRUP 100 ML">LYNEVIT SYRUP 100 ML</option>
                                                    <option value="MAALOX 250 ML SP (EUROP)">MAALOX 250 ML SP (EUROP)
                                                    </option>
                                                    <option value="MAALOX CES B/40 (EUROP)">MAALOX CES B/40 (EUROP)</option>
                                                    <option value="MAALOX SACHET 15ML (EUROP)">MAALOX SACHET 15ML (EUROP)
                                                    </option>
                                                    <option value="MAGASCON SACHET 15 ML">MAGASCON SACHET 15 ML</option>
                                                    <option value="MAGASCONE SP 200 ML">MAGASCONE SP 200 ML</option>
                                                    <option value="MAGNAVIT 30 CAPS">MAGNAVIT 30 CAPS</option>
                                                    <option value="MAGNAVIT TONIC 200 ML SP">MAGNAVIT TONIC 200 ML SP
                                                    </option>
                                                    <option value="MAGNES ACTIVE DENK SACHETS B/30">MAGNES ACTIVE DENK
                                                        SACHETS B/30</option>
                                                    <option value="MALADOX(FANSIDAR) CES PLQ">MALADOX(FANSIDAR) CES PLQ
                                                    </option>
                                                    <option value="MALAXIN-PLUS CAPS">MALAXIN-PLUS CAPS</option>
                                                    <option value="MANNITOL 100 ML INJ">MANNITOL 100 ML INJ</option>
                                                    <option value="MAXIDEX COLLYRE 0.1%">MAXIDEX COLLYRE 0.1%</option>
                                                    <option value="MAXIDROL COLLYRE 3 ML (EUROP)">MAXIDROL COLLYRE 3 ML
                                                        (EUROP)</option>
                                                    <option value="MAYTAB TAB 20 CES">MAYTAB TAB 20 CES</option>
                                                    <option value="MEBENDAZOLE CES">MEBENDAZOLE CES</option>
                                                    <option value="MEBENDAZOLE 100 MG 30 ML SP">MEBENDAZOLE 100 MG 30 ML SP
                                                    </option>
                                                    <option value="MEDIK -55 CES">MEDIK -55 CES</option>
                                                    <option value="MEDILYT">MEDILYT</option>
                                                    <option value="MEFANTHER FORT CES">MEFANTHER FORT CES</option>
                                                    <option value="MEFANTHER SIROP 60ML">MEFANTHER SIROP 60ML</option>
                                                    <option value="MEFTAL-FORTE CES">MEFTAL-FORTE CES</option>
                                                    <option value="MENTRIL 100 ML SOL">MENTRIL 100 ML SOL</option>
                                                    <option value="MEOFLOX 60ML SIROP">MEOFLOX 60ML SIROP</option>
                                                    <option value="MEOFLOX B/10 CES">MEOFLOX B/10 CES</option>
                                                    <option value="MERCUROCROME 60 ML">MERCUROCROME 60 ML</option>
                                                    <option value="METAZOL 250MG CES">METAZOL 250MG CES</option>
                                                    <option value="METHERGINE INJ">METHERGINE INJ</option>
                                                    <option value="METHERINE GOUTTES 10 ML(BIS)">METHERINE GOUTTES 10
                                                        ML(BIS)</option>
                                                    <option value="METNOR 200 MG CES">METNOR 200 MG CES</option>
                                                    <option value="METNOR 400 MG CES">METNOR 400 MG CES</option>
                                                    <option value="METNOR SP 30 ML">METNOR SP 30 ML</option>
                                                    <option value="METOCLOPRAMIDE INJ">METOCLOPRAMIDE INJ</option>
                                                    <option value="METROKIM 500MG CAPS">METROKIM 500MG CAPS</option>
                                                    <option value="METRONIDAZOL 500 MG 10 CES">METRONIDAZOL 500 MG 10 CES
                                                    </option>
                                                    <option value="METRONIDAZOLE 25*10CES">METRONIDAZOLE 25*10CES</option>
                                                    <option value="METRONIDAZOLE 125 MG SP">METRONIDAZOLE 125 MG SP</option>
                                                    <option value="METRONIDAZOLE IV PERFUSION">METRONIDAZOLE IV PERFUSION
                                                    </option>
                                                    <option value="MICROLAX AD TUBE B/4 (EUROP)">MICROLAX AD TUBE B/4
                                                        (EUROP)</option>
                                                    <option value="MICROLAX BB B/4 TUBE (EUROP)">MICROLAX BB B/4 TUBE
                                                        (EUROP)</option>
                                                    <option value="MIG GEL 125 ML SP">MIG GEL 125 ML SP</option>
                                                    <option value="MIG GEL 200ML SP">MIG GEL 200ML SP</option>
                                                    <option value="MIPOTEC 200MCG CES B/4">MIPOTEC 200MCG CES B/4</option>
                                                    <option value="MISTROVIX CES">MISTROVIX CES</option>
                                                    <option value="MOXYCLAV DUO 457 SP 100 ML">MOXYCLAV DUO 457 SP 100 ML
                                                    </option>
                                                    <option value="MOXYCLAV DUO 1000TAB">MOXYCLAV DUO 1000TAB</option>
                                                    <option value="MOXYCLAV DUO 562.5 CES">MOXYCLAV DUO 562.5 CES</option>
                                                    <option value="MUCOPRIN ADULT SP">MUCOPRIN ADULT SP</option>
                                                    <option value="MUCOPRIN PED SP">MUCOPRIN PED SP</option>
                                                    <option value="MUCORIL SP">MUCORIL SP</option>
                                                    <option value="MUCOSIF EXPECT 100ML SP.">MUCOSIF EXPECT 100ML SP.
                                                    </option>
                                                    <option value="MUCOSIF PED 100ML SP">MUCOSIF PED 100ML SP</option>
                                                    <option value="MULTIVITAMINE 100 ML SP">MULTIVITAMINE 100 ML SP</option>
                                                    <option value="MULTIVITAMINE CES">MULTIVITAMINE CES</option>
                                                    <option value="MULTIVITAMINE GOUTTE">MULTIVITAMINE GOUTTE</option>
                                                    <option value="MUSCLE PLUS">MUSCLE PLUS</option>
                                                    <option value="MY VITA ADULT EFF">MY VITA ADULT EFF</option>
                                                    <option value="MY VITA C +ZINC EFF">MY VITA C +ZINC EFF</option>
                                                    <option value="MY VITA KIDDS EFF CES">MY VITA KIDDS EFF CES</option>
                                                    <option value="MYCOZEMA CREME 30 GR">MYCOZEMA CREME 30 GR</option>
                                                    <option value="NAOMI CREAM 50 GR">NAOMI CREAM 50 GR</option>
                                                    <option value="NAOMI DIAPER ADULT 80-112 CM MEDIUM">NAOMI DIAPER ADULT
                                                        80-112 CM MEDIUM</option>
                                                    <option value="NAPRIL 10MG CES">NAPRIL 10MG CES</option>
                                                    <option value="NASCARO SP">NASCARO SP</option>
                                                    <option value="NECLAZ M 30 CES">NECLAZ M 30 CES</option>
                                                    <option value="NEOCIN 15 GR">NEOCIN 15 GR</option>
                                                    <option value="NEOGYNAX B/12 OVILE">NEOGYNAX B/12 OVILE</option>
                                                    <option value="NEOGYNAX B/6">NEOGYNAX B/6</option>
                                                    <option value="NEUROTON 500MG 50 ML SIROP">NEUROTON 500MG 50 ML SIROP
                                                    </option>
                                                    <option value="NEUROTONE CES">NEUROTONE CES</option>
                                                    <option value="NEWMEX SPAS CES">NEWMEX SPAS CES</option>
                                                    <option value="NEWMEX-500 CES">NEWMEX-500 CES</option>
                                                    <option value="NEWMEX-FORTE CES">NEWMEX-FORTE CES</option>
                                                    <option value="NOCIGEL SP 200 ML">NOCIGEL SP 200 ML</option>
                                                    <option value="NOOTROPYL CES (GENERIQUE)">NOOTROPYL CES (GENERIQUE)
                                                    </option>
                                                    <option value="NOOTRYMON 5ML 1GR INJ">NOOTRYMON 5ML 1GR INJ</option>
                                                    <option value="NORFLOXACIN 400MG CES">NORFLOXACIN 400MG CES</option>
                                                    <option value="NORMEGYL CES">NORMEGYL CES</option>
                                                    <option value="NORMEGYL DP CES">NORMEGYL DP CES</option>
                                                    <option value="NORMEGYL SP 30ML">NORMEGYL SP 30ML</option>
                                                    <option value="NORMEGYL SP 60ML">NORMEGYL SP 60ML</option>
                                                    <option value="NORMET 200MG CES 10 CES">NORMET 200MG CES 10 CES</option>
                                                    <option value="NORMET 400MG CES 10 CES">NORMET 400MG CES 10 CES</option>
                                                    <option value="NORMET 60ML">NORMET 60ML</option>
                                                    <option value="NORMET SP 30ML">NORMET SP 30ML</option>
                                                    <option value="NORMEZOL 30ML SP">NORMEZOL 30ML SP</option>
                                                    <option value="NORMEZOL (NORFLO+METRO ) CES">NORMEZOL (NORFLO+METRO )
                                                        CES</option>
                                                    <option value="NOZINAN 100MG CES (EUROP)">NOZINAN 100MG CES (EUROP)
                                                    </option>
                                                    <option value="NUTRILIN CES">NUTRILIN CES</option>
                                                    <option value="NUTRILINE SP 100 ML">NUTRILINE SP 100 ML</option>
                                                    <option value="NUTRISANG SP">NUTRISANG SP</option>
                                                    <option value="NYSTATINE VAGINAL">NYSTATINE VAGINAL</option>
                                                    <option value="NYSTATINE CES">NYSTATINE CES</option>
                                                    <option value="OFLOKIN OZ CES 10 CES">OFLOKIN OZ CES 10 CES</option>
                                                    <option value="OFLOQUE-OZ SP 60 ML">OFLOQUE-OZ SP 60 ML</option>
                                                    <option value="OFLOXACIN-OZ CES">OFLOXACIN-OZ CES</option>
                                                    <option value="OMEPRAZOL -20 CAP">OMEPRAZOL -20 CAP</option>
                                                    <option value="OMEPRAZOL INJ">OMEPRAZOL INJ</option>
                                                    <option value="OMG-3 SOFT GELATING 2*10 CAPS">OMG-3 SOFT GELATING 2*10
                                                        CAPS</option>
                                                    <option value="ONDANSETROM 30ML SP">ONDANSETROM 30ML SP</option>
                                                    <option value="ONDANSETRON CES">ONDANSETRON CES</option>
                                                    <option value="ONDANSTERON INJ 4 MG/2ML">ONDANSTERON INJ 4 MG/2ML
                                                    </option>
                                                    <option value="OPTALIDON 5*10 CES">OPTALIDON 5*10 CES</option>
                                                    <option value="OPTICROM 2% COLLYRE (EUROP)">OPTICROM 2% COLLYRE (EUROP)
                                                    </option>
                                                    <option value="ORAL SACHETS">ORAL SACHETS</option>
                                                    <option value="ORANGINE 100 ML SP (AFRI PHARMA)">ORANGINE 100 ML SP
                                                        (AFRI PHARMA)</option>
                                                    <option value="ORGAMETRIL 5MG CES (EUROP)">ORGAMETRIL 5MG CES (EUROP)
                                                    </option>
                                                    <option value="ORNID 500MG OVULES B/10">ORNID 500MG OVULES B/10</option>
                                                    <option value="ORNID INJ 100 ML">ORNID INJ 100 ML</option>
                                                    <option value="ORNID OX CES">ORNID OX CES</option>
                                                    <option value="ORNID OX INJ 100 ML">ORNID OX INJ 100 ML</option>
                                                    <option value="ORNID OX SP 60ML">ORNID OX SP 60ML</option>
                                                    <option value="OROKEN ENF 40MG /5ML FL/40ML (EUROP)">OROKEN ENF 40MG
                                                        /5ML FL/40ML (EUROP)</option>
                                                    <option value="OTOBIOTIC 5 ML">OTOBIOTIC 5 ML</option>
                                                    <option value="OTRIVIN 1% GOUTTE NASAL (EUROP)">OTRIVIN 1% GOUTTE NASAL
                                                        (EUROP)</option>
                                                    <option value="OUATE 100 GR">OUATE 100 GR</option>
                                                    <option value="OUATE 250 GR">OUATE 250 GR</option>
                                                    <option value="OUATE 500GR">OUATE 500GR</option>
                                                    <option value="OVOGYL (METRONIDAZOLE) 500MG OV">OVOGYL (METRONIDAZOLE)
                                                        500MG OV</option>
                                                    <option value="OXARIN 60MG 0.6 ML INJECTION">OXARIN 60MG 0.6 ML
                                                        INJECTION</option>
                                                    <option value="OXARIN 80 MG 0.8 ML INJECTION">OXARIN 80 MG 0.8 ML
                                                        INJECTION</option>
                                                    <option value="OXIN 200MG CAPS">OXIN 200MG CAPS</option>
                                                    <option value="OXYTOCIN 2ML INJ">OXYTOCIN 2ML INJ</option>
                                                    <option value="PA 12 CES">PA 12 CES</option>
                                                    <option value="PAIN RILIF CES 10 CES">PAIN RILIF CES 10 CES</option>
                                                    <option value="PANADOL 500 MG 12 CES EFF">PANADOL 500 MG 12 CES EFF
                                                    </option>
                                                    <option value="PANADOL ADVANCE 500 MG CES (EUROP)">PANADOL ADVANCE 500
                                                        MG CES (EUROP)</option>
                                                    <option value="PANAL CES">PANAL CES</option>
                                                    <option value="PANTEC 20 MG B/30 CES">PANTEC 20 MG B/30 CES</option>
                                                    <option value="PANTEC 40 MG INJ">PANTEC 40 MG INJ</option>
                                                    <option value="PANTEC CES 40 MG">PANTEC CES 40 MG</option>
                                                    <option value="PAPAVERINE 40MG 2ML INJ">PAPAVERINE 40MG 2ML INJ</option>
                                                    <option value="PAPAVERINE CES">PAPAVERINE CES</option>
                                                    <option value="PARA-C SP 100ML (PARA + VIT C)">PARA-C SP 100ML (PARA +
                                                        VIT C)</option>
                                                    <option value="PARACETAMOL 100 ML SP">PARACETAMOL 100 ML SP</option>
                                                    <option value="PARACETAMOL CES">PARACETAMOL CES</option>
                                                    <option value="PARACETAMOL INFISION">PARACETAMOL INFISION</option>
                                                    <option value="PARACETAMOL INJ">PARACETAMOL INJ</option>
                                                    <option value="PARACO-DENK 1000/60 SUPPO B/10">PARACO-DENK 1000/60 SUPPO
                                                        B/10</option>
                                                    <option value="PARAFEN (PARA+IBU) CES">PARAFEN (PARA+IBU) CES</option>
                                                    <option value="PARAFEN 60 ML SP">PARAFEN 60 ML SP</option>
                                                    <option value="PARALGAN 500 MG EFF10 CES">PARALGAN 500 MG EFF10 CES
                                                    </option>
                                                    <option value="PARALGAN FORTE 1000MG EFF10 CES">PARALGAN FORTE 1000MG
                                                        EFF10 CES</option>
                                                    <option value="PASMEX 10 CES">PASMEX 10 CES</option>
                                                    <option value="PATOUX CES">PATOUX CES</option>
                                                    <option value="PDE ANTIGAL 35 GR">PDE ANTIGAL 35 GR</option>
                                                    <option value="PDE CAMPHREE 35GR">PDE CAMPHREE 35GR</option>
                                                    <option value="PDE CHRYSOPHANIQUE 35 GR">PDE CHRYSOPHANIQUE 35 GR
                                                    </option>
                                                    <option value="PDE ICTHIOLEE 35 GR">PDE ICTHIOLEE 35 GR</option>
                                                    <option value="PDE OXID DE ZINC 35 GR">PDE OXID DE ZINC 35 GR</option>
                                                    <option value="PDE TETRA OPHT">PDE TETRA OPHT</option>
                                                    <option value="PEDIFEN 100MG/5ML SP 100ML (EUROP)">PEDIFEN 100MG/5ML SP
                                                        100ML (EUROP)</option>
                                                    <option value="PEDIFEN AD 400MG CES (EUROP)">PEDIFEN AD 400MG CES
                                                        (EUROP)</option>
                                                    <option value="PENICILLINE 2.4 MEGA">PENICILLINE 2.4 MEGA</option>
                                                    <option value="PENICILLINE 4 MEGA(PROCAINE)">PENICILLINE 4
                                                        MEGA(PROCAINE)</option>
                                                    <option value="PENICILLINE 5 MEGA">PENICILLINE 5 MEGA</option>
                                                    <option value="PENICILLINE V 125 60ML SP">PENICILLINE V 125 60ML SP
                                                    </option>
                                                    <option value="PENICILLINE- V 250MG CES">PENICILLINE- V 250MG CES
                                                    </option>
                                                    <option value="PERDOLAN BB 100MG SUPPO (EUROP)">PERDOLAN BB 100MG SUPPO
                                                        (EUROP)</option>
                                                    <option value="PERMANGANATE POTASIUM VRAC">PERMANGANATE POTASIUM VRAC
                                                    </option>
                                                    <option value="PHENOBARBITAL CES">PHENOBARBITAL CES</option>
                                                    <option value="PHONE ITEL">PHONE ITEL</option>
                                                    <option value="PHOSPHALUGEL SACHET (EUROP)">PHOSPHALUGEL SACHET (EUROP)
                                                    </option>
                                                    <option value="PILULE-S">PILULE-S</option>
                                                    <option value="PINAX SP 60 ML">PINAX SP 60 ML</option>
                                                    <option value="PINAX TAB 1*9 CES">PINAX TAB 1*9 CES</option>
                                                    <option value="PIRATOX CES">PIRATOX CES</option>
                                                    <option value="POCHE DE SANG 250ML">POCHE DE SANG 250ML</option>
                                                    <option value="POCHE DE SANG 450ML">POCHE DE SANG 450ML</option>
                                                    <option value="POIRE NO 1">POIRE NO 1</option>
                                                    <option value="POIRE NO 2">POIRE NO 2</option>
                                                    <option value="POIRE NO 6">POIRE NO 6</option>
                                                    <option value="POIRE NO.4">POIRE NO.4</option>
                                                    <option value="POIRE NO8">POIRE NO8</option>
                                                    <option value="POLYDEXACINE COLLYRE.">POLYDEXACINE COLLYRE.</option>
                                                    <option value="POLYGEL CES SHALINA">POLYGEL CES SHALINA</option>
                                                    <option value="POLYGEL SP 200 ML">POLYGEL SP 200 ML</option>
                                                    <option value="POLYGEL SP MANGO 12OML">POLYGEL SP MANGO 12OML</option>
                                                    <option value="POLYGYNAX 12 OV (EUROP)">POLYGYNAX 12 OV (EUROP)</option>
                                                    <option value="POP NO-5 POWDER">POP NO-5 POWDER</option>
                                                    <option value="POUDRE MONGANGA BEBE 300 GM">POUDRE MONGANGA BEBE 300 GM
                                                    </option>
                                                    <option value="POWER PLUS CES 10 CES">POWER PLUS CES 10 CES</option>
                                                    <option value="PRAZOL CES">PRAZOL CES</option>
                                                    <option value="PRAZOL KIT CES">PRAZOL KIT CES</option>
                                                    <option value="PRAZOLE D CAPSULES 10 CES">PRAZOLE D CAPSULES 10 CES
                                                    </option>
                                                    <option value="PREDNISOLON COLYYRE">PREDNISOLON COLYYRE</option>
                                                    <option value="PREDNISOLONE 5MG CES">PREDNISOLONE 5MG CES</option>
                                                    <option value="PREGNERVE 75 MG 3*10 CES">PREGNERVE 75 MG 3*10 CES
                                                    </option>
                                                    <option value="PREGNYL 5000 UI INJ (EUROP)">PREGNYL 5000 UI INJ (EUROP)
                                                    </option>
                                                    <option value="PREMI FLAM 60 ML">PREMI FLAM 60 ML</option>
                                                    <option value="PREMI SPAS GOUTTE">PREMI SPAS GOUTTE</option>
                                                    <option value="PREMICOLD GOUTTE 15 ML">PREMICOLD GOUTTE 15 ML</option>
                                                    <option value="PREMIMOL EFFERVESCENT 1000 MG 2*4 CES">PREMIMOL
                                                        EFFERVESCENT 1000 MG 2*4 CES</option>
                                                    <option value="PROCEDOL CES 5*4 EFF 10 CES">PROCEDOL CES 5*4 EFF 10 CES
                                                    </option>
                                                    <option value="PROCEF INJ">PROCEF INJ</option>
                                                    <option value="PROCYL INJ">PROCYL INJ</option>
                                                    <option value="PRODOLAN SUPPO BB">PRODOLAN SUPPO BB</option>
                                                    <option value="PRODOLAN SUPPO ENF">PRODOLAN SUPPO ENF</option>
                                                    <option value="PROFENID 100 MG CES (EUROP)">PROFENID 100 MG CES (EUROP)
                                                    </option>
                                                    <option value="PROFENID 100MG SUPPO (EUROP)">PROFENID 100MG SUPPO
                                                        (EUROP)</option>
                                                    <option value="PROFER-Z SP 200 ML">PROFER-Z SP 200 ML</option>
                                                    <option value="PROGESTERONE 2 ML INJ">PROGESTERONE 2 ML INJ</option>
                                                    <option value="PROMETHAZINE 25MG CES">PROMETHAZINE 25MG CES</option>
                                                    <option value="PROMETHAZINE 5 MG 100 ML SP">PROMETHAZINE 5 MG 100 ML SP
                                                    </option>
                                                    <option value="PROMETHAZINE EXPECTORANT 100ML">PROMETHAZINE EXPECTORANT
                                                        100ML</option>
                                                    <option value="PROMETHAZINE INJ">PROMETHAZINE INJ</option>
                                                    <option value="PROMILA SP 150 ML">PROMILA SP 150 ML</option>
                                                    <option value="PROTONIC 100ML SP">PROTONIC 100ML SP</option>
                                                    <option value="PROVIDON 120 ML SOL">PROVIDON 120 ML SOL</option>
                                                    <option value="PULMINE ENFANT">PULMINE ENFANT</option>
                                                    <option value="PULMINE BEBE">PULMINE BEBE</option>
                                                    <option value="PULMINE SP 100 ML">PULMINE SP 100 ML</option>
                                                    <option value="PYLORIMEX 7KIT">PYLORIMEX 7KIT</option>
                                                    <option value="QUININE GOUTTE 15ML">QUININE GOUTTE 15ML</option>
                                                    <option value="QUININE 100 ML SP">QUININE 100 ML SP</option>
                                                    <option value="QUININE 100MG SUPPO">QUININE 100MG SUPPO</option>
                                                    <option value="QUININE 250MG 10 CES (P.K.)">QUININE 250MG 10 CES (P.K.)
                                                    </option>
                                                    <option value="QUININE 300 MG 10 CES (P.K.)">QUININE 300 MG 10 CES
                                                        (P.K.)</option>
                                                    <option value="QUININE 500MG 10CES (P.K.)">QUININE 500MG 10CES (P.K.)
                                                    </option>
                                                    <option value="QUININE 500MG INJ">QUININE 500MG INJ</option>
                                                    <option value="QUININE GOUTTE 15 ML P K">QUININE GOUTTE 15 ML P K
                                                    </option>
                                                    <option value="RECTOSOL HC">RECTOSOL HC</option>
                                                    <option value="RECTOSOL SIMPLE">RECTOSOL SIMPLE</option>
                                                    <option value="RELAX 5GM">RELAX 5GM</option>
                                                    <option value="RELIF BAUM 100 GM">RELIF BAUM 100 GM</option>
                                                    <option value="RELIF CREME TUBE 50 GM">RELIF CREME TUBE 50 GM</option>
                                                    <option value="RELIF SPARY">RELIF SPARY</option>
                                                    <option value="RELIFF CES">RELIFF CES</option>
                                                    <option value="RHINATHIOL AD SP 125 ML (EUROP)">RHINATHIOL AD SP 125 ML
                                                        (EUROP)</option>
                                                    <option value="RHINATHIOL ENF (EUROP)">RHINATHIOL ENF (EUROP)</option>
                                                    <option value="RHUMALGAN SPRAY 70 ML">RHUMALGAN SPRAY 70 ML</option>
                                                    <option value="RHUMFIX 0.5% NASAL DROP (ENF)">RHUMFIX 0.5% NASAL DROP
                                                        (ENF)</option>
                                                    <option value="RHUMFIX 1% NASAL DROPS (ADULT)">RHUMFIX 1% NASAL DROPS
                                                        (ADULT)</option>
                                                    <option value="RHUMFIX BABY 5ML">RHUMFIX BABY 5ML</option>
                                                    <option value="RHUMINE GOUTTE 0.5%">RHUMINE GOUTTE 0.5%</option>
                                                    <option value="RHUMINE GOUTTE 10ML 1%">RHUMINE GOUTTE 10ML 1%</option>
                                                    <option value="RINOMICINE 10MG B/10 SACHT">RINOMICINE 10MG B/10 SACHT
                                                    </option>
                                                    <option value="ROSA FORCE 50 MG 4 CES">ROSA FORCE 50 MG 4 CES</option>
                                                    <option value="ROSACID SP 100 ML">ROSACID SP 100 ML</option>
                                                    <option value="ROSAMI CREAM 50GM">ROSAMI CREAM 50GM</option>
                                                    <option value="ROSBACT CREAM 15GM">ROSBACT CREAM 15GM</option>
                                                    <option value="ROSBACT CREAM 30GM">ROSBACT CREAM 30GM</option>
                                                    <option value="ROSUMEX 10 3*10 CES">ROSUMEX 10 3*10 CES</option>
                                                    <option value="ROSUMEX F 200 MG CES 3*10">ROSUMEX F 200 MG CES 3*10
                                                    </option>
                                                    <option value="RUFENAC CREAM 30GM">RUFENAC CREAM 30GM</option>
                                                    <option value="RUFENAC SPRAY 70ML">RUFENAC SPRAY 70ML</option>
                                                    <option value="S A T 1500 UT 10 AMP">S A T 1500 UT 10 AMP</option>
                                                    <option value="S- FORCE 2 CES">S- FORCE 2 CES</option>
                                                    <option value="S-FORCE 50 MG 4 CES">S-FORCE 50 MG 4 CES</option>
                                                    <option value="SAC A URINE 2 LIT">SAC A URINE 2 LIT</option>
                                                    <option value="SALBUTAMOL 0.25 MG 10 AMP">SALBUTAMOL 0.25 MG 10 AMP
                                                    </option>
                                                    <option value="SALBUTAMOL 2MG CES">SALBUTAMOL 2MG CES</option>
                                                    <option value="SALBUTAMOL SP">SALBUTAMOL SP</option>
                                                    <option value="SALBUTAMOL SPRAY">SALBUTAMOL SPRAY</option>
                                                    <option value="SALBUTAMOL SUPPO 1GM (GEN)">SALBUTAMOL SUPPO 1GM (GEN)
                                                    </option>
                                                    <option value="SAVON MONGANGA BEBE 90 GM">SAVON MONGANGA BEBE 90 GM
                                                    </option>
                                                    <option value="SCORVITEX-Z TAB B/10 CES">SCORVITEX-Z TAB B/10 CES
                                                    </option>
                                                    <option value="SECOURS CES 10 CES">SECOURS CES 10 CES</option>
                                                    <option value="SEDATOUX SP 100 ML">SEDATOUX SP 100 ML</option>
                                                    <option value="SEKROL ADULT 30MG 150ML SP">SEKROL ADULT 30MG 150ML SP
                                                    </option>
                                                    <option value="SEKROL PED 0.15MG 100ML SP">SEKROL PED 0.15MG 100ML SP
                                                    </option>
                                                    <option value="SERC 8MG 90CES">SERC 8MG 90CES</option>
                                                    <option value="SERING 60 ML">SERING 60 ML</option>
                                                    <option value="SERINGUE 10ML">SERINGUE 10ML</option>
                                                    <option value="SERINGUE 20ML">SERINGUE 20ML</option>
                                                    <option value="SERINGUE 2CC">SERINGUE 2CC</option>
                                                    <option value="SERINGUE 5 CC">SERINGUE 5 CC</option>
                                                    <option value="SERINGUE A INSULINE -100 UNITE">SERINGUE A INSULINE -100
                                                        UNITE</option>
                                                    <option value="SERUM GLUCOSE 10% 500 ML">SERUM GLUCOSE 10% 500 ML
                                                    </option>
                                                    <option value="SERUM GLUCOSE 5% 1 LIT">SERUM GLUCOSE 5% 1 LIT</option>
                                                    <option value="SERUM GLUCOSE 5% 500 ML">SERUM GLUCOSE 5% 500 ML</option>
                                                    <option value="SERUM MIXTE 1 LIT">SERUM MIXTE 1 LIT</option>
                                                    <option value="SERUM MIXTE 500 ML">SERUM MIXTE 500 ML</option>
                                                    <option value="SERUM PHYSIO 500 ML">SERUM PHYSIO 500 ML</option>
                                                    <option value="SERUM RINGER LACTATE 500 ML">SERUM RINGER LACTATE 500 ML
                                                    </option>
                                                    <option value="SHALBACTAM TZ IV 1.125 G">SHALBACTAM TZ IV 1.125 G
                                                    </option>
                                                    <option value="SHALCIP TZ CES 10 CES">SHALCIP TZ CES 10 CES</option>
                                                    <option value="SHALDEX COLLYRE 10 ML">SHALDEX COLLYRE 10 ML</option>
                                                    <option value="SHALFLOX(NORFLOXACIN) CES">SHALFLOX(NORFLOXACIN) CES
                                                    </option>
                                                    <option value="SHALPLAST [BAND AID]">SHALPLAST [BAND AID]</option>
                                                    <option value="SHALTOUX 125ML SP">SHALTOUX 125ML SP</option>
                                                    <option value="SHALTOUX ORANGES LOZENGES">SHALTOUX ORANGES LOZENGES
                                                    </option>
                                                    <option value="SHALTOUX RAPID 100 ML SP">SHALTOUX RAPID 100 ML SP
                                                    </option>
                                                    <option value="SIFA COLD ENF 100ML SP">SIFA COLD ENF 100ML SP</option>
                                                    <option value="SINUTAB FORTE 20 CES">SINUTAB FORTE 20 CES</option>
                                                    <option value="SOCOMOL 1 GM EFF">SOCOMOL 1 GM EFF</option>
                                                    <option value="SOCOMOL GEL 30 GM">SOCOMOL GEL 30 GM</option>
                                                    <option value="SOCOMOL INJECTION">SOCOMOL INJECTION</option>
                                                    <option value="SOCOMOL SPRAY 100 ML">SOCOMOL SPRAY 100 ML</option>
                                                    <option value="SOCOMOL SUSPENSION 100 ML">SOCOMOL SUSPENSION 100 ML
                                                    </option>
                                                    <option value="SOCOMOL TAB 10 CES">SOCOMOL TAB 10 CES</option>
                                                    <option value="SOLUT-PHYSIO GTTE NASALE">SOLUT-PHYSIO GTTE NASALE
                                                    </option>
                                                    <option value="SOLUTION DAKIN">SOLUTION DAKIN</option>
                                                    <option value="SOND VESICAL (2 VOISE )NO-10">SOND VESICAL (2 VOISE
                                                        )NO-10</option>
                                                    <option value="SOND VESICAL (2 VOISE) NO 8">SOND VESICAL (2 VOISE) NO 8
                                                    </option>
                                                    <option value="SONDE NASO GASTRIC N0 14">SONDE NASO GASTRIC N0 14
                                                    </option>
                                                    <option value="SONDE NASO GASTRIL NO 18">SONDE NASO GASTRIL NO 18
                                                    </option>
                                                    <option value="SONDE VESICAL (2 VOIES ) G 14">SONDE VESICAL (2 VOIES ) G
                                                        14</option>
                                                    <option value="SONDE VESICAL (2 VOIES) G16">SONDE VESical (2 VOIES) G16
                                                    </option>
                                                    <option value="SONDE VESICAL (2 VOIES) G18">SONDE VESICAL (2 VOIES) G18
                                                    </option>
                                                    <option value="SONDE VESICALE 2 VOIES N 20">SONDE VESICALE 2 VOIES N 20
                                                    </option>
                                                    <option value="SONDE VESICALE N12">SONDE VESICALE N12</option>
                                                    <option value="SPARADRAP 2.5 CM">SPARADRAP 2.5 CM</option>
                                                    <option value="SPARADRAP 5 CM">SPARADRAP 5 CM</option>
                                                    <option value="SPARADRAP 7.5CM">SPARADRAP 7.5CM</option>
                                                    <option value="SPARET DROPS SP 15ML">SPARET DROPS SP 15ML</option>
                                                    <option value="SPASFON SUPPO (EUROP)">SPASFON SUPPO (EUROP)</option>
                                                    <option value="SPASFON CES (EUROP)">SPASFON CES (EUROP)</option>
                                                    <option value="SPASFON INJ (EUROP)">SPASFON INJ (EUROP)</option>
                                                    <option value="SPASMASIF 30ML GOUTTE">SPASMASIF 30ML GOUTTE</option>
                                                    <option value="SPASTA 10 B/10 CES">SPASTA 10 B/10 CES</option>
                                                    <option value="SPASTA 20 B/10 CES">SPASTA 20 B/10 CES</option>
                                                    <option value="STEROVIT GUTTES">STEROVIT GUTTES</option>
                                                    <option value="STIMULEX CAPS">STIMULEX CAPS</option>
                                                    <option value="STIMULEX 100 ML SP">STIMULEX 100 ML SP</option>
                                                    <option value="SUDROX 500MG">SUDROX 500MG</option>
                                                    <option value="SUDROX SP 100ML">SUDROX SP 100ML</option>
                                                    <option value="SULFATE DE FER CES">SULFATE DE FER CES</option>
                                                    <option value="SUPER APETI PLUS SP 200 ML">SUPER APETI PLUS SP 200 ML
                                                    </option>
                                                    <option value="SUPER APETI(CYPRO) 120ML SP">SUPER APETI(CYPRO) 120ML SP
                                                    </option>
                                                    <option value="SUPER APETI(CYPRO)4 MG CES">SUPER APETI(CYPRO)4 MG CES
                                                    </option>
                                                    <option value="SUPER APPETIT PLUS CES">SUPER APPETIT PLUS CES</option>
                                                    <option value="TANZOL 10ML SP">TANZOL 10ML SP</option>
                                                    <option value="TANZOL CES">TANZOL CES</option>
                                                    <option value="TAZEX 1125 MG INJ">TAZEX 1125 MG INJ</option>
                                                    <option value="TEGRETOL 200 MG CES (EUROP)">TEGRETOL 200 MG CES (EUROP)
                                                    </option>
                                                    <option value="TENORMIN 100 MG CES (EUROP)">TENORMIN 100 MG CES (EUROP)
                                                    </option>
                                                    <option value="TENSIONMETRE AVEC STETOSCOP">TENSIONMETRE AVEC STETOSCOP
                                                    </option>
                                                    <option value="TENSIONMETRE DIGITAL G.F">TENSIONMETRE DIGITAL G.F
                                                    </option>
                                                    <option value="TERPIN CODEIN CES">TERPIN CODEIN CES</option>
                                                    <option value="TERPON 180 ML SP (AD) (EUROP)">TERPON 180 ML SP (AD)
                                                        (EUROP)</option>
                                                    <option value="TERPONE 180 ML SP (ENF) (EUROP)">TERPONE 180 ML SP (ENF)
                                                        (EUROP)</option>
                                                    <option value="TERRA-CORTRIL PDE OPHT 3.5 GR (EUROP)">TERRA-CORTRIL PDE
                                                        OPHT 3.5 GR (EUROP)</option>
                                                    <option value="TEST DE GROSSESSE">TEST DE GROSSESSE</option>
                                                    <option value="TESTOSTERONE INJ">TESTOSTERONE INJ</option>
                                                    <option value="TETRA 250 MG CAPS">TETRA 250 MG CAPS</option>
                                                    <option value="THERMOMETRE DIGITAL">THERMOMETRE DIGITAL</option>
                                                    <option value="TIMOLOL GENERIC">TIMOLOL GENERIC</option>
                                                    <option value="TIMOLOL..0.5% COLLYRE (EUROP)">TIMOLOL..0.5% COLLYRE
                                                        (EUROP)</option>
                                                    <option value="TIMOPTOL 0.5% COLLYRE 5 ML (EUROP)">TIMOPTOL 0.5% COLLYRE
                                                        5 ML (EUROP)</option>
                                                    <option value="TINIZOL 500 CES">TINIZOL 500 CES</option>
                                                    <option value="TOT HEMA 10ML BUV AMP B/10 (EUROP)">TOT HEMA 10ML BUV AMP
                                                        B/10 (EUROP)</option>
                                                    <option value="TRACOL 500MG 3*10 CES">TRACOL 500MG 3*10 CES</option>
                                                    <option value="TRAMADOL CAPS">TRAMADOL CAPS</option>
                                                    <option value="TRAMADOL 2 ML INJ 5AMP">TRAMADOL 2 ML INJ 5AMP</option>
                                                    <option value="TRANXENE 10MG 30 GELULES">TRANXENE 10MG 30 GELULES
                                                    </option>
                                                    <option value="TRANXENE 5MG 30 GELULES">TRANXENE 5MG 30 GELULES</option>
                                                    <option value="TRI WELL SP 100 ML">TRI WELL SP 100 ML</option>
                                                    <option value="TRIBEM INJ">TRIBEM INJ</option>
                                                    <option value="TRIBEM CES 30 CES">TRIBEM CES 30 CES</option>
                                                    <option value="TRIBEX FORT 30DR">TRIBEX FORT 30DR</option>
                                                    <option value="TRIOKIT">TRIOKIT</option>
                                                    <option value="TRIOMEGA CES">TRIOMEGA CES</option>
                                                    <option value="TRITAZIDE 10/12.5MG CES (EUROP)">TRITAZIDE 10/12.5MG CES
                                                        (EUROP)</option>
                                                    <option value="TRITAZIDE 10/25MG CES (EUROP)">TRITAZIDE 10/25MG CES
                                                        (EUROP)</option>
                                                    <option value="TROBIKEN INJ + H20 DISTILLE 2MG">TROBIKEN INJ + H20
                                                        DISTILLE 2MG</option>
                                                    <option value="TROUSSE A TRANSFUSION">TROUSSE A TRANSFUSION</option>
                                                    <option value="TROUSSE A PERFUSION">TROUSSE A PERFUSION</option>
                                                    <option value="TSHEKA -100 MG 4 CES">TSHEKA -100 MG 4 CES</option>
                                                    <option value="TSHEKA- 100 MG ORAL JELLY">TSHEKA- 100 MG ORAL JELLY
                                                    </option>
                                                    <option value="TULLE GRAS">TULLE GRAS</option>
                                                    <option value="TUSSIPLEX (ANTI TOUX) 100 ML SP">TUSSIPLEX (ANTI TOUX)
                                                        100 ML SP</option>
                                                    <option value="UTROGESTAN 200MG CAPS (EUROP)">UTROGESTAN 200MG CAPS
                                                        (EUROP)</option>
                                                    <option value="VAGYNAX OV">VAGYNAX OV</option>
                                                    <option value="VASOCAR -T B/14 CES">VASOCAR -T B/14 CES</option>
                                                    <option value="VASOCAR 10 B/14 CES">VASOCAR 10 B/14 CES</option>
                                                    <option value="VASOCAR T 28 CES">VASOCAR T 28 CES</option>
                                                    <option value="VASOCAR- 5 CES">VASOCAR- 5 CES</option>
                                                    <option value="VASOCAR-TH3*10 CES">VASOCAR-TH3*10 CES</option>
                                                    <option value="VENTOLINE SPRAY (EUROP)">VENTOLINE SPRAY (EUROP)</option>
                                                    <option value="VERMOX 100 MG CES (EUROP)">VERMOX 100 MG CES (EUROP)
                                                    </option>
                                                    <option value="VERMOX 30 ML SP 100 MG (EUROP)">VERMOX 30 ML SP 100 MG
                                                        (EUROP)</option>
                                                    <option value="VIFEX SP 100ML">VIFEX SP 100ML</option>
                                                    <option value="VIKAS BOMBOM CES">VIKAS BOMBOM CES</option>
                                                    <option value="VILONG CES">VILONG CES</option>
                                                    <option value="VITALO PLUS 200ML SYRUP">VITALO PLUS 200ML SYRUP</option>
                                                    <option value="VITAMIN 4 SP 100 ML">VITAMIN 4 SP 100 ML</option>
                                                    <option value="VITAMIN- 4 CES 10*10">VITAMIN- 4 CES 10*10</option>
                                                    <option value="VITAMINE B COMPEX 100 ML SP">VITAMINE B COMPEX 100 ML SP
                                                    </option>
                                                    <option value="VITAMINE B COMPLEX 2 ML INJ">VITAMINE B COMPLEX 2 ML INJ
                                                    </option>
                                                    <option value="VITAMINE B COMPLEXE CES">VITAMINE B COMPLEXE CES</option>
                                                    <option value="VITAMINE B1 100MG CES">VITAMINE B1 100MG CES</option>
                                                    <option value="VITAMINE B1 INJ">VITAMINE B1 INJ</option>
                                                    <option value="VITAMINE B12 INJ">VITAMINE B12 INJ</option>
                                                    <option value="VITAMINE B6 CES">VITAMINE B6 CES</option>
                                                    <option value="VITAMINE B6 2 ML INJ">VITAMINE B6 2 ML INJ</option>
                                                    <option value="VITAMINE C 100 ML SP">VITAMINE C 100 ML SP</option>
                                                    <option value="VITAMINE C 5 ML INJ">VITAMINE C 5 ML INJ</option>
                                                    <option value="VITAMINE C 500MG CES">VITAMINE C 500MG CES</option>
                                                    <option value="VITAMINE K1 INJ">VITAMINE K1 INJ</option>
                                                    <option value="VITAMINE K3 INJ">VITAMINE K3 INJ</option>
                                                    <option value="VITRON-Z CES">VITRON-Z CES</option>
                                                    <option value="VITRON-Z SP">VITRON-Z SP</option>
                                                    <option value="VOLPA 10*10 CES">VOLPA 10*10 CES</option>
                                                    <option value="VOLPA PLUS CES 10*10">VOLPA PLUS CES 10*10</option>
                                                    <option value="VOLTARENE 100MG SUPPOS (EUROP)">VOLTARENE 100MG SUPPOS
                                                        (EUROP)</option>
                                                    <option value="VOLTARENE 50MG CES (EUROP)">VOLTARENE 50MG CES (EUROP)
                                                    </option>
                                                    <option value="WEL PLUS+ 10 CES">WEL PLUS+ 10 CES</option>
                                                    <option value="WORMEX 100 MG CES">WORMEX 100 MG CES</option>
                                                    <option value="WORMEX 30 ML SP">WORMEX 30 ML SP</option>
                                                    <option value="WORMEX 500 MG CES">WORMEX 500 MG CES</option>
                                                    <option value="X-1 CES">X-1 CES</option>
                                                    <option value="ZEBU+ 10 CES">ZEBU+ 10 CES</option>
                                                    <option value="ZEBU+ GEL 30 GM">ZEBU+ GEL 30 GM</option>
                                                    <option value="ZEBU+100ML SP">ZEBU+100ML SP</option>
                                                    <option value="ZENDEX CES">ZENDEX CES</option>
                                                    <option value="ZENKOF CES">ZENKOF CES</option>
                                                    <option value="ZENKOF SP">ZENKOF SP</option>
                                                    <option value="ZOPEX 5 MG 10 CES">ZOPEX 5 MG 10 CES</option>
                                                    <option value="ZOPEX F 10 CES">ZOPEX F 10 CES</option>
                                                    <option value="ZYLORIC 100MG CES (EUROP)">ZYLORIC 100MG CES (EUROP)
                                                    </option>
                                                </select>
                                                <div
                                                    class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                    <i class="fas fa-capsules"></i>
                                                </div>
                                                <div
                                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                                    <i class="fas fa-chevron-down"></i>
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
                                                                    <?php
                                                                    // Sécuriser la note
                                                                    $note = isset($fournisseur['note_qualite']) ? $fournisseur['note_qualite'] : 0;
                                                                    $note_float = is_numeric($note) ? (float) $note : 0.0;
                                                                    $note_formatted = number_format($note_float, 1);

                                                                    // Afficher les étoiles
                                                                    for ($i = 1; $i <= 5; $i++):
                                                                        $star_class = $i <= $note_float ? 'text-yellow-500' : 'text-gray-300';
                                                                        ?>
                                                                        <i
                                                                            class="fas fa-star <?php echo $star_class; ?> text-xs mr-0.5"></i>
                                                                    <?php endfor; ?>

                                                                    <!-- Afficher la note numérique -->
                                                                    <span
                                                                        class="ml-1 font-semibold text-gray-700"><?php echo $note_formatted; ?>/5</span>
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
                                                        #<?php echo str_pad((string) $produit['id'], 4, '0', STR_PAD_LEFT); ?>
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
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-yellow-100 to-yellow-50 text-yellow-800 border border-yellow-200">
                                                        <i class="fas fa-star text-yellow-500 mr-1 text-xs"></i>
                                                        <?php
                                                        // Vérifier et formater en toute sécurité
                                                        $note_value = isset($fournisseur['note_qualite']) ? (float) $fournisseur['note_qualite'] : 0.0;
                                                        echo number_format($note_value, 1);
                                                        ?>/5
                                                    </div>
                                                <?php elseif ($note >= 2.5): ?>
                                                    <div
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-yellow-100 to-yellow-50 text-yellow-800 border border-yellow-200">
                                                        <i class="fas fa-star text-yellow-500 mr-1 text-xs"></i>
                                                        <?php echo number_format((float) $note, 1); ?>/5
                                                    </div>
                                                <?php else: ?>
                                                    <div
                                                        class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-gradient-to-r from-red-100 to-red-50 text-red-800 border border-red-200">
                                                        <i class="fas fa-star text-red-500 mr-1 text-xs"></i>
                                                        <?php echo number_format((float) $note, 1); ?>/5
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
                                                    <!-- Bouton Modifier -->
                                                    <button
                                                        onclick="modifierFournisseur(<?php echo $fournisseur['id']; ?>, '<?php echo htmlspecialchars($fournisseur['nom_societe']); ?>')"
                                                        class="group relative px-3 py-1.5 bg-gradient-to-br from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white rounded-lg border border-blue-600 shadow-sm hover:shadow transition-all duration-200 text-xs font-medium overflow-hidden">
                                                        <div
                                                            class="absolute inset-0 bg-white opacity-0 group-hover:opacity-10 transition-opacity duration-200">
                                                        </div>
                                                        <i
                                                            class="fas fa-edit mr-1 group-hover:rotate-12 transition-transform duration-200"></i>

                                                    </button>

                                                    <!-- Bouton Noter -->
                                                    <button
                                                        onclick="noterFournisseur(<?php echo $fournisseur['id']; ?>, '<?php echo htmlspecialchars($fournisseur['nom_societe']); ?>')"
                                                        class="group relative px-3 py-1.5 bg-gradient-to-br from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700 text-white rounded-lg border border-yellow-600 shadow-sm hover:shadow transition-all duration-200 text-xs font-medium overflow-hidden">
                                                        <div
                                                            class="absolute inset-0 bg-white opacity-0 group-hover:opacity-10 transition-opacity duration-200">
                                                        </div>
                                                        <i
                                                            class="fas fa-star mr-1 group-hover:scale-125 transition-transform duration-200"></i>

                                                    </button>

                                                    <!-- Bouton Voir -->
                                                    <button onclick="voirDetailsFournisseur(<?php echo $fournisseur['id']; ?>)"
                                                        class="group relative px-3 py-1.5 bg-gradient-to-br from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white rounded-lg border border-gray-600 shadow-sm hover:shadow transition-all duration-200 text-xs font-medium overflow-hidden">
                                                        <div
                                                            class="absolute inset-0 bg-white opacity-0 group-hover:opacity-10 transition-opacity duration-200">
                                                        </div>
                                                        <i
                                                            class="fas fa-eye mr-1 group-hover:scale-125 transition-transform duration-200"></i>

                                                    </button>
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
                                                <select id="nom" name="nom" required
                                                    value="<?php echo htmlspecialchars($produit['nom']); ?>"
                                                    class="w-full px-4 py-3 pl-11 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 shadow-sm hover:shadow appearance-none bg-white">
                                                    <option value="" disabled selected>Sélectionnez un produit</option>
                                                    <option value="A- DEM POUDRE 100GM">A- DEM POUDRE 100GM</option>
                                                    <option value="A-DEM CREAM 30GM">A-DEM CREAM 30GM</option>
                                                    <option value="A-DEM CREME 15 GM">A-DEM CREME 15 GM</option>
                                                    <option value="A-DEM FEMINA">A-DEM FEMINA</option>
                                                    <option value="A-DEM SAVON 125G">A-DEM SAVON 125G</option>
                                                    <option value="A-DEM SAVON 75 GM">A-DEM SAVON 75 GM</option>
                                                    <option value="ABBOCATH G20">ABBOCATH G20</option>
                                                    <option value="ABBOCATH G22">ABBOCATH G22</option>
                                                    <option value="ABBOCATH G24">ABBOCATH G24</option>
                                                    <option value="ACIDE FOLICE CES">ACIDE FOLICE CES</option>
                                                    <option value="ACTION CES MEDICO">ACTION CES MEDICO</option>
                                                    <option value="ADRENALINE INJ">ADRENALINE INJ</option>
                                                    <option value="ALBENDAZOL SP 10 ML">ALBENDAZOL SP 10 ML</option>
                                                    <option value="ALBENDAZOLE 400MG CES">ALBENDAZOLE 400MG CES</option>
                                                    <option value="ALCEF 1G INJ">ALCEF 1G INJ</option>
                                                    <option value="ALCOOL ANTISEPTIQUE SPRAY 100 ML">ALCOOL ANTISEPTIQUE
                                                        SPRAY 100 ML</option>
                                                    <option value="ALCOOL DENATURE 5 L">ALCOOL DENATURE 5 L</option>
                                                    <option value="ALCOOL DENATURE 60 ML">ALCOOL DENATURE 60 ML</option>
                                                    <option value="ALCOOL IODE 60 ML">ALCOOL IODE 60 ML</option>
                                                    <option value="ALDACTON 100MG CES 50 CES">ALDACTON 100MG CES 50 CES
                                                    </option>
                                                    <option value="ALDACTON 50MG CES (EUROP)">ALDACTON 50MG CES (EUROP)
                                                    </option>
                                                    <option value="ALDACTONE 25 MG 50 CES">ALDACTONE 25 MG 50 CES</option>
                                                    <option value="ALDOMET 250 MG EUR">ALDOMET 250 MG EUR</option>
                                                    <option value="ALDOMET 250MG">ALDOMET 250MG</option>
                                                    <option value="ALEZE CES 1 CES">ALEZE CES 1 CES</option>
                                                    <option value="ALEZE M3">ALEZE M3</option>
                                                    <option value="ALEZZ CES 28 CES">ALEZZ CES 28 CES</option>
                                                    <option value="ALGESAL BAUME SIMPLE 40GM (EUROP)">ALGESAL BAUME SIMPLE
                                                        40GM (EUROP)</option>
                                                    <option value="ALGESAL SURACTIVE PDE 40 GR (EUROP)">ALGESAL SURACTIVE
                                                        PDE 40 GR (EUROP)</option>
                                                    <option value="ALIVIO TABLETS B/10">ALIVIO TABLETS B/10</option>
                                                    <option value="ALLERGINE CES">ALLERGINE CES</option>
                                                    <option value="ALUGEL SP">ALUGEL SP</option>
                                                    <option value="ALVITYL CES EFF B/10">ALVITYL CES EFF B/10</option>
                                                    <option value="ALVITYL PLUS CAPS (EUROP)">ALVITYL PLUS CAPS (EUROP)
                                                    </option>
                                                    <option value="ALVYTIL 150 ML SP (EUROP)">ALVYTIL 150 ML SP (EUROP)
                                                    </option>
                                                    <option value="AMBROXE SP 15MG ENF">AMBROXE SP 15MG ENF</option>
                                                    <option value="AMBROXE SP 30 MG ADULT">AMBROXE SP 30 MG ADULT</option>
                                                    <option value="AMBROXOL 15 MG 100 ML ENF LOCAL">AMBROXOL 15 MG 100 ML
                                                        ENF LOCAL</option>
                                                    <option value="AMBROXOL 30 MG SIROP ADULLT LOCAL">AMBROXOL 30 MG SIROP
                                                        ADULLT LOCAL</option>
                                                    <option value="AMICEF INJ">AMICEF INJ</option>
                                                    <option value="AMIDOL 25*10 CES">AMIDOL 25*10 CES</option>
                                                    <option value="AMIFER SP ADULT">AMIFER SP ADULT</option>
                                                    <option value="AMIFER SP JUNIOR 150 ML">AMIFER SP JUNIOR 150 ML</option>
                                                    <option value="AMIGYN 500 MG CES">AMIGYN 500 MG CES</option>
                                                    <option value="AMINOPHYLLINE 10 ML INJ">AMINOPHYLLINE 10 ML INJ</option>
                                                    <option value="AMINOPHYLLINE CES">AMINOPHYLLINE CES</option>
                                                    <option value="AMLO DENK 10MG B/50">AMLO DENK 10MG B/50</option>
                                                    <option value="AMLO DENK 5 MG B/50">AMLO DENK 5 MG B/50</option>
                                                    <option value="AMLOR 10MG CES (EUROP)">AMLOR 10MG CES (EUROP)</option>
                                                    <option value="AMLOX 10 MG CES">AMLOX 10 MG CES</option>
                                                    <option value="AMLOX 5 MG 30 CES">AMLOX 5 MG 30 CES</option>
                                                    <option value="AMOXICILINE 1 GR">AMOXICILINE 1 GR</option>
                                                    <option value="AMOXICILLINE 125 SP">AMOXICILLINE 125 SP</option>
                                                    <option value="AMOXICILLINE 250 SP">AMOXICILLINE 250 SP</option>
                                                    <option value="AMOXYCILLINE 250MG CAPS">AMOXYCILLINE 250MG CAPS</option>
                                                    <option value="AMOXYCILLINE 500MG CAPS">AMOXYCILLINE 500MG CAPS</option>
                                                    <option value="AMPICILLINE 1GR">AMPICILLINE 1GR</option>
                                                    <option value="AMYCEF 1GR INJ B/1 AMP">AMYCEF 1GR INJ B/1 AMP</option>
                                                    <option value="ANAFLAM 60ML SP">ANAFLAM 60ML SP</option>
                                                    <option value="ANAFLAM CES">ANAFLAM CES</option>
                                                    <option value="ANSET SP 30 ML">ANSET SP 30 ML</option>
                                                    <option value="ANSET CES 4 CES">ANSET CES 4 CES</option>
                                                    <option value="ANSET INJ 1 AMP">ANSET INJ 1 AMP</option>
                                                    <option value="ANSET SUPPO">ANSET SUPPO</option>
                                                    <option value="ANTIBIOPUR 0.3% COLLYRE 5ML">ANTIBIOPUR 0.3% COLLYRE 5ML
                                                    </option>
                                                    <option value="ANTIDOUL CES P/10CES">ANTIDOUL CES P/10CES</option>
                                                    <option value="ANTIGRIPE SIROP 100 ML">ANTIGRIPE SIROP 100 ML</option>
                                                    <option value="ANTITOUSSIF 100 ML SP">ANTITOUSSIF 100 ML SP</option>
                                                    <option value="ANUSOL SUPPO (EUROP)">ANUSOL SUPPO (EUROP)</option>
                                                    <option value="APETAMIN SP 200 ML">APETAMIN SP 200 ML</option>
                                                    <option value="APHEROL CAP 3*10">APHEROL CAP 3*10</option>
                                                    <option value="APPETIVIT 200 SP">APPETIVIT 200 SP</option>
                                                    <option value="ARAUBACT INJ 100ML">ARAUBACT INJ 100ML</option>
                                                    <option value="ARAUBACT(10*1) CES">ARAUBACT(10*1) CES</option>
                                                    <option value="ARAUFIX 200 MG 10 CES">ARAUFIX 200 MG 10 CES</option>
                                                    <option value="ARAUFIX OX CES">ARAUFIX OX CES</option>
                                                    <option value="ARAUFLOX IV 100 ML">ARAUFLOX IV 100 ML</option>
                                                    <option value="ARGYROL 0.5% 15 ML">ARGYROL 0.5% 15 ML</option>
                                                    <option value="ARGYROL 1 % 15ML">ARGYROL 1 % 15ML</option>
                                                    <option value="ARODERME CREME 40 GR">ARODERME CREME 40 GR</option>
                                                    <option value="ARODERME POUDRE BABY">ARODERME POUDRE BABY</option>
                                                    <option value="ARODERME POUDRE MEDICAL 50GM">ARODERME POUDRE MEDICAL
                                                        50GM</option>
                                                    <option value="ARODERME POUDRE MEDICALE 100GM">ARODERME POUDRE MEDICALE
                                                        100GM</option>
                                                    <option value="AROGYL B/7 OVULE">AROGYL B/7 OVULE</option>
                                                    <option value="ARTE+LUME 20/120 B/24">ARTE+LUME 20/120 B/24</option>
                                                    <option value="ARTE-PED CES">ARTE-PED CES</option>
                                                    <option value="ARTE-PED 60 ML SP">ARTE-PED 60 ML SP</option>
                                                    <option value="ARTE-PED DT 20/120 MG B/12 CES">ARTE-PED DT 20/120 MG
                                                        B/12 CES</option>
                                                    <option value="ARTE-PED DT 20/120 MG B/6 CES">ARTE-PED DT 20/120 MG B/6
                                                        CES</option>
                                                    <option value="ARTE-PED DT 80/480 MG B/6 CES">ARTE-PED DT 80/480 MG B/6
                                                        CES</option>
                                                    <option value="ARTE-PLUS KIT PACK CAPS">ARTE-PLUS KIT PACK CAPS</option>
                                                    <option value="ARTEMETHER INJ">ARTEMETHER INJ</option>
                                                    <option value="ARTEQUICK CES">ARTEQUICK CES</option>
                                                    <option value="ASPIRINE 500MG CES">ASPIRINE 500MG CES</option>
                                                    <option value="ASPIRINE BAYER JUNIOR 100MG CES (EUROP)">ASPIRINE BAYER
                                                        JUNIOR 100MG CES (EUROP)</option>
                                                    <option value="ATEMAL 120 MG INJ">ATEMAL 120 MG INJ</option>
                                                    <option value="ATEMAL 180 MG INJ">ATEMAL 180 MG INJ</option>
                                                    <option value="ATEMAL 240 MG INJ">ATEMAL 240 MG INJ</option>
                                                    <option value="ATEMAL 30 MG INJ">ATEMAL 30 MG INJ</option>
                                                    <option value="ATEMAL 40/240 MG CES">ATEMAL 40/240 MG CES</option>
                                                    <option value="ATEMAL 60 MG INJ">ATEMAL 60 MG INJ</option>
                                                    <option value="ATEMAL 80/480 6 CES">ATEMAL 80/480 6 CES</option>
                                                    <option value="ATEMAL SUSPENSION 60 ML SP">ATEMAL SUSPENSION 60 ML SP
                                                    </option>
                                                    <option value="ATEMAL150 MG INJ">ATEMAL150 MG INJ</option>
                                                    <option value="ATENOLOL DENK 50 MG 10*10 CES">ATENOLOL DENK 50 MG 10*10
                                                        CES</option>
                                                    <option value="ATROPINE INJ">ATROPINE INJ</option>
                                                    <option value="AUGMENTIN BEBE 30ML PDRE SP">AUGMENTIN BEBE 30ML PDRE SP
                                                    </option>
                                                    <option value="AUGMENTIN ENFT 60 ML PDRE SP (EUROP)">AUGMENTIN ENFT 60
                                                        ML PDRE SP (EUROP)</option>
                                                    <option value="AUVAX 10 MG CES 20 CES">AUVAX 10 MG CES 20 CES</option>
                                                    <option value="AVAMYS 120DOSES 27.5MG (EUROP)">AVAMYS 120DOSES 27.5MG
                                                        (EUROP)</option>
                                                    <option value="AZIPRO 30 ML SP">AZIPRO 30 ML SP</option>
                                                    <option value="AZIPRO 500 MG CES">AZIPRO 500 MG CES</option>
                                                    <option value="BACTRIM 400 MG/80MG 20 CES (ROCHE) (EUROP)">BACTRIM 400
                                                        MG/80MG 20 CES (ROCHE) (EUROP)</option>
                                                    <option value="BACTRIM SUSP PED FL/100ML (EUROP)">BACTRIM SUSP PED
                                                        FL/100ML (EUROP)</option>
                                                    <option value="BANDE CAMBRIC 10CM">BANDE CAMBRIC 10CM</option>
                                                    <option value="BANDE CAMBRIC 5CM">BANDE CAMBRIC 5CM</option>
                                                    <option value="BANDE CAMBRIC 7CM">BANDE CAMBRIC 7CM</option>
                                                    <option value="BANDE ELASTIQUE 10CM">BANDE ELASTIQUE 10CM</option>
                                                    <option value="BANDE ELASTIQUE 15CM">BANDE ELASTIQUE 15CM</option>
                                                    <option value="BANDE ELASTIQUE 5CM">BANDE ELASTIQUE 5CM</option>
                                                    <option value="BANDE ELASTIQUE 7.5CM">BANDE ELASTIQUE 7.5CM</option>
                                                    <option value="BANDE PLATREE 10 CM">BANDE PLATREE 10 CM</option>
                                                    <option value="BANDE PLATREE 15 CM">BANDE PLATREE 15 CM</option>
                                                    <option value="BANDE PLATREE 20 CM">BANDE PLATREE 20 CM</option>
                                                    <option value="BANEOCIN 20 GR PDE (EUROP)">BANEOCIN 20 GR PDE (EUROP)
                                                    </option>
                                                    <option value="BAUM DE NERF (P M)">BAUM DE NERF (P M)</option>
                                                    <option value="BAUME DE NERF (G M)">BAUME DE NERF (G M)</option>
                                                    <option value="BAUME DE NERF SMALL 38 GM">BAUME DE NERF SMALL 38 GM
                                                    </option>
                                                    <option value="BENERVA 300 MG CES (EUROP)">BENERVA 300 MG CES (EUROP)
                                                    </option>
                                                    <option value="BENZOATE DE BENZYL">BENZOATE DE BENZYL</option>
                                                    <option value="BETASOL 15 GR CREAM">BETASOL 15 GR CREAM</option>
                                                    <option value="BETASOL 30 GR CREAM">BETASOL 30 GR CREAM</option>
                                                    <option value="BETASOL LOTION 30 ML">BETASOL LOTION 30 ML</option>
                                                    <option value="BETATOUX 100 ML SP (AFRI PHARMA)">BETATOUX 100 ML SP
                                                        (AFRI PHARMA)</option>
                                                    <option value="BICARBONATE DE SODIUM 100 GM">BICARBONATE DE SODIUM 100
                                                        GM</option>
                                                    <option value="BIEN MANGER 10*10 CES">BIEN MANGER 10*10 CES</option>
                                                    <option value="BIEN MANGER 100 ML SIROP">BIEN MANGER 100 ML SIROP
                                                    </option>
                                                    <option value="BIEN MANGER PLUS 10*10 CES">BIEN MANGER PLUS 10*10 CES
                                                    </option>
                                                    <option value="BIEN MANGER PLUS SP 200 ML">BIEN MANGER PLUS SP 200 ML
                                                    </option>
                                                    <option value="BIOFORCE SP200 ML">BIOFORCE SP200 ML</option>
                                                    <option value="BISTOURI 21">BISTOURI 21</option>
                                                    <option value="BLEU DE METHELINE 60 ML">BLEU DE METHELINE 60 ML</option>
                                                    <option value="BLOUSES USAGE UNIQUE">BLOUSES USAGE UNIQUE</option>
                                                    <option value="BRONCATHIOL SIROP ENF 125 ML">BRONCATHIOL SIROP ENF 125
                                                        ML</option>
                                                    <option value="BRONCATHIOL SUSP EXP 100 ML">BRONCATHIOL SUSP EXP 100 ML
                                                    </option>
                                                    <option value="BRONCHALENE ADULTE 150 ML SP (EUROP)">BRONCHALENE ADULTE
                                                        150 ML SP (EUROP)</option>
                                                    <option value="BRONCHALENE ENF 150 ML SP (EUROP)">BRONCHALENE ENF 150 ML
                                                        SP (EUROP)</option>
                                                    <option value="BUCOLAX (BISACODYL)5MG CES">BUCOLAX (BISACODYL)5MG CES
                                                    </option>
                                                    <option value="BUSCOPAN 1ML INJ">BUSCOPAN 1ML INJ</option>
                                                    <option value="BUSCOPAN CES">BUSCOPAN CES</option>
                                                    <option value="BUTHOL 100ML SIROP">BUTHOL 100ML SIROP</option>
                                                    <option value="C 4 SP">C 4 SP</option>
                                                    <option value="C-XONE 1.5 INJ">C-XONE 1.5 INJ</option>
                                                    <option value="C-XONE INJ (CEFRIAXONE) INJ 1 GM">C-XONE INJ (CEFRIAXONE)
                                                        INJ 1 GM</option>
                                                    <option value="C-XONE S 750 MG INJ">C-XONE S 750 MG INJ</option>
                                                    <option value="C4 CES">C4 CES</option>
                                                    <option value="CAL-D CES B/15">CAL-D CES B/15</option>
                                                    <option value="CALCI DENK CES">CALCI DENK CES</option>
                                                    <option value="CALCIUM GLUCONAT 10 ML INJ">CALCIUM GLUCONAT 10 ML INJ
                                                    </option>
                                                    <option value="CALCUL STOP SP">CALCUL STOP SP</option>
                                                    <option value="CANDEREL CES (EUROP)">CANDEREL CES (EUROP)</option>
                                                    <option value="CANDERM POWDER 50 GM">CANDERM POWDER 50 GM</option>
                                                    <option value="CANDERM PUDRE 100 GM">CANDERM PUDRE 100 GM</option>
                                                    <option value="CANDISTEN OVULE">CANDISTEN OVULE</option>
                                                    <option value="CANDISTEN CREME 30 GR">CANDISTEN CREME 30 GR</option>
                                                    <option value="CANDISTEN POUDRE">CANDISTEN POUDRE</option>
                                                    <option value="CAPTOPRIL 25MG CES">CAPTOPRIL 25MG CES</option>
                                                    <option value="CARBOKINA CES">CARBOKINA CES</option>
                                                    <option value="CAT GUTTE VICRYL NO 1">CAT GUTTE VICRYL NO 1</option>
                                                    <option value="CATGUT CHROMIC 0">CATGUT CHROMIC 0</option>
                                                    <option value="CATGUT CHROMIC 1">CATGUT CHROMIC 1</option>
                                                    <option value="CATGUT CHROMIC 1/0">CATGUT CHROMIC 1/0</option>
                                                    <option value="CATGUT CHROMIC 2">CATGUT CHROMIC 2</option>
                                                    <option value="CATGUT CHROMIC 2/0">CATGUT CHROMIC 2/0</option>
                                                    <option value="CATGUT MERSILENE 1">CATGUT MERSILENE 1</option>
                                                    <option value="CATGUT MERSILENE 2">CATGUT MERSILENE 2</option>
                                                    <option value="CATGUT MERSILENE 2/0">CATGUT MERSILENE 2/0</option>
                                                    <option value="CATGUT VICRYL 0">CATGUT VICRYL 0</option>
                                                    <option value="CATGUT VICRYL 2">CATGUT VICRYL 2</option>
                                                    <option value="CATGUT VICRYL 2/0">CATGUT VICRYL 2/0</option>
                                                    <option value="CEDOCARD 20 MG 100 CES">CEDOCARD 20 MG 100 CES</option>
                                                    <option value="CEFALAXIN 250 MG SP">CEFALAXIN 250 MG SP</option>
                                                    <option value="CEFALEXIN 125 MG SP">CEFALEXIN 125 MG SP</option>
                                                    <option value="CEFALEXINE CAP">CEFALEXINE CAP</option>
                                                    <option value="CEFAM CAPS">CEFAM CAPS</option>
                                                    <option value="CEFATAX INJ SHALINA 1GR">CEFATAX INJ SHALINA 1GR</option>
                                                    <option value="CEFAZONE 1 GR">CEFAZONE 1 GR</option>
                                                    <option value="CEFIXIME 100 SUSP">CEFIXIME 100 SUSP</option>
                                                    <option value="CEFIXIME 400 MG CES">CEFIXIME 400 MG CES</option>
                                                    <option value="CEFIXIME 200 CES">CEFIXIME 200 CES</option>
                                                    <option value="CEFOTAXIM INJ 1GR">CEFOTAXIM INJ 1GR</option>
                                                    <option value="CEFPODOX 200 MG CES">CEFPODOX 200 MG CES</option>
                                                    <option value="CEFPODOX(CEFPODIXINE) 100ML SP">CEFPODOX(CEFPODIXINE)
                                                        100ML SP</option>
                                                    <option value="CEFTRIAXONE 1GR">CEFTRIAXONE 1GR</option>
                                                    <option value="CEFTRIN PLUS 1 G (ALISONS)">CEFTRIN PLUS 1 G (ALISONS)
                                                    </option>
                                                    <option value="CELEBREX 100 MG 20 GELULES">CELEBREX 100 MG 20 GELULES
                                                    </option>
                                                    <option value="CELEBREX 200 MG 10 GELULES">CELEBREX 200 MG 10 GELULES
                                                    </option>
                                                    <option value="CELESTENE GOUTTES 30 ML(EUROP)">CELESTENE GOUTTES 30
                                                        ML(EUROP)</option>
                                                    <option value="CEMYCINE 500MG CES">CEMYCINE 500MG CES</option>
                                                    <option value="CEMYCINE(AZYTHROMYCINE)30ML">CEMYCINE(AZYTHROMYCINE)30ML
                                                    </option>
                                                    <option value="CEPIME 1000 MG INJ">CEPIME 1000 MG INJ</option>
                                                    <option value="CEPRA S 1 GM INJ">CEPRA S 1 GM INJ</option>
                                                    <option value="CEPRA S PLUS 1.5 INJ">CEPRA S PLUS 1.5 INJ</option>
                                                    <option value="CERULYSE SOL 5% AURIC 10 ML (EUROP)">CERULYSE SOL 5%
                                                        AURIC 10 ML (EUROP)</option>
                                                    <option value="CESADOX CES">CESADOX CES</option>
                                                    <option value="CESADOX SP">CESADOX SP</option>
                                                    <option value="CESAFLAM 1*10 CES">CESAFLAM 1*10 CES</option>
                                                    <option value="CESAKROL ADULT SP 30 MG">CESAKROL ADULT SP 30 MG</option>
                                                    <option value="CESAKROL PEDIATRIC 15MG SP">CESAKROL PEDIATRIC 15MG SP
                                                    </option>
                                                    <option value="CESATOUX 100 ML SP">CESATOUX 100 ML SP</option>
                                                    <option value="CETHER L DISPERSIBLE 12 CES">CETHER L DISPERSIBLE 12 CES
                                                    </option>
                                                    <option value="CETHER L DT 6 CES ENF">CETHER L DT 6 CES ENF</option>
                                                    <option value="CETHER-L CES AD">CETHER-L CES AD</option>
                                                    <option value="CETHER-L SP 60ML">CETHER-L SP 60ML</option>
                                                    <option value="CEZINC 20 MG 60 ML">CEZINC 20 MG 60 ML</option>
                                                    <option value="CEZINC 20 MG CES 10 TAB">CEZINC 20 MG CES 10 TAB</option>
                                                    <option value="CHEVAL FORCE 10 CES">CHEVAL FORCE 10 CES</option>
                                                    <option value="CHEWAL FORCE GEL 30 GM">CHEWAL FORCE GEL 30 GM</option>
                                                    <option value="CHLORA OTIQUE DROPS">CHLORA OTIQUE DROPS</option>
                                                    <option value="CHLORAMIN-T 500MG VRAC">CHLORAMIN-T 500MG VRAC</option>
                                                    <option value="CHLORAMPHENICOL PDE OPHTALMIQUE">CHLORAMPHENICOL PDE
                                                        OPHTALMIQUE</option>
                                                    <option value="CHLORAPHENICOL 1GR">CHLORAPHENICOL 1GR</option>
                                                    <option value="CHLORAPHENICOL COLLYRE (EYE DROPS)">CHLORAPHENICOL
                                                        COLLYRE (EYE DROPS)</option>
                                                    <option value="CHLORPHENIRAMINE 4MG CES">CHLORPHENIRAMINE 4MG CES
                                                    </option>
                                                    <option value="CHLORPROMAZINE 50 MG INJ">CHLORPROMAZINE 50 MG INJ
                                                    </option>
                                                    <option value="CHYMAR CES">CHYMAR CES</option>
                                                    <option value="CHYMAR INJ">CHYMAR INJ</option>
                                                    <option value="CIFIN 0.3% COLLYRE 10 ML">CIFIN 0.3% COLLYRE 10 ML
                                                    </option>
                                                    <option value="CIFIN 500 MG CES">CIFIN 500 MG CES</option>
                                                    <option value="CIFIN-DX COLLYRE">CIFIN-DX COLLYRE</option>
                                                    <option value="CIFIN-TZ CES">CIFIN-TZ CES</option>
                                                    <option value="CIMETIDINE 200 MG(TAGA) INJ">CIMETIDINE 200 MG(TAGA) INJ
                                                    </option>
                                                    <option value="CIMETIDINE 200MG CES">CIMETIDINE 200MG CES</option>
                                                    <option value="CIMETIDINE 400MG CES">CIMETIDINE 400MG CES</option>
                                                    <option value="CIPRO-TZ">CIPRO-TZ</option>
                                                    <option value="CIPROFLOXACINE 200 MG INJ">CIPROFLOXACINE 200 MG INJ
                                                    </option>
                                                    <option value="CIPROFLOXACINE 500MG CES">CIPROFLOXACINE 500MG CES
                                                    </option>
                                                    <option value="CIPRONAT 250MG CES (EUROP)">CIPRONAT 250MG CES (EUROP)
                                                    </option>
                                                    <option value="CIPRONAT 500MG CES (EUROP)">CIPRONAT 500MG CES (EUROP)
                                                    </option>
                                                    <option value="CIPRONAT 750 MG CES (EUROP)">CIPRONAT 750 MG CES (EUROP)
                                                    </option>
                                                    <option value="CITRIMEX -DT CES">CITRIMEX -DT CES</option>
                                                    <option value="CITRIMEX SP">CITRIMEX SP</option>
                                                    <option value="CLAMOXYL 125 MG 60 ML SP (EUROP)">CLAMOXYL 125 MG 60 ML
                                                        SP (EUROP)</option>
                                                    <option value="CLAMOXYL 250 MG 60 ML SP (EUROP)">CLAMOXYL 250 MG 60 ML
                                                        SP (EUROP)</option>
                                                    <option value="CLAMOXYL 500 MG CAPS (EUROP)">CLAMOXYL 500 MG CAPS
                                                        (EUROP)</option>
                                                    <option value="CLAMOXYL 500 MG SP 12 DOSES (EUROP)">CLAMOXYL 500 MG SP
                                                        12 DOSES (EUROP)</option>
                                                    <option value="CLAMPCORDON">CLAMPCORDON</option>
                                                    <option value="CLARINEZ 1% AD GOUTT">CLARINEZ 1% AD GOUTT</option>
                                                    <option value="CLARINEZ 0.5% (PED)">CLARINEZ 0.5% (PED)</option>
                                                    <option value="CLIFA 150 MG CAPS">CLIFA 150 MG CAPS</option>
                                                    <option value="CLIFA 150MG INJ">CLIFA 150MG INJ</option>
                                                    <option value="CLIFA 300 MG 2*10CES">CLIFA 300 MG 2*10CES</option>
                                                    <option value="CLIFA 300MG INJ">CLIFA 300MG INJ</option>
                                                    <option value="CLIFA 600 MG CAP">CLIFA 600 MG CAP</option>
                                                    <option value="CLIFA 600 MG INJ">CLIFA 600 MG INJ</option>
                                                    <option value="CLOMID BLEU (EUROP)">CLOMID BLEU (EUROP)</option>
                                                    <option value="CLOTRISAN POUDRE 50 GM">CLOTRISAN POUDRE 50 GM</option>
                                                    <option value="CLOXACILINA INJ">CLOXACILINA INJ</option>
                                                    <option value="CLOXACILLINE 500MG">CLOXACILLINE 500MG</option>
                                                    <option value="CLOZIDERM POWDER 50GR">CLOZIDERM POWDER 50GR</option>
                                                    <option value="CO ARINATE AD FDC 500MG (EUROP)">CO ARINATE AD FDC 500MG
                                                        (EUROP)</option>
                                                    <option value="CO ARINATE FDC JUNIOR 250MG (EUROP)">CO ARINATE FDC
                                                        JUNIOR 250MG (EUROP)</option>
                                                    <option value="CO ARTESIANE 60ML SP (EUROP)">CO ARTESIANE 60ML SP
                                                        (EUROP)</option>
                                                    <option value="CO TRIMOXAZOL 100 ML SP">CO TRIMOXAZOL 100 ML SP</option>
                                                    <option value="CO TRIMOXAZOLE 480MG CES">CO TRIMOXAZOLE 480MG CES
                                                    </option>
                                                    <option value="CO TRIMOXAZOLE FORTE CES">CO TRIMOXAZOLE FORTE CES
                                                    </option>
                                                    <option value="COARTEM 80/480 B/6 CES">COARTEM 80/480 B/6 CES</option>
                                                    <option value="COARTEM DISPERSIBLE 20/120 MG">COARTEM DISPERSIBLE 20/120
                                                        MG</option>
                                                    <option value="COLCHICINE HOUDE CES (EUROP)">COLCHICINE HOUDE CES
                                                        (EUROP)</option>
                                                    <option value="COLD CES">COLD CES</option>
                                                    <option value="COLD EXPRESS 0.05% NASAL SPRAY">COLD EXPRESS 0.05% NASAL
                                                        SPRAY</option>
                                                    <option value="COLD EXPRESS 0.1% NASAL SPRAY">COLD EXPRESS 0.1% NASAL
                                                        SPRAY</option>
                                                    <option value="COLD EXPRESS 25*4 CES">COLD EXPRESS 25*4 CES</option>
                                                    <option value="COLD-EXPRESS INHELAR">COLD-EXPRESS INHELAR</option>
                                                    <option value="COLDRIL SP 100 ML">COLDRIL SP 100 ML</option>
                                                    <option value="COLDTEC SP 100 ML">COLDTEC SP 100 ML</option>
                                                    <option value="COMBAVERS 15 ML SP">COMBAVERS 15 ML SP</option>
                                                    <option value="COMBAVERS CES">COMBAVERS CES</option>
                                                    <option value="COMBO PAIN CES">COMBO PAIN CES</option>
                                                    <option value="COMPRESSED STERILE 30*30 CM">COMPRESSED STERILE 30*30 CM
                                                    </option>
                                                    <option value="COMPRESSED STERILE 40*40 CM">COMPRESSED STERILE 40*40 CM
                                                    </option>
                                                    <option value="CONDOM CHIC 50 PES">CONDOM CHIC 50 PES</option>
                                                    <option value="CONDOM OK">CONDOM OK</option>
                                                    <option value="CONDOM OK 24*3">CONDOM OK 24*3</option>
                                                    <option value="CONFO VICKS LIO">CONFO VICKS LIO</option>
                                                    <option value="CORTI SPRAY">CORTI SPRAY</option>
                                                    <option value="CORTI-STENE 30 ML GOUTTE">CORTI-STENE 30 ML GOUTTE
                                                    </option>
                                                    <option value="CREAM MONGANGA BEBE">CREAM MONGANGA BEBE</option>
                                                    <option value="CYCLOMAX OVULES">CYCLOMAX OVULES</option>
                                                    <option value="CYPLA 4 CES">CYPLA 4 CES</option>
                                                    <option value="CYPLA 4 SP">CYPLA 4 SP</option>
                                                    <option value="CYPOMEX CES">CYPOMEX CES</option>
                                                    <option value="CYPOMEX SP">CYPOMEX SP</option>
                                                    <option value="DAFRACLAVE 400/57MG SP (EUROP)">DAFRACLAVE 400/57MG SP
                                                        (EUROP)</option>
                                                    <option value="DAVIGRA 100 MG CES B/2">DAVIGRA 100 MG CES B/2</option>
                                                    <option value="DAVIGRA 50MG CES">DAVIGRA 50MG CES</option>
                                                    <option value="DECONTRACTYL 50 DRA (EUROP)">DECONTRACTYL 50 DRA (EUROP)
                                                    </option>
                                                    <option value="DECONTRACTYL 80 GR PDE (EUROP)">DECONTRACTYL 80 GR PDE
                                                        (EUROP)</option>
                                                    <option value="DELTAVAGINE OV">DELTAVAGINE OV</option>
                                                    <option value="DEPAKINE 200MG CES (EUROP)">DEPAKINE 200MG CES (EUROP)
                                                    </option>
                                                    <option value="DEPAKINE 500MG CES (EUROP)">DEPAKINE 500MG CES (EUROP)
                                                    </option>
                                                    <option value="DEPAKINE GTTES 40ML">DEPAKINE GTTES 40ML</option>
                                                    <option value="DERMATOL POUDRE 50 GM">DERMATOL POUDRE 50 GM</option>
                                                    <option value="DERMOBACTER SOL 125 ML (EUROP)">DERMOBACTER SOL 125 ML
                                                        (EUROP)</option>
                                                    <option value="DETTOL 50 ML. LIQUIDE">DETTOL 50 ML. LIQUIDE</option>
                                                    <option value="DETTOL 125ML LIQUIDE">DETTOL 125ML LIQUIDE</option>
                                                    <option value="DETTOL 250ML LIQUIDE">DETTOL 250ML LIQUIDE</option>
                                                    <option value="DETTOL SOAP 175 GM">DETTOL SOAP 175 GM</option>
                                                    <option value="DEXACORT CES">DEXACORT CES</option>
                                                    <option value="DEXAMETHASON COLLYRE 5ML">DEXAMETHASON COLLYRE 5ML
                                                    </option>
                                                    <option value="DEXAMETHASONE 0.5MG CES">DEXAMETHASONE 0.5MG CES</option>
                                                    <option value="DEXAMETHAZONE 1ML INJ">DEXAMETHAZONE 1ML INJ</option>
                                                    <option value="DEXAROSE 0.50 MG CES 10*10">DEXAROSE 0.50 MG CES 10*10
                                                    </option>
                                                    <option value="DEXOL CAPS">DEXOL CAPS</option>
                                                    <option value="DEXOL SP 100 ML">DEXOL SP 100 ML</option>
                                                    <option value="DIAZEPAM CES">DIAZEPAM CES</option>
                                                    <option value="DIAZEPAM INJ">DIAZEPAM INJ</option>
                                                    <option value="DICLO DENK 100 MG SUPPO">DICLO DENK 100 MG SUPPO</option>
                                                    <option value="DICLO-DENK 100 MG 10 SUPPO">DICLO-DENK 100 MG 10 SUPPO
                                                    </option>
                                                    <option value="DICLOBIS COLLYRE 0.1% 5ML">DICLOBIS COLLYRE 0.1% 5ML
                                                    </option>
                                                    <option value="DICLOFENAC 100 MG CES B/10X10">DICLOFENAC 100 MG CES
                                                        B/10X10</option>
                                                    <option value="DICLOFENAC 100MG SUPPO">DICLOFENAC 100MG SUPPO</option>
                                                    <option value="DICLOFENAC 50 MG CES">DICLOFENAC 50 MG CES</option>
                                                    <option value="DICLOFENAC INJ 75 MG INJ">DICLOFENAC INJ 75 MG INJ
                                                    </option>
                                                    <option value="DICYNONE 250 MG CAPS (EUROP)">DICYNONE 250 MG CAPS
                                                        (EUROP)</option>
                                                    <option value="DICYNONE 250MG AMP (EUROP)">DICYNONE 250MG AMP (EUROP)
                                                    </option>
                                                    <option value="DIGEST SACHET B/1 15ML">DIGEST SACHET B/1 15ML</option>
                                                    <option value="DIGEST SP">DIGEST SP</option>
                                                    <option value="DIGOXINE AMP INJ B/6 AMP">DIGOXINE AMP INJ B/6 AMP
                                                    </option>
                                                    <option value="DIGOXINE NATIVELLE CES (EUROP)">DIGOXINE NATIVELLE CES
                                                        (EUROP)</option>
                                                    <option value="DIMAGEL SP">DIMAGEL SP</option>
                                                    <option value="DIPERAKINES CES">DIPERAKINES CES</option>
                                                    <option value="DIPROSON 15 GR CREAM">DIPROSON 15 GR CREAM</option>
                                                    <option value="DIPROSON LOTION 30 ML">DIPROSON LOTION 30 ML</option>
                                                    <option value="DIVA NORMAL">DIVA NORMAL</option>
                                                    <option value="DOLA CR CAPSULES">DOLA CR CAPSULES</option>
                                                    <option value="DOLAREN GEL">DOLAREN GEL</option>
                                                    <option value="DOLAREN PLUS GEL">DOLAREN PLUS GEL</option>
                                                    <option value="DOLAREN PLUS SPRAY">DOLAREN PLUS SPRAY</option>
                                                    <option value="DOLIPRANE 1GR EFF CES (EUROP)">DOLIPRANE 1GR EFF CES
                                                        (EUROP)</option>
                                                    <option value="DOLIPRANE 200MG SUPPO (EUROP)">DOLIPRANE 200MG SUPPO
                                                        (EUROP)</option>
                                                    <option value="DOLIPRANE 300MG SUPPO (EUROP)">DOLIPRANE 300MG SUPPO
                                                        (EUROP)</option>
                                                    <option value="DOLIPRANE 500MG EFF CES (EUROP)">DOLIPRANE 500MG EFF CES
                                                        (EUROP)</option>
                                                    <option value="DOLIPRANE SUPPO 150MG (EUROP)">DOLIPRANE SUPPO 150MG
                                                        (EUROP)</option>
                                                    <option value="DOXYCILLINE 100MG CAPS">DOXYCILLINE 100MG CAPS</option>
                                                    <option value="DOZAR INJ">DOZAR INJ</option>
                                                    <option value="DOZAR INJ 4.5 MG">DOZAR INJ 4.5 MG</option>
                                                    <option value="DR CALM BAUM 6GM">DR CALM BAUM 6GM</option>
                                                    <option value="DR CALM BAUM 9GM">DR CALM BAUM 9GM</option>
                                                    <option value="DR COLD CES">DR COLD CES</option>
                                                    <option value="DR MAISON">DR MAISON</option>
                                                    <option value="DR MUTU P/10 CES">DR MUTU P/10 CES</option>
                                                    <option value="DR ZINC 10 CES">DR ZINC 10 CES</option>
                                                    <option value="DR.COLD SP 125MG 100ML">DR.COLD SP 125MG 100ML</option>
                                                    <option value="DUPHALAC 200 ML SUSP (EUROP)">DUPHALAC 200 ML SUSP
                                                        (EUROP)</option>
                                                    <option value="DUPHALAC SACHET (EUROP)">DUPHALAC SACHET (EUROP)</option>
                                                    <option value="DUPHALGEL SP">DUPHALGEL SP</option>
                                                    <option value="DUPHASTON 10MG CES (EUROP)">DUPHASTON 10MG CES (EUROP)
                                                    </option>
                                                    <option value="DUSPATALIN 200 MG 30 CES">DUSPATALIN 200 MG 30 CES
                                                    </option>
                                                    <option value="EAU DIGESTIVE 100 ML SP">EAU DIGESTIVE 100 ML SP</option>
                                                    <option value="EAU DISTILLEE 10 ML">EAU DISTILLEE 10 ML</option>
                                                    <option value="EAU OXYGENE 10 VOL 60 ML(LOCAL)">EAU OXYGENE 10 VOL 60
                                                        ML(LOCAL)</option>
                                                    <option value="EAU OXYGENEE 10 VOL 120 ML (EUROP)">EAU OXYGENEE 10 VOL
                                                        120 ML (EUROP)</option>
                                                    <option value="EFFERALGAN (VIT C) CES EFF (EUROP)">EFFERALGAN (VIT C)
                                                        CES EFF (EUROP)</option>
                                                    <option value="EFFERALGAN 500MG CES EFF (EUROP)">EFFERALGAN 500MG CES
                                                        EFF (EUROP)</option>
                                                    <option value="EFFERALGAN CES EFF + VIT C EFF CES">EFFERALGAN CES EFF +
                                                        VIT C EFF CES</option>
                                                    <option value="EFFERALGAN CODEINE CES (EUROP)">EFFERALGAN CODEINE CES
                                                        (EUROP)</option>
                                                    <option value="EFFERALGAN PED 90ML SP (EUROP)">EFFERALGAN PED 90ML SP
                                                        (EUROP)</option>
                                                    <option value="EFFORTIL GOUTTE 30 ML (EUROP)">EFFORTIL GOUTTE 30 ML
                                                        (EUROP)</option>
                                                    <option value="EFICEF 200 MG CES">EFICEF 200 MG CES</option>
                                                    <option value="EFISHAL (VITE) 200 CAPS">EFISHAL (VITE) 200 CAPS</option>
                                                    <option value="ENA DENK 10 MG">ENA DENK 10 MG</option>
                                                    <option value="EPIDERM 15 GR CREAM">EPIDERM 15 GR CREAM</option>
                                                    <option value="EPIDERM 30 GR CREAM">EPIDERM 30 GR CREAM</option>
                                                    <option value="EPIDERM 30 ML LOTION">EPIDERM 30 ML LOTION</option>
                                                    <option value="EPIGRANIEN G21">EPIGRANIEN G21</option>
                                                    <option value="EPIGRANIEN G23">EPIGRANIEN G23</option>
                                                    <option value="ERGOGINE 50 ML GTTE">ERGOGINE 50 ML GTTE</option>
                                                    <option value="ERGOMETHRINE INJ">ERGOMETHRINE INJ</option>
                                                    <option value="ERYTHROMYCINE 125 ML SP">ERYTHROMYCINE 125 ML SP</option>
                                                    <option value="ERYTHROMYCINE 250 MG CES">ERYTHROMYCINE 250 MG CES
                                                    </option>
                                                    <option value="ERYTHROMYCINE 250 ML SP">ERYTHROMYCINE 250 ML SP</option>
                                                    <option value="ERYTHROMYCINE 500 MG CES">ERYTHROMYCINE 500 MG CES
                                                    </option>
                                                    <option value="ESIDREX 25MG CES (EUROP)">ESIDREX 25MG CES (EUROP)
                                                    </option>
                                                    <option value="ESMODOM(ESOMO+DOMPERIDON) CAPS">ESMODOM(ESOMO+DOMPERIDON)
                                                        CAPS</option>
                                                    <option value="ESOMEX 40 INJ">ESOMEX 40 INJ</option>
                                                    <option value="ESOMEX 40 MG CAP">ESOMEX 40 MG CAP</option>
                                                    <option value="ESTOWASH 100ML SOLUTION">ESTOWASH 100ML SOLUTION</option>
                                                    <option value="ESTRADIOL 2 ML INJ">ESTRADIOL 2 ML INJ</option>
                                                    <option value="EUCALYPTOL 100 ML SP (AFRI PHARMA)">EUCALYPTOL 100 ML SP
                                                        (AFRI PHARMA)</option>
                                                    <option value="EXOFEN 160 GR POUDRE (EUROP)">EXOFEN 160 GR POUDRE
                                                        (EUROP)</option>
                                                    <option value="EXOZOL CREAM PLUS 50GM">EXOZOL CREAM PLUS 50GM</option>
                                                    <option value="EXOZOL POWDER 75GM">EXOZOL POWDER 75GM</option>
                                                    <option value="EXTRAPOWER 10CES">EXTRAPOWER 10CES</option>
                                                    <option value="FALCIDOX CES">FALCIDOX CES</option>
                                                    <option value="FALOR 100 ML VAGINAL SOL">FALOR 100 ML VAGINAL SOL
                                                    </option>
                                                    <option value="FANSIDAR CES (EUROP)">FANSIDAR CES (EUROP)</option>
                                                    <option value="FAST QUICK CES B/1">FAST QUICK CES B/1</option>
                                                    <option value="FEMACLIN 3 OV (CLINDAMYCINE+CLOTRIMAZOLE)">FEMACLIN 3 OV
                                                        (CLINDAMYCINE+CLOTRIMAZOLE)</option>
                                                    <option value="FENZAR 18MG 1*10 CES">FENZAR 18MG 1*10 CES</option>
                                                    <option value="FENZAR 6MG 1*10 CES">FENZAR 6MG 1*10 CES</option>
                                                    <option value="FERDROP PLUS GOUTTES 30 ML">FERDROP PLUS GOUTTES 30 ML
                                                    </option>
                                                    <option value="FERRO VITAL DENK 30 CES">FERRO VITAL DENK 30 CES</option>
                                                    <option value="FERVEX ADULTES (EUROP)">FERVEX ADULTES (EUROP)</option>
                                                    <option value="FIXALEN 100MG SUSP">FIXALEN 100MG SUSP</option>
                                                    <option value="FIXALEN 200 MG CES">FIXALEN 200 MG CES</option>
                                                    <option value="FIXALEN 400 MG CES">FIXALEN 400 MG CES</option>
                                                    <option value="FLAGYL 4% SP 120ML (EUROP)">FLAGYL 4% SP 120ML (EUROP)
                                                    </option>
                                                    <option value="FLAGYL 500 MG CES (EUROP)">FLAGYL 500 MG CES (EUROP)
                                                    </option>
                                                    <option value="FLAMASIF 1*10CES">FLAMASIF 1*10CES</option>
                                                    <option value="FLAMO CES">FLAMO CES</option>
                                                    <option value="FLAZINE 50 GR PDE">FLAZINE 50 GR PDE</option>
                                                    <option value="FLODENT 100 GM (SHALINA)">FLODENT 100 GM (SHALINA)
                                                    </option>
                                                    <option value="FLODENT 50GM ( SHALINA)">FLODENT 50GM ( SHALINA)</option>
                                                    <option value="FLODENT CHARCOAL TOOTHPAST 100 GR">FLODENT CHARCOAL
                                                        TOOTHPAST 100 GR</option>
                                                    <option value="FLODENT GINGER MINT TOOTHPASTE 100 GR">FLODENT GINGER
                                                        MINT TOOTHPASTE 100 GR</option>
                                                    <option value="FLODENT KIDS TOOTHPASTE 50 GR">FLODENT KIDS TOOTHPASTE 50
                                                        GR</option>
                                                    <option value="FLUCONAZOLE INFUSION IV">FLUCONAZOLE INFUSION IV</option>
                                                    <option value="FLUCONAZOLE- 150MG B/1 CES">FLUCONAZOLE- 150MG B/1 CES
                                                    </option>
                                                    <option value="FLUDITEC AD 5% 125ML SP (EUROP)">FLUDITEC AD 5% 125ML SP
                                                        (EUROP)</option>
                                                    <option value="FLUDITEC ENF 2% 125ML SP (EUROP)">FLUDITEC ENF 2% 125ML
                                                        SP (EUROP)</option>
                                                    <option value="FLUOMIZIN 10 MG 6CES VAG">FLUOMIZIN 10 MG 6CES VAG
                                                    </option>
                                                    <option value="FORTALINE 20 CAPS (EUROP)">FORTALINE 20 CAPS (EUROP)
                                                    </option>
                                                    <option value="FUNBACT 75 GM SOAP">FUNBACT 75 GM SOAP</option>
                                                    <option value="FUNBACT A CREME 15 GM">FUNBACT A CREME 15 GM</option>
                                                    <option value="FUNBACT CREME 30GM TUBE">FUNBACT CREME 30GM TUBE</option>
                                                    <option value="FUNBACT POUDRE 50 GM">FUNBACT POUDRE 50 GM</option>
                                                    <option value="FUNBACT SOAP 125 GM">FUNBACT SOAP 125 GM</option>
                                                    <option value="FUNOTRIP CES">FUNOTRIP CES</option>
                                                    <option value="FU">FU</option>
                                                    <option value="FURADANTINE CES">FURADANTINE CES</option>
                                                    <option value="FUROSEMIDE CES (LASIX)">FUROSEMIDE CES (LASIX)</option>
                                                    <option value="FUROSEMIDE INJ (LASIX)">FUROSEMIDE INJ (LASIX)</option>
                                                    <option value="G-FLAM SP 60 ML">G-FLAM SP 60 ML</option>
                                                    <option value="GANT # 7.5">GANT # 7.5</option>
                                                    <option value="GANT EXAMEN">GANT EXAMEN</option>
                                                    <option value="GARANTI 3 CES">GARANTI 3 CES</option>
                                                    <option value="GASTROMEX (10X10) CES">GASTROMEX (10X10) CES</option>
                                                    <option value="GASTROMEX(ANTIACID) 100 ML SP">GASTROMEX(ANTIACID) 100 ML
                                                        SP</option>
                                                    <option value="GAVISCON SUSP BUV FL/250 ML (EUROP)">GAVISCON SUSP BUV
                                                        FL/250 ML (EUROP)</option>
                                                    <option value="GAVISCON SUSP BUV SACH B/24">GAVISCON SUSP BUV SACH B/24
                                                    </option>
                                                    <option value="GAZ HYDROPHYLE PM (NIKITA)">GAZ HYDROPHYLE PM (NIKITA)
                                                    </option>
                                                    <option value="GAZ HYDROPHYLLE ( G M )">GAZ HYDROPHYLLE ( G M )</option>
                                                    <option value="GAZ RULLO">GAZ RULLO</option>
                                                    <option value="GENTAMYCINE 80 MG 2 ML INJ">GENTAMYCINE 80 MG 2 ML INJ
                                                    </option>
                                                    <option value="GENTAMYCINE COLLYRE">GENTAMYCINE COLLYRE</option>
                                                    <option value="GERMOL LIQUID 1000 ML">GERMOL LIQUID 1000 ML</option>
                                                    <option value="GERMOL LIQUID 100 ML">GERMOL LIQUID 100 ML</option>
                                                    <option value="GERMOL LIQUID 50 ML">GERMOL LIQUID 50 ML</option>
                                                    <option value="GERMOL SOAP 125 GM">GERMOL SOAP 125 GM</option>
                                                    <option value="GERMOL SOAP 75GM">GERMOL SOAP 75GM</option>
                                                    <option value="GET BACT PLUS CREAM 50 GM">GET BACT PLUS CREAM 50 GM
                                                    </option>
                                                    <option value="GET-SPASS CES 10*10">GET-SPASS CES 10*10</option>
                                                    <option value="GET-Z+ SP 200ML">GET-Z+ SP 200ML</option>
                                                    <option value="GETOMOL 10CES">GETOMOL 10CES</option>
                                                    <option value="GETSTATINE SP 30 ML">GETSTATINE SP 30 ML</option>
                                                    <option value="GLIBEN CES">GLIBEN CES</option>
                                                    <option value="GLIBEN M CES">GLIBEN M CES</option>
                                                    <option value="GLUCOPHAGE 500MG CES (EUROP)">GLUCOPHAGE 500MG CES
                                                        (EUROP)</option>
                                                    <option value="GLUCOPHAGE 500MG CES B/60 (EUROP)">GLUCOPHAGE 500MG CES
                                                        B/60 (EUROP)</option>
                                                    <option value="GLUCOPHAGE 850 MG B/30 CES">GLUCOPHAGE 850 MG B/30 CES
                                                    </option>
                                                    <option value="GLUCOSE HYPERTONIQUE 50% 50ML">GLUCOSE HYPERTONIQUE 50%
                                                        50ML</option>
                                                    <option value="GOGYNAX 30 GR CREME">GOGYNAX 30 GR CREME</option>
                                                    <option value="GOGYNAX VAGINAL">GOGYNAX VAGINAL</option>
                                                    <option value="GRIMOL SP 50 ML">GRIMOL SP 50 ML</option>
                                                    <option value="GRIPAL ADULT SP 100 ML">GRIPAL ADULT SP 100 ML</option>
                                                    <option value="GRIPAL ENFANT 100ML SP">GRIPAL ENFANT 100ML SP</option>
                                                    <option value="GRIPAL NOURRISSON 100ML SP">GRIPAL NOURRISSON 100ML SP
                                                    </option>
                                                    <option value="GRIPE WATERE 200 ML">GRIPE WATERE 200 ML</option>
                                                    <option value="GRISEOFULVINE 500 MG CES">GRISEOFULVINE 500 MG CES
                                                    </option>
                                                    <option value="GY-TEC TABLET 4 CES">GY-TEC TABLET 4 CES</option>
                                                    <option value="H+ METRO TAB 10 CES">H+ METRO TAB 10 CES</option>
                                                    <option value="H.Q NORMEL">H.Q NORMEL</option>
                                                    <option value="HELMINTOX ADULT SACHET(EUROPE)">HELMINTOX ADULT
                                                        SACHET(EUROPE)</option>
                                                    <option value="HEMAMEX SIROP 100 ML">HEMAMEX SIROP 100 ML</option>
                                                    <option value="HEMOBIN 100ML SIROP">HEMOBIN 100ML SIROP</option>
                                                    <option value="HEMOFORCE 200ML SP">HEMOFORCE 200ML SP</option>
                                                    <option value="HEMOFORCE PLUS ZINC 200ML SP">HEMOFORCE PLUS ZINC 200ML
                                                        SP</option>
                                                    <option value="HEMOFORCE PREGA SP">HEMOFORCE PREGA SP</option>
                                                    <option value="HEMOGLOBINE B12 100 ML SP">HEMOGLOBINE B12 100 ML SP
                                                    </option>
                                                    <option value="HEMOREX CAPS">HEMOREX CAPS</option>
                                                    <option value="HEMORRIDAL HC">HEMORRIDAL HC</option>
                                                    <option value="HEMORRIDAL SUPPO">HEMORRIDAL SUPPO</option>
                                                    <option value="HEPTIN SP 2 MG 100 ML">HEPTIN SP 2 MG 100 ML</option>
                                                    <option value="HERCULES CAP B/30">HERCULES CAP B/30</option>
                                                    <option value="HIFER CES">HIFER CES</option>
                                                    <option value="HIFER SP 150ML">HIFER SP 150ML</option>
                                                    <option value="HIFER-Z 200 SP ML">HIFER-Z 200 SP ML</option>
                                                    <option value="HISTAMINIC 100 ML SP">HISTAMINIC 100 ML SP</option>
                                                    <option value="HP-3 KIT 1*7">HP-3 KIT 1*7</option>
                                                    <option value="HUILE DE RICIN 100 ML">HUILE DE RICIN 100 ML</option>
                                                    <option value="HYDROCORTISONE 1 GR">HYDROCORTISONE 1 GR</option>
                                                    <option value="IBU FORTE CAPS">IBU FORTE CAPS</option>
                                                    <option value="IBUCAP CAPS 25*10">IBUCAP CAPS 25*10</option>
                                                    <option value="IBUCAP FORTE CAPS 10*10">IBUCAP FORTE CAPS 10*10</option>
                                                    <option value="IBUCAP SP">IBUCAP SP</option>
                                                    <option value="IBUCAP SUSP 100/125MG 60ML">IBUCAP SUSP 100/125MG 60ML
                                                    </option>
                                                    <option value="IBUPROFEN 400MG CES">IBUPROFEN 400MG CES</option>
                                                    <option value="IBUSIF (IBUPROFEN)100MG 60ML">IBUSIF (IBUPROFEN)100MG
                                                        60ML</option>
                                                    <option value="IBUTEC 10*10">IBUTEC 10*10</option>
                                                    <option value="IBUTEC CAP 25*10">IBUTEC CAP 25*10</option>
                                                    <option value="IDEOS CES (EUROP)">IDEOS CES (EUROP)</option>
                                                    <option value="INDOBIS 0.1% COLLYRE">INDOBIS 0.1% COLLYRE</option>
                                                    <option value="INDOMETHACINE 100MG SUPP">INDOMETHACINE 100MG SUPP
                                                    </option>
                                                    <option value="INDOMETHACINE 25MG CAPS">INDOMETHACINE 25MG CAPS</option>
                                                    <option value="INFERON 5 ML INJ">INFERON 5 ML INJ</option>
                                                    <option value="INSULINE ACTRAPID 40UI/10ML (EUROP)">INSULINE ACTRAPID
                                                        40UI/10ML (EUROP)</option>
                                                    <option value="INSULINE INSULATARD 40UI 10 ML-EUROP">INSULINE INSULATARD
                                                        40UI 10 ML-EUROP</option>
                                                    <option value="IPPROSEC INJ">IPPROSEC INJ</option>
                                                    <option value="IPPROSEC(OMEPRAZOLE) 10CES">IPPROSEC(OMEPRAZOLE) 10CES
                                                    </option>
                                                    <option value="KAMACILONE 4 MG CES">KAMACILONE 4 MG CES</option>
                                                    <option value="KAMASUTRA CONDOM 3'S CHOCOLATE 24*3">KAMASUTRA CONDOM 3'S
                                                        CHOCOLATE 24*3</option>
                                                    <option value="KAMASUTRA CONDOM 3'S DOTTED 24*3">KAMASUTRA CONDOM 3'S
                                                        DOTTED 24*3</option>
                                                    <option value="KAMASUTRA CONDOM 3'S LONGLAST 24*3">KAMASUTRA CONDOM 3'S
                                                        LONGLAST 24*3</option>
                                                    <option value="KANAMYCINE 1 GR">KANAMYCINE 1 GR</option>
                                                    <option value="KAOLIN PLUS SP">KAOLIN PLUS SP</option>
                                                    <option value="KAPTOL LOZENGES 2*8 CES">KAPTOL LOZENGES 2*8 CES</option>
                                                    <option value="KAPTOL SP 100 ML">KAPTOL SP 100 ML</option>
                                                    <option value="KAPTOL SUPPO BEBE">KAPTOL SUPPO BEBE</option>
                                                    <option value="KAPTOL SUPPO ENF">KAPTOL SUPPO ENF</option>
                                                    <option value="KENAKORT 40 MG INJ (EUROP)">KENAKORT 40 MG INJ (EUROP)
                                                    </option>
                                                    <option value="KETAMINE HCL INJ">KETAMINE HCL INJ</option>
                                                    <option value="KETAZOL 15 GR CREAM">KETAZOL 15 GR CREAM</option>
                                                    <option value="KETAZOL 30 GR CREAM">KETAZOL 30 GR CREAM</option>
                                                    <option value="KETAZOL CES 10 CES">KETAZOL CES 10 CES</option>
                                                    <option value="KETAZOL SHAMPOO 100ML">KETAZOL SHAMPOO 100ML</option>
                                                    <option value="KIDA PLUS CES">KIDA PLUS CES</option>
                                                    <option value="KIDA PLUS SUSP 10ML">KIDA PLUS SUSP 10ML</option>
                                                    <option value="KIFARU 100MG CES">KIFARU 100MG CES</option>
                                                    <option value="KIFARU 50MG CES">KIFARU 50MG CES</option>
                                                    <option value="KINFORCE 100 MG/4 CES">KINFORCE 100 MG/4 CES</option>
                                                    <option value="KITOKO 4 CES 50 MG">KITOKO 4 CES 50 MG</option>
                                                    <option value="KITOKO PLUS 4 CES 100 MG">KITOKO PLUS 4 CES 100 MG
                                                    </option>
                                                    <option value="KOFLYN ROUGE 100 ML SP(MUCOLYTIQUE)">KOFLYN ROUGE 100 ML
                                                        SP(MUCOLYTIQUE)</option>
                                                    <option value="KOFSIL (COUGH SIROP) 100 ML SP">KOFSIL (COUGH SIROP) 100
                                                        ML SP</option>
                                                    <option value="KOFSIL PASTILLES">KOFSIL PASTILLES</option>
                                                    <option value="KULA MUZURI 100 ML SP">KULA MUZURI 100 ML SP</option>
                                                    <option value="L-ARTEM FORTE CES">L-ARTEM FORTE CES</option>
                                                    <option value="LACTOLASE 100 ML FLC">LACTOLASE 100 ML FLC</option>
                                                    <option value="LACTOLASE 15ML SACHET B/20">LACTOLASE 15ML SACHET B/20
                                                    </option>
                                                    <option value="LARGACTIL 25 MG 5 ML INJ (EUROP)">LARGACTIL 25 MG 5 ML
                                                        INJ (EUROP)</option>
                                                    <option value="LAROXYL 50 MG CES">LAROXYL 50 MG CES</option>
                                                    <option value="LEMOTIN CES">LEMOTIN CES</option>
                                                    <option value="LEOPARD BALM 6GR">LEOPARD BALM 6GR</option>
                                                    <option value="LEOPARD TUBE BALM 25 GR">LEOPARD TUBE BALM 25 GR</option>
                                                    <option value="LEVAMISOLE 150 MG CES">LEVAMISOLE 150 MG CES</option>
                                                    <option value="LEVAMISOLE 50 MG CES">LEVAMISOLE 50 MG CES</option>
                                                    <option value="LEVOCIZINE 10 CES">LEVOCIZINE 10 CES</option>
                                                    <option value="LEVOCIZINE SP">LEVOCIZINE SP</option>
                                                    <option value="LEXOTAN 3MG CES (EUROP)">LEXOTAN 3MG CES (EUROP)</option>
                                                    <option value="LEXOTAN 6MG CES (EUROP)">LEXOTAN 6MG CES (EUROP)</option>
                                                    <option value="LIDOCAINE 2%(ANESTHESIQUE LOCAL) 20 ML">LIDOCAINE
                                                        2%(ANESTHESIQUE LOCAL) 20 ML</option>
                                                    <option value="LINCOCIN 500 MG CAPS (EUROP)">LINCOCIN 500 MG CAPS
                                                        (EUROP)</option>
                                                    <option value="LINCOMYCIN 600 MG AMP">LINCOMYCIN 600 MG AMP</option>
                                                    <option value="LINCOMYCINE 250 MG/5ML SP">LINCOMYCINE 250 MG/5ML SP
                                                    </option>
                                                    <option value="LINCOMYCINE 500MG">LINCOMYCINE 500MG</option>
                                                    <option value="LOPRADE 2MG CES">LOPRADE 2MG CES</option>
                                                    <option value="LOSACAED-H CES">LOSACAED-H CES</option>
                                                    <option value="LOTION MONGANGA BEBE">LOTION MONGANGA BEBE</option>
                                                    <option value="LUAGRA CES 2 CES">LUAGRA CES 2 CES</option>
                                                    <option value="LUAGRA JELLY">LUAGRA JELLY</option>
                                                    <option value="LUMEART 20/120 B/12 CES">LUMEART 20/120 B/12 CES</option>
                                                    <option value="LUMEART 40/240MG CES">LUMEART 40/240MG CES</option>
                                                    <option value="LUMEART 80/480 MG CES">LUMEART 80/480 MG CES</option>
                                                    <option value="LUTHER DP CES">LUTHER DP CES</option>
                                                    <option value="LUTHER FORTE CES">LUTHER FORTE CES</option>
                                                    <option value="LUTHER SP 60ML">LUTHER SP 60ML</option>
                                                    <option value="LYNEVIT CAP">LYNEVIT CAP</option>
                                                    <option value="LYNEVIT SYRUP 100 ML">LYNEVIT SYRUP 100 ML</option>
                                                    <option value="MAALOX 250 ML SP (EUROP)">MAALOX 250 ML SP (EUROP)
                                                    </option>
                                                    <option value="MAALOX CES B/40 (EUROP)">MAALOX CES B/40 (EUROP)</option>
                                                    <option value="MAALOX SACHET 15ML (EUROP)">MAALOX SACHET 15ML (EUROP)
                                                    </option>
                                                    <option value="MAGASCON SACHET 15 ML">MAGASCON SACHET 15 ML</option>
                                                    <option value="MAGASCONE SP 200 ML">MAGASCONE SP 200 ML</option>
                                                    <option value="MAGNAVIT 30 CAPS">MAGNAVIT 30 CAPS</option>
                                                    <option value="MAGNAVIT TONIC 200 ML SP">MAGNAVIT TONIC 200 ML SP
                                                    </option>
                                                    <option value="MAGNES ACTIVE DENK SACHETS B/30">MAGNES ACTIVE DENK
                                                        SACHETS B/30</option>
                                                    <option value="MALADOX(FANSIDAR) CES PLQ">MALADOX(FANSIDAR) CES PLQ
                                                    </option>
                                                    <option value="MALAXIN-PLUS CAPS">MALAXIN-PLUS CAPS</option>
                                                    <option value="MANNITOL 100 ML INJ">MANNITOL 100 ML INJ</option>
                                                    <option value="MAXIDEX COLLYRE 0.1%">MAXIDEX COLLYRE 0.1%</option>
                                                    <option value="MAXIDROL COLLYRE 3 ML (EUROP)">MAXIDROL COLLYRE 3 ML
                                                        (EUROP)</option>
                                                    <option value="MAYTAB TAB 20 CES">MAYTAB TAB 20 CES</option>
                                                    <option value="MEBENDAZOLE CES">MEBENDAZOLE CES</option>
                                                    <option value="MEBENDAZOLE 100 MG 30 ML SP">MEBENDAZOLE 100 MG 30 ML SP
                                                    </option>
                                                    <option value="MEDIK -55 CES">MEDIK -55 CES</option>
                                                    <option value="MEDILYT">MEDILYT</option>
                                                    <option value="MEFANTHER FORT CES">MEFANTHER FORT CES</option>
                                                    <option value="MEFANTHER SIROP 60ML">MEFANTHER SIROP 60ML</option>
                                                    <option value="MEFTAL-FORTE CES">MEFTAL-FORTE CES</option>
                                                    <option value="MENTRIL 100 ML SOL">MENTRIL 100 ML SOL</option>
                                                    <option value="MEOFLOX 60ML SIROP">MEOFLOX 60ML SIROP</option>
                                                    <option value="MEOFLOX B/10 CES">MEOFLOX B/10 CES</option>
                                                    <option value="MERCUROCROME 60 ML">MERCUROCROME 60 ML</option>
                                                    <option value="METAZOL 250MG CES">METAZOL 250MG CES</option>
                                                    <option value="METHERGINE INJ">METHERGINE INJ</option>
                                                    <option value="METHERINE GOUTTES 10 ML(BIS)">METHERINE GOUTTES 10
                                                        ML(BIS)</option>
                                                    <option value="METNOR 200 MG CES">METNOR 200 MG CES</option>
                                                    <option value="METNOR 400 MG CES">METNOR 400 MG CES</option>
                                                    <option value="METNOR SP 30 ML">METNOR SP 30 ML</option>
                                                    <option value="METOCLOPRAMIDE INJ">METOCLOPRAMIDE INJ</option>
                                                    <option value="METROKIM 500MG CAPS">METROKIM 500MG CAPS</option>
                                                    <option value="METRONIDAZOL 500 MG 10 CES">METRONIDAZOL 500 MG 10 CES
                                                    </option>
                                                    <option value="METRONIDAZOLE 25*10CES">METRONIDAZOLE 25*10CES</option>
                                                    <option value="METRONIDAZOLE 125 MG SP">METRONIDAZOLE 125 MG SP</option>
                                                    <option value="METRONIDAZOLE IV PERFUSION">METRONIDAZOLE IV PERFUSION
                                                    </option>
                                                    <option value="MICROLAX AD TUBE B/4 (EUROP)">MICROLAX AD TUBE B/4
                                                        (EUROP)</option>
                                                    <option value="MICROLAX BB B/4 TUBE (EUROP)">MICROLAX BB B/4 TUBE
                                                        (EUROP)</option>
                                                    <option value="MIG GEL 125 ML SP">MIG GEL 125 ML SP</option>
                                                    <option value="MIG GEL 200ML SP">MIG GEL 200ML SP</option>
                                                    <option value="MIPOTEC 200MCG CES B/4">MIPOTEC 200MCG CES B/4</option>
                                                    <option value="MISTROVIX CES">MISTROVIX CES</option>
                                                    <option value="MOXYCLAV DUO 457 SP 100 ML">MOXYCLAV DUO 457 SP 100 ML
                                                    </option>
                                                    <option value="MOXYCLAV DUO 1000TAB">MOXYCLAV DUO 1000TAB</option>
                                                    <option value="MOXYCLAV DUO 562.5 CES">MOXYCLAV DUO 562.5 CES</option>
                                                    <option value="MUCOPRIN ADULT SP">MUCOPRIN ADULT SP</option>
                                                    <option value="MUCOPRIN PED SP">MUCOPRIN PED SP</option>
                                                    <option value="MUCORIL SP">MUCORIL SP</option>
                                                    <option value="MUCOSIF EXPECT 100ML SP.">MUCOSIF EXPECT 100ML SP.
                                                    </option>
                                                    <option value="MUCOSIF PED 100ML SP">MUCOSIF PED 100ML SP</option>
                                                    <option value="MULTIVITAMINE 100 ML SP">MULTIVITAMINE 100 ML SP</option>
                                                    <option value="MULTIVITAMINE CES">MULTIVITAMINE CES</option>
                                                    <option value="MULTIVITAMINE GOUTTE">MULTIVITAMINE GOUTTE</option>
                                                    <option value="MUSCLE PLUS">MUSCLE PLUS</option>
                                                    <option value="MY VITA ADULT EFF">MY VITA ADULT EFF</option>
                                                    <option value="MY VITA C +ZINC EFF">MY VITA C +ZINC EFF</option>
                                                    <option value="MY VITA KIDDS EFF CES">MY VITA KIDDS EFF CES</option>
                                                    <option value="MYCOZEMA CREME 30 GR">MYCOZEMA CREME 30 GR</option>
                                                    <option value="NAOMI CREAM 50 GR">NAOMI CREAM 50 GR</option>
                                                    <option value="NAOMI DIAPER ADULT 80-112 CM MEDIUM">NAOMI DIAPER ADULT
                                                        80-112 CM MEDIUM</option>
                                                    <option value="NAPRIL 10MG CES">NAPRIL 10MG CES</option>
                                                    <option value="NASCARO SP">NASCARO SP</option>
                                                    <option value="NECLAZ M 30 CES">NECLAZ M 30 CES</option>
                                                    <option value="NEOCIN 15 GR">NEOCIN 15 GR</option>
                                                    <option value="NEOGYNAX B/12 OVILE">NEOGYNAX B/12 OVILE</option>
                                                    <option value="NEOGYNAX B/6">NEOGYNAX B/6</option>
                                                    <option value="NEUROTON 500MG 50 ML SIROP">NEUROTON 500MG 50 ML SIROP
                                                    </option>
                                                    <option value="NEUROTONE CES">NEUROTONE CES</option>
                                                    <option value="NEWMEX SPAS CES">NEWMEX SPAS CES</option>
                                                    <option value="NEWMEX-500 CES">NEWMEX-500 CES</option>
                                                    <option value="NEWMEX-FORTE CES">NEWMEX-FORTE CES</option>
                                                    <option value="NOCIGEL SP 200 ML">NOCIGEL SP 200 ML</option>
                                                    <option value="NOOTROPYL CES (GENERIQUE)">NOOTROPYL CES (GENERIQUE)
                                                    </option>
                                                    <option value="NOOTRYMON 5ML 1GR INJ">NOOTRYMON 5ML 1GR INJ</option>
                                                    <option value="NORFLOXACIN 400MG CES">NORFLOXACIN 400MG CES</option>
                                                    <option value="NORMEGYL CES">NORMEGYL CES</option>
                                                    <option value="NORMEGYL DP CES">NORMEGYL DP CES</option>
                                                    <option value="NORMEGYL SP 30ML">NORMEGYL SP 30ML</option>
                                                    <option value="NORMEGYL SP 60ML">NORMEGYL SP 60ML</option>
                                                    <option value="NORMET 200MG CES 10 CES">NORMET 200MG CES 10 CES</option>
                                                    <option value="NORMET 400MG CES 10 CES">NORMET 400MG CES 10 CES</option>
                                                    <option value="NORMET 60ML">NORMET 60ML</option>
                                                    <option value="NORMET SP 30ML">NORMET SP 30ML</option>
                                                    <option value="NORMEZOL 30ML SP">NORMEZOL 30ML SP</option>
                                                    <option value="NORMEZOL (NORFLO+METRO ) CES">NORMEZOL (NORFLO+METRO )
                                                        CES</option>
                                                    <option value="NOZINAN 100MG CES (EUROP)">NOZINAN 100MG CES (EUROP)
                                                    </option>
                                                    <option value="NUTRILIN CES">NUTRILIN CES</option>
                                                    <option value="NUTRILINE SP 100 ML">NUTRILINE SP 100 ML</option>
                                                    <option value="NUTRISANG SP">NUTRISANG SP</option>
                                                    <option value="NYSTATINE VAGINAL">NYSTATINE VAGINAL</option>
                                                    <option value="NYSTATINE CES">NYSTATINE CES</option>
                                                    <option value="OFLOKIN OZ CES 10 CES">OFLOKIN OZ CES 10 CES</option>
                                                    <option value="OFLOQUE-OZ SP 60 ML">OFLOQUE-OZ SP 60 ML</option>
                                                    <option value="OFLOXACIN-OZ CES">OFLOXACIN-OZ CES</option>
                                                    <option value="OMEPRAZOL -20 CAP">OMEPRAZOL -20 CAP</option>
                                                    <option value="OMEPRAZOL INJ">OMEPRAZOL INJ</option>
                                                    <option value="OMG-3 SOFT GELATING 2*10 CAPS">OMG-3 SOFT GELATING 2*10
                                                        CAPS</option>
                                                    <option value="ONDANSETROM 30ML SP">ONDANSETROM 30ML SP</option>
                                                    <option value="ONDANSETRON CES">ONDANSETRON CES</option>
                                                    <option value="ONDANSTERON INJ 4 MG/2ML">ONDANSTERON INJ 4 MG/2ML
                                                    </option>
                                                    <option value="OPTALIDON 5*10 CES">OPTALIDON 5*10 CES</option>
                                                    <option value="OPTICROM 2% COLLYRE (EUROP)">OPTICROM 2% COLLYRE (EUROP)
                                                    </option>
                                                    <option value="ORAL SACHETS">ORAL SACHETS</option>
                                                    <option value="ORANGINE 100 ML SP (AFRI PHARMA)">ORANGINE 100 ML SP
                                                        (AFRI PHARMA)</option>
                                                    <option value="ORGAMETRIL 5MG CES (EUROP)">ORGAMETRIL 5MG CES (EUROP)
                                                    </option>
                                                    <option value="ORNID 500MG OVULES B/10">ORNID 500MG OVULES B/10</option>
                                                    <option value="ORNID INJ 100 ML">ORNID INJ 100 ML</option>
                                                    <option value="ORNID OX CES">ORNID OX CES</option>
                                                    <option value="ORNID OX INJ 100 ML">ORNID OX INJ 100 ML</option>
                                                    <option value="ORNID OX SP 60ML">ORNID OX SP 60ML</option>
                                                    <option value="OROKEN ENF 40MG /5ML FL/40ML (EUROP)">OROKEN ENF 40MG
                                                        /5ML FL/40ML (EUROP)</option>
                                                    <option value="OTOBIOTIC 5 ML">OTOBIOTIC 5 ML</option>
                                                    <option value="OTRIVIN 1% GOUTTE NASAL (EUROP)">OTRIVIN 1% GOUTTE NASAL
                                                        (EUROP)</option>
                                                    <option value="OUATE 100 GR">OUATE 100 GR</option>
                                                    <option value="OUATE 250 GR">OUATE 250 GR</option>
                                                    <option value="OUATE 500GR">OUATE 500GR</option>
                                                    <option value="OVOGYL (METRONIDAZOLE) 500MG OV">OVOGYL (METRONIDAZOLE)
                                                        500MG OV</option>
                                                    <option value="OXARIN 60MG 0.6 ML INJECTION">OXARIN 60MG 0.6 ML
                                                        INJECTION</option>
                                                    <option value="OXARIN 80 MG 0.8 ML INJECTION">OXARIN 80 MG 0.8 ML
                                                        INJECTION</option>
                                                    <option value="OXIN 200MG CAPS">OXIN 200MG CAPS</option>
                                                    <option value="OXYTOCIN 2ML INJ">OXYTOCIN 2ML INJ</option>
                                                    <option value="PA 12 CES">PA 12 CES</option>
                                                    <option value="PAIN RILIF CES 10 CES">PAIN RILIF CES 10 CES</option>
                                                    <option value="PANADOL 500 MG 12 CES EFF">PANADOL 500 MG 12 CES EFF
                                                    </option>
                                                    <option value="PANADOL ADVANCE 500 MG CES (EUROP)">PANADOL ADVANCE 500
                                                        MG CES (EUROP)</option>
                                                    <option value="PANAL CES">PANAL CES</option>
                                                    <option value="PANTEC 20 MG B/30 CES">PANTEC 20 MG B/30 CES</option>
                                                    <option value="PANTEC 40 MG INJ">PANTEC 40 MG INJ</option>
                                                    <option value="PANTEC CES 40 MG">PANTEC CES 40 MG</option>
                                                    <option value="PAPAVERINE 40MG 2ML INJ">PAPAVERINE 40MG 2ML INJ</option>
                                                    <option value="PAPAVERINE CES">PAPAVERINE CES</option>
                                                    <option value="PARA-C SP 100ML (PARA + VIT C)">PARA-C SP 100ML (PARA +
                                                        VIT C)</option>
                                                    <option value="PARACETAMOL 100 ML SP">PARACETAMOL 100 ML SP</option>
                                                    <option value="PARACETAMOL CES">PARACETAMOL CES</option>
                                                    <option value="PARACETAMOL INFISION">PARACETAMOL INFISION</option>
                                                    <option value="PARACETAMOL INJ">PARACETAMOL INJ</option>
                                                    <option value="PARACO-DENK 1000/60 SUPPO B/10">PARACO-DENK 1000/60 SUPPO
                                                        B/10</option>
                                                    <option value="PARAFEN (PARA+IBU) CES">PARAFEN (PARA+IBU) CES</option>
                                                    <option value="PARAFEN 60 ML SP">PARAFEN 60 ML SP</option>
                                                    <option value="PARALGAN 500 MG EFF10 CES">PARALGAN 500 MG EFF10 CES
                                                    </option>
                                                    <option value="PARALGAN FORTE 1000MG EFF10 CES">PARALGAN FORTE 1000MG
                                                        EFF10 CES</option>
                                                    <option value="PASMEX 10 CES">PASMEX 10 CES</option>
                                                    <option value="PATOUX CES">PATOUX CES</option>
                                                    <option value="PDE ANTIGAL 35 GR">PDE ANTIGAL 35 GR</option>
                                                    <option value="PDE CAMPHREE 35GR">PDE CAMPHREE 35GR</option>
                                                    <option value="PDE CHRYSOPHANIQUE 35 GR">PDE CHRYSOPHANIQUE 35 GR
                                                    </option>
                                                    <option value="PDE ICTHIOLEE 35 GR">PDE ICTHIOLEE 35 GR</option>
                                                    <option value="PDE OXID DE ZINC 35 GR">PDE OXID DE ZINC 35 GR</option>
                                                    <option value="PDE TETRA OPHT">PDE TETRA OPHT</option>
                                                    <option value="PEDIFEN 100MG/5ML SP 100ML (EUROP)">PEDIFEN 100MG/5ML SP
                                                        100ML (EUROP)</option>
                                                    <option value="PEDIFEN AD 400MG CES (EUROP)">PEDIFEN AD 400MG CES
                                                        (EUROP)</option>
                                                    <option value="PENICILLINE 2.4 MEGA">PENICILLINE 2.4 MEGA</option>
                                                    <option value="PENICILLINE 4 MEGA(PROCAINE)">PENICILLINE 4
                                                        MEGA(PROCAINE)</option>
                                                    <option value="PENICILLINE 5 MEGA">PENICILLINE 5 MEGA</option>
                                                    <option value="PENICILLINE V 125 60ML SP">PENICILLINE V 125 60ML SP
                                                    </option>
                                                    <option value="PENICILLINE- V 250MG CES">PENICILLINE- V 250MG CES
                                                    </option>
                                                    <option value="PERDOLAN BB 100MG SUPPO (EUROP)">PERDOLAN BB 100MG SUPPO
                                                        (EUROP)</option>
                                                    <option value="PERMANGANATE POTASIUM VRAC">PERMANGANATE POTASIUM VRAC
                                                    </option>
                                                    <option value="PHENOBARBITAL CES">PHENOBARBITAL CES</option>
                                                    <option value="PHONE ITEL">PHONE ITEL</option>
                                                    <option value="PHOSPHALUGEL SACHET (EUROP)">PHOSPHALUGEL SACHET (EUROP)
                                                    </option>
                                                    <option value="PILULE-S">PILULE-S</option>
                                                    <option value="PINAX SP 60 ML">PINAX SP 60 ML</option>
                                                    <option value="PINAX TAB 1*9 CES">PINAX TAB 1*9 CES</option>
                                                    <option value="PIRATOX CES">PIRATOX CES</option>
                                                    <option value="POCHE DE SANG 250ML">POCHE DE SANG 250ML</option>
                                                    <option value="POCHE DE SANG 450ML">POCHE DE SANG 450ML</option>
                                                    <option value="POIRE NO 1">POIRE NO 1</option>
                                                    <option value="POIRE NO 2">POIRE NO 2</option>
                                                    <option value="POIRE NO 6">POIRE NO 6</option>
                                                    <option value="POIRE NO.4">POIRE NO.4</option>
                                                    <option value="POIRE NO8">POIRE NO8</option>
                                                    <option value="POLYDEXACINE COLLYRE.">POLYDEXACINE COLLYRE.</option>
                                                    <option value="POLYGEL CES SHALINA">POLYGEL CES SHALINA</option>
                                                    <option value="POLYGEL SP 200 ML">POLYGEL SP 200 ML</option>
                                                    <option value="POLYGEL SP MANGO 12OML">POLYGEL SP MANGO 12OML</option>
                                                    <option value="POLYGYNAX 12 OV (EUROP)">POLYGYNAX 12 OV (EUROP)</option>
                                                    <option value="POP NO-5 POWDER">POP NO-5 POWDER</option>
                                                    <option value="POUDRE MONGANGA BEBE 300 GM">POUDRE MONGANGA BEBE 300 GM
                                                    </option>
                                                    <option value="POWER PLUS CES 10 CES">POWER PLUS CES 10 CES</option>
                                                    <option value="PRAZOL CES">PRAZOL CES</option>
                                                    <option value="PRAZOL KIT CES">PRAZOL KIT CES</option>
                                                    <option value="PRAZOLE D CAPSULES 10 CES">PRAZOLE D CAPSULES 10 CES
                                                    </option>
                                                    <option value="PREDNISOLON COLYYRE">PREDNISOLON COLYYRE</option>
                                                    <option value="PREDNISOLONE 5MG CES">PREDNISOLONE 5MG CES</option>
                                                    <option value="PREGNERVE 75 MG 3*10 CES">PREGNERVE 75 MG 3*10 CES
                                                    </option>
                                                    <option value="PREGNYL 5000 UI INJ (EUROP)">PREGNYL 5000 UI INJ (EUROP)
                                                    </option>
                                                    <option value="PREMI FLAM 60 ML">PREMI FLAM 60 ML</option>
                                                    <option value="PREMI SPAS GOUTTE">PREMI SPAS GOUTTE</option>
                                                    <option value="PREMICOLD GOUTTE 15 ML">PREMICOLD GOUTTE 15 ML</option>
                                                    <option value="PREMIMOL EFFERVESCENT 1000 MG 2*4 CES">PREMIMOL
                                                        EFFERVESCENT 1000 MG 2*4 CES</option>
                                                    <option value="PROCEDOL CES 5*4 EFF 10 CES">PROCEDOL CES 5*4 EFF 10 CES
                                                    </option>
                                                    <option value="PROCEF INJ">PROCEF INJ</option>
                                                    <option value="PROCYL INJ">PROCYL INJ</option>
                                                    <option value="PRODOLAN SUPPO BB">PRODOLAN SUPPO BB</option>
                                                    <option value="PRODOLAN SUPPO ENF">PRODOLAN SUPPO ENF</option>
                                                    <option value="PROFENID 100 MG CES (EUROP)">PROFENID 100 MG CES (EUROP)
                                                    </option>
                                                    <option value="PROFENID 100MG SUPPO (EUROP)">PROFENID 100MG SUPPO
                                                        (EUROP)</option>
                                                    <option value="PROFER-Z SP 200 ML">PROFER-Z SP 200 ML</option>
                                                    <option value="PROGESTERONE 2 ML INJ">PROGESTERONE 2 ML INJ</option>
                                                    <option value="PROMETHAZINE 25MG CES">PROMETHAZINE 25MG CES</option>
                                                    <option value="PROMETHAZINE 5 MG 100 ML SP">PROMETHAZINE 5 MG 100 ML SP
                                                    </option>
                                                    <option value="PROMETHAZINE EXPECTORANT 100ML">PROMETHAZINE EXPECTORANT
                                                        100ML</option>
                                                    <option value="PROMETHAZINE INJ">PROMETHAZINE INJ</option>
                                                    <option value="PROMILA SP 150 ML">PROMILA SP 150 ML</option>
                                                    <option value="PROTONIC 100ML SP">PROTONIC 100ML SP</option>
                                                    <option value="PROVIDON 120 ML SOL">PROVIDON 120 ML SOL</option>
                                                    <option value="PULMINE ENFANT">PULMINE ENFANT</option>
                                                    <option value="PULMINE BEBE">PULMINE BEBE</option>
                                                    <option value="PULMINE SP 100 ML">PULMINE SP 100 ML</option>
                                                    <option value="PYLORIMEX 7KIT">PYLORIMEX 7KIT</option>
                                                    <option value="QUININE GOUTTE 15ML">QUININE GOUTTE 15ML</option>
                                                    <option value="QUININE 100 ML SP">QUININE 100 ML SP</option>
                                                    <option value="QUININE 100MG SUPPO">QUININE 100MG SUPPO</option>
                                                    <option value="QUININE 250MG 10 CES (P.K.)">QUININE 250MG 10 CES (P.K.)
                                                    </option>
                                                    <option value="QUININE 300 MG 10 CES (P.K.)">QUININE 300 MG 10 CES
                                                        (P.K.)</option>
                                                    <option value="QUININE 500MG 10CES (P.K.)">QUININE 500MG 10CES (P.K.)
                                                    </option>
                                                    <option value="QUININE 500MG INJ">QUININE 500MG INJ</option>
                                                    <option value="QUININE GOUTTE 15 ML P K">QUININE GOUTTE 15 ML P K
                                                    </option>
                                                    <option value="RECTOSOL HC">RECTOSOL HC</option>
                                                    <option value="RECTOSOL SIMPLE">RECTOSOL SIMPLE</option>
                                                    <option value="RELAX 5GM">RELAX 5GM</option>
                                                    <option value="RELIF BAUM 100 GM">RELIF BAUM 100 GM</option>
                                                    <option value="RELIF CREME TUBE 50 GM">RELIF CREME TUBE 50 GM</option>
                                                    <option value="RELIF SPARY">RELIF SPARY</option>
                                                    <option value="RELIFF CES">RELIFF CES</option>
                                                    <option value="RHINATHIOL AD SP 125 ML (EUROP)">RHINATHIOL AD SP 125 ML
                                                        (EUROP)</option>
                                                    <option value="RHINATHIOL ENF (EUROP)">RHINATHIOL ENF (EUROP)</option>
                                                    <option value="RHUMALGAN SPRAY 70 ML">RHUMALGAN SPRAY 70 ML</option>
                                                    <option value="RHUMFIX 0.5% NASAL DROP (ENF)">RHUMFIX 0.5% NASAL DROP
                                                        (ENF)</option>
                                                    <option value="RHUMFIX 1% NASAL DROPS (ADULT)">RHUMFIX 1% NASAL DROPS
                                                        (ADULT)</option>
                                                    <option value="RHUMFIX BABY 5ML">RHUMFIX BABY 5ML</option>
                                                    <option value="RHUMINE GOUTTE 0.5%">RHUMINE GOUTTE 0.5%</option>
                                                    <option value="RHUMINE GOUTTE 10ML 1%">RHUMINE GOUTTE 10ML 1%</option>
                                                    <option value="RINOMICINE 10MG B/10 SACHT">RINOMICINE 10MG B/10 SACHT
                                                    </option>
                                                    <option value="ROSA FORCE 50 MG 4 CES">ROSA FORCE 50 MG 4 CES</option>
                                                    <option value="ROSACID SP 100 ML">ROSACID SP 100 ML</option>
                                                    <option value="ROSAMI CREAM 50GM">ROSAMI CREAM 50GM</option>
                                                    <option value="ROSBACT CREAM 15GM">ROSBACT CREAM 15GM</option>
                                                    <option value="ROSBACT CREAM 30GM">ROSBACT CREAM 30GM</option>
                                                    <option value="ROSUMEX 10 3*10 CES">ROSUMEX 10 3*10 CES</option>
                                                    <option value="ROSUMEX F 200 MG CES 3*10">ROSUMEX F 200 MG CES 3*10
                                                    </option>
                                                    <option value="RUFENAC CREAM 30GM">RUFENAC CREAM 30GM</option>
                                                    <option value="RUFENAC SPRAY 70ML">RUFENAC SPRAY 70ML</option>
                                                    <option value="S A T 1500 UT 10 AMP">S A T 1500 UT 10 AMP</option>
                                                    <option value="S- FORCE 2 CES">S- FORCE 2 CES</option>
                                                    <option value="S-FORCE 50 MG 4 CES">S-FORCE 50 MG 4 CES</option>
                                                    <option value="SAC A URINE 2 LIT">SAC A URINE 2 LIT</option>
                                                    <option value="SALBUTAMOL 0.25 MG 10 AMP">SALBUTAMOL 0.25 MG 10 AMP
                                                    </option>
                                                    <option value="SALBUTAMOL 2MG CES">SALBUTAMOL 2MG CES</option>
                                                    <option value="SALBUTAMOL SP">SALBUTAMOL SP</option>
                                                    <option value="SALBUTAMOL SPRAY">SALBUTAMOL SPRAY</option>
                                                    <option value="SALBUTAMOL SUPPO 1GM (GEN)">SALBUTAMOL SUPPO 1GM (GEN)
                                                    </option>
                                                    <option value="SAVON MONGANGA BEBE 90 GM">SAVON MONGANGA BEBE 90 GM
                                                    </option>
                                                    <option value="SCORVITEX-Z TAB B/10 CES">SCORVITEX-Z TAB B/10 CES
                                                    </option>
                                                    <option value="SECOURS CES 10 CES">SECOURS CES 10 CES</option>
                                                    <option value="SEDATOUX SP 100 ML">SEDATOUX SP 100 ML</option>
                                                    <option value="SEKROL ADULT 30MG 150ML SP">SEKROL ADULT 30MG 150ML SP
                                                    </option>
                                                    <option value="SEKROL PED 0.15MG 100ML SP">SEKROL PED 0.15MG 100ML SP
                                                    </option>
                                                    <option value="SERC 8MG 90CES">SERC 8MG 90CES</option>
                                                    <option value="SERING 60 ML">SERING 60 ML</option>
                                                    <option value="SERINGUE 10ML">SERINGUE 10ML</option>
                                                    <option value="SERINGUE 20ML">SERINGUE 20ML</option>
                                                    <option value="SERINGUE 2CC">SERINGUE 2CC</option>
                                                    <option value="SERINGUE 5 CC">SERINGUE 5 CC</option>
                                                    <option value="SERINGUE A INSULINE -100 UNITE">SERINGUE A INSULINE -100
                                                        UNITE</option>
                                                    <option value="SERUM GLUCOSE 10% 500 ML">SERUM GLUCOSE 10% 500 ML
                                                    </option>
                                                    <option value="SERUM GLUCOSE 5% 1 LIT">SERUM GLUCOSE 5% 1 LIT</option>
                                                    <option value="SERUM GLUCOSE 5% 500 ML">SERUM GLUCOSE 5% 500 ML</option>
                                                    <option value="SERUM MIXTE 1 LIT">SERUM MIXTE 1 LIT</option>
                                                    <option value="SERUM MIXTE 500 ML">SERUM MIXTE 500 ML</option>
                                                    <option value="SERUM PHYSIO 500 ML">SERUM PHYSIO 500 ML</option>
                                                    <option value="SERUM RINGER LACTATE 500 ML">SERUM RINGER LACTATE 500 ML
                                                    </option>
                                                    <option value="SHALBACTAM TZ IV 1.125 G">SHALBACTAM TZ IV 1.125 G
                                                    </option>
                                                    <option value="SHALCIP TZ CES 10 CES">SHALCIP TZ CES 10 CES</option>
                                                    <option value="SHALDEX COLLYRE 10 ML">SHALDEX COLLYRE 10 ML</option>
                                                    <option value="SHALFLOX(NORFLOXACIN) CES">SHALFLOX(NORFLOXACIN) CES
                                                    </option>
                                                    <option value="SHALPLAST [BAND AID]">SHALPLAST [BAND AID]</option>
                                                    <option value="SHALTOUX 125ML SP">SHALTOUX 125ML SP</option>
                                                    <option value="SHALTOUX ORANGES LOZENGES">SHALTOUX ORANGES LOZENGES
                                                    </option>
                                                    <option value="SHALTOUX RAPID 100 ML SP">SHALTOUX RAPID 100 ML SP
                                                    </option>
                                                    <option value="SIFA COLD ENF 100ML SP">SIFA COLD ENF 100ML SP</option>
                                                    <option value="SINUTAB FORTE 20 CES">SINUTAB FORTE 20 CES</option>
                                                    <option value="SOCOMOL 1 GM EFF">SOCOMOL 1 GM EFF</option>
                                                    <option value="SOCOMOL GEL 30 GM">SOCOMOL GEL 30 GM</option>
                                                    <option value="SOCOMOL INJECTION">SOCOMOL INJECTION</option>
                                                    <option value="SOCOMOL SPRAY 100 ML">SOCOMOL SPRAY 100 ML</option>
                                                    <option value="SOCOMOL SUSPENSION 100 ML">SOCOMOL SUSPENSION 100 ML
                                                    </option>
                                                    <option value="SOCOMOL TAB 10 CES">SOCOMOL TAB 10 CES</option>
                                                    <option value="SOLUT-PHYSIO GTTE NASALE">SOLUT-PHYSIO GTTE NASALE
                                                    </option>
                                                    <option value="SOLUTION DAKIN">SOLUTION DAKIN</option>
                                                    <option value="SOND VESICAL (2 VOISE )NO-10">SOND VESICAL (2 VOISE
                                                        )NO-10</option>
                                                    <option value="SOND VESICAL (2 VOISE) NO 8">SOND VESICAL (2 VOISE) NO 8
                                                    </option>
                                                    <option value="SONDE NASO GASTRIC N0 14">SONDE NASO GASTRIC N0 14
                                                    </option>
                                                    <option value="SONDE NASO GASTRIL NO 18">SONDE NASO GASTRIL NO 18
                                                    </option>
                                                    <option value="SONDE VESICAL (2 VOIES ) G 14">SONDE VESICAL (2 VOIES ) G
                                                        14</option>
                                                    <option value="SONDE VESICAL (2 VOIES) G16">SONDE VESical (2 VOIES) G16
                                                    </option>
                                                    <option value="SONDE VESICAL (2 VOIES) G18">SONDE VESICAL (2 VOIES) G18
                                                    </option>
                                                    <option value="SONDE VESICALE 2 VOIES N 20">SONDE VESICALE 2 VOIES N 20
                                                    </option>
                                                    <option value="SONDE VESICALE N12">SONDE VESICALE N12</option>
                                                    <option value="SPARADRAP 2.5 CM">SPARADRAP 2.5 CM</option>
                                                    <option value="SPARADRAP 5 CM">SPARADRAP 5 CM</option>
                                                    <option value="SPARADRAP 7.5CM">SPARADRAP 7.5CM</option>
                                                    <option value="SPARET DROPS SP 15ML">SPARET DROPS SP 15ML</option>
                                                    <option value="SPASFON SUPPO (EUROP)">SPASFON SUPPO (EUROP)</option>
                                                    <option value="SPASFON CES (EUROP)">SPASFON CES (EUROP)</option>
                                                    <option value="SPASFON INJ (EUROP)">SPASFON INJ (EUROP)</option>
                                                    <option value="SPASMASIF 30ML GOUTTE">SPASMASIF 30ML GOUTTE</option>
                                                    <option value="SPASTA 10 B/10 CES">SPASTA 10 B/10 CES</option>
                                                    <option value="SPASTA 20 B/10 CES">SPASTA 20 B/10 CES</option>
                                                    <option value="STEROVIT GUTTES">STEROVIT GUTTES</option>
                                                    <option value="STIMULEX CAPS">STIMULEX CAPS</option>
                                                    <option value="STIMULEX 100 ML SP">STIMULEX 100 ML SP</option>
                                                    <option value="SUDROX 500MG">SUDROX 500MG</option>
                                                    <option value="SUDROX SP 100ML">SUDROX SP 100ML</option>
                                                    <option value="SULFATE DE FER CES">SULFATE DE FER CES</option>
                                                    <option value="SUPER APETI PLUS SP 200 ML">SUPER APETI PLUS SP 200 ML
                                                    </option>
                                                    <option value="SUPER APETI(CYPRO) 120ML SP">SUPER APETI(CYPRO) 120ML SP
                                                    </option>
                                                    <option value="SUPER APETI(CYPRO)4 MG CES">SUPER APETI(CYPRO)4 MG CES
                                                    </option>
                                                    <option value="SUPER APPETIT PLUS CES">SUPER APPETIT PLUS CES</option>
                                                    <option value="TANZOL 10ML SP">TANZOL 10ML SP</option>
                                                    <option value="TANZOL CES">TANZOL CES</option>
                                                    <option value="TAZEX 1125 MG INJ">TAZEX 1125 MG INJ</option>
                                                    <option value="TEGRETOL 200 MG CES (EUROP)">TEGRETOL 200 MG CES (EUROP)
                                                    </option>
                                                    <option value="TENORMIN 100 MG CES (EUROP)">TENORMIN 100 MG CES (EUROP)
                                                    </option>
                                                    <option value="TENSIONMETRE AVEC STETOSCOP">TENSIONMETRE AVEC STETOSCOP
                                                    </option>
                                                    <option value="TENSIONMETRE DIGITAL G.F">TENSIONMETRE DIGITAL G.F
                                                    </option>
                                                    <option value="TERPIN CODEIN CES">TERPIN CODEIN CES</option>
                                                    <option value="TERPON 180 ML SP (AD) (EUROP)">TERPON 180 ML SP (AD)
                                                        (EUROP)</option>
                                                    <option value="TERPONE 180 ML SP (ENF) (EUROP)">TERPONE 180 ML SP (ENF)
                                                        (EUROP)</option>
                                                    <option value="TERRA-CORTRIL PDE OPHT 3.5 GR (EUROP)">TERRA-CORTRIL PDE
                                                        OPHT 3.5 GR (EUROP)</option>
                                                    <option value="TEST DE GROSSESSE">TEST DE GROSSESSE</option>
                                                    <option value="TESTOSTERONE INJ">TESTOSTERONE INJ</option>
                                                    <option value="TETRA 250 MG CAPS">TETRA 250 MG CAPS</option>
                                                    <option value="THERMOMETRE DIGITAL">THERMOMETRE DIGITAL</option>
                                                    <option value="TIMOLOL GENERIC">TIMOLOL GENERIC</option>
                                                    <option value="TIMOLOL..0.5% COLLYRE (EUROP)">TIMOLOL..0.5% COLLYRE
                                                        (EUROP)</option>
                                                    <option value="TIMOPTOL 0.5% COLLYRE 5 ML (EUROP)">TIMOPTOL 0.5% COLLYRE
                                                        5 ML (EUROP)</option>
                                                    <option value="TINIZOL 500 CES">TINIZOL 500 CES</option>
                                                    <option value="TOT HEMA 10ML BUV AMP B/10 (EUROP)">TOT HEMA 10ML BUV AMP
                                                        B/10 (EUROP)</option>
                                                    <option value="TRACOL 500MG 3*10 CES">TRACOL 500MG 3*10 CES</option>
                                                    <option value="TRAMADOL CAPS">TRAMADOL CAPS</option>
                                                    <option value="TRAMADOL 2 ML INJ 5AMP">TRAMADOL 2 ML INJ 5AMP</option>
                                                    <option value="TRANXENE 10MG 30 GELULES">TRANXENE 10MG 30 GELULES
                                                    </option>
                                                    <option value="TRANXENE 5MG 30 GELULES">TRANXENE 5MG 30 GELULES</option>
                                                    <option value="TRI WELL SP 100 ML">TRI WELL SP 100 ML</option>
                                                    <option value="TRIBEM INJ">TRIBEM INJ</option>
                                                    <option value="TRIBEM CES 30 CES">TRIBEM CES 30 CES</option>
                                                    <option value="TRIBEX FORT 30DR">TRIBEX FORT 30DR</option>
                                                    <option value="TRIOKIT">TRIOKIT</option>
                                                    <option value="TRIOMEGA CES">TRIOMEGA CES</option>
                                                    <option value="TRITAZIDE 10/12.5MG CES (EUROP)">TRITAZIDE 10/12.5MG CES
                                                        (EUROP)</option>
                                                    <option value="TRITAZIDE 10/25MG CES (EUROP)">TRITAZIDE 10/25MG CES
                                                        (EUROP)</option>
                                                    <option value="TROBIKEN INJ + H20 DISTILLE 2MG">TROBIKEN INJ + H20
                                                        DISTILLE 2MG</option>
                                                    <option value="TROUSSE A TRANSFUSION">TROUSSE A TRANSFUSION</option>
                                                    <option value="TROUSSE A PERFUSION">TROUSSE A PERFUSION</option>
                                                    <option value="TSHEKA -100 MG 4 CES">TSHEKA -100 MG 4 CES</option>
                                                    <option value="TSHEKA- 100 MG ORAL JELLY">TSHEKA- 100 MG ORAL JELLY
                                                    </option>
                                                    <option value="TULLE GRAS">TULLE GRAS</option>
                                                    <option value="TUSSIPLEX (ANTI TOUX) 100 ML SP">TUSSIPLEX (ANTI TOUX)
                                                        100 ML SP</option>
                                                    <option value="UTROGESTAN 200MG CAPS (EUROP)">UTROGESTAN 200MG CAPS
                                                        (EUROP)</option>
                                                    <option value="VAGYNAX OV">VAGYNAX OV</option>
                                                    <option value="VASOCAR -T B/14 CES">VASOCAR -T B/14 CES</option>
                                                    <option value="VASOCAR 10 B/14 CES">VASOCAR 10 B/14 CES</option>
                                                    <option value="VASOCAR T 28 CES">VASOCAR T 28 CES</option>
                                                    <option value="VASOCAR- 5 CES">VASOCAR- 5 CES</option>
                                                    <option value="VASOCAR-TH3*10 CES">VASOCAR-TH3*10 CES</option>
                                                    <option value="VENTOLINE SPRAY (EUROP)">VENTOLINE SPRAY (EUROP)</option>
                                                    <option value="VERMOX 100 MG CES (EUROP)">VERMOX 100 MG CES (EUROP)
                                                    </option>
                                                    <option value="VERMOX 30 ML SP 100 MG (EUROP)">VERMOX 30 ML SP 100 MG
                                                        (EUROP)</option>
                                                    <option value="VIFEX SP 100ML">VIFEX SP 100ML</option>
                                                    <option value="VIKAS BOMBOM CES">VIKAS BOMBOM CES</option>
                                                    <option value="VILONG CES">VILONG CES</option>
                                                    <option value="VITALO PLUS 200ML SYRUP">VITALO PLUS 200ML SYRUP</option>
                                                    <option value="VITAMIN 4 SP 100 ML">VITAMIN 4 SP 100 ML</option>
                                                    <option value="VITAMIN- 4 CES 10*10">VITAMIN- 4 CES 10*10</option>
                                                    <option value="VITAMINE B COMPEX 100 ML SP">VITAMINE B COMPEX 100 ML SP
                                                    </option>
                                                    <option value="VITAMINE B COMPLEX 2 ML INJ">VITAMINE B COMPLEX 2 ML INJ
                                                    </option>
                                                    <option value="VITAMINE B COMPLEXE CES">VITAMINE B COMPLEXE CES</option>
                                                    <option value="VITAMINE B1 100MG CES">VITAMINE B1 100MG CES</option>
                                                    <option value="VITAMINE B1 INJ">VITAMINE B1 INJ</option>
                                                    <option value="VITAMINE B12 INJ">VITAMINE B12 INJ</option>
                                                    <option value="VITAMINE B6 CES">VITAMINE B6 CES</option>
                                                    <option value="VITAMINE B6 2 ML INJ">VITAMINE B6 2 ML INJ</option>
                                                    <option value="VITAMINE C 100 ML SP">VITAMINE C 100 ML SP</option>
                                                    <option value="VITAMINE C 5 ML INJ">VITAMINE C 5 ML INJ</option>
                                                    <option value="VITAMINE C 500MG CES">VITAMINE C 500MG CES</option>
                                                    <option value="VITAMINE K1 INJ">VITAMINE K1 INJ</option>
                                                    <option value="VITAMINE K3 INJ">VITAMINE K3 INJ</option>
                                                    <option value="VITRON-Z CES">VITRON-Z CES</option>
                                                    <option value="VITRON-Z SP">VITRON-Z SP</option>
                                                    <option value="VOLPA 10*10 CES">VOLPA 10*10 CES</option>
                                                    <option value="VOLPA PLUS CES 10*10">VOLPA PLUS CES 10*10</option>
                                                    <option value="VOLTARENE 100MG SUPPOS (EUROP)">VOLTARENE 100MG SUPPOS
                                                        (EUROP)</option>
                                                    <option value="VOLTARENE 50MG CES (EUROP)">VOLTARENE 50MG CES (EUROP)
                                                    </option>
                                                    <option value="WEL PLUS+ 10 CES">WEL PLUS+ 10 CES</option>
                                                    <option value="WORMEX 100 MG CES">WORMEX 100 MG CES</option>
                                                    <option value="WORMEX 30 ML SP">WORMEX 30 ML SP</option>
                                                    <option value="WORMEX 500 MG CES">WORMEX 500 MG CES</option>
                                                    <option value="X-1 CES">X-1 CES</option>
                                                    <option value="ZEBU+ 10 CES">ZEBU+ 10 CES</option>
                                                    <option value="ZEBU+ GEL 30 GM">ZEBU+ GEL 30 GM</option>
                                                    <option value="ZEBU+100ML SP">ZEBU+100ML SP</option>
                                                    <option value="ZENDEX CES">ZENDEX CES</option>
                                                    <option value="ZENKOF CES">ZENKOF CES</option>
                                                    <option value="ZENKOF SP">ZENKOF SP</option>
                                                    <option value="ZOPEX 5 MG 10 CES">ZOPEX 5 MG 10 CES</option>
                                                    <option value="ZOPEX F 10 CES">ZOPEX F 10 CES</option>
                                                    <option value="ZYLORIC 100MG CES (EUROP)">ZYLORIC 100MG CES (EUROP)
                                                    </option>
                                                </select>
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
                                                                    (<?php
                                                                    $note = isset($fournisseur['note_qualite']) ? (float) $fournisseur['note_qualite'] : 0.0;
                                                                    echo number_format($note, 1);
                                                                    ?>/5)
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

    <!-- Modal Modifier Fournisseur -->
    <div id="modalModifierFournisseur"
        class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div
            class="bg-gradient-to-br from-white to-blue-50 rounded-2xl shadow-2xl w-full max-w-md transform transition-all duration-300 scale-95 opacity-0">
            <div class="p-6 border-b border-blue-200">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="p-3 bg-gradient-to-br from-blue-100 to-blue-200 rounded-xl mr-3">
                            <i class="fas fa-edit text-blue-600"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-800">Modifier le fournisseur</h3>
                    </div>
                    <button onclick="fermerModal('modalModifierFournisseur')"
                        class="p-2 hover:bg-blue-100 rounded-full transition-colors">
                        <i class="fas fa-times text-gray-500 hover:text-gray-700"></i>
                    </button>
                </div>
                <p class="text-gray-600 mt-2" id="modalModifierNomFournisseur"></p>
            </div>

            <div class="p-6">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Nom de société</label>
                        <input type="text" id="nouveauNomSociete" name="nom_societe"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Contact principal</label>
                        <input type="text" id="nouveauContact" name="contact_principal"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>


                </div>

                <div class="flex justify-end space-x-3 mt-8 pt-6 border-t border-gray-200">
                    <button onclick="fermerModal('modalModifierFournisseur')"
                        class="px-5 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors font-medium">
                        Annuler
                    </button>
                    <button onclick="sauvegarderModification()"
                        class="px-5 py-2 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white rounded-lg shadow hover:shadow-md transition-all duration-200 font-medium">
                        Sauvegarder
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Noter Fournisseur -->
    <div id="modalNoterFournisseur"
        class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div
            class="bg-gradient-to-br from-white to-yellow-50 rounded-2xl shadow-2xl w-full max-w-md transform transition-all duration-300 scale-95 opacity-0">
            <div class="p-6 border-b border-yellow-200">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="p-3 bg-gradient-to-br from-yellow-100 to-yellow-200 rounded-xl mr-3">
                            <i class="fas fa-star text-yellow-600"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-800">Noter le fournisseur</h3>
                    </div>
                    <button onclick="fermerModal('modalNoterFournisseur')"
                        class="p-2 hover:bg-yellow-100 rounded-full transition-colors">
                        <i class="fas fa-times text-gray-500 hover:text-gray-700"></i>
                    </button>
                </div>
                <p class="text-gray-600 mt-2" id="modalNoterNomFournisseur"></p>
            </div>

            <div class="p-6">
                <div class="text-center mb-6">
                    <div class="flex justify-center space-x-1 mb-4" id="starsContainer">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <button type="button" data-value="<?php echo $i; ?>"
                                class="star-btn text-3xl transition-all duration-200 hover:scale-110 focus:outline-none">
                                <i class="fas fa-star text-gray-300 hover:text-yellow-400"></i>
                            </button>
                        <?php endfor; ?>
                    </div>
                    <div class="text-sm text-gray-600" id="starRatingText">Cliquez sur les étoiles pour noter</div>
                    <input type="hidden" id="selectedRating" value="0">
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Commentaire (optionnel)</label>
                    <textarea id="ratingComment" rows="3"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:border-transparent resize-none"
                        placeholder="Ajouter un commentaire sur la qualité..."></textarea>
                </div>

                <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200">
                    <button onclick="fermerModal('modalNoterFournisseur')"
                        class="px-5 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors font-medium">
                        Annuler
                    </button>
                    <button onclick="sauvegarderNotation()"
                        class="px-5 py-2 bg-gradient-to-r from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700 text-white rounded-lg shadow hover:shadow-md transition-all duration-200 font-medium">
                        Enregistrer la note
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Détails Fournisseur -->
    <div id="modalDetailsFournisseur"
        class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div
            class="bg-gradient-to-br from-white to-emerald-50 rounded-2xl shadow-2xl w-full max-w-lg transform transition-all duration-300 scale-95 opacity-0">
            <div class="p-6 border-b border-emerald-200">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="p-3 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl mr-3">
                            <i class="fas fa-info-circle text-emerald-600"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-800">Détails du fournisseur</h3>
                    </div>
                    <button onclick="fermerModal('modalDetailsFournisseur')"
                        class="p-2 hover:bg-emerald-100 rounded-full transition-colors">
                        <i class="fas fa-times text-gray-500 hover:text-gray-700"></i>
                    </button>
                </div>
            </div>

            <div class="p-6" id="fournisseurDetailsContent">
                <!-- Les détails seront chargés ici dynamiquement -->
                <div class="text-center py-8">
                    <div
                        class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gradient-to-r from-emerald-100 to-emerald-200 mb-4">
                        <i class="fas fa-spinner fa-spin text-emerald-600 text-2xl"></i>
                    </div>
                    <p class="text-gray-600">Chargement des détails...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Variables globales
        let currentFournisseurId = null;
        let currentFournisseurNom = '';

        // Fonctions d'ouverture de modals
        async function modifierFournisseur(id, nom) {
            currentFournisseurId = id;
            currentFournisseurNom = nom;

            // Charger les données actuelles via API
            try {
                const response = await fetch(`?api=fournisseur_details&id=${id}`);
                const data = await response.json();

                if (data.success) {
                    document.getElementById('nouveauNomSociete').value = data.data.nom_societe;
                    document.getElementById('nouveauContact').value = data.data.contact_principal;
                    document.getElementById('nouveauEmail').value = data.data.email || '';
                    document.getElementById('nouveauTelephone').value = data.data.telephone || '';
                }
            } catch (error) {
                console.error('Erreur lors du chargement des données:', error);
            }

            document.getElementById('modalModifierNomFournisseur').textContent = nom;
            ouvrirModal('modalModifierFournisseur');
        }

        function noterFournisseur(id, nom) {
            currentFournisseurId = id;
            currentFournisseurNom = nom;
            document.getElementById('modalNoterNomFournisseur').textContent = nom;
            resetStars();
            ouvrirModal('modalNoterFournisseur');
        }

        function voirDetailsFournisseur(id) {
            currentFournisseurId = id;
            ouvrirModal('modalDetailsFournisseur');
            chargerDetailsFournisseur(id);
        }

        // Fonctions de gestion des modals
        function ouvrirModal(modalId) {
            const modal = document.getElementById(modalId);
            const modalContent = modal.querySelector('div > div');

            modal.classList.remove('hidden');
            setTimeout(() => {
                modalContent.style.opacity = '1';
                modalContent.style.transform = 'scale(1)';
            }, 10);

            // Fermer la modal avec ESC
            document.addEventListener('keydown', function fermerEsc(e) {
                if (e.key === 'Escape') {
                    fermerModal(modalId);
                    document.removeEventListener('keydown', fermerEsc);
                }
            });
        }

        function fermerModal(modalId) {
            const modal = document.getElementById(modalId);
            const modalContent = modal.querySelector('div > div');

            modalContent.style.opacity = '0';
            modalContent.style.transform = 'scale(0.95)';

            setTimeout(() => {
                modal.classList.add('hidden');
                currentFournisseurId = null;
                currentFournisseurNom = '';
            }, 300);
        }

        // Fonctions pour les étoiles de notation
        function resetStars() {
            const stars = document.querySelectorAll('.star-btn i');
            stars.forEach(star => {
                star.classList.remove('text-yellow-500');
                star.classList.add('text-gray-300');
            });
            document.getElementById('selectedRating').value = 0;
            document.getElementById('starRatingText').textContent = 'Cliquez sur les étoiles pour noter';

            // Réinitialiser les événements
            stars.forEach((star, index) => {
                star.parentElement.onclick = () => selectionnerEtoile(index + 1);
            });
        }

        function selectionnerEtoile(rating) {
            const stars = document.querySelectorAll('.star-btn i');
            document.getElementById('selectedRating').value = rating;

            stars.forEach((star, index) => {
                if (index < rating) {
                    star.classList.remove('text-gray-300');
                    star.classList.add('text-yellow-500');
                } else {
                    star.classList.remove('text-yellow-500');
                    star.classList.add('text-gray-300');
                }
            });

            const texts = ['Très mauvais', 'Mauvais', 'Moyen', 'Bon', 'Excellent'];
            document.getElementById('starRatingText').textContent = texts[rating - 1] || 'Cliquez sur les étoiles pour noter';
        }

        // Fonction pour charger les détails du fournisseur
        async function chargerDetailsFournisseur(id) {
            const contentElement = document.getElementById('fournisseurDetailsContent');

            // Afficher un indicateur de chargement
            contentElement.innerHTML = `
        <div class="text-center py-12">
            <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-r from-blue-100 to-blue-200 mb-6">
                <i class="fas fa-spinner fa-spin text-blue-600 text-2xl"></i>
            </div>
            <h4 class="text-lg font-bold text-gray-800 mb-2">Chargement en cours</h4>
            <p class="text-gray-600">Récupération des informations du fournisseur...</p>
        </div>
    `;

            try {
                // Utiliser une URL relative correcte
                const response = await fetch(`?api=fournisseur_details&id=${id}&t=${Date.now()}`, {
                    headers: {
                        'Accept': 'application/json',
                        'Cache-Control': 'no-cache'
                    }
                });

                if (!response.ok) {
                    throw new Error(`Erreur HTTP: ${response.status}`);
                }

                const data = await response.json();
                console.log('Données reçues:', data); // Pour le débogage

                if (data.success && data.data) {
                    const details = data.data;

                    // Construire la liste des produits
                    let produitsHTML = '';
                    if (details.nombre_produits > 0 && details.produits_liste && details.produits_liste !== 'Aucun produit') {
                        const produits = details.produits_liste.split('; ');
                        produitsHTML = `
                    <div class="mt-6">
                        <div class="flex items-center mb-3">
                            <i class="fas fa-capsules text-emerald-600 mr-2"></i>
                            <h6 class="font-semibold text-gray-700">Produits fournis</h6>
                            <span class="ml-2 px-2 py-1 bg-emerald-100 text-emerald-700 text-xs rounded-full font-bold">
                                ${details.nombre_produits}
                            </span>
                        </div>
                        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 max-h-40 overflow-y-auto">
                            <ul class="space-y-2">
                                ${produits.map(p => `<li class="flex items-center text-sm">
                                    <i class="fas fa-caret-right text-emerald-500 mr-2 text-xs"></i>
                                    <span class="text-gray-700">${p}</span>
                                </li>`).join('')}
                            </ul>
                        </div>
                    </div>
                `;
                    } else {
                        produitsHTML = `
                    <div class="mt-6 p-4 bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg border border-gray-200">
                        <div class="flex items-center">
                            <i class="fas fa-info-circle text-gray-400 mr-2"></i>
                            <span class="text-gray-600 text-sm">Aucun produit fourni par ce partenaire</span>
                        </div>
                    </div>
                `;
                    }

                    // Construire les étoiles pour la note
                    function generateStars(rating) {
                        const fullStars = Math.floor(rating);
                        const halfStar = rating % 1 >= 0.5;
                        let starsHTML = '';

                        for (let i = 1; i <= 5; i++) {
                            if (i <= fullStars) {
                                starsHTML += '<i class="fas fa-star text-yellow-500 mx-0.5"></i>';
                            } else if (i === fullStars + 1 && halfStar) {
                                starsHTML += '<i class="fas fa-star-half-alt text-yellow-500 mx-0.5"></i>';
                            } else {
                                starsHTML += '<i class="far fa-star text-yellow-500 mx-0.5"></i>';
                            }
                        }
                        return starsHTML;
                    }

                    // Mettre à jour le contenu du modal
                    contentElement.innerHTML = `
                <div class="space-y-6">
                    <!-- En-tête -->
                    <div class="flex items-start">
                        <div class="p-4 bg-gradient-to-br from-emerald-100 to-emerald-200 rounded-xl mr-4 shadow-sm">
                            <i class="fas fa-warehouse text-emerald-600 text-2xl"></i>
                        </div>
                        <div class="flex-1">
                            <h4 class="text-xl font-bold text-gray-800 mb-1">${details.nom_societe}</h4>
                            <div class="flex items-center text-emerald-600 font-medium mb-2">
                                <i class="fas fa-user mr-2 text-sm"></i>
                                ${details.contact_principal}
                            </div>
                            <div class="flex items-center text-xs text-gray-500 bg-gray-50 px-3 py-1 rounded-lg inline-block">
                                <i class="fas fa-calendar-alt mr-1"></i>
                                Partenaire depuis ${details.created_at}
                            </div>
                        </div>
                    </div>
                    
                    <!-- Note et statistiques -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-gradient-to-br from-yellow-50 to-yellow-100 p-4 rounded-xl border border-yellow-200">
                            <div class="flex flex-col items-center">
                                <div class="text-sm text-yellow-700 mb-2 font-medium">Note qualité</div>
                                <div class="text-3xl font-bold text-yellow-800 mb-2">${details.note_qualite}/5</div>
                                <div class="text-yellow-500 text-lg">
                                    ${generateStars(parseFloat(details.note_qualite))}
                                </div>
                            </div>
                        </div>
                        <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-xl border border-blue-200">
                            <div class="flex flex-col items-center">
                                <div class="text-sm text-blue-700 mb-2 font-medium">Produits fournis</div>
                                <div class="text-3xl font-bold text-blue-800">${details.nombre_produits}</div>
                                <div class="text-xs text-blue-600 mt-2">en catalogue</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Informations de contact -->
                    <div class="space-y-3">

                        <div class="bg-gradient-to-r from-gray-50 to-gray-100 p-4 rounded-lg">
                            <div class="flex items-center mb-2">
                                <i class="fas fa-map-marker-alt text-gray-400 mr-3 w-5"></i>
                                <span class="text-gray-700 font-medium">Adresse</span>
                            </div>
                            <div class="text-gray-900 pl-8 text-sm">${details.adresse_siege}</div>
                        </div>
                    </div>
                    
                    <!-- Liste des produits -->
                    ${produitsHTML}
                    
                    <!-- Actions -->
                    <div class="flex justify-center space-x-4 pt-6 border-t border-gray-200">
                        <button onclick="modifierFournisseur(${id}, '${details.nom_societe.replace(/'/g, "\\'")}')"
                            class="group relative px-5 py-2.5 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 overflow-hidden flex items-center">
                            <div class="absolute inset-0 bg-white opacity-0 group-hover:opacity-10 transition-opacity duration-200"></div>
                            <i class="fas fa-edit mr-2"></i>
                            Modifier
                        </button>
                        <button onclick="noterFournisseur(${id}, '${details.nom_societe.replace(/'/g, "\\'")}')"
                            class="group relative px-5 py-2.5 bg-gradient-to-r from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700 text-white rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 overflow-hidden flex items-center">
                            <div class="absolute inset-0 bg-white opacity-0 group-hover:opacity-10 transition-opacity duration-200"></div>
                            <i class="fas fa-star mr-2"></i>
                            Noter
                        </button>
                    </div>
                </div>
            `;

                } else {
                    // Si la réponse API indique une erreur
                    contentElement.innerHTML = `
                <div class="text-center py-8">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gradient-to-r from-yellow-100 to-yellow-200 mb-4">
                        <i class="fas fa-exclamation-triangle text-yellow-600 text-2xl"></i>
                    </div>
                    <h4 class="text-lg font-bold text-gray-800 mb-2">Information non disponible</h4>
                    <p class="text-gray-600 mb-4">${data.message || 'Les détails de ce fournisseur ne sont pas disponibles.'}</p>
                    <button onclick="fermerModal('modalDetailsFournisseur')"
                        class="px-5 py-2.5 bg-gradient-to-r from-gray-500 to-gray-600 hover:from-gray-600 hover:to-gray-700 text-white rounded-lg font-medium shadow">
                        Fermer
                    </button>
                </div>
            `;
                }

            } catch (error) {
                console.error('Erreur lors du chargement des détails:', error);

                contentElement.innerHTML = `
            <div class="text-center py-8">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gradient-to-r from-red-100 to-red-200 mb-4">
                    <i class="fas fa-exclamation-circle text-red-600 text-2xl"></i>
                </div>
                <h4 class="text-lg font-bold text-gray-800 mb-2">Erreur de connexion</h4>
                <p class="text-gray-600 mb-4">Impossible de charger les détails du fournisseur.</p>
                <div class="flex justify-center space-x-3">
                    <button onclick="chargerDetailsFournisseur(${id})"
                        class="px-4 py-2 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white rounded-lg font-medium flex items-center">
                        <i class="fas fa-redo mr-2"></i>Réessayer
                    </button>
                    <button onclick="fermerModal('modalDetailsFournisseur')"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                        Fermer
                    </button>
                </div>
                <div class="mt-4 text-xs text-gray-500">
                    Erreur technique: ${error.message}
                </div>
            </div>
        `;
            }
        }

        // Fonction utilitaire pour générer les étoiles
        function generateStars(rating) {
            let stars = '';
            for (let i = 1; i <= 5; i++) {
                if (i <= rating) {
                    stars += '<i class="fas fa-star text-yellow-500"></i>';
                } else if (i - 0.5 <= rating) {
                    stars += '<i class="fas fa-star-half-alt text-yellow-500"></i>';
                } else {
                    stars += '<i class="far fa-star text-yellow-500"></i>';
                }
            }
            return stars;
        }


        // Fonctions de sauvegarde
        function sauvegarderModification() {
            if (currentFournisseurId) {
                const nouveauNom = document.getElementById('nouveauNomSociete').value;
                const nouveauContact = document.getElementById('nouveauContact').value;

                // Créer un formulaire pour soumettre les données
                let form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                form.style.display = 'none';

                // Ajouter les champs cachés
                form.innerHTML = `
            <input type="hidden" name="action" value="modifier_fournisseur">
            <input type="hidden" name="fournisseur_id" value="${currentFournisseurId}">
            <input type="hidden" name="nom_societe" value="${nouveauNom}">
            <input type="hidden" name="contact_principal" value="${nouveauContact}">
            <!-- Ajoutez d'autres champs si nécessaire -->
        `;

                document.body.appendChild(form);
                form.submit();
            }
        }

        function sauvegarderNotation() {
            if (currentFournisseurId) {
                const note = document.getElementById('selectedRating').value;
                const commentaire = document.getElementById('ratingComment').value;

                if (note > 0) {
                    // Créer un formulaire pour soumettre les données
                    let form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    form.style.display = 'none';

                    // IMPORTANT: note_qualite va dans la colonne note_qualite
                    form.innerHTML = `
                <input type="hidden" name="action" value="noter_fournisseur">
                <input type="hidden" name="fournisseur_id" value="${currentFournisseurId}">
                <input type="hidden" name="note_qualite" value="${note}">
                <input type="hidden" name="commentaire" value="${commentaire}">
            `;

                    document.body.appendChild(form);
                    form.submit();
                } else {
                    afficherMessage('error', 'Veuillez sélectionner une note');
                }
            }
        }

        // Fonction pour afficher les messages
        function afficherMessage(type, message) {
            const container = document.createElement('div');
            container.className = `fixed top-4 right-4 z-50 transform transition-all duration-300 translate-x-full`;

            const colors = {
                success: 'from-green-500 to-green-600',
                error: 'from-red-500 to-red-600',
                warning: 'from-yellow-500 to-yellow-600',
                info: 'from-blue-500 to-blue-600'
            };

            container.innerHTML = `
            <div class="bg-gradient-to-r ${colors[type]} text-white px-6 py-4 rounded-xl shadow-lg flex items-center min-w-64">
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'} mr-3 text-xl"></i>
                <div class="flex-1">
                    <div class="font-medium">${message}</div>
                </div>
                <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white hover:text-gray-200">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

            document.body.appendChild(container);

            setTimeout(() => {
                container.style.transform = 'translateX(0)';
            }, 10);

            setTimeout(() => {
                container.style.transform = 'translateX(100%)';
                setTimeout(() => container.remove(), 300);
            }, 5000);
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function () {
            // Initialiser les étoiles
            const starButtons = document.querySelectorAll('.star-btn');
            starButtons.forEach((btn, index) => {
                btn.onclick = () => selectionnerEtoile(index + 1);
            });

            // Fermer les modals en cliquant en dehors
            document.querySelectorAll('[id^="modal"]').forEach(modal => {
                modal.addEventListener('click', function (e) {
                    if (e.target === this) {
                        fermerModal(this.id);
                    }
                });
            });
        });

        // Fonctions existantes (garder les anciennes fonctions)
        function validerProduit(id) {
            if (confirm('Êtes-vous sûr de vouloir valider ce produit pour la vente ?')) {
                let form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                form.innerHTML = `
                <input type="hidden" name="action" value="valider_produit">
                <input type="hidden" name="produit_id" value="${id}">
            `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function archiverProduit(id) {
            if (confirm('Êtes-vous sûr de vouloir archiver ce produit ?\n\nIl ne sera plus disponible pour la vente.')) {
                let form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                form.innerHTML = `
                <input type="hidden" name="action" value="archiver_produit">
                <input type="hidden" name="produit_id" value="${id}">
            `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function toggleAjoutCategorie() {
            let form = document.getElementById('formAjoutCategorie');
            form.classList.toggle('hidden');
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

        // Fonction pour charger les détails du produit
        async function chargerDetailsProduit(id) {
            const contentElement = document.getElementById('detailsProduitContent');

            // Afficher un indicateur de chargement
            contentElement.innerHTML = `
        <div class="text-center py-12">
            <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-gradient-to-r from-blue-100 to-blue-200 mb-6">
                <i class="fas fa-spinner fa-spin text-blue-600 text-2xl"></i>
            </div>
            <h4 class="text-lg font-bold text-gray-800 mb-2">Chargement en cours</h4>
            <p class="text-gray-600">Récupération des informations du produit...</p>
        </div>
    `;

            try {
                // Faire une requête AJAX pour obtenir les détails du produit
                const response = await fetch(`?api=produit_details&id=${id}`);
                const data = await response.json();

                if (data.success) {
                    const produit = data.data;
                    contentElement.innerHTML = genererDetailsProduit(produit);
                } else {
                    contentElement.innerHTML = genererErreurDetails(data.message || 'Impossible de charger les détails');
                }
            } catch (error) {
                console.error('Erreur:', error);
                contentElement.innerHTML = genererErreurDetails('Erreur de connexion au serveur');
            }
        }

        // Fonction pour générer l'affichage des détails
        function genererDetailsProduit(produit) {
            // Formatage de la date
            const dateAjout = new Date(produit.created_at).toLocaleDateString('fr-FR', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });

            // Déterminer le badge de statut
            let statusBadge = '';
            if (produit.statut === 'actif') {
                statusBadge = '<span class="px-3 py-1 bg-gradient-to-r from-emerald-100 to-emerald-200 text-emerald-800 rounded-full text-sm font-semibold border border-emerald-300">Actif</span>';
            } else if (produit.statut === 'en_attente') {
                statusBadge = '<span class="px-3 py-1 bg-gradient-to-r from-yellow-100 to-yellow-200 text-yellow-800 rounded-full text-sm font-semibold border border-yellow-300">En attente</span>';
            } else {
                statusBadge = '<span class="px-3 py-1 bg-gradient-to-r from-red-100 to-red-200 text-red-800 rounded-full text-sm font-semibold border border-red-300">Inactif</span>';
            }

            // Badge ordonnance
            const ordonnanceBadge = produit.necessite_ordonnance
                ? '<span class="inline-flex items-center px-2 py-1 bg-gradient-to-r from-purple-100 to-purple-200 text-purple-800 rounded-full text-xs font-semibold border border-purple-300"><i class="fas fa-file-prescription mr-1 text-xs"></i> Ordonnance</span>'
                : '';

            return `
        <div class="space-y-6">
            <!-- En-tête avec ID -->
            <div class="flex items-start justify-between">
                <div class="flex items-start">
                    <div class="p-4 bg-gradient-to-br from-blue-100 to-blue-200 rounded-xl mr-4 shadow-sm">
                        <i class="fas fa-capsules text-blue-600 text-2xl"></i>
                    </div>
                    <div>
                        <h4 class="text-xl font-bold text-gray-800 mb-1">${produit.nom}</h4>
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-sm text-blue-600 bg-blue-50 px-2 py-1 rounded font-mono">
                                #${produit.id.toString().padStart(4, '0')}
                            </span>
                            ${ordonnanceBadge}
                        </div>
                    </div>
                </div>
                ${statusBadge}
            </div>
            
            <!-- Informations principales -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-gradient-to-br from-gray-50 to-gray-100 p-4 rounded-xl border border-gray-200">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-folder text-gray-400 mr-2"></i>
                        <div class="text-sm text-gray-600">Catégorie</div>
                    </div>
                    <div class="font-semibold text-gray-800">${produit.categorie_nom || 'Non spécifiée'}</div>
                </div>
                
                <div class="bg-gradient-to-br from-gray-50 to-gray-100 p-4 rounded-xl border border-gray-200">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-truck text-gray-400 mr-2"></i>
                        <div class="text-sm text-gray-600">Fournisseur</div>
                    </div>
                    <div class="font-semibold text-gray-800">${produit.fournisseur_nom || 'Non spécifié'}</div>
                </div>
            </div>
            
            <!-- Code barre -->
            ${produit.code_barre ? `
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-xl border border-blue-200">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="flex items-center text-sm text-blue-600 mb-1">
                                <i class="fas fa-barcode mr-2"></i>
                                Code barre
                            </div>
                            <div class="font-mono font-bold text-gray-800 text-lg">${produit.code_barre}</div>
                        </div>
                        <i class="fas fa-barcode text-blue-400 text-2xl"></i>
                    </div>
                </div>
            ` : ''}
            
            <!-- Dates -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-gradient-to-br from-gray-50 to-gray-100 p-4 rounded-xl border border-gray-200">
                    <div class="flex items-center mb-2">
                        <i class="far fa-calendar-plus text-gray-400 mr-2"></i>
                        <div class="text-sm text-gray-600">Date d'ajout</div>
                    </div>
                    <div class="text-gray-700">${dateAjout}</div>
                </div>
                
                ${produit.updated_at && produit.updated_at !== produit.created_at ? `
                <div class="bg-gradient-to-br from-gray-50 to-gray-100 p-4 rounded-xl border border-gray-200">
                    <div class="flex items-center mb-2">
                        <i class="far fa-calendar-check text-gray-400 mr-2"></i>
                        <div class="text-sm text-gray-600">Dernière modification</div>
                    </div>
                    <div class="text-gray-700">${new Date(produit.updated_at).toLocaleDateString('fr-FR')}</div>
                </div>
                ` : ''}
            </div>
            
            <!-- Description -->
            ${produit.description ? `
                <div class="bg-gradient-to-br from-gray-50 to-gray-100 p-4 rounded-xl border border-gray-200">
                    <div class="flex items-center mb-3">
                        <i class="fas fa-align-left text-gray-400 mr-2"></i>
                        <div class="text-sm font-semibold text-gray-700">Description</div>
                    </div>
                    <div class="text-gray-700 whitespace-pre-line">${produit.description}</div>
                </div>
            ` : ''}
            
            <!-- Informations médicales -->
            <div class="bg-gradient-to-br from-red-50 to-red-100 p-4 rounded-xl border border-red-200">
                <div class="flex items-center mb-3">
                    <i class="fas fa-stethoscope text-red-600 mr-2"></i>
                    <div class="text-sm font-semibold text-red-700">Informations médicales</div>
                </div>
                
                <div class="space-y-3">
                    ${produit.composition ? `
                        <div>
                            <div class="text-xs text-gray-600 mb-1">Composition</div>
                            <div class="text-sm text-gray-700">${produit.composition}</div>
                        </div>
                    ` : ''}
                    
                    ${produit.posologie ? `
                        <div>
                            <div class="text-xs text-gray-600 mb-1">Posologie</div>
                            <div class="text-sm text-gray-700">${produit.posologie}</div>
                        </div>
                    ` : ''}
                    
                    ${produit.contre_indications ? `
                        <div>
                            <div class="text-xs text-gray-600 mb-1">Contre-indications</div>
                            <div class="text-sm text-gray-700">${produit.contre_indications}</div>
                        </div>
                    ` : ''}
                </div>
            </div>
            
            <!-- Actions -->
            <div class="flex flex-wrap justify-center gap-4 pt-6 border-t border-gray-200">
                <a href="?page=modifier_produit&id=${produit.id}"
                    class="group relative px-5 py-2.5 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 overflow-hidden flex items-center">
                    <div class="absolute inset-0 bg-white opacity-0 group-hover:opacity-10 transition-opacity duration-200"></div>
                    <i class="fas fa-edit mr-2"></i>
                    Modifier
                </a>
                
                ${produit.statut === 'en_attente' ? `
                    <button onclick="ouvrirModalValider(${produit.id}, '${produit.nom.replace(/'/g, "\\'")}', '${(produit.categorie_nom || '').replace(/'/g, "\\'")}')"
                        class="group relative px-5 py-2.5 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 overflow-hidden flex items-center">
                        <div class="absolute inset-0 bg-white opacity-0 group-hover:opacity-10 transition-opacity duration-200"></div>
                        <i class="fas fa-check mr-2"></i>
                        Valider
                    </button>
                ` : ''}
                
                <button onclick="ouvrirModalArchiver(${produit.id}, '${produit.nom.replace(/'/g, "\\'")}', '${(produit.categorie_nom || '').replace(/'/g, "\\'")}')"
                    class="group relative px-5 py-2.5 bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white rounded-lg font-medium shadow hover:shadow-md transition-all duration-200 overflow-hidden flex items-center">
                    <div class="absolute inset-0 bg-white opacity-0 group-hover:opacity-10 transition-opacity duration-200"></div>
                    <i class="fas fa-archive mr-2"></i>
                    Archiver
                </button>
            </div>
        </div>
    `;
        }

        // Fonction pour afficher une erreur
        function genererErreurDetails(message) {
            return `
        <div class="text-center py-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gradient-to-r from-yellow-100 to-yellow-200 mb-4">
                <i class="fas fa-exclamation-triangle text-yellow-600 text-2xl"></i>
            </div>
            <h4 class="text-lg font-bold text-gray-800 mb-2">Erreur de chargement</h4>
            <p class="text-gray-600 mb-4">${message}</p>
            <button onclick="chargerDetailsProduit(currentProduitId)" 
                class="px-5 py-2.5 bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white rounded-lg font-medium flex items-center mx-auto">
                <i class="fas fa-redo mr-2"></i>Réessayer
            </button>
        </div>
    `;
        }
    </script>
</body>

</html>