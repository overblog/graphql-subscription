# OverblogGraphQLSubscription

This library allow using GraphQL subscription over [Mercure protocol](https://mercure.rocks/)
with any implementation of [GraphQL PHP](https://github.com/webonyx/graphql-php). It Comes out-of-the-box
with a Symfony Bridge so it can be easily combine with [OverblogGraphQLBundle](https://github.com/overblog/GraphQLBundle)
or [API Platform](https://github.com/api-platform/api-platform) or other Symfony implementation based on GraphQL PHP.

## Installation

```bash
composer req overblog/graphql-subscription
```

### Default builder executor

```php
<?php
use GraphQL\Executor\ExecutionResult;
use GraphQL\GraphQL;

function (
    $schema,
    $source,
    $rootValue = null,
    $context = null,
    $variableValues = null,
    ?string $operationName = null
): ExecutionResult {
    return GraphQL::executeQuery(...func_get_args());
}
```

## CORS preflight headers

This library does not handle natively CORS preflight headers.

## Symfony

### Installation without flex

Add the OverblogGraphQLSubscriptionBundle to your application's kernel:

```php
    public function registerBundles()
    {
        $bundles = [
            // ...
            new Overblog\GraphQLSubscription\Bridge\Symfony\OverblogGraphQLSubscriptionBundle(),
            // ...
        ];
        // ...
    }
```

### Configuration

Symfony Flex generates:

* default configuration in `config/packages/graphql_subscription.yaml`.

    ```yaml
    overblog_graphql_subscription:
        topic_url_pattern: "http://localhost:8000/subscriptions/{channel}/{id}.json"
        mercure_hub:
            url: "https://mercure.roks/hub"
    #       Will use the mercure bundle default publisher
            handler_id: ~
    #       Uncomment to use without mercure bundle
    #        publish:
    #            secret_key: "!mySuperPublisherSecretKey!"
            subscribe:
                secret_key: "!mySuperSubscriberSecretKey!"
    #    Uncomment to modify storage filesystem default path
    #    storage:
    #        path: "%kernel.project_dir%/var/graphql-subscriber"
    #   The graphql query handler
    #   for OverblogGraphQLBundle: "Overblog\\GraphQLBundle\\Request\\Executor::execute"
    #   for API-Plateform: "api_platform.graphql.executor::executeQuery"
        graphql_executor: ~
        schema_builder: ~
    ```

* default routes in `config/routes/graphql_subscription.yaml`

    ```yaml
    overblog_graphql_subscription_endpoint:
        resource: "@OverblogGraphQLSubscriptionBundle/Resources/config/routing/single.yaml"
        prefix: /subscriptions
    #   Only for Symfony >= 4.2
    #    trailing_slash_on_root: false

    # Uncomment to enabled multiple schema
    #overblog_graphql_subscription_multiple_endpoint:
    #  resource: "@OverblogGraphQLSubscriptionBundle/Resources/config/routing/multiple.yaml"
    #  prefix: /subscriptions
    ```

### Handling CORS preflight headers

NelmioCorsBundle is recommended to manage CORS preflight,
[follow instructions](https://github.com/nelmio/NelmioCorsBundle#installation) to install it.

Here a configuration assuming that subscription endpoint is `/subscriptions`:

```yaml
nelmio_cors:
    defaults:
        origin_regex: true
        allow_origin: ['%env(CORS_ALLOW_ORIGIN)%']
        allow_methods: ['GET', 'OPTIONS', 'POST']
        allow_headers: ['Content-Type']
        max_age: 3600
    paths:
        '^/subscriptions': ~
```
