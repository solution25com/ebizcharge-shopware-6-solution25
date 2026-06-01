<?php declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$root = realpath($argv[1] ?? dirname(__DIR__));
if ($root === false) {
    fwrite(STDERR, "Could not resolve plugin root.\n");
    exit(1);
}

ebizcharge_register_autoload($root);

$mainServicesXml = $root . '/src/Resources/config/services.xml';
if (!is_file($mainServicesXml)) {
    fwrite(STDERR, "Missing services.xml at {$mainServicesXml}\n");
    exit(1);
}

$coreServiceTypes = [
    'payment_method.repository' => Shopware\Core\Framework\DataAbstractionLayer\EntityRepository::class,
    'order_transaction.repository' => Shopware\Core\Framework\DataAbstractionLayer\EntityRepository::class,
    'logger' => Psr\Log\LoggerInterface::class,
    'http_client' => Symfony\Contracts\HttpClient\HttpClientInterface::class,
    'Doctrine\DBAL\Connection' => Doctrine\DBAL\Connection::class,
    'Shopware\Core\System\SystemConfig\SystemConfigService' => Shopware\Core\System\SystemConfig\SystemConfigService::class,
    'Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler' => Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler::class,
    'Shopware\Core\Framework\Plugin\Util\PluginIdProvider' => Shopware\Core\Framework\Plugin\Util\PluginIdProvider::class,
];

$graph = [
    'services' => [],
    'aliases' => [],
    'files' => [],
];
$violations = [];

loadServiceFile($mainServicesXml, $graph, $violations);

$packageConfigDir = $root . '/src/Resources/config/packages';
if (is_dir($packageConfigDir)) {
    $packageEntries = array_values(array_diff(scandir($packageConfigDir) ?: [], ['.', '..']));
    if ($packageEntries !== []) {
        $violations[] = 'src/Resources/config/packages contains runtime files, but v0.0.6 does not explicitly load package config during plugin boot.';
    }
}

foreach ($graph['files'] as $file) {
    $content = file_get_contents($file);
    if ($content === false) {
        $violations[] = 'Could not read parsed service file ' . $file;
        continue;
    }

    if (str_contains($content, 'monolog.logger.')) {
        $violations[] = sprintf('%s references a plugin-specific monolog channel; v0.0.6 must use the default logger service only.', $file);
    }

    if (str_contains($content, '<prototype ')) {
        $violations[] = sprintf('%s still uses <prototype ...>; runtime services must remain explicitly wired.', $file);
    }
}

