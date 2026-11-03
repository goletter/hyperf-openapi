<?php

declare(strict_types=1);

namespace Goletter\OpenApi;

use Goletter\OpenApi\Command\OpenApiGenerateCommand;
use Goletter\OpenApi\Generator\OpenApiGenerator;
use Goletter\OpenApi\Listener\RegisterOpenApiRouteListener;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                OpenApiGenerator::class => OpenApiGenerator::class,
            ],
            'commands' => [
                OpenApiGenerateCommand::class,
            ],
            'listeners' => [
                RegisterOpenApiRouteListener::class,
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'OpenAPI 生成与 Apifox 导入相关配置。',
                    'source' => __DIR__ . '/../publish/openapi.php',
                    'destination' => BASE_PATH . '/config/autoload/openapi.php',
                ],
            ],
        ];
    }
}
