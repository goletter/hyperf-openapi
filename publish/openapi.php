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
     * 要生成的版本；空数组则自动扫描 Controller 下 V1/V2/... 目录。
     * 顺序建议新→旧，与 BaseDispatcherController::$versions 一致。
     */
    'versions' => ['v3', 'v2', 'v1'],

    /**
     * folder：Apifox 二级目录名（最终 = {version}/{folder}/{模块名}，如 v1/Api/需求管理）。
     * 模块名取自 Controller 类注释首行，无注释时回退为资源名（AccountDemand）。
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

    // Apifox 前置 URL；需要多环境时再往数组里追加即可
    'servers' => [
        [
            'url' => env('OPENAPI_SERVER_URL', env('APP_URL', 'http://127.0.0.1:' . env('SERVER_PORT', 8501))),
            'description' => env('OPENAPI_SERVER_DESCRIPTION', '开发环境'),
        ],
    ],

    'security_schemes' => [
        'bearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
        ],
    ],

    // 所有接口统一的公共 header 入参：components.parameters 定义一次，每个 operation 以 $ref 引用；
    // required 可按需切换（true 时文档侧必填，运行时由网关/中间件另行校验）。
    'common_headers' => [
        'X-Tenant-Id' => ['description' => '租户ID', 'required' => false, 'type' => 'integer'],
        'X-Company-Id' => ['description' => '公司ID', 'required' => false, 'type' => 'integer'],
        'X-User-Id' => ['description' => '用户ID', 'required' => false, 'type' => 'integer'],
        'Authorization' => ['description' => 'Bearer 访问令牌', 'required' => false, 'type' => 'string'],
        // 分版本文档按当前文件版本写死 enum/default/example（如 v1）；此处仅作未分版本时的兜底描述。
        'X-API-Version' => ['description' => 'API 版本（分版本文档导出时写死为该文件版本）', 'required' => true, 'type' => 'string'],
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
