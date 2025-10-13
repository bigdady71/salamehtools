<?php
session_start();
function auth_user(){return $_SESSION['user']??null;}