<?php
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
require 'app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/bootstrap.php';
$app = \Config\Services::codeigniter();
$app->initialize();

$db = \Config\Database::connect();
$builder = $db->table('users');
$builder->where('role', 'driver');
$query = $builder->get();

header('Content-Type: application/json');
echo json_encode($query->getResultArray(), JSON_PRETTY_PRINT);
