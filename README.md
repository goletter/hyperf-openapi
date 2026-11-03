# goletter/hyperf-openapi

自动扫描分发式 Controller，**按 API 版本**生成 OpenAPI 3（无需注解），可直接导入 Apifox。

## 生成

```bash
# 生成全部已有 Controller 的版本
php bin/hyperf.php openapi:generate

# 只生成 v1（不要用 --version，会与框架全局参数冲突）
php bin/hyperf.php openapi:generate --api-version=v1

# 兜底脚本（不经 Hyperf Command）
php bin/openapi-generate.php v1
```

输出示例：

```
storage/openapi/openapi.v1.json
storage/openapi/openapi.v2.json
storage/openapi/openapi.v3.json
storage/openapi/index.json
```

## HTTP

| URL | 说明 |
|-----|------|
| `/openapi.json` | 版本列表索引 |
| `/openapi/v1.json` | v1 规格（Apifox URL 导入） |
| `/openapi/v2.json` | v2 |
| `/openapi/v1.yaml` | YAML |

## 导入 Apifox

每个版本**单独导入**一次：

1. 项目设置 → 导入数据 → OpenAPI/Swagger  
2. 选 `storage/openapi/openapi.v1.json`  
3. **「导入到」选「创建新模块」**（导入到旧模块时经常不带前置 URL）  
4. **勾选「导入 Servers 为环境」**  
5. 若前置 URL 仍空：环境管理 → `…` → 导入 `storage/openapi/apifox.environments.json`  
6. 右上角切换到「开发环境」，确认该模块前置 URL

`servers.description` 建议用 `开发环境` / `测试环境` / `生产环境`（与 Apifox 默认环境名一致）。  
`servers.url` 为绝对地址、无尾斜杠；接口 path 已含 `/api`。  
环境变量：`OPENAPI_SERVER_URL`、`OPENAPI_SERVER_URL_TEST`、`OPENAPI_SERVER_URL_PROD`。

## 配置

`config/autoload/openapi.php`：

```php
'versions' => ['v3', 'v2', 'v1'], // 空=自动扫 Vn 目录

'gateways' => [
    [
        'prefix' => '/api',
        'namespace' => 'App\\Controller\\Api',
        'path' => BASE_PATH . '/app/Controller/Api',
        'folder' => 'Api',   // Apifox 目录：v1/Api
    ],
    [
        'prefix' => '/api/admin',
        'namespace' => 'App\\Controller\\Admin',
        'path' => BASE_PATH . '/app/Controller/Admin',
        'folder' => 'Admin', // Apifox 目录：v1/Admin
    ],
],
```

目录结构（`x-apifox-folder`）：`v1/Api`、`v1/Admin`、`v2/Api`…  
一次导入全量用 `storage/openapi/openapi.json`。

## 扫描约定

| 方法 | 路径 | HTTP |
|------|------|------|
| `index` | `/{prefix}/{resource}` | GET |
| `store` / `create` | 同上 | POST |
| `update` | `/{prefix}/{resource}/{id}` | PUT |
| 其它 | `/{prefix}/{resource}/{action}` | GET；有 FormRequest → POST |

资源名默认：`AccountDemandController` → `account_demands`（`snake_plural`）。

### 注释 / Request / Resource

| 来源 | 用途 |
|------|------|
| Controller 类注释 | Tag 名（如「需求管理」）、模块说明 |
| 方法注释 | `summary`；`@param XxxRequest` / `@return` |
| `Request::rules()` + `attributes()` | 请求体字段、中文说明、必填 |
| 方法里 `XxxResource::class` | 响应 `data` 结构 |
| Resource 空时回落同名 Model `$fillable`/`$casts` | 响应字段 |
| 统一信封 | `success/status/code/message/data`；列表默认顶层 `total`（对齐 `ResponseFormatMiddleware`） |

示例：

```php
/**
 * 需求管理 接口
 */
class AccountDemandController {
    /** 需求添加 */
    public function store(AccountDemandRequest $request): Resource {
        return $this->success($data, AccountDemandResource::class);
    }
}
```
