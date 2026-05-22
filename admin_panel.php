<?php
/**
 * VITALZOO - PANEL DE ADMINISTRACIÓN MAESTRO v4.9.1 PRO (HOTFIX)
 * ---------------------------------------------------------------------------
 * Sistema de Gestión Integral de Recursos Veterinarios y Administrativos.
 * * ESTRUCTURA VISUAL:
 * - Fondo: assets/img/hero-bg.png
 * - Logo: assets/img/logo.png
 * * CORRECCIONES APLICADAS:
 * - SOLUCIONADO: Error 'creado_en' no encontrado en tabla productos.
 * - MANTENIDO: Soporte para subida de archivos (PNG, JPG, WEBP).
 * - MANTENIDO: Roles expandidos: Administrador (1), Veterinario (2), Usuario (4).
 * - MANTENIDO: Edición de contraseñas de usuarios.
 */

// --- 1. CONFIGURACIÓN INICIAL Y DEPENDENCIAS ---
require_once 'config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$roles_autorizados = ['Administrador', 1, 'Dueño de Veterinaria'];
if (!isset($_SESSION['user_rol']) || !in_array($_SESSION['user_rol'], $roles_autorizados)) {
    header("Location: login.php?auth_error=insufficient_permissions&timestamp=" . time());
    exit;
}

$db = getDBConnection();
$mensaje = "";
$seccion = isset($_GET['view']) ? $_GET['view'] : 'dashboard';

