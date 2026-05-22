<?php
/**
 * VITALZOO - PORTAL PÚBLICO LIQUID GLASS EDITION (v5.3 - ULTIMATE)
 * ---------------------------------------------------------------------------
 * - Solución definitiva al error de columnas de contacto inexistentes en DB.
 * - Mapa dinámico incluido en Contacto de Sogamoso.
 * - Validación de sesión para compra con modal de alerta premium.
 * - Sección de Servicios con Formulario de Agendamiento Avanzado.
 * - Verificación asíncrona de disponibilidad del médico.
 * - Panel de Autogestión de Citas (Ver, Editar y Cancelar en tiempo real).
 * - Carrito de Compras lateral estilo Mercado Libre (Liquid Glass).
 * - Botón flotante animado de WhatsApp directo.
 */
require_once 'config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$is_logged = isset($_SESSION['user_id']);
$db = getDBConnection();
$mensaje_cita = "";

// --- DETECTAR SI LAS NUEVAS COLUMNAS EXISTEN EN LA BASE DE DATOS ---
$tiene_nuevas_columnas = false;
try {
    $test = $db->query("SELECT tipo_cita FROM citas LIMIT 1");
    $tiene_nuevas_columnas = true;
} catch (Exception $e) {
    $tiene_nuevas_columnas = false;
}

// --- PROCESAR AGENDAMIENTO / EDICIÓN / CANCELACIÓN DE CITAS ---

// 1. Crear Cita Nueva
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_agendar_cita'])) {
    if (!$is_logged) {
        $mensaje_cita = "error|Debes iniciar sesión para agendar una cita.";
    } else {
        try {
            $cliente_id = $_SESSION['user_id'];
            $vet_id = intval($_POST['veterinario_id']);
            $fecha = $_POST['fecha'];
            $hora = $_POST['hora'];
            $tipo_cita = isset($_POST['tipo_cita']) ? $_POST['tipo_cita'] : 'cita medica';
            $contacto = htmlspecialchars(trim($_POST['contacto_notificacion']));
            $metodo = isset($_POST['metodo_notificacion']) ? $_POST['metodo_notificacion'] : 'WhatsApp';
            $motivo = htmlspecialchars(trim($_POST['motivo']));

            // Validar si el horario ya está reservado para ese veterinario
            $check = $db->prepare("SELECT id FROM citas WHERE veterinario_id = ? AND fecha = ? AND hora = ? AND estado != 'cancelada'");
            $check->execute([$vet_id, $fecha, $hora]);

            if ($check->rowCount() > 0) {
                $mensaje_cita = "error|Lo sentimos, este especialista ya tiene una cita reservada para esa fecha y hora.";
            } else {
                if ($tiene_nuevas_columnas) {
                    $stmt = $db->prepare("INSERT INTO citas (cliente_id, veterinario_id, fecha, hora, tipo_cita, contacto_notificacion, metodo_notificacion, motivo, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')");
                    $stmt->execute([$cliente_id, $vet_id, $fecha, $hora, $tipo_cita, $contacto, $metodo, $motivo]);
                } else {
                    $stmt = $db->prepare("INSERT INTO citas (cliente_id, veterinario_id, fecha, hora, motivo, estado) VALUES (?, ?, ?, ?, ?, 'pendiente')");
                    $stmt->execute([$cliente_id, $vet_id, $fecha, $hora, $motivo]);
                }
                $mensaje_cita = "success|¡Tu cita ha sido programada con éxito! Te esperamos.";
            }
        } catch (PDOException $e) {
            $mensaje_cita = "error|Error de conexión con la base de datos de citas: " . $e->getMessage();
        }
    }
}

// 2. Modificar / Editar Cita Existente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_editar_cita'])) {
    if (!$is_logged) {
         $mensaje_cita = "error|Operación no autorizada.";
    } else {
        try {
            $cita_id = intval($_POST['cita_id']);
            $vet_id = intval($_POST['edit_veterinario_id']);
            $fecha = $_POST['edit_fecha'];
            $hora = $_POST['edit_hora'];
            $tipo_cita = $_POST['edit_tipo_cita'];
            $contacto = htmlspecialchars(trim($_POST['edit_contacto_notificacion']));
            $metodo = $_POST['edit_metodo_notificacion'];
            $motivo = htmlspecialchars(trim($_POST['edit_motivo']));

            // Validar choque de horarios
            $check = $db->prepare("SELECT id FROM citas WHERE veterinario_id = ? AND fecha = ? AND hora = ? AND id != ? AND estado != 'cancelada'");
            $check->execute([$vet_id, $fecha, $hora, $cita_id]);

            if ($check->rowCount() > 0) {
                $mensaje_cita = "error|El especialista elegido ya se encuentra ocupado en ese nuevo horario.";
            } else {
                if ($tiene_nuevas_columnas) {
                    $stmt = $db->prepare("UPDATE citas SET veterinario_id = ?, fecha = ?, hora = ?, tipo_cita = ?, contacto_notificacion = ?, metodo_notificacion = ?, motivo = ? WHERE id = ? AND cliente_id = ?");
                    $stmt->execute([$vet_id, $fecha, $hora, $tipo_cita, $contacto, $metodo, $motivo, $cita_id, $_SESSION['user_id']]);
                } else {
                    $stmt = $db->prepare("UPDATE citas SET veterinario_id = ?, fecha = ?, hora = ?, motivo = ? WHERE id = ? AND cliente_id = ?");
                    $stmt->execute([$vet_id, $fecha, $hora, $motivo, $cita_id, $_SESSION['user_id']]);
                }
                $mensaje_cita = "success|Cita reprogramada y actualizada correctamente.";
            }
        } catch (PDOException $e) {
             $mensaje_cita = "error|Error al reprogramar la cita: " . $e->getMessage();
        }
    }
}

