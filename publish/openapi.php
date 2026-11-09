<?php

declare(strict_types=1);

use function Hyperf\Support\env;

/**
 * goletter/hyperf-openapi — 按 API 版本分别生成，可导入 Apifox。
 *
 *   php bin/hyperf.php openapi:generate
 *   php bin/hyperf.php openapi:generate --api-version=v1
 */
return [
    'enable' => (bool) env('OPENAPI_ENABLE', true),

    /**
     * 要生成的版本；空数组则回退 api_dispatcher.versions，再否则自动扫描 Vn 目录。
     * 推荐与 config/autoload/api_dispatcher.php 共用同一份 versions（require 引用）。
     */
    'versions' => ['v3', 'v2', 'v1'],

    /**
     * folder：Apifox 二级目录名（最终目录 = {version}/{folder}，如 v1/Api）。
     */
    'gateways' => [
        [
            'prefix' => '/api',
            'namespace' => 'App\\Controller\\Api',
            'path' => BASE_PATH . '/app/Controller/Api',
            'folder' => 'Api',
        ],
        [
            'prefix' => '/api/admin',
            'namespace' => 'App\\Controller\\Admin',
            'path' => BASE_PATH . '/app/Controller/Admin',
            'folder' => 'Admin',
        ],
    ],

    /** camel | snake | snake_plural(默认, account_demands) | kebab | kebab_plural */
    'resource_case' => env('OPENAPI_RESOURCE_CASE', 'snake_plural'),
    'operation_security' => (bool) env('OPENAPI_OPERATION_SECURITY', true),

    /**
     * 响应信封：
     * - formatted（默认）：对齐 ResponseFormatMiddleware，列表为顶层 total
     * - raw：Resource 原始输出，列表保留 meta
     */
    'response_envelope' => env('OPENAPI_RESPONSE_ENVELOPE', 'formatted'),

    'output' => [
        'dir' => BASE_PATH . '/storage/openapi',
        'json_pattern' => 'openapi.{version}.json',
        'yaml_pattern' => 'openapi.{version}.yaml',
    ],

    'info' => [
        'title' => env('OPENAPI_TITLE', 'Goletter API'),
        'version' => env('OPENAPI_VERSION', '1.0.0'),
        'description' => env('OPENAPI_DESCRIPTION', '按版本自动扫描 Controller，可导入 Apifox'),
    ],

    /**
     * Apifox 前置 URL 来源（description 建议用「开发环境/测试环境/生产环境」）。
     * 导入 OpenAPI 时务必：导入到「创建新模块」+ 勾选「导入 Servers 为环境」。
     * 若仍无前置 URL，再导入同目录生成的 apifox.environments.json（环境管理 → 导入）。
     */
    'servers' => array_values(array_filter([
        [
            'url' => env('OPENAPI_SERVER_URL')
                ?: (env('APP_URL') ?: ('http://127.0.0.1:' . env('SERVER_PORT', 8501))),
            'description' => env('OPENAPI_SERVER_DESCRIPTION', '开发环境'),
        ],
        [
            'url' => env('OPENAPI_SERVER_URL_TEST')
                ?: (env('OPENAPI_SERVER_URL')
                    ?: (env('APP_URL') ?: ('http://127.0.0.1:' . env('SERVER_PORT', 8501)))),
            'description' => env('OPENAPI_SERVER_DESCRIPTION_TEST', '测试环境'),
        ],
        [
            'url' => env('OPENAPI_SERVER_URL_PROD')
                ?: (env('OPENAPI_SERVER_URL')
                    ?: (env('APP_URL') ?: ('http://127.0.0.1:' . env('SERVER_PORT', 8501)))),
            'description' => env('OPENAPI_SERVER_DESCRIPTION_PROD', '生产环境'),
        ],
    ])),

    'security_schemes' => [
        'bearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
        ],
    ],

    'security' => [],

    'route' => [
        'enable' => (bool) env('OPENAPI_ROUTE_ENABLE', true),
        'index_path' => '/openapi.json',
        'json_path' => '/openapi/{version}.json',
        'yaml_path' => '/openapi/{version}.yaml',
        'mode' => env('OPENAPI_ROUTE_MODE', 'generate'),
    ],
];
