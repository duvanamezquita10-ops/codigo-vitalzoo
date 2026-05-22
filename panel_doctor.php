<?php
/**
 * VITALZOO - PANEL DE VETERINARIOS / DOCTORES v1.0 PRO
 * ---------------------------------------------------------------------------
 * Sistema de Autogestión de Consultas, Reprogramaciones e Historias Médicas.
 * Exclusivo para Rol_id = 2.
 * * DISEÑO: Liquid Glassmorphism Premium.
 */

require_once 'config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Control estricto de roles: Solamente Rol ID 2 (Doctor / Veterinario)
if (!isset($_SESSION['user_rol']) || $_SESSION['user_rol'] != 2) {
    header("Location: login.php?auth_error=doctor_access_only&timestamp=" . time());
    exit;
}

$db = getDBConnection();
$mensaje = "";
$veterinario_id = null;

// Obtener el ID del Veterinario correspondiente a esta sesión de usuario
try {
    $stmt_vet = $db->prepare("SELECT id, especialidad_id, horario_inicio, horario_fin FROM veterinarios WHERE usuario_id = ? LIMIT 1");
    $stmt_vet->execute([$_SESSION['user_id']]);
    $vet_data = $stmt_vet->fetch(PDO::FETCH_ASSOC);
    
    if ($vet_data) {
        $veterinario_id = $vet_data['id'];
    } else {
        // Autoregistro de contingencia si no está en la tabla de veterinarios
        $stmt_add_vet = $db->prepare("INSERT INTO veterinarios (usuario_id, especialidad_id, horario_inicio, horario_fin) VALUES (?, 3, '08:00:00', '18:00:00')");
        $stmt_add_vet->execute([$_SESSION['user_id']]);
        $veterinario_id = $db->lastInsertId();
        
        $vet_data = [
            'especialidad_id' => 3,
            'horario_inicio' => '08:00:00',
            'horario_fin' => '18:00:00'
        ];
    }
} catch (PDOException $e) {
    die("Error al inicializar el perfil médico: " . $e->getMessage());
}

// --- PROCESAMIENTO DE ACCIONES MÉDICAS (CRUD CITAS DOCTOR) ---

// 1. Reprogramar o editar cita
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_doctor_edit_cita'])) {
    try {
        $cita_id = intval($_POST['cita_id']);
        $fecha = $_POST['fecha'];
        $hora = $_POST['hora'];
        $tipo_cita = $_POST['tipo_cita'];
        $motivo = htmlspecialchars(trim($_POST['motivo']));
        
        // Verificar conflicto de horario
        $stmt_conf = $db->prepare("SELECT id FROM citas WHERE veterinario_id = ? AND fecha = ? AND hora = ? AND id != ? AND estado != 'cancelada'");
        $stmt_conf->execute([$veterinario_id, $fecha, $hora, $cita_id]);
        
        if ($stmt_conf->rowCount() > 0) {
            $mensaje = "error|Ya tienes agendada otra consulta en esa fecha y hora.";
        } else {
            $stmt_upd = $db->prepare("UPDATE citas SET fecha = ?, hora = ?, tipo_cita = ?, motivo = ? WHERE id = ? AND veterinario_id = ?");
            $stmt_upd->execute([$fecha, $hora, $tipo_cita, $motivo, $cita_id, $veterinario_id]);
            $mensaje = "success|Cita reprogramada con éxito.";
        }
    } catch (Exception $e) {
        $mensaje = "error|No se pudo reprogramar la cita: " . $e->getMessage();
    }
}

// 2. Modificar estado de cita (Completar / Cancelar)
if (isset($_GET['estado_cita']) && isset($_GET['cita_id'])) {
    try {
        $cita_id = intval($_GET['cita_id']);
        $nuevo_estado = $_GET['estado_cita'];
        
        if (in_array($nuevo_estado, ['pendiente', 'completada', 'cancelada'])) {
            $stmt_est = $db->prepare("UPDATE citas SET estado = ? WHERE id = ? AND veterinario_id = ?");
            $stmt_est->execute([$nuevo_estado, $cita_id, $veterinario_id]);
            $mensaje = "success|Estado de la consulta actualizado a '$nuevo_estado'.";
        }
    } catch (Exception $e) {
        $mensaje = "error|No se pudo actualizar la cita.";
    }
}

