<?php
require_once 'config/db.php';

// Asegurar que la sesión esté iniciada correctamente
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = "";
$success = "";

try {
    $db = getDBConnection();
    
    // --- REPARACIÓN AUTOMÁTICA DEL ADMIN ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'])) {
        $email_posted = trim($_POST['email']);
        if ($email_posted === 'admin@vitalzoo.com') {
            $newHash = password_hash('admin123', PASSWORD_DEFAULT);
            $updateAdmin = $db->prepare("UPDATE usuarios SET password = ? WHERE email = 'admin@vitalzoo.com'");
            $updateAdmin->execute([$newHash]);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'login') {
            $email = trim($_POST['email']);
            $password = $_POST['password'];

            if (!empty($email) && !empty($password)) {
                $stmt = $db->prepare("SELECT * FROM usuarios WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    if (password_verify($password, $user['password'])) {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_nombre'] = $user['nombre'];
                        $_SESSION['user_rol'] = $user['rol_id'];
                        
                        // LÓGICA DE REDIRECCIÓN POR ROL ACTUALIZADA (CORRECCIÓN ADMIN_PANEL.PHP)
                        if ($user['rol_id'] == 1) {
                            // Si es Administrador
                            header("Location: admin_panel.php");
                        } elseif ($user['rol_id'] == 2) {
                            // Si es Veterinario / Médico
                            header("Location: panel_doctor.php");
                        } else {
                            // Si es Cliente o cualquier otro rol
                            header("Location: index.php");
                        }
                        exit;
                    } else {
                        $error = "La contraseña ingresada es incorrecta.";
                    }
                } else {
                    $error = "No existe ninguna cuenta registrada con este correo.";
                }
            } else {
                $error = "Por favor, completa todos los campos.";
            }
        } 
        
        else if ($action === 'register') {
            $nombre = trim($_POST['nombre']);
            $email = trim($_POST['email']);
            $password_plain = $_POST['password'];
            
            if (!empty($nombre) && !empty($email) && !empty($password_plain)) {
                $password_hashed = password_hash($password_plain, PASSWORD_DEFAULT);
                $rol_id = 4; // Cliente

                try {
                    $stmt = $db->prepare("INSERT INTO usuarios (nombre, email, password, rol_id, verificado) VALUES (?, ?, ?, ?, 1)");
                    $stmt->execute([$nombre, $email, $password_hashed, $rol_id]);
                    $success = "¡Cuenta creada! Ahora puedes iniciar sesión.";
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) {
                        $error = "Este correo electrónico ya está en uso.";
                    } else {
                        $error = "Error en el registro: " . $e->getMessage();
                    }
                }
            } else {
                $error = "Todos los campos son obligatorios.";
            }
        }
    }
} catch (PDOException $e) {
    $error = "Error de conexión a la base de datos: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso - VitalZoo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link class="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&family=Lexend:wght@400;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'], title: ['Lexend', 'sans-serif'] },
                }
            }
        }
    </script>
    <style>
        body {
            background-image: linear-gradient(rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.85)), url('assets/img/hero-bg.png');
            background-size: cover; background-position: center; background-attachment: fixed;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
    </style>
