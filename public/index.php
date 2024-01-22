<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Valitron\Validator;
use Carbon\Carbon;
use Slim\Routing\RouteContext;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

session_start();

$container = new Container();
$app = AppFactory::createFromContainer($container);

$app->add(function (Request $request, RequestHandler $handler) use ($container) {
    $routeContext = RouteContext::fromRequest($request);
    $route = $routeContext->getRoute();
    $container->set('routeName', $routeName = !empty($route) ? $route->getName() : '');

    return $handler->handle($request);
});

$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

$container->set('renderer', function () use ($container) {
    $phpVew = new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
    $phpVew->setLayout('layout.phtml');
    $phpVew->addAttribute('routeName', $container->get('routeName'));
    return $phpVew;
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$container->set('pdo', function () {
    $databaseUrl = parse_url(getenv('DATABASE_URL'));

    if (!$databaseUrl) {
        throw new \Exception("Error reading the database URL");
    }

    $username = $databaseUrl['user'];
    $password = $databaseUrl['pass'];
    $host = $databaseUrl['host'];
    $port = $databaseUrl['port'] ?? null;
    $dbName = ltrim($databaseUrl['path'], '/');

    if (isset($port)) {
        $dsn = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $host,
            $port,
            $dbName,
            $username,
            $password
        );
    } else {
        $dsn = sprintf(
            "pgsql:host=%s;dbname=%s;user=%s;password=%s",
            $host,
            $dbName,
            $username,
            $password
        );
    }

    $pdo = new \PDO($dsn);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

    return $pdo;
});

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'home.phtml');
})->setName('home');

$app->get('/urls', function ($request, $response) {
    $pdo = $this->get('pdo');
    $sql = 'SELECT
                urls.id,
                urls.name,
                max(url_checks.created_at) AS last_check,
                url_checks.status_code 
            FROM
                url_checks 
            RIGHT JOIN
                urls 
                    ON url_checks.url_id = urls.id 
            GROUP BY
                urls.id,
                urls.name,
                url_checks.status_code 
            ORDER BY
                urls.id DESC';
    $stmt = $pdo->query($sql);
    $urlsData = $stmt->fetchAll();
    $params = ['urlsData' => $urlsData];
    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls');

$app->get('/urls/{id}', function ($request, $response, $args) {
    $id = $args['id'];
    $pdo = $this->get('pdo');
    $stmt = $pdo->prepare('SELECT * FROM urls WHERE id = ?');
    $stmt->execute([$id]);
    $urlData = $stmt->fetch();

    if (!$urlData) {
        die('Нет такого URL');
    }

    $stmt = $pdo->prepare('SELECT * FROM url_checks WHERE url_id = ? ORDER BY id DESC');
    $stmt->execute([$id]);
    $urlChecksData = $stmt->fetchAll();

    $messages = $this->get('flash')->getMessages();
    $params = ['flash' => $messages, 'urlData' => $urlData, 'urlChecksData' => $urlChecksData];
    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('showUrl');

$app->post('/urls', function ($request, $response) use ($router) {
    $urlData = $request->getParsedBodyParam('url');
    $validator = new Validator(['name' => $urlData['name']]);
    $validator->rule('required', 'name')->message('URL не должен быть пустым');
    $validator->rule('url', 'name')->message('Некорректный URL');
    $validator->rule('lengthMax', 'name', 255)->message('URL превышает 255 символов');

    if ($validator->validate()) {
        $parsedURL = parse_url($urlData['name']);
        $normilizedUrl = sprintf('%s://%s', $parsedURL['scheme'], $parsedURL['host']);
        $timestamp = Carbon::now()->toDateTimeString();

        try {
            $pdo = $this->get('pdo');
            $stmt = $pdo->prepare('SELECT * FROM urls WHERE name = ?');
            $stmt->execute([$normilizedUrl]);
            $existedUrl = $stmt->fetch();

            if (!$existedUrl) {
                $stmt = $pdo->prepare('INSERT INTO urls (name, created_at) VALUES (?, ?)');
                $stmt->execute([$normilizedUrl, $timestamp]);
                $id = $pdo->lastInsertId();
                $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
            } else {
                $id = $existedUrl['id'];
                $this->get('flash')->addMessage('success', 'Страница уже существует');
            }
        } catch (\Exception $e) {
            die($e->getMessage());
        }

        $url = $router->urlFor('showUrl', ['id' => $id]);
        return $response->withRedirect($url);
    }

    $errors = $validator->errors();
    $params = [
        'urlData' => $urlData,
        'errors' => $errors
    ];

    $response = $response->withStatus(422);
    return $this->get('renderer')->render($response, 'home.phtml', $params);
});

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($router) {
    $id = $args['url_id'];
    $pdo = $this->get('pdo');
    $stmt = $pdo->prepare('SELECT * FROM urls WHERE id = ?');
    $stmt->execute([$id]);
    $urlData = $stmt->fetch();

    if (!$urlData) {
        die('Нет такого URL');
    }

    $timestamp = Carbon::now()->toDateTimeString();
    $stmt = $pdo->prepare('INSERT INTO url_checks (url_id, created_at) VALUES (?, ?)');
    $stmt->execute([$id, $timestamp]);

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');

    $url = $router->urlFor('showUrl', ['id' => $id]);
    return $response->withRedirect($url);
});

$app->run();
