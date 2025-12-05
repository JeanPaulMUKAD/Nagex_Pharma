<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réinitialisation - NAGEX Pharma</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .pharma-green { background-color: #10B981; }
        .pharma-green:hover { background-color: #059669; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-4xl w-full bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col md:flex-row">
        <!-- Section Photo -->
        <div class="md:w-1/2 bg-green-50 flex items-center justify-center p-8">
            <div class="text-center">
                <img src="https://images.unsplash.com/photo-1599045118108-bf9954418b76?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=500&q=80" 
                     alt="Sécurité" class="rounded-2xl shadow-lg mx-auto w-64 h-64 object-cover">
                <h2 class="text-2xl font-bold text-green-800 mt-6">Sécurité du compte</h2>
                <p class="text-green-600 mt-2">Protégez votre accès professionnel</p>
            </div>
        </div>

        <!-- Section Formulaire -->
        <div class="md:w-1/2 p-8 md:p-12 flex items-center justify-center">
            <div class="w-full max-w-md">
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold text-gray-900">Mot de passe oublié</h1>
                    <p class="text-gray-600 mt-2">Réinitialisez votre mot de passe en 3 étapes</p>
                </div>

                <form action="reset_process.php" method="POST" class="space-y-6">
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <i class="fas fa-info-circle text-yellow-600 mr-2"></i>
                            <p class="text-sm text-yellow-800">
                                Un lien de réinitialisation vous sera envoyé par email.
                            </p>
                        </div>
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-envelope mr-2"></i>Email professionnel
                        </label>
                        <input type="email" id="email" name="email" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all duration-200"
                               placeholder="votre@email.com">
                    </div>

                    <button type="submit"
                            class="w-full pharma-green text-white py-3 px-4 rounded-lg font-semibold hover:bg-green-700 transition-colors duration-200 flex items-center justify-center">
                        <i class="fas fa-paper-plane mr-2"></i>
                        Envoyer le lien de réinitialisation
                    </button>
                </form>

                <div class="mt-6 text-center">
                    <p class="text-gray-600">
                        <a href="login.php" class="text-green-600 font-semibold hover:text-green-800 transition-colors">
                            <i class="fas fa-arrow-left mr-1"></i>
                            Retour à la connexion
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>