// 3. Modificar disponibilidad y especialidad
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_doctor_config'])) {
    try {
        $especialidad_id = intval($_POST['especialidad_id']);
        $h_inicio = $_POST['horario_inicio'];
        $h_fin = $_POST['horario_fin'];
        
        $stmt_conf_upd = $db->prepare("UPDATE veterinarios SET especialidad_id = ?, horario_inicio = ?, horario_fin = ? WHERE id = ?");
        $stmt_conf_upd->execute([$especialidad_id, $h_inicio, $h_fin, $veterinario_id]);
        
        // Actualizar datos de vista
        $vet_data['especialidad_id'] = $especialidad_id;
        $vet_data['horario_inicio'] = $h_inicio;
        $vet_data['horario_fin'] = $h_fin;
        
        $mensaje = "success|Tus preferencias profesionales de horario y especialidad se han guardado.";
    } catch (Exception $e) {
        $mensaje = "error|Error al guardar ajustes profesionales.";
    }
}

// --- CONSULTAS DE DATOS ---

// Listado de Especialidades
$especialidades = [];
try {
    $especialidades = $db->query("SELECT * FROM especialidades ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Conteo rápido de estadísticas médicas del Doctor
try {
    // Total consultas asignadas hoy en adelante
    $stmt_st1 = $db->prepare("SELECT COUNT(*) FROM citas WHERE veterinario_id = ? AND fecha >= CURRENT_DATE() AND estado = 'pendiente'");
    $stmt_st1->execute([$veterinario_id]);
    $conteo_pendientes = $stmt_st1->fetchColumn();

    // Total completadas históricas
    $stmt_st2 = $db->prepare("SELECT COUNT(*) FROM citas WHERE veterinario_id = ? AND estado = 'completada'");
    $stmt_st2->execute([$veterinario_id]);
    $conteo_completadas = $stmt_st2->fetchColumn();
} catch (PDOException $e) {
    $conteo_pendientes = $conteo_completadas = 0;
}

// Citas del Doctor
$mis_citas = [];
try {
    $stmt_citas = $db->prepare("
        SELECT c.*, u.nombre as paciente_nombre, u.email as paciente_contacto
        FROM citas c
        JOIN usuarios u ON c.cliente_id = u.id
        WHERE c.veterinario_id = ?
        ORDER BY c.fecha ASC, c.hora ASC
    ");
    $stmt_citas->execute([$veterinario_id]);
    $mis_citas = $stmt_citas->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mis_citas = [];
}
?>
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clínica Veterinaria | Panel Médico</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-med: #06b6d4;
            --bg-dark: #020617;
            --glass: rgba(15, 23, 42, 0.75);
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-dark);
            color: #f1f5f9;
            background-image: 
                linear-gradient(to bottom, rgba(2, 6, 23, 0.85), rgba(2, 6, 23, 0.95)),
                url('assets/img/hero-bg.png');
            background-attachment: fixed;
            background-size: cover;
            background-position: center;
            overflow-x: hidden;
        }

        .glass-panel {
            background: var(--glass);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            position: relative;
            z-index: 10;
        }

        .custom-scroll::-webkit-scrollbar { width: 5px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 10px; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade { animation: fadeIn 0.4s ease-out forwards; }

        input, select, textarea, button, a {
            position: relative;
            z-index: 50 !important;
            pointer-events: auto !important;
        }
        
        #edit-modal {
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
    </style>
</head>
<body class="min-h-screen w-full flex flex-col">

    <!-- MODAL REPROGRAMACIÓN -->
    <div id="edit-modal" class="hidden">
        <div class="glass-panel p-8 md:p-10 rounded-[2.5rem] max-w-lg w-full border-cyan-500/30">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-bold flex items-center gap-2 text-cyan-400"><i data-lucide="calendar"></i> Reprogramar Paciente</h3>
                <button onclick="closeReprogramModal()" class="text-slate-400 hover:text-white"><i data-lucide="x"></i></button>
            </div>
            
            <form action="panel_doctor.php" method="POST" class="space-y-4">
                <input type="hidden" name="cita_id" id="edit-cita-id">
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-500 uppercase">Nueva Fecha</label>
                        <input type="date" name="fecha" id="edit-fecha" required min="<?php echo date('Y-m-d'); ?>" class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500 text-white font-bold">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-500 uppercase">Nueva Hora</label>
                        <input type="time" name="hora" id="edit-hora" required class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500 text-white font-bold">
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-500 uppercase">Tipo de Consulta</label>
                    <select name="tipo_cita" id="edit-tipo" required class="w-full bg-black/40 border border-white/10 rounded-xl p-4 text-sm outline-none focus:border-cyan-500 text-white font-bold appearance-none">
                        <option value="cita medica">Consulta Médica</option>
                        <option value="cita estetica">Servicio de Peluquería / Estética</option>
                    </select>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-500 uppercase">Motivos y Observaciones</label>
                    <textarea name="motivo" id="edit-motivo" required rows="3" class="w-full bg-black/40 border border-white/10 p-4 rounded-xl text-sm outline-none focus:border-cyan-500 text-white"></textarea>
                </div>

                <button type="submit" name="action_doctor_edit_cita" class="w-full bg-cyan-600 hover:bg-cyan-700 text-white py-4 rounded-xl font-black text-xs uppercase tracking-widest transition-all">SOBREESCRIBIR CITACIÓN</button>
            </form>
        </div>
    </div>

    <!-- HEADER SUPERIOR -->
    <header class="glass-panel border-b border-white/5 px-10 py-5 flex items-center justify-between w-full">
        <div class="flex items-center gap-4">
            <img src="assets/img/logo.png" alt="Logo VitalZoo" class="h-10 w-auto" onerror="this.src='https://via.placeholder.com/100x40'">
            <h2 class="text-xs font-black text-slate-400 uppercase tracking-widest hidden sm:block">Panel de Especialistas / <span class="text-cyan-400">Dr. <?php echo $_SESSION['user_nombre']; ?></span></h2>
        </div>
        <div class="flex items-center gap-6">
            <div class="flex items-center gap-2 px-4 py-2 bg-cyan-500/10 rounded-full border border-cyan-500/20">
                <span class="w-2 h-2 bg-cyan-500 rounded-full animate-pulse"></span>
                <span class="text-[9px] font-black text-cyan-400 uppercase">Modulo Médico Activo</span>
            </div>
            <a href="logout.php" class="text-red-400 font-bold text-xs hover:underline">Cerrar Sesión</a>
        </div>
    </header>

    <main class="flex-1 p-10 max-w-7xl mx-auto w-full space-y-12">

        <!-- CABECERA PRINCIPAL -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6 animate-fade">
            <div>
                <h1 class="text-4xl font-black tracking-tighter">Bienvenido, <span class="text-cyan-400">Dr. <?php echo $_SESSION['user_nombre']; ?></span></h1>
                <p class="text-slate-400 text-sm">Gestiona la agenda médica, reprogramaciones y disponibilidad de tus consultas asignadas.</p>
            </div>
        </div>

        <!-- NOTIFICACIONES DE ACCIÓN -->
        <?php if(!empty($mensaje)): list($m_tipo, $m_txt) = explode('|', $mensaje); ?>
            <div class="p-5 rounded-2xl glass-panel border-l-4 <?php echo $m_tipo=='success'?'border-green-500 text-green-400':'border-red-500 text-red-400' ?> flex items-center justify-between animate-fade">
                <div class="flex items-center gap-4">
                    <i data-lucide="<?php echo $m_tipo=='success'?'check-circle':'alert-triangle' ?>"></i>
                    <span class="text-sm font-bold"><?php echo $m_txt; ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-slate-500 hover:text-white"><i data-lucide="x"></i></button>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- PANEL IZQUIERDO: KPI Y CONFIGURACIÓN -->
            <div class="space-y-8 lg:col-span-1">
                
                <!-- KPI Médicos -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="glass-panel p-6 rounded-3xl border-b-2 border-cyan-500/40">
                        <i data-lucide="clock" class="text-cyan-400 mb-3" size="20"></i>
                        <p class="text-2xl font-black"><?php echo $conteo_pendientes; ?></p>
                        <p class="text-[9px] font-bold text-slate-500 uppercase">Consultas Pendientes</p>
                    </div>
                    <div class="glass-panel p-6 rounded-3xl border-b-2 border-green-500/40">
                        <i data-lucide="check-square" class="text-green-500 mb-3" size="20"></i>
                        <p class="text-2xl font-black"><?php echo $conteo_completadas; ?></p>
                        <p class="text-[9px] font-bold text-slate-500 uppercase">Historial Completado</p>
                    </div>
                </div>

                <!-- CONFIGURACIÓN PROFESIONAL -->
                <div class="glass-panel p-8 rounded-[2rem]">
                    <h3 class="text-lg font-black mb-6 flex items-center gap-2 border-b border-white/5 pb-4"><i data-lucide="sliders" class="text-cyan-400"></i> Ajustes de Agenda</h3>
                    <form action="panel_doctor.php" method="POST" class="space-y-4">
                        <div class="space-y-1">
                            <label class="text-[10px] font-black text-slate-500 uppercase">Mi Especialidad</label>
                            <select name="especialidad_id" required class="w-full bg-black/40 border border-white/10 rounded-xl p-3.5 text-xs font-bold outline-none focus:border-cyan-500">
                                <?php foreach($especialidades as $esp): ?>
                                    <option value="<?php echo $esp['id']; ?>" class="text-slate-900" <?php echo $vet_data['especialidad_id'] == $esp['id'] ? 'selected':'' ?>><?php echo $esp['nombre']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="text-[10px] font-black text-slate-500 uppercase">Hora Inicio</label>
                                <input type="time" name="horario_inicio" value="<?php echo $vet_data['horario_inicio']; ?>" required class="w-full bg-black/40 border border-white/10 rounded-xl p-3 text-xs outline-none focus:border-cyan-500 text-white font-bold">
                            </div>
                            <div class="space-y-1">
                                <label class="text-[10px] font-black text-slate-500 uppercase">Hora Cierre</label>
                                <input type="time" name="horario_fin" value="<?php echo $vet_data['horario_fin']; ?>" required class="w-full bg-black/40 border border-white/10 rounded-xl p-3 text-xs outline-none focus:border-cyan-500 text-white font-bold">
                            </div>
                        </div>
                        <button type="submit" name="action_doctor_config" class="w-full bg-cyan-600 hover:bg-cyan-700 text-white py-3.5 rounded-xl font-black text-[10px] uppercase tracking-widest transition-all">Guardar Disponibilidad</button>
                    </form>
                </div>
            </div>

            <!-- PANEL DERECHO: CRONOGRAMA DE CITAS -->
            <div class="lg:col-span-2 space-y-6">
                <div class="glass-panel rounded-[2rem] overflow-hidden">
                    <div class="p-8 border-b border-white/5 bg-white/[0.01]">
                        <h3 class="font-black text-lg flex items-center gap-3"><i data-lucide="list" class="text-cyan-400"></i> Tu Agenda Médica</h3>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="text-[9px] font-black text-slate-500 uppercase tracking-widest border-b border-white/5 bg-black/10">
                                    <th class="px-8 py-4">Paciente</th>
                                    <th class="px-8 py-4">Fecha y Hora</th>
                                    <th class="px-8 py-4">Servicio</th>
                                    <th class="px-8 py-4 text-right">Control</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php if(empty($mis_citas)): ?>
                                    <tr>
                                        <td colspan="4" class="p-16 text-center text-slate-500 font-bold uppercase text-xs">No tienes citas programadas asignadas en el sistema.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach($mis_citas as $c): ?>
                                <tr class="hover:bg-white/[0.01] transition-colors">
                                    <td class="px-8 py-6">
                                        <div class="font-black text-sm"><?php echo $c['paciente_nombre']; ?></div>
                                        <div class="text-[10px] text-slate-500 italic"><?php echo $c['paciente_contacto']; ?></div>
                                    </td>
                                    <td class="px-8 py-6">
                                        <div class="font-black text-xs text-white"><?php echo date('d M, Y', strtotime($c['fecha'])); ?></div>
                                        <div class="text-[10px] text-slate-500 font-bold"><?php echo substr($c['hora'], 0, 5); ?> hrs</div>
                                    </td>
                                    <td class="px-8 py-6">
                                        <span class="px-2 py-1 rounded text-[9px] font-black uppercase tracking-wider <?php echo $c['tipo_cita'] == 'cita estetica' ? 'bg-orange-500/10 text-orange-400 border border-orange-500/20' : 'bg-cyan-500/10 text-cyan-400 border border-cyan-500/20' ?>">
                                            <?php echo $c['tipo_cita'] ?: 'cita medica'; ?>
                                        </span>
                                        <p class="text-[10px] text-slate-500 mt-2 truncate max-w-[150px]">"<?php echo $c['motivo']; ?>"</p>
                                    </td>
                                    <td class="px-8 py-6 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <!-- Enviar Recordatorio (Llama a API) -->
                                            <button onclick="enviarRecordatorio('<?php echo $c['id']; ?>', '<?php echo addslashes($c['paciente_nombre']); ?>', '<?php echo $c['contacto_notificacion']; ?>', '<?php echo $c['metodo_notificacion']; ?>')" class="p-2 bg-emerald-500/10 text-emerald-400 hover:bg-emerald-600 hover:text-white rounded-lg transition" title="Notificar">
                                                <i data-lucide="bell" size="14"></i>
                                            </button>
                                            
                                            <!-- Modificar Cita -->
                                            <button onclick='openReprogramModal(<?php echo json_encode($c); ?>)' class="p-2 bg-blue-500/10 text-blue-400 hover:bg-blue-600 hover:text-white rounded-lg transition" title="Reprogramar">
                                                <i data-lucide="edit" size="14"></i>
                                            </button>
                                            
                                            <!-- Completar Cita -->
                                            <?php if($c['estado'] == 'pendiente'): ?>
                                                <a href="?estado_cita=completada&cita_id=<?php echo $c['id']; ?>" class="p-2 bg-green-500/10 text-green-400 hover:bg-green-600 hover:text-white rounded-lg transition" title="Marcar completada">
                                                    <i data-lucide="check" size="14"></i>
                                                </a>
                                            <?php endif; ?>

                                            <!-- Cancelar Cita -->
                                            <?php if($c['estado'] != 'cancelada'): ?>
                                                <a href="?estado_cita=cancelada&cita_id=<?php echo $c['id']; ?>" onclick="return confirm('¿Seguro que deseas cancelar esta cita?')" class="p-2 bg-red-500/10 text-red-400 hover:bg-red-600 hover:text-white rounded-lg transition" title="Cancelar">
                                                    <i data-lucide="x" size="14"></i>
                                                </a>
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

        </div>
    </main>

    <script>
        lucide.createIcons();

        function openReprogramModal(cita) {
            document.getElementById('edit-cita-id').value = cita.id;
            document.getElementById('edit-fecha').value = cita.fecha;
            document.getElementById('edit-hora').value = cita.hora.substring(0, 5);
            document.getElementById('edit-tipo').value = cita.tipo_cita ? cita.tipo_cita : 'cita medica';
            document.getElementById('edit-motivo').value = cita.motivo;

            const modal = document.getElementById('edit-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            lucide.createIcons();
        }

        function closeReprogramModal() {
            const modal = document.getElementById('edit-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // LLAMADA A LA API DE RECORDATORIO
        function enviarRecordatorio(citaId, nombre, contacto, metodo) {
            if (!contacto) {
                alert("Este paciente no tiene registrado medio de contacto para alertas.");
                return;
            }

            // Realizar llamada de red para despachar la notificación simulada
            fetch(`api_recordatorio.php?cita_id=${citaId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        alert(`¡Notificación despachada con éxito!\nPaciente: ${nombre}\nEnviado por: ${metodo} al: ${contacto}\n\nMensaje enviado: "${data.mensaje_preview}"`);
                    } else {
                        alert("Error de API: " + data.message);
                    }
                })
                .catch(() => alert("Error de red con el API de notificaciones."));
        }
    </script>
</body>
</html>