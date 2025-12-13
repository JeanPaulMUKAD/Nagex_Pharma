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

// ============================================
// INCLUSION DE FPDF
// ============================================

// Chemin vers FPDF à la racine du projet
$fpdf_path = __DIR__ . '/../../fpdf.php';
$font_path = __DIR__ . '/../../font/';

if (file_exists($fpdf_path)) {
    require_once $fpdf_path;

    // Définir le chemin des polices
    define('FPDF_FONTPATH', $font_path);
} else {
    die("Erreur: FPDF n'est pas installé. Veuillez installer FPDF à la racine de votre projet.");
}

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
    // Désactiver ONLY_FULL_GROUP_BY pour éviter les erreurs de syntaxe
    $pdo->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
} catch (Exception $e) {
    error_log("Erreur de connexion à la base de données: " . $e->getMessage());
    die("Erreur de connexion à la base de données. Veuillez contacter l'administrateur.");
}

// ============================================
// FONCTIONS UTILITAIRES
// ============================================

/**
 * Formatte un montant avec devise
 */
function formatMontant($montant, string $devise = 'CDF'): string
{
    // S'assurer que c'est un nombre
    if (!is_numeric($montant)) {
        $montant = 0;
    }

    // Convertir en float
    $montant_float = floatval($montant);

    if ($devise === 'USD') {
        return '$' . number_format($montant_float, 2, '.', ',');
    }
    return number_format($montant_float, 0, '.', ',') . ' FC';
}

/**
 * Initialise les polices UTF-8 pour FPDF
 */
function initialiserPolicesUTF8($pdf) {
    // Chemin vers le dossier des polices
    $font_path = __DIR__ . '/../../font/';
    
    // Vérifier si la police DejaVu existe (UTF-8 compatible)
    $dejavu_sans = $font_path . 'DejaVuSans.php';
    $dejavu_sans_bold = $font_path . 'DejaVuSans-Bold.php';
    
    if (file_exists($dejavu_sans)) {
        // Utiliser DejaVu (UTF-8 compatible)
        $pdf->AddFont('DejaVu', '', 'DejaVuSans.php');
        $pdf->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.php');
        $pdf->AddFont('DejaVu', 'I', 'DejaVuSans-Oblique.php');
        $pdf->AddFont('DejaVu', 'BI', 'DejaVuSans-BoldOblique.php');
        return 'DejaVu';
    }
    
    // Si DejaVu n'existe pas, télécharger ou utiliser Arial Unicode MS
    // Pour l'instant, on utilise Arial standard (avec conversion d'accents)
    return 'Arial';
}

/**
 * Convertit les caractères accentués pour FPDF standard
 */
function convertirAccents($texte) {
    $accented = array(
        'à', 'á', 'â', 'ã', 'ä', 'å', 'æ',
        'ç',
        'è', 'é', 'ê', 'ë',
        'ì', 'í', 'î', 'ï',
        'ð', 'ñ',
        'ò', 'ó', 'ô', 'õ', 'ö', 'ø',
        'ù', 'ú', 'û', 'ü',
        'ý', 'ÿ',
        'À', 'Á', 'Â', 'Ã', 'Ä', 'Å', 'Æ',
        'Ç',
        'È', 'É', 'Ê', 'Ë',
        'Ì', 'Í', 'Î', 'Ï',
        'Ð', 'Ñ',
        'Ò', 'Ó', 'Ô', 'Õ', 'Ö', 'Ø',
        'Ù', 'Ú', 'Û', 'Ü',
        'Ý',
        'œ', 'Œ', '€', '£', '¥', '§', '©', '®', '™',
        '«', '»', '…', '–', '—', '¿', '¡'
    );
    
    $unaccented = array(
        'a', 'a', 'a', 'a', 'a', 'a', 'ae',
        'c',
        'e', 'e', 'e', 'e',
        'i', 'i', 'i', 'i',
        'd', 'n',
        'o', 'o', 'o', 'o', 'o', 'o',
        'u', 'u', 'u', 'u',
        'y', 'y',
        'A', 'A', 'A', 'A', 'A', 'A', 'AE',
        'C',
        'E', 'E', 'E', 'E',
        'I', 'I', 'I', 'I',
        'D', 'N',
        'O', 'O', 'O', 'O', 'O', 'O',
        'U', 'U', 'U', 'U',
        'Y',
        'oe', 'OE', 'EUR', 'GBP', 'JPY', 'SS', '(c)', '(R)', 'TM',
        '<<', '>>', '...', '-', '-', '?', '!'
    );
    
    return str_replace($accented, $unaccented, $texte);
}

/**
 * Génère un PDF de reçu pour une commande avec FPDF (version UTF-8)
 */
