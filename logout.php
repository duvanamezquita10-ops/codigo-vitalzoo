<?php
// Iniciar la sesión para poder acceder a ella y eliminarla
session_start();

// Limpiar todas las variables de sesión
$_SESSION = array();

// Si se desea destruir la sesión completamente, también hay que borrar la cookie de sesión.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finalmente, destruir la sesión
session_destroy();

// Redirigir al inicio o al login
header("Location: index.php");
exit;
?>