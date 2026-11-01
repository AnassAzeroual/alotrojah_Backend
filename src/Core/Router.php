<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];
    private array $groupStack = [];

    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }

    public function addRoute(string $method, string $pattern, mixed $handler, array $middlewares = []): void
    {
        $prefix = '';
        $groupMiddlewares = [];

        foreach ($this->groupStack as $group) {
            if (isset($group['prefix'])) {
                $prefix .= '/' . trim($group['prefix'], '/');
            }
            if (isset($group['middleware'])) {
                $groupMiddlewares = array_merge($groupMiddlewares, (array) $group['middleware']);
            }
        }

        $fullPattern = '/' . trim($prefix . '/' . trim($pattern, '/'), '/');
        if ($fullPattern === '') {
            $fullPattern = '/';
        }

        $allMiddlewares = array_merge($groupMiddlewares, $middlewares);

        // Convert {param} to regex named captures or wildcards
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($matches) use (&$paramNames) {
            $paramNames[] = $matches[1];
            return '([^/]+)';
        }, $fullPattern);

        $regex = '~^' . $regex . '$~';

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $fullPattern,
            'regex' => $regex,
            'paramNames' => $paramNames,
            'handler' => $handler,
            'middlewares' => $allMiddlewares,
        ];
    }

    public function get(string $pattern, mixed $handler, array $middlewares = []): void
    {
        $this->addRoute('GET', $pattern, $handler, $middlewares);
    }

    public function post(string $pattern, mixed $handler, array $middlewares = []): void
    {
        $this->addRoute('POST', $pattern, $handler, $middlewares);
    }

    public function put(string $pattern, mixed $handler, array $middlewares = []): void
    {
        $this->addRoute('PUT', $pattern, $handler, $middlewares);
    }

    public function patch(string $pattern, mixed $handler, array $middlewares = []): void
    {
        $this->addRoute('PATCH', $pattern, $handler, $middlewares);
    }

    public function delete(string $pattern, mixed $handler, array $middlewares = []): void
    {
        $this->addRoute('DELETE', $pattern, $handler, $middlewares);
    }

    public function apiResource(string $name, string $controllerClass, array $options = []): void
    {
        $only = $options['only'] ?? ['index', 'store', 'show', 'update', 'destroy'];
        $singular = $options['param'] ?? rtrim($name, 's');

        if (in_array('index', $only, true)) {
            $this->get($name, [$controllerClass, 'index']);
        }
        if (in_array('store', $only, true)) {
            $this->post($name, [$controllerClass, 'store']);
        }
        if (in_array('show', $only, true)) {
            $this->get("{$name}/{{$singular}}", [$controllerClass, 'show']);
        }
        if (in_array('update', $only, true)) {
            $this->put("{$name}/{{$singular}}", [$controllerClass, 'update']);
            $this->patch("{$name}/{{$singular}}", [$controllerClass, 'update']);
        }
        if (in_array('destroy', $only, true)) {
            $this->delete("{$name}/{{$singular}}", [$controllerClass, 'destroy']);
        }
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        // Handle CORS Pre-flight OPTIONS
        if ($method === 'OPTIONS') {
            return new Response('', 204, [
                'Access-Control-Allow-Origin' => $this->resolveCorsOrigin($request),
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, X-Auth-Token, Content-Type, Accept, X-Requested-With',
                'Access-Control-Allow-Credentials' => 'true',
                'Access-Control-Max-Age' => '86400',
            ]);
        }

        $methodAllowed = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                if ($route['method'] !== $method) {
                    $methodAllowed = true;
                    continue;
                }

                // Extract params
                array_shift($matches); // drop full match
                $params = [];
                foreach ($route['paramNames'] as $index => $name) {
                    $val = isset($matches[$index]) ? urldecode($matches[$index]) : null;
                    $params[$name] = $val;
                    if (!isset($params['id'])) {
                        $params['id'] = $val;
                    }
                }
                $request->setRouteParams($params);

                // Run middlewares
                foreach ($route['middlewares'] as $middleware) {
                    if (is_string($middleware)) {
                        $middlewareInstance = new $middleware();
                        $result = $middlewareInstance->handle($request);
                    } elseif (is_callable($middleware)) {
                        $result = $middleware($request);
                    } else {
                        $result = null;
                    }

                    if ($result instanceof Response) {
                        return $this->attachCors($result, $request);
                    }
                }

                // Execute handler
                $handler = $route['handler'];
                $response = null;

                if (is_callable($handler)) {
                    $response = $handler($request, $params);
                } elseif (is_array($handler) && count($handler) === 2) {
                    [$class, $action] = $handler;
                    $controller = new $class();
                    $response = $controller->$action($request, $params);
                } elseif (is_string($handler) && class_exists($handler)) {
                    // Invokable controller
                    $controller = new $handler();
                    $response = $controller($request, $params);
                }

                if ($response instanceof Response) {
                    return $this->attachCors($response, $request);
                }

                return $this->attachCors(Response::ok($response), $request);
            }
        }

        if ($methodAllowed) {
            return $this->attachCors(Response::fail('Method not allowed.', 405), $request);
        }

        return $this->attachCors(Response::fail('Route not found.', 404), $request);
    }

    private function resolveCorsOrigin(Request $request): string
    {
        $origin = $request->header('origin');
        $allowed = Config::get('frontend_urls', []);
        if ($origin && in_array($origin, (array) $allowed, true)) {
            return $origin;
        }
        return '*';
    }

    private function attachCors(Response $response, Request $request): Response
    {
        $origin = $this->resolveCorsOrigin($request);
        $response->header('Access-Control-Allow-Origin', $origin);
        $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Authorization, X-Auth-Token, Content-Type, Accept, X-Requested-With');
        $response->header('Access-Control-Allow-Credentials', 'true');
        return $response;
    }
}
