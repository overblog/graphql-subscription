<?php

declare(strict_types=1);

namespace Overblog\GraphQLSubscription\Bridge\Symfony\ExecutorAdapter;

use GraphQL\Executor\ExecutionResult;
use Overblog\GraphQLBundle\Request\Executor;

final class GraphQLBundleExecutorAdapter
{
    private $executor;

    public function __construct(Executor $executor)
    {
        $this->executor = $executor;
    }

    public function __invoke(
        ?string $schemaName,
        string $query,
        $rootValue = null,
        $context = null,
        ?array $variables = null,
        ?string $operationName = null
    ): ExecutionResult {
        return $this->executor->execute($schemaName, \compact('query', 'variables', 'operationName'), $rootValue);
    }
}
