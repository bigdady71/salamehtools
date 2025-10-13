<?php
require_once __DIR__.'/auth.php';
function require_login(){if(!auth_user()){header('Location:/public/login');exit;}}