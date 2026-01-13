<?php declare(strict_types=1);
// config/journal_functions.php

function loggerActivite($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $action, $details = null, $table_concernee = null, $element_id = null)
{
    try {
        // Ne pas logger les actions du gérant lui-même si configuré ainsi
        if ($utilisateur_role == 'gerant' && !LOG_GERANT_ACTIONS) {
            return;
        }

        $ip_adresse = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $db->prepare("
            INSERT INTO journal_activites 
            (utilisateur_id, utilisateur_nom, utilisateur_role, action, details, table_concernee, element_id, ip_adresse, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $utilisateur_id,
            $utilisateur_nom,
            $utilisateur_role,
            $action,
            $details,
            $table_concernee,
            $element_id,
            $ip_adresse,
            $user_agent
        ]);

    } catch (Exception $e) {
        error_log("Erreur lors du log d'activité: " . $e->getMessage());
    }
}

// Constante pour contrôler si on log les actions du gérant
define('LOG_GERANT_ACTIONS', true); // Mettre à true pour aussi logger les actions du gérant

// Fonctions spécifiques pour les actions courantes
function loggerConnexion($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $success = true)
{
    $action = $success ? 'connexion_reussie' : 'connexion_echouee';
    $details = $success ? 'Utilisateur connecté avec succès' : 'Échec de la tentative de connexion';

    loggerActivite($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $action, $details);
}

function loggerDeconnexion($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role)
{
    loggerActivite($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, 'deconnexion', 'Utilisateur déconnecté');
}

function loggerCreation($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $table, $element_id, $details = null)
{
    $action = 'creation_' . $table;
    $details = $details ?? "Création d'un nouvel élément dans $table (ID: $element_id)";

    loggerActivite($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $action, $details, $table, $element_id);
}

function loggerModification($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $table, $element_id, $changements = [])
{
    $action = 'modification_' . $table;
    $details = !empty($changements)
        ? "Modifications: " . json_encode($changements, JSON_UNESCAPED_UNICODE)
        : "Élément modifié dans $table (ID: $element_id)";

    loggerActivite($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $action, $details, $table, $element_id);
}

function loggerSuppression($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $table, $element_id, $details = null)
{
    $action = 'suppression_' . $table;
    $details = $details ?? "Suppression d'un élément dans $table (ID: $element_id)";

    loggerActivite($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $action, $details, $table, $element_id);
}

function loggerVisualisation($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $table, $element_id = null, $details = null)
{
    $action = 'visualisation_' . $table;
    $details = $details ?? ($element_id
        ? "Visualisation de l'élément $element_id dans $table"
        : "Consultation de la liste $table");

    loggerActivite($db, $utilisateur_id, $utilisateur_nom, $utilisateur_role, $action, $details, $table, $element_id);
}
?>