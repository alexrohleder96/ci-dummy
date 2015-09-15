<?php 

/**
 * Codeburner Framework.
 *
 * @author Alex Rohleder <contato@alexrohleder.com.br>
 * @copyright 2015 Alex Rohleder
 * @license http://opensource.org/licenses/MIT
 */

namespace Codeburner\Router;

use Codeburner\Router\Strategies\ConcreteUriStrategy as DefaultStrategy;
use Codeburner\Router\Mapper as Collection;
use Codeburner\Router\Exceptions\MethodNotAllowedException;
use Codeburner\Router\Exceptions\NotFoundException;
use Exception;

/**
 * An interface that homogenizes all the dispatch strategies.
 *
 * @author Alex Rohleder <contato@alexrohleder.com.br>
 * @since 1.0.0
 */

interface DispatcherStrategyInterface
{

    /**
     * Dispatch the matched route action.
     *
     * @param string|array|closure $action The matched route action.
     * @param array                $params The route parameters.
     *
     * @return mixed The response of request.
     */

    public function dispatch($action, array $params);
}

/**
 * The dispatcher class is responsable to find and execute the callback
 * of the approprieted route for a given HTTP method and URI.
 *
 * @author Alex Rohleder <contato@alexrohleder.com.br>
 * @since 1.0.0
 */

class Dispatcher
{

    /**
     * The action dispatch strategy object.
     *
     * @var StrategyInterface
     */

    protected $strategy;

    /**
     * The route collection.
     *
     * @var Collection
     */

    protected $collection;

    /**
     * Define a basepath to all routes.
     *
     * @var string
     */

    protected $basepath;

    /**
     * Construct the route dispatcher.
     *
     * @param Collection        $collection The collection to save routes.
     * @param string            $basepath   Define a URI prefix that must be excluded on matches.
     * @param StrategyInterface $strategy   The strategy to dispatch matched route action.
     */

    public function __construct(Collection $collection, $basepath = '', StrategyInterface $strategy = null)
    {
        $this->collection = $collection;
        $this->basepath   = (string) $basepath;
        $this->strategy   = $strategy ?: new DefaultStrategy;
    }

    /**
     * Find and dispatch a route based on the request http method and uri.
     *
     * @param string $method The HTTP method of the request, that should be GET, POST, PUT, PATCH or DELETE.
     * @param string $uri    The URi of request.
     *
     * @return mixed The request response
     */

    public function dispatch($method, $uri)
    {
        $method = $this->getHttpMethod($method);
        $uri = $this->getUriPath($uri);

        if ($route = $this->collection->getStaticRoute($method, $uri)) {
            
            return $this->strategy->dispatch(
                $route['action'],
                []
            );

        }

        if ($route = $this->matchDinamicRoute($this->collection->getDinamicRoutes($method, $uri), $uri)) {

            return $this->strategy->dispatch(
                $this->resolveDinamicRouteAction($route['action'], $route['params']),
                $route['params']
            );

        }

        $this->dispatchNotFoundRoute($method, $uri);
    }

    /**
     * Verify if the given http method is valid.
     *
     * @param  int|string $method
     * @throws Exception
     * @return int
     */

    protected function getHttpMethod($method)
    {
        if (in_array($method, Mapper::$methods)) {
            return $method;
        }

        if (in_array(strtolower($method), $methods = array_map('strtolower', array_flip(Mapper::$methods)))) {
            return $method;
        }

        throw new Exception('The HTTP method given to the route dispatcher is not supported or is incorrect.');
    }

    /**
     * Get only the path of a given url or uri.
     *
     * @param string $uri The given URL
     *
     * @throws Exception
     * @return string
     */

    protected function getUriPath($uri)
    {
        $path = parse_url(substr(strstr(';' . $uri, ';' . $this->basepath), strlen(';' . $this->basepath)), PHP_URL_PATH);

        if ($path === false) {
            throw new Exception('Seriously malformed URL passed to route dispatcher.');
        }

        return $path;
    }

