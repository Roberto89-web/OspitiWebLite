<?php

namespace App\Routing;

use App\Response;
use App\Exceptions\NotFoundException;

class Router {
    private array $routes = [];

    /**
     * Add a route to the table.
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path Path pattern (can be regex)
     * @param callable $handler Handler function
     */
    public function addRoute(string $method, string $path, callable $handler): void {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler
        ];
    }

    /**
     * Dispatch the current request to a registered route.
     * @param string $method
     * @param string $uri
     * @return mixed
     */
    public function dispatch(string $method, string $uri): mixed {
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] === $method) {
                // Check for regex match
                if (preg_match('#^' . $route['path'] . '$#', $uri, $matches)) {
                    // Remove the first element of matches (the full match)
                    array_shift($matches);
                    return call_user_func_array($route['handler'], $matches);
                }
            }
        }

        throw new NotFoundException();
    }
}
