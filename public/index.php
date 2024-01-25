<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Valitron\Validator;
use Carbon\Carbon;
use Slim\Routing\RouteContext;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpNotFoundException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ClientException;
use DiDom\Document;

session_start();

$container = new Container();
$app = AppFactory::createFromContainer($container);

$app->add(function (Request $request, RequestHandler $handler) use ($container) {
    $routeContext = RouteContext::fromRequest($request);
    $route = $routeContext->getRoute();
    $container->set('routeName', $route ? $route->getName() : '');
    return $handler->handle($request);
});

$app->addRoutingMiddleware();

$customErrorHandler = function (
    Request $request,
    Throwable $exception
) use ($app) {
    if ($exception instanceof HttpNotFoundException) {
        $response = $app->getResponseFactory()->createResponse();
        $response->withStatus(404);
        return $this->get('renderer')->render($response, 'errors/404.phtml');
    } else {
        $response = $app->getResponseFactory()->createResponse();
        $response->withStatus(500);
        return $this->get('renderer')->render($response, 'errors/500.phtml');
    }
};

$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

$container->set('renderer', function () use ($container) {
    $phpVew = new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
    $phpVew->setLayout('layout.phtml');
    $phpVew->addAttribute('routeName', $container->get('routeName'));
    return $phpVew;
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$container->set('router', function () use ($app) {
    return $app->getRouteCollector()->getRouteParser();
});

$container->set('pdo', function () {
    $databaseUrl = parse_url((string) getenv('DATABASE_URL'));

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

$container->set('getUrllData', function () {
    return function (\PDO $pdo, string $field, string|int $value) {
        $stmt = $pdo->prepare("SELECT * FROM urls WHERE {$field} = ?");
        $stmt->execute([$value]);
        return $stmt->fetch();
    };
});

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
    $urlData = $this->call($this->get('getUrllData'), [$pdo, 'id', $id]);

    if (!$urlData) {
        throw new HttpNotFoundException($request);
    }

    $stmt = $pdo->prepare('SELECT * FROM url_checks WHERE url_id = ? ORDER BY id DESC');
    $stmt->execute([$id]);
    $urlChecksData = $stmt->fetchAll();

    $messages = $this->get('flash')->getMessages();
    $params = ['flash' => $messages, 'urlData' => $urlData, 'urlChecksData' => $urlChecksData];
    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('showUrl');

$app->post('/urls', function ($request, $response) {
    $urlData = $request->getParsedBodyParam('url');

    $validator = new Validator(['name' => $urlData['name']]);
    $validator->rule('required', 'name')->message('URL не должен быть пустым');
    $validator->rule('url', 'name')->message('Некорректный URL');
    $validator->rule('lengthMax', 'name', 255)->message('URL превышает 255 символов');

    if ($validator->validate()) {
        $parsedURL = parse_url($urlData['name']);
        $normilizedUrl = sprintf('%s://%s', $parsedURL['scheme'], $parsedURL['host']);

        $pdo = $this->get('pdo');
        $existedUrl = $this->call($this->get('getUrllData'), [$pdo, 'name', $normilizedUrl]);

        if (!$existedUrl) {
            $stmt = $pdo->prepare('INSERT INTO urls (name, created_at) VALUES (?, ?)');
            $stmt->execute([$normilizedUrl, Carbon::now()]);
            $id = $pdo->lastInsertId();
            $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
        } else {
            $id = $existedUrl['id'];
            $this->get('flash')->addMessage('success', 'Страница уже существует');
        }

        $url = $this->get('router')->urlFor('showUrl', ['id' => $id]);
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

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) {
    $id = $args['url_id'];
    $pdo = $this->get('pdo');
    $urlData = $this->call($this->get('getUrllData'), [$pdo, 'id', $id]);
    $url = $this->get('router')->urlFor('showUrl', ['id' => $id]);

    if (!$urlData) {
        throw new HttpNotFoundException($request);
    }

    try {
        $requestOptions = [
            'allow_redirects' => false,
            'connect_timeout' => 10,
            'timeout' => 10
        ];
        $client = new Client($requestOptions);
        $result = $client->get($urlData['name']);
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('danger', 'Произошла ошибка при проверке, не удалось подключиться');
        return $response->withRedirect($url);
    } catch (ClientException $e) {
        $this->get('flash')->addMessage('warning', 'Проверка была выполнена успешно, но сервер ответил с ошибкой');
        return $response->withRedirect($url);
    } catch (ServerException $e) {
        $this->get('flash')->addMessage('warning', 'Проверка была выполнена успешно, но сервер ответил с ошибкой');
        return $response->withRedirect($url);
    }

    $statusCode = $result->getStatusCode();
    $html = (string) $result->getBody();

    $document = new Document();

    if ($html !== '') {
        $document->loadHtml($html);
    }

    $description = $document->first('meta[name=description]::attr(content)');
    $h1 = optional($document->first('h1'))->innerHtml();
    $title = optional($document->first('title'))->innerHtml();

    $sql = 'INSERT 
            INTO
                url_checks
                (url_id, status_code, h1, title, description, created_at) 
            VALUES
                (?, ?, ?, ?, ?, ?)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id, $statusCode, $h1, $title, $description, Carbon::now()]);

    $this->get('flash')->addMessage('success', 'Страница успешно проверена');

    return $response->withRedirect($url);
});

$app->run();