    /**
     * Find and return the request dinamic route based on the compiled data and uri.
     *
     * @param array  $routes All the compiled data from dinamic routes.
     * @param string $uri    The URi of request.
     *
     * @return array|false If the request match an array with the action and parameters will be returned
     *                     otherwide a false will.
     */

    protected function matchDinamicRoute($routes, $uri)
    {
        foreach ($routes as $route) {
            if (!preg_match($route['regex'], $uri, $matches)) {
                continue;
            }

            list($routeAction, $routeParams) = $route['map'][count($matches)];

            $params = [];
            $i = 0;

            foreach ($routeParams as $name) {
                $params[$name] = $matches[++$i];
            }

            return ['action' => $routeAction, 'params' => $params];
        }

        return false;
    }

    /**
     * Generate an HTTP error request with method not allowed or not found.
     *
     * @param string $method The HTTP method that must not be checked.
     * @param string $uri    The URi of request.
     *
     * @throws NotFoundException
     * @throws MethodNotAllowedException
     */

    protected function dispatchNotFoundRoute($method, $uri)
    {
        $dm = $dm = [];

        if ($sm = ($this->checkStaticRouteInOtherMethods($method, $uri)) 
                || $dm = ($this->checkDinamicRouteInOtherMethods($method, $uri))) {
            throw new MethodNotAllowedException($method, $uri, array_merge((array) $sm, (array) $dm));
        }

        throw new NotFoundException($method, $uri);
    }

    /**
     * Verify if a static route match in another method than the requested.
     *
     * @param string $jump_method The HTTP method that must not be checked
     * @param string $uri         The URi that must be matched.
     *
     * @return array
     */

    protected function checkStaticRouteInOtherMethods($jump_method, $uri)
    {
        $methods = [];

        foreach (Mapper::$methods as $method) {
            if ($route = $this->collection->getStaticRoute($method, $uri)) {
                $methods[$method] = $route;
            }
        }

        unset($methods[$jump_method]);

        return $methods;
    }

    /**
     * Verify if a dinamic route match in another method than the requested.
     *
     * @param string $jump_method The HTTP method that must not be checked
     * @param string $uri         The URi that must be matched.
     *
     * @return array
     */

    protected function checkDinamicRouteInOtherMethods($jump_method, $uri)
    {
        $methods = [];
        $offset = substr_count($uri, '/') - 1;

        foreach (Mapper::$methods as $method) {
            if ($route = $this->matchDinamicRoute(
                    $this->collection->getDinamicRoutes($method, $offset), $uri)
            ) {
                $methods[$method] = $route;
            }
        }

        unset($methods[$jump_method]);

        return $methods;
    }

    /**
     * Resolve dinamic action, inserting route parameters at requested points.
     *
     * @param string|array|closure $action The route action.
     * @param array                $params The dinamic routes parameters.
     *
     * @return string
     */

    protected function resolveDinamicRouteAction($action, $params)
    {
        if (is_array($action)) {
            foreach ($action as $key => $value) {
                if (is_string($value)) {
                    $action[$key] = str_replace(['{', '}'], '', str_replace(array_keys($params), array_values($params), $value));
                }
            }
        }

        return $action;
    }

    /**
     * Get the getCollection() of routes.
     *
     * @return Collection
     */

    public function getCollection()
    {
        return $this->collection;
    }

    /**
     * Get the current dispatch strategy.
     *
     * @return StrategyInterface
     */

    public function getStrategy()
    {
        return $this->strategy;
    }

    /**
     * Get the actual base path of this dispatch.
     *
     * @return string
     */

    public function getBasePath()
    {
        return $this->basepath;
    }

    /**
     * Set a new basepath, this will be a prefix that must be excluded in every
     * requested URi.
     *
     * @param string $basepath The new basepath
     */
    
    public function setBasePath($basepath)
    {
        $this->basepath = $basepath;
    }

}