foreach ($graph['services'] as $id => $definition) {
    if (isset($graph['aliases'][$id])) {
        continue;
    }

    $class = resolveServiceClass($id, $graph, $coreServiceTypes);
    if ($class === null) {
        $violations[] = sprintf('Service "%s" does not resolve to a class.', $id);
        continue;
    }

    if (!class_exists($class)) {
        $violations[] = sprintf('Service "%s" resolves to missing class "%s".', $id, $class);
        continue;
    }

    $reflection = new ReflectionClass($class);
    $constructor = $reflection->getConstructor();
    $arguments = $definition['arguments'];

    if ($constructor === null) {
        if ($arguments !== []) {
            $violations[] = sprintf('Service "%s" declares constructor arguments, but %s has no constructor.', $id, $class);
        }

        continue;
    }

    $parameters = $constructor->getParameters();
    $requiredCount = count(array_filter($parameters, static fn (ReflectionParameter $parameter): bool => !$parameter->isOptional()));

    if (count($arguments) < $requiredCount) {
        $violations[] = sprintf(
            'Service "%s" provides %d constructor arguments, but %s requires at least %d.',
            $id,
            count($arguments),
            $class,
            $requiredCount
        );
    }

    if (count($arguments) > count($parameters)) {
        $violations[] = sprintf(
            'Service "%s" provides %d constructor arguments, but %s only accepts %d.',
            $id,
            count($arguments),
            $class,
            count($parameters)
        );
    }

    foreach ($parameters as $index => $parameter) {
        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;

        if (
            $typeName !== null
            && str_starts_with($typeName, 'EbizChargeShopware\\')
            && interface_exists($typeName)
            && !serviceIdExists($typeName, $graph, $coreServiceTypes)
        ) {
            $violations[] = sprintf('Plugin interface "%s" is constructor-injected in %s but has no explicit service id or alias.', $typeName, $class);
        }

        if (!array_key_exists($index, $arguments)) {
            continue;
        }

        $referenceId = $arguments[$index];
        if (!serviceIdExists($referenceId, $graph, $coreServiceTypes)) {
            $violations[] = sprintf('Service "%s" references non-existent service id "%s".', $id, $referenceId);
            continue;
        }

        if ($typeName === null) {
            continue;
        }

        $resolvedReferenceClass = resolveServiceClass($referenceId, $graph, $coreServiceTypes);
        if ($resolvedReferenceClass === null) {
            continue;
        }

        if (!is_a($resolvedReferenceClass, $typeName, true)) {
            $violations[] = sprintf(
                'Service "%s" constructor parameter $%s expects %s, but "%s" resolves to %s.',
                $id,
                $parameter->getName(),
                $typeName,
                $referenceId,
                $resolvedReferenceClass
            );
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, implode(PHP_EOL, array_unique($violations)) . PHP_EOL);
    exit(1);
}

echo "Service graph validation passed for {$root}\n";

function loadServiceFile(string $file, array &$graph, array &$violations): void
{
    $realPath = realpath($file);
    if ($realPath === false) {
        $violations[] = 'Could not resolve service file ' . $file;

        return;
    }

    if (isset($graph['files'][$realPath])) {
        return;
    }

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    if (!$dom->load($realPath)) {
        $violations[] = 'Failed to parse XML: ' . $realPath;

        return;
    }

    $graph['files'][$realPath] = $realPath;

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('s', 'http://symfony.com/schema/dic/services');

    foreach ($xpath->query('/s:container/s:imports/s:import') ?: [] as $importNode) {
        if (!$importNode instanceof DOMElement) {
            continue;
        }

        $resource = $importNode->getAttribute('resource');
        if ($resource === '') {
            $violations[] = sprintf('Import in %s is missing a resource attribute.', $realPath);
            continue;
        }

        loadServiceFile(dirname($realPath) . '/' . $resource, $graph, $violations);
    }

    foreach ($xpath->query('/s:container/s:services/*') ?: [] as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        if ($node->localName === 'prototype') {
            $violations[] = sprintf('%s contains a <prototype ...> entry; v0.0.6 requires explicit services only.', $realPath);
            continue;
        }

        if ($node->localName !== 'service') {
            continue;
        }

        if ($node->hasAttribute('resource') || $node->hasAttribute('exclude')) {
            $violations[] = sprintf('%s still contains invalid or bulk service-discovery attributes on <service>.', $realPath);
        }

        $id = $node->getAttribute('id');
        if ($id === '') {
            $violations[] = sprintf('%s contains a <service> entry without an id.', $realPath);
            continue;
        }

        $alias = $node->getAttribute('alias');
        if ($alias !== '') {
            $graph['aliases'][$id] = $alias;
        }

        $graph['services'][$id] = [
            'class' => $node->getAttribute('class') ?: null,
            'arguments' => collectServiceArguments($node),
            'file' => $realPath,
        ];
    }
}

function collectServiceArguments(DOMElement $serviceNode): array
{
    $arguments = [];

    foreach ($serviceNode->childNodes as $childNode) {
        if (!$childNode instanceof DOMElement || $childNode->localName !== 'argument') {
            continue;
        }

        if ($childNode->getAttribute('type') !== 'service') {
            continue;
        }

        $id = $childNode->getAttribute('id');
        if ($id !== '') {
            $arguments[] = $id;
        }
    }

    return $arguments;
}

function serviceIdExists(string $id, array $graph, array $coreServiceTypes): bool
{
    return isset($graph['services'][$id]) || isset($graph['aliases'][$id]) || isset($coreServiceTypes[$id]);
}

function resolveServiceClass(string $id, array $graph, array $coreServiceTypes, array $visited = []): ?string
{
    if (isset($visited[$id])) {
        return null;
    }

    if (isset($coreServiceTypes[$id])) {
        return $coreServiceTypes[$id];
    }

    if (isset($graph['aliases'][$id])) {
        $visited[$id] = true;

        return resolveServiceClass($graph['aliases'][$id], $graph, $coreServiceTypes, $visited);
    }

    if (!isset($graph['services'][$id])) {
        return null;
    }

    $class = $graph['services'][$id]['class'];
    if (is_string($class) && $class !== '') {
        return $class;
    }

    return str_contains($id, '\\') ? $id : null;
}
