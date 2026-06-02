<?php
/**
 * Aplicación de Registro y Sincronización de Estudiantes con Soporte de Imágenes
 * Conectividad dual: PostgreSQL (PDO) y MongoDB Atlas (Composer Driver)
 * Muestra la separación clara: Postgres guarda solo texto; MongoDB guarda texto + imagen Base64
 */

require 'vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;

// --- 1. CONFIGURACIÓN DE CONEXIONES Y VARIABLES DE ENTORNO ---

// Configuración PostgreSQL
$pg_url = getenv('DATABASE_URL'); 
$pg_connected = false;
$pdo = null;
$pg_error = null;

try {
    if ($pg_url) {
        $dbopts = parse_url($pg_url);
        $pg_host = $dbopts["host"] ?? '';
        $pg_port = $dbopts["port"] ?? 5432;
        $pg_user = $dbopts["user"] ?? '';
        $pg_pass = $dbopts["pass"] ?? '';
        $pg_db   = ltrim($dbopts["path"] ?? '', '/');
    } else {
        $pg_host = getenv('PGHOST') ?: 'localhost';
        $pg_port = getenv('PGPORT') ?: '5432';
        $pg_user = getenv('PGUSER') ?: 'postgres';
        $pg_pass = getenv('PGPASSWORD') ?: '';
        $pg_db   = getenv('PGDATABASE') ?: 'estudiantes';
    }

    if (!empty($pg_host)) {
        $dsn = "pgsql:host=$pg_host;port=$pg_port;dbname=$pg_db;sslmode=require";
        $pdo = new PDO($dsn, $pg_user, $pg_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5
        ]);
        $pg_connected = true;
    } else {
        $pg_error = "La variable de entorno de PostgreSQL no está configurada.";
    }
} catch (PDOException $e) {
    $pg_error = "Error al conectar con PostgreSQL: " . $e->getMessage();
}

// Configuración MongoDB
$mongo_uri = getenv('MONGODB_URI'); 
$mongo_db_name = getenv('MONGODB_DB') ?: 'colegio';
$mongo_connected = false;
$mongo_collection = null;
$mongo_error = null;

try {
    if ($mongo_uri) {
        if (!extension_loaded('mongodb')) {
            throw new Exception("La extensión PHP 'mongodb' no está instalada o cargada.");
        }
        $mongo_client = new Client($mongo_uri);
        $mongo_client->listDatabases(); // Test de conexión rápida
        
        $mongo_collection = $mongo_client->selectCollection($mongo_db_name, 'estudiantes');
        $mongo_connected = true;
    } else {
        $mongo_error = "La variable de entorno MONGODB_URI no está configurada.";
    }
} catch (Exception $e) {
    $mongo_error = "Error al conectar con MongoDB: " . $e->getMessage();
}


// --- 2. PROCESAMIENTO DEL FORMULARIO DE REGISTRO (POST) ---

