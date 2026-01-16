<?php
declare(strict_types=1);

// config/journal_functions.php

// Constante pour contrôler si on log les actions du gérant
define('LOG_GERANT_ACTIONS', true); // Mettre à true pour aussi logger les actions du gérant

/**
 * Fonction principale de journalisation
 * @param mixed $db Instance PDO/PDOStatement
 * @param int $utilisateur_id
 * @param string $utilisateur_nom
 * @param string $utilisateur_role
 * @param string $action
 * @param string|null $details
 * @param string|null $table_concernee
 * @param int|null $element_id
 */
function logActivity($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $action, $details = null, $table_concernee = null, $element_id = null): bool
{
    try {
        // Ne pas logger les actions du gérant lui-même si configuré ainsi
        if ($utilisateur_role == 'gerant' && !LOG_GERANT_ACTIONS) {
            return true;
        }

        $ip_adresse = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $db->prepare("
            INSERT INTO journal_activites 
            (utilisateur_id, utilisateur_nom, utilisateur_role, action, details, table_concernee, element_id, ip_adresse, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        return $stmt->execute([
            $utilisateur_id,
            $utilisateur_nom,
            $utilisateur_role,
            $action,
            $details,
            $table_concernee,
            $element_id,
            $ip_adresse,
            substr($user_agent, 0, 500) // Limiter la taille
        ]);

    } catch (Exception $e) {
        error_log("Erreur lors du log d'activité: " . $e->getMessage());
        return false;
    }
}

// Fonctions spécifiques pour les actions courantes (gardez l'alias pour compatibilité)
function loggerActivite($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $action, $details = null, $table_concernee = null, $element_id = null)
{
    return logActivity($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $action, $details, $table_concernee, $element_id);
}

function loggerConnexion($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $success = true)
{
    $action = $success ? 'connexion_reussie' : 'connexion_echouee';
    $details = $success ? 'Utilisateur connecté avec succès' : 'Échec de la tentative de connexion';

    return logActivity($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $action, $details, 'utilisateurs', $utilisateur_id);
}

function loggerDeconnexion($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role)
{
    return logActivity($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, 'deconnexion', 'Utilisateur déconnecté', 'utilisateurs', $utilisateur_id);
}

function loggerCreation($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $table, $element_id, $details = null)
{
    $action = 'creation_' . $table;
    $details = $details ?? "Création d'un nouvel élément dans $table (ID: $element_id)";

    return logActivity($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $action, $details, $table, $element_id);
}

function loggerModification($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $table, $element_id, $changements = [])
{
    $action = 'modification_' . $table;
    $details = !empty($changements)
        ? "Modifications: " . json_encode($changements, JSON_UNESCAPED_UNICODE)
        : "Élément modifié dans $table (ID: $element_id)";

    return logActivity($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $action, $details, $table, $element_id);
}

function loggerSuppression($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $table, $element_id, $details = null)
{
    $action = 'suppression_' . $table;
    $details = $details ?? "Suppression d'un élément dans $table (ID: $element_id)";

    return logActivity($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $action, $details, $table, $element_id);
}

function loggerVisualisation($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $table, $element_id = null, $details = null)
{
    $action = 'visualisation_' . $table;
    $details = $details ?? ($element_id
        ? "Visualisation de l'élément $element_id dans $table"
        : "Consultation de la liste $table");

    return logActivity($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $action, $details, $table, $element_id);
}

// Fonction pour récupérer les activités
function getJournalActivites($db, $limit = 100, $utilisateur_id = null, $date_debut = null, $date_fin = null)
{
    try {
        $sql = "
            SELECT ja.*, u.email as utilisateur_email
            FROM journal_activites ja
            LEFT JOIN utilisateurs u ON ja.utilisateur_id = u.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($utilisateur_id) {
            $sql .= " AND ja.utilisateur_id = ?";
            $params[] = $utilisateur_id;
        }
        
        if ($date_debut) {
            $sql .= " AND DATE(ja.created_at) >= ?";
            $params[] = $date_debut;
        }
        
        if ($date_fin) {
            $sql .= " AND DATE(ja.created_at) <= ?";
            $params[] = $date_fin;
        }
        
        $sql .= " ORDER BY ja.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Erreur récupération journal: " . $e->getMessage());
        return [];
    }
}
?>