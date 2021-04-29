<?php
/**
 * Use this class to inject additional Postman data into resulting collection.json file. Handy method to add
 * scripts to Postman collections. Currently there is only one PHPDoc tag is supported.
 *
 * The first part (before colon) is a name of subdirectory where template is placed and the name of
 * element in resulting collection file. The second part (after colon) is the name of template to render.
 *
 * Example. Add the following tag to LoginController::apiLogin() method: @postman(event:getAccessToken)
 *
 * Result will be the new element "event". E.g.:
 *
 * {
 *    name: "Get token",
 *    request: {
 *        url: "http://rompos2.local/api/login",
 *        method: "POST",
 *        header: [
 *            {
 *                key: "Accept",
 *                value: "application/json"
 *            }
 *        ],
 *        body: {
 *            mode: "formdata",
 *            formdata: [
 *                {
 *                    key: "email",
 *                    value: "admin@example.com",
 *                    type: "text",
 *                    enabled: true
 *                },
 *                {
 *                    key: "password",
 *                    value: "password",
 *                    type: "text",
 *                    enabled: true
 *                }
 *            ]
 *        },
 *        description: "Get OAuth token for registered user",
 *        response: [ ]
 *    },
 *    event: [
 *        {
 *            listen: "test",
 *            script: {
 *                id: "0a442a3e-0611-11ea-81a9-4ccc6a66a490",
 *                type: "text/javascript",
 *                exec: [
 *                    "var isOk = tests["Status code is 200"] = (responseCode.code === 200);",
 *                    "if (isOk) {",
 *                    " var jsonData = JSON.parse(responseBody);",
 *                    " postman.setEnvironmentVariable("token", jsonData.access_token);",
 *                    "}"
 *                ]
 *            }
 *        }
 *    ]
 * }
 *
 * You have manually to change GenerateDocumentation::generatePostmanCollection() method to use this class:
 *
 * private function generatePostmanCollection(Collection $routes)
 * {
 *     $writer = new ExtendedCollectionWriter($routes, $this->baseUrl);
 *
 *     return $writer->getCollection();
 * }
 *
 * Created by PhpStorm.
 * User: Vladimir Zabara <wlady2001@gmail.com>
 * Date: 11/13/19
 * Time: 14:02
 */
namespace Mpociot\ApiDoc\Postman;

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Class ExtendedCollectionWriter
 * @package App\Services\ApiDoc\Postman
 */
class ExtendedCollectionWriter
{
    /**
     * @var Collection
     */
    private $routeGroups;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * CollectionWriter constructor.
     *
     * @param Collection $routeGroups
     */
    public function __construct(Collection $routeGroups, $baseUrl)
    {
        $this->routeGroups = $routeGroups;
        $this->baseUrl = $baseUrl;
    }

    public function getCollection()
    {
        try {
            URL::forceRootUrl($this->baseUrl);
            if (Str::startsWith($this->baseUrl, 'https://')) {
                URL::forceScheme('https');
            }
        } catch (\Error $e) {
            echo "Warning: Couldn't force base url as your version of Lumen doesn't have the forceRootUrl method.\n";
            echo "You should probably double check URLs in your generated Postman collection.\n";
        }

        $collection = [
            'variables' => [],
            'info' => [
                'name' => config('apidoc.postman.name') ?: config('app.name').' API',
                '_postman_id' => Uuid::uuid4()->toString(),
                'description' => config('apidoc.postman.description') ?: '',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.0.0/collection.json',
            ],
            'item' => $this->routeGroups->map(function ($routes, $groupName) {
                return [
                    'name' => $groupName,
                    'description' => '',
                    'item' => $routes->map(function ($route) {
                        $mode = $route['methods'][0] === 'PUT' ? 'urlencoded' : 'formdata';

                        $res = [
                            'name' => $route['title'] != '' ? $route['title'] : url($route['uri']),
                            'request' => [
                                'url' => url($route['uri']).(collect($route['queryParameters'])->isEmpty()
                                    ? ''
                                    : ('?'.implode('&', collect($route['queryParameters'])->map(function ($parameter, $key) {
                                        return urlencode($key).'='.urlencode($parameter['value'] ?? '');
                                    })->all()))),
                                'method' => $route['methods'][0],
                                'header' => collect($route['headers'])
                                    ->union([
                                        'Accept' => 'application/json',
                                    ])
                                    ->map(function ($value, $header) {
                                        return [
                                            'key' => $header,
                                            'value' => $value,
                                        ];
                                    })
                                    ->values()->all(),
                                'body' => [
                                    'mode' => $mode,
                                    $mode => collect($route['bodyParameters'])->map(function ($parameter, $key) {
                                        return [
                                            'key' => $key,
                                            'value' => $parameter['value'] ?? '',
                                            'type' => 'text',
                                            'enabled' => true,
                                        ];
                                    })->values()->toArray(),
                                ],
                                'description' => $route['description'],
                                'response' => [],
                            ],
                        ];

                        if (!empty($route['postman'])) {
                            $tag = $route['postman'][0];
                            $data = substr($tag->getContent(), 1, -1);
                            list($element, $fileName) = explode(':', $data);
                            $content = view('apidoc::postman.' . $element . '.' . $fileName, ['uuid' => Uuid::uuid1()->toString()]);
                            if (!empty($content)) {
                                $res[$element] = json_decode(trim($content));
                            }
                        }

                        return $res;
                    })->toArray(),
                ];
            })->values()->toArray(),
        ];

        return json_encode($collection);
    }
}
