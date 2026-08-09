<?php

declare(strict_types=1);

use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Europe/Rome');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$app = AppFactory::create();

// In locale l'app non è montata alla radice del dominio (es. /FarmaSintonia/,
// instradata su public/ da un .htaccess di root): calcola il base path dallo
// script realmente eseguito. In produzione (DocumentRoot = public/) risulta
// sempre "", quindi non ha alcun effetto.
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = dirname($scriptName);
if (basename($basePath) === 'public') {
    $basePath = dirname($basePath);
}
if ($basePath === '/' || $basePath === '\\') {
    $basePath = '';
}
if ($basePath !== '') {
    $app->setBasePath($basePath);
}

$twig = Twig::create(__DIR__ . '/../templates', [
    'cache' => false,
]);
$twig->getEnvironment()->addGlobal('base_path', $basePath);
$app->add(TwigMiddleware::create($app, $twig));

$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(($_ENV['APP_ENV'] ?? '') === 'development', true, true);

(require __DIR__ . '/../src/routes.php')($app);

$app->run();
