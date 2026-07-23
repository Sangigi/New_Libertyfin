<?php
// logout.php

require_once __DIR__ . '/vendor/autoload.php';

use App\Http\Middleware\Auth;

Auth::start();

// Destruir todas las variables de sesión
$_SESSION = array();

// Destruir la sesión
session_destroy();

// Redirigir al login
header("Location: ../Login");
exit();
