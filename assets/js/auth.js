// Gestion des formulaires d'authentification
document.addEventListener('DOMContentLoaded', function() {
    // Formulaires de connexion et inscription
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const resetForm = document.getElementById('resetForm');
    
    // Gestion du formulaire de connexion
    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            e.preventDefault();
            handleLogin();
        });
    }
    
    // Gestion du formulaire d'inscription
    if (registerForm) {
        registerForm.addEventListener('submit', function(e) {
            e.preventDefault();
            handleRegister();
        });
    }
    
    // Gestion du formulaire de réinitialisation
    if (resetForm) {
        resetForm.addEventListener('submit', function(e) {
            e.preventDefault();
            handleReset();
        });
    }
});

async function handleLogin() {
    const formData = new FormData(document.getElementById('loginForm'));
    formData.append('action', 'login');
    
    try {
        const response = await fetch('auth.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification(result.message, 'success');
            setTimeout(() => {
                window.location.href = result.redirect;
            }, 1500);
        } else {
            showNotification(result.message, 'error');
        }
    } catch (error) {
        showNotification('Erreur de connexion au serveur', 'error');
        console.error('Error:', error);
    }
}

async function handleRegister() {
    const formData = new FormData(document.getElementById('registerForm'));
    formData.append('action', 'register');
    
    // Validation côté client
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    if (password !== confirmPassword) {
        showNotification('Les mots de passe ne correspondent pas!', 'error');
        return;
    }
    
    if (password.length < 8) {
        showNotification('Le mot de passe doit contenir au moins 8 caractères!', 'error');
        return;
    }
    
    try {
        const response = await fetch('auth.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification(result.message, 'success');
            setTimeout(() => {
                window.location.href = 'login.php';
            }, 2000);
        } else {
            showNotification(result.message, 'error');
        }
    } catch (error) {
        showNotification('Erreur de connexion au serveur', 'error');
        console.error('Error:', error);
    }
}

async function handleReset() {
    const formData = new FormData(document.getElementById('resetForm'));
    formData.append('action', 'reset_password');
    
    try {
        const response = await fetch('auth.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification(result.message, 'success');
        } else {
            showNotification(result.message, 'error');
        }
    } catch (error) {
        showNotification('Erreur de connexion au serveur', 'error');
        console.error('Error:', error);
    }
}

function showNotification(message, type) {
    // Créer une notification toast
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 transform transition-transform duration-300 ${
        type === 'success' ? 'bg-green-500 text-white' : 
        type === 'error' ? 'bg-red-500 text-white' : 
        'bg-blue-500 text-white'
    }`;
    
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-${type === 'success' ? 'check' : 'exclamation-triangle'} mr-2"></i>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Supprimer après 5 secondes
    setTimeout(() => {
        notification.remove();
    }, 5000);
}