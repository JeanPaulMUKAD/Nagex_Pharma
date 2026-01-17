<?php
// config/log_client_action.php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/journal_functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($_SESSION['user_id'])) {
        try {
            $database = new Database();
            $pdo = $database->getConnection();
            
            logActivity(
                $pdo,
                $_SESSION['user_id'],
                $_SESSION['user_nom'] ?? 'Utilisateur',
                $_SESSION['user_role'] ?? 'guest',
                'action_client_' . ($data['action'] ?? 'inconnu'),
                json_encode($data['details'] ?? [], JSON_UNESCAPED_UNICODE),
                'frontend',
                null
            );
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log("Erreur journalisation action client: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
?>