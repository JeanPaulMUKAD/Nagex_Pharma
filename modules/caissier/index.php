<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';

// Vérifier l'authentification et le rôle
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'caissier') {
    header('Location: /login.php');
    exit;
}

$db = new Database();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_nom'];
$user_role = $_SESSION['user_role'];

// ============================================================================
// FONCTIONS POUR LA GESTION DES PRIX
// ============================================================================

function getTauxChange()
{
    // Taux par défaut si l'API échoue
    $tauxParDefaut = 2500;

    try {
        // Utilisation d'une API gratuite de taux de change
        $apiUrl = 'https://api.exchangerate-api.com/v4/latest/USD';
        $response = file_get_contents($apiUrl);

        if ($response !== false) {
            $data = json_decode($response, true);
            if (isset($data['rates']['CDF'])) {
                return floatval($data['rates']['CDF']);
            }
        }
    } catch (Exception $e) {
        error_log("Erreur API taux de change: " . $e->getMessage());
    }

    return $tauxParDefaut;
}

function getPrixProduits($db)
{
    try {
        $stmt = $db->prepare("
            SELECT p.id, p.reference, p.nom, 
                   COALESCE(pp.prix_fc, p.prix_vente) as prix_fc,
                   COALESCE(pp.prix_usd, p.prix_vente / 2500) as prix_usd,
                   COALESCE(pp.taux_conversion, 2500) as taux_conversion,
                   pp.date_debut, pp.date_fin
            FROM produits p
            LEFT JOIN prix_vente pp ON p.id = pp.produit_id 
                AND pp.date_fin IS NULL
            WHERE p.actif = 1
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur getPrixProduits: " . $e->getMessage());
        return [];
    }
}

function updatePrixProduit($db, $produit_id, $prix_fc, $prix_usd, $taux_conversion, $user_id)
{
    try {
        // Désactiver l'ancien prix
        $stmt = $db->prepare("
            UPDATE prix_vente 
            SET date_fin = CURDATE() 
            WHERE produit_id = :produit_id AND date_fin IS NULL
        ");
        $stmt->bindParam(':produit_id', $produit_id);
        $stmt->execute();

        // Insérer le nouveau prix
        $stmt = $db->prepare("
            INSERT INTO prix_vente (produit_id, prix_fc, prix_usd, taux_conversion, date_debut, created_by)
            VALUES (:produit_id, :prix_fc, :prix_usd, :taux_conversion, CURDATE(), :user_id)
        ");
        $stmt->bindParam(':produit_id', $produit_id);
        $stmt->bindParam(':prix_fc', $prix_fc);
        $stmt->bindParam(':prix_usd', $prix_usd);
        $stmt->bindParam(':taux_conversion', $taux_conversion);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();

        return true;
    } catch (PDOException $e) {
        error_log("Erreur updatePrixProduit: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// FONCTION POUR RÉCUPÉRER TOUS LES PRODUITS
// ============================================================================

function getAllProduits($db)
{
    try {
        $stmt = $db->prepare("
            SELECT 
                p.id,
                p.reference,
                p.nom,
                p.description,
                p.prix_vente as prix_vente_ht,
                COALESCE(p.taux_tva, 0) as taux_tva,
                (p.prix_vente * (1 + COALESCE(p.taux_tva, 0)/100)) as prix_ttc,
                COALESCE(SUM(s.quantite_actuelle), 0) as stock,
                c.nom as categorie_nom,
                f.nom_societe as fournisseur_nom,
                COALESCE(pp.prix_fc, p.prix_vente) as prix_fc,
                COALESCE(pp.prix_usd, p.prix_vente / 2500) as prix_usd
            FROM produits p
            LEFT JOIN stocks s ON p.id = s.produit_id AND s.statut = 'en_stock'
            LEFT JOIN categories c ON p.categorie_id = c.id
            LEFT JOIN fournisseurs f ON p.fournisseur_id = f.id
            LEFT JOIN prix_vente pp ON p.id = pp.produit_id AND pp.date_fin IS NULL
            WHERE p.statut = 'actif'
            GROUP BY p.id, p.reference, p.nom, p.description, p.prix_vente, p.taux_tva,
                     c.nom, f.nom_societe, pp.prix_fc, pp.prix_usd
            HAVING stock > 0
            ORDER BY p.nom
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur getAllProduits: " . $e->getMessage());
        return [];
    }
}

// Récupérer tous les produits
$all_produits = getAllProduits($db);

function getHistoriquePrix($db, $produit_id = null)
{
    try {
        $sql = "
            SELECT pp.*, p.nom as produit_nom, u.nom as caissier_nom
            FROM prix_vente pp
            JOIN produits p ON pp.produit_id = p.id
            JOIN utilisateurs u ON pp.created_by = u.id
        ";

        if ($produit_id) {
            $sql .= " WHERE pp.produit_id = :produit_id";
        }

        $sql .= " ORDER BY pp.date_debut DESC, pp.created_at DESC";

        $stmt = $db->prepare($sql);

        if ($produit_id) {
            $stmt->bindParam(':produit_id', $produit_id);
        }

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur getHistoriquePrix: " . $e->getMessage());
        return [];
    }
}



// ============================================================================
// FONCTIONS POUR LE TRAITEMENT DES COMMANDES
// ============================================================================

function getCommandesEnAttente($db, $user_id)
{
    try {
        $stmt = $db->prepare("
            SELECT c.*, cl.nom as client_nom, cl.prenom as client_prenom,
                   COUNT(cd.id) as nb_produits
            FROM commandes c
            JOIN utilisateurs cl ON c.client_id = cl.id
            LEFT JOIN commande_details cd ON c.id = cd.commande_id
            WHERE c.statut = 'en_attente'
            AND (c.caissier_id IS NULL OR c.caissier_id = :user_id)
            GROUP BY c.id
            ORDER BY c.date_commande ASC
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur getCommandesEnAttente: " . $e->getMessage());
        return [];
    }
}

function getDetailsCommande($db, $commande_id)
{
    try {
        $stmt = $db->prepare("
            SELECT cd.*, p.nom as produit_nom, p.reference
            FROM commande_details cd
            JOIN produits p ON cd.produit_id = p.id
            WHERE cd.commande_id = :commande_id
        ");
        $stmt->bindParam(':commande_id', $commande_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur getDetailsCommande: " . $e->getMessage());
        return [];
    }
}

function validerCommande($db, $commande_id, $mode_paiement, $user_id)
{
    try {
        $db->beginTransaction();

        // Mettre à jour la commande
        $stmt = $db->prepare("
            UPDATE commandes 
            SET statut = 'paye', 
                caissier_id = :user_id,
                mode_paiement = :mode_paiement,
                date_paiement = NOW()
            WHERE id = :commande_id AND statut = 'en_attente'
        ");
        $stmt->bindParam(':commande_id', $commande_id);
        $stmt->bindParam(':mode_paiement', $mode_paiement);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();

        // Mettre à jour les stocks
        $stmt = $db->prepare("
            UPDATE stocks s
            JOIN commande_details cd ON s.produit_id = cd.produit_id
            SET s.quantite_actuelle = s.quantite_actuelle - cd.quantite
            WHERE cd.commande_id = :commande_id
        ");
        $stmt->bindParam(':commande_id', $commande_id);
        $stmt->execute();

        $db->commit();
        return true;
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Erreur validerCommande: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// FONCTIONS POUR LES TRANSACTIONS
// ============================================================================

function getStatsTransactionsTempsReel($db, $user_id)
{
    try {
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_ventes_jour,
                COALESCE(SUM(total_ttc), 0) as ca_jour,
                COALESCE(SUM(CASE WHEN mode_paiement = 'especes' THEN total_ttc ELSE 0 END), 0) as especes_jour,
                COALESCE(SUM(CASE WHEN mode_paiement = 'mobile_money' THEN total_ttc ELSE 0 END), 0) as mobile_money_jour,
                COALESCE(SUM(CASE WHEN mode_paiement = 'carte' THEN total_ttc ELSE 0 END), 0) as carte_jour,
                COUNT(CASE WHEN statut = 'rembourse' THEN 1 END) as remboursements_jour
            FROM ventes 
            WHERE caissier_id = :user_id 
            AND DATE(date_vente) = CURDATE()
        ");
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur getStatsTransactionsTempsReel: " . $e->getMessage());
        return [];
    }
}

function traiterRemboursement($db, $vente_id, $motif, $user_id)
{
    try {
        $db->beginTransaction();

        // Récupérer les détails de la vente
        $stmt = $db->prepare("
            SELECT vd.produit_id, vd.quantite
            FROM vente_details vd
            WHERE vd.vente_id = :vente_id
        ");
        $stmt->bindParam(':vente_id', $vente_id);
        $stmt->execute();
        $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Restocker les produits
        foreach ($details as $detail) {
            $stmt = $db->prepare("
                UPDATE stocks 
                SET quantite_actuelle = quantite_actuelle + :quantite
                WHERE produit_id = :produit_id
                ORDER BY date_reception ASC
                LIMIT 1
            ");
            $stmt->bindParam(':quantite', $detail['quantite']);
            $stmt->bindParam(':produit_id', $detail['produit_id']);
            $stmt->execute();
        }

        // Marquer la vente comme remboursée
        $stmt = $db->prepare("
            UPDATE ventes 
            SET statut = 'rembourse',
                motif_remboursement = :motif,
                date_remboursement = NOW(),
                caissier_remboursement = :user_id
            WHERE id = :vente_id
        ");
        $stmt->bindParam(':vente_id', $vente_id);
        $stmt->bindParam(':motif', $motif);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();

        $db->commit();
        return true;
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Erreur traiterRemboursement: " . $e->getMessage());
        return false;
    }
}
// ============================================================================
// FONCTIONS POUR LA RECHERCHE ET GESTION DES PRODUITS
// ============================================================================

function rechercherProduit($db, $search_term)
{
    try {
        $stmt = $db->prepare("
            SELECT 
                p.id,
                p.reference,
                p.nom,
                p.description,
                p.prix_vente as prix_vente_ht,
                COALESCE(p.taux_tva, 0) as taux_tva,
                (p.prix_vente * (1 + COALESCE(p.taux_tva, 0)/100)) as prix_ttc,
                COALESCE(SUM(s.quantite_actuelle), 0) as stock,
                c.nom as categorie_nom,
                f.nom_societe as fournisseur_nom,
                -- Prix en devises
                COALESCE(pp.prix_fc, p.prix_vente) as prix_fc,
                COALESCE(pp.prix_usd, p.prix_vente / 2500) as prix_usd,
                COALESCE(pp.taux_conversion, 2500) as taux_conversion
            FROM produits p
            LEFT JOIN stocks s ON p.id = s.produit_id AND s.statut = 'en_stock'
            LEFT JOIN categories c ON p.categorie_id = c.id
            LEFT JOIN fournisseurs f ON p.fournisseur_id = f.id
            LEFT JOIN prix_vente pp ON p.id = pp.produit_id AND pp.date_fin IS NULL
            WHERE (p.reference LIKE :search OR p.nom LIKE :search OR p.description LIKE :search)
            AND p.statut = 'actif'
            GROUP BY p.id, p.reference, p.nom, p.description, p.prix_vente, p.taux_tva,
                     c.nom, f.nom_societe, pp.prix_fc, pp.prix_usd, pp.taux_conversion
            HAVING stock > 0
            ORDER BY p.nom
            LIMIT 10
        ");
        $stmt->bindValue(':search', '%' . $search_term . '%');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur rechercherProduit: " . $e->getMessage());
        return [];
    }
}

function getProduitByCode($db, $code)
{
    try {
        $stmt = $db->prepare("
            SELECT 
                p.id,
                p.reference,
                p.nom,
                p.description,
                p.prix_vente as prix_vente_ht,
                COALESCE(p.taux_tva, 0) as taux_tva,
                (p.prix_vente * (1 + COALESCE(p.taux_tva, 0)/100)) as prix_ttc,
                COALESCE(SUM(s.quantite_actuelle), 0) as stock,
                c.nom as categorie_nom,
                f.nom_societe as fournisseur_nom,
                -- Prix en devises
                COALESCE(pp.prix_fc, p.prix_vente) as prix_fc,
                COALESCE(pp.prix_usd, p.prix_vente / 2500) as prix_usd,
                COALESCE(pp.taux_conversion, 2500) as taux_conversion
            FROM produits p
            LEFT JOIN stocks s ON p.id = s.produit_id AND s.statut = 'en_stock'
            LEFT JOIN categories c ON p.categorie_id = c.id
            LEFT JOIN fournisseurs f ON p.fournisseur_id = f.id
            LEFT JOIN prix_vente pp ON p.id = pp.produit_id AND pp.date_fin IS NULL
            WHERE p.reference = :code
            AND p.statut = 'actif'
            GROUP BY p.id, p.reference, p.nom, p.description, p.prix_vente, p.taux_tva,
                     c.nom, f.nom_societe, pp.prix_fc, pp.prix_usd, pp.taux_conversion
            HAVING stock > 0
        ");
        $stmt->bindParam(':code', $code);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur getProduitByCode: " . $e->getMessage());
        return null;
    }
}

function getProduitById($db, $id)
{
    try {
        $stmt = $db->prepare("
            SELECT 
                p.id,
                p.reference,
                p.nom,
                p.description,
                p.prix_vente as prix_vente_ht,
                COALESCE(p.taux_tva, 0) as taux_tva,
                (p.prix_vente * (1 + COALESCE(p.taux_tva, 0)/100)) as prix_ttc,
                COALESCE(SUM(s.quantite_actuelle), 0) as stock,
                c.nom as categorie_nom,
                f.nom_societe as fournisseur_nom,
                -- Prix en devises
                COALESCE(pp.prix_fc, p.prix_vente) as prix_fc,
                COALESCE(pp.prix_usd, p.prix_vente / 2500) as prix_usd,
                COALESCE(pp.taux_conversion, 2500) as taux_conversion
            FROM produits p
            LEFT JOIN stocks s ON p.id = s.produit_id AND s.statut = 'en_stock'
            LEFT JOIN categories c ON p.categorie_id = c.id
            LEFT JOIN fournisseurs f ON p.fournisseur_id = f.id
            LEFT JOIN prix_vente pp ON p.id = pp.produit_id AND pp.date_fin IS NULL
            WHERE p.id = :id
            AND p.statut = 'actif'
            GROUP BY p.id, p.reference, p.nom, p.description, p.prix_vente, p.taux_tva,
                     c.nom, f.nom_societe, pp.prix_fc, pp.prix_usd, pp.taux_conversion
            HAVING stock > 0
        ");
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erreur getProduitById: " . $e->getMessage());
        return null;
    }
}

// ============================================================================
// FONCTION POUR TRAITER UNE VENTE
// ============================================================================

function traiterVente($db, $panier, $mode_paiement, $montant_remise, $user_id)
{
    try {
        $db->beginTransaction();

        // Calcul des totaux
        $total_ht = 0;
        $total_tva = 0;

        foreach ($panier as $item) {
            $produit = getProduitById($db, $item['id']);
            if (!$produit) {
                throw new Exception("Produit non trouvé: " . $item['nom']);
            }

            $prix_ht = $produit['prix_vente_ht'] * $item['quantite'];
            $tva = $prix_ht * ($produit['taux_tva'] / 100);
            $total_ht += $prix_ht;
            $total_tva += $tva;
        }

        $total_ttc = $total_ht + $total_tva - $montant_remise;

        // Créer la référence de vente
        $reference = 'V' . date('YmdHis');

        // Insérer la vente
        $stmt = $db->prepare("
            INSERT INTO ventes (reference, caissier_id, total_ht, total_tva, total_ttc, 
                              montant_remise, mode_paiement, date_vente)
            VALUES (:reference, :caissier_id, :total_ht, :total_tva, :total_ttc, 
                   :montant_remise, :mode_paiement, NOW())
        ");
        $stmt->bindParam(':reference', $reference);
        $stmt->bindParam(':caissier_id', $user_id);
        $stmt->bindParam(':total_ht', $total_ht);
        $stmt->bindParam(':total_tva', $total_tva);
        $stmt->bindParam(':total_ttc', $total_ttc);
        $stmt->bindParam(':montant_remise', $montant_remise);
        $stmt->bindParam(':mode_paiement', $mode_paiement);
        $stmt->execute();

        $vente_id = $db->lastInsertId();

        // Insérer les détails de vente et mettre à jour les stocks
        foreach ($panier as $item) {
            $produit = getProduitById($db, $item['id']);

            // Insérer détail vente
            $stmt = $db->prepare("
                INSERT INTO vente_details (vente_id, produit_id, quantite, prix_vente_ht, taux_tva)
                VALUES (:vente_id, :produit_id, :quantite, :prix_vente_ht, :taux_tva)
            ");
            $stmt->bindParam(':vente_id', $vente_id);
            $stmt->bindParam(':produit_id', $item['id']);
            $stmt->bindParam(':quantite', $item['quantite']);
            $stmt->bindParam(':prix_vente_ht', $produit['prix_vente_ht']);
            $stmt->bindParam(':taux_tva', $produit['taux_tva']);
            $stmt->execute();

            // Mettre à jour le stock (méthode FIFO)
            $stmt = $db->prepare("
                UPDATE stocks 
                SET quantite_actuelle = quantite_actuelle - :quantite
                WHERE produit_id = :produit_id 
                AND quantite_actuelle > 0
                AND statut = 'en_stock'
                ORDER BY date_reception ASC
                LIMIT 1
            ");
            $stmt->bindParam(':quantite', $item['quantite']);
            $stmt->bindParam(':produit_id', $item['id']);
            $stmt->execute();

            // Vérifier si la mise à jour a fonctionné
            if ($stmt->rowCount() === 0) {
                throw new Exception("Stock insuffisant pour: " . $item['nom']);
            }
        }

        $db->commit();
        return [
            'success' => true,
            'reference' => $reference,
            'total_ttc' => $total_ttc,
            'vente_id' => $vente_id
        ];

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Erreur traiterVente: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ============================================================================
// RÉCUPÉRATION DES DONNÉES
// ============================================================================

$taux_change = getTauxChange();
$prix_produits = getPrixProduits($db);
$historique_prix = getHistoriquePrix($db);
$commandes_attente = getCommandesEnAttente($db, $user_id);
$stats_transactions = getStatsTransactionsTempsReel($db, $user_id);

// ============================================================================
// TRAITEMENT DES ACTIONS POST
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Mise à jour des prix
        if (isset($_POST['update_prix'])) {
            $produit_id = (int) $_POST['produit_id'];
            $prix_fc = floatval($_POST['prix_fc']);
            $prix_usd = floatval($_POST['prix_usd']);
            $taux_conversion = floatval($_POST['taux_conversion']);

            if (updatePrixProduit($db, $produit_id, $prix_fc, $prix_usd, $taux_conversion, $user_id)) {
                $_SESSION['success_message'] = "Prix mis à jour avec succès";
            } else {
                throw new Exception("Erreur lors de la mise à jour du prix");
            }
        }

        // Validation de commande
        if (isset($_POST['valider_commande'])) {
            $commande_id = (int) $_POST['commande_id'];
            $mode_paiement = $_POST['mode_paiement'];

            if (validerCommande($db, $commande_id, $mode_paiement, $user_id)) {
                $_SESSION['success_message'] = "Commande validée avec succès";
            } else {
                throw new Exception("Erreur lors de la validation de la commande");
            }
        }

        // Remboursement
        if (isset($_POST['traiter_remboursement'])) {
            $vente_id = (int) $_POST['vente_id'];
            $motif = $_POST['motif_remboursement'];

            if (traiterRemboursement($db, $vente_id, $motif, $user_id)) {
                $_SESSION['success_message'] = "Remboursement traité avec succès";
            } else {
                throw new Exception("Erreur lors du remboursement");
            }
        }

        // NOUVEAU: Traitement d'une vente
        if (isset($_POST['process_vente'])) {
            $panier_data = json_decode($_POST['panier_data'], true);
            $mode_paiement = $_POST['mode_paiement'];
            $montant_remise = floatval($_POST['montant_remise'] ?? 0);

            if (empty($panier_data)) {
                throw new Exception("Le panier est vide");
            }

            $resultat_vente = traiterVente($db, $panier_data, $mode_paiement, $montant_remise, $user_id);

            if ($resultat_vente['success']) {
                $_SESSION['vente_success'] = [
                    'reference' => $resultat_vente['reference'],
                    'total_ttc' => $resultat_vente['total_ttc'],
                    'montant_remise' => $montant_remise
                ];
            } else {
                throw new Exception($resultat_vente['message']);
            }
        }

        header('Location: caisse.php');
        exit;

    } catch (Exception $e) {
        $_SESSION['error_message'] = "Erreur: " . $e->getMessage();
        header('Location: caisse.php');
        exit;
    }
}

// ============================================================================
// TRAITEMENT DES REQUÊTES AJAX
// ============================================================================

if (isset($_GET['search_product'])) {
    header('Content-Type: application/json');
    $results = rechercherProduit($db, $_GET['search_product']);
    echo json_encode($results);
    exit;
}

if (isset($_GET['get_product'])) {
    header('Content-Type: application/json');
    $product = getProduitByCode($db, $_GET['get_product']);
    echo json_encode($product ?: []);
    exit;
}
if (isset($_GET['get_product_by_id'])) {
    header('Content-Type: application/json');
    $product = getProduitById($db, (int) $_GET['get_product_by_id']);
    echo json_encode($product ?: []);
    exit;
}

if (isset($_GET['get_commande_details'])) {
    header('Content-Type: application/json');
    $details = getDetailsCommande($db, (int) $_GET['get_commande_details']);
    echo json_encode(['details' => $details]);
    exit;
}

if (isset($_GET['get_stats_temps_reel'])) {
    header('Content-Type: application/json');
    $stats = getStatsTransactionsTempsReel($db, $user_id);
    echo json_encode($stats);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'actualiser_taux') {
    header('Content-Type: application/json');
    $nouveau_taux = getTauxChange();
    echo json_encode(['success' => true, 'taux' => $nouveau_taux]);
    exit;
}

// Fonction pour formater les dates
function formatDate($date)
{
    if (empty($date))
        return 'Non définie';
    return date('d/m/Y H:i', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Caissier - Pharma Management</title>
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
            color: white;
        }

        .section {
            display: none;
        }

        .section.active {
            display: block;
        }

        .fade-in {
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
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
                <div class="w-10 h-10 bg-green-600 rounded-full flex items-center justify-center">
                    <i class="fas fa-cash-register text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">NAGEX Pharma</h1>
                    <p class="text-xs text-green-600">Dashboard Caissier</p>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="mt-6 ">
            <div class="px-4 space-y-2">
                <!-- Tableau de bord -->
                <a href="#dashboard" data-section="dashboard"
                    class="active flex items-center px-4 py-3 text-gray-700 rounded-lg transition-colors">
                    <i class="fas fa-tachometer-alt w-6"></i>
                    <span class="ml-3 font-medium">Tableau de bord</span>
                </a>

                <!-- Gestion des prix -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Gestion des prix
                    </p>
                    <a href="#prix" data-section="prix"
                        class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                        <i class="fas fa-tags w-6 text-purple-500"></i>
                        <span class="ml-3 font-medium">Prix & Devises</span>
                    </a>
                    <a href="#historique-prix" data-section="historique-prix"
                        class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                        <i class="fas fa-history w-6 text-blue-500"></i>
                        <span class="ml-3 font-medium">Historique Prix</span>
                    </a>
                </div>

                <!-- Traitement commandes -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Traitement
                        commandes</p>
                    <a href="#commandes" data-section="commandes"
                        class="flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-shopping-cart w-6 text-orange-500"></i>
                            <span class="ml-3 font-medium">Commandes en attente</span>
                        </div>
                        <span
                            class="bg-orange-500 text-white text-xs px-2 py-1 rounded-full"><?php echo count($commandes_attente); ?></span>
                    </a>
                </div>

                <!-- Transactions -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Transactions</p>
                    <a href="#caisse" data-section="caisse"
                        class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                        <i class="fas fa-cash-register w-6 text-green-500"></i>
                        <span class="ml-3 font-medium">Nouvelle Vente</span>
                    </a>
                    <a href="#remboursements" data-section="remboursements"
                        class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-green-50 transition-colors">
                        <i class="fas fa-undo w-6 text-red-500"></i>
                        <span class="ml-3 font-medium">Remboursements</span>
                    </a>
                </div>
            </div>
        </nav>

        <!-- User Profile -->
        <div class="absolute bottom-0 left-0 right-0 p-4 border-t">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-cash-register text-green-600"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($user_name); ?>
                    </p>
                    <p class="text-xs text-green-600 truncate">Caissier</p>
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
                    <h2 class="text-2xl font-bold text-gray-800" id="pageTitle">Tableau de bord Caissier</h2>
                    <p class="text-gray-600">Gestion des prix, commandes et transactions</p>
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

        <!-- Main Content Area -->
        <main class="p-8">
            <!-- Messages d'alerte -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-600 mr-2"></i>
                        <span class="text-green-800"><?php echo $_SESSION['success_message']; ?></span>
                    </div>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>
                        <span class="text-red-800"><?php echo $_SESSION['error_message']; ?></span>
                    </div>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Section Tableau de bord -->
            <div id="dashboard" class="section active">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Commandes en attente -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-orange-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Commandes en attente</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo count($commandes_attente); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-shopping-cart text-orange-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Chiffre d'affaires -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-green-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">CA aujourd'hui</p>
                                <p class="text-2xl font-bold text-gray-900">
                                    <?php echo number_format($stats_transactions['ca_jour'] ?? 0, 2, ',', ' '); ?> FC
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-chart-line text-green-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Ventes aujourd'hui -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-blue-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Ventes aujourd'hui</p>
                                <p class="text-2xl font-bold text-gray-900">
                                    <?php echo $stats_transactions['total_ventes_jour'] ?? 0; ?>
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-receipt text-blue-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Taux de change -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-purple-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Taux de change</p>
                                <p class="text-2xl font-bold text-gray-900">
                                    <?php echo number_format($taux_change, 2, ',', ' '); ?>
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-exchange-alt text-purple-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions rapides -->
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-6">Actions rapides</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <a href="#prix" onclick="showSection('prix')"
                            class="flex flex-col items-center p-4 bg-purple-50 rounded-lg hover:bg-purple-100 transition-colors text-center">
                            <i class="fas fa-tags text-purple-600 text-2xl mb-2"></i>
                            <span class="font-medium text-sm text-gray-700">Gérer les prix</span>
                        </a>

                        <a href="#commandes" onclick="showSection('commandes')"
                            class="flex flex-col items-center p-4 bg-orange-50 rounded-lg hover:bg-orange-100 transition-colors text-center">
                            <i class="fas fa-shopping-cart text-orange-600 text-2xl mb-2"></i>
                            <span class="font-medium text-sm text-gray-700">Commandes</span>
                        </a>

                        <a href="#caisse" onclick="showSection('caisse')"
                            class="flex flex-col items-center p-4 bg-green-50 rounded-lg hover:bg-green-100 transition-colors text-center">
                            <i class="fas fa-cash-register text-green-600 text-2xl mb-2"></i>
                            <span class="font-medium text-sm text-gray-700">Nouvelle vente</span>
                        </a>

                        <a href="#remboursements" onclick="showSection('remboursements')"
                            class="flex flex-col items-center p-4 bg-red-50 rounded-lg hover:bg-red-100 transition-colors text-center">
                            <i class="fas fa-undo text-red-600 text-2xl mb-2"></i>
                            <span class="font-medium text-sm text-gray-700">Remboursements</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Section Gestion des Prix -->
            <div id="prix" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Gestion des Prix et Devises</h3>
                    </div>
                    <div class="p-6">
                        <!-- Taux de change -->
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h4 class="font-semibold text-blue-800">Taux de change actuel</h4>
                                    <p class="text-blue-600">1 USD =
                                        <?php echo number_format($taux_change, 2, ',', ' '); ?> CDF
                                    </p>
                                </div>
                                <button onclick="actualiserTauxChange()"
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm">
                                    <i class="fas fa-sync-alt mr-2"></i>Actualiser
                                </button>
                            </div>
                        </div>

                        <!-- Liste des produits avec prix -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Produit</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prix
                                            FC</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prix
                                            USD</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Taux
                                        </th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($prix_produits as $produit): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <div class="font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($produit['nom']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo htmlspecialchars($produit['reference']); ?>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <input type="number" id="prix_fc_<?php echo $produit['id']; ?>"
                                                    value="<?php echo number_format($produit['prix_fc'], 2, '.', ''); ?>"
                                                    step="0.01"
                                                    class="w-24 border border-gray-300 rounded px-2 py-1 text-sm">
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <input type="number" id="prix_usd_<?php echo $produit['id']; ?>"
                                                    value="<?php echo number_format($produit['prix_usd'], 2, '.', ''); ?>"
                                                    step="0.01"
                                                    class="w-24 border border-gray-300 rounded px-2 py-1 text-sm">
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <input type="number" id="taux_<?php echo $produit['id']; ?>"
                                                    value="<?php echo number_format($produit['taux_conversion'], 2, '.', ''); ?>"
                                                    step="0.01"
                                                    class="w-24 border border-gray-300 rounded px-2 py-1 text-sm">
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <button onclick="updatePrix(<?php echo $produit['id']; ?>)"
                                                    class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-sm">
                                                    <i class="fas fa-save mr-1"></i>Mettre à jour
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Historique des Prix -->
            <div id="historique-prix" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Historique des modifications de prix</h3>
                    </div>
                    <div class="p-6">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Produit</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prix
                                            FC</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prix
                                            USD</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Taux
                                        </th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Période</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                            Modifié par</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($historique_prix as $historique): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 whitespace-nowrap font-medium text-gray-900">
                                                <?php echo htmlspecialchars($historique['produit_nom']); ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-gray-500">
                                                <?php echo number_format($historique['prix_fc'], 2, ',', ' '); ?> FC
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-gray-500">
                                                <?php echo number_format($historique['prix_usd'], 2, ',', ' '); ?> $
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-gray-500">
                                                <?php echo number_format($historique['taux_conversion'], 2, ',', ' '); ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-gray-500">
                                                <?php echo formatDate($historique['date_debut']); ?>
                                                <?php if ($historique['date_fin']): ?>
                                                    <br>au <?php echo formatDate($historique['date_fin']); ?>
                                                <?php else: ?>
                                                    <br><span class="text-green-600">En cours</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-gray-500">
                                                <?php echo htmlspecialchars($historique['caissier_nom']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Commandes en attente -->
            <div id="commandes" class="section">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Commandes en attente de paiement</h3>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($commandes_attente)): ?>
                            <div class="space-y-4">
                                <?php foreach ($commandes_attente as $commande): ?>
                                    <div class="border border-orange-200 rounded-lg p-4 bg-orange-50">
                                        <div class="flex items-center justify-between mb-3">
                                            <div>
                                                <h4 class="font-semibold text-orange-800">Commande
                                                    #<?php echo $commande['numero_commande']; ?></h4>
                                                <p class="text-orange-600">Client:
                                                    <?php echo htmlspecialchars($commande['client_nom'] . ' ' . $commande['client_prenom']); ?>
                                                </p>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-lg font-bold text-orange-700">
                                                    <?php echo number_format($commande['montant_total'], 2, ',', ' '); ?> FC
                                                </div>
                                                <div class="text-sm text-orange-600">
                                                    <?php echo $commande['nb_produits']; ?> produits
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <div class="text-sm text-orange-600">
                                                Créée le <?php echo formatDate($commande['date_commande']); ?>
                                            </div>
                                            <div class="flex space-x-2">
                                                <button onclick="voirDetailsCommande(<?php echo $commande['id']; ?>)"
                                                    class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm">
                                                    <i class="fas fa-eye mr-1"></i>Détails
                                                </button>
                                                <button onclick="validerPaiement(<?php echo $commande['id']; ?>)"
                                                    class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-sm">
                                                    <i class="fas fa-check mr-1"></i>Valider paiement
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-check-circle text-green-500 text-3xl mb-2"></i>
                                <p>Aucune commande en attente</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Section Caisse (Nouvelle vente) -->
            <div id="caisse" class="section">
                <div class="">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">Nouvelle vente</h2>
                    </div>
                    <div class="p-6">
                        <!-- Recherche produit -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-barcode mr-2"></i>Sélectionner un produit
                            </label>
                            <div class="flex space-x-2 mb-4">
                                <input type="text" id="search-product"
                                    class="flex-1 px-4 py-3 border border-gray-300 text-gray-700 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent text-lg"
                                    placeholder="Rechercher par nom, référence ou catégorie..."
                                    onkeyup="filterProducts()">
                                <button onclick="clearSearch()"
                                    class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <!-- Sélection des produits -->
                            <div class="border border-gray-300 rounded-lg max-h-80 overflow-y-auto">
                                <div id="products-list" class="divide-y divide-gray-200">
                                    <?php if (!empty($all_produits)): ?>
                                        <?php foreach ($all_produits as $produit): ?>
                                            <div class="product-item p-4 hover:bg-gray-50 cursor-pointer transition-colors"
                                                data-name="<?php echo htmlspecialchars(strtolower($produit['nom'])); ?>"
                                                data-reference="<?php echo htmlspecialchars(strtolower($produit['reference'])); ?>"
                                                data-category="<?php echo htmlspecialchars(strtolower($produit['categorie_nom'])); ?>"
                                                onclick="addToCart(<?php echo $produit['id']; ?>)">
                                                <div class="flex items-center justify-between">
                                                    <div class="flex-1">
                                                        <div class="flex items-center space-x-3">
                                                            <div
                                                                class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                                                <i class="fas fa-pills text-blue-600"></i>
                                                            </div>
                                                            <div>
                                                                <div class="font-semibold text-gray-900">
                                                                    <?php echo htmlspecialchars($produit['nom']); ?>
                                                                </div>
                                                                <div class="text-sm text-gray-500">
                                                                    <?php echo htmlspecialchars($produit['reference']); ?>
                                                                    <?php if ($produit['categorie_nom']): ?>
                                                                        • <?php echo htmlspecialchars($produit['categorie_nom']); ?>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <?php if ($produit['description']): ?>
                                                                    <div class="text-xs text-gray-400 mt-1">
                                                                        <?php echo htmlspecialchars(substr($produit['description'], 0, 60)); ?>
                                                                        <?php if (strlen($produit['description']) > 60): ?>...<?php endif; ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="text-right">
                                                        <div class="flex items-center space-x-4">
                                                            <!-- Informations stock -->
                                                            <div class="text-sm text-gray-600 text-center">
                                                                <div class="font-semibold"><?php echo $produit['stock']; ?>
                                                                </div>
                                                                <div class="text-xs">en stock</div>
                                                            </div>

                                                            <!-- Prix -->
                                                            <div>
                                                                <div class="font-bold text-green-600 text-lg">
                                                                    <?php echo number_format($produit['prix_fc'], 0, ',', ' '); ?>
                                                                    FC
                                                                </div>
                                                                <div class="text-xs text-gray-500">
                                                                    <?php echo number_format($produit['prix_usd'], 2, ',', ' '); ?>
                                                                    $
                                                                </div>
                                                                <div class="text-xs text-blue-500">
                                                                    HT:
                                                                    <?php echo number_format($produit['prix_vente_ht'], 0, ',', ' '); ?>
                                                                    FC
                                                                </div>
                                                                <?php if ($produit['taux_tva'] > 0): ?>
                                                                    <div class="text-xs text-orange-500">
                                                                        TVA: <?php echo $produit['taux_tva']; ?>%
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>

                                                            <!-- Bouton ajouter -->
                                                            <button
                                                                class="bg-green-500 hover:bg-green-600 text-white p-2 rounded-lg transition-colors"
                                                                onclick="event.stopPropagation(); addToCart(<?php echo $produit['id']; ?>)">
                                                                <i class="fas fa-plus"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center py-8 text-gray-500 text-red-700">
                                            <i class="fas fa-box-open text-3xl mb-2"></i>
                                            <p>Aucun produit disponible</p>
                                            <p class="text-sm">Tous les produits sont en rupture de stock</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Statistiques produits -->
                            <?php if (!empty($all_produits)): ?>
                                <div class="mt-3 flex items-center justify-between text-xs text-gray-500">
                                    <span><?php echo count($all_produits); ?> produit(s) disponible(s)</span>
                                    <span>Stock total: <?php echo array_sum(array_column($all_produits, 'stock')); ?>
                                        unités</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div id="search-results" class="mt-2 hidden"></div>
                    </div>

                    <!-- Panier -->
                    <div class="mb-6">
                        <h3 class="text-md font-semibold text-gray-900 mb-3">Panier</h3>
                        <div id="cart" class="space-y-2 max-h-96 overflow-y-auto">
                            <div class="text-center text-gray-500 py-8">
                                <i class="fas fa-shopping-cart text-3xl mb-2"></i>
                                <p>Panier vide</p>
                                <p class="text-sm">Scannez ou recherchez des produits</p>
                            </div>
                        </div>
                    </div>

                    <!-- Total et réduction -->
                    <div class="border-t border-gray-200 pt-4">
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Remise</label>
                                <div class="flex space-x-2">
                                    <input type="number" id="discount-amount" min="0" step="0.01"
                                        class="flex-1 px-3 py-2 border border-gray-300 rounded text-sm text-gray-700"
                                        placeholder="Montant FC" onchange="updateTotals()">
                                    <button onclick="applyDiscount()"
                                        class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-2 rounded text-sm">
                                        Appliquer
                                    </button>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm text-gray-600">Sous-total:</div>
                                <div id="subtotal-amount" class="text-xl font-bold text-gray-900">0,00 FC</div>
                                <div id="subtotal-usd" class="text-sm text-gray-500">0,00 $</div>
                            </div>
                        </div>

                        <div class="flex justify-between items-center mb-4">
                            <span class="text-lg font-semibold text-gray-700">Total à payer:</span>
                            <div class="text-right">
                                <span id="total-amount" class="text-3xl font-bold text-green-600">0,00 FC</span>
                                <div id="total-usd" class="text-sm text-gray-500">0,00 $</div>
                            </div>
                        </div>



                        <div class="flex space-x-3">
                            <button onclick="imprimerTicket()"
                                class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-4 rounded-lg font-semibold text-lg">
                                <i class="fas fa-print mr-2"></i>Imprimer ticket
                            </button>
                            <button onclick="finaliserVente()"
                                class="flex-1 bg-green-500 hover:bg-green-600 text-white py-4 rounded-lg font-semibold text-lg">
                                <i class="fas fa-credit-card mr-2"></i>Finaliser vente
                            </button>
                        </div>
                    </div>
                </div>
            </div>
    </div>


    <!-- Section Remboursements - Version compacte -->
    <div id="remboursements" class="section">
        <div class="bg-white rounded-2xl shadow-sm">
            <div class="px-6 py-4 border-b">
                <h3 class="text-xl font-semibold text-gray-900 text-center">Gestion des remboursements</h3>
            </div>
            <div class="p-6">
                
                <div class="flex flex-col items-center"> 
                    <div class="bg-red-50 border border-red-200 rounded-lg p-8 mb-8 w-full max-w-2xl text-center">
                        
                        <h4 class="font-semibold text-red-800 mb-6 text-lg">
                            <i class="fas fa-undo mr-2"></i>Effectuer un remboursement
                        </h4>

                        <form method="POST" class="space-y-6"> 
                            <div class="flex flex-col md:flex-row gap-6 items-center justify-center">
                                <div class="w-full md:w-64"> 
                                    <label class="block text-sm font-medium text-red-700 mb-2">
                                        Numéro de vente
                                    </label>
                                    <input type="text" name="vente_id" required
                                        class="w-full border border-red-300 rounded-lg px-4 py-3 text-sm text-center focus:ring-2 focus:ring-red-500 focus:border-transparent"
                                        placeholder="Ex: V202401150001">
                                </div>

                                <div class="w-full md:w-64"> 
                                    <label class="block text-sm font-medium text-red-700 mb-2">
                                        Motif du remboursement
                                    </label>
                                    <select name="motif_remboursement" required
                                        class="w-full border border-red-300 rounded-lg px-4 py-3 text-sm text-gray-700 text-left focus:ring-2 focus:ring-red-500 focus:border-transparent">
                                        <option value="">Sélectionnez un motif</option>
                                        <option value="retour_client">Retour client</option>
                                        <option value="erreur_caisse">Erreur de caisse</option>
                                        <option value="produit_defectueux">Produit défectueux</option>
                                        <option value="autre">Autre raison</option>
                                    </select>
                                </div>
                            </div>

                            <div class="flex justify-center">
                                <button type="submit" name="traiter_remboursement"
                                    class="bg-red-600 hover:bg-red-700 text-white py-3 px-8 rounded-lg text-sm font-medium transition-colors duration-200 w-64">
                                    <!-- Largeur fixe -->
                                    <i class="fas fa-undo mr-2"></i>Traiter le remboursement
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Statistiques remboursements -->
                <div class="flex justify-center">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 w-full max-w-4xl">
                        <div class="bg-white border border-gray-200 rounded-lg p-6 text-center shadow-sm">
                            <div class="text-3xl font-bold text-red-600 mb-2">
                                <?php echo $stats_transactions['remboursements_jour'] ?? 0; ?>
                            </div>
                            <div class="text-sm text-gray-600 font-medium">Remboursements aujourd'hui</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </main>
    </div>

    <script>
        let cart = [];
        let currentPaymentMethod = null;
        let discountAmount = 0;
        const tauxChange = <?php echo $taux_change; ?>;

        // Navigation entre sections
        function showSection(sectionId, event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }

            // Masquer toutes les sections
            document.querySelectorAll('.section').forEach(section => {
                section.classList.remove('active');
            });

            // Afficher la section sélectionnée
            const targetSection = document.getElementById(sectionId);
            if (targetSection) {
                targetSection.classList.add('active');
            }

            // Mettre à jour le titre de la page
            const titles = {
                'dashboard': 'Tableau de bord Caissier',
                'prix': 'Gestion des Prix et Devises',
                'historique-prix': 'Historique des Prix',
                'commandes': 'Commandes en Attente',
                'caisse': 'Nouvelle Vente',
                'remboursements': 'Gestion des Remboursements'
            };

            const pageTitle = document.getElementById('pageTitle');
            if (pageTitle) {
                pageTitle.textContent = titles[sectionId] || 'Tableau de bord Caissier';
            }

            // Mettre à jour la navigation active
            document.querySelectorAll('nav a').forEach(link => {
                link.classList.remove('active');
            });

            const activeLink = document.querySelector(`nav a[href="#${sectionId}"]`);
            if (activeLink) {
                activeLink.classList.add('active');
            }
        }

        // Gestion des prix
        function updatePrix(produitId) {
            const prixFc = document.getElementById('prix_fc_' + produitId).value;
            const prixUsd = document.getElementById('prix_usd_' + produitId).value;
            const taux = document.getElementById('taux_' + produitId).value;

            const formData = new FormData();
            formData.append('update_prix', 'true');
            formData.append('produit_id', produitId);
            formData.append('prix_fc', prixFc);
            formData.append('prix_usd', prixUsd);
            formData.append('taux_conversion', taux);

            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    if (response.ok) {
                        alert('Prix mis à jour avec succès');
                        location.reload();
                    } else {
                        alert('Erreur lors de la mise à jour');
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur lors de la mise à jour');
                });
        }

        // Actualiser le taux de change
        function actualiserTauxChange() {
            fetch('?action=actualiser_taux')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Taux de change actualisé: ' + data.taux + ' CDF pour 1 USD');
                        location.reload();
                    } else {
                        alert('Erreur lors de l\'actualisation');
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur de connexion');
                });
        }

        // Validation de commande
        function validerPaiement(commandeId) {
            const modePaiement = prompt('Mode de paiement (especes/carte/mobile):');

            if (modePaiement && ['especes', 'carte', 'mobile'].includes(modePaiement)) {
                const formData = new FormData();
                formData.append('valider_commande', 'true');
                formData.append('commande_id', commandeId);
                formData.append('mode_paiement', modePaiement);

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => {
                        if (response.ok) {
                            alert('Commande validée avec succès');
                            location.reload();
                        } else {
                            alert('Erreur lors de la validation');
                        }
                    })
                    .catch(error => {
                        console.error('Erreur:', error);
                        alert('Erreur lors de la validation');
                    });
            }
        }

        // Voir détails commande
        function voirDetailsCommande(commandeId) {
            fetch('?get_commande_details=' + commandeId)
                .then(response => response.json())
                .then(data => {
                    let detailsHtml = '<div class="space-y-2">';
                    data.details.forEach(detail => {
                        detailsHtml += `
                            <div class="flex justify-between border-b pb-2">
                                <div>${detail.produit_nom}</div>
                                <div>${detail.quantite} x ${detail.prix_unitaire} FC</div>
                                <div class="font-semibold">${detail.sous_total} FC</div>
                            </div>
                        `;
                    });
                    detailsHtml += '</div>';

                    // Afficher dans un modal
                    alert('Détails de la commande:\n' + detailsHtml);
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur lors du chargement des détails');
                });
        }

        // Fonctions pour la caisse
        function handleSearchKeypress(event) {
            if (event.key === 'Enter') {
                searchProduct();
            }
        }

        function searchProduct() {
            const searchTerm = document.getElementById('search-product').value.trim();
            if (searchTerm.length === 0) return;

            fetch(`?search_product=${encodeURIComponent(searchTerm)}`)
                .then(response => response.json())
                .then(products => {
                    displaySearchResults(products);
                })
                .catch(error => {
                    console.error('Erreur recherche:', error);
                });
        }

        function displaySearchResults(products) {
            const resultsDiv = document.getElementById('search-results');

            if (products.length === 0) {
                resultsDiv.innerHTML = '<div class="text-red-500 text-sm p-2">Aucun produit trouvé</div>';
                resultsDiv.classList.remove('hidden');
                return;
            }

            resultsDiv.innerHTML = products.map(product => `
        <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg mb-2 hover:bg-gray-50 cursor-pointer"
             onclick="addToCart(${product.id})">
            <div class="flex-1">
                <div class="font-medium">${product.nom}</div>
                <div class="text-sm text-gray-500">
                    ${product.reference} 
                    ${product.categorie_nom ? '• ' + product.categorie_nom : ''}
                    ${product.fournisseur_nom ? '• ' + product.fournisseur_nom : ''}
                </div>
                <div class="text-xs text-gray-400 mt-1">
                    Stock: ${product.stock} unités
                    ${product.description ? '• ' + product.description.substring(0, 50) + '...' : ''}
                </div>
            </div>
            <div class="text-right">
                <div class="font-semibold text-green-600">${parseFloat(product.prix_fc).toFixed(2)} FC</div>
                <div class="text-xs text-gray-500">${parseFloat(product.prix_usd).toFixed(2)} $</div>
                <div class="text-xs text-blue-500">HT: ${parseFloat(product.prix_vente_ht).toFixed(2)} FC</div>
                ${product.taux_tva > 0 ?
                    `<div class="text-xs text-orange-500">TVA: ${parseFloat(product.taux_tva).toFixed(2)}%</div>` :
                    ''
                }
            </div>
        </div>
    `).join('');

            resultsDiv.classList.remove('hidden');
        }


        function addToCart(productId) {
            fetch(`?get_product_by_id=${productId}`)
                .then(response => response.json())
                .then(product => {
                    if (!product) {
                        alert('Produit non trouvé');
                        return;
                    }

                    const existingItem = cart.find(item => item.id === product.id);
                    if (existingItem) {
                        if (existingItem.quantite >= product.stock) {
                            alert('Stock insuffisant pour ce produit');
                            return;
                        }
                        existingItem.quantite += 1;
                    } else {
                        if (product.stock < 1) {
                            alert('Stock insuffisant pour ce produit');
                            return;
                        }
                        cart.push({
                            id: product.id,
                            reference: product.reference,
                            nom: product.nom,
                            prix_fc: parseFloat(product.prix_fc),
                            prix_usd: parseFloat(product.prix_usd),
                            prix_vente_ht: parseFloat(product.prix_vente_ht),
                            taux_tva: parseFloat(product.taux_tva),
                            quantite: 1,
                            stock: parseInt(product.stock)
                        });
                    }

                    updateCartDisplay();
                    document.getElementById('search-product').value = '';
                    document.getElementById('search-results').classList.add('hidden');
                    document.getElementById('search-product').focus();
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur lors de l\'ajout au panier');
                });
        }

        function showAddToCartFeedback(productName) {
            // Créer un toast de confirmation
            const toast = document.createElement('div');
            toast.className = 'fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 animate-fade-in';
            toast.innerHTML = `
        <div class="flex items-center space-x-2">
            <i class="fas fa-check-circle"></i>
            <span>${productName} ajouté au panier</span>
        </div>
    `;

            document.body.appendChild(toast);

            // Supprimer le toast après 3 secondes
            setTimeout(() => {
                toast.remove();
            }, 3000);
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function () {
            // Focus sur la recherche au chargement
            const searchInput = document.getElementById('search-product');
            if (searchInput) {
                searchInput.focus();
            }

            // Ajouter le CSS pour l'animation
            const style = document.createElement('style');
            style.textContent = `
        @keyframes fade-in {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in {
            animation: fade-in 0.3s ease-out;
        }
        .product-item:hover {
            background-color: #f9fafb;
        }
    `;
            document.head.appendChild(style);
        });


        function updateCartDisplay() {
            const cartElement = document.getElementById('cart');

            if (cart.length === 0) {
                cartElement.innerHTML = `
            <div class="text-center text-gray-500 py-8">
                <i class="fas fa-shopping-cart text-3xl mb-2"></i>
                <p>Panier vide</p>
                <p class="text-sm">Scannez ou recherchez des produits</p>
            </div>`;
            } else {
                cartElement.innerHTML = cart.map((item, index) => `
            <div class="cart-item fade-in flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <div class="flex-1">
                    <div class="font-medium text-sm">${item.nom}</div>
                    <div class="text-xs text-gray-500">${item.reference}</div>
                    <div class="flex items-center space-x-2 mt-1">
                        <button onclick="updateQuantity(${index}, -1)" 
                                class="w-6 h-6 bg-gray-300 rounded text-xs hover:bg-gray-400">-</button>
                        <span class="text-sm font-medium w-8 text-center">${item.quantite}</span>
                        <button onclick="updateQuantity(${index}, 1)" 
                                ${item.quantite >= item.stock ? 'disabled' : ''} 
                                class="w-6 h-6 bg-gray-300 rounded text-xs hover:bg-gray-400 ${item.quantite >= item.stock ? 'opacity-50 cursor-not-allowed' : ''}">+</button>
                    </div>
                </div>
                <div class="text-right">
                    <div class="font-semibold text-green-600">${(item.prix_fc * item.quantite).toFixed(2)} FC</div>
                    <div class="text-xs text-gray-500">${item.prix_fc} FC/u</div>
                    ${item.taux_tva > 0 ?
                        `<div class="text-xs text-orange-500">TVA incluse</div>` :
                        `<div class="text-xs text-blue-500">HT: ${(item.prix_vente_ht * item.quantite).toFixed(2)} FC</div>`
                    }
                    <button onclick="removeFromCart(${index})" 
                            class="text-red-500 hover:text-red-700 text-xs mt-1">
                        <i class="fas fa-trash"></i> Supprimer
                    </button>
                </div>
            </div>
        `).join('');
            }

            updateTotals();
        }

        function updateQuantity(index, change) {
            const item = cart[index];
            const newQuantity = item.quantite + change;

            if (newQuantity < 1) {
                removeFromCart(index);
                return;
            }

            if (newQuantity > item.stock) {
                alert('Stock insuffisant pour ' + item.nom);
                return;
            }

            item.quantite = newQuantity;
            updateCartDisplay();
        }

        function removeFromCart(index) {
            cart.splice(index, 1);
            updateCartDisplay();
        }
        function clearSearch() {
            document.getElementById('search-product').value = '';
            filterProducts();
            document.getElementById('search-product').focus();
        }

        function applyDiscount() {
            discountAmount = parseFloat(document.getElementById('discount-amount').value) || 0;
            updateTotals();
        }

        function updateTotals() {
            const subtotalFC = cart.reduce((total, item) => total + (item.prix_fc * item.quantite), 0);
            const totalFC = Math.max(0, subtotalFC - discountAmount);
            const totalUSD = totalFC / tauxChange;

            document.getElementById('subtotal-amount').textContent = subtotalFC.toFixed(2) + ' FC';
            document.getElementById('subtotal-usd').textContent = (subtotalFC / tauxChange).toFixed(2) + ' $';
            document.getElementById('total-amount').textContent = totalFC.toFixed(2) + ' FC';
            document.getElementById('total-usd').textContent = totalUSD.toFixed(2) + ' $';
        }

        function setPaymentMethod(method) {
            currentPaymentMethod = method;

            // Mettre à jour l'interface
            document.querySelectorAll('.payment-method').forEach(btn => {
                btn.classList.remove('active');
                btn.classList.remove('bg-green-600');
            });

            event.target.classList.add('active', 'bg-green-600');
        }

        // Fonctions pour la recherche et filtrage des produits
        function filterProducts() {
            const searchTerm = document.getElementById('search-product').value.toLowerCase().trim();
            const productItems = document.querySelectorAll('.product-item');

            let visibleCount = 0;

            productItems.forEach(item => {
                const productName = item.getAttribute('data-name');
                const productReference = item.getAttribute('data-reference');
                const productCategory = item.getAttribute('data-category');

                const matchesSearch = !searchTerm ||
                    productName.includes(searchTerm) ||
                    productReference.includes(searchTerm) ||
                    productCategory.includes(searchTerm);

                if (matchesSearch) {
                    item.style.display = 'block';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            // Afficher un message si aucun résultat
            const productList = document.getElementById('products-list');
            let noResultsMsg = productList.querySelector('.no-results-message');

            if (visibleCount === 0 && searchTerm) {
                if (!noResultsMsg) {
                    noResultsMsg = document.createElement('div');
                    noResultsMsg.className = 'no-results-message text-center py-8 text-gray-500';
                    noResultsMsg.innerHTML = `
                <i class="fas fa-search text-3xl mb-2"></i>
                <p>Aucun produit trouvé</p>
                <p class="text-sm">Essayez avec d'autres termes de recherche</p>
            `;
                    productList.appendChild(noResultsMsg);
                }
            } else if (noResultsMsg) {
                noResultsMsg.remove();
            }
        }

        function imprimerTicket() {
            if (cart.length === 0) {
                alert('Le panier est vide');
                return;
            }

            const subtotalFC = cart.reduce((total, item) => total + (item.prix_fc * item.quantite), 0);
            const totalFC = Math.max(0, subtotalFC - discountAmount);
            const totalUSD = totalFC / tauxChange;

            const ticketContent = `
                <div style="font-family: Arial, sans-serif; font-size: 12px; text-align: center;">
                    <h3>NAGEX PHARMA</h3>
                    <p>Ticket de caisse</p>
                    <p>Date: ${new Date().toLocaleString()}</p>
                    <hr>
                    <div style="text-align: left;">
                        ${cart.map(item => `
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span>${item.nom}</span>
                                <span>${item.quantite} x ${item.prix_fc} FC</span>
                            </div>
                        `).join('')}
                    </div>
                    <hr>
                    <div style="display: flex; justify-content: space-between; font-weight: bold;">
                        <span>Total FC:</span>
                        <span>${totalFC.toFixed(2)} FC</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Total USD:</span>
                        <span>${totalUSD.toFixed(2)} $</span>
                    </div>
                    <hr>
                    <p>Merci de votre visite !</p>
                </div>
            `;

            const printWindow = window.open('', '_blank');
            printWindow.document.write(ticketContent);
            printWindow.document.close();
            printWindow.print();
        }

        function finaliserVente() {
            if (cart.length === 0) {
                alert('Le panier est vide');
                return;
            }

            if (!currentPaymentMethod) {
                alert('Veuillez sélectionner un mode de paiement');
                return;
            }

            const formData = new FormData();
            formData.append('process_vente', 'true');
            formData.append('panier_data', JSON.stringify(cart));
            formData.append('mode_paiement', currentPaymentMethod);
            formData.append('montant_remise', discountAmount);

            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    if (response.ok) {
                        return response;
                    }
                    throw new Error('Erreur lors de la finalisation de la vente');
                })
                .then(() => {
                    // Réinitialiser le panier après succès
                    cart = [];
                    discountAmount = 0;
                    currentPaymentMethod = null;
                    updateCartDisplay();
                    updateTotals();

                    // Le message de succès sera affiché via la redirection PHP
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    alert('Erreur lors de la finalisation de la vente: ' + error.message);
                });
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function () {
            // Ajouter les écouteurs d'événements à tous les liens de navigation
            document.querySelectorAll('nav a[href^="#"]').forEach(link => {
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    const sectionId = this.getAttribute('href').substring(1);
                    showSection(sectionId, event);
                });
            });

            // Actualisation automatique du taux de change toutes les heures
            setInterval(actualiserTauxChange, 3600000);

            // Mise à jour en temps réel des statistiques
            setInterval(() => {
                fetch('?get_stats_temps_reel')
                    .then(response => response.json())
                    .then(data => {
                        // Mettre à jour les indicateurs du dashboard si nécessaire
                        console.log('Stats temps réel:', data);
                    });
            }, 30000);

            // Afficher la section dashboard par défaut
            showSection('dashboard');
        });
    </script>

    <style>
        /* Ajouter dans la balise style existante */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }

        .product-card {
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .product-card:hover {
            border-color: #10b981;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .stock-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-weight: 600;
        }

        .stock-high {
            background-color: #d1fae5;
            color: #065f46;
        }

        .stock-medium {
            background-color: #fef3c7;
            color: #92400e;
        }

        .stock-low {
            background-color: #fee2e2;
            color: #991b1b;
        }
    </style>
</body>

</html>