function genererPDFRecu(PDO $pdo, int $commande_id, int $client_id): string
{
    try {
        // Vérifier que la commande appartient au client
        $stmt = $pdo->prepare("
            SELECT c.*, u.nom as client_nom, u.email, u.telephone, u.adresse,
                   COUNT(cd.id) as nombre_articles, SUM(cd.quantite) as total_quantite
            FROM commandes c
            JOIN utilisateurs u ON c.client_id = u.id
            LEFT JOIN commande_details cd ON c.id = cd.commande_id
            WHERE c.id = :commande_id AND c.client_id = :client_id
            GROUP BY c.id
        ");
        $stmt->execute([':commande_id' => $commande_id, ':client_id' => $client_id]);
        $commande = $stmt->fetch();

        if (!$commande) {
            throw new Exception("Commande non trouvee ou non autorisee");
        }

        // Récupérer les détails de la commande
        $stmt = $pdo->prepare("
            SELECT cd.*, p.nom as produit_nom, p.code_barre,
                   cat.nom as categorie_nom
            FROM commande_details cd
            JOIN produits p ON cd.produit_id = p.id
            LEFT JOIN categories cat ON p.categorie_id = cat.id
            WHERE cd.commande_id = :commande_id
            ORDER BY p.nom ASC
        ");
        $stmt->execute([':commande_id' => $commande_id]);
        $details = $stmt->fetchAll();

        // Traductions (sans accents pour compatibilité)
        $statut_fr = [
            'paye' => 'PAYEE',
            'en_attente' => 'EN ATTENTE',
            'expedie' => 'EXPEDIEE',
            'livre' => 'LIVREE',
            'annule' => 'ANNULEE'
        ];

        $mode_paiement_fr = [
            'especes' => 'Especes',
            'carte' => 'Carte bancaire',
            'mobile' => 'Mobile Money',
            'virement' => 'Virement bancaire'
        ];

        // Créer le PDF
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->AddPage();
        
        // Initialiser la police UTF-8
        $font = initialiserPolicesUTF8($pdf);
        
        // ========== EN-TÊTE ==========
        // Logo/En-tête
        $pdf->SetFont($font, 'B', 24);
        $pdf->SetTextColor(16, 185, 129); // Vert NAGEX
        $pdf->Cell(0, 10, 'NAGEX PHARMA', 0, 1, 'C');

        $pdf->SetFont($font, 'B', 16);
        $pdf->SetTextColor(55, 65, 81); // Gris foncé
        $pdf->Cell(0, 8, 'RECU DE COMMANDE', 0, 1, 'C');

        $pdf->SetFont($font, '', 10);
        $pdf->SetTextColor(107, 114, 128); // Gris
        $pdf->Cell(0, 6, convertirAccents('Votre sante, notre priorite'), 0, 1, 'C');

        // Ligne de séparation
        $pdf->SetDrawColor(16, 185, 129);
        $pdf->SetLineWidth(0.5);
        $pdf->Line(10, $pdf->GetY() + 5, 200, $pdf->GetY() + 5);
        $pdf->Ln(10);

        // ========== INFORMATIONS COMMANDE ==========
        $pdf->SetFont($font, 'B', 12);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->SetFillColor(240, 253, 244); // Vert très clair
        $pdf->Cell(0, 8, 'INFORMATIONS DE LA COMMANDE', 0, 1, 'L', true);
        $pdf->Ln(2);

        $pdf->SetFont($font, '', 10);
        $pdf->SetTextColor(0, 0, 0);

        // Tableau des informations
        $pdf->Cell(40, 7, 'N° Commande:', 0, 0);
        $pdf->SetFont($font, 'B', 10);
        $pdf->Cell(60, 7, $commande['numero_commande'], 0, 0);

        $pdf->SetFont($font, '', 10);
        $pdf->Cell(30, 7, 'Date:', 0, 0);
        $pdf->Cell(0, 7, date('d/m/Y a H:i', strtotime($commande['date_commande'])), 0, 1);

        $pdf->Cell(40, 7, 'Statut:', 0, 0);
        $pdf->SetFont($font, 'B', 10);
        $pdf->SetTextColor(16, 185, 129); // Vert pour le statut
        $pdf->Cell(60, 7, $statut_fr[$commande['statut']] ?? strtoupper($commande['statut']), 0, 0);

        $pdf->SetFont($font, '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(30, 7, convertirAccents('Mode paiement:'), 0, 0);
        $pdf->Cell(0, 7, convertirAccents($mode_paiement_fr[$commande['mode_paiement']] ?? $commande['mode_paiement']), 0, 1);
        $pdf->Ln(5);

        // ========== INFORMATIONS CLIENT ==========
        $pdf->SetFont($font, 'B', 12);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->SetFillColor(240, 253, 244);
        $pdf->Cell(0, 8, 'INFORMATIONS CLIENT', 0, 1, 'L', true);
        $pdf->Ln(2);

        $pdf->SetFont($font, '', 10);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->Cell(40, 7, 'Nom:', 0, 0);
        $pdf->Cell(0, 7, convertirAccents($commande['client_nom']), 0, 1);

        $pdf->Cell(40, 7, 'Email:', 0, 0);
        $pdf->Cell(0, 7, $commande['email'], 0, 1);

        if ($commande['telephone']) {
            $pdf->Cell(40, 7, convertirAccents('Telephone:'), 0, 0);
            $pdf->Cell(0, 7, $commande['telephone'], 0, 1);
        }

        if ($commande['adresse']) {
            $pdf->Cell(40, 7, convertirAccents('Adresse:'), 0, 0);
            $pdf->MultiCell(0, 7, convertirAccents($commande['adresse']));
        }
        $pdf->Ln(5);

        // ========== DÉTAILS DES PRODUITS ==========
        $pdf->SetFont($font, 'B', 12);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->SetFillColor(240, 253, 244);
        $pdf->Cell(0, 8, convertirAccents('DETAILS DE LA COMMANDE'), 0, 1, 'L', true);
        $pdf->Ln(2);

        // En-tête du tableau
        $pdf->SetFont($font, 'B', 10);
        $pdf->SetFillColor(220, 252, 231); // Vert clair
        $pdf->SetTextColor(5, 95, 70); // Vert foncé

        $pdf->Cell(10, 8, '#', 1, 0, 'C', true);
        $pdf->Cell(70, 8, 'PRODUIT', 1, 0, 'L', true);
        $pdf->Cell(25, 8, convertirAccents('CATEGORIE'), 1, 0, 'L', true);
        $pdf->Cell(20, 8, 'QTE', 1, 0, 'C', true);
        $pdf->Cell(30, 8, convertirAccents('PRIX UNIT.'), 1, 0, 'R', true);
        $pdf->Cell(35, 8, convertirAccents('SOUS-TOTAL'), 1, 1, 'R', true);

        // Contenu du tableau
        $pdf->SetFont($font, '', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(255, 255, 255);

        $total = 0;
        $alternate = false;

        foreach ($details as $index => $detail) {
            $sous_total = floatval($detail['prix_unitaire']) * intval($detail['quantite']);
            $total += $sous_total;

            // Alternance des couleurs de fond
            if ($alternate) {
                $pdf->SetFillColor(249, 250, 251); // Gris très clair
            } else {
                $pdf->SetFillColor(255, 255, 255); // Blanc
            }
            $alternate = !$alternate;

            $pdf->Cell(10, 8, $index + 1, 1, 0, 'C', true);

            // Nom du produit
            $produit_desc = convertirAccents($detail['produit_nom']);
            $pdf->MultiCell(70, 8, $produit_desc, 1, 'L', true);
            $pdf->SetXY($pdf->GetX() + 80, $pdf->GetY() - 8); // Répositionner après MultiCell

            $categorie = convertirAccents($detail['categorie_nom'] ?? '');
            $pdf->Cell(25, 8, substr($categorie, 0, 12), 1, 0, 'L', true);
            $pdf->Cell(20, 8, $detail['quantite'], 1, 0, 'C', true);
            $pdf->Cell(30, 8, formatMontant(floatval($detail['prix_unitaire'])), 1, 0, 'R', true);
            $pdf->Cell(35, 8, formatMontant($sous_total), 1, 1, 'R', true);
        }

        // ========== RÉCAPITULATIF FINANCIER ==========
        $pdf->Ln(5);
        $pdf->SetFont($font, 'B', 12);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->SetFillColor(240, 253, 244);
        $pdf->Cell(0, 8, convertirAccents('RECAPITULATIF FINANCIER'), 0, 1, 'L', true);
        $pdf->Ln(2);

        $pdf->SetFont($font, '', 10);
        $pdf->SetTextColor(0, 0, 0);

        // Calcul des montants
        $montant_total = floatval($commande['montant_total']);
        $frais_livraison = 0;
        $remise = max(0, $total - $montant_total);

        // Tableau récapitulatif
        $pdf->Cell(100, 8, convertirAccents('Total produits:'), 0, 0, 'R');
        $pdf->Cell(30, 8, formatMontant($total), 0, 1, 'R');

        $pdf->Cell(100, 8, convertirAccents('Frais de livraison:'), 0, 0, 'R');
        $pdf->Cell(30, 8, formatMontant($frais_livraison), 0, 1, 'R');

        if ($remise > 0) {
            $pdf->Cell(100, 8, convertirAccents('Remise appliquee:'), 0, 0, 'R');
            $pdf->SetTextColor(239, 68, 68); // Rouge pour la remise
            $pdf->Cell(30, 8, '-' . formatMontant($remise), 0, 1, 'R');
            $pdf->SetTextColor(0, 0, 0);
        }

        // Ligne de séparation avant le total
        $pdf->SetDrawColor(16, 185, 129);
        $pdf->SetLineWidth(0.3);
        $pdf->Line(110, $pdf->GetY(), 190, $pdf->GetY());
        $pdf->Ln(2);

        // Total
        $pdf->SetFont($font, 'B', 12);
        $pdf->SetTextColor(16, 185, 129);
        $pdf->Cell(100, 10, convertirAccents('MONTANT TOTAL:'), 0, 0, 'R');
        $pdf->Cell(30, 10, formatMontant($montant_total), 0, 1, 'R');

        $pdf->SetFont($font, '', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(100, 7, convertirAccents('Montant paye:'), 0, 0, 'R');
        $pdf->SetFont($font, 'B', 10);
        $pdf->Cell(30, 7, formatMontant($montant_total), 0, 1, 'R');

        $pdf->SetFont($font, '', 10);
        $pdf->Cell(100, 7, convertirAccents('Reste a payer:'), 0, 0, 'R');
        $pdf->Cell(30, 7, formatMontant(0), 0, 1, 'R');
        $pdf->Ln(10);

        // ========== INFORMATIONS SUPPLÉMENTAIRES ==========
        $pdf->SetFont($font, 'B', 12);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->SetFillColor(240, 253, 244);
        $pdf->Cell(0, 8, convertirAccents('INFORMATIONS SUPPLEMENTAIRES'), 0, 1, 'L', true);
        $pdf->Ln(2);

        $pdf->SetFont($font, '', 9);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->Cell(60, 6, convertirAccents("Nombre d'articles:"), 0, 0);
        $pdf->Cell(0, 6, count($details), 0, 1);

        $pdf->Cell(60, 6, convertirAccents('Quantite totale:'), 0, 0);
        $pdf->Cell(0, 6, array_sum(array_column($details, 'quantite')), 0, 1);

        $pdf->Cell(60, 6, convertirAccents('Date limite de retour:'), 0, 0);
        $pdf->Cell(0, 6, date('d/m/Y', strtotime('+30 days', strtotime($commande['date_commande']))), 0, 1);

        $pdf->Cell(60, 6, convertirAccents('Delai de livraison:'), 0, 0);
        $pdf->Cell(0, 6, '24-48h pour Kinshasa, 3-5 jours province', 0, 1);
        $pdf->Ln(8);

        // ========== INFORMATIONS SOCIÉTÉ ==========
        $pdf->SetFont($font, 'B', 10);
        $pdf->SetTextColor(16, 185, 129);
        $pdf->Cell(0, 6, 'NAGEX PHARMA SARL', 0, 1, 'C');

        $pdf->SetFont($font, '', 9);
        $pdf->SetTextColor(75, 85, 99);
        $pdf->Cell(0, 5, convertirAccents('Votre sante, notre priorite'), 0, 1, 'C');

        $pdf->SetFont($font, '', 8);
        $pdf->Cell(0, 4, '☎ +243 81 234 5678 | ✉ contact@nagexpharma.cd', 0, 1, 'C');
        $pdf->Cell(0, 4, convertirAccents('📍 Kinshasa, Republique Democratique du Congo'), 0, 1, 'C');
        $pdf->Ln(5);

        // ========== PIED DE PAGE ==========
        $pdf->SetY(-40);
        $pdf->SetFont($font, 'I', 8);
        $pdf->SetTextColor(107, 114, 128);

        // Ligne de séparation
        $pdf->SetDrawColor(16, 185, 129);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
        $pdf->Ln(5);

        $pdf->Cell(0, 4, convertirAccents('Merci pour votre confiance ! Nous sommes heureux de vous servir.'), 0, 1, 'C');
        $pdf->Cell(0, 4, convertirAccents("Ce document est une copie numerique du recu original et a valeur de justificatif d'achat."), 0, 1, 'C');
        $pdf->Ln(3);

        // Signature
        $pdf->Cell(0, 4, '___________________________________________', 0, 1, 'C');
        $pdf->Cell(0, 4, convertirAccents('Signature et cachet de l\'etablissement'), 0, 1, 'C');
        $pdf->Ln(2);

        // Informations techniques
        $pdf->SetFont($font, '', 7);
        $pdf->SetTextColor(156, 163, 175);
        $pdf->Cell(0, 3, convertirAccents('Document genere electroniquement le ') . date('d/m/Y a H:i:s'), 0, 1, 'C');
        $pdf->Cell(0, 3, 'ID de transaction: NXP-' . $commande_id . '-' . date('YmdHis'), 0, 1, 'C');

        // Retourner le PDF en tant que chaîne
        return $pdf->Output('S'); // 'S' pour retourner comme chaîne

    } catch (Exception $e) {
        error_log("Erreur generation PDF commande $commande_id: " . $e->getMessage());
        throw new Exception("Impossible de generer le PDF: " . $e->getMessage());
    }
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

/**
 * Génère le HTML des détails du produit pour le modal
 */
function genererHTMLDetailsProduit(PDO $pdo, int $produit_id, int $client_id): string
{
    try {
        // Récupérer les détails du produit
        $stmt = $pdo->prepare("
            SELECT 
                p.*,
                c.nom as categorie_nom,
                pv.prix_fc,
                pv.prix_usd,
                pr.type_promotion,
                pr.valeur as promo_valeur,
                COALESCE(SUM(l.quantite_actuelle), 0) as stock_total,
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
            LEFT JOIN lots l ON p.id = l.produit_id 
                AND l.statut = 'en_stock'
                AND l.date_expiration > CURDATE()
            WHERE p.id = :produit_id AND p.statut = 'actif'
            GROUP BY p.id
        ");

        $stmt->execute([
            ':produit_id' => $produit_id,
            ':client_id' => $client_id
        ]);

        $produit = $stmt->fetch();

        if (!$produit) {
            return '<div class="p-8 text-center"><p class="text-red-600">Produit non trouvé</p></div>';
        }

        ob_start();
        ?>
        <!-- Close Button -->
        <button onclick="fermerModalProduit()"
            class="absolute top-4 right-4 z-20 w-10 h-10 bg-white/90 backdrop-blur-sm rounded-full flex items-center justify-center shadow-lg hover:shadow-xl hover:scale-110 transition-all text-gray-600 hover:text-rose-600">
            <i class="fas fa-times text-xl"></i>
        </button>

        <!-- Product Header -->
        <div class="relative h-48 bg-gradient-to-r from-blue-500/10 to-cyan-500/10 overflow-hidden">
            <!-- Badges -->
            <div class="absolute top-4 left-4 flex flex-col space-y-2 z-10">
                <?php if (!empty($produit['promo_valeur']) && $produit['promo_valeur'] > 0): ?>
                    <span
                        class="inline-flex items-center px-3 py-1.5 bg-gradient-to-r from-rose-500 to-pink-600 text-white text-sm font-bold rounded-full shadow-lg animate-pulse">
                        <i class="fas fa-bolt mr-1.5"></i>
                        PROMO
                        -<?php echo $produit['type_promotion'] == 'pourcentage' ? $produit['promo_valeur'] . '%' : formatMontant($produit['promo_valeur']); ?>
                    </span>
                <?php endif; ?>

                <span
                    class="inline-flex items-center px-3 py-1.5 bg-emerald-100 text-emerald-800 text-sm font-semibold rounded-full shadow">
                    <i class="fas fa-tag mr-1.5"></i>
                    <?php echo e($produit['categorie_nom']); ?>
                </span>
            </div>

            <!-- Stock Status -->
            <div class="absolute top-4 right-4 z-10">
                <?php if ($produit['stock_total'] > 10): ?>
                    <span
                        class="inline-flex items-center px-3 py-1.5 bg-emerald-100 text-emerald-800 text-sm font-bold rounded-full shadow-lg">
                        <i class="fas fa-check-circle mr-1.5"></i>
                        En stock
                    </span>
                <?php elseif ($produit['stock_total'] > 0): ?>
                    <span
                        class="inline-flex items-center px-3 py-1.5 bg-amber-100 text-amber-800 text-sm font-bold rounded-full shadow-lg">
                        <i class="fas fa-exclamation-circle mr-1.5"></i>
                        Stock limité
                    </span>
                <?php else: ?>
                    <span
                        class="inline-flex items-center px-3 py-1.5 bg-rose-100 text-rose-800 text-sm font-bold rounded-full shadow-lg">
                        <i class="fas fa-times-circle mr-1.5"></i>
                        Rupture
                    </span>
                <?php endif; ?>
            </div>

            <!-- Product Image -->
            <div class="h-full flex items-center justify-center">
                <div class="text-center">
                    <div
                        class="w-24 h-24 mx-auto mb-4 bg-gradient-to-br from-blue-500 to-cyan-600 rounded-2xl flex items-center justify-center shadow-2xl">
                        <i class="fas fa-capsules text-white text-4xl"></i>
                    </div>
                    <p class="text-sm text-blue-600 font-semibold">NAGEX Pharma</p>
                </div>
            </div>
        </div>

        <!-- Scrollable Content -->
        <div class="p-6 overflow-y-auto max-h-[calc(90vh-12rem)]">
            <!-- Product Title -->
            <h1 class="text-2xl font-bold text-gray-900 mb-2"><?php echo e($produit['nom']); ?></h1>

            <!-- Product Code -->
            <div class="flex items-center text-gray-600 mb-4">
                <i class="fas fa-barcode mr-2 text-gray-400"></i>
                <span class="font-mono text-sm"><?php echo e($produit['code_barre'] ?? 'N/A'); ?></span>
            </div>

            <!-- Price Section -->
            <div class="bg-gradient-to-r from-emerald-50 to-teal-50 rounded-2xl p-5 mb-6 border border-emerald-200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm mb-1">Prix</p>
                        <div class="flex items-baseline space-x-3">
                            <span class="text-3xl font-bold text-emerald-600">
                                <?php echo formatMontant($produit['prix_final']); ?>
                            </span>

                            <?php if (!empty($produit['promo_valeur']) && $produit['promo_valeur'] > 0): ?>
                                <span class="text-lg text-gray-400 line-through">
                                    <?php echo formatMontant($produit['prix_fc']); ?>
                                </span>
                                <span class="text-sm text-rose-600 font-semibold">
                                    <i class="fas fa-save mr-1"></i>
                                    Économisez <?php echo formatMontant($produit['prix_fc'] - $produit['prix_final']); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($produit['prix_usd']) && $produit['prix_usd'] > 0): ?>
                            <p class="text-gray-600 text-sm mt-2">
                                <i class="fas fa-dollar-sign mr-1"></i>
                                ≈ $<?php echo number_format(floatval($produit['prix_usd'] ?? 0), 2); ?> USD
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Stock Info -->
                    <div class="text-right">
                        <p class="text-sm text-gray-600 mb-1">Disponibilité</p>
                        <p
                            class="text-lg font-bold <?php echo $produit['stock_total'] > 0 ? 'text-emerald-600' : 'text-rose-600'; ?>">
                            <?php echo $produit['stock_total']; ?> unités
                        </p>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div class="mb-6">
                <h3 class="text-lg font-bold text-gray-900 mb-3 flex items-center">
                    <i class="fas fa-file-alt text-blue-500 mr-2"></i>
                    Description
                </h3>
                <div class="bg-gray-50 rounded-xl p-4">
                    <p class="text-gray-700 leading-relaxed">
                        <?php echo nl2br(e($produit['description'] ?? 'Aucune description disponible.')); ?>
                    </p>
                </div>
            </div>

            <!-- Specifications Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <!-- Form and Dosage -->
                <div class="bg-white rounded-xl p-4 border border-gray-200">
                    <h4 class="font-bold text-gray-900 mb-2 flex items-center">
                        <i class="fas fa-pills text-purple-500 mr-2"></i>
                        Caractéristiques
                    </h4>
                    <div class="space-y-2">
                        <?php if (!empty($produit['forme']) && $produit['forme'] !== ''): ?>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Forme:</span>
                                <span class="font-semibold"><?php echo e($produit['forme']); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($produit['dosage']) && $produit['dosage'] !== ''): ?>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Dosage:</span>
                                <span class="font-semibold"><?php echo e($produit['dosage']); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($produit['fabricant']) && $produit['fabricant'] !== ''): ?>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Fabricant:</span>
                                <span class="font-semibold"><?php echo e($produit['fabricant']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Storage and Expiry -->
                <div class="bg-white rounded-xl p-4 border border-gray-200">
                    <h4 class="font-bold text-gray-900 mb-2 flex items-center">
                        <i class="fas fa-box text-amber-500 mr-2"></i>
                        Conservation
                    </h4>
                    <div class="space-y-2">
                        <?php if (!empty($produit['conditions_conservation']) && $produit['conditions_conservation'] !== ''): ?>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Conditions:</span>
                                <span class="font-semibold"><?php echo e($produit['conditions_conservation']); ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="flex justify-between">
                            <span class="text-gray-600">Statut:</span>
                            <span class="font-semibold text-emerald-600"><?php echo e($produit['statut'] ?? 'Actif'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ingredients -->
            <?php
            $ingredients = [];
            try {
                $stmtIngredients = $pdo->prepare("
                    SELECT i.nom, i.description 
                    FROM ingredients i
                    JOIN produit_ingredients pi ON i.id = pi.ingredient_id
                    WHERE pi.produit_id = :produit_id
                    LIMIT 3
                ");
                $stmtIngredients->execute([':produit_id' => $produit_id]);
                $ingredients = $stmtIngredients->fetchAll();
            } catch (Exception $e) {
                // Ignorer l'erreur des ingrédients, ce n'est pas critique
                error_log("Note: Pas d'ingrédients pour produit $produit_id - " . $e->getMessage());
            }
            ?>

            <?php if (!empty($ingredients)): ?>
                <div class="mb-6">
                    <h3 class="text-lg font-bold text-gray-900 mb-3 flex items-center">
                        <i class="fas fa-flask text-emerald-500 mr-2"></i>
                        Ingrédients principaux
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <?php foreach ($ingredients as $ingredient): ?>
                            <div class="bg-emerald-50 rounded-lg p-3">
                                <h4 class="font-bold text-emerald-800 text-sm"><?php echo e($ingredient['nom'] ?? ''); ?></h4>
                                <?php if (!empty($ingredient['description'])): ?>
                                    <p class="text-xs text-gray-600 mt-1"><?php echo e(substr($ingredient['description'], 0, 60)); ?>...</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Action Buttons -->
        <div class="border-t border-gray-200 p-6 bg-white">
            <div class="flex flex-col sm:flex-row items-center justify-between space-y-4 sm:space-y-0">
                <!-- Left Actions -->
                <div class="flex items-center space-x-4">
                    <!-- Favorites -->
                    <form method="POST" action="" class="inline"
                        onsubmit="event.preventDefault(); toggleFavoriModal(<?php echo $produit['id']; ?>, <?php echo $produit['est_favori'] ? 'true' : 'false'; ?>);">
                        <button type="submit"
                            class="inline-flex items-center px-4 py-2.5 bg-gradient-to-r from-rose-50 to-pink-50 border border-rose-200 text-rose-700 font-semibold rounded-xl hover:from-rose-100 hover:to-pink-100 hover:border-rose-300 transition-all group">
                            <i
                                class="fas fa-heart <?php echo $produit['est_favori'] ? 'fas text-rose-500' : 'far'; ?> mr-2"></i>
                            <?php echo $produit['est_favori'] ? 'Retirer' : 'Favoris'; ?>
                        </button>
                    </form>

                    <!-- Share -->
                    <button onclick="partagerProduit(<?php echo $produit['id']; ?>)"
                        class="inline-flex items-center px-4 py-2.5 bg-gradient-to-r from-blue-50 to-cyan-50 border border-blue-200 text-blue-700 font-semibold rounded-xl hover:from-blue-100 hover:to-cyan-100 hover:border-blue-300 transition-all group">
                        <i class="fas fa-share-alt mr-2"></i>
                        Partager
                    </button>
                </div>

                <!-- Add to Cart -->
                <div class="flex items-center space-x-4">
                    <!-- Quantity Selector -->
                    <div class="flex items-center bg-gray-100 rounded-xl" id="quantity-selector-<?php echo $produit['id']; ?>">
                        <button onclick="modifierQuantiteModal(<?php echo $produit['id']; ?>, -1)"
                            class="w-10 h-10 flex items-center justify-center text-gray-600 hover:text-gray-900 hover:bg-gray-200 rounded-l-xl transition-colors">
                            <i class="fas fa-minus"></i>
                        </button>
                        <div class="px-4 py-2">
                            <span id="quantite-display-<?php echo $produit['id']; ?>" class="font-bold text-gray-900">1</span>
                        </div>
                        <button onclick="modifierQuantiteModal(<?php echo $produit['id']; ?>, 1)"
                            class="w-10 h-10 flex items-center justify-center text-gray-600 hover:text-gray-900 hover:bg-gray-200 rounded-r-xl transition-colors">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>

                    <!-- Add to Cart Button -->
                    <form method="POST" action="" onsubmit="return ajouterAuPanierModal(<?php echo $produit['id']; ?>);">
                        <input type="hidden" name="action" value="ajouter_panier">
                        <input type="hidden" name="produit_id" value="<?php echo $produit['id']; ?>">
                        <input type="hidden" name="quantite" value="1" id="quantite-input-<?php echo $produit['id']; ?>">
                        <button type="submit"
                            class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-bold rounded-xl hover:from-emerald-600 hover:to-teal-700 shadow-lg hover:shadow-xl transition-all group"
                            <?php echo $produit['stock_total'] <= 0 ? 'disabled' : ''; ?>>
                            <i class="fas fa-cart-plus mr-3"></i>
                            Ajouter au panier
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php

        return ob_get_clean();

    } catch (Exception $e) {
        error_log("Erreur génération détails produit ID $produit_id: " . $e->getMessage());
        // Retourner uniquement le message d'erreur sans le HTML du modal
        return '<div class="p-8 text-center"><p class="text-red-600">Erreur technique: ' . htmlspecialchars($e->getMessage()) . '</p></div>';
    }
}

// ============================================
// ENDPOINT AJAX POUR DÉTAILS PRODUIT
// ============================================

if (isset($_GET['ajax']) && $_GET['ajax'] == 'details_produit' && isset($_GET['id'])) {
    $produit_id = intval($_GET['id']);
    echo genererHTMLDetailsProduit($pdo, $produit_id, $user_id);
    exit();
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
                        ON DUPLICATE KEY UPDATE date_ajout = NOW()
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

            case 'telecharger_recu':
                try {
                    $commande_id = intval($_POST['commande_id'] ?? 0);

                    // Vérifier si la commande existe et appartient au client
                    $stmt = $pdo->prepare("SELECT id, numero_commande FROM commandes WHERE id = :id AND client_id = :client_id");
                    $stmt->execute([':id' => $commande_id, ':client_id' => $user_id]);
                    $commande = $stmt->fetch();

                    if (!$commande) {
                        throw new Exception("Commande non trouvée ou non autorisée");
                    }

                    // Générer le PDF avec FPDF
                    $pdf_content = genererPDFRecu($pdo, $commande_id, $user_id);

                    if (empty($pdf_content)) {
                        throw new Exception("Le PDF généré est vide");
                    }

                    // Envoyer les headers pour le téléchargement du PDF
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: attachment; filename="Recu_NAGEX_' . $commande['numero_commande'] . '_' . date('Ymd_His') . '.pdf"');
                    header('Content-Length: ' . strlen($pdf_content));
                    header('Cache-Control: private, max-age=0, must-revalidate');
                    header('Pragma: public');
                    header('Expires: 0');

                    echo $pdf_content;
                    exit();

                } catch (Exception $e) {
                    // Afficher une alerte JavaScript avec l'erreur
                    echo '<script>
            alert("❌ Erreur lors du téléchargement: ' . addslashes($e->getMessage()) . '");
            window.history.back();
        </script>';
                    exit();
                }
                break;



            // METTRE À JOUR LE PROFIL
            case 'mettre_a_jour_profil':
                try {
                    $stmt = $pdo->prepare("
                        UPDATE utilisateurs SET
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
$categories = [];

// Informations du client
try {
    $stmt = $pdo->prepare("SELECT nom, email, telephone, adresse, created_at as date_inscription FROM utilisateurs WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $user_info = $stmt->fetch();
} catch (Exception $e) {
    error_log("Erreur chargement info client: " . $e->getMessage());
}

switch ($current_page) {
    case 'catalogue':
        try {
            // Récupérer les catégories pour le catalogue
            $stmt = $pdo->query("SELECT id, nom FROM categories WHERE statut = 'actif' ORDER BY nom");
            $categories = $stmt->fetchAll();

            // Récupérer les filtres
            $categorie_id = $_GET['categorie'] ?? null;
            $recherche = $_GET['recherche'] ?? '';
            $tri = $_GET['tri'] ?? 'nom';

            // Construction de la requête de base
            $query = "
                SELECT p.*, 
                       c.nom as categorie_nom,
                       pv.prix_fc, 
                       pv.prix_usd,
                       pr.type_promotion, 
                       pr.valeur as promo_valeur,
                       COALESCE(SUM(l.quantite_actuelle), 0) as stock_quantite,
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
                LEFT JOIN lots l ON p.id = l.produit_id 
                    AND l.statut = 'en_stock'
                    AND l.date_expiration > CURDATE()
                WHERE p.statut = 'actif'
            ";

            $params = [':client_id' => $user_id];

            // Ajout des conditions de filtrage
            if ($categorie_id) {
                $query .= " AND p.categorie_id = :categorie_id";
                $params[':categorie_id'] = $categorie_id;
            }

            if ($recherche) {
                $query .= " AND (p.nom LIKE :recherche OR p.description LIKE :recherche OR p.code_barre LIKE :recherche)";
                $params[':recherche'] = "%$recherche%";
            }

            // Groupe by
            $query .= " GROUP BY p.id";

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
                SELECT p.*, 
                       c.nom as categorie_nom,
                       pv.prix_fc, 
                       pv.prix_usd,
                       pr.type_promotion, 
                       pr.valeur as promo_valeur,
                       COALESCE(SUM(l.quantite_actuelle), 0) as stock_quantite,
                       1 as est_favori,
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
                LEFT JOIN lots l ON p.id = l.produit_id 
                    AND l.statut = 'en_stock'
                    AND l.date_expiration > CURDATE()
                WHERE f.client_id = :client_id AND p.statut = 'actif'
                GROUP BY p.id
                ORDER BY f.date_ajout ASC
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

                // Créer les placeholders et les paramètres nommés
                $placeholders = [];
                $params = [':client_id' => $user_id];
                $i = 0;

                foreach ($produit_ids as $produit_id) {
                    $param_name = ':produit_id_' . $i;
                    $placeholders[] = $param_name;
                    $params[$param_name] = $produit_id;
                    $i++;
                }

                $placeholders_str = implode(',', $placeholders);

                $stmt = $pdo->prepare("
                SELECT p.*, 
                       c.nom as categorie_nom,
                       pv.prix_fc, 
                       pv.prix_usd,
                       pr.type_promotion, 
                       pr.valeur as promo_valeur,
                       COALESCE(SUM(l.quantite_actuelle), 0) as stock_quantite,
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
                LEFT JOIN lots l ON p.id = l.produit_id 
                    AND l.statut = 'en_stock'
                    AND l.date_expiration > CURDATE()
                WHERE p.id IN ($placeholders_str) AND p.statut = 'actif'
                GROUP BY p.id
            ");

                $stmt->execute($params);
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
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.25),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
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
                        <p class="text-xs text-green-600 font-medium">Client Dashboard</p>
                    </div>
                </div>
            </div>
            <!-- ========== NAVIGATION LATÉRALE ========== -->
            <nav class="space-y-1">
                <!-- Tableau de bord -->
                <a href="?page=dashboard"
                    class="menu-item group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-300 ease-out <?php echo $current_page == 'dashboard' ? 'active-menu bg-gradient-to-r from-emerald-500 to-teal-600 text-white shadow-lg' : 'text-gray-600 hover:bg-gradient-to-r hover:from-emerald-50 hover:to-teal-50 hover:text-emerald-700 hover:shadow-md'; ?>">
                    <div class="relative">
                        <i
                            class="fas fa-home w-5 h-5 mr-3 <?php echo $current_page == 'dashboard' ? 'text-white' : 'text-gray-400 group-hover:text-emerald-500'; ?>"></i>
                    </div>
                    <span class="flex-1">Tableau de bord</span>
                    <?php if ($current_page == 'dashboard'): ?>
                        <div class="absolute -right-2 top-1/2 -translate-y-1/2 w-2 h-8 bg-emerald-500 rounded-l-lg"></div>
                    <?php endif; ?>
                </a>

                <!-- Section Boutique -->
                <div class="section-title">
                    <div
                        class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-emerald-600 flex items-center">
                        <div class="w-8 h-px bg-emerald-200 mr-3"></div>
                        <i class="fas fa-shopping-bag text-emerald-500 mr-2"></i>
                        <span>Boutique</span>
                        <div class="flex-1 h-px bg-emerald-200 ml-3"></div>
                    </div>
                </div>

                <!-- Catalogue produits -->
                <a href="?page=catalogue"
                    class="menu-item group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-300 ease-out <?php echo $current_page == 'catalogue' ? 'active-menu bg-gradient-to-r from-emerald-500 to-teal-600 text-white shadow-lg' : 'text-gray-600 hover:bg-gradient-to-r hover:from-emerald-50 hover:to-teal-50 hover:text-emerald-700 hover:shadow-md'; ?>">
                    <div class="relative">
                        <i
                            class="fas fa-store w-5 h-5 mr-3 <?php echo $current_page == 'catalogue' ? 'text-white' : 'text-gray-400 group-hover:text-emerald-500'; ?>"></i>
                    </div>
                    <span class="flex-1">Catalogue produits</span>
                    <?php if ($current_page == 'catalogue'): ?>
                        <div class="absolute -right-2 top-1/2 -translate-y-1/2 w-2 h-8 bg-emerald-500 rounded-l-lg"></div>
                    <?php endif; ?>
                </a>

                <!-- Favoris -->
                <a href="?page=favoris"
                    class="menu-item group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-300 ease-out <?php echo $current_page == 'favoris' ? 'active-menu bg-gradient-to-r from-emerald-500 to-teal-600 text-white shadow-lg' : 'text-gray-600 hover:bg-gradient-to-r hover:from-emerald-50 hover:to-teal-50 hover:text-emerald-700 hover:shadow-md'; ?>">
                    <div class="relative">
                        <i
                            class="fas fa-heart w-5 h-5 mr-3 <?php echo $current_page == 'favoris' ? 'text-white' : 'text-gray-400 group-hover:text-emerald-500'; ?>"></i>
                    </div>
                    <span class="flex-1">Mes favoris</span>
                    <?php if ($stats['produits_favoris'] > 0): ?>
                        <span
                            class="badge-danger ml-auto animate-pulse bg-gradient-to-r from-rose-500 to-pink-600 text-white text-xs font-bold py-1 px-2.5 rounded-full shadow-sm">
                            <?php echo $stats['produits_favoris']; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($current_page == 'favoris'): ?>
                        <div class="absolute -right-2 top-1/2 -translate-y-1/2 w-2 h-8 bg-emerald-500 rounded-l-lg"></div>
                    <?php endif; ?>
                </a>

                <!-- Section Commandes -->
                <div class="section-title">
                    <div
                        class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-emerald-600 flex items-center">
                        <div class="w-8 h-px bg-emerald-200 mr-3"></div>
                        <i class="fas fa-shopping-cart text-emerald-500 mr-2"></i>
                        <span>Commandes</span>
                        <div class="flex-1 h-px bg-emerald-200 ml-3"></div>
                    </div>
                </div>

                <!-- Panier -->
                <a href="?page=panier"
                    class="menu-item group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-300 ease-out <?php echo $current_page == 'panier' ? 'active-menu bg-gradient-to-r from-emerald-500 to-teal-600 text-white shadow-lg' : 'text-gray-600 hover:bg-gradient-to-r hover:from-emerald-50 hover:to-teal-50 hover:text-emerald-700 hover:shadow-md'; ?>">
                    <div class="relative">
                        <i
                            class="fas fa-cart-shopping w-5 h-5 mr-3 <?php echo $current_page == 'panier' ? 'text-white' : 'text-gray-400 group-hover:text-emerald-500'; ?>"></i>
                    </div>
                    <span class="flex-1">Mon panier</span>
                    <?php if (!empty($_SESSION['panier'])): ?>
                        <span
                            class="badge-warning ml-auto bg-gradient-to-r from-amber-400 to-orange-500 text-white text-xs font-bold py-1 px-2.5 rounded-full shadow-sm">
                            <?php echo array_sum($_SESSION['panier']); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($current_page == 'panier'): ?>
                        <div class="absolute -right-2 top-1/2 -translate-y-1/2 w-2 h-8 bg-emerald-500 rounded-l-lg"></div>
                    <?php endif; ?>
                </a>

                <!-- Historique commandes -->
                <a href="?page=commandes"
                    class="menu-item group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-300 ease-out <?php echo $current_page == 'commandes' ? 'active-menu bg-gradient-to-r from-emerald-500 to-teal-600 text-white shadow-lg' : 'text-gray-600 hover:bg-gradient-to-r hover:from-emerald-50 hover:to-teal-50 hover:text-emerald-700 hover:shadow-md'; ?>">
                    <div class="relative">
                        <i
                            class="fas fa-history w-5 h-5 mr-3 <?php echo $current_page == 'commandes' ? 'text-white' : 'text-gray-400 group-hover:text-emerald-500'; ?>"></i>
                    </div>
                    <span class="flex-1">Mes commandes</span>
                    <?php if ($stats['commandes_total'] > 0): ?>
                        <span
                            class="badge-info ml-auto bg-gradient-to-r from-blue-500 to-cyan-600 text-white text-xs font-bold py-1 px-2.5 rounded-full shadow-sm">
                            <?php echo $stats['commandes_total']; ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($current_page == 'commandes'): ?>
                        <div class="absolute -right-2 top-1/2 -translate-y-1/2 w-2 h-8 bg-emerald-500 rounded-l-lg"></div>
                    <?php endif; ?>
                </a>

                <!-- Section Compte -->
                <div class="section-title">
                    <div
                        class="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-emerald-600 flex items-center">
                        <div class="w-8 h-px bg-emerald-200 mr-3"></div>
                        <i class="fas fa-user-circle text-emerald-500 mr-2"></i>
                        <span>Mon compte</span>
                        <div class="flex-1 h-px bg-emerald-200 ml-3"></div>
                    </div>
                </div>

                <!-- Profil -->
                <a href="?page=profil"
                    class="menu-item group relative flex items-center px-4 py-3 text-sm font-medium rounded-xl transition-all duration-300 ease-out <?php echo $current_page == 'profil' ? 'active-menu bg-gradient-to-r from-emerald-500 to-teal-600 text-white shadow-lg' : 'text-gray-600 hover:bg-gradient-to-r hover:from-emerald-50 hover:to-teal-50 hover:text-emerald-700 hover:shadow-md'; ?>">
                    <div class="relative">
                        <i
                            class="fas fa-user-edit w-5 h-5 mr-3 <?php echo $current_page == 'profil' ? 'text-white' : 'text-gray-400 group-hover:text-emerald-500'; ?>"></i>
                    </div>
                    <span class="flex-1">Mon profil</span>
                    <?php if ($current_page == 'profil'): ?>
                        <div class="absolute -right-2 top-1/2 -translate-y-1/2 w-2 h-8 bg-emerald-500 rounded-l-lg"></div>
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
                                class="font-medium text-white"><?php echo e($_SESSION['user_nom'] ?? 'Client'); ?></span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-clock mr-2 text-gray-400"></i>
                            <span class="text-white">Connecté depuis <?php echo date('H:i'); ?></span>
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
                <!-- ========== TABLEAU DE BORD CLIENT ========== -->
                <div class="mb-8">
                    <!-- En-tête avec bienvenue -->
                    <div class="mb-8 text-center lg:text-left">
                        <div
                            class="inline-flex items-center justify-center lg:justify-start px-4 py-2 bg-gradient-to-r from-emerald-50 to-teal-50 rounded-2xl border border-emerald-100 mb-4">
                            <i class="fas fa-leaf text-emerald-500 mr-2"></i>
                            <span class="text-sm font-medium text-emerald-700">NAGEX Pharma - Espace Client</span>
                        </div>
                        <h1 class="text-3xl lg:text-4xl font-bold text-gray-900 tracking-tight mb-2">
                            Tableau de bord
                        </h1>
                        <p class="text-gray-600 text-lg">
                            Bonjour <span
                                class="font-semibold text-emerald-600"><?php echo isset($_SESSION['user_nom']) ? $_SESSION['user_nom'] : 'Client'; ?></span>,
                            bienvenue dans votre espace dédié
                        </p>
                    </div>

                    <!-- Cartes de statistiques améliorées -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
                        <!-- Carte : Commandes totales -->
                        <div
                            class="stat-card group relative bg-gradient-to-br from-white to-gray-50 p-6 rounded-2xl shadow-lg border border-gray-100 hover:shadow-xl transition-all duration-300 hover:-translate-y-1">
                            <div
                                class="absolute inset-0 bg-gradient-to-br from-purple-500/5 to-transparent rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                            </div>
                            <div class="relative flex items-center">
                                <div class="p-4 bg-gradient-to-br from-purple-500 to-violet-600 rounded-xl shadow-lg">
                                    <i class="fas fa-shopping-bag text-white text-2xl"></i>
                                </div>
                                <div class="ml-5">
                                    <p class="text-gray-500 text-sm font-medium mb-1">Commandes totales</p>
                                    <p class="text-3xl font-bold text-gray-900"><?php echo $stats['commandes_total']; ?></p>
                                    <div class="mt-2 text-xs text-purple-600 font-semibold flex items-center">
                                        <i class="fas fa-trend-up mr-1"></i>
                                        <span>Suivi complet</span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 pt-4 border-t border-gray-100">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">En attente :</span>
                                    <span
                                        class="font-semibold text-amber-600"><?php echo $stats['commandes_attente'] ?? 0; ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Carte : Commandes en cours -->
                        <div
                            class="stat-card group relative bg-gradient-to-br from-white to-gray-50 p-6 rounded-2xl shadow-lg border border-gray-100 hover:shadow-xl transition-all duration-300 hover:-translate-y-1">
                            <div
                                class="absolute inset-0 bg-gradient-to-br from-blue-500/5 to-transparent rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                            </div>
                            <div class="relative flex items-center">
                                <div class="p-4 bg-gradient-to-br from-blue-500 to-cyan-600 rounded-xl shadow-lg">
                                    <i class="fas fa-clock text-white text-2xl"></i>
                                </div>
                                <div class="ml-5">
                                    <p class="text-gray-500 text-sm font-medium mb-1">En cours</p>
                                    <p class="text-3xl font-bold text-gray-900"><?php echo $stats['commandes_encours']; ?>
                                    </p>
                                    <div class="mt-2 text-xs text-blue-600 font-semibold flex items-center">
                                        <i class="fas fa-spinner fa-spin mr-1"></i>
                                        <span>Traitement actif</span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 pt-4 border-t border-gray-100">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">Livraison :</span>
                                    <span
                                        class="font-semibold text-blue-600"><?php echo $stats['commandes_livraison'] ?? 0; ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Carte : Dépenses totales -->
                        <div
                            class="stat-card group relative bg-gradient-to-br from-white to-gray-50 p-6 rounded-2xl shadow-lg border border-gray-100 hover:shadow-xl transition-all duration-300 hover:-translate-y-1">
                            <div
                                class="absolute inset-0 bg-gradient-to-br from-emerald-500/5 to-transparent rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                            </div>
                            <div class="relative flex items-center">
                                <div class="p-4 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-xl shadow-lg">
                                    <i class="fas fa-chart-line text-white text-2xl"></i>
                                </div>
                                <div class="ml-5">
                                    <p class="text-gray-500 text-sm font-medium mb-1">Dépenses totales</p>
                                    <?php if (isset($commande) && !empty($commande)): ?>
                                        <p class="text-3xl font-bold text-gray-900">
                                            <?php echo formatMontant(floatval($commande['montant_total'] ?? 0)); ?>
                                        </p>
                                    <?php else: ?>
                                        <p class="text-3xl font-bold text-gray-900"><?php echo formatMontant(0); ?></p>
                                    <?php endif; ?>
                                    <div class="mt-2 text-xs text-emerald-600 font-semibold flex items-center">
                                        <i class="fas fa-wallet mr-1"></i>
                                        <span>Solde actuel</span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 pt-4 border-t border-gray-100">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">Moyenne :</span>
                                    <?php
                                    $avg = isset($stats['commandes_total']) && $stats['commandes_total'] > 0 && isset($commande['montant_total'])
                                        ? floatval($commande['montant_total']) / $stats['commandes_total']
                                        : 0;
                                    ?>
                                    <span class="font-semibold text-emerald-600"><?php echo formatMontant($avg); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Carte : Favoris -->
                        <div
                            class="stat-card group relative bg-gradient-to-br from-white to-gray-50 p-6 rounded-2xl shadow-lg border border-gray-100 hover:shadow-xl transition-all duration-300 hover:-translate-y-1">
                            <div
                                class="absolute inset-0 bg-gradient-to-br from-rose-500/5 to-transparent rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                            </div>
                            <div class="relative flex items-center">
                                <div class="p-4 bg-gradient-to-br from-rose-500 to-pink-600 rounded-xl shadow-lg">
                                    <i class="fas fa-heart text-white text-2xl"></i>
                                </div>
                                <div class="ml-5">
                                    <p class="text-gray-500 text-sm font-medium mb-1">Favoris</p>
                                    <p class="text-3xl font-bold text-gray-900"><?php echo $stats['produits_favoris']; ?>
                                    </p>
                                    <div class="mt-2 text-xs text-rose-600 font-semibold flex items-center">
                                        <i class="fas fa-star mr-1"></i>
                                        <span>Produits aimés</span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 pt-4 border-t border-gray-100">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">En stock :</span>
                                    <span
                                        class="font-semibold text-emerald-600"><?php echo $stats['favoris_stock'] ?? $stats['produits_favoris']; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Grille principale : Dernières commandes + Actions rapides -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                        <!-- Dernières commandes -->
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
                            <div class="p-6 border-b border-gray-100">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <div class="p-3 bg-gradient-to-br from-blue-100 to-cyan-100 rounded-xl mr-4">
                                            <i class="fas fa-history text-blue-600 text-xl"></i>
                                        </div>
                                        <div>
                                            <h2 class="text-xl font-bold text-gray-900">Dernières commandes</h2>
                                            <p class="text-sm text-gray-500">Vos 5 dernières transactions</p>
                                        </div>
                                    </div>
                                    <div class="text-xs font-semibold text-blue-600 bg-blue-50 px-3 py-1 rounded-full">
                                        <?php echo count($dernieres_commandes); ?> commandes
                                    </div>
                                </div>
                            </div>

                            <div class="p-6">
                                <?php if (count($dernieres_commandes) > 0): ?>
                                    <div class="space-y-4">
                                        <?php foreach ($dernieres_commandes as $index => $commande): ?>
                                            <div
                                                class="group relative bg-white p-4 rounded-xl border border-gray-200 hover:border-blue-200 hover:shadow-md transition-all duration-200">
                                                <div class="flex items-center justify-between">
                                                    <div class="flex-1">
                                                        <div class="flex items-center mb-2">
                                                            <span
                                                                class="font-bold text-gray-900 mr-3">#<?php echo e($commande['numero_commande']); ?></span>
                                                            <span class="text-xs px-2.5 py-1 rounded-full font-semibold <?php
                                                            echo $commande['statut'] == 'paye' ? 'bg-emerald-100 text-emerald-800' :
                                                                ($commande['statut'] == 'en_attente' ? 'bg-amber-100 text-amber-800' :
                                                                    ($commande['statut'] == 'expedie' ? 'bg-blue-100 text-blue-800' :
                                                                        'bg-rose-100 text-rose-800'));
                                                            ?>">
                                                                <?php echo ucfirst(str_replace('_', ' ', $commande['statut'])); ?>
                                                            </span>
                                                        </div>
                                                        <div class="flex items-center text-sm text-gray-600">
                                                            <i class="fas fa-calendar-day mr-2 text-gray-400"></i>
                                                            <span><?php echo date('d/m/Y', strtotime($commande['date_commande'])); ?></span>
                                                            <span class="mx-2">•</span>
                                                            <i class="fas fa-boxes mr-2 text-gray-400"></i>
                                                            <span><?php echo $commande['nombre_produits']; ?> produit(s)</span>
                                                        </div>
                                                    </div>
                                                    <div class="text-right">
                                                        <p class="text-lg font-bold text-emerald-600 mb-1">
                                                            <?php echo formatMontant($commande['montant_total']); ?>
                                                        </p>
                                                        <button onclick="voirDetails(<?php echo $commande['id']; ?>)"
                                                            class="text-sm text-blue-600 hover:text-blue-800 font-medium flex items-center justify-end">
                                                            Détails
                                                            <i class="fas fa-chevron-right ml-1 text-xs"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="mt-6 pt-6 border-t border-gray-100 text-center">
                                        <a href="?page=commandes"
                                            class="inline-flex items-center text-blue-600 hover:text-blue-800 font-semibold text-sm group">
                                            <span>Voir l'historique complet</span>
                                            <i
                                                class="fas fa-arrow-right ml-2 transform group-hover:translate-x-1 transition-transform duration-200"></i>
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-10">
                                        <div
                                            class="w-20 h-20 mx-auto mb-6 bg-gray-100 rounded-full flex items-center justify-center">
                                            <i class="fas fa-shopping-bag text-gray-400 text-3xl"></i>
                                        </div>
                                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Aucune commande</h3>
                                        <p class="text-gray-600 mb-6 max-w-sm mx-auto">
                                            Vous n'avez pas encore passé de commande. Découvrez notre catalogue pour commencer.
                                        </p>
                                        <a href="?page=catalogue"
                                            class="inline-flex items-center bg-gradient-to-r from-blue-600 to-cyan-600 hover:from-blue-700 hover:to-cyan-700 text-white font-semibold px-6 py-3 rounded-xl shadow-lg hover:shadow-xl transition-all duration-300">
                                            <i class="fas fa-store mr-3"></i>
                                            Explorer le catalogue
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Actions rapides -->
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
                            <div class="p-6 border-b border-gray-100">
                                <div class="flex items-center">
                                    <div class="p-3 bg-gradient-to-br from-emerald-100 to-teal-100 rounded-xl mr-4">
                                        <i class="fas fa-bolt text-emerald-600 text-xl"></i>
                                    </div>
                                    <div>
                                        <h2 class="text-xl font-bold text-gray-900">Actions rapides</h2>
                                        <p class="text-sm text-gray-500">Accédez rapidement aux fonctionnalités clés</p>
                                    </div>
                                </div>
                            </div>

                            <div class="p-6">
                                <div class="space-y-5">
                                    <!-- Action : Catalogue -->
                                    <a href="?page=catalogue"
                                        class="group relative flex items-center justify-between p-5 bg-gradient-to-r from-blue-50 to-cyan-50 rounded-xl border border-blue-200 hover:border-blue-300 hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5">
                                        <div class="flex items-center">
                                            <div
                                                class="p-3 bg-gradient-to-br from-blue-500 to-cyan-600 rounded-lg shadow-lg mr-4 group-hover:scale-110 transition-transform duration-300">
                                                <i class="fas fa-store text-white text-xl"></i>
                                            </div>
                                            <div>
                                                <p class="font-bold text-gray-900 text-lg mb-1">Parcourir le catalogue</p>
                                                <p class="text-sm text-gray-600">Découvrez +500 produits pharmaceutiques</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center">
                                            <span
                                                class="text-blue-600 font-semibold text-sm mr-3 hidden md:inline">Accéder</span>
                                            <i
                                                class="fas fa-chevron-right text-blue-400 text-lg group-hover:translate-x-2 transition-transform duration-300"></i>
                                        </div>
                                    </a>

                                    <!-- Action : Panier -->
                                    <a href="?page=panier"
                                        class="group relative flex items-center justify-between p-5 bg-gradient-to-r from-amber-50 to-orange-50 rounded-xl border border-amber-200 hover:border-amber-300 hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5">
                                        <div class="flex items-center">
                                            <div
                                                class="p-3 bg-gradient-to-br from-amber-500 to-orange-600 rounded-lg shadow-lg mr-4 group-hover:scale-110 transition-transform duration-300">
                                                <i class="fas fa-shopping-cart text-white text-xl"></i>
                                            </div>
                                            <div>
                                                <p class="font-bold text-gray-900 text-lg mb-1">Voir mon panier</p>
                                                <p class="text-sm text-gray-600">
                                                    <?php
                                                    $nb_articles = !empty($_SESSION['panier']) ? array_sum($_SESSION['panier']) : 0;
                                                    $article_text = $nb_articles == 1 ? 'article' : 'articles';
                                                    echo "$nb_articles $article_text en attente";
                                                    ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="flex items-center">
                                            <?php if ($nb_articles > 0): ?>
                                                <span
                                                    class="bg-gradient-to-r from-amber-500 to-orange-600 text-white text-xs font-bold py-1 px-2.5 rounded-full shadow-sm mr-3">
                                                    <?php echo $nb_articles; ?>
                                                </span>
                                            <?php endif; ?>
                                            <span
                                                class="text-amber-600 font-semibold text-sm mr-3 hidden md:inline">Voir</span>
                                            <i
                                                class="fas fa-chevron-right text-amber-400 text-lg group-hover:translate-x-2 transition-transform duration-300"></i>
                                        </div>
                                    </a>

                                    <!-- Action : Favoris -->
                                    <a href="?page=favoris"
                                        class="group relative flex items-center justify-between p-5 bg-gradient-to-r from-rose-50 to-pink-50 rounded-xl border border-rose-200 hover:border-rose-300 hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5">
                                        <div class="flex items-center">
                                            <div
                                                class="p-3 bg-gradient-to-br from-rose-500 to-pink-600 rounded-lg shadow-lg mr-4 group-hover:scale-110 transition-transform duration-300">
                                                <i class="fas fa-heart text-white text-xl"></i>
                                            </div>
                                            <div>
                                                <p class="font-bold text-gray-900 text-lg mb-1">Mes favoris</p>
                                                <p class="text-sm text-gray-600">
                                                    <?php echo $stats['produits_favoris']; ?> produit(s) sauvegardé(s)
                                                </p>
                                            </div>
                                        </div>
                                        <div class="flex items-center">
                                            <?php if ($stats['produits_favoris'] > 0): ?>
                                                <span
                                                    class="bg-gradient-to-r from-rose-500 to-pink-600 text-white text-xs font-bold py-1 px-2.5 rounded-full shadow-sm mr-3">
                                                    <?php echo $stats['produits_favoris']; ?>
                                                </span>
                                            <?php endif; ?>
                                            <span
                                                class="text-rose-600 font-semibold text-sm mr-3 hidden md:inline">Consulter</span>
                                            <i
                                                class="fas fa-chevron-right text-rose-400 text-lg group-hover:translate-x-2 transition-transform duration-300"></i>
                                        </div>
                                    </a>

                                    <!-- Action supplémentaire : Mon profil -->
                                    <a href="?page=profil"
                                        class="group relative flex items-center justify-between p-5 bg-gradient-to-r from-emerald-50 to-teal-50 rounded-xl border border-emerald-200 hover:border-emerald-300 hover:shadow-lg transition-all duration-300 hover:-translate-y-0.5">
                                        <div class="flex items-center">
                                            <div
                                                class="p-3 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-lg shadow-lg mr-4 group-hover:scale-110 transition-transform duration-300">
                                                <i class="fas fa-user-edit text-white text-xl"></i>
                                            </div>
                                            <div>
                                                <p class="font-bold text-gray-900 text-lg mb-1">Mon profil</p>
                                                <p class="text-sm text-gray-600">Gérez vos informations personnelles</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center">
                                            <span
                                                class="text-emerald-600 font-semibold text-sm mr-3 hidden md:inline">Gérer</span>
                                            <i
                                                class="fas fa-chevron-right text-emerald-400 text-lg group-hover:translate-x-2 transition-transform duration-300"></i>
                                        </div>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                >
            <?php elseif ($current_page == 'catalogue'): ?>
                <!-- ========== CATALOGUE PRODUITS ========== -->
                <div class="mb-8">
                    <!-- En-tête du catalogue -->
                    <div class="mb-8 text-center lg:text-left">
                        <div
                            class="inline-flex items-center justify-center lg:justify-start px-4 py-2 bg-gradient-to-r from-emerald-50 to-teal-50 rounded-2xl border border-emerald-100 mb-4">
                            <i class="fas fa-pills text-emerald-500 mr-2"></i>
                            <span class="text-sm font-medium text-emerald-700">Pharmacie NAGEX</span>
                        </div>
                        <h1 class="text-3xl lg:text-4xl font-bold text-gray-900 tracking-tight mb-2">Catalogue des produits
                        </h1>
                        <p class="text-gray-600 text-lg">Découvrez notre sélection de
                            <?php echo count($produits_catalogue); ?> produits pharmaceutiques de qualité
                        </p>
                    </div>

                    <!-- Filtres et recherche - Carte améliorée -->
                    <div
                        class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 p-6 mb-8">
                        <div class="flex items-center mb-6">
                            <div class="p-3 bg-gradient-to-br from-blue-100 to-cyan-100 rounded-xl mr-4">
                                <i class="fas fa-sliders-h text-blue-600 text-xl"></i>
                            </div>
                            <div>
                                <h2 class="text-xl font-bold text-gray-900">Filtrer & Rechercher</h2>
                                <p class="text-sm text-gray-500">Affinez votre recherche parmi nos produits</p>
                            </div>
                        </div>

                        <form method="GET" action="" class="space-y-6">
                            <input type="hidden" name="page" value="catalogue">

                            <!-- Grille de filtres -->
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                                <!-- Recherche -->
                                <div class="relative">
                                    <label class="block text-gray-700 text-sm font-semibold mb-2 pl-1">
                                        <i class="fas fa-search mr-2 text-blue-500"></i>Recherche
                                    </label>
                                    <div class="relative">
                                        <input type="text" id="recherche" name="recherche"
                                            value="<?php echo e($_GET['recherche'] ?? ''); ?>"
                                            class="w-full pl-11 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm hover:border-blue-300 transition-colors"
                                            placeholder="Nom, description, code...">
                                        <div class="absolute left-3 top-1/2 transform -translate-y-1/2">
                                            <i class="fas fa-search text-gray-400"></i>
                                        </div>
                                    </div>
                                </div>

                                <!-- Catégorie -->
                                <div>
                                    <label class="block text-gray-700 text-sm font-semibold mb-2 pl-1">
                                        <i class="fas fa-tag mr-2 text-emerald-500"></i>Catégorie
                                    </label>
                                    <div class="relative">
                                        <select id="categorie" name="categorie"
                                            class="w-full pl-10 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent shadow-sm hover:border-emerald-300 transition-colors appearance-none">
                                            <option value="">Toutes les catégories</option>
                                            <?php foreach ($categories ?? [] as $categorie): ?>
                                                <option value="<?php echo $categorie['id']; ?>" <?php echo (isset($_GET['categorie']) && $_GET['categorie'] == $categorie['id']) ? 'selected' : ''; ?>>
                                                    <?php echo e($categorie['nom']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="absolute left-3 top-1/2 transform -translate-y-1/2">
                                            <i class="fas fa-tag text-gray-400"></i>
                                        </div>
                                        <div class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                            <i class="fas fa-chevron-down text-gray-400"></i>
                                        </div>
                                    </div>
                                </div>

                                <!-- Tri -->
                                <div>
                                    <label class="block text-gray-700 text-sm font-semibold mb-2 pl-1">
                                        <i class="fas fa-sort-amount-down mr-2 text-purple-500"></i>Trier par
                                    </label>
                                    <div class="relative">
                                        <select id="tri" name="tri"
                                            class="w-full pl-10 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent shadow-sm hover:border-purple-300 transition-colors appearance-none">
                                            <option value="nom" <?php echo ($_GET['tri'] ?? 'nom') == 'nom' ? 'selected' : ''; ?>>Nom A-Z</option>
                                            <option value="prix_croissant" <?php echo ($_GET['tri'] ?? '') == 'prix_croissant' ? 'selected' : ''; ?>>Prix croissant</option>
                                            <option value="prix_decroissant" <?php echo ($_GET['tri'] ?? '') == 'prix_decroissant' ? 'selected' : ''; ?>>Prix décroissant</option>
                                            <option value="nouveautes" <?php echo ($_GET['tri'] ?? '') == 'nouveautes' ? 'selected' : ''; ?>>Nouveautés</option>
                                            <option value="populaires" <?php echo ($_GET['tri'] ?? '') == 'populaires' ? 'selected' : ''; ?>>Plus populaires</option>
                                        </select>
                                        <div class="absolute left-3 top-1/2 transform -translate-y-1/2">
                                            <i class="fas fa-sort text-gray-400"></i>
                                        </div>
                                        <div class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                            <i class="fas fa-chevron-down text-gray-400"></i>
                                        </div>
                                    </div>
                                </div>

                                <!-- Stock -->
                                <div>
                                    <label class="block text-gray-700 text-sm font-semibold mb-2 pl-1">
                                        <i class="fas fa-boxes mr-2 text-amber-500"></i>Disponibilité
                                    </label>
                                    <div class="relative">
                                        <select id="stock" name="stock"
                                            class="w-full pl-10 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent shadow-sm hover:border-amber-300 transition-colors appearance-none">
                                            <option value="">Tous les produits</option>
                                            <option value="en_stock" <?php echo ($_GET['stock'] ?? '') == 'en_stock' ? 'selected' : ''; ?>>En stock seulement</option>
                                            <option value="rupture" <?php echo ($_GET['stock'] ?? '') == 'rupture' ? 'selected' : ''; ?>>Rupture de stock</option>
                                        </select>
                                        <div class="absolute left-3 top-1/2 transform -translate-y-1/2">
                                            <i class="fas fa-box text-gray-400"></i>
                                        </div>
                                        <div class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                            <i class="fas fa-chevron-down text-gray-400"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Boutons d'action -->
                            <div
                                class="flex flex-col sm:flex-row justify-between items-center pt-4 border-t border-gray-100">
                                <div class="mb-4 sm:mb-0">
                                    <span class="text-sm text-gray-500">
                                        <span
                                            class="font-semibold text-emerald-600"><?php echo count($produits_catalogue); ?></span>
                                        produits trouvés
                                    </span>
                                </div>
                                <div class="flex space-x-3">
                                    <a href="?page=catalogue"
                                        class="inline-flex items-center px-5 py-2.5 border border-gray-300 rounded-xl text-gray-700 hover:bg-gray-50 hover:border-gray-400 font-medium transition-colors shadow-sm">
                                        <i class="fas fa-redo mr-2"></i>
                                        Réinitialiser
                                    </a>
                                    <button type="submit"
                                        class="inline-flex items-center px-6 py-2.5 bg-gradient-to-r from-green-600 to-green-600 hover:from-green-700 hover:to-green-700 text-white font-semibold rounded-xl shadow-lg hover:shadow-xl transition-all duration-300">
                                        <i class="fas fa-filter mr-3"></i>
                                        Appliquer les filtres
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Liste des produits -->
                    <?php if (count($produits_catalogue) > 0): ?>
                        <!-- Barre d'info résultats -->
                        <div
                            class="flex flex-col sm:flex-row justify-between items-center mb-6 p-4 bg-gradient-to-r from-blue-50 to-cyan-50 rounded-xl border border-blue-200">
                            <div class="flex items-center mb-3 sm:mb-0">
                                <i class="fas fa-cube text-blue-500 text-lg mr-3"></i>
                                <div>
                                    <span class="font-semibold text-gray-800"><?php echo count($produits_catalogue); ?>
                                        produits</span>
                                    <span class="text-gray-600 text-sm ml-2">correspondent à votre recherche</span>
                                </div>
                            </div>
                            <div class="flex items-center">
                                <i class="fas fa-lightbulb text-amber-500 mr-2"></i>
                                <span class="text-sm text-gray-600">
                                    <span
                                        class="font-semibold text-emerald-600"><?php echo $stats['en_promotion'] ?? 0; ?></span>
                                    produits en promotion
                                </span>
                            </div>
                        </div>

                        <!-- Grille des produits -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                            <?php foreach ($produits_catalogue as $produit): ?>
                                <div
                                    class="product-card group relative bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-200 hover:border-emerald-300 overflow-hidden transition-all duration-300 hover:-translate-y-2 hover:shadow-xl">
                                    <!-- Badge promotion -->
                                    <?php if ($produit['promo_valeur']): ?>
                                        <div class="absolute top-4 left-4 z-10">
                                            <span
                                                class="inline-flex items-center px-3 py-1.5 bg-gradient-to-r from-rose-500 to-pink-600 text-white text-xs font-bold rounded-full shadow-lg">
                                                <i class="fas fa-bolt mr-1.5"></i>
                                                <?php echo $produit['type_promotion'] == 'pourcentage' ?
                                                    '-' . $produit['promo_valeur'] . '%' :
                                                    '-' . formatMontant($produit['promo_valeur']);
                                                ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Badge favori -->
                                    <div class="absolute top-4 right-4 z-10">
                                        <form method="POST" action="" class="inline">
                                            <input type="hidden" name="action"
                                                value="<?php echo $produit['est_favori'] ? 'retirer_favori' : 'ajouter_favori'; ?>">
                                            <input type="hidden" name="produit_id" value="<?php echo $produit['id']; ?>">
                                            <button type="submit"
                                                class="p-2 bg-white/90 backdrop-blur-sm rounded-full shadow-md hover:shadow-lg transition-all hover:scale-110 <?php echo $produit['est_favori'] ? 'text-rose-500' : 'text-gray-400 hover:text-rose-400'; ?>">
                                                <i
                                                    class="fas fa-heart <?php echo $produit['est_favori'] ? 'fas' : 'far'; ?> text-lg"></i>
                                            </button>
                                        </form>
                                    </div>

                                    <!-- Image produit (placeholder) -->
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
                                            <?php echo e(substr($produit['description'] ?? 'Produit de qualité pharmaceutique', 0, 80)); ?>...
                                        </p>

                                        <!-- Prix -->
                                        <div class="mb-5">
                                            <div class="flex items-baseline space-x-2 mb-1">
                                                <span class="font-bold text-2xl text-emerald-600">
                                                    <?php echo formatMontant($produit['prix_final']); ?>
                                                </span>
                                                <?php if ($produit['promo_valeur']): ?>
                                                    <span class="text-sm text-gray-400 line-through">
                                                        <?php echo formatMontant($produit['prix_fc']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($produit['prix_usd']): ?>
                                                <p class="text-xs text-gray-500 flex items-center">
                                                    <i class="fas fa-dollar-sign mr-1.5"></i>
                                                    $<?php echo number_format(floatval($produit['prix_usd'] ?? 0), 2); ?> USD
                                                </p>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Stock -->
                                        <div class="mb-5">
                                            <div class="flex items-center justify-between">
                                                <span class="text-sm font-medium text-gray-700">Disponibilité</span>
                                                <?php if ($produit['stock_quantite'] > 10): ?>
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-1 bg-emerald-100 text-emerald-800 text-xs font-bold rounded-full">
                                                        <i class="fas fa-check-circle mr-1.5"></i>
                                                        En stock
                                                    </span>
                                                <?php elseif ($produit['stock_quantite'] > 0): ?>
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-1 bg-amber-100 text-amber-800 text-xs font-bold rounded-full">
                                                        <i class="fas fa-exclamation-circle mr-1.5"></i>
                                                        Stock limité
                                                    </span>
                                                <?php else: ?>
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-1 bg-rose-100 text-rose-800 text-xs font-bold rounded-full">
                                                        <i class="fas fa-times-circle mr-1.5"></i>
                                                        Rupture
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($produit['stock_quantite'] > 0): ?>
                                                <div class="mt-2">
                                                    <div class="w-full bg-gray-200 rounded-full h-1.5">
                                                        <?php
                                                        $pourcentage = min(100, ($produit['stock_quantite'] / ($produit['stock_quantite'] + 10)) * 100);
                                                        $couleur = $produit['stock_quantite'] > 10 ? 'bg-emerald-500' : 'bg-amber-500';
                                                        ?>
                                                        <div class="<?php echo $couleur; ?> h-1.5 rounded-full"
                                                            style="width: <?php echo $pourcentage; ?>%"></div>
                                                    </div>
                                                    <p class="text-xs text-gray-500 mt-1 text-right">
                                                        <?php echo $produit['stock_quantite']; ?> unités disponibles
                                                    </p>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Actions -->
                                        <div class="flex space-x-3">
                                            <!-- Bouton Détails -->
                                            <button onclick="afficherDetailsProduit(<?php echo $produit['id']; ?>)"
                                                class="flex-1 inline-flex items-center justify-center px-4 py-2.5 bg-gradient-to-r from-blue-50 to-cyan-50 border border-blue-200 text-blue-700 font-semibold rounded-xl hover:bg-gradient-to-r hover:from-blue-100 hover:to-cyan-100 hover:border-blue-300 hover:text-blue-800 transition-all group">
                                                <i class="fas fa-eye mr-2 group-hover:scale-110 transition-transform"></i>
                                                Détails
                                            </button>

                                            <!-- Bouton Ajouter au panier -->
                                            <form method="POST" action="" class="flex-1">
                                                <input type="hidden" name="action" value="ajouter_panier">
                                                <input type="hidden" name="produit_id" value="<?php echo $produit['id']; ?>">
                                                <input type="hidden" name="quantite" value="1">
                                                <button type="submit"
                                                    class="w-full inline-flex items-center justify-center px-4 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-semibold rounded-xl hover:from-emerald-600 hover:to-teal-700 shadow-md hover:shadow-lg transition-all group"
                                                    <?php echo $produit['stock_quantite'] <= 0 ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-cart-plus mr-2 group-hover:scale-110 transition-transform"></i>
                                                    Ajouter
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <!-- État vide amélioré -->
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 p-16 text-center">
                            <div class="max-w-md mx-auto">
                                <div
                                    class="w-24 h-24 mx-auto mb-8 bg-gradient-to-br from-blue-100 to-cyan-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-search text-blue-400 text-4xl"></i>
                                </div>
                                <h3 class="text-2xl font-bold text-gray-900 mb-3">Aucun produit trouvé</h3>
                                <p class="text-gray-600 mb-8">
                                    Aucun produit ne correspond à vos critères de recherche. Essayez de modifier vos filtres ou
                                    utilisez des termes de recherche différents.
                                </p>
                                <div class="space-y-4">
                                    <a href="?page=catalogue"
                                        class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-green-600 to-green-600 text-white font-semibold rounded-xl hover:from-green-700 hover:to-green-700 shadow-lg hover:shadow-xl transition-all">
                                        <i class="fas fa-redo mr-3"></i>
                                        Réinitialiser la recherche
                                    </a>

                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($current_page == 'favoris'): ?>
                <!-- ========== PAGE MES FAVORIS ========== -->
                <div class="mb-8">
                    <!-- En-tête avec compteur -->
                    <div class="mb-8 text-center lg:text-left">
                        <div
                            class="inline-flex items-center justify-center lg:justify-start px-4 py-2 bg-gradient-to-r from-green-50 to-pink-50 rounded-2xl border border-green-100 mb-4">
                            <i class="fas fa-heart text-green-500 mr-2"></i>
                            <span class="text-sm font-medium text-green-700">Vos préférences</span>
                        </div>
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between">
                            <div>
                                <h1 class="text-3xl lg:text-4xl font-bold text-gray-900 tracking-tight mb-2">Mes produits
                                    favoris</h1>
                                <p class="text-gray-600 text-lg">Vos produits préférés sauvegardés pour un accès rapide</p>
                            </div>
                            <?php if (count($produits_favoris) > 0): ?>
                                <div class="mt-4 lg:mt-0">
                                    <span
                                        class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-rose-500 to-pink-600 text-white font-bold rounded-full shadow-lg">
                                        <i class="fas fa-star mr-2"></i>
                                        <?php echo count($produits_favoris); ?>
                                        produit<?php echo count($produits_favoris) > 1 ? 's' : ''; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (count($produits_favoris) > 0): ?>
                        <!-- Statistiques des favoris -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                            <div class="bg-gradient-to-br from-rose-50 to-pink-50 rounded-2xl border border-rose-200 p-6">
                                <div class="flex items-center">
                                    <div class="p-3 bg-white rounded-xl shadow-sm mr-4">
                                        <i class="fas fa-tags text-rose-500 text-xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600 mb-1">Catégories</p>
                                        <p class="text-2xl font-bold text-gray-900">
                                            <?php
                                            $categories_uniques = array_unique(array_column($produits_favoris, 'categorie_nom'));
                                            echo count($categories_uniques);
                                            ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-gradient-to-br from-emerald-50 to-teal-50 rounded-2xl border border-emerald-200 p-6">
                                <div class="flex items-center">
                                    <div class="p-3 bg-white rounded-xl shadow-sm mr-4">
                                        <i class="fas fa-percent text-emerald-500 text-xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600 mb-1">En promotion</p>
                                        <p class="text-2xl font-bold text-gray-900">
                                            <?php
                                            $en_promotion = array_filter($produits_favoris, function ($p) {
                                                return $p['promo_valeur'] > 0;
                                            });
                                            echo count($en_promotion);
                                            ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-gradient-to-br from-blue-50 to-cyan-50 rounded-2xl border border-blue-200 p-6">
                                <div class="flex items-center">
                                    <div class="p-3 bg-white rounded-xl shadow-sm mr-4">
                                        <i class="fas fa-sack-dollar text-blue-500 text-xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600 mb-1">Valeur totale</p>
                                        <p class="text-2xl font-bold text-gray-900">
                                            <?php
                                            $total = array_sum(array_column($produits_favoris, 'prix_final'));
                                            echo formatMontant($total);
                                            ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Actions groupées -->
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-sm border border-gray-200 p-4 mb-8">
                            <div class="flex flex-col sm:flex-row justify-between items-center">
                                <div class="mb-4 sm:mb-0">
                                    <h3 class="font-semibold text-gray-900 text-lg mb-1">Gérer vos favoris</h3>
                                    <p class="text-sm text-gray-600">Actions rapides sur votre sélection</p>
                                </div>
                                <div class="flex space-x-3">
                                    <form method="POST" action="" class="flex-1">
                                        <input type="hidden" name="action" value="vider_favoris">
                                        <button type="submit"
                                            onclick="return confirm('Êtes-vous sûr de vouloir vider tous vos favoris ?')"
                                            class="inline-flex items-center px-4 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-xl hover:bg-gray-50 transition-colors">
                                            <i class="fas fa-trash-alt mr-2"></i>
                                            Tout vider
                                        </button>
                                    </form>
                                    <a href="?page=catalogue"
                                        class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-blue-600 to-cyan-600 text-white font-semibold rounded-xl hover:from-blue-700 hover:to-cyan-700 shadow-md hover:shadow-lg transition-all">
                                        <i class="fas fa-plus-circle mr-2"></i>
                                        Ajouter plus
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Grille des produits favoris -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                            <?php foreach ($produits_favoris as $index => $produit): ?>
                                <div
                                    class="product-card group relative bg-gradient-to-br from-white to-rose-50/30 rounded-2xl shadow-lg border border-rose-100 hover:border-rose-300 overflow-hidden transition-all duration-300 hover:-translate-y-2 hover:shadow-xl">
                                    <!-- Badge promotion -->
                                    <?php if ($produit['promo_valeur']): ?>
                                        <div class="absolute top-4 left-4 z-10">
                                            <span
                                                class="inline-flex items-center px-3 py-1.5 bg-gradient-to-r from-rose-500 to-pink-600 text-white text-xs font-bold rounded-full shadow-lg">
                                                <i class="fas fa-bolt mr-1.5"></i>
                                                <?php echo $produit['type_promotion'] == 'pourcentage' ?
                                                    '-' . $produit['promo_valeur'] . '%' :
                                                    '-' . formatMontant($produit['promo_valeur']);
                                                ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Numéro d'ordre (optionnel) -->
                                    <div class="absolute top-4 right-4 z-10">
                                        <span
                                            class="inline-flex items-center justify-center w-8 h-8 bg-rose-500 text-white text-xs font-bold rounded-full shadow-md">
                                            <?php echo $index + 1; ?>
                                        </span>
                                    </div>

                                    <!-- Image produit -->
                                    <div
                                        class="h-48 bg-gradient-to-br from-rose-50 to-pink-50 flex items-center justify-center overflow-hidden">
                                        <div class="text-center p-6">
                                            <i class="fas fa-heart text-4xl text-rose-300 mb-3"></i>
                                            <p class="text-xs text-rose-600 font-semibold">Votre favori</p>
                                        </div>
                                    </div>

                                    <!-- Corps de la carte -->
                                    <div class="p-5">
                                        <!-- Catégorie -->
                                        <div class="mb-3">
                                            <span
                                                class="inline-flex items-center px-3 py-1 bg-rose-100 text-rose-800 text-xs font-semibold rounded-full">
                                                <i class="fas fa-tag mr-1.5 text-xs"></i>
                                                <?php echo e($produit['categorie_nom']); ?>
                                            </span>
                                        </div>

                                        <!-- Nom produit -->
                                        <h3
                                            class="font-bold text-gray-900 text-lg mb-2 group-hover:text-rose-700 transition-colors line-clamp-1">
                                            <?php echo e($produit['nom']); ?>
                                        </h3>

                                        <!-- Description -->
                                        <p class="text-gray-600 text-sm mb-4 line-clamp-2 leading-relaxed">
                                            <?php echo e(substr($produit['description'] ?? 'Produit ajouté à vos favoris', 0, 80)); ?>...
                                        </p>

                                        <!-- Prix -->
                                        <div class="mb-5">
                                            <div class="flex items-baseline space-x-2 mb-1">
                                                <span class="font-bold text-2xl text-rose-600">
                                                    <?php echo formatMontant($produit['prix_final']); ?>
                                                </span>
                                                <?php if ($produit['promo_valeur']): ?>
                                                    <span class="text-sm text-gray-400 line-through">
                                                        <?php echo formatMontant($produit['prix_fc']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($produit['prix_usd']): ?>
                                                <p class="text-xs text-gray-500 flex items-center">
                                                    <i class="fas fa-dollar-sign mr-1.5"></i>
                                                    <?php echo number_format(floatval($produit['prix_usd'] ?? 0), 2); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Stock -->
                                        <div class="mb-5">
                                            <div class="flex items-center justify-between">
                                                <span class="text-sm font-medium text-gray-700">Disponibilité</span>
                                                <?php if ($produit['stock_quantite'] > 10): ?>
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-1 bg-emerald-100 text-emerald-800 text-xs font-bold rounded-full">
                                                        <i class="fas fa-check-circle mr-1.5"></i>
                                                        En stock
                                                    </span>
                                                <?php elseif ($produit['stock_quantite'] > 0): ?>
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-1 bg-amber-100 text-amber-800 text-xs font-bold rounded-full">
                                                        <i class="fas fa-exclamation-circle mr-1.5"></i>
                                                        Stock limité
                                                    </span>
                                                <?php else: ?>
                                                    <span
                                                        class="inline-flex items-center px-2.5 py-1 bg-rose-100 text-rose-800 text-xs font-bold rounded-full">
                                                        <i class="fas fa-times-circle mr-1.5"></i>
                                                        Rupture
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Actions -->
                                        <div class="flex space-x-3">
                                            <!-- Bouton Retirer -->
                                            <form method="POST" action="" class="flex-1">
                                                <input type="hidden" name="action" value="retirer_favori">
                                                <input type="hidden" name="produit_id" value="<?php echo $produit['id']; ?>">
                                                <button type="submit"
                                                    class="w-full inline-flex items-center justify-center px-4 py-2.5 bg-gradient-to-r from-gray-100 to-gray-200 border border-gray-300 text-gray-700 font-semibold rounded-xl hover:from-gray-200 hover:to-gray-300 hover:border-gray-400 hover:text-gray-800 transition-all group">
                                                    <i
                                                        class="fas fa-heart-broken mr-2 group-hover:scale-110 transition-transform"></i>
                                                    Retirer
                                                </button>
                                            </form>

                                            <!-- Bouton Ajouter au panier -->
                                            <form method="POST" action="" class="flex-1">
                                                <input type="hidden" name="action" value="ajouter_panier">
                                                <input type="hidden" name="produit_id" value="<?php echo $produit['id']; ?>">
                                                <input type="hidden" name="quantite" value="1">
                                                <button type="submit"
                                                    class="w-full inline-flex items-center justify-center px-4 py-2.5 bg-gradient-to-r from-rose-500 to-pink-600 text-white font-semibold rounded-xl hover:from-rose-600 hover:to-pink-700 shadow-md hover:shadow-lg transition-all group"
                                                    <?php echo $produit['stock_quantite'] <= 0 ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-cart-plus mr-2 group-hover:scale-110 transition-transform"></i>
                                                    Acheter
                                                </button>
                                            </form>
                                        </div>
                                    </div>

                                    <!-- Indicateur favori en bas -->
                                    <div
                                        class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-rose-400 to-pink-500 opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pied de page avec résumé -->
                        <div class="mt-10 pt-8 border-t border-gray-200">
                            <div class="bg-gradient-to-br from-rose-50 to-pink-50 rounded-2xl border border-rose-200 p-6">
                                <div class="flex flex-col md:flex-row justify-between items-center">
                                    <div class="mb-6 md:mb-0">
                                        <h3 class="text-lg font-bold text-gray-900 mb-2">Résumé de vos favoris</h3>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div class="text-center">
                                                <p class="text-sm text-gray-600">Produits en stock</p>
                                                <p class="text-2xl font-bold text-emerald-600">
                                                    <?php
                                                    $en_stock = array_filter($produits_favoris, function ($p) {
                                                        return $p['stock_quantite'] > 0;
                                                    });
                                                    echo count($en_stock);
                                                    ?>
                                                </p>
                                            </div>
                                            <div class="text-center">
                                                <p class="text-sm text-gray-600">Économie totale</p>
                                                <p class="text-2xl font-bold text-rose-600">
                                                    <?php
                                                    $economie = array_sum(array_map(function ($p) {
                                                        return $p['promo_valeur'] ? ($p['prix_fc'] - $p['prix_final']) : 0;
                                                    }, $produits_favoris));
                                                    echo formatMontant($economie);
                                                    ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-center md:text-right">
                                        <p class="text-sm text-gray-600 mb-2">Suggestion</p>
                                        <a href="?page=catalogue&categorie=<?php echo $produits_favoris[0]['categorie_id'] ?? ''; ?>"
                                            class="inline-flex items-center px-5 py-3 bg-gradient-to-r from-blue-600 to-cyan-600 text-white font-semibold rounded-xl hover:from-blue-700 hover:to-cyan-700 shadow-lg hover:shadow-xl transition-all">
                                            <i class="fas fa-arrow-right mr-2"></i>
                                            Explorer cette catégorie
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- État vide amélioré -->
                        <div
                            class="bg-gradient-to-br from-white to-green-50/30 rounded-2xl shadow-lg border border-green-100 p-16 text-center">
                            <div class="max-w-md mx-auto">
                                <div
                                    class="w-28 h-28 mx-auto mb-8 bg-gradient-to-br from-green-100 to-green-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-heart text-green-300 text-5xl"></i>
                                </div>
                                <h3 class="text-2xl font-bold text-gray-900 mb-3">Vos favoris sont vides</h3>
                                <p class="text-gray-600 mb-8 leading-relaxed">
                                    Vous n'avez pas encore ajouté de produit à vos favoris. Les favoris vous permettent de
                                    sauvegarder vos produits préférés pour y accéder rapidement plus tard.
                                </p>
                                <div class="space-y-4">
                                    <a href="?page=catalogue"
                                        class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white font-semibold rounded-xl hover:from-green-600 hover:to-green-700 shadow-lg hover:shadow-xl transition-all">
                                        <i class="fas fa-store mr-3"></i>
                                        Découvrir le catalogue
                                    </a>
                                    <div class="pt-4 border-t border-green-100">
                                        <p class="text-sm text-gray-500">
                                            <i class="fas fa-lightbulb text-amber-500 mr-2"></i>
                                            Astuce : Cliquez sur <i class="fas fa-heart text-green-400 mx-1"></i> pour ajouter
                                            des produits à vos favoris
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($current_page == 'panier'): ?>
                <!-- ========== PAGE MON PANIER ========== -->
                <div class="mb-8">
                    <!-- En-tête avec statistiques -->
                    <div class="mb-8">
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between mb-6">
                            <div class="mb-6 lg:mb-0">
                                <div
                                    class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-emerald-50 to-teal-50 rounded-2xl border border-emerald-100 mb-4">
                                    <i class="fas fa-shopping-basket text-emerald-500 mr-2"></i>
                                    <span class="text-sm font-medium text-emerald-700">Vos achats en cours</span>
                                </div>
                                <h1 class="text-3xl lg:text-4xl font-bold text-gray-900 tracking-tight mb-2">Mon panier</h1>
                                <p class="text-gray-600 text-lg">Gérez vos produits sélectionnés avant validation</p>
                            </div>

                            <?php if (!empty($panier_details)): ?>
                                <div class="flex items-center space-x-4">
                                    <!-- Compteur d'articles -->
                                    <div
                                        class="text-center px-4 py-2 bg-gradient-to-br from-blue-50 to-cyan-50 rounded-xl border border-blue-200">
                                        <p class="text-sm text-gray-600 mb-1">Articles</p>
                                        <p class="text-xl font-bold text-blue-600">
                                            <?php echo array_sum($_SESSION['panier'] ?? []); ?>
                                        </p>
                                    </div>

                                    <!-- Bouton Vider le panier -->
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="vider_panier">
                                        <button type="submit"
                                            onclick="return confirm('Êtes-vous sûr de vouloir vider complètement votre panier ?')"
                                            class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-rose-50 to-pink-50 border border-rose-200 text-rose-700 font-semibold rounded-xl hover:from-rose-100 hover:to-pink-100 hover:border-rose-300 hover:text-rose-800 transition-all group">
                                            <i class="fas fa-trash-alt mr-2 group-hover:scale-110 transition-transform"></i>
                                            Vider le panier
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Résumé rapide (si panier non vide) -->
                        <?php if (!empty($panier_details)): ?>
                            <?php
                            $total_panier = 0;
                            $total_articles = 0;
                            $economie_totale = 0;
                            foreach ($panier_details as $item) {
                                $total_panier += $item['sous_total'];
                                $total_articles += $item['quantite'];
                                if ($item['produit']['promo_valeur']) {
                                    $economie_totale += ($item['produit']['prix_fc'] - $item['produit']['prix_final']) * $item['quantite'];
                                }
                            }
                            ?>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                                <div class="bg-gradient-to-br from-blue-50 to-cyan-50 rounded-2xl border border-blue-200 p-5">
                                    <div class="flex items-center">
                                        <div class="p-3 bg-white rounded-xl shadow-sm mr-4">
                                            <i class="fas fa-boxes text-blue-500 text-xl"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600 mb-1">Articles totaux</p>
                                            <p class="text-2xl font-bold text-gray-900"><?php echo $total_articles; ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    class="bg-gradient-to-br from-emerald-50 to-teal-50 rounded-2xl border border-emerald-200 p-5">
                                    <div class="flex items-center">
                                        <div class="p-3 bg-white rounded-xl shadow-sm mr-4">
                                            <i class="fas fa-sack-dollar text-emerald-500 text-xl"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600 mb-1">Valeur totale</p>
                                            <p class="text-2xl font-bold text-gray-900">
                                                <?php echo formatMontant($total_panier); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    class="bg-gradient-to-br from-amber-50 to-orange-50 rounded-2xl border border-amber-200 p-5">
                                    <div class="flex items-center">
                                        <div class="p-3 bg-white rounded-xl shadow-sm mr-4">
                                            <i class="fas fa-percent text-amber-500 text-xl"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600 mb-1">Économies</p>
                                            <p class="text-2xl font-bold text-gray-900">
                                                <?php echo formatMontant($economie_totale); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Contenu principal -->
                    <?php if (!empty($panier_details)): ?>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                            <!-- Liste des produits (2/3 de largeur) -->
                            <div class="lg:col-span-2">
                                <div
                                    class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
                                    <!-- En-tête du tableau -->
                                    <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white">
                                        <div class="flex items-center">
                                            <div class="p-2 bg-gradient-to-br from-blue-100 to-cyan-100 rounded-lg mr-4">
                                                <i class="fas fa-list-alt text-blue-600"></i>
                                            </div>
                                            <div>
                                                <h2 class="text-xl font-bold text-gray-900">Contenu du panier</h2>
                                                <p class="text-sm text-gray-500"><?php echo $total_articles; ?> article(s)
                                                    sélectionné(s)</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Liste des produits -->
                                    <div class="divide-y divide-gray-100">
                                        <?php foreach ($panier_details as $item): ?>
                                            <div class="p-6 hover:bg-gray-50/50 transition-colors">
                                                <div class="flex flex-col md:flex-row md:items-center">
                                                    <!-- Informations produit -->
                                                    <div class="flex-1 mb-4 md:mb-0">
                                                        <div class="flex items-start">
                                                            <!-- Image produit (placeholder) -->
                                                            <div
                                                                class="w-16 h-16 bg-gradient-to-br from-blue-50 to-cyan-50 rounded-xl flex items-center justify-center mr-4">
                                                                <i class="fas fa-pills text-blue-400 text-xl"></i>
                                                            </div>

                                                            <!-- Détails produit -->
                                                            <div class="flex-1">
                                                                <div class="flex items-start justify-between">
                                                                    <div>
                                                                        <h3 class="font-bold text-gray-900 text-lg mb-1">
                                                                            <?php echo e($item['produit']['nom']); ?>
                                                                        </h3>
                                                                        <div class="flex items-center space-x-3">
                                                                            <span
                                                                                class="inline-flex items-center px-2.5 py-0.5 bg-emerald-100 text-emerald-800 text-xs font-semibold rounded-full">
                                                                                <i class="fas fa-tag mr-1 text-xs"></i>
                                                                                <?php echo e($item['produit']['categorie_nom']); ?>
                                                                            </span>
                                                                            <?php if ($item['produit']['promo_valeur']): ?>
                                                                                <span
                                                                                    class="inline-flex items-center px-2.5 py-0.5 bg-rose-100 text-rose-800 text-xs font-semibold rounded-full">
                                                                                    <i class="fas fa-bolt mr-1 text-xs"></i>
                                                                                    Promotion
                                                                                </span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>

                                                                    <!-- Bouton favori -->
                                                                    <form method="POST" action="" class="inline">
                                                                        <input type="hidden" name="action"
                                                                            value="<?php echo $item['produit']['est_favori'] ? 'retirer_favori' : 'ajouter_favori'; ?>">
                                                                        <input type="hidden" name="produit_id"
                                                                            value="<?php echo $item['produit']['id']; ?>">
                                                                        <button type="submit"
                                                                            class="p-2 text-gray-400 hover:text-rose-500 ml-4">
                                                                            <i
                                                                                class="fas fa-heart <?php echo $item['produit']['est_favori'] ? 'fas text-rose-500' : 'far'; ?>"></i>
                                                                        </button>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Contrôles quantité et prix -->
                                                    <div class="flex items-center justify-between md:justify-end space-x-6">
                                                        <!-- Prix unitaire -->
                                                        <div class="text-right">
                                                            <div class="flex flex-col items-end">
                                                                <span class="font-bold text-emerald-600 text-lg">
                                                                    <?php echo formatMontant($item['produit']['prix_final']); ?>
                                                                </span>
                                                                <?php if ($item['produit']['promo_valeur']): ?>
                                                                    <span class="text-sm text-gray-400 line-through">
                                                                        <?php echo formatMontant($item['produit']['prix_fc']); ?>
                                                                    </span>
                                                                    <span class="text-xs text-rose-600 font-semibold mt-1">
                                                                        <?php
                                                                        $economie = ($item['produit']['prix_fc'] - $item['produit']['prix_final']) * $item['quantite'];
                                                                        echo '- ' . formatMontant($economie);
                                                                        ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>

                                                        <!-- Contrôle quantité -->
                                                        <div class="flex items-center space-x-3">
                                                            <form method="POST" action="" class="flex items-center">
                                                                <input type="hidden" name="action" value="modifier_quantite">
                                                                <input type="hidden" name="produit_id"
                                                                    value="<?php echo $item['produit']['id']; ?>">

                                                                <!-- Bouton diminuer -->
                                                                <button type="submit" name="quantite"
                                                                    value="<?php echo max(1, $item['quantite'] - 1); ?>"
                                                                    class="w-8 h-8 flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition-colors">
                                                                    <i class="fas fa-minus text-xs"></i>
                                                                </button>

                                                                <!-- Quantité actuelle -->
                                                                <div class="mx-2 min-w-12 text-center">
                                                                    <span
                                                                        class="font-bold text-gray-900 text-lg"><?php echo $item['quantite']; ?></span>
                                                                </div>

                                                                <!-- Bouton augmenter -->
                                                                <button type="submit" name="quantite"
                                                                    value="<?php echo $item['quantite'] + 1; ?>"
                                                                    class="w-8 h-8 flex items-center justify-center bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition-colors">
                                                                    <i class="fas fa-plus text-xs"></i>
                                                                </button>
                                                            </form>
                                                        </div>

                                                        <!-- Sous-total -->
                                                        <div class="text-right min-w-24">
                                                            <p class="font-bold text-lg text-gray-900">
                                                                <?php echo formatMontant($item['sous_total']); ?>
                                                            </p>
                                                            <p class="text-xs text-gray-500">sous-total</p>
                                                        </div>

                                                        <!-- Bouton supprimer -->
                                                        <div class="ml-4">
                                                            <form method="POST" action="">
                                                                <input type="hidden" name="action" value="modifier_quantite">
                                                                <input type="hidden" name="produit_id"
                                                                    value="<?php echo $item['produit']['id']; ?>">
                                                                <input type="hidden" name="quantite" value="0">
                                                                <button type="submit"
                                                                    class="p-2 text-gray-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors">
                                                                    <i class="fas fa-trash-alt"></i>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Actions supplémentaires -->
                                <div class="mt-6 flex flex-col sm:flex-row justify-between items-center space-y-4 sm:space-y-0">
                                    <a href="?page=catalogue"
                                        class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-blue-50 to-cyan-50 border border-blue-200 text-blue-700 font-semibold rounded-xl hover:from-blue-100 hover:to-cyan-100 hover:border-blue-300 transition-all group">
                                        <i class="fas fa-arrow-left mr-2 group-hover:-translate-x-1 transition-transform"></i>
                                        Continuer mes achats
                                    </a>

                                    <div class="flex items-center space-x-3">
                                        <form method="POST" action="">
                                            <input type="hidden" name="action" value="sauvegarder_panier">
                                            <button type="submit"
                                                class="inline-flex items-center px-4 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-xl hover:bg-gray-50 transition-colors">
                                                <i class="fas fa-save mr-2"></i>
                                                Sauvegarder
                                            </button>
                                        </form>

                                        <form method="POST" action="">
                                            <input type="hidden" name="action" value="partager_panier">
                                            <button type="submit"
                                                class="inline-flex items-center px-4 py-2.5 bg-gradient-to-r from-emerald-50 to-teal-50 border border-emerald-200 text-emerald-700 font-medium rounded-xl hover:from-emerald-100 hover:to-teal-100 hover:border-emerald-300 transition-all">
                                                <i class="fas fa-share-alt mr-2"></i>
                                                Partager
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Récapitulatif (1/3 de largeur) -->
                            <div>
                                <div
                                    class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-xl border border-gray-100 p-6 sticky top-6">
                                    <!-- En-tête récapitulatif -->
                                    <div class="flex items-center mb-6">
                                        <div class="p-3 bg-gradient-to-br from-emerald-100 to-teal-100 rounded-xl mr-4">
                                            <i class="fas fa-receipt text-emerald-600 text-xl"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-xl font-bold text-gray-900">Récapitulatif</h3>
                                            <p class="text-sm text-gray-500">Détails de votre commande</p>
                                        </div>
                                    </div>

                                    <!-- Détails du récapitulatif -->
                                    <div class="space-y-4 mb-6">
                                        <!-- Sous-total -->
                                        <div class="flex justify-between items-center py-3 border-b border-gray-100">
                                            <div>
                                                <p class="font-medium text-gray-700">Sous-total</p>
                                                <p class="text-sm text-gray-500"><?php echo $total_articles; ?> article(s)</p>
                                            </div>
                                            <span
                                                class="font-bold text-lg text-gray-900"><?php echo formatMontant($total_panier); ?></span>
                                        </div>

                                        <!-- Frais de livraison -->
                                        <div class="flex justify-between items-center py-3 border-b border-gray-100">
                                            <div>
                                                <p class="font-medium text-gray-700">Frais de livraison</p>
                                                <p class="text-sm text-emerald-600 font-semibold">Livraison standard gratuite
                                                </p>
                                            </div>
                                            <span
                                                class="font-bold text-lg text-emerald-600"><?php echo formatMontant(0); ?></span>
                                        </div>

                                        <!-- Économies -->
                                        <?php if ($economie_totale > 0): ?>
                                            <div class="flex justify-between items-center py-3 border-b border-gray-100">
                                                <div>
                                                    <p class="font-medium text-gray-700">Économies réalisées</p>
                                                    <p class="text-sm text-rose-600 font-semibold">Promotions appliquées</p>
                                                </div>
                                                <span class="font-bold text-lg text-rose-600">-
                                                    <?php echo formatMontant($economie_totale); ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Total -->
                                        <div class="pt-4">
                                            <div class="flex justify-between items-center mb-2">
                                                <p class="font-bold text-lg text-gray-900">Total à payer</p>
                                                <p class="text-2xl font-bold text-emerald-600">
                                                    <?php echo formatMontant($total_panier); ?>
                                                </p>
                                            </div>
                                            <p class="text-sm text-gray-500 text-right">
                                                TVA incluse
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Bouton de validation -->
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="passer_commande">
                                        <button type="submit"
                                            class="w-full inline-flex items-center justify-center px-6 py-4 bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-600 hover:to-teal-700 text-white font-bold text-lg rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 group mb-4">
                                            <i class="fas fa-lock mr-3 group-hover:scale-110 transition-transform"></i>
                                            Passer la commande
                                        </button>
                                    </form>

                                    <!-- Paiement sécurisé -->
                                    <div class="text-center mb-6">
                                        <p class="text-xs text-gray-500 mb-2">Paiement 100% sécurisé</p>
                                        <div class="flex justify-center space-x-2">
                                            <i class="fas fa-shield-alt text-emerald-500"></i>
                                            <i class="fab fa-cc-visa text-blue-500"></i>
                                            <i class="fab fa-cc-mastercard text-red-500"></i>
                                            <i class="fab fa-cc-paypal text-blue-400"></i>
                                        </div>
                                    </div>

                                    <!-- Informations supplémentaires -->
                                    <div class="space-y-3 pt-4 border-t border-gray-100">
                                        <div class="flex items-start">
                                            <i class="fas fa-truck text-blue-500 mt-1 mr-3"></i>
                                            <div>
                                                <p class="text-sm font-medium text-gray-700">Livraison gratuite</p>
                                                <p class="text-xs text-gray-500">Délai de 24-48h pour Kinshasa</p>
                                            </div>
                                        </div>
                                        <div class="flex items-start">
                                            <i class="fas fa-undo-alt text-emerald-500 mt-1 mr-3"></i>
                                            <div>
                                                <p class="text-sm font-medium text-gray-700">Retour facile</p>
                                                <p class="text-xs text-gray-500">30 jours pour changer d'avis</p>
                                            </div>
                                        </div>
                                        <div class="flex items-start">
                                            <i class="fas fa-headset text-purple-500 mt-1 mr-3"></i>
                                            <div>
                                                <p class="text-sm font-medium text-gray-700">Support 24/7</p>
                                                <p class="text-xs text-gray-500">Assistance téléphonique</p>
                                            </div>
                                        </div>
                                    </div>
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
                                    <i class="fas fa-shopping-basket text-blue-400 text-5xl"></i>
                                </div>
                                <h3 class="text-2xl font-bold text-gray-900 mb-3">Votre panier est vide</h3>
                                <p class="text-gray-600 mb-8 leading-relaxed">
                                    Vous n'avez pas encore ajouté de produits à votre panier. Parcourez notre catalogue pour
                                    découvrir nos produits pharmaceutiques de qualité.
                                </p>
                                <div class="space-y-4">
                                    <a href="?page=catalogue"
                                        class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-semibold rounded-xl hover:from-emerald-600 hover:to-teal-700 shadow-lg hover:shadow-xl transition-all">
                                        <i class="fas fa-store mr-3"></i>
                                        Découvrir le catalogue
                                    </a>
                                    <div class="pt-4 border-t border-gray-100">
                                        <p class="text-sm text-gray-500">
                                            <i class="fas fa-lightbulb text-amber-500 mr-2"></i>
                                            Nos clients ajoutent en moyenne 3-4 produits par commande
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($current_page == 'commandes'): ?>
                <!-- ========== PAGE MES COMMANDES ========== -->
                <div class="mb-8">
                    <!-- En-tête avec filtres -->
                    <div class="mb-8">
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between mb-6">
                            <div class="mb-6 lg:mb-0">
                                <div
                                    class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-blue-50 to-cyan-50 rounded-2xl border border-blue-100 mb-4">
                                    <i class="fas fa-clipboard-list text-blue-500 mr-2"></i>
                                    <span class="text-sm font-medium text-blue-700">Historique d'achats</span>
                                </div>
                                <h1 class="text-3xl lg:text-4xl font-bold text-gray-900 tracking-tight mb-2">Mes commandes
                                </h1>
                                <p class="text-gray-600 text-lg">Suivez toutes vos commandes passées et en cours</p>
                            </div>

                            <?php if (count($commandes_client) > 0): ?>
                                <div class="flex items-center space-x-4">
                                    <!-- Statistiques rapides -->
                                    <div
                                        class="text-center px-4 py-2 bg-gradient-to-br from-emerald-50 to-teal-50 rounded-xl border border-emerald-200">
                                        <p class="text-sm text-gray-600 mb-1">Total commandes</p>
                                        <p class="text-xl font-bold text-emerald-600"><?php echo count($commandes_client); ?>
                                        </p>
                                    </div>

                                    <!-- Filtre par statut -->
                                    <form method="GET" action="" class="relative">
                                        <input type="hidden" name="page" value="commandes">
                                        <select name="statut" onchange="this.form.submit()"
                                            class="pl-10 pr-8 py-2 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent appearance-none">
                                            <option value="">Tous les statuts</option>
                                            <option value="paye" <?php echo ($_GET['statut'] ?? '') == 'paye' ? 'selected' : ''; ?>>Payées</option>
                                            <option value="en_attente" <?php echo ($_GET['statut'] ?? '') == 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                                            <option value="expedie" <?php echo ($_GET['statut'] ?? '') == 'expedie' ? 'selected' : ''; ?>>Expédiées</option>
                                            <option value="livre" <?php echo ($_GET['statut'] ?? '') == 'livre' ? 'selected' : ''; ?>>Livrées</option>
                                            <option value="annule" <?php echo ($_GET['statut'] ?? '') == 'annule' ? 'selected' : ''; ?>>Annulées</option>
                                        </select>
                                        <div class="absolute left-3 top-1/2 transform -translate-y-1/2">
                                            <i class="fas fa-filter text-gray-400"></i>
                                        </div>
                                        <div class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                            <i class="fas fa-chevron-down text-gray-400"></i>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Résumé des commandes -->
                        <?php if (count($commandes_client) > 0): ?>
                            <?php
                            $total_commandes = count($commandes_client);
                            $commandes_payees = array_filter($commandes_client, function ($c) {
                                return $c['statut'] == 'paye';
                            });
                            $commandes_attente = array_filter($commandes_client, function ($c) {
                                return $c['statut'] == 'en_attente';
                            });
                            $montant_total = array_sum(array_column($commandes_client, 'montant_total'));
                            ?>

                            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                                <div class="bg-gradient-to-br from-blue-50 to-cyan-50 rounded-2xl border border-blue-200 p-5">
                                    <div class="flex items-center">
                                        <div class="p-3 bg-white rounded-xl shadow-sm mr-4">
                                            <i class="fas fa-shopping-bag text-blue-500 text-xl"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600 mb-1">Total commandes</p>
                                            <p class="text-2xl font-bold text-gray-900"><?php echo $total_commandes; ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    class="bg-gradient-to-br from-emerald-50 to-teal-50 rounded-2xl border border-emerald-200 p-5">
                                    <div class="flex items-center">
                                        <div class="p-3 bg-white rounded-xl shadow-sm mr-4">
                                            <i class="fas fa-check-circle text-emerald-500 text-xl"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600 mb-1">Commandes payées</p>
                                            <p class="text-2xl font-bold text-gray-900"><?php echo count($commandes_payees); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    class="bg-gradient-to-br from-amber-50 to-orange-50 rounded-2xl border border-amber-200 p-5">
                                    <div class="flex items-center">
                                        <div class="p-3 bg-white rounded-xl shadow-sm mr-4">
                                            <i class="fas fa-clock text-amber-500 text-xl"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600 mb-1">En attente</p>
                                            <p class="text-2xl font-bold text-gray-900"><?php echo count($commandes_attente); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    class="bg-gradient-to-br from-purple-50 to-violet-50 rounded-2xl border border-purple-200 p-5">
                                    <div class="flex items-center">
                                        <div class="p-3 bg-white rounded-xl shadow-sm mr-4">
                                            <i class="fas fa-sack-dollar text-purple-500 text-xl"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm text-gray-600 mb-1">Montant total</p>
                                            <p class="text-2xl font-bold text-gray-900">
                                                <?php echo formatMontant($montant_total); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Contenu principal -->
                    <?php if (count($commandes_client) > 0): ?>
                        <!-- Navigation par statut -->
                        <div class="mb-6 overflow-x-auto">
                            <div class="flex space-x-2 pb-2">
                                <a href="?page=commandes"
                                    class="inline-flex items-center px-4 py-2 rounded-xl <?php echo empty($_GET['statut']) ? 'bg-gradient-to-r from-blue-600 to-cyan-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> transition-colors whitespace-nowrap">
                                    <i class="fas fa-layer-group mr-2"></i>
                                    Toutes (<?php echo $total_commandes; ?>)
                                </a>
                                <a href="?page=commandes&statut=paye"
                                    class="inline-flex items-center px-4 py-2 rounded-xl <?php echo ($_GET['statut'] ?? '') == 'paye' ? 'bg-gradient-to-r from-emerald-600 to-teal-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> transition-colors whitespace-nowrap">
                                    <i class="fas fa-check-circle mr-2"></i>
                                    Payées (<?php echo count($commandes_payees); ?>)
                                </a>
                                <a href="?page=commandes&statut=en_attente"
                                    class="inline-flex items-center px-4 py-2 rounded-xl <?php echo ($_GET['statut'] ?? '') == 'en_attente' ? 'bg-gradient-to-r from-amber-600 to-orange-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> transition-colors whitespace-nowrap">
                                    <i class="fas fa-clock mr-2"></i>
                                    En attente (<?php echo count($commandes_attente); ?>)
                                </a>
                                <a href="?page=commandes&statut=expedie"
                                    class="inline-flex items-center px-4 py-2 rounded-xl <?php echo ($_GET['statut'] ?? '') == 'expedie' ? 'bg-gradient-to-r from-blue-600 to-cyan-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> transition-colors whitespace-nowrap">
                                    <i class="fas fa-shipping-fast mr-2"></i>
                                    Expédiées
                                </a>
                                <a href="?page=commandes&statut=livre"
                                    class="inline-flex items-center px-4 py-2 rounded-xl <?php echo ($_GET['statut'] ?? '') == 'livre' ? 'bg-gradient-to-r from-purple-600 to-violet-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> transition-colors whitespace-nowrap">
                                    <i class="fas fa-home mr-2"></i>
                                    Livrées
                                </a>
                            </div>
                        </div>

                        <!-- Liste des commandes -->
                        <div class="space-y-4">
                            <?php foreach ($commandes_client as $commande): ?>
                                <div
                                    class="command-card group bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-200 hover:border-blue-200 overflow-hidden transition-all duration-300 hover:shadow-xl">
                                    <!-- En-tête de la commande -->
                                    <div class="p-6 border-b border-gray-100">
                                        <div class="flex flex-col lg:flex-row lg:items-center justify-between">
                                            <div class="mb-4 lg:mb-0">
                                                <div class="flex items-center mb-2">
                                                    <h3 class="font-bold text-gray-900 text-xl mr-4">
                                                        Commande #<?php echo e($commande['numero_commande']); ?>
                                                    </h3>
                                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold <?php
                                                    echo $commande['statut'] == 'paye' ? 'bg-emerald-100 text-emerald-800' :
                                                        ($commande['statut'] == 'en_attente' ? 'bg-amber-100 text-amber-800' :
                                                            ($commande['statut'] == 'expedie' ? 'bg-blue-100 text-blue-800' :
                                                                ($commande['statut'] == 'livre' ? 'bg-purple-100 text-purple-800' :
                                                                    ($commande['statut'] == 'annule' ? 'bg-rose-100 text-rose-800' :
                                                                        'bg-gray-100 text-gray-800'))));
                                                    ?>">
                                                        <i class="fas <?php
                                                        echo $commande['statut'] == 'paye' ? 'fa-check-circle' :
                                                            ($commande['statut'] == 'en_attente' ? 'fa-clock' :
                                                                ($commande['statut'] == 'expedie' ? 'fa-shipping-fast' :
                                                                    ($commande['statut'] == 'livre' ? 'fa-home' :
                                                                        ($commande['statut'] == 'annule' ? 'fa-times-circle' :
                                                                            'fa-info-circle'))));
                                                        ?> mr-1.5"></i>
                                                        <?php echo ucfirst(str_replace('_', ' ', $commande['statut'])); ?>
                                                    </span>
                                                </div>
                                                <div class="flex items-center text-sm text-gray-600">
                                                    <i class="fas fa-calendar-day mr-2 text-gray-400"></i>
                                                    <span><?php echo date('d/m/Y', strtotime($commande['date_commande'])); ?></span>
                                                    <span class="mx-2">•</span>
                                                    <i class="fas fa-clock mr-2 text-gray-400"></i>
                                                    <span><?php echo date('H:i', strtotime($commande['date_commande'])); ?></span>
                                                </div>
                                            </div>

                                            <div class="text-right">
                                                <p class="text-2xl font-bold text-emerald-600 mb-1">
                                                    <?php echo formatMontant($commande['montant_total']); ?>
                                                </p>
                                                <p class="text-sm text-gray-500">Montant total</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Détails de la commande -->
                                    <div class="p-6">
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                                            <!-- Informations produits -->
                                            <div class="bg-gradient-to-br from-blue-50 to-cyan-50 rounded-xl p-4">
                                                <div class="flex items-center mb-3">
                                                    <div class="p-2 bg-white rounded-lg mr-3">
                                                        <i class="fas fa-boxes text-blue-500"></i>
                                                    </div>
                                                    <div>
                                                        <p class="font-semibold text-gray-900">Articles</p>
                                                        <p class="text-sm text-gray-600">Contenu de la commande</p>
                                                    </div>
                                                </div>
                                                <div class="pl-10">
                                                    <p class="text-lg font-bold text-blue-600 mb-1">
                                                        <?php echo $commande['nombre_produits']; ?> produit(s)
                                                    </p>
                                                    <p class="text-sm text-gray-600">
                                                        <?php echo formatMontant($commande['montant_total'] / $commande['nombre_produits']); ?>
                                                        en moyenne
                                                    </p>
                                                </div>
                                            </div>

                                            <!-- Paiement -->
                                            <div class="bg-gradient-to-br from-emerald-50 to-teal-50 rounded-xl p-4">
                                                <div class="flex items-center mb-3">
                                                    <div class="p-2 bg-white rounded-lg mr-3">
                                                        <?php if ($commande['mode_paiement'] == 'especes'): ?>
                                                            <i class="fas fa-money-bill-wave text-emerald-500"></i>
                                                        <?php elseif ($commande['mode_paiement'] == 'carte'): ?>
                                                            <i class="fas fa-credit-card text-emerald-500"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-mobile-alt text-emerald-500"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <p class="font-semibold text-gray-900">Paiement</p>
                                                        <p class="text-sm text-gray-600">Mode de règlement</p>
                                                    </div>
                                                </div>
                                                <div class="pl-10">
                                                    <p class="text-lg font-bold text-emerald-600 mb-1">
                                                        <?php echo $commande['mode_paiement'] == 'especes' ? 'Espèces' :
                                                            ($commande['mode_paiement'] == 'carte' ? 'Carte bancaire' : 'Mobile Money'); ?>
                                                    </p>
                                                    <p
                                                        class="text-sm <?php echo $commande['statut'] == 'paye' ? 'text-emerald-600' : 'text-amber-600'; ?> font-semibold">
                                                        <?php echo $commande['statut'] == 'paye' ? 'Réglé' : 'En attente'; ?>
                                                    </p>
                                                </div>
                                            </div>

                                            <!-- Livraison -->
                                            <div class="bg-gradient-to-br from-purple-50 to-violet-50 rounded-xl p-4">
                                                <div class="flex items-center mb-3">
                                                    <div class="p-2 bg-white rounded-lg mr-3">
                                                        <i class="fas fa-truck text-purple-500"></i>
                                                    </div>
                                                    <div>
                                                        <p class="font-semibold text-gray-900">Livraison</p>
                                                        <p class="text-sm text-gray-600">Statut d'expédition</p>
                                                    </div>
                                                </div>
                                                <div class="pl-10">
                                                    <?php if (in_array($commande['statut'], ['expedie', 'livre'])): ?>
                                                        <p class="text-lg font-bold text-purple-600 mb-1">
                                                            <?php echo $commande['statut'] == 'livre' ? 'Livrée' : 'Expédiée'; ?>
                                                        </p>
                                                        <p class="text-sm text-gray-600">
                                                            <?php echo $commande['date_livraison'] ?? 'Date non disponible'; ?>
                                                        </p>
                                                    <?php else: ?>
                                                        <p class="text-lg font-bold text-amber-600 mb-1">En préparation</p>
                                                        <p class="text-sm text-gray-600">Délai estimé : 24-48h</p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Actions -->
                                        <div class="flex flex-wrap gap-3">


                                            <!-- Télécharger le reçu -->
                                            <?php if ($commande['statut'] == 'paye'): ?>
                                                <form method="POST" action="" class="inline" onsubmit="showReceiptLoader(this)">
                                                    <input type="hidden" name="action" value="telecharger_recu">
                                                    <input type="hidden" name="commande_id" value="<?php echo $commande['id']; ?>">
                                                    <button type="submit"
                                                        class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-semibold rounded-xl hover:from-emerald-700 hover:to-teal-700 shadow-md hover:shadow-lg transition-all group">
                                                        <i class="fas fa-file-pdf mr-2 group-hover:scale-110 transition-transform"></i>
                                                        <span class="receipt-text">Télécharger le reçu</span>
                                                        <span class="receipt-loader hidden ml-2">
                                                            <i class="fas fa-spinner fa-spin"></i>
                                                        </span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Suivi -->
                                            <?php if (in_array($commande['statut'], ['expedie', 'livre'])): ?>
                                                <button onclick="suivreLivraison(<?php echo $commande['id']; ?>)"
                                                    class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-semibold rounded-xl hover:from-emerald-700 hover:to-teal-700 shadow-md hover:shadow-lg transition-all group">
                                                    <i
                                                        class="fas fa-map-marker-alt mr-2 group-hover:scale-110 transition-transform"></i>
                                                    Suivre la livraison
                                                </button>
                                            <?php endif; ?>

                                            <!-- Répéter -->
                                            <form method="POST" action="" class="inline">
                                                <input type="hidden" name="action" value="repetition_commande">
                                                <input type="hidden" name="commande_id" value="<?php echo $commande['id']; ?>">
                                                <button type="submit"
                                                    class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-purple-600 to-violet-600 text-white font-semibold rounded-xl hover:from-purple-700 hover:to-violet-700 shadow-md hover:shadow-lg transition-all group">
                                                    <i class="fas fa-redo mr-2 group-hover:scale-110 transition-transform"></i>
                                                    Commander à nouveau
                                                </button>
                                            </form>

                                            <!-- Support -->
                                            <a href="https://wa.me/243974521216?text=Bonjour, j'aimerais me renseigner sur: <?php echo e($commande['numero_commande']); ?>"
                                                class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-amber-600 to-orange-600 text-white font-semibold rounded-xl hover:from-amber-700 hover:to-orange-700 shadow-md hover:shadow-lg transition-all group">
                                                <i class="fas fa-headset mr-2 group-hover:scale-110 transition-transform"></i>
                                                Contacter le support
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Pagination (exemple) -->
                        <div class="mt-8 flex justify-center">
                            <nav class="flex items-center space-x-2">
                                <button
                                    class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <button
                                    class="px-4 py-2 bg-gradient-to-r from-blue-600 to-cyan-600 text-white rounded-lg font-semibold">
                                    1
                                </button>
                                <button
                                    class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                    2
                                </button>
                                <button
                                    class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                    3
                                </button>
                                <span class="px-2 text-gray-500">...</span>
                                <button
                                    class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </nav>
                        </div>

                    <?php else: ?>
                        <!-- État vide amélioré -->
                        <div
                            class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 p-16 text-center">
                            <div class="max-w-md mx-auto">
                                <div
                                    class="w-28 h-28 mx-auto mb-8 bg-gradient-to-br from-blue-100 to-cyan-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-shopping-bag text-blue-400 text-5xl"></i>
                                </div>
                                <h3 class="text-2xl font-bold text-gray-900 mb-3">Aucune commande enregistrée</h3>
                                <p class="text-gray-600 mb-8 leading-relaxed">
                                    Vous n'avez pas encore passé de commande. Découvrez notre catalogue et profitez de nos
                                    produits pharmaceutiques de qualité avec livraison rapide.
                                </p>
                                <div class="space-y-4">
                                    <a href="?page=catalogue"
                                        class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-semibold rounded-xl hover:from-emerald-600 hover:to-teal-700 shadow-lg hover:shadow-xl transition-all">
                                        <i class="fas fa-store mr-3"></i>
                                        Découvrir le catalogue
                                    </a>
                                    <div class="pt-4 border-t border-gray-100">
                                        <p class="text-sm text-gray-500">
                                            <i class="fas fa-lightbulb text-amber-500 mr-2"></i>
                                            Première commande ? Profitez de 10% de réduction avec le code <span
                                                class="font-semibold text-emerald-600">BIENVENUE10</span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($current_page == 'profil'): ?>
                <!-- ========== PAGE MON PROFIL ========== -->
                <div class="mb-8">
                    <!-- En-tête avec avatar -->
                    <div class="mb-8">
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between mb-6">
                            <div class="mb-6 lg:mb-0">
                                <div
                                    class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-emerald-50 to-teal-50 rounded-2xl border border-emerald-100 mb-4">
                                    <i class="fas fa-user-circle text-emerald-500 mr-2"></i>
                                    <span class="text-sm font-medium text-emerald-700">Informations personnelles</span>
                                </div>
                                <h1 class="text-3xl lg:text-4xl font-bold text-gray-900 tracking-tight mb-2">Mon profil</h1>
                                <p class="text-gray-600 text-lg">Gérez vos informations personnelles et préférences</p>
                            </div>

                            <!-- Avatar et statut -->
                            <div class="flex items-center space-x-4">
                                <div class="relative">
                                    <div
                                        class="w-16 h-16 bg-gradient-to-br from-emerald-500 to-teal-600 rounded-full flex items-center justify-center text-white text-2xl font-bold shadow-lg">
                                        <?php echo substr($user_info['nom'] ?? 'U', 0, 1); ?>
                                    </div>
                                    <div
                                        class="absolute bottom-0 right-0 w-5 h-5 bg-emerald-500 border-2 border-white rounded-full">
                                    </div>
                                </div>
                                <div>
                                    <p class="font-bold text-gray-900"><?php echo e($user_info['nom'] ?? 'Utilisateur'); ?>
                                    </p>
                                    <p class="text-sm text-gray-500">Membre depuis
                                        <?php echo date('m/Y', strtotime($user_info['date_inscription'] ?? 'now')); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Grille principale -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                        <!-- Colonne gauche : Formulaire principal -->
                        <div class="lg:col-span-2">
                            <!-- Carte informations personnelles -->
                            <div
                                class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 p-6 mb-6">
                                <div class="flex items-center mb-6">
                                    <div class="p-3 bg-gradient-to-br from-emerald-100 to-teal-100 rounded-xl mr-4">
                                        <i class="fas fa-id-card text-emerald-600 text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold text-gray-900">Informations personnelles</h3>
                                        <p class="text-sm text-gray-500">Mettez à jour vos coordonnées</p>
                                    </div>
                                </div>

                                <form method="POST" action="" class="space-y-6">
                                    <input type="hidden" name="action" value="mettre_a_jour_profil">

                                    <!-- Grille de champs -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <!-- Nom complet -->
                                        <div class="relative">
                                            <label class="block text-gray-700 text-sm font-semibold mb-2 pl-1" for="nom">
                                                <span class="text-rose-500">*</span> Nom complet
                                            </label>
                                            <div class="relative">
                                                <input type="text" id="nom" name="nom" required
                                                    value="<?php echo e($user_info['nom'] ?? ''); ?>"
                                                    class="w-full pl-11 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent shadow-sm hover:border-emerald-300 transition-colors"
                                                    placeholder="Votre nom complet">
                                                <div class="absolute left-3 top-1/2 transform -translate-y-1/2">
                                                    <i class="fas fa-user text-gray-400"></i>
                                                </div>
                                            </div>
                                            <p class="text-xs text-gray-500 mt-1">Votre nom tel qu'il apparaîtra sur vos
                                                commandes</p>
                                        </div>

                                        <!-- Email -->
                                        <div class="relative">
                                            <label class="block text-gray-700 text-sm font-semibold mb-2 pl-1" for="email">
                                                <span class="text-rose-500">*</span> Adresse email
                                            </label>
                                            <div class="relative">
                                                <input type="email" id="email" name="email" required
                                                    value="<?php echo e($user_info['email'] ?? ''); ?>"
                                                    class="w-full pl-11 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent shadow-sm hover:border-emerald-300 transition-colors"
                                                    placeholder="votre@email.com">
                                                <div class="absolute left-3 top-1/2 transform -translate-y-1/2">
                                                    <i class="fas fa-envelope text-gray-400"></i>
                                                </div>
                                            </div>
                                            <p class="text-xs text-gray-500 mt-1">Nous ne partagerons jamais votre email</p>
                                        </div>

                                        <!-- Téléphone -->
                                        <div class="relative">
                                            <label class="block text-gray-700 text-sm font-semibold mb-2 pl-1"
                                                for="telephone">
                                                Numéro de téléphone
                                            </label>
                                            <div class="relative">
                                                <input type="text" id="telephone" name="telephone"
                                                    value="<?php echo e($user_info['telephone'] ?? ''); ?>"
                                                    class="w-full pl-11 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm hover:border-blue-300 transition-colors"
                                                    placeholder="+243 XX XX XX XX">
                                                <div class="absolute left-3 top-1/2 transform -translate-y-1/2">
                                                    <i class="fas fa-phone text-gray-400"></i>
                                                </div>
                                            </div>
                                            <p class="text-xs text-gray-500 mt-1">Pour les notifications de livraison</p>
                                        </div>

                                        <!-- Date de naissance -->
                                        <div class="relative">
                                            <label class="block text-gray-700 text-sm font-semibold mb-2 pl-1"
                                                for="date_naissance">
                                                Date de naissance
                                            </label>
                                            <div class="relative">
                                                <input type="date" id="date_naissance" name="date_naissance"
                                                    value="<?php echo e($user_info['date_naissance'] ?? ''); ?>"
                                                    class="w-full pl-11 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent shadow-sm hover:border-purple-300 transition-colors">
                                                <div class="absolute left-3 top-1/2 transform -translate-y-1/2">
                                                    <i class="fas fa-birthday-cake text-gray-400"></i>
                                                </div>
                                            </div>
                                            <p class="text-xs text-gray-500 mt-1">Optionnel - Pour les offres personnalisées
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Adresse -->
                                    <div class="relative">
                                        <label class="block text-gray-700 text-sm font-semibold mb-2 pl-1" for="adresse">
                                            Adresse complète
                                        </label>
                                        <div class="relative">
                                            <textarea id="adresse" name="adresse" rows="3"
                                                class="w-full pl-11 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm hover:border-blue-300 transition-colors resize-none"
                                                placeholder="Votre adresse complète pour la livraison"><?php echo e($user_info['adresse'] ?? ''); ?></textarea>
                                            <div class="absolute left-3 top-4">
                                                <i class="fas fa-map-marker-alt text-gray-400"></i>
                                            </div>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1">Rue, commune, ville, code postal</p>
                                    </div>

                                    <!-- Ville et code postal -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <!-- Ville -->
                                        <div class="relative">
                                            <label class="block text-gray-700 text-sm font-semibold mb-2 pl-1" for="ville">
                                                Ville
                                            </label>
                                            <div class="relative">
                                                <input type="text" id="ville" name="ville"
                                                    value="<?php echo e($user_info['ville'] ?? ''); ?>"
                                                    class="w-full pl-11 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm hover:border-blue-300 transition-colors"
                                                    placeholder="Kinshasa, Lubumbashi...">
                                                <div class="absolute left-3 top-1/2 transform -translate-y-1/2">
                                                    <i class="fas fa-city text-gray-400"></i>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Code postal -->
                                        <div class="relative">
                                            <label class="block text-gray-700 text-sm font-semibold mb-2 pl-1"
                                                for="code_postal">
                                                Code postal
                                            </label>
                                            <div class="relative">
                                                <input type="text" id="code_postal" name="code_postal"
                                                    value="<?php echo e($user_info['code_postal'] ?? ''); ?>"
                                                    class="w-full pl-11 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm hover:border-blue-300 transition-colors"
                                                    placeholder="XXXXX">
                                                <div class="absolute left-3 top-1/2 transform -translate-y-1/2">
                                                    <i class="fas fa-mailbox text-gray-400"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Boutons d'action -->
                                    <div class="flex flex-col sm:flex-row justify-between pt-6 border-t border-gray-100">
                                        <div class="mb-4 sm:mb-0">
                                            <p class="text-sm text-gray-500">
                                                <span class="text-rose-500">*</span> Champs obligatoires
                                            </p>
                                        </div>
                                        <div class="flex space-x-3">
                                            <a href="?page=dashboard"
                                                class="inline-flex items-center px-5 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-xl hover:bg-gray-50 transition-colors">
                                                <i class="fas fa-times mr-2"></i>
                                                Annuler
                                            </a>
                                            <button type="submit"
                                                class="inline-flex items-center px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 text-white font-semibold rounded-xl hover:from-emerald-600 hover:to-teal-700 shadow-lg hover:shadow-xl transition-all group">
                                                <i class="fas fa-save mr-2 group-hover:scale-110 transition-transform"></i>
                                                Enregistrer les modifications
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- Carte mot de passe -->
                            <div
                                class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 p-6">
                                <div class="flex items-center mb-6">
                                    <div class="p-3 bg-gradient-to-br from-amber-100 to-orange-100 rounded-xl mr-4">
                                        <i class="fas fa-lock text-amber-600 text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold text-gray-900">Sécurité du compte</h3>
                                        <p class="text-sm text-gray-500">Modifiez votre mot de passe</p>
                                    </div>
                                </div>

                                <form method="POST" action="" class="space-y-6">
                                    <input type="hidden" name="action" value="changer_mot_de_passe">

                                    <div class="space-y-4">
                                        <!-- Mot de passe actuel -->
                                        <div class="relative">
                                            <label class="block text-gray-700 text-sm font-semibold mb-2 pl-1"
                                                for="mot_de_passe_actuel">
                                                Mot de passe actuel
                                            </label>
                                            <div class="relative">
                                                <input type="password" id="mot_de_passe_actuel" name="mot_de_passe_actuel"
                                                    required
                                                    class="w-full pl-11 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent shadow-sm hover:border-amber-300 transition-colors"
                                                    placeholder="••••••••">
                                                <div class="absolute left-3 top-1/2 transform -translate-y-1/2">
                                                    <i class="fas fa-key text-gray-400"></i>
                                                </div>
                                                <button type="button"
                                                    class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                                    onclick="togglePassword('mot_de_passe_actuel', this)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Nouveau mot de passe -->
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div class="relative">
                                                <label class="block text-gray-700 text-sm font-semibold mb-2 pl-1"
                                                    for="nouveau_mot_de_passe">
                                                    Nouveau mot de passe
                                                </label>
                                                <div class="relative">
                                                    <input type="password" id="nouveau_mot_de_passe"
                                                        name="nouveau_mot_de_passe" required
                                                        class="w-full pl-11 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent shadow-sm hover:border-emerald-300 transition-colors"
                                                        placeholder="••••••••">
                                                    <div class="absolute left-3 top-1/2 transform -translate-y-1/2">
                                                        <i class="fas fa-lock text-gray-400"></i>
                                                    </div>
                                                    <button type="button"
                                                        class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                                        onclick="togglePassword('nouveau_mot_de_passe', this)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="relative">
                                                <label class="block text-gray-700 text-sm font-semibold mb-2 pl-1"
                                                    for="confirmer_mot_de_passe">
                                                    Confirmer le mot de passe
                                                </label>
                                                <div class="relative">
                                                    <input type="password" id="confirmer_mot_de_passe"
                                                        name="confirmer_mot_de_passe" required
                                                        class="w-full pl-11 pr-4 py-3 bg-white border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent shadow-sm hover:border-emerald-300 transition-colors"
                                                        placeholder="••••••••">
                                                    <div class="absolute left-3 top-1/2 transform -translate-y-1/2">
                                                        <i class="fas fa-lock text-gray-400"></i>
                                                    </div>
                                                    <button type="button"
                                                        class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                                        onclick="togglePassword('confirmer_mot_de_passe', this)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Indicateur de force -->
                                        <div class="bg-gray-50 rounded-lg p-4">
                                            <p class="text-sm font-semibold text-gray-700 mb-2">Force du mot de passe</p>
                                            <div class="flex items-center space-x-2 mb-2">
                                                <div class="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                                                    <div id="password-strength"
                                                        class="h-full w-0 bg-gray-400 transition-all duration-300"></div>
                                                </div>
                                                <span id="password-strength-text"
                                                    class="text-xs font-semibold text-gray-500">Faible</span>
                                            </div>
                                            <ul class="text-xs text-gray-500 space-y-1">
                                                <li class="flex items-center" id="length-check">
                                                    <i class="fas fa-circle text-gray-300 mr-2 text-xs"></i>
                                                    Au moins 8 caractères
                                                </li>
                                                <li class="flex items-center" id="uppercase-check">
                                                    <i class="fas fa-circle text-gray-300 mr-2 text-xs"></i>
                                                    Une majuscule
                                                </li>
                                                <li class="flex items-center" id="number-check">
                                                    <i class="fas fa-circle text-gray-300 mr-2 text-xs"></i>
                                                    Un chiffre
                                                </li>
                                                <li class="flex items-center" id="special-check">
                                                    <i class="fas fa-circle text-gray-300 mr-2 text-xs"></i>
                                                    Un caractère spécial
                                                </li>
                                            </ul>
                                        </div>
                                    </div>

                                    <!-- Bouton changer mot de passe -->
                                    <div class="flex justify-end pt-4 border-t border-gray-100">
                                        <button type="submit"
                                            class="inline-flex items-center px-6 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 text-white font-semibold rounded-xl hover:from-amber-600 hover:to-orange-700 shadow-lg hover:shadow-xl transition-all group">
                                            <i class="fas fa-key mr-2 group-hover:scale-110 transition-transform"></i>
                                            Changer le mot de passe
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Colonne droite : Informations et actions -->
                        <div class="space-y-6">
                            <!-- Carte statistiques -->
                            <div
                                class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 p-6">
                                <div class="flex items-center mb-6">
                                    <div class="p-3 bg-gradient-to-br from-blue-100 to-cyan-100 rounded-xl mr-4">
                                        <i class="fas fa-chart-bar text-blue-600 text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold text-gray-900">Votre activité</h3>
                                        <p class="text-sm text-gray-500">Résumé de votre compte</p>
                                    </div>
                                </div>

                                <div class="space-y-4">
                                    <div class="flex items-center justify-between py-3 border-b border-gray-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-shopping-bag text-blue-500 mr-3"></i>
                                            <div>
                                                <p class="font-medium text-gray-700">Commandes totales</p>
                                                <p class="text-sm text-gray-500">Depuis votre inscription</p>
                                            </div>
                                        </div>
                                        <span
                                            class="text-lg font-bold text-blue-600"><?php echo $stats['commandes_total'] ?? 0; ?></span>
                                    </div>

                                    <div class="flex items-center justify-between py-3 border-b border-gray-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-heart text-rose-500 mr-3"></i>
                                            <div>
                                                <p class="font-medium text-gray-700">Favoris</p>
                                                <p class="text-sm text-gray-500">Produits sauvegardés</p>
                                            </div>
                                        </div>
                                        <span
                                            class="text-lg font-bold text-rose-600"><?php echo $stats['produits_favoris'] ?? 0; ?></span>
                                    </div>

                                    <div class="flex items-center justify-between py-3">
                                        <div class="flex items-center">
                                            <i class="fas fa-star text-amber-500 mr-3"></i>
                                            <div>
                                                <p class="font-medium text-gray-700">Membre depuis</p>
                                                <p class="text-sm text-gray-500">Fidélité</p>
                                            </div>
                                        </div>
                                        <span class="text-lg font-bold text-amber-600">
                                            <?php
                                            $date_inscription = new DateTime($user_info['date_inscription'] ?? 'now');
                                            $today = new DateTime();
                                            $interval = $date_inscription->diff($today);
                                            echo $interval->format('%a') . ' jours';
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Carte préférences -->
                            <div
                                class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 p-6">
                                <div class="flex items-center mb-6">
                                    <div class="p-3 bg-gradient-to-br from-purple-100 to-violet-100 rounded-xl mr-4">
                                        <i class="fas fa-cog text-purple-600 text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold text-gray-900">Préférences</h3>
                                        <p class="text-sm text-gray-500">Personnalisez votre expérience</p>
                                    </div>
                                </div>

                                <div class="space-y-4">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="font-medium text-gray-700">Notifications email</p>
                                            <p class="text-sm text-gray-500">Promotions et nouvelles</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" class="sr-only peer" checked>
                                            <div
                                                class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500">
                                            </div>
                                        </label>
                                    </div>

                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="font-medium text-gray-700">Notifications SMS</p>
                                            <p class="text-sm text-gray-500">Livraisons et alertes</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" class="sr-only peer" checked>
                                            <div
                                                class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500">
                                            </div>
                                        </label>
                                    </div>

                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="font-medium text-gray-700">Mode sombre</p>
                                            <p class="text-sm text-gray-500">Interface sombre</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" class="sr-only peer">
                                            <div
                                                class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-gray-800">
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Carte actions compte -->
                            <div
                                class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-lg border border-gray-100 p-6">
                                <div class="flex items-center mb-6">
                                    <div class="p-3 bg-gradient-to-br from-rose-100 to-pink-100 rounded-xl mr-4">
                                        <i class="fas fa-exclamation-triangle text-rose-600 text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold text-gray-900">Actions du compte</h3>
                                        <p class="text-sm text-gray-500">Gestion avancée</p>
                                    </div>
                                </div>

                                <div class="space-y-3">
                                    <button onclick="exporterDonnees()"
                                        class="w-full flex items-center justify-between p-3 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-xl border border-blue-200 transition-colors group">
                                        <div class="flex items-center">
                                            <i class="fas fa-download mr-3"></i>
                                            <span>Exporter mes données</span>
                                        </div>
                                        <i
                                            class="fas fa-chevron-right text-blue-400 group-hover:translate-x-1 transition-transform"></i>
                                    </button>

                                    <button onclick="supprimerCompte()"
                                        class="w-full flex items-center justify-between p-3 bg-rose-50 hover:bg-rose-100 text-rose-700 rounded-xl border border-rose-200 transition-colors group">
                                        <div class="flex items-center">
                                            <i class="fas fa-trash-alt mr-3"></i>
                                            <span>Supprimer mon compte</span>
                                        </div>
                                        <i
                                            class="fas fa-chevron-right text-rose-400 group-hover:translate-x-1 transition-transform"></i>
                                    </button>

                                    <form method="POST" action="" class="w-full">
                                        <input type="hidden" name="action" value="deconnexion">
                                        <button type="submit"
                                            class="w-full flex items-center justify-between p-3 bg-gray-50 hover:bg-gray-100 text-gray-700 rounded-xl border border-gray-200 transition-colors group">
                                            <div class="flex items-center">
                                                <i class="fas fa-sign-out-alt mr-3"></i>
                                                <span>Déconnexion</span>
                                            </div>
                                            <i
                                                class="fas fa-chevron-right text-gray-400 group-hover:translate-x-1 transition-transform"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                    // Fonction pour basculer l'affichage du mot de passe
                    function togglePassword(inputId, button) {
                        const input = document.getElementById(inputId);
                        const icon = button.querySelector('i');

                        if (input.type === 'password') {
                            input.type = 'text';
                            icon.classList.remove('fa-eye');
                            icon.classList.add('fa-eye-slash');
                        } else {
                            input.type = 'password';
                            icon.classList.remove('fa-eye-slash');
                            icon.classList.add('fa-eye');
                        }
                    }

                    // Vérificateur de force du mot de passe
                    document.getElementById('nouveau_mot_de_passe')?.addEventListener('input', function (e) {
                        const password = e.target.value;
                        const strengthBar = document.getElementById('password-strength');
                        const strengthText = document.getElementById('password-strength-text');
                        const checks = {
                            length: document.getElementById('length-check'),
                            uppercase: document.getElementById('uppercase-check'),
                            number: document.getElementById('number-check'),
                            special: document.getElementById('special-check')
                        };

                        let strength = 0;
                        let totalChecks = 0;

                        // Longueur
                        if (password.length >= 8) {
                            checks.length.querySelector('i').className = 'fas fa-check-circle text-emerald-500 mr-2';
                            strength += 25;
                        } else {
                            checks.length.querySelector('i').className = 'fas fa-circle text-gray-300 mr-2';
                        }

                        // Majuscule
                        if (/[A-Z]/.test(password)) {
                            checks.uppercase.querySelector('i').className = 'fas fa-check-circle text-emerald-500 mr-2';
                            strength += 25;
                        } else {
                            checks.uppercase.querySelector('i').className = 'fas fa-circle text-gray-300 mr-2';
                        }

                        // Chiffre
                        if (/\d/.test(password)) {
                            checks.number.querySelector('i').className = 'fas fa-check-circle text-emerald-500 mr-2';
                            strength += 25;
                        } else {
                            checks.number.querySelector('i').className = 'fas fa-circle text-gray-300 mr-2';
                        }

                        // Caractère spécial
                        if (/[^A-Za-z0-9]/.test(password)) {
                            checks.special.querySelector('i').className = 'fas fa-check-circle text-emerald-500 mr-2';
                            strength += 25;
                        } else {
                            checks.special.querySelector('i').className = 'fas fa-circle text-gray-300 mr-2';
                        }

                        // Mettre à jour la barre et le texte
                        strengthBar.style.width = strength + '%';

                        if (strength < 50) {
                            strengthBar.style.backgroundColor = '#ef4444';
                            strengthText.textContent = 'Faible';
                            strengthText.className = 'text-xs font-semibold text-rose-500';
                        } else if (strength < 75) {
                            strengthBar.style.backgroundColor = '#f59e0b';
                            strengthText.textContent = 'Moyen';
                            strengthText.className = 'text-xs font-semibold text-amber-500';
                        } else {
                            strengthBar.style.backgroundColor = '#10b981';
                            strengthText.textContent = 'Fort';
                            strengthText.className = 'text-xs font-semibold text-emerald-500';
                        }
                    });

                    // Fonctions pour les actions du compte
                    function exporterDonnees() {
                        if (confirm('Voulez-vous exporter toutes vos données personnelles ?')) {
                            // Logique d'export ici
                            alert('Votre demande d\'export a été prise en compte. Vous recevrez un email avec vos données sous 24h.');
                        }
                    }

                    function supprimerCompte() {
                        if (confirm('Êtes-vous sûr de vouloir supprimer définitivement votre compte ? Cette action est irréversible.')) {
                            if (confirm('Toutes vos données seront effacées. Confirmez la suppression :')) {
                                window.location.href = '?page=profil&action=supprimer_compte';
                            }
                        }
                    }
                </script>

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


    <!-- Modal pour les détails du produit - Version simplifiée -->
    <div id="modalDetailsProduit" class="fixed inset-0 z-50 hidden">
        <!-- Overlay -->
        <div class="fixed inset-0 bg-black bg-opacity-50" onclick="fermerModalProduit()"></div>

        <!-- Modal Container -->
        <div class="relative h-screen w-screen flex items-center justify-center p-4">
            <!-- Modal Content -->
            <div id="modalContent"
                class="relative bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-auto">
                <!-- Le contenu sera chargé ici dynamiquement -->
            </div>
        </div>
    </div>

    <script>
        // Fonction pour afficher les détails du produit - VERSION CORRIGÉE
        function afficherDetailsProduit(produitId) {
            console.log('Afficher détails produit ID:', produitId);

            const modal = document.getElementById('modalDetailsProduit');
            const modalContent = document.getElementById('modalContent');

            if (!modal || !modalContent) {
                console.error('Modal non trouvé');
                alert('Erreur: Modal non trouvé');
                return;
            }

            // Afficher le modal
            modal.classList.remove('hidden');

            // Ajouter un overlay de chargement
            modalContent.innerHTML = `
        <div class="p-8 text-center">
            <div class="inline-block animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-blue-500 mb-4"></div>
            <p class="text-gray-600">Chargement des détails...</p>
        </div>
    `;

            // Charger les détails via AJAX
            fetch(`?ajax=details_produit&id=${produitId}`)
                .then(response => {
                    console.log('Réponse reçue, status:', response.status);
                    if (!response.ok) {
                        throw new Error('Erreur HTTP: ' + response.status);
                    }
                    return response.text();
                })
                .then(html => {
                    console.log('HTML reçu, longueur:', html.length);

                    // Vérifier si le HTML contient des erreurs
                    if (html.includes('Erreur technique') || html.includes('Produit non trouvé')) {
                        throw new Error('Erreur dans le contenu HTML');
                    }

                    // Vider le contenu avant d'insérer le nouveau
                    modalContent.innerHTML = '';

                    // Insérer le HTML
                    modalContent.innerHTML = html;

                    // Réinitialiser la quantité à 1
                    const quantiteDisplay = document.getElementById(`quantite-display-${produitId}`);
                    const quantiteInput = document.getElementById(`quantite-input-${produitId}`);
                    if (quantiteDisplay) quantiteDisplay.textContent = '1';
                    if (quantiteInput) quantiteInput.value = '1';

                    console.log('Modal chargé avec succès');
                })
                .catch(error => {
                    console.error('Erreur dans fetch:', error);
                    // Ne pas remplacer tout le contenu, juste ajouter un message d'erreur
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'bg-red-50 border-l-4 border-red-500 p-4 mt-4';
                    errorDiv.innerHTML = `
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-red-400"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-red-700">
                            Erreur: ${error.message}
                        </p>
                    </div>
                </div>
            `;
                    modalContent.appendChild(errorDiv);
                });
        }

        // Fonction pour fermer le modal
        function fermerModalProduit() {
            const modal = document.getElementById('modalDetailsProduit');
            if (modal) {
                modal.classList.add('hidden');
                // Vider le contenu après un court délai
                setTimeout(() => {
                    const modalContent = document.getElementById('modalContent');
                    if (modalContent) {
                        modalContent.innerHTML = '';
                    }
                }, 300);
            }
        }

        // Fonction pour modifier la quantité dans le modal
        function modifierQuantiteModal(produitId, delta) {
            const quantiteDisplay = document.getElementById(`quantite-display-${produitId}`);
            const quantiteInput = document.getElementById(`quantite-input-${produitId}`);

            if (!quantiteDisplay || !quantiteInput) {
                console.error('Éléments quantité non trouvés pour produit:', produitId);
                return;
            }

            let quantite = parseInt(quantiteDisplay.textContent) || 1;
            quantite += delta;

            // Limiter entre 1 et 99
            if (quantite < 1) quantite = 1;
            if (quantite > 99) quantite = 99;

            quantiteDisplay.textContent = quantite;
            quantiteInput.value = quantite;
        }

        // Fonction pour montrer l'indicateur de chargement
        function showReceiptLoader(form) {
            const button = form.querySelector('button');
            const text = button.querySelector('.receipt-text');
            const loader = button.querySelector('.receipt-loader');

            if (text && loader) {
                text.classList.add('hidden');
                loader.classList.remove('hidden');
                button.disabled = true;
            }

            // Réactiver le bouton après 5 secondes au cas où
            setTimeout(() => {
                if (text && loader) {
                    text.classList.remove('hidden');
                    loader.classList.add('hidden');
                    button.disabled = false;
                }
            }, 5000);
        }

        // Style CSS pour l'indicateur
        const style = document.createElement('style');
        style.textContent = `
    .hidden { display: none !important; }
    .receipt-loader i { animation: spin 1s linear infinite; }
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
`;
        document.head.appendChild(style);

        // Fonction pour ajouter au panier depuis le modal
        function ajouterAuPanierModal(produitId) {
            const quantiteInput = document.getElementById(`quantite-input-${produitId}`);
            const quantite = quantiteInput ? parseInt(quantiteInput.value) || 1 : 1;

            console.log('Ajouter au panier:', produitId, 'quantité:', quantite);

            // Créer un formulaire pour soumettre la requête
            const formData = new FormData();
            formData.append('action', 'ajouter_panier');
            formData.append('produit_id', produitId);
            formData.append('quantite', quantite);

            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    console.log('Produit ajouté au panier');
                    // Fermer le modal
                    fermerModalProduit();

                    // Afficher un message de confirmation
                    showToast('✅ Produit ajouté au panier!', 'success');

                    // Rafraîchir la page pour mettre à jour le compteur du panier
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                })
                .catch(error => {
                    console.error('Erreur ajout panier:', error);
                    showToast('❌ Erreur lors de l\'ajout au panier', 'error');
                });

            return false; // Empêcher la soumission normale du formulaire
        }

        // Fonction pour gérer les favoris dans le modal
        function toggleFavoriModal(produitId, estFavori) {
            const action = estFavori ? 'retirer_favori' : 'ajouter_favori';
            console.log('Toggle favori:', produitId, 'action:', action);

            const formData = new FormData();
            formData.append('action', action);
            formData.append('produit_id', produitId);

            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(response => {
                    const message = estFavori ? '✅ Retiré des favoris!' : '✅ Ajouté aux favoris!';
                    showToast(message, 'success');
                    // Rafraîchir le modal pour mettre à jour l'état
                    afficherDetailsProduit(produitId);
                })
                .catch(error => {
                    console.error('Erreur toggle favori:', error);
                    showToast('❌ Erreur lors de la mise à jour', 'error');
                });
        }

        // Fonction pour partager un produit
        function partagerProduit(produitId) {
            const url = window.location.origin + window.location.pathname + '?page=catalogue&produit=' + produitId;
            const title = document.title;

            if (navigator.share) {
                navigator.share({
                    title: title,
                    text: 'Découvrez ce produit sur NAGEX Pharma',
                    url: url
                });
            } else {
                // Fallback pour copier dans le presse-papier
                navigator.clipboard.writeText(url);
                showToast('✅ Lien copié dans le presse-papier!', 'success');
            }
        }

        // Fonction pour afficher des notifications toast
        function showToast(message, type = 'info') {
            // Créer l'élément toast
            const toast = document.createElement('div');
            toast.className = `fixed top-4 right-4 z-[110] px-6 py-3 rounded-xl shadow-xl transform transition-all duration-300 translate-x-full ${type === 'success' ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-rose-100 text-rose-800 border border-rose-300'}`;
            toast.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-3"></i>
            <span>${message}</span>
        </div>
    `;

            document.body.appendChild(toast);

            // Animation d'entrée
            setTimeout(() => {
                toast.classList.remove('translate-x-full');
                toast.classList.add('translate-x-0');
            }, 10);

            // Supprimer après 3 secondes
            setTimeout(() => {
                toast.classList.remove('translate-x-0');
                toast.classList.add('translate-x-full');
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 3000);
        }

        // Fermer le modal avec la touche Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                fermerModalProduit();
            }
        });

        // Fermer en cliquant en dehors du modal
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('modalDetailsProduit');
            if (modal) {
                modal.addEventListener('click', function (event) {
                    // Si on clique sur l'overlay (l'élément modal lui-même, pas son contenu)
                    if (event.target === this) {
                        fermerModalProduit();
                    }
                });
            }
        });

        // Fonction de debug
        function debugModal() {
            console.log('=== DEBUG MODAL ===');
            console.log('Modal:', document.getElementById('modalDetailsProduit'));
            console.log('Modal content:', document.getElementById('modalContent'));
            console.log('URL test:', `?ajax=details_produit&id=1`);

            // Tester avec un ID de produit connu
            afficherDetailsProduit(1);
        }
    </script>
</body>

</html>