// --- 2. MOTOR DE PROCESAMIENTO DE ACCIONES ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // CREACIÓN DE PRODUCTOS (CON SUBIDA DE IMAGEN)
    if (isset($_POST['action_create_product'])) {
        try {
            $nombre = htmlspecialchars(trim($_POST['p_nombre']));
            $precio = floatval($_POST['p_precio']);
            $stock  = intval($_POST['p_stock']);
            $img_url = 'assets/img/default_product.png';

            // Procesar archivo de imagen
            if (isset($_FILES['p_file']) && $_FILES['p_file']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['p_file']['tmp_name'];
                $fileName = $_FILES['p_file']['name'];
                $fileSize = $_FILES['p_file']['size'];
                $fileType = $_FILES['p_file']['type'];
                $fileNameCmps = explode(".", $fileName);
                $fileExtension = strtolower(end($fileNameCmps));

                $extensions_allowed = array('jpg', 'jpeg', 'png', 'webp');

                if (in_array($fileExtension, $extensions_allowed)) {
                    $uploadFileDir = './assets/img/productos/';
                    if (!is_dir($uploadFileDir)) mkdir($uploadFileDir, 0777, true);
                    
                    $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
                    $dest_path = $uploadFileDir . $newFileName;

                    if(move_uploaded_file($fileTmpPath, $dest_path)) {
                        $img_url = 'assets/img/productos/' . $newFileName;
                    }
                }
            } elseif (!empty($_POST['p_img'])) {
                $img_url = $_POST['p_img'];
            }
            
            // CORRECCIÓN: Se elimina 'creado_en' para evitar error de columna inexistente
            $stmt = $db->prepare("INSERT INTO productos (nombre, precio, stock, imagen_url) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nombre, $precio, $stock, $img_url]);
            
            $mensaje = "success|El producto '$nombre' ha sido catalogado exitosamente.";
        } catch (PDOException $e) {
            $mensaje = "error|Fallo en la base de datos: " . $e->getMessage();
        }
    }

    // CREACIÓN DE USUARIOS
    if (isset($_POST['action_create_user'])) {
        try {
            $nombre = htmlspecialchars(trim($_POST['u_nombre']));
            $email  = filter_var($_POST['u_email'], FILTER_SANITIZE_EMAIL);
            $pass   = password_hash($_POST['u_pass'], PASSWORD_BCRYPT, ['cost' => 12]);
            $rol    = intval($_POST['u_rol']);
            
            $checkEmail = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
            $checkEmail->execute([$email]);
            
            if ($checkEmail->rowCount() > 0) {
                throw new Exception("El correo electrónico ya se encuentra registrado.");
            }
            
            $stmt = $db->prepare("INSERT INTO usuarios (nombre, email, password, rol_id, fecha_registro) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$nombre, $email, $pass, $rol]);
            
            $mensaje = "success|Cuenta para '$nombre' habilitada correctamente.";
        } catch (Exception $e) {
            $mensaje = "error|" . $e->getMessage();
        }
    }

    // ACTUALIZACIÓN DE PRODUCTOS
    if (isset($_POST['action_edit_product'])) {
        try {
            $id     = intval($_POST['p_id']);
            $nombre = htmlspecialchars(trim($_POST['p_nombre']));
            $precio = floatval($_POST['p_precio']);
            $stock  = intval($_POST['p_stock']);
            
            $stmt = $db->prepare("UPDATE productos SET nombre = ?, precio = ?, stock = ? WHERE id = ?");
            $stmt->execute([$nombre, $precio, $stock, $id]);
            $mensaje = "success|Inventario actualizado para el producto ID #$id.";
        } catch (Exception $e) {
            $mensaje = "error|Error de edición: " . $e->getMessage();
        }
    }

    // ACTUALIZACIÓN DE USUARIOS (INCLUYENDO CONTRASEÑA OPCIONAL)
    if (isset($_POST['action_edit_user'])) {
        try {
            $id     = intval($_POST['u_id']);
            $nombre = htmlspecialchars(trim($_POST['u_nombre']));
            $email  = filter_var($_POST['u_email'], FILTER_SANITIZE_EMAIL);
            $rol    = intval($_POST['u_rol']);
            $new_pass = $_POST['u_pass_new'] ?? '';
            
            if (!empty($new_pass)) {
                // Actualizar con nueva contraseña
                $hashed_pass = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt = $db->prepare("UPDATE usuarios SET nombre = ?, email = ?, rol_id = ?, password = ? WHERE id = ?");
                $stmt->execute([$nombre, $email, $rol, $hashed_pass, $id]);
            } else {
                // Actualizar sin tocar la contraseña
                $stmt = $db->prepare("UPDATE usuarios SET nombre = ?, email = ?, rol_id = ? WHERE id = ?");
                $stmt->execute([$nombre, $email, $rol, $id]);
            }
            
            $mensaje = "success|Los privilegios y datos del usuario han sido modificados.";
        } catch (Exception $e) {
            $mensaje = "error|Fallo al actualizar: " . $e->getMessage();
        }
    }
}

/**
 * PROCESAMIENTO DE BORRADO
 */
if (isset($_GET['delete']) && isset($_GET['table'])) {
    $id = intval($_GET['delete']);
    $tabla = $_GET['table'];
    $tablas_seguras = ['usuarios', 'productos', 'proveedores', 'citas', 'servicios'];

    if (in_array($tabla, $tablas_seguras)) {
        try {
            if ($tabla === 'usuarios' && $id === $_SESSION['user_id']) {
                throw new Exception("No puedes eliminar tu propia cuenta.");
            }
            $stmt = $db->prepare("DELETE FROM $tabla WHERE id = ?");
            $stmt->execute([$id]);
            $mensaje = "success|Registro eliminado correctamente de $tabla.";
        } catch (Exception $e) {
            $mensaje = "error|" . $e->getMessage();
        }
    }
}

// --- 3. EXTRACCIÓN DE DATOS ---
try {
    $total_vets = $db->query("SELECT COUNT(*) FROM usuarios WHERE rol_id = 2")->fetchColumn();
    $total_users = $db->query("SELECT COUNT(*) FROM usuarios WHERE rol_id = 4")->fetchColumn();
    $total_prods = $db->query("SELECT COUNT(*) FROM productos")->fetchColumn();
    $total_ingresos = $db->query("SELECT SUM(total) FROM ventas")->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $total_vets = $total_users = $total_prods = $total_ingresos = 0;
}

$query = "";
switch ($seccion) {
    case 'usuarios':
        $query = "SELECT u.*, r.nombre as rol_nombre FROM usuarios u LEFT JOIN roles r ON u.rol_id = r.id ORDER BY u.rol_id ASC";
        break;
    case 'productos':
        $query = "SELECT * FROM productos ORDER BY stock ASC";
        break;
    case 'ventas':
        $query = "SELECT v.*, u.nombre as cliente FROM ventas v LEFT JOIN usuarios u ON v.usuario_id = u.id ORDER BY v.fecha_venta DESC";
        break;
    case 'citas':
        $query = "SELECT c.*, u.nombre as cliente, v.nombre as veterinario FROM citas c JOIN usuarios u ON c.cliente_id = u.id JOIN usuarios v ON c.veterinario_id = v.id ORDER BY c.fecha DESC";
        break;
    default:
        $query = "SELECT c.*, u.nombre as cliente FROM citas c JOIN usuarios u ON c.cliente_id = u.id ORDER BY c.fecha DESC LIMIT 10";
        break;
}

try {
    $data_list = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $data_list = [];
}
?>
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control | VitalZoo</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #3b82f6;
            --bg-dark: #020617;
            --glass: rgba(15, 23, 42, 0.75);
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-dark);
            color: #f1f5f9;
            background-image: 
                linear-gradient(to bottom, rgba(2, 6, 23, 0.8), rgba(2, 6, 23, 0.95)),
                url('assets/img/hero-bg.png');
            background-attachment: fixed;
            background-size: cover;
            background-position: center;
        }

        .glass-panel {
            background: var(--glass);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            position: relative;
            z-index: 10;
        }

        input, select, button, a {
            position: relative;
            z-index: 50 !important;
            pointer-events: auto !important;
            cursor: pointer;
        }

        input[type="text"], input[type="number"], input[type="email"], input[type="password"], input[type="file"] {
            cursor: text !important;
        }

        .nav-item.active {
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
            border-right: 4px solid #3b82f6;
        }

        .custom-scroll::-webkit-scrollbar { width: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 10px; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade { animation: fadeIn 0.4s ease-out forwards; }

        .logo-container {
            transition: transform 0.3s ease;
        }
        .logo-container:hover {
            transform: translateY(-5px);
        }
    </style>
</head>
<body class="h-screen w-full flex overflow-hidden">

    <!-- SIDEBAR -->
    <aside class="w-80 glass-panel h-full hidden lg:flex flex-col z-50 border-r border-white/5">
        <div class="p-8 flex flex-col items-center">
            <div class="logo-container mb-6">
                <img src="assets/img/logo.png" alt="VitalZoo Logo" class="w-44 h-auto drop-shadow-2xl" onerror="this.src='https://via.placeholder.com/200x80?text=VITALZOO+LOGO'">
            </div>
            <div class="h-px w-full bg-gradient-to-r from-transparent via-white/10 to-transparent"></div>
        </div>

        <nav class="flex-1 px-4 space-y-2 overflow-y-auto custom-scroll pt-4">
            <p class="px-6 text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] mb-4">Administración</p>
            
            <a href="?view=dashboard" class="nav-item <?php echo $seccion=='dashboard'?'active':'' ?> flex items-center gap-4 px-6 py-4 rounded-xl font-bold transition-all hover:bg-white/5">
                <i data-lucide="layout-dashboard" size="20"></i> Panel Principal
            </a>

            <a href="?view=usuarios" class="nav-item <?php echo $seccion=='usuarios'?'active':'' ?> flex items-center gap-4 px-6 py-4 rounded-xl font-bold transition-all hover:bg-white/5">
                <i data-lucide="users" size="20"></i> Gestión de Personal
            </a>

            <a href="?view=productos" class="nav-item <?php echo $seccion=='productos'?'active':'' ?> flex items-center gap-4 px-6 py-4 rounded-xl font-bold transition-all hover:bg-white/5">
                <i data-lucide="package" size="20"></i> Stock e Inventario
            </a>

            <a href="?view=citas" class="nav-item <?php echo $seccion=='citas'?'active':'' ?> flex items-center gap-4 px-6 py-4 rounded-xl font-bold transition-all hover:bg-white/5">
                <i data-lucide="calendar" size="20"></i> Agenda Médica
            </a>

            <a href="?view=ventas" class="nav-item <?php echo $seccion=='ventas'?'active':'' ?> flex items-center gap-4 px-6 py-4 rounded-xl font-bold transition-all hover:bg-white/5">
                <i data-lucide="shopping-cart" size="20"></i> Ventas Realizadas
            </a>
        </nav>

        <div class="p-6 bg-black/40 border-t border-white/5">
            <div class="flex items-center gap-4 mb-6">
                <div class="w-12 h-12 rounded-2xl bg-blue-600 flex items-center justify-center font-black text-white">
                    <?php echo strtoupper(substr($_SESSION['user_nombre'], 0, 1)); ?>
                </div>
                <div class="flex-1 overflow-hidden">
                    <p class="font-bold text-sm truncate"><?php echo $_SESSION['user_nombre']; ?></p>
                    <p class="text-[10px] text-blue-400 font-black uppercase">Root Admin</p>
                </div>
            </div>
            <a href="logout.php" class="flex items-center justify-center gap-3 w-full py-3 bg-red-600/10 hover:bg-red-600 text-red-500 hover:text-white rounded-xl font-black text-[10px] transition-all uppercase tracking-widest">
                <i data-lucide="log-out" size="14"></i> Desconectarse
            </a>
        </div>
    </aside>

    <main class="flex-1 h-full overflow-y-auto custom-scroll relative">
        <header class="sticky top-0 z-40 px-10 py-5 glass-panel border-b border-white/5 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="lg:hidden">
                    <img src="assets/img/logo.png" alt="Logo" class="h-8 w-auto">
                </div>
                <h2 class="text-xs font-black text-slate-500 uppercase tracking-widest">Consola / <span class="text-white"><?php echo $seccion; ?></span></h2>
            </div>
            <div class="flex items-center gap-6">
                <div class="flex items-center gap-2 px-4 py-2 bg-green-500/10 rounded-full">
                    <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                    <span class="text-[9px] font-black text-green-500 uppercase">Sistema Online</span>
                </div>
            </div>
        </header>

        <div class="p-10 max-w-7xl mx-auto">
            <!-- CABECERA -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-12 animate-fade">
                <div>
                    <h1 class="text-5xl font-black tracking-tighter mb-2">Sección <span class="text-blue-500"><?php echo ucfirst($seccion); ?></span></h1>
                    <p class="text-slate-400 text-sm font-medium">Control maestro de VitalZoo v4.9.1</p>
                </div>
                <div class="flex gap-4">
                    <?php if($seccion == 'productos'): ?>
                        <button onclick="openModal('modal-create-product')" class="px-8 py-4 bg-blue-600 hover:bg-blue-700 text-white rounded-2xl font-black shadow-lg flex items-center gap-3 transition-all active:scale-95">
                            <i data-lucide="plus-circle" size="18"></i> NUEVO PRODUCTO
                        </button>
                    <?php elseif($seccion == 'usuarios'): ?>
                        <button onclick="openModal('modal-create-user')" class="px-8 py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl font-black shadow-lg flex items-center gap-3 transition-all active:scale-95">
                            <i data-lucide="user-plus" size="18"></i> NUEVO PERSONAL
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- MENSAJES -->
            <?php if(!empty($mensaje)): list($m_tipo, $m_txt) = explode('|', $mensaje); ?>
                <div class="mb-8 p-6 rounded-2xl glass-panel border-l-4 <?php echo $m_tipo=='success'?'border-green-500':'border-red-500' ?> flex items-center justify-between animate-fade">
                    <div class="flex items-center gap-4">
                        <i data-lucide="<?php echo $m_tipo=='success'?'check-circle':'alert-triangle' ?>" class="<?php echo $m_tipo=='success'?'text-green-500':'text-red-500' ?>"></i>
                        <span class="text-sm font-bold"><?php echo $m_txt; ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- CUADROS KPI -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-12">
                <div class="glass-panel p-8 rounded-[2rem] border border-white/5 hover:border-blue-500/40 transition-all animate-fade">
                    <div class="p-3 w-fit bg-blue-500/10 text-blue-500 rounded-2xl mb-6">
                        <i data-lucide="stethoscope" size="24"></i>
                    </div>
                    <p class="text-4xl font-black mb-1"><?php echo $total_vets; ?></p>
                    <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Veterinarios</p>
                </div>

                <div class="glass-panel p-8 rounded-[2rem] border border-white/5 hover:border-green-500/40 transition-all animate-fade" style="animation-delay: 0.1s">
                    <div class="p-3 w-fit bg-green-500/10 text-green-500 rounded-2xl mb-6">
                        <i data-lucide="dollar-sign" size="24"></i>
                    </div>
                    <p class="text-4xl font-black mb-1">$<?php echo number_format($total_ingresos, 0, ',', '.'); ?></p>
                    <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Ingresos Totales</p>
                </div>

                <div class="glass-panel p-8 rounded-[2rem] border border-white/5 hover:border-orange-500/40 transition-all animate-fade" style="animation-delay: 0.2s">
                    <div class="p-3 w-fit bg-orange-500/10 text-orange-500 rounded-2xl mb-6">
                        <i data-lucide="box" size="24"></i>
                    </div>
                    <p class="text-4xl font-black mb-1"><?php echo $total_prods; ?></p>
                    <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Stock Productos</p>
                </div>

                <div class="glass-panel p-8 rounded-[2rem] border border-white/5 hover:border-purple-500/40 transition-all animate-fade" style="animation-delay: 0.3s">
                    <div class="p-3 w-fit bg-purple-500/10 text-purple-500 rounded-2xl mb-6">
                        <i data-lucide="heart" size="24"></i>
                    </div>
                    <p class="text-4xl font-black mb-1"><?php echo $total_users; ?></p>
                    <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Clientes</p>
                </div>
            </div>

            <!-- TABLA -->
            <div class="glass-panel rounded-[2.5rem] overflow-hidden animate-fade" style="animation-delay: 0.4s">
                <div class="p-8 border-b border-white/5 flex justify-between items-center bg-white/[0.01]">
                    <h3 class="font-black text-xl">Registros del Sistema</h3>
                    <div class="relative">
                        <i data-lucide="search" size="16" class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
                        <input type="text" id="dbSearch" onkeyup="filterTable()" placeholder="Escribe para filtrar..." class="bg-black/60 border border-white/10 rounded-2xl py-3 pl-12 pr-6 text-sm focus:border-blue-500 outline-none w-80">
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full" id="mainTable">
                        <thead>
                            <tr class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] border-b border-white/5">
                                <th class="px-10 py-8 text-center">ID</th>
                                <th class="px-10 py-8 text-left">Información</th>
                                <th class="px-10 py-8 text-left">Estado/Valor</th>
                                <th class="px-10 py-8 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php foreach($data_list as $row): ?>
                            <tr class="hover:bg-white/[0.02] transition-colors group">
                                <td class="px-10 py-8 text-center font-black text-slate-600 text-xs">#<?php echo $row['id']; ?></td>
                                <td class="px-10 py-8 text-left">
                                    <div class="flex items-center gap-4">
                                        <?php if($seccion == 'productos'): ?>
                                            <img src="<?php echo $row['imagen_url']; ?>" class="w-12 h-12 rounded-xl object-cover border border-white/10" onerror="this.src='https://via.placeholder.com/60'">
                                            <div>
                                                <p class="font-black text-base group-hover:text-blue-400 transition"><?php echo $row['nombre']; ?></p>
                                                <p class="text-[10px] text-slate-500 font-bold uppercase">Cod: <?php echo str_pad($row['id'], 5, '0', STR_PAD_LEFT); ?></p>
                                            </div>
                                        <?php else: ?>
                                            <div class="w-12 h-12 rounded-2xl bg-slate-800 flex items-center justify-center font-black text-blue-500 text-lg">
                                                <?php echo strtoupper(substr($row['nombre'] ?? 'U', 0, 1)); ?>
                                            </div>
                                            <div>
                                                <p class="font-black text-base group-hover:text-blue-400 transition"><?php echo $row['nombre']; ?></p>
                                                <p class="text-[10px] text-slate-500 font-bold italic"><?php echo $row['email'] ?? 'Sin correo'; ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-10 py-8 text-left">
                                    <?php if($seccion == 'productos'): ?>
                                        <div class="flex flex-col">
                                            <span class="font-black text-green-500 text-lg">$<?php echo number_format($row['precio'], 0, ',', '.'); ?></span>
                                            <span class="text-[10px] font-bold uppercase <?php echo $row['stock']<5?'text-red-500':'text-slate-500' ?>">Existencias: <?php echo $row['stock']; ?></span>
                                        </div>
                                    <?php elseif($seccion == 'usuarios'): ?>
                                        <span class="px-4 py-2 bg-blue-500/10 text-blue-400 text-[10px] font-black uppercase rounded-xl border border-blue-500/20">
                                            <?php echo $row['rol_nombre']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-xs font-bold text-slate-400"><?php echo date('d M, Y', strtotime($row['fecha'] ?? 'today')); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-10 py-8 text-right">
                                    <div class="flex justify-end gap-3">
                                        <?php if(in_array($seccion, ['productos', 'usuarios'])): ?>
                                            <button onclick='openEditModal(<?php echo json_encode($row); ?>, "<?php echo $seccion; ?>")' class="p-3 bg-blue-500/10 hover:bg-blue-600 text-blue-400 hover:text-white rounded-xl transition">
                                                <i data-lucide="edit-3" size="18"></i>
                                            </button>
                                            <button onclick="confirmDelete('<?php echo $seccion; ?>', <?php echo $row['id']; ?>, '<?php echo addslashes($row['nombre']); ?>')" class="p-3 bg-red-500/10 hover:bg-red-600 text-red-400 hover:text-white rounded-xl transition">
                                                <i data-lucide="trash-2" size="18"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="px-4 py-2 bg-white/5 hover:bg-white/10 rounded-xl text-[10px] font-black uppercase tracking-widest">Ver Ficha</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- MODAL: CREAR PRODUCTO -->
    <div id="modal-create-product" class="fixed inset-0 bg-black/95 backdrop-blur-2xl z-[100] hidden items-center justify-center p-6">
        <div class="glass-panel max-w-lg w-full rounded-[3rem] p-12 relative border-t-8 border-blue-600 animate-fade">
            <button onclick="closeModal('modal-create-product')" class="absolute top-10 right-10 text-slate-500 hover:text-white transition-transform hover:rotate-90">
                <i data-lucide="x" size="28"></i>
            </button>
            <h2 class="text-4xl font-black tracking-tighter mb-10 flex items-center gap-4"><i data-lucide="package-plus" class="text-blue-500" size="40"></i> Registrar Ítem</h2>
            <form method="POST" enctype="multipart/form-data" class="space-y-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Nombre Comercial</label>
                    <input type="text" name="p_nombre" placeholder="Ej: Shampoo VitalZoo" required class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl font-bold focus:border-blue-500 outline-none text-white transition-all">
                </div>
                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Precio de Venta</label>
                        <input type="number" step="0.01" name="p_precio" placeholder="0.00" required class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl font-black text-green-500 outline-none text-xl">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Cantidad Inicial</label>
                        <input type="number" name="p_stock" placeholder="0" required class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl font-bold outline-none text-white text-xl">
                    </div>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Cargar Imagen (PNG, JPG, WEBP)</label>
                    <input type="file" name="p_file" accept=".png, .jpg, .jpeg, .webp" class="w-full bg-black/40 border border-white/10 p-4 rounded-2xl text-xs outline-none text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-[10px] file:font-black file:bg-blue-600 file:text-white hover:file:bg-blue-700 cursor-pointer">
                    <p class="text-[9px] text-slate-500 mt-1">* Opcional: También puedes pegar una URL abajo.</p>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-500 uppercase ml-2">O Enlace Directo (URL)</label>
                    <input type="text" name="p_img" placeholder="https://..." class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl text-xs outline-none text-slate-400">
                </div>
                <button type="submit" name="action_create_product" class="w-full bg-blue-600 py-6 rounded-2xl font-black text-white hover:bg-blue-700 transition shadow-2xl shadow-blue-600/40 uppercase tracking-[0.2em] text-sm mt-4">CONFIRMAR REGISTRO</button>
            </form>
        </div>
    </div>

    <!-- MODAL: CREAR USUARIO / PERSONAL -->
    <div id="modal-create-user" class="fixed inset-0 bg-black/95 backdrop-blur-2xl z-[100] hidden items-center justify-center p-6">
        <div class="glass-panel max-w-lg w-full rounded-[3rem] p-12 relative border-t-8 border-indigo-600 animate-fade">
            <button onclick="closeModal('modal-create-user')" class="absolute top-10 right-10 text-slate-500 hover:text-white transition-transform hover:rotate-90">
                <i data-lucide="x" size="28"></i>
            </button>
            <h2 class="text-4xl font-black tracking-tighter mb-10 flex items-center gap-4"><i data-lucide="user-plus" class="text-indigo-500" size="40"></i> Nuevo Usuario</h2>
            <form method="POST" class="space-y-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Nombre del Usuario</label>
                    <input type="text" name="u_nombre" placeholder="Nombre completo" required class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl font-bold focus:border-indigo-500 outline-none text-white transition-all">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Email de Acceso</label>
                    <input type="email" name="u_email" placeholder="correo@ejemplo.com" required class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl font-bold focus:border-indigo-500 outline-none text-white transition-all">
                </div>
                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Contraseña</label>
                        <input type="password" name="u_pass" placeholder="********" required class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl font-bold outline-none text-white">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Rol del Sistema</label>
                        <select name="u_rol" required class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl font-bold outline-none text-indigo-400 appearance-none">
                            <option value="4">Usuario / Cliente</option>
                            <option value="2">Veterinario</option>
                            <option value="1">Administrador</option>
                        </select>
                    </div>
                </div>
                <button type="submit" name="action_create_user" class="w-full bg-indigo-600 py-6 rounded-2xl font-black text-white hover:bg-indigo-700 transition shadow-2xl shadow-indigo-600/40 uppercase tracking-[0.2em] text-sm mt-4">DAR DE ALTA</button>
            </form>
        </div>
    </div>

    <!-- MODAL: ELIMINAR -->
    <div id="modal-delete" class="fixed inset-0 bg-red-950/95 backdrop-blur-2xl z-[200] hidden items-center justify-center p-6">
        <div class="glass-panel max-w-sm w-full rounded-[3rem] p-12 text-center animate-fade">
            <div class="w-24 h-24 bg-red-600/20 text-red-500 rounded-full flex items-center justify-center mx-auto mb-8 border border-red-500/20">
                <i data-lucide="trash-2" size="48"></i>
            </div>
            <h3 class="text-3xl font-black mb-4 tracking-tighter">¿Eliminar permanentemente?</h3>
            <p class="text-slate-400 text-sm mb-10 leading-relaxed font-medium italic">Esta operación no puede deshacerse. Se borrará a: <br><span id="del-label" class="text-white font-black text-lg not-italic mt-2 block"></span></p>
            <div class="flex flex-col gap-4">
                <a id="del-confirm-btn" href="#" class="bg-red-600 text-white py-5 rounded-2xl font-black hover:bg-red-700 transition uppercase tracking-widest text-xs">BORRAR DEFINITIVAMENTE</a>
                <button onclick="closeModal('modal-delete')" class="text-slate-500 font-bold hover:text-white transition py-2 text-xs uppercase tracking-widest">CANCELAR ACCIÓN</button>
            </div>
        </div>
    </div>

    <!-- MODAL: EDICIÓN DINÁMICA -->
    <div id="modal-edit" class="fixed inset-0 bg-black/95 backdrop-blur-2xl z-[120] hidden items-center justify-center p-6">
        <div class="glass-panel max-w-lg w-full rounded-[3rem] p-12 relative border-t-8 border-cyan-500 animate-fade">
            <button onclick="closeModal('modal-edit')" class="absolute top-10 right-10 text-slate-500 hover:text-white transition-transform hover:rotate-90">
                <i data-lucide="x" size="28"></i>
            </button>
            <h2 class="text-4xl font-black tracking-tighter mb-10 flex items-center gap-4"><i data-lucide="edit-3" class="text-cyan-500" size="40"></i> Editar Datos</h2>
            
            <!-- FORMULARIO EDITAR PRODUCTOS -->
            <form id="form-edit-productos" method="POST" class="hidden space-y-8">
                <input type="hidden" name="p_id" id="edit-p-id">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Nombre del Producto</label>
                    <input type="text" name="p_nombre" id="edit-p-nombre" required class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl font-bold focus:border-cyan-500 outline-none text-white">
                </div>
                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Precio Actual ($)</label>
                        <input type="number" step="0.01" name="p_precio" id="edit-p-precio" required class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl font-black text-green-500 text-xl outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Unidades Disponibles</label>
                        <input type="number" name="p_stock" id="edit-p-stock" required class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl font-bold text-white text-xl outline-none">
                    </div>
                </div>
                <button type="submit" name="action_edit_product" class="w-full bg-cyan-600 py-6 rounded-2xl font-black uppercase tracking-widest text-white hover:bg-cyan-700 transition shadow-2xl shadow-cyan-600/40">GUARDAR CAMBIOS EN STOCK</button>
            </form>

            <!-- FORMULARIO EDITAR USUARIOS -->
            <form id="form-edit-usuarios" method="POST" class="hidden space-y-6">
                <input type="hidden" name="u_id" id="edit-u-id">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Nombre Completo</label>
                    <input type="text" name="u_nombre" id="edit-u-nombre" required class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl font-bold text-white outline-none">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Correo Electrónico</label>
                    <input type="email" name="u_email" id="edit-u-email" required class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl text-slate-400 outline-none">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Rol Asignado</label>
                        <select name="u_rol" id="edit-u-rol" required class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl font-bold text-blue-400 outline-none appearance-none">
                            <option value="1">Administrador</option>
                            <option value="2">Veterinario</option>
                            <option value="4">Usuario / Cliente</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-500 uppercase ml-2">Nueva Contraseña</label>
                        <input type="password" name="u_pass_new" placeholder="Dejar vacío para no cambiar" class="w-full bg-black/40 border border-white/10 p-5 rounded-2xl font-bold text-white outline-none placeholder:text-[9px] placeholder:font-normal">
                    </div>
                </div>
                <button type="submit" name="action_edit_user" class="w-full bg-blue-600 py-6 rounded-2xl font-black uppercase tracking-widest text-white hover:bg-blue-700 transition mt-4">ACTUALIZAR PERFIL</button>
            </form>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script>
        lucide.createIcons();

        function openModal(id) {
            const el = document.getElementById(id);
            if (el) {
                el.classList.remove('hidden');
                el.classList.add('flex');
                document.body.style.overflow = 'hidden';
                lucide.createIcons();
            }
        }

        function closeModal(id) {
            const el = document.getElementById(id);
            if (el) {
                el.classList.add('hidden');
                el.classList.remove('flex');
                document.body.style.overflow = 'auto';
            }
        }

        function openEditModal(data, section) {
            document.getElementById('form-edit-productos').classList.add('hidden');
            document.getElementById('form-edit-usuarios').classList.add('hidden');

            if (section === 'productos') {
                document.getElementById('edit-p-id').value = data.id;
                document.getElementById('edit-p-nombre').value = data.nombre;
                document.getElementById('edit-p-precio').value = data.precio;
                document.getElementById('edit-p-stock').value = data.stock;
                document.getElementById('form-edit-productos').classList.remove('hidden');
            } else if (section === 'usuarios') {
                document.getElementById('edit-u-id').value = data.id;
                document.getElementById('edit-u-nombre').value = data.nombre;
                document.getElementById('edit-u-email').value = data.email;
                document.getElementById('edit-u-rol').value = data.rol_id;
                document.getElementById('form-edit-usuarios').classList.remove('hidden');
            }
            openModal('modal-edit');
        }

        function confirmDelete(table, id, name) {
            document.getElementById('del-label').innerText = name;
            document.getElementById('del-confirm-btn').href = `?view=${table}&table=${table}&delete=${id}`;
            openModal('modal-delete');
        }

        function filterTable() {
            const input = document.getElementById("dbSearch");
            const filter = input.value.toUpperCase();
            const tr = document.getElementById("mainTable").getElementsByTagName("tr");
            for (let i = 1; i < tr.length; i++) {
                const td = tr[i].getElementsByTagName("td")[1];
                if (td) {
                    const txtValue = td.textContent || td.innerText;
                    tr[i].style.display = txtValue.toUpperCase().indexOf(filter) > -1 ? "" : "none";
                }
            }
        }
        
        window.onkeydown = (e) => {
            if(e.key === 'Escape') {
                document.querySelectorAll('[id^="modal-"]:not(.hidden)').forEach(m => closeModal(m.id));
            }
        }
    </script>
</body>
</html>