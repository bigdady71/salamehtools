<?php
$t=$_GET['t']??'empty';header('Content-Type:image/png');readfile('https://chart.googleapis.com/chart?cht=qr&chs=150x150&chl='.urlencode($t));