$status_message = null;
$status_type = null; 
$pg_save_ok = false;
$mongo_save_ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar'])) {
    $codigo = trim($_POST['codigo'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $programa = trim($_POST['programa'] ?? '');
    $base64_image = null;

    // Procesar la imagen subida (Cédula o Diploma)
    if (isset($_FILES['documento_img']) && $_FILES['documento_img']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['documento_img']['tmp_name'];
        $file_type = $_FILES['documento_img']['type'];
        $file_size = $_FILES['documento_img']['size'];
        
        // Validar que el archivo sea efectivamente una imagen
        if (strpos($file_type, 'image/') === 0) {
            // Límite prudente de 4MB para conversión Base64 en este demo
            if ($file_size <= 4 * 1024 * 1024) {
                $file_data = file_get_contents($file_tmp);
                $base64_image = 'data:' . $file_type . ';base64,' . base64_encode($file_data);
            } else {
                $status_message = "La imagen supera el tamaño máximo permitido (4MB).";
                $status_type = "error";
            }
        } else {
            $status_message = "El archivo debe ser una imagen válida (PNG, JPG, JPEG).";
            $status_type = "error";
        }
    } else {
        $status_message = "Es obligatorio adjuntar un documento (Cédula o Diploma).";
        $status_type = "error";
    }

    // Continuar si pasó la validación del archivo
    if ($status_type !== 'error') {
        if (empty($codigo) || empty($nombre) || empty($email) || empty($programa)) {
            $status_message = "Todos los campos de texto son de carácter obligatorio.";
            $status_type = "error";
        } else {
            // 1. Guardar en PostgreSQL (Estructurado sin imagen, optimizando espacio)
            if ($pg_connected && $pdo) {
                try {
                    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM estudiantes WHERE codigo = ?");
                    $stmt_check->execute([$codigo]);
                    if ($stmt_check->fetchColumn() > 0) {
                        throw new Exception("El código de estudiante ya existe en PostgreSQL.");
                    }

                    $stmt = $pdo->prepare("INSERT INTO estudiantes (codigo, nombre, email, programa) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$codigo, $nombre, $email, $programa]);
                    $pg_save_ok = true;
                } catch (Exception $e) {
                    $pg_error = "Error al insertar en PostgreSQL: " . $e->getMessage();
                }
            }

            // 2. Guardar en MongoDB Atlas (Documental con imagen en Base64)
            if ($mongo_connected && $mongo_collection) {
                try {
                    $exists = $mongo_collection->findOne(['codigo' => $codigo]);
                    if ($exists) {
                        throw new Exception("El código de estudiante ya existe en MongoDB.");
                    }

                    $insertResult = $mongo_collection->insertOne([
                        'codigo' => $codigo,
                        'nombre' => $nombre,
                        'email' => $email,
                        'programa' => $programa,
                        'documento_base64' => $base64_image, // Imagen guardada como string codificado!
                        'fecha_respaldo' => new UTCDateTime()
                    ]);
                    
                    if ($insertResult->getInsertedCount() > 0) {
                        $mongo_save_ok = true;
                    }
                } catch (Exception $e) {
                    $mongo_error = "Error al insertar en MongoDB Atlas: " . $e->getMessage();
                }
            }

            // Evaluación de Sincronización
            if ($pg_save_ok && $mongo_save_ok) {
                $status_message = "¡Sincronización Exitosa! El estudiante fue registrado en PostgreSQL (SQL) y su respaldo con documento gráfico se almacenó en MongoDB Atlas (NoSQL).";
                $status_type = "success";
            } elseif ($pg_save_ok && !$mongo_save_ok) {
                $status_message = "Registro parcial: Guardado en PostgreSQL, pero falló el respaldo con imagen en MongoDB Atlas. Detalle: " . htmlspecialchars($mongo_error);
                $status_type = "warning";
            } elseif (!$pg_save_ok && $mongo_save_ok) {
                $status_message = "Registro parcial: Guardado en MongoDB Atlas con su imagen, pero falló el registro relacional en PostgreSQL. Detalle: " . htmlspecialchars($pg_error);
                $status_type = "warning";
            } else {
                $status_message = "Error general: No se pudo registrar la información en ningún motor de base de datos.";
                $status_type = "error";
            }
        }
    }
}


// --- 3. CONSULTA DE LISTADOS ---

$estudiantes_pg = [];
if ($pg_connected && $pdo) {
    try {
        $stmt_list = $pdo->query("SELECT * FROM estudiantes ORDER BY fecha_registro DESC");
        $estudiantes_pg = $stmt_list->fetchAll();
    } catch (PDOException $e) {
        $pg_error = "Error al consultar PostgreSQL: " . $e->getMessage();
    }
}

$estudiantes_mongo = [];
if ($mongo_connected && $mongo_collection) {
    try {
        $cursor = $mongo_collection->find([], ['sort' => ['fecha_respaldo' => -1]]);
        $estudiantes_mongo = iterator_to_array($cursor);
    } catch (Exception $e) {
        $mongo_error = "Error al consultar MongoDB: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronizador Estudiantil: PostgreSQL & MongoDB con Imágenes</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col">

    <!-- Header -->
    <header class="bg-gradient-to-r from-blue-700 to-indigo-800 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-5 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 bg-white/10 rounded-lg">
                    <svg class="w-8 h-8 text-blue-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-xl font-bold tracking-tight">Sincronizador Multimotor Estudiantil</h1>
                    <p class="text-xs text-blue-100">Separación de Datos: Postgres (Relacional Ligero) • MongoDB (BSON + Imágenes)</p>
                </div>
            </div>
            
            <div class="flex gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold <?php echo $pg_connected ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'; ?>">
                    <span class="w-2 h-2 rounded-full <?php echo $pg_connected ? 'bg-emerald-400' : 'bg-rose-400'; ?>"></span>
                    PostgreSQL
                </span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold <?php echo $mongo_connected ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300'; ?>">
                    <span class="w-2 h-2 rounded-full <?php echo $mongo_connected ? 'bg-emerald-400' : 'bg-rose-400'; ?>"></span>
                    MongoDB Atlas
                </span>
            </div>
        </div>
    </header>

    <!-- Contenido Principal -->
    <main class="flex-grow max-w-7xl w-full mx-auto px-4 py-8 grid grid-cols-1 lg:grid-cols-3 gap-8">

        <!-- Formulario de Registro (Columna Izquierda) -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 sticky top-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                    </svg>
                    Registrar Estudiante
                </h2>

                <!-- Tarjetas de estado / Mensajes de Respuesta -->
                <?php if ($status_message): ?>
                    <div class="mb-5 p-4 rounded-lg text-sm border <?php 
                        if ($status_type === 'success') echo 'bg-emerald-50 border-emerald-200 text-emerald-800';
                        elseif ($status_type === 'warning') echo 'bg-amber-50 border-amber-200 text-amber-800';
                        else echo 'bg-rose-50 border-rose-200 text-rose-800';
                    ?>">
                        <?php echo $status_message; ?>
                    </div>
                <?php endif; ?>

                <!-- Formulario habilitado para archivos binarios -->
                <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Código Estudiante *</label>
                        <input type="text" name="codigo" required placeholder="Ej: EST-2026-05" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Nombre Completo *</label>
                        <input type="text" name="nombre" required placeholder="Ej: Brayan Mendoza" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Correo Electrónico *</label>
                        <input type="email" name="email" required placeholder="Ej: brayan@universidad.com" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Programa Académico *</label>
                        <input type="text" name="programa" required placeholder="Ej: Ingeniería de Software" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                    </div>
                    
                    <!-- Carga de Archivo (Cédula o Diploma) -->
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">Foto Documento (Cédula/Diploma) *</label>
                        <input type="file" name="documento_img" accept="image/*" required class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 border border-slate-300 rounded-lg p-1 bg-white cursor-pointer">
                        <p class="text-[10px] text-slate-400 mt-1">Sube una foto JPG/PNG nítida de hasta 4MB.</p>
                    </div>

                    <button type="submit" name="registrar" class="w-full bg-gradient-to-r from-blue-700 to-indigo-700 hover:from-blue-800 hover:to-indigo-800 text-white font-semibold py-2 px-4 rounded-lg shadow-sm transition-all text-sm flex items-center justify-center gap-2 mt-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Guardar & Sincronizar
                    </button>
                </form>
            </div>
        </div>

        <!-- Vista de Datos en Paralelo (Columna Derecha / Centro) -->
        <div class="lg:col-span-2 space-y-8">
            
            <!-- Listado 1: PostgreSQL (Relacional - Estricto y Ligero) -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4 flex items-center justify-between border-b border-slate-100 pb-3">
                    <span class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-blue-600"></span>
                        Listado PostgreSQL (SQL Estructurado - Sin Imágenes)
                    </span>
                    <span class="text-xs font-semibold bg-blue-50 text-blue-700 px-2.5 py-1 rounded-full">
                        <?php echo count($estudiantes_pg); ?> registros
                    </span>
                </h2>

                <?php if (empty($estudiantes_pg)): ?>
                    <div class="text-center py-8 text-slate-400 text-sm">
                        No hay estudiantes registrados en PostgreSQL.
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm border-collapse">
                            <thead>
                                <tr class="border-b border-slate-200 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                                    <th class="py-3 px-2">Código</th>
                                    <th class="py-3 px-2">Nombre</th>
                                    <th class="py-3 px-2">Email</th>
                                    <th class="py-3 px-2">Programa</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <?php foreach ($estudiantes_pg as $est): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                        <td class="py-3 px-2 font-mono text-xs font-bold text-slate-900"><?php echo htmlspecialchars($est['codigo']); ?></td>
                                        <td class="py-3 px-2 font-medium"><?php echo htmlspecialchars($est['nombre']); ?></td>
                                        <td class="py-3 px-2 text-slate-500"><?php echo htmlspecialchars($est['email']); ?></td>
                                        <td class="py-3 px-2 text-xs"><span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded"><?php echo htmlspecialchars($est['programa']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Listado 2: MongoDB Atlas (Documental - Almacena las imágenes pesadas en Base64) -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4 flex items-center justify-between border-b border-slate-100 pb-3">
                    <span class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-600"></span>
                        Listado MongoDB Atlas (NoSQL - Con Imagen Base64)
                    </span>
                    <span class="text-xs font-semibold bg-emerald-50 text-emerald-700 px-2.5 py-1 rounded-full">
                        <?php echo count($estudiantes_mongo); ?> documentos
                    </span>
                </h2>

                <?php if (empty($estudiantes_mongo)): ?>
                    <div class="text-center py-8 text-slate-400 text-sm">
                        No hay documentos respaldados en MongoDB Atlas.
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm border-collapse">
                            <thead>
                                <tr class="border-b border-slate-200 text-slate-400 text-xs uppercase tracking-wider font-semibold">
                                    <th class="py-3 px-2">Código</th>
                                    <th class="py-3 px-2">Nombre</th>
                                    <th class="py-3 px-2">Documento de Respaldo</th>
                                    <th class="py-3 px-2">Metadatos BSON</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <?php foreach ($estudiantes_mongo as $doc): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                        <td class="py-3 px-2 font-mono text-xs font-bold text-slate-900"><?php echo htmlspecialchars($doc['codigo'] ?? ''); ?></td>
                                        <td class="py-3 px-2 font-medium"><?php echo htmlspecialchars($doc['nombre'] ?? ''); ?></td>
                                        
                                        <!-- Columna del Visor Gráfico con Miniatura, Ojo y Texto -->
                                        <td class="py-3 px-2">
                                            <?php if (!empty($doc['documento_base64'])): ?>
                                                <div class="flex items-center gap-3">
                                                    <!-- Miniatura -->
                                                    <img src="<?php echo $doc['documento_base64']; ?>" 
                                                         class="w-10 h-10 object-cover rounded-lg border border-slate-200 shadow-sm cursor-pointer hover:ring-2 hover:ring-indigo-500 transition-all"
                                                         onclick="abrirVisor('<?php echo $doc['documento_base64']; ?>', '<?php echo htmlspecialchars($doc['nombre'] ?? ''); ?>')">
                                                    
                                                    <!-- Botón de Ojo interactivo "Ver imagen" -->
                                                    <button onclick="abrirVisor('<?php echo $doc['documento_base64']; ?>', '<?php echo htmlspecialchars($doc['nombre'] ?? ''); ?>')"
                                                            class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 font-semibold bg-indigo-50 hover:bg-indigo-100 px-2.5 py-1.5 rounded-lg transition-all shadow-sm">
                                                        <!-- Icono del Ojo SVG -->
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                        </svg>
                                                        Ver imagen
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-xs text-slate-400 italic">Sin documento cargado</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td class="py-3 px-2">
                                            <div class="text-[9px] bg-emerald-50 text-emerald-800 p-1.5 rounded-lg font-mono max-w-xs truncate" title="Objeto BSON completo">
                                                { id: "<?php echo (string)$doc['_id']; ?>", has_image: <?php echo !empty($doc['documento_base64']) ? 'true' : 'false'; ?> }
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </main>

    <!-- VISOR MODAL PREMIUM PARA DOCUMENTOS (CÉDULA O DIPLOMA) -->
    <div id="imageModal" class="hidden fixed inset-0 bg-slate-900/85 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-2xl w-full p-6 shadow-2xl relative animate-in fade-in duration-200">
            <!-- Botón de Cerrar -->
            <button onclick="cerrarVisor()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 p-1.5 rounded-full hover:bg-slate-100 transition-all">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
            <h3 class="text-lg font-bold text-slate-900 mb-1" id="modalTitle">Documento de Estudiante</h3>
            <p class="text-xs text-slate-500 mb-4">Esta imagen se encuentra almacenada de forma estructurada directamente en tu base de datos MongoDB Atlas (Formato BSON - Base64).</p>
            
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-2 flex justify-center items-center overflow-hidden max-h-[450px]">
                <img id="modalImg" src="" alt="Documento oficial del estudiante" class="max-h-[400px] w-auto object-contain rounded-lg shadow-sm">
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-slate-900 text-slate-400 py-6 mt-12 border-t border-slate-800 text-xs">
        <div class="max-w-7xl mx-auto px-4 flex flex-col md:flex-row items-center justify-between gap-4">
            <div>
                <p>© 2026 - Aplicación de Sincronización Estudiantil Dual.</p>
                <p class="text-slate-600">PostgreSQL (Relacional Estricto - Estructura) & MongoDB Atlas (Documental - Multimedia Base64)</p>
            </div>
            <div class="flex gap-4">
                <span class="hover:text-white transition-colors">PHP 8.2</span>
                <span>•</span>
                <span class="hover:text-white transition-colors">PDO PostgreSQL</span>
                <span>•</span>
                <span class="hover:text-white transition-colors">Base64 en MongoDB</span>
            </div>
        </div>
    </footer>

    <!-- SCRIPT DE CONTROL PARA EL MODAL -->
    <script>
        function abrirVisor(base64Data, nombreEstudiante) {
            document.getElementById('modalImg').src = base64Data;
            document.getElementById('modalTitle').innerHTML = "Documento Oficial de: <span class='text-indigo-600'>" + nombreEstudiante + "</span>";
            document.getElementById('imageModal').classList.remove('hidden');
        }

        function cerrarVisor() {
            document.getElementById('imageModal').classList.add('hidden');
            document.getElementById('modalImg').src = "";
        }
        
        // Cerrar modal al hacer clic en las zonas externas oscuras
        window.onclick = function(event) {
            const modal = document.getElementById('imageModal');
            if (event.target == modal) {
                cerrarVisor();
            }
        }
    </script>

</body>
</html>
