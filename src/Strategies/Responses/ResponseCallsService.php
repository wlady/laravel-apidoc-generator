<?php
/**
 * Created by PhpStorm.
 * User: Vladimir Zabara <wlady2001@gmail.com>
 * Date: 11/5/19
 * Time: 3:56 PM
 */
namespace Mpociot\ApiDoc\Strategies\Responses;

use Illuminate\Routing\Route;
use Mpociot\ApiDoc\Strategies\Responses\ResponseCalls;
use Mpociot\ApiDoc\Tools\Traits\ParamHelpers;

class ResponseCallsService extends ResponseCalls
{
    use ParamHelpers;

    public function __invoke(Route $route, \ReflectionClass $controller, \ReflectionMethod $method, array $routeRules, array $context = [])
    {
        if (in_array($route->getName(), [
            'api.check',
            'api.login',
        ])) {
            $routeRules['response_calls']['headers']['X-Client-Id'] = $routeRules['headers']['X-Client-Id'];
            $routeRules['response_calls']['headers']['X-Client-Secret'] = $routeRules['headers']['X-Client-Secret'];
            unset($routeRules['headers']['X-Client-Id'], $routeRules['headers']['X-Client-Secret']);
        } else {
            $routeRules['response_calls']['headers']['Authorization'] = $routeRules['headers']['Authorization'];
            unset($routeRules['headers']['Authorization']);
        }

        return parent::__invoke($route, $controller, $method, $routeRules, $context);
    }
}