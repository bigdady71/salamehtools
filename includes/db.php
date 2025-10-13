<?php
$db=require __DIR__.'/../config/db.php';
$pdo=new PDO($db['dsn'],$db['user'],$db['pass']);