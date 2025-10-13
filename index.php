<?php
$path=$_GET['path']??'public/home';$file=__DIR__.'/pages/'.$path.'.php';if(!is_file($file)){http_response_code(404);echo'Not found';exit;}require $file;