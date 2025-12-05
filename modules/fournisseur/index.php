<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';

// Vérifier l'authentification et le rôle
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_role'] !== 'fournisseur') {
    header('Location: /login.php');
    exit;
}

$db = new Database();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_nom'];
$user_role = $_SESSION['user_role'];
$user_email = $_SESSION['user_email'];

// Récupérer l'ID du fournisseur associé à l'utilisateur
$stmt = $db->prepare("SELECT id FROM Fournisseurs WHERE email = ?");
$stmt->execute([$user_email]);
$fournisseur = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fournisseur) {
    // Si aucun fournisseur n'est trouvé, utiliser le premier fournisseur disponible
    $stmt = $db->prepare("SELECT id FROM Fournisseurs WHERE est_actif = TRUE LIMIT 1");
    $stmt->execute();
    $fournisseur = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$fournisseur) {
        die("Aucun fournisseur actif trouvé dans la base de données.");
    }
}

$fournisseur_id = $fournisseur['id'];

// Fonctions pour récupérer les données du fournisseur
function getCommandesFournisseur($db, $fournisseur_id) {
    $stmt = $db->prepare("
        SELECT c.*, u.nom as createur_nom, 
               COUNT(lc.id) as nb_lignes,
               COALESCE(SUM(lc.quantite_commandee * lc.prix_achat), 0) as total_estime
        FROM Commandes c
        JOIN Utilisateurs u ON c.cree_par = u.id
        LEFT JOIN LignesCommande lc ON c.id = lc.commande_id
        WHERE c.fournisseur_id = ?
        GROUP BY c.id
        ORDER BY c.date_commande DESC
    ");
    $stmt->execute([$fournisseur_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDetailCommande($db, $commande_id) {
    $stmt = $db->prepare("
        SELECT lc.*, p.nom as produit_nom, p.reference as produit_reference
        FROM LignesCommande lc
        JOIN Produits p ON lc.produit_id = p.id
        WHERE lc.commande_id = ?
    ");
    $stmt->execute([$commande_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getProduitsFournisseur($db, $fournisseur_id) {
    $stmt = $db->prepare("
        SELECT DISTINCT p.*, c.nom as categorie_nom
        FROM Produits p
        JOIN Categories c ON p.categorie_id = c.id
        WHERE p.id IN (
            SELECT DISTINCT produit_id 
            FROM Stocks 
            WHERE fournisseur_id = ?
        ) OR p.id IN (
            SELECT DISTINCT lc.produit_id 
            FROM LignesCommande lc
            JOIN Commandes c ON lc.commande_id = c.id
            WHERE c.fournisseur_id = ?
        )
        ORDER BY p.nom
    ");
    $stmt->execute([$fournisseur_id, $fournisseur_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getStockProduits($db, $fournisseur_id) {
    $stmt = $db->prepare("
        SELECT produit_id, SUM(quantite_actuelle) as stock_total
        FROM Stocks 
        WHERE fournisseur_id = ?
        GROUP BY produit_id
    ");
    $stmt->execute([$fournisseur_id]);
    $stocks = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stocks[$row['produit_id']] = $row['stock_total'];
    }
    return $stocks;
}

function getLivraisonsRecent($db, $fournisseur_id) {
    $stmt = $db->prepare("
        SELECT be.*, c.reference as commande_ref,
               COUNT(lbe.id) as nb_lignes,
               COALESCE(SUM(lbe.quantite_recue), 0) as total_quantite
        FROM BonsEntree be
        JOIN Commandes c ON be.commande_id = c.id
        LEFT JOIN LignesBonEntree lbe ON be.id = lbe.bon_entree_id
        WHERE c.fournisseur_id = ?
        GROUP BY be.id
        ORDER BY be.date_entree DESC
        LIMIT 10
    ");
    $stmt->execute([$fournisseur_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getStatistiquesFournisseur($db, $fournisseur_id) {
    $stats = [];
    
    // Commandes en attente
    $stmt = $db->prepare("SELECT COUNT(*) FROM Commandes WHERE fournisseur_id = ? AND statut = 'envoyee'");
    $stmt->execute([$fournisseur_id]);
    $stats['commandes_attente'] = (int)$stmt->fetchColumn();
    
    // Commandes cette année
    $stmt = $db->prepare("SELECT COUNT(*) FROM Commandes WHERE fournisseur_id = ? AND YEAR(date_commande) = YEAR(CURDATE())");
    $stmt->execute([$fournisseur_id]);
    $stats['commandes_annee'] = (int)$stmt->fetchColumn();
    
    // Chiffre d'affaires estimé
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(lc.quantite_commandee * lc.prix_achat), 0) 
        FROM LignesCommande lc
        JOIN Commandes c ON lc.commande_id = c.id
        WHERE c.fournisseur_id = ? AND c.statut IN ('envoyee', 'recue_partiel', 'recue_complet')
    ");
    $stmt->execute([$fournisseur_id]);
    $stats['chiffre_affaires'] = (float)$stmt->fetchColumn();
    
    // Produits fournis
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT produit_id) 
        FROM Stocks 
        WHERE fournisseur_id = ?
    ");
    $stmt->execute([$fournisseur_id]);
    $stats['produits_fournis'] = (int)$stmt->fetchColumn();
    
    return $stats;
}

// Récupérer les données
try {
    $stats = getStatistiquesFournisseur($db, $fournisseur_id);
    $commandes_data = getCommandesFournisseur($db, $fournisseur_id);
    $produits_data = getProduitsFournisseur($db, $fournisseur_id);
    $stocks_data = getStockProduits($db, $fournisseur_id);
    $livraisons_data = getLivraisonsRecent($db, $fournisseur_id);
    
    // Informations du fournisseur
    $stmt = $db->prepare("SELECT * FROM Fournisseurs WHERE id = ?");
    $stmt->execute([$fournisseur_id]);
    $info_fournisseur = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Si pas d'info fournisseur, créer des données par défaut
    if (!$info_fournisseur) {
        $info_fournisseur = [
            'nom' => $user_name,
            'contact' => $user_name,
            'telephone' => 'Non défini',
            'email' => $user_email,
            'adresse' => 'Non définie'
        ];
    }
    
} catch (PDOException $e) {
    $stats = ['commandes_attente' => 0, 'commandes_annee' => 0, 'chiffre_affaires' => 0, 'produits_fournis' => 0];
    $commandes_data = $produits_data = $livraisons_data = [];
    $stocks_data = [];
    $info_fournisseur = [
        'nom' => $user_name,
        'contact' => $user_name,
        'telephone' => 'Non défini',
        'email' => $user_email,
        'adresse' => 'Non définie'
    ];
    error_log("Erreur base de données: " . $e->getMessage());
}

// Fonction pour formater les dates
function formatDate($date) {
    if (empty($date)) return 'Non définie';
    return date('d/m/Y H:i', strtotime($date));
}

// Fonction pour le statut des commandes
function getStatutCommandeText($statut) {
    $statuts = [
        'brouillon' => 'Brouillon',
        'envoyee' => 'En attente',
        'recue_partiel' => 'Reçu partiel',
        'recue_complet' => 'Reçu complet',
        'annulee' => 'Annulée'
    ];
    return $statuts[$statut] ?? $statut;
}

function getStatutCommandeClass($statut) {
    $classes = [
        'brouillon' => 'bg-gray-100 text-gray-800',
        'envoyee' => 'bg-orange-100 text-orange-800',
        'recue_partiel' => 'bg-blue-100 text-blue-800',
        'recue_complet' => 'bg-green-100 text-green-800',
        'annulee' => 'bg-red-100 text-red-800'
    ];
    return $classes[$statut] ?? 'bg-gray-100 text-gray-800';
}

// Traitement du formulaire de profil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        $nom = $_POST['nom'] ?? '';
        $contact = $_POST['contact'] ?? '';
        $telephone = $_POST['telephone'] ?? '';
        $email = $_POST['email'] ?? '';
        $adresse = $_POST['adresse'] ?? '';
        
        $stmt = $db->prepare("
            UPDATE Fournisseurs 
            SET nom = ?, contact = ?, telephone = ?, email = ?, adresse = ?
            WHERE id = ?
        ");
        $stmt->execute([$nom, $contact, $telephone, $email, $adresse, $fournisseur_id]);
        
        // Mettre à jour les informations locales
        $info_fournisseur['nom'] = $nom;
        $info_fournisseur['contact'] = $contact;
        $info_fournisseur['telephone'] = $telephone;
        $info_fournisseur['email'] = $email;
        $info_fournisseur['adresse'] = $adresse;
        
        $success_message = "Profil mis à jour avec succès!";
    } catch (PDOException $e) {
        $error_message = "Erreur lors de la mise à jour: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Fournisseur - Pharma Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <style>
        .sidebar { transition: all 0.3s ease; }
        .active { background-color: #10B981; color: white; }
        .pharma-green { background-color: #10B981; }
        .pharma-green:hover { background-color: #059669; }
        .sidebar-item:hover { background-color: #D1FAE5; }
    </style>
</head>

<body class="bg-gray-100" style="font-family: 'Montserrat', sans-serif;">
    <!-- Sidebar -->
    <div class="sidebar fixed inset-y-0 left-0 z-40 w-64 bg-white shadow-lg">
        <!-- Logo -->
        <div class="flex items-center justify-center p-6 border-b">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-blue-600 rounded-full flex items-center justify-center">
                    <i class="fas fa-truck text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">NAGEX Pharma</h1>
                    <p class="text-xs text-blue-600">Dashboard Fournisseur</p>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="mt-6">
            <div class="px-4 space-y-2">
                <a href="#dashboard" onclick="showSection('dashboard')" class="active flex items-center px-4 py-3 text-gray-700 rounded-lg transition-colors">
                    <i class="fas fa-tachometer-alt w-6"></i>
                    <span class="ml-3 font-medium">Tableau de bord</span>
                </a>

                <!-- Commandes -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Commandes</p>
                    <a href="#commandes" onclick="showSection('commandes')" class="flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-blue-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-clipboard-list w-6 text-orange-500"></i>
                            <span class="ml-3 font-medium">Mes Commandes</span>
                        </div>
                        <span class="bg-orange-500 text-white text-xs px-2 py-1 rounded-full"><?php echo count($commandes_data); ?></span>
                    </a>
                </div>

                <!-- Produits -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Produits</p>
                    <a href="#produits" onclick="showSection('produits')" class="flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-blue-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-pills w-6 text-green-500"></i>
                            <span class="ml-3 font-medium">Produits Fournis</span>
                        </div>
                        <span class="bg-green-500 text-white text-xs px-2 py-1 rounded-full"><?php echo count($produits_data); ?></span>
                    </a>
                </div>

                <!-- Livraisons -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Livraisons</p>
                    <a href="#livraisons" onclick="showSection('livraisons')" class="flex items-center justify-between px-4 py-3 text-gray-700 rounded-lg hover:bg-blue-50 transition-colors">
                        <div class="flex items-center">
                            <i class="fas fa-shipping-fast w-6 text-purple-500"></i>
                            <span class="ml-3 font-medium">Historique Livraisons</span>
                        </div>
                        <span class="bg-purple-500 text-white text-xs px-2 py-1 rounded-full"><?php echo count($livraisons_data); ?></span>
                    </a>
                </div>

                <!-- Profil -->
                <div class="mt-4">
                    <p class="px-4 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Compte</p>
                    <a href="#profil" onclick="showSection('profil')" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-blue-50 transition-colors">
                        <i class="fas fa-user-circle w-6 text-indigo-500"></i>
                        <span class="ml-3 font-medium">Mon Profil</span>
                    </a>
                </div>
            </div>
        </nav>

        <!-- User Profile -->
        <div class="absolute bottom-0 left-0 right-0 p-4 border-t">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-truck text-blue-600"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($info_fournisseur['nom']); ?></p>
                    <p class="text-xs text-blue-600 truncate">Fournisseur</p>
                </div>
                <a href="../utilisateurs/logout.php" class="text-gray-400 hover:text-red-500 transition-colors" title="Déconnexion">
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
                    <h2 class="text-2xl font-bold text-gray-800" id="pageTitle">Tableau de bord Fournisseur</h2>
                    <p class="text-gray-600">Gestion des commandes et livraisons</p>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-sm text-gray-600">
                        <i class="fas fa-building mr-2"></i>
                        <?php echo htmlspecialchars($info_fournisseur['nom']); ?>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="p-8">
            <!-- Messages d'alerte -->
            <?php if (isset($success_message)): ?>
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-600 mr-2"></i>
                        <span class="text-green-800"><?php echo $success_message; ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>
                        <span class="text-red-800"><?php echo $error_message; ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Section Tableau de bord -->
            <div id="dashboard" class="section">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Commandes en attente -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-orange-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Commandes en attente</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $stats['commandes_attente']; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-clock text-orange-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Commandes cette année -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-blue-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Commandes cette année</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $stats['commandes_annee']; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-chart-line text-blue-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Chiffre d'affaires -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-green-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Chiffre d'affaires</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['chiffre_affaires'], 0, ',', ' '); ?> €</p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-euro-sign text-green-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Produits fournis -->
                    <div class="bg-white rounded-2xl shadow-sm p-6 border-l-4 border-purple-500">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Produits fournis</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $stats['produits_fournis']; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                                <i class="fas fa-pills text-purple-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dernières commandes et informations -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Dernières commandes -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-semibold text-gray-900">Dernières Commandes</h3>
                            <a href="#commandes" onclick="showSection('commandes')" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                Voir tout <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                        <div class="space-y-4">
                            <?php if (!empty(array_slice($commandes_data, 0, 5))): ?>
                                <?php foreach (array_slice($commandes_data, 0, 5) as $commande): ?>
                                    <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg">
                                        <div class="flex-1">
                                            <div class="flex items-center justify-between mb-2">
                                                <span class="font-medium text-gray-900"><?php echo htmlspecialchars($commande['reference']); ?></span>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo getStatutCommandeClass($commande['statut']); ?>">
                                                    <?php echo getStatutCommandeText($commande['statut']); ?>
                                                </span>
                                            </div>
                                            <div class="text-sm text-gray-600">
                                                <?php echo formatDate($commande['date_commande']); ?> • 
                                                <?php echo $commande['nb_lignes']; ?> produit(s) • 
                                                <?php echo number_format((float)$commande['total_estime'], 2, ',', ' '); ?> €
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-8 text-gray-500">
                                    <i class="fas fa-clipboard-list text-2xl mb-2"></i>
                                    <p>Aucune commande récente</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Informations fournisseur -->
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6">Mes Informations</h3>
                        <div class="space-y-4">
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Nom de l'entreprise</span>
                                <span class="font-medium"><?php echo htmlspecialchars($info_fournisseur['nom']); ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Contact</span>
                                <span class="font-medium"><?php echo htmlspecialchars($info_fournisseur['contact'] ?? 'Non défini'); ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Téléphone</span>
                                <span class="font-medium"><?php echo htmlspecialchars($info_fournisseur['telephone'] ?? 'Non défini'); ?></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Email</span>
                                <span class="font-medium"><?php echo htmlspecialchars($info_fournisseur['email'] ?? 'Non défini'); ?></span>
                            </div>
                            <div class="pt-4 border-t">
                                <a href="#profil" onclick="showSection('profil')" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                    <i class="fas fa-edit mr-1"></i>Modifier mes informations
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Commandes -->
            <div id="commandes" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xl font-semibold text-gray-900">Mes Commandes</h3>
                            <div class="flex space-x-2">
                                <button onclick="filterCommandes('all')" class="px-3 py-1 text-xs bg-gray-200 rounded-lg hover:bg-gray-300">Toutes</button>
                                <button onclick="filterCommandes('envoyee')" class="px-3 py-1 text-xs bg-orange-200 rounded-lg hover:bg-orange-300">En attente</button>
                                <button onclick="filterCommandes('recue_complet')" class="px-3 py-1 text-xs bg-green-200 rounded-lg hover:bg-green-300">Livrées</button>
                            </div>
                        </div>
                    </div>
                    <div class="p-6">
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Référence</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nb. Produits</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Montant HT</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Créé par</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200" id="commandesTable">
                                    <?php if (!empty($commandes_data)): ?>
                                        <?php foreach ($commandes_data as $commande): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($commande['reference']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo formatDate($commande['date_commande']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $commande['nb_lignes']; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo number_format((float)$commande['total_estime'], 2, ',', ' '); ?> €
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo getStatutCommandeClass($commande['statut']); ?>">
                                                        <?php echo getStatutCommandeText($commande['statut']); ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($commande['createur_nom']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <button onclick="showDetailCommande(<?php echo $commande['id']; ?>)" class="text-blue-600 hover:text-blue-900" title="Voir détail">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="px-6 py-4 text-center text-gray-500">
                                                Aucune commande trouvée
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Produits -->
            <div id="produits" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Produits que je fournis</h3>
                    </div>
                    <div class="p-6">
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produit</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Référence</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Catégorie</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Stock actuel</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prix d'achat</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Prix de vente</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (!empty($produits_data)): ?>
                                        <?php foreach ($produits_data as $produit): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($produit['nom']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($produit['reference']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($produit['categorie_nom']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $stocks_data[$produit['id']] ?? 0; ?> unités
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo number_format((float)$produit['prix_achat'], 2, ',', ' '); ?> €
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo number_format((float)$produit['prix_vente'], 2, ',', ' '); ?> €
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                                Aucun produit trouvé
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Livraisons -->
            <div id="livraisons" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Historique des Livraisons</h3>
                    </div>
                    <div class="p-6">
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bon d'entrée</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Commande</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date réception</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nb. Produits</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Quantité totale</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Statut</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (!empty($livraisons_data)): ?>
                                        <?php foreach ($livraisons_data as $livraison): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($livraison['reference']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($livraison['commande_ref']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo formatDate($livraison['date_entree']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $livraison['nb_lignes']; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $livraison['total_quantite']; ?> unités
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                        <?php echo $livraison['statut'] === 'valide' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                                                        <?php echo $livraison['statut'] === 'valide' ? 'Validé' : 'En attente'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                                Aucune livraison trouvée
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Profil -->
            <div id="profil" class="section hidden">
                <div class="bg-white rounded-2xl shadow-sm">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-xl font-semibold text-gray-900">Mon Profil Fournisseur</h3>
                    </div>
                    <div class="p-6">
                        <div class="max-w-2xl">
                            <form method="POST" class="space-y-6">
                                <input type="hidden" name="update_profile" value="1">
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Nom de l'entreprise *</label>
                                        <input type="text" name="nom" value="<?php echo htmlspecialchars($info_fournisseur['nom']); ?>" 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Contact</label>
                                        <input type="text" name="contact" value="<?php echo htmlspecialchars($info_fournisseur['contact'] ?? ''); ?>" 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Téléphone</label>
                                        <input type="tel" name="telephone" value="<?php echo htmlspecialchars($info_fournisseur['telephone'] ?? ''); ?>" 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Email *</label>
                                        <input type="email" name="email" value="<?php echo htmlspecialchars($info_fournisseur['email'] ?? ''); ?>" 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Adresse</label>
                                    <textarea name="adresse" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"><?php echo htmlspecialchars($info_fournisseur['adresse'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="flex justify-end">
                                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                        <i class="fas fa-save mr-2"></i>Enregistrer les modifications
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <!-- Modal pour les détails de commande -->
    <div id="commandeDetailModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-2xl p-6 w-full max-w-4xl mx-4 max-h-90vh overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h4 class="text-lg font-semibold">Détail de la commande</h4>
                <button onclick="closeCommandeDetail()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="commandeDetailContent">
                <!-- Contenu chargé dynamiquement -->
            </div>
        </div>
    </div>

    <script>
        // Navigation entre sections
        function showSection(sectionName) {
            document.querySelectorAll('.section').forEach(section => {
                section.classList.add('hidden');
            });
            document.getElementById(sectionName).classList.remove('hidden');

            const titles = {
                'dashboard': 'Tableau de bord Fournisseur',
                'commandes': 'Mes Commandes',
                'produits': 'Produits Fournis',
                'livraisons': 'Historique des Livraisons',
                'profil': 'Mon Profil'
            };
            document.getElementById('pageTitle').textContent = titles[sectionName] || 'Tableau de bord Fournisseur';

            document.querySelectorAll('nav a').forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href') === '#' + sectionName) {
                    link.classList.add('active');
                }
            });
        }

        // Filtrage des commandes
        function filterCommandes(filter) {
            const rows = document.querySelectorAll('#commandesTable tr');
            rows.forEach(row => {
                if (row.cells.length < 5) return; // Skip header row
                
                const statutCell = row.cells[4];
                const statutText = statutCell.querySelector('span').textContent.toLowerCase();
                
                if (filter === 'all' || 
                    (filter === 'envoyee' && statutText.includes('attente')) ||
                    (filter === 'recue_complet' && statutText.includes('complet'))) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Voir détail commande
        function showDetailCommande(commandeId) {
            // Pour l'instant, on affiche une alerte simple
            // Dans une version complète, on ferait un appel AJAX pour récupérer les détails
            alert('Détail de la commande ' + commandeId + '\n\nCette fonctionnalité sera implémentée avec un appel AJAX pour afficher les produits de la commande.');
            
            // Exemple d'implémentation AJAX (à compléter) :
            /*
            fetch(`/api/commandes/${commandeId}/details`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('commandeDetailContent').innerHTML = data.html;
                    document.getElementById('commandeDetailModal').classList.remove('hidden');
                });
            */
        }

        function closeCommandeDetail() {
            document.getElementById('commandeDetailModal').classList.add('hidden');
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            showSection('dashboard');
        });
    </script>
</body>
</html>