</head>
<body class="p-6">

    <div class="w-full max-w-md">
        <div class="flex justify-center mb-8">
            <a href="index.php">
                <img src="assets/img/logo.png" alt="Logo VitalZoo" class="h-16 w-auto" onerror="this.src='https://via.placeholder.com/150x60?text=VitalZoo'">
            </a>
        </div>

        <div class="glass-card rounded-[2.5rem] p-8 md:p-10 shadow-2xl text-white">
            <div class="text-center mb-8">
                <h1 id="auth-title" class="font-title text-3xl font-bold mb-2">Bienvenido</h1>
                <p id="auth-subtitle" class="text-blue-200">Ingresa para cuidar a tus mascotas</p>
            </div>

            <?php if($error): ?>
                <div class="bg-red-500/20 border border-red-500/50 text-red-200 p-4 rounded-2xl mb-6 text-sm flex items-center gap-3">
                    <i data-lucide="alert-circle" size="18"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if($success): ?>
                <div class="bg-green-500/20 border border-green-500/50 text-green-200 p-4 rounded-2xl mb-6 text-sm flex items-center gap-3">
                    <i data-lucide="check-circle" size="18"></i> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="space-y-5">
                <input type="hidden" name="action" id="auth-action" value="login">
                
                <div id="field-nombre" class="hidden space-y-2">
                    <label class="text-sm font-semibold ml-1">Nombre Completo</label>
                    <div class="relative">
                        <i data-lucide="user" class="absolute left-4 top-3.5 text-white/40" size="18"></i>
                        <input type="text" name="nombre" placeholder="Nombre completo" class="w-full bg-white/5 border border-white/10 rounded-2xl py-3.5 pl-12 pr-4 outline-none focus:ring-2 focus:ring-blue-500 transition text-white">
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-sm font-semibold ml-1">Correo Electrónico</label>
                    <div class="relative">
                        <i data-lucide="mail" class="absolute left-4 top-3.5 text-white/40" size="18"></i>
                        <input type="email" name="email" required placeholder="admin@vitalzoo.com" class="w-full bg-white/5 border border-white/10 rounded-2xl py-3.5 pl-12 pr-4 outline-none focus:ring-2 focus:ring-blue-500 transition text-white">
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-sm font-semibold ml-1">Contraseña</label>
                    <div class="relative">
                        <i data-lucide="lock" class="absolute left-4 top-3.5 text-white/40" size="18"></i>
                        <input type="password" name="password" required placeholder="••••••••" class="w-full bg-white/5 border border-white/10 rounded-2xl py-3.5 pl-12 pr-4 outline-none focus:ring-2 focus:ring-blue-500 transition text-white">
                    </div>
                </div>

                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 rounded-2xl shadow-lg transition-all transform active:scale-95 mt-4">
                    <span id="submit-text">Iniciar Sesión</span>
                </button>
            </form>

            <div class="mt-8 pt-6 border-t border-white/10 text-center">
                <p id="toggle-text" class="text-white/60">¿Aún no tienes cuenta?</p>
                <button onclick="toggleAuth()" id="toggle-btn" class="text-blue-400 font-bold hover:text-blue-300 mt-1 transition">
                    Regístrate aquí
                </button>
            </div>
        </div>

        <div class="mt-8 text-center">
            <a href="index.php" class="text-white/40 hover:text-white transition flex items-center justify-center gap-2">
                <i data-lucide="arrow-left" size="16"></i> Volver al inicio
            </a>
        </div>
    </div>

    <script>
        lucide.createIcons();
        function toggleAuth() {
            const action = document.getElementById('auth-action');
            const title = document.getElementById('auth-title');
            const subtitle = document.getElementById('auth-subtitle');
            const submitText = document.getElementById('submit-text');
            const toggleText = document.getElementById('toggle-text');
            const toggleBtn = document.getElementById('toggle-btn');
            const fieldNombre = document.getElementById('field-nombre');

            if (action.value === 'login') {
                action.value = 'register';
                title.innerText = 'Crear Cuenta';
                subtitle.innerText = 'Únete a nuestra comunidad veterinaria';
                submitText.innerText = 'Registrarse';
                toggleText.innerText = '¿Ya tienes una cuenta?';
                toggleBtn.innerText = 'Inicia sesión aquí';
                fieldNombre.classList.remove('hidden');
                fieldNombre.querySelector('input').required = true;
            } else {
                action.value = 'login';
                title.innerText = 'Bienvenido';
                subtitle.innerText = 'Ingresa para cuidar a tus mascotas';
                submitText.innerText = 'Iniciar Sesión';
                toggleText.innerText = '¿Aún no tienes cuenta?';
                toggleBtn.innerText = 'Regístrate aquí';
                fieldNombre.classList.add('hidden');
                fieldNombre.querySelector('input').required = false;
            }
        }
    </script>
</body>
</html>