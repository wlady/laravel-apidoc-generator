<?php
/**
 * Created by PhpStorm.
 * User: Vladimir Zabara <wlady2001@gmail.com>
 * Date: 11/8/19
 * Time: 11:01 AM
 */
namespace Mpociot\ApiDoc\Strategies\Metadata;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Mpociot\ApiDoc\Strategies\Metadata\GetFromDocBlocks;
use Mpociot\ApiDoc\Tools\RouteDocBlocker;
use Mpociot\Reflection\DocBlock;
use ReflectionClass;
use ReflectionMethod;

class DocBlocksService extends GetFromDocBlocks
{
    public function __invoke(
        Route $route,
        ReflectionClass $controller,
        ReflectionMethod $method,
        array $routeRules,
        array $context = []
    ) {
        $docBlocks = RouteDocBlocker::getDocBlocksFromRoute($route);
        /** @var DocBlock $methodDocBlock */
        $methodDocBlock = $docBlocks['method'];

        list($routeGroupName, $routeGroupDescription, $routeTitle) = $this->getRouteGroupDescriptionAndTitle($methodDocBlock, $docBlocks['class']);
        list($moduleName, $moduleDescription) = $this->getModuleNameIfExists($docBlocks['class']);

        return [
            'groupName'         => $routeGroupName,
            'groupDescription'  => $routeGroupDescription,
            'moduleName'        => $moduleName,
            'moduleDescription' => $moduleDescription,
            'roles'             => $this->getRoles($route),
            'postman'           => $methodDocBlock->getTagsByName('postman'),
            'title'             => $routeTitle ?: $methodDocBlock->getShortDescription(),
            'description'       => $methodDocBlock->getLongDescription()->getContents(),
            'authenticated'     => $this->getAuthStatusFromDocBlock($methodDocBlock->getTags()),
            'deprecated'        => $this->getDeprecatedStatus($methodDocBlock->getTags()),
        ];
    }

    protected function getModuleNameIfExists(DocBlock $controllerDocBlock)
    {
        $moduleName        = '';
        $moduleDescription = '';
        if ( ! empty($controllerDocBlock->getTags())) {
            foreach ($controllerDocBlock->getTags() as $tag) {
                if ($tag->getName() === 'module') {
                    $moduleDescriptionParts = explode("\n", trim($tag->getContent()));
                    $moduleName             = array_shift($moduleDescriptionParts);
                    $moduleDescription      = trim(implode("\n", $moduleDescriptionParts));
                }
            }
        }

        return [
            $moduleName,
            $moduleDescription,
        ];
    }

    /**
     * Collect roles allowed to access this route
     *
     * @param Route $route
     *
     * @return array
     */
    protected function getRoles($route)
    {
        $roles      = [];
        $scope      = '';
        $permission = '';
        foreach ($route->middleware() as $middleware) {
            if (substr($middleware, 0, 5) == 'scope') {
                $scope = substr($middleware, 6);
            } else if (substr($middleware, 0, 3) == 'can') {
                $parts = explode(',', $middleware);
                if ( ! empty($parts[0])) {
                    $permission = substr($parts[0], 4);
                } else {
                    $permission = substr($middleware, 4);
                }
            } else {
                $permission = 'view';
            }
        }
        if ($scope && $permission) {
            $perms = DB::table('model_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
                ->join('model_has_roles', 'model_has_roles.model_id', '=', 'model_has_permissions.model_id')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->select('model_has_permissions.*', 'permissions.name', 'roles.id', 'roles.name AS role')
                ->whereIn('model_has_permissions.permission_id', function ($query) use ($scope, $permission) {
                    return $query->select('id')->from('permissions')->whereRaw("name = '{$scope}-$permission' AND `guard_name` = 'api'");
                })
                ->where('roles.guard_name', 'api')
                ->orderBy('roles.id')
                ->groupBy('roles.name')
                ->get();
            foreach ($perms as $perm) {
                $roles[] = ucwords($perm->role);
            }
        }

        return $roles;
    }

    /**
     * @param array $tags Tags in the method doc block
     *
     * @return string
     */
    protected function getDeprecatedStatus(array $tags)
    {
        $deprecatedTag = collect($tags)
            ->first(function ($tag) {
                return $tag instanceof Tag && strtolower($tag->getName()) === 'deprecated';
            });

        return (bool) $deprecatedTag;
    }
}