// 3. Cancelar / Eliminar Cita
if (isset($_GET['cancel_cita']) && $is_logged) {
    try {
        $cita_id = intval($_GET['cancel_cita']);
        $stmt = $db->prepare("UPDATE citas SET estado = 'cancelada' WHERE id = ? AND cliente_id = ?");
        $stmt->execute([$cita_id, $_SESSION['user_id']]);
        $mensaje_cita = "success|Cita médica cancelada con éxito.";
    } catch (PDOException $e) {
        $mensaje_cita = "error|No se pudo cancelar la cita.";
    }
}

// --- PETICIÓN ASÍNCRONA (AJAX) ---
if (isset($_GET['check_disponibilidad'])) {
    $v_id = intval($_GET['v_id']);
    $f = $_GET['f'];
    $h = $_GET['h'];
    try {
        $check = $db->prepare("SELECT id FROM citas WHERE veterinario_id = ? AND fecha = ? AND hora = ? AND estado != 'cancelada'");
        $check->execute([$v_id, $f, $h]);
        header('Content-Type: application/json');
        echo json_encode(['disponible' => $check->rowCount() === 0]);
        exit;
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['disponible' => false]);
        exit;
    }
}

// Obtener productos activos
try {
    $productos = $db->query("SELECT * FROM productos WHERE stock > 0 LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $productos = [];
}

// Obtener veterinarios disponibles para el formulario de citas
try {
    $vets = $db->query("
        SELECT v.id, u.nombre as doc_nombre, e.nombre as especialidad 
        FROM veterinarios v 
        JOIN usuarios u ON v.usuario_id = u.id 
        JOIN especialidades e ON v.especialidad_id = e.id
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $vets = [];
}

// Obtener citas agendadas del usuario actual para el Panel de Autogestión
$user_citas = [];
if ($is_logged) {
    try {
        $stmt_user_citas = $db->prepare("
            SELECT c.*, u.nombre as doc_nombre, e.nombre as especialidad 
            FROM citas c 
            JOIN veterinarios v ON c.veterinario_id = v.id 
            JOIN usuarios u ON v.usuario_id = u.id 
            JOIN especialidades e ON v.especialidad_id = e.id 
            WHERE c.cliente_id = ? AND c.estado != 'cancelada'
            ORDER BY c.fecha ASC, c.hora ASC
        ");
        $stmt_user_citas->execute([$_SESSION['user_id']]);
        $user_citas = $stmt_user_citas->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $user_citas = [];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VitalZoo | Veterinaria Sogamoso</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --glass-bg: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.12);
        }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            scroll-behavior: smooth; 
            background-color: #020617; 
            margin: 0;
            overflow-x: hidden;
        }
        
        .animated-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            background: radial-gradient(circle at 50% 50%, #0f172a 0%, #020617 100%);
        }

        .mesh-gradient {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background-image: 
                radial-gradient(at 0% 0%, hsla(210, 100%, 20%, 0.3) 0px, transparent 50%),
                radial-gradient(at 100% 100%, hsla(220, 100%, 30%, 0.2) 0px, transparent 50%);
            opacity: 0.6;
            filter: blur(60px);
        }

        .liquid-glass {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.05), rgba(255, 255, 255, 0.01));
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid var(--glass-border);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.4);
        }

        .hero-section {
            background: linear-gradient(to bottom, rgba(2, 6, 23, 0.4), rgba(2, 6, 23, 0.9)), 
                        url('assets/img/hero-bg.png');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }

        .product-card {
            transition: all 0.5s cubic-bezier(0.23, 1, 0.320, 1);
        }
        .product-card:hover {
            transform: translateY(-10px);
            border-color: rgba(59, 130, 246, 0.4);
            background: rgba(255, 255, 255, 0.05);
        }

        .map-container {
            border-radius: 2rem;
            overflow: hidden;
            border: 1px solid var(--glass-border);
        }

        /* Modal Styles */
        #auth-modal, #edit-appointment-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 100;
            background: rgba(0,0,0,0.85);
            backdrop-filter: blur(15px);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        /* Sidebar del Carrito de Mercado Libre */
        #cart-sidebar {
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }
        .floating { animation: float 5s ease-in-out infinite; }

        @keyframes pulse-ring {
            0% { transform: scale(0.95); opacity: 1; }
            50% { transform: scale(1.15); opacity: 0.5; }
            100% { transform: scale(1.3); opacity: 0; }
        }
        .whatsapp-ring { animation: pulse-ring 2s infinite ease-out; }
    </style>
