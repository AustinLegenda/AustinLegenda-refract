<?php
// WindForecastImporter.php
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/config.php';


use Legenda\NormalSurf\Hooks\LoadData;

$pdo = new PDO(
  "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
  DB_USER, DB_PASS,
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$defs = [
  ['key'=>'41112',  'office'=>'JAX', 'x'=>71, 'y'=>80], // Fernandina
  ['key'=>'median', 'office'=>'JAX', 'x'=>74, 'y'=>68], // St. Johns Approach
  ['key'=>'41117',  'office'=>'JAX', 'x'=>83, 'y'=>45], // St. Augustine
];

$counts = LoadData::winds_fcst_refresh($pdo, $defs);
var_dump($counts);