</head>
<body class="text-white selection:bg-blue-500 selection:text-white">

    <!-- BOTÓN DE WHATSAPP FLOTANTE PREMIUM -->
    <a href="https://wa.me/573106280270" target="_blank" class="fixed bottom-8 right-8 z-[100] flex items-center justify-center w-16 h-16 bg-green-500 rounded-full shadow-2xl hover:scale-110 active:scale-95 transition-all duration-300 group">
        <span class="absolute inset-0 rounded-full bg-green-500/40 whatsapp-ring"></span>
        <i data-lucide="message-circle" class="w-8 h-8 text-white relative z-10"></i>
        <!-- Tooltip -->
        <span class="absolute right-20 bg-slate-900 border border-white/10 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest opacity-0 group-hover:opacity-100 transition-opacity duration-300 pointer-events-none whitespace-nowrap">
            ¿Ayuda? Chatea con nosotros
        </span>
    </a>

    <!-- MODAL DE AVISO (PARA COMPRA/CITAS SIN SESIÓN) -->
    <div id="auth-modal" class="hidden">
        <div class="liquid-glass p-8 md:p-12 rounded-[3rem] max-w-md w-full text-center border-white/20 animate-fade">
            <div class="w-20 h-20 bg-blue-500/20 rounded-full flex items-center justify-center mx-auto mb-6">
                <i data-lucide="user-plus" class="text-blue-400" size="40"></i>
            </div>
            <h3 class="text-2xl font-black mb-4">¡Paso necesario!</h3>
            <p class="text-slate-400 mb-8">Para poder realizar compras y agendar citas médicas con nuestros profesionales, necesitas iniciar sesión en el portal.</p>
            <div class="flex flex-col gap-4">
                <a href="login.php" class="bg-blue-600 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:scale-105 transition-all">Iniciar Sesión</a>
                <a href="login.php" class="bg-white/10 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-white hover:text-black transition-all">Registrarme</a>
                <button onclick="closeModal()" class="text-slate-500 text-[10px] uppercase font-bold mt-2 hover:text-white">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- MODAL DE EDICIÓN DE CITA -->
    <div id="edit-appointment-modal" class="hidden">
        <div class="liquid-glass p-8 md:p-10 rounded-[2.5rem] max-w-lg w-full border-cyan-500/30">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold flex items-center gap-2 text-cyan-400"><i data-lucide="calendar-range"></i> Reprogramar Cita</h3>
                <button onclick="closeEditAppointmentModal()" class="text-slate-400 hover:text-white"><i data-lucide="x"></i></button>
            </div>
            
            <form action="#servicios" method="POST" class="space-y-4">
                <input type="hidden" name="cita_id" id="edit-cita-id">
                
                <div class="space-y-1">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Especialista</label>
                    <select name="edit_veterinario_id" id="edit-vet-id" required class="w-full bg-black/60 border border-white/10 rounded-xl p-3.5 text-sm font-semibold outline-none focus:border-cyan-500 text-white">
                        <?php foreach($vets as $v): ?>
                            <option value="<?php echo $v['id']; ?>" class="text-slate-900 font-bold">
                                Dr. <?php echo $v['doc_nombre']; ?> (<?php echo $v['especialidad']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Nueva Fecha</label>
                        <input type="date" name="edit_fecha" id="edit-fecha" required min="<?php echo date('Y-m-d'); ?>" class="w-full bg-black/60 border border-white/10 rounded-xl p-3.5 text-sm outline-none focus:border-cyan-500 text-slate-300 font-bold">
                    </div>
                    <div class="space-y-1">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Nueva Hora</label>
                        <input type="time" name="edit_hora" id="edit-hora" required class="w-full bg-black/60 border border-white/10 rounded-xl p-3.5 text-sm outline-none focus:border-cyan-500 text-slate-300 font-bold">
                    </div>
                </div>

                <?php if ($tiene_nuevas_columnas): ?>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Tipo de Servicio</label>
                        <select name="edit_tipo_cita" id="edit-tipo-cita" required class="w-full bg-black/60 border border-white/10 rounded-xl p-3.5 text-sm font-semibold outline-none focus:border-cyan-500 text-white">
                            <option value="cita medica">Cita Médica</option>
                            <option value="cita estetica">Estética / Peluquería</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Medio de Notificación</label>
                        <select name="edit_metodo_notificacion" id="edit-metodo" required class="w-full bg-black/60 border border-white/10 rounded-xl p-3.5 text-sm font-semibold outline-none focus:border-cyan-500 text-white">
                            <option value="WhatsApp">WhatsApp</option>
                            <option value="Correo">Correo Electrónico</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Celular o Email para Alertas</label>
                    <input type="text" name="edit_contacto_notificacion" id="edit-contacto" required class="w-full bg-black/60 border border-white/10 rounded-xl p-3.5 text-sm outline-none focus:border-cyan-500 text-white font-bold">
                </div>
                <?php endif; ?>

                <div class="space-y-1">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Motivo</label>
                    <textarea name="edit_motivo" id="edit-motivo" required rows="2" class="w-full bg-black/60 border border-white/10 p-3 rounded-xl text-sm outline-none focus:border-cyan-500 text-white"></textarea>
                </div>

                <button type="submit" name="action_editar_cita" class="w-full bg-cyan-600 hover:bg-cyan-700 text-white py-4 rounded-xl font-black text-xs uppercase tracking-widest transition-all">SOBREESCRIBIR AGENDACIÓN</button>
            </form>
        </div>
    </div>

    <!-- CARRO COMPRAS LATERAL (Mercado Libre Style) -->
    <aside id="cart-sidebar" class="fixed right-0 top-0 h-screen w-full md:w-[450px] z-[90] glass-panel border-l border-white/10 translate-x-full flex flex-col justify-between p-8">
        <div>
            <div class="flex justify-between items-center mb-8 pb-4 border-b border-white/10">
                <h3 class="text-xl font-black flex items-center gap-2"><i data-lucide="shopping-bag" class="text-blue-500"></i> Mi Carrito</h3>
                <button onclick="toggleCart()" class="text-slate-400 hover:text-white transition"><i data-lucide="x" size="24"></i></button>
            </div>
            
            <!-- Items del carrito -->
            <div id="cart-items-container" class="space-y-6 max-h-[60vh] overflow-y-auto custom-scroll pr-2">
                <!-- Se rellena por JavaScript dinámicamente -->
                <div class="text-center text-slate-500 py-12">
                    <i data-lucide="shopping-cart" class="mx-auto mb-4 opacity-20" size="40"></i>
                    <p class="font-bold">Tu carrito está vacío</p>
                </div>
            </div>
        </div>

        <div class="border-t border-white/10 pt-6 space-y-4">
            <div class="flex justify-between items-center">
                <span class="text-sm font-semibold text-slate-400">Total acumulado:</span>
                <span id="cart-total-value" class="text-2xl font-black text-green-400">$0</span>
            </div>
            <button onclick="realizarPagoSimulado()" class="w-full bg-green-600 hover:bg-green-700 text-white py-4 rounded-xl font-black text-xs uppercase tracking-widest shadow-lg transition-all active:scale-95 flex items-center justify-center gap-2">
                <i data-lucide="credit-card"></i> PROCESAR COMPRA SEGURA
            </button>
        </div>
    </aside>

    <div class="animated-bg"></div>
    <div class="mesh-gradient"></div>

    <!-- NAVBAR -->
    <nav class="fixed w-full z-50 py-4 px-6 lg:px-20">
        <div class="max-w-[1400px] mx-auto liquid-glass py-4 px-8 flex justify-between items-center rounded-3xl">
            <div class="flex items-center gap-4">
                <img src="assets/img/logo.png" alt="VitalZoo Logo" class="w-10 h-10 object-contain" onerror="this.src='https://cdn-icons-png.flaticon.com/512/616/616408.png'">
                <span class="text-2xl font-black tracking-tighter uppercase hidden sm:block">VITAL<span class="text-blue-500">ZOO</span></span>
            </div>
            
            <div class="hidden md:flex gap-10 font-bold text-[10px] uppercase tracking-[0.2em] text-slate-300">
                <a href="#inicio" class="hover:text-blue-400 transition-all">Inicio</a>
                <a href="#productos" class="hover:text-blue-400 transition-all">Tienda</a>
                <a href="#servicios" class="hover:text-blue-400 transition-all">Servicios</a>
                <a href="#contacto" class="hover:text-blue-400 transition-all">Ubicación</a>
            </div>

            <div class="flex items-center gap-4">
                <!-- Ícono Carrito con contador -->
                <button onclick="toggleCart()" class="relative p-2.5 bg-white/5 rounded-xl border border-white/10 text-slate-300 hover:text-white hover:bg-white/10 transition">
                    <i data-lucide="shopping-cart" size="18"></i>
                    <span id="cart-badge" class="absolute -top-1 -right-1 bg-blue-600 text-[9px] font-black w-5 h-5 flex items-center justify-center rounded-full text-white scale-0 transition">0</span>
                </button>
                
                <?php if($is_logged): ?>
                    <a href="logout.php" class="bg-red-600/20 text-red-400 border border-red-500/20 px-6 py-3 rounded-2xl font-black text-[10px] tracking-widest uppercase hover:bg-red-600 hover:text-white transition-all">SALIR</a>
                <?php else: ?>
                    <a href="login.php" class="bg-white/10 border border-white/20 px-6 py-3 rounded-2xl font-black text-[10px] tracking-widest hover:bg-white hover:text-black transition-all">LOGIN</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <section id="inicio" class="relative min-h-screen hero-section flex items-center justify-center text-center px-6 pt-20">
        <div class="max-w-4xl z-10">
            <div class="inline-block px-5 py-2 liquid-glass rounded-full text-[10px] font-black tracking-[0.4em] text-blue-400 mb-8 floating">
                VETERINARIA & BOUTIQUE
            </div>
            <h1 class="text-5xl md:text-8xl font-black mb-8 tracking-tighter leading-[0.9] text-white">
                CUIDADO DE <br> 
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 to-cyan-300">ALTO NIVEL</span>
            </h1>
            <p class="text-lg text-slate-300 mb-12 max-w-xl mx-auto font-medium">
                Hospitalización, estética y la mejor boutique para tus mascotas en Sogamoso.
            </p>
            <div class="flex flex-wrap justify-center gap-6">
                <a href="#servicios" class="bg-blue-600 px-10 py-5 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:scale-105 transition-all shadow-xl shadow-blue-600/30">Nuestros Servicios</a>
                <a href="#productos" class="liquid-glass px-10 py-5 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-white hover:text-black transition-all">Comprar Ahora</a>
            </div>
        </div>
    </section>

    <!-- PRODUCTOS (PETSHOP CON AGREGAR AL CARRITO ESTILO MERCADO LIBRE) -->
    <section id="productos" class="py-32 px-6 lg:px-20 max-w-[1400px] mx-auto">
        <div class="mb-16 flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
            <div>
                <h2 class="text-4xl font-black mb-2 uppercase tracking-tighter font-title">Petshop <span class="text-blue-500">Virtual</span></h2>
                <p class="text-slate-400">Nutrición, medicamentos y accesorios premium.</p>
            </div>
            <button onclick="toggleCart()" class="text-xs font-black uppercase text-blue-400 hover:underline flex items-center gap-2">
                <i data-lucide="shopping-bag" size="14"></i> Abrir mi Orden de Compra
            </button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
            <?php foreach($productos as $p): ?>
            <div class="product-card liquid-glass p-4 rounded-[2.5rem] group flex flex-col justify-between">
                <div>
                    <div class="relative mb-6 overflow-hidden rounded-[2rem] aspect-square">
                        <img src="<?php echo $p['imagen_url']; ?>" class="w-full h-full object-cover transform group-hover:scale-110 transition-all duration-700" onerror="this.src='https://images.unsplash.com/photo-1583337130417-3346a1be7dee?q=80&w=400&auto=format&fit=crop'">
                    </div>
                    <div class="px-2 pb-2">
                        <h3 class="font-bold text-lg mb-2 text-white line-clamp-1"><?php echo $p['nombre']; ?></h3>
                    </div>
                </div>
                <div class="px-2 pt-2 flex items-center justify-between">
                    <span class="text-xl font-black text-white">$<?php echo number_format($p['precio'], 0, ',', '.'); ?></span>
                    <!-- Botón de adición con trigger al carrito -->
                    <button onclick="addToCart(<?php echo htmlspecialchars(json_encode($p)); ?>)" class="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center hover:bg-blue-600 text-blue-400 hover:text-white transition-all shadow-lg">
                        <i data-lucide="shopping-cart" size="18"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- SECCIÓN DE SERVICIOS Y CITAS -->
    <section id="servicios" class="py-32 px-6 lg:px-20 max-w-[1400px] mx-auto">
        <div class="mb-16">
            <h2 class="text-4xl font-black mb-2 uppercase tracking-tighter">Servicios <span class="text-blue-500">Médicos</span></h2>
            <div class="w-20 h-1.5 bg-blue-600 rounded-full mb-6"></div>
            <p class="text-slate-400 max-w-xl">Ofrecemos consulta de medicina general, cirugías especializadas, odontología y laboratorio clínico las 24 horas.</p>
        </div>

        <!-- Alertas de agendación de citas -->
        <?php if(!empty($mensaje_cita)): list($c_tipo, $c_txt) = explode('|', $mensaje_cita); ?>
            <div class="mb-8 p-5 rounded-2xl liquid-glass border-l-4 <?php echo $c_tipo=='success'?'border-green-500 text-green-400':'border-red-500 text-red-400' ?> flex items-center justify-between animate-fade">
                <div class="flex items-center gap-4">
                    <i data-lucide="<?php echo $c_tipo=='success'?'check-circle':'alert-circle' ?>"></i>
                    <span class="text-sm font-bold"><?php echo $c_txt; ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-slate-500 hover:text-white"><i data-lucide="x" size="18"></i></button>
            </div>
        <?php endif; ?>

        <div class="grid lg:grid-cols-5 gap-12 items-stretch">
            
            <!-- Cards de Servicios de la Clínica -->
            <div class="lg:col-span-3 grid sm:grid-cols-2 gap-6">
                <div class="liquid-glass p-8 rounded-[2rem] hover:border-blue-500/30 transition-all group">
                    <div class="w-12 h-12 bg-blue-600/10 text-blue-400 rounded-xl flex items-center justify-center mb-6"><i data-lucide="stethoscope"></i></div>
                    <h3 class="font-bold text-xl mb-2 text-white">Consulta Médica</h3>
                    <p class="text-slate-400 text-sm">Valoración clínica profunda y planes de tratamiento especializados para su bienestar.</p>
                </div>

                <div class="liquid-glass p-8 rounded-[2rem] hover:border-red-500/30 transition-all group">
                    <div class="w-12 h-12 bg-red-600/10 text-red-400 rounded-xl flex items-center justify-center mb-6"><i data-lucide="heart-pulse"></i></div>
                    <h3 class="font-bold text-xl mb-2 text-white">Cirugía Especializada</h3>
                    <p class="text-slate-400 text-sm">Quirófano propio con monitorización anestésica avanzada y cirujanos las 24 horas.</p>
                </div>

                <div class="liquid-glass p-8 rounded-[2rem] hover:border-green-500/30 transition-all group">
                    <div class="w-12 h-12 bg-green-600/10 text-green-400 rounded-xl flex items-center justify-center mb-6"><i data-lucide="sparkles"></i></div>
                    <h3 class="font-bold text-xl mb-2 text-white">Estética de Lujo</h3>
                    <p class="text-slate-400 text-sm">Peluquería canina y felina con baños medicados y cortes adaptados a cada raza.</p>
                </div>

                <div class="liquid-glass p-8 rounded-[2rem] hover:border-yellow-500/30 transition-all group">
                    <div class="w-12 h-12 bg-yellow-600/10 text-yellow-400 rounded-xl flex items-center justify-center mb-6"><i data-lucide="activity"></i></div>
                    <h3 class="font-bold text-xl mb-2 text-white">Laboratorio 24h</h3>
                    <p class="text-slate-400 text-sm">Resultados inmediatos en exámenes de sangre, ecografías diagnósticas y rayos X.</p>
                </div>
            </div>

            <!-- Formulario de Agendamiento Directo -->
            <div class="lg:col-span-2 liquid-glass p-8 rounded-[2.5rem] flex flex-col justify-between border-blue-500/20">
                <div>
                    <h3 class="font-black text-2xl mb-2 tracking-tight">Agenda tu <span class="text-blue-500">Cita</span></h3>
                    <p class="text-xs text-slate-400 mb-6">Completa tus datos para confirmar tu agenda.</p>
                </div>

                <?php if($is_logged): ?>
                    <form action="#servicios" method="POST" class="space-y-4" id="form-agendar">
                        <!-- Selector de Veterinario -->
                        <div class="space-y-1">
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1">Especialista / Veterinario</label>
                            <select name="veterinario_id" id="vet-selector" onchange="verificarDisponibilidadCompleta()" required class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-sm font-semibold outline-none focus:border-blue-500">
                                <option value="" disabled selected>Elige un profesional</option>
                                <?php foreach($vets as $v): ?>
                                    <option value="<?php echo $v['id']; ?>" class="text-slate-900 font-bold">
                                        Dr. <?php echo $v['doc_nombre']; ?> (<?php echo $v['especialidad']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Fecha y Hora -->
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1">Fecha</label>
                                <input type="date" name="fecha" id="fecha-input" onchange="verificarDisponibilidadCompleta()" required min="<?php echo date('Y-m-d'); ?>" class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-blue-500 text-slate-300 font-bold">
                            </div>
                            <div class="space-y-1">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1">Hora</label>
                                <input type="time" name="hora" id="hora-input" onchange="verificarDisponibilidadCompleta()" required class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-blue-500 text-slate-300 font-bold">
                            </div>
                        </div>

                        <!-- Indicador en tiempo real de disponibilidad del Médico -->
                        <div id="status-disponibilidad" class="hidden p-3 rounded-xl text-center text-xs font-bold uppercase transition"></div>

                        <!-- Opciones Expandidas si existen en DB -->
                        <?php if ($tiene_nuevas_columnas): ?>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="space-y-1">
                                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1">Tipo de Cita</label>
                                    <select name="tipo_cita" required class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-sm font-semibold outline-none focus:border-blue-500">
                                        <option value="cita medica">Consulta Médica</option>
                                        <option value="cita estetica">Estética / Peluquería</option>
                                    </select>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1">Recordatorio por</label>
                                    <select name="metodo_notificacion" required class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-sm font-semibold outline-none focus:border-blue-500">
                                        <option value="WhatsApp">WhatsApp</option>
                                        <option value="Correo">Correo Electrónico</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Contacto de Notificación -->
                            <div class="space-y-1">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1">Número de Celular o Correo</label>
                                <input type="text" name="contacto_notificacion" required placeholder="Ej: 3106280270 o correo@gmail.com" class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-blue-500 text-white font-bold">
                            </div>
                        <?php endif; ?>

                        <!-- Motivo -->
                        <div class="space-y-1">
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1">Motivo / Síntomas</label>
                            <textarea name="motivo" required rows="2" placeholder="Ej: Control de vacunas, baño medicado..." class="w-full bg-black/40 border border-white/10 p-4 rounded-xl text-sm outline-none focus:border-blue-500 placeholder:text-slate-600 text-white"></textarea>
                        </div>

                        <button type="submit" name="action_agendar_cita" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-4 rounded-xl font-black text-xs uppercase tracking-widest transition-all hover:scale-[1.02] shadow-xl shadow-blue-600/30">RESERVAR CITA AHORA</button>
                    </form>
                <?php else: ?>
                    <!-- Vista Invitado Bloqueada -->
                    <div class="flex flex-col items-center justify-center text-center p-6 bg-black/20 rounded-[2rem] border border-white/5 py-12">
                        <div class="w-14 h-14 bg-blue-500/10 text-blue-400 rounded-full flex items-center justify-center mb-4">
                            <i data-lucide="lock" size="24"></i>
                        </div>
                        <h4 class="font-bold text-lg mb-2">Sección Asegurada</h4>
                        <p class="text-xs text-slate-400 mb-6 max-w-[240px]">Inicia sesión en VitalZoo para acceder a la agenda médica en línea.</p>
                        <button onclick="handleActionRequest()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl text-[10px] font-black tracking-widest uppercase">Identificarse ahora</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- PANEL DE AUTOGESTIÓN DE CITAS -->
    <?php if($is_logged && !empty($user_citas)): ?>
    <section class="py-16 px-6 lg:px-20 max-w-[1400px] mx-auto animate-fade">
        <div class="liquid-glass p-10 md:p-12 rounded-[3rem]">
            <div class="flex justify-between items-center mb-10 border-b border-white/10 pb-6">
                <div>
                    <h3 class="text-2xl font-black flex items-center gap-3"><i data-lucide="calendar-check" class="text-cyan-400"></i> Tus Citas Programadas</h3>
                    <p class="text-xs text-slate-400 mt-1">Administra tus agendamientos médicos y estéticos registrados.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach($user_citas as $cita): ?>
                <div class="bg-white/5 border border-white/10 p-6 rounded-2xl flex flex-col justify-between gap-6 hover:border-cyan-500/30 transition-all">
                    <div>
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <span class="px-2 py-1 rounded bg-cyan-500/10 text-cyan-400 text-[10px] font-bold uppercase tracking-widest border border-cyan-500/20">
                                    <?php echo isset($cita['tipo_cita']) ? $cita['tipo_cita'] : 'consulta médica'; ?>
                                </span>
                            </div>
                            <span class="text-[10px] text-slate-500 font-bold uppercase">ID #<?php echo $cita['id']; ?></span>
                        </div>
                        <h4 class="text-lg font-bold text-white">Dr. <?php echo $cita['doc_nombre']; ?> (<?php echo $cita['especialidad']; ?>)</h4>
                        <p class="text-slate-400 text-sm mt-1"><?php echo date('d M, Y', strtotime($cita['fecha'])); ?> • <?php echo $cita['hora']; ?> hrs</p>
                        <p class="text-xs text-blue-200 mt-2 bg-white/5 p-3 rounded-lg">"<?php echo $cita['motivo']; ?>"</p>
                    </div>

                    <div class="flex items-center justify-between border-t border-white/5 pt-4">
                        <div class="text-[10px] text-slate-400">
                            <?php if (isset($cita['contacto_notificacion'])): ?>
                                Notificación: <span class="font-bold text-white"><?php echo $cita['contacto_notificacion']; ?></span>
                            <?php else: ?>
                                Notificación: <span class="font-bold text-slate-500">No especificado</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex gap-2">
                            <!-- Botón Editar Cita -->
                            <button onclick='openEditAppointmentModal(<?php echo json_encode($cita); ?>)' class="p-2 bg-cyan-600/10 text-cyan-400 hover:bg-cyan-600 hover:text-white rounded-xl transition" title="Reprogramar">
                                <i data-lucide="edit" size="16"></i>
                            </button>
                            <!-- Botón Cancelar Cita -->
                            <a href="?cancel_cita=<?php echo $cita['id']; ?>" onclick="return confirm('¿Seguro que deseas cancelar esta cita?')" class="p-2 bg-red-600/10 text-red-400 hover:bg-red-600 hover:text-white rounded-xl transition" title="Cancelar">
                                <i data-lucide="calendar-x" size="16"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- CONTACTO Y MAPA -->
    <section id="contacto" class="py-32 px-6 lg:px-20 max-w-[1400px] mx-auto">
        <div class="mb-16 text-center">
            <h2 class="text-4xl font-black mb-2 uppercase tracking-tighter">Visítanos en <span class="text-blue-500">Sogamoso</span></h2>
            <p class="text-slate-400">Atención profesional para tus mascotas en el Barrio Magdalena.</p>
        </div>

        <div class="grid lg:grid-cols-2 gap-12 items-stretch">
            <!-- MAPA DINÁMICO -->
            <div class="map-container min-h-[400px]">
                <iframe 
                    src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3969.344445695022!2d-72.9238384!3d5.7161748!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x8e6a45938d827f31%3A0xc48c4844391e9f0!2sCl.%207%20%2327a-9%2C%20Sogamoso%2C%20Boyac%C3%A1!5e0!3m2!1ses!2sco!4v1715462000000!5m2!1ses!2sco" 
                    width="100%" 
                    height="100%" 
                    style="border:0;" 
                    allowfullscreen="" 
                    loading="lazy" 
                    referrerpolicy="no-referrer-when-downgrade">
                </iframe>
            </div>

            <!-- INFO DE CONTACTO -->
            <div class="liquid-glass p-12 rounded-[3rem] flex flex-col justify-center">
                <div class="space-y-8">
                    <div class="flex items-center gap-6">
                        <div class="w-14 h-14 rounded-2xl bg-blue-500/20 flex items-center justify-center text-blue-400 shrink-0"><i data-lucide="map-pin" size="28"></i></div>
                        <div>
                            <h4 class="font-black text-[10px] uppercase tracking-widest text-blue-500 mb-1">Dirección Exacta</h4>
                            <p class="text-xl font-bold">Calle 7 # 27A-09, Magdalena</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-6">
                        <div class="w-14 h-14 rounded-2xl bg-green-500/20 flex items-center justify-center text-green-400 shrink-0"><i data-lucide="phone" size="28"></i></div>
                        <div>
                            <h4 class="font-black text-[10px] uppercase tracking-widest text-green-500 mb-1">Urgencias y Citas</h4>
                            <p class="text-xl font-bold">310 628 0270</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-6">
                        <div class="w-14 h-14 rounded-2xl bg-white/10 flex items-center justify-center text-white shrink-0"><i data-lucide="clock" size="28"></i></div>
                        <div>
                            <h4 class="font-black text-[10px] uppercase tracking-widest text-slate-500 mb-1">Horario de Atención</h4>
                            <p class="text-xl font-bold">Lunes a Sábado: 8am - 7pm</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer class="py-20 text-center">
        <img src="assets/img/logo.png" class="w-8 h-8 mx-auto mb-6 opacity-30">
        <p class="text-slate-600 text-[9px] font-black uppercase tracking-[0.6em]">VITALZOO • VETERINARIA & BOUTIQUE • 2026</p>
    </footer>

    <!-- SCRIPT DE INTERACTIVIDAD -->
    <script>
        lucide.createIcons();

        const isUserLogged = <?php echo $is_logged ? 'true' : 'false'; ?>;
        
        // --- MOTOR DEL CARRITO DE COMPRAS (Estilo Mercado Libre con LocalStorage) ---
        let cart = JSON.parse(localStorage.getItem('vitalzoo_cart')) || [];

        function updateCartUI() {
            const container = document.getElementById('cart-items-container');
            const badge = document.getElementById('cart-badge');
            const totalVal = document.getElementById('cart-total-value');
            
            // Vaciar contenedor
            container.innerHTML = '';
            
            if (cart.length === 0) {
                badge.classList.add('scale-0');
                container.innerHTML = `
                    <div class="text-center text-slate-500 py-12">
                        <i data-lucide="shopping-cart" class="mx-auto mb-4 opacity-20" size="40"></i>
                        <p class="font-bold">Tu carrito está vacío</p>
                    </div>
                `;
                totalVal.innerText = "$0";
                localStorage.setItem('vitalzoo_cart', JSON.stringify(cart));
                lucide.createIcons();
                return;
            }

            // Mostrar el contador de items
            badge.innerText = cart.reduce((acc, curr) => acc + curr.quantity, 0);
            badge.classList.remove('scale-0');
            badge.classList.add('scale-100');

            let totalSum = 0;

            cart.forEach((item, index) => {
                totalSum += item.precio * item.quantity;
                container.innerHTML += `
                    <div class="flex items-center gap-4 bg-white/5 border border-white/5 p-4 rounded-2xl relative group">
                        <img src="${item.imagen_url}" class="w-16 h-16 rounded-xl object-cover border border-white/10" onerror="this.src='https://via.placeholder.com/100'">
                        <div class="flex-1 min-w-0">
                            <h4 class="font-bold text-sm truncate text-white">${item.nombre}</h4>
                            <p class="text-xs text-blue-400 font-bold">$${new Intl.NumberFormat('es-CO').format(item.precio)}</p>
                            
                            <!-- Control de cantidad -->
                            <div class="flex items-center gap-3 mt-2">
                                <button onclick="changeQuantity(${index}, -1)" class="w-6 h-6 rounded-lg bg-white/10 hover:bg-blue-600 flex items-center justify-center text-xs font-bold">-</button>
                                <span class="text-xs font-bold text-slate-300">${item.quantity}</span>
                                <button onclick="changeQuantity(${index}, 1)" class="w-6 h-6 rounded-lg bg-white/10 hover:bg-blue-600 flex items-center justify-center text-xs font-bold">+</button>
                            </div>
                        </div>
                        <button onclick="removeFromCart(${index})" class="text-red-400 hover:text-red-500 absolute top-4 right-4 opacity-0 group-hover:opacity-100 transition-opacity">
                            <i data-lucide="trash-2" size="16"></i>
                        </button>
                    </div>
                `;
            });

            totalVal.innerText = `$${new Intl.NumberFormat('es-CO').format(totalSum)}`;
            localStorage.setItem('vitalzoo_cart', JSON.stringify(cart));
            lucide.createIcons();
        }

        function addToCart(product) {
            if (!isUserLogged) {
                handleActionRequest();
                return;
            }

            const existingIndex = cart.findIndex(item => item.id === product.id);
            if (existingIndex > -1) {
                cart[existingIndex].quantity += 1;
            } else {
                cart.push({ ...product, quantity: 1 });
            }

            updateCartUI();
            
            // Efecto de apertura del sidebar automático de Mercado Libre
            document.getElementById('cart-sidebar').classList.remove('translate-x-full');
        }

        function changeQuantity(index, amount) {
            cart[index].quantity += amount;
            if (cart[index].quantity <= 0) {
                cart.splice(index, 1);
            }
            updateCartUI();
        }

        function removeFromCart(index) {
            cart.splice(index, 1);
            updateCartUI();
        }

        function toggleCart() {
            const sidebar = document.getElementById('cart-sidebar');
            sidebar.classList.toggle('translate-x-full');
        }

        function realizarPagoSimulado() {
            if (cart.length === 0) {
                alert("¡Tu carrito está vacío!");
                return;
            }
            
            // Simular flujo de Mercado Libre con redirección a pagos ficticios
            alert("Redirigiendo a tu pasarela de pago seguro...\nTotal: " + document.getElementById('cart-total-value').innerText);
            
            // Vaciar y cerrar
            cart = [];
            updateCartUI();
            toggleCart();
        }

        function handleActionRequest() {
            if (!isUserLogged) {
                const modal = document.getElementById('auth-modal');
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }
        }

        function closeModal() {
            const modal = document.getElementById('auth-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // --- SISTEMA DE VERIFICACIÓN ASÍNCRONA DE DISPONIBILIDAD ---
        function verificarDisponibilidadCompleta() {
            const doctor = document.getElementById('vet-selector').value;
            const fecha = document.getElementById('fecha-input').value;
            const hora = document.getElementById('hora-input').value;
            const statusBox = document.getElementById('status-disponibilidad');

            if (!doctor || !fecha || !hora) {
                statusBox.classList.add('hidden');
                return;
            }

            statusBox.classList.remove('hidden');
            statusBox.className = "p-3 rounded-xl text-center text-xs font-black uppercase bg-blue-500/10 text-blue-400";
            statusBox.innerHTML = "<span class='animate-pulse'>Buscando agenda...</span>";

            fetch(`index.php?check_disponibilidad=1&v_id=${doctor}&f=${fecha}&h=${hora}`)
                .then(res => res.json())
                .then(data => {
                    if (data.disponible) {
                        statusBox.className = "p-3 rounded-xl text-center text-xs font-black uppercase bg-green-500/20 text-green-400 border border-green-500/30";
                        statusBox.innerText = "Especialista Disponible en este horario";
                    } else {
                        statusBox.className = "p-3 rounded-xl text-center text-xs font-black uppercase bg-red-500/20 text-red-400 border border-red-500/30";
                        statusBox.innerText = "Doctor ocupado. Elige otra hora o fecha";
                    }
                })
                .catch(() => {
                    statusBox.classList.add('hidden');
                });
        }

        
        // --- SISTEMA DE GESTIÓN DE CITAS DEL CLIENTE (MODAL EDICIÓN) ---
        function openEditAppointmentModal(cita) {
            document.getElementById('edit-cita-id').value = cita.id;
            document.getElementById('edit-vet-id').value = cita.veterinario_id;
            document.getElementById('edit-fecha').value = cita.fecha;
            document.getElementById('edit-hora').value = cita.hora.substring(0, 5);
            
            const editTipoCita = document.getElementById('edit-tipo-cita');
            if (editTipoCita && cita.tipo_cita) {
                editTipoCita.value = cita.tipo_cita;
            }
            
            const editMetodo = document.getElementById('edit-metodo');
            if (editMetodo && cita.metodo_notificacion) {
                editMetodo.value = cita.metodo_notificacion;
            }
            
            const editContacto = document.getElementById('edit-contacto');
            if (editContacto && cita.contacto_notificacion) {
                editContacto.value = cita.contacto_notificacion;
            }
            
            document.getElementById('edit-motivo').value = cita.motivo;

            const modal = document.getElementById('edit-appointment-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            lucide.createIcons();
        }

        function closeEditAppointmentModal() {
            const modal = document.getElementById('edit-appointment-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        window.onclick = function(event) {
            const authModal = document.getElementById('auth-modal');
            const editModal = document.getElementById('edit-appointment-modal');
            if (event.target == authModal) closeModal();
            if (event.target == editModal) closeEditAppointmentModal();
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('auth-modal').classList.add('hidden');
            document.getElementById('edit-appointment-modal').classList.add('hidden');
            updateCartUI(); // Cargar estado inicial del carrito
        });
    </script>
   <div id="checkout-modal" class="hidden fixed inset-0 z-[9999] bg-black/90 backdrop-blur-xl flex items-center justify-center p-6">
        <div class="liquid-glass w-full max-w-lg p-8 rounded-[2.5rem] border border-blue-500/20 shadow-2xl">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-2xl font-black">Finalizar Compra</h3>
                <button onclick="document.getElementById('checkout-modal').classList.add('hidden')" class="text-slate-500 hover:text-white">✕</button>
            </div>
            
            <form id="form-checkout" onsubmit="procesarPagoFinal(event)" class="space-y-4">
                <div class="space-y-1">
                    <label class="text-[10px] uppercase font-bold text-slate-500">Dirección de Entrega</label>
                    <input type="text" name="direccion" required class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                <div class="space-y-1">
                    <label class="text-[10px] uppercase font-bold text-slate-500">Teléfono / WhatsApp</label>
                    <input type="tel" name="telefono" required class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-sm text-white focus:ring-2 focus:ring-blue-500 outline-none">
                </div>
                
                <div class="pt-4 border-t border-white/10">
                    <label class="text-[10px] uppercase font-bold text-slate-500 mb-2 block">Método de Pago</label>
                    <div class="grid grid-cols-2 gap-4">
                        <label class="cursor-pointer bg-white/5 p-4 rounded-xl border border-white/10 hover:border-blue-500 transition">
                            <input type="radio" name="metodo" value="qr" checked> <span class="text-xs font-bold ml-2">Código QR</span>
                        </label>
                        <label class="cursor-pointer bg-white/5 p-4 rounded-xl border border-white/10 hover:border-blue-500 transition">
                            <input type="radio" name="metodo" value="contraentrega"> <span class="text-xs font-bold ml-2">Contra Entrega</span>
                        </label>
                    </div>
                </div>

                <button type="submit" class="w-full bg-blue-600 py-4 rounded-xl font-black text-xs uppercase mt-6 hover:bg-blue-500 transition">Confirmar Compra</button>
            </form>
        </div>
    </div>

    <!-- VISOR DE QR -->
    <div id="qr-modal" class="hidden fixed inset-0 z-[10000] bg-black/95 flex flex-col items-center justify-center p-6 text-center">
        <h3 class="text-2xl font-black mb-4">Escanea para pagar</h3>
        <div class="bg-white p-4 rounded-2xl mb-6">
            <img src="assets/img/codigo_qr.png" class="w-64 h-64 object-contain" onerror="this.src='https://via.placeholder.com/300?text=QR+No+Encontrado'">
        </div>
        <p class="text-slate-400 text-xs mb-8 max-w-xs">Envía el comprobante a nuestro WhatsApp una vez realizado el pago.</p>
        <button onclick="cerrarQR()" class="bg-white text-black px-8 py-3 rounded-xl font-bold hover:bg-slate-200 transition">He pagado</button>
    </div>

    <script>
        function procesarPagoFinal(e) {
            e.preventDefault();
            const metodo = document.querySelector('input[name="metodo"]:checked').value;
            
            if(metodo === 'qr') {
                document.getElementById('checkout-modal').classList.add('hidden');
                document.getElementById('qr-modal').classList.remove('hidden');
            } else {
                alert("Compra registrada. Nuestro equipo se pondrá en contacto.");
                location.reload();
            }
        }

        function cerrarQR() {
            document.getElementById('qr-modal').classList.add('hidden');
            location.reload();
        }
    </script>
</body>
</html>