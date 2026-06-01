<?php declare(strict_types=1);

namespace Psr\Log {
    if (!interface_exists(LoggerInterface::class)) {
        interface LoggerInterface
        {
            public function emergency(string|\Stringable $message, array $context = []): void;
            public function alert(string|\Stringable $message, array $context = []): void;
            public function critical(string|\Stringable $message, array $context = []): void;
            public function error(string|\Stringable $message, array $context = []): void;
            public function warning(string|\Stringable $message, array $context = []): void;
            public function notice(string|\Stringable $message, array $context = []): void;
            public function info(string|\Stringable $message, array $context = []): void;
            public function debug(string|\Stringable $message, array $context = []): void;
            public function log($level, string|\Stringable $message, array $context = []): void;
        }
    }

    if (!class_exists(NullLogger::class)) {
        final class NullLogger implements LoggerInterface
        {
            public function emergency(string|\Stringable $message, array $context = []): void {}
            public function alert(string|\Stringable $message, array $context = []): void {}
            public function critical(string|\Stringable $message, array $context = []): void {}
            public function error(string|\Stringable $message, array $context = []): void {}
            public function warning(string|\Stringable $message, array $context = []): void {}
            public function notice(string|\Stringable $message, array $context = []): void {}
            public function info(string|\Stringable $message, array $context = []): void {}
            public function debug(string|\Stringable $message, array $context = []): void {}
            public function log($level, string|\Stringable $message, array $context = []): void {}
        }
    }
}

namespace Doctrine\DBAL {
    if (!class_exists(Connection::class)) {
        class Connection
        {
            public array $inserts = [];
            public array $updates = [];
            public array $statements = [];
            public array $rows = [];

            public function insert(string $table, array $data): void
            {
                $this->inserts[] = ['table' => $table, 'data' => $data];
                $id = (string) ($data['order_transaction_id'] ?? count($this->rows));
                $this->rows[$table][$id] = $data;
            }

            public function update(string $table, array $data, array $criteria): void
            {
                $this->updates[] = ['table' => $table, 'data' => $data, 'criteria' => $criteria];
                $id = (string) ($criteria['order_transaction_id'] ?? '');
                $existing = $this->rows[$table][$id] ?? [];
                $this->rows[$table][$id] = array_merge($existing, $data);
            }

            public function fetchAssociative(string $query, array $params = []): array|false
            {
                if (str_contains($query, 'FROM ebizcharge_payment_transaction')) {
                    $id = (string) ($params['id'] ?? '');

                    return $this->rows['ebizcharge_payment_transaction'][$id] ?? false;
                }

                return false;
            }

            public function executeStatement(string $sql, array $params = []): void
            {
                $this->statements[] = ['sql' => $sql, 'params' => $params];

                if (str_contains($sql, 'INSERT INTO `ebizcharge_payment_transaction`')) {
                    $id = (string) ($params['order_transaction_id'] ?? '');
                    $existing = $this->rows['ebizcharge_payment_transaction'][$id] ?? null;

                    if ($existing === null) {
                        $this->rows['ebizcharge_payment_transaction'][$id] = $params;

                        return;
                    }

                    $merged = array_merge($existing, $params);
                    $merged['created_at'] = $existing['created_at'] ?? ($params['created_at'] ?? null);
                    $this->rows['ebizcharge_payment_transaction'][$id] = $merged;
                }
            }
        }
    }
}

namespace Symfony\Contracts\HttpClient {
    if (!interface_exists(HttpClientInterface::class)) {
        interface HttpClientInterface
        {
            public function request(string $method, string $url, array $options = []): object;
        }
    }
}

namespace Symfony\Contracts\HttpClient\Exception {
    if (!interface_exists(ExceptionInterface::class)) {
        interface ExceptionInterface extends \Throwable
        {
        }
    }
}

namespace Symfony\Component\HttpFoundation {
    if (!class_exists(ParameterBag::class)) {
        class ParameterBag
        {
            public function __construct(private array $values = [])
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function getString(string $key, string $default = ''): string
            {
                $value = $this->get($key, $default);

                return is_string($value) ? $value : $default;
            }
        }
    }

    if (!class_exists(Request::class)) {
        class Request
        {
            public ParameterBag $query;
            public ParameterBag $request;

            public function __construct(array $query = [], array $request = [])
            {
                $this->query = new ParameterBag($query);
                $this->request = new ParameterBag($request);
            }
        }
    }

    if (!class_exists(RedirectResponse::class)) {
        class RedirectResponse
        {
            public function __construct(public string $targetUrl)
            {
            }
        }
    }

    if (!class_exists(JsonResponse::class)) {
        class JsonResponse
        {
            public function __construct(public mixed $data)
            {
            }
        }
    }
}

namespace Symfony\Component\Routing\Attribute {
    if (!class_exists(Route::class)) {
        #[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
        class Route
        {
            public function __construct(
                public ?string $path = null,
                public ?string $name = null,
                public ?array $methods = null,
                public ?array $defaults = null
            ) {
            }
        }
    }
}

namespace Symfony\Component\Console\Command {
    if (!class_exists(Command::class)) {
        class Command
        {
            public const SUCCESS = 0;
            public const FAILURE = 1;

            public function __construct()
            {
            }

            protected function addOption(string $name, ?string $shortcut = null, ?int $mode = null, string $description = ''): void
            {
            }
        }
    }
}

namespace Symfony\Component\Console\Input {
    if (!interface_exists(InputInterface::class)) {
        interface InputInterface
        {
            public function getOption(string $name): mixed;
        }
    }

    if (!class_exists(InputOption::class)) {
        class InputOption
        {
            public const VALUE_OPTIONAL = 4;
        }
    }
}

namespace Symfony\Component\Console\Output {
    if (!interface_exists(OutputInterface::class)) {
        interface OutputInterface
        {
        }
    }
}

namespace Symfony\Component\Console\Style {
    if (!class_exists(SymfonyStyle::class)) {
        class SymfonyStyle
        {
            public array $lines = [];

            public function __construct(object $input, object $output)
            {
            }

            public function definitionList(array ...$list): void
            {
                $this->lines[] = $list;
            }

            public function success(string $message): void
            {
                $this->lines[] = ['success' => $message];
            }

            public function error(string $message): void
            {
                $this->lines[] = ['error' => $message];
            }
        }
    }
}

namespace Shopware\Core\Framework {
    if (!class_exists(Context::class)) {
        class Context
        {
        }
    }
}

namespace Shopware\Core\Framework\Struct {
    if (!class_exists(Struct::class)) {
        class Struct
        {
        }
    }
}

namespace Shopware\Core\Framework\Plugin {
    use Shopware\Core\Framework\Context;

    if (!class_exists(Context\InstallContext::class)) {
        class BaseContext
        {
            public function __construct(private readonly ?Context $context = null)
            {
            }

            public function getContext(): Context
            {
                return $this->context ?? new Context();
            }
        }

        class_alias(BaseContext::class, Context\InstallContext::class);
        class_alias(BaseContext::class, Context\ActivateContext::class);
        class_alias(BaseContext::class, Context\DeactivateContext::class);
        class_alias(BaseContext::class, Context\UninstallContext::class);
        class_alias(BaseContext::class, Context\UpdateContext::class);
    }
}

namespace Shopware\Core\Framework {
    use Shopware\Core\Framework\Plugin\Context\ActivateContext;
    use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
    use Shopware\Core\Framework\Plugin\Context\InstallContext;
    use Shopware\Core\Framework\Plugin\Context\UninstallContext;
    use Shopware\Core\Framework\Plugin\Context\UpdateContext;

    if (!class_exists(Plugin::class)) {
        class Plugin
        {
            protected ?object $container = null;

            public function install(InstallContext $installContext): void
            {
            }

            public function activate(ActivateContext $activateContext): void
            {
            }

            public function update(UpdateContext $updateContext): void
            {
            }

            public function deactivate(DeactivateContext $deactivateContext): void
            {
            }

            public function uninstall(UninstallContext $uninstallContext): void
            {
            }
        }
    }
}

namespace Shopware\Core\Framework\Plugin\Util {
    if (!class_exists(PluginIdProvider::class)) {
        class PluginIdProvider
        {
            public function getPluginIdByBaseClass(string $class, object $context): string
            {
                return 'plugin-id';
            }
        }
    }
}

namespace Shopware\Core\Framework\DataAbstractionLayer {
    if (!class_exists(EntityRepository::class)) {
        class EntityRepository
        {
            public function create(array $data, object $context): void
            {
            }

            public function update(array $data, object $context): void
            {
            }

            public function search(object $criteria, object $context): object
            {
                return new class {
                    public function first(): mixed
                    {
                        return null;
                    }
                };
            }

            public function searchIds(object $criteria, object $context): object
            {
                return new class {
                    public function firstId(): ?string
                    {
                        return null;
                    }

                    public function getIds(): array
                    {
                        return [];
                    }
                };
            }
        }
    }
}

namespace Shopware\Core\Framework\DataAbstractionLayer\Search {
    if (!class_exists(Criteria::class)) {
        class Criteria
        {
            public function __construct(public array $ids = [])
            {
            }

            public function addAssociation(string $association): self
            {
                return $this;
            }

            public function addFilter(object $filter): self
            {
                return $this;
            }

            public function setLimit(int $limit): self
            {
                return $this;
            }
        }
    }
}

namespace Shopware\Core\Framework\DataAbstractionLayer\Search\Filter {
    if (!class_exists(EqualsFilter::class)) {
        class EqualsFilter
        {
            public function __construct(public string $field, public mixed $value)
            {
            }
        }
    }
}

namespace Shopware\Core\Framework\Validation\DataBag {
    if (!class_exists(RequestDataBag::class)) {
        class RequestDataBag
        {
        }
    }
}

namespace Shopware\Core\System\SalesChannel {
    if (!class_exists(SalesChannelContext::class)) {
        class SalesChannelContext
        {
            public function __construct(private readonly string $salesChannelId = 'sales-channel-id')
            {
            }

            public function getSalesChannelId(): string
            {
                return $this->salesChannelId;
            }
        }
    }
}

namespace Shopware\Core\System\SystemConfig {
    if (!class_exists(SystemConfigService::class)) {
        class SystemConfigService
        {
            private array $values = [];

            public function get(string $key, ?string $salesChannelId = null): mixed
            {
                return $this->values[$this->index($key, $salesChannelId)] ?? null;
            }

            public function set(string $key, mixed $value, ?string $salesChannelId = null): void
            {
                $this->values[$this->index($key, $salesChannelId)] = $value;
            }

            private function index(string $key, ?string $salesChannelId): string
            {
                return ($salesChannelId ?? 'global') . ':' . $key;
            }
        }
    }
}

namespace Shopware\Core\Checkout\Cart {
    if (!class_exists(Cart::class)) {
        class Cart
        {
        }
    }
}

namespace Shopware\Core\Checkout\Payment\Cart\PaymentHandler {
    if (!class_exists(AbstractPaymentHandler::class)) {
        abstract class AbstractPaymentHandler
        {
        }
    }

    if (!class_exists(PaymentHandlerType::class)) {
        class PaymentHandlerType
        {
            public const REFUND = 'refund';
            public const RECURRING = 'recurring';
        }
    }
}

namespace Shopware\Core\Checkout\Payment\Cart {
    if (!class_exists(PaymentTransactionStruct::class)) {
        class PaymentTransactionStruct
        {
            public function __construct(
                private readonly string $orderTransactionId = 'transaction-id',
                private readonly ?string $returnUrl = 'https://shop.example.test/return'
            ) {
            }

            public function getOrderTransactionId(): string
            {
                return $this->orderTransactionId;
            }

            public function getReturnUrl(): ?string
            {
                return $this->returnUrl;
            }
        }
    }
}

namespace Shopware\Core\Checkout\Payment {
    if (!class_exists(PaymentException::class)) {
        class PaymentException extends \RuntimeException
        {
            public static function invalidTransaction(string $transactionId): self
            {
                return new self('Invalid transaction ' . $transactionId);
            }

            public static function invalidOrder(string $orderId): self
            {
                return new self('Invalid order ' . $orderId);
            }

            public static function asyncProcessInterrupted(string $transactionId, string $message): self
            {
                return new self($message . ' [' . $transactionId . ']');
            }

            public static function customerCanceled(string $transactionId, string $message): self
            {
                return new self($message . ' [' . $transactionId . ']');
            }

            public static function asyncFinalizeInterrupted(string $transactionId, string $message): self
            {
                return new self($message . ' [' . $transactionId . ']');
            }
        }
    }
}

namespace Shopware\Core\Checkout\Order\Aggregate\OrderTransaction {
    if (!class_exists(OrderTransactionStates::class)) {
        final class OrderTransactionStates
        {
            public const STATE_OPEN = 'open';
            public const STATE_IN_PROGRESS = 'in_progress';
            public const STATE_PAID = 'paid';
            public const STATE_AUTHORIZED = 'authorized';
            public const STATE_FAILED = 'failed';
            public const STATE_CANCELLED = 'cancelled';
        }
    }

    if (!class_exists(OrderTransactionEntity::class)) {
        class OrderTransactionEntity
        {
            private ?object $stateMachineState = null;

            public function setStateMachineState(?object $stateMachineState): void
            {
                $this->stateMachineState = $stateMachineState;
            }

            public function getStateMachineState(): ?object
            {
                return $this->stateMachineState;
            }
        }
    }

    if (!class_exists(OrderTransactionStateHandler::class)) {
        class OrderTransactionStateHandler
        {
            public array $transitions = [];

            public function authorize(string $id, object $context): void
            {
                $this->transitions[] = ['authorize', $id];
            }

            public function paid(string $id, object $context): void
            {
                $this->transitions[] = ['paid', $id];
            }

            public function fail(string $id, object $context): void
            {
                $this->transitions[] = ['fail', $id];
            }

            public function cancel(string $id, object $context): void
            {
                $this->transitions[] = ['cancel', $id];
            }

            public function process(string $id, object $context): void
            {
                $this->transitions[] = ['process', $id];
            }
        }
    }
}

namespace Shopware\Core\System\StateMachine\Exception {
    if (!class_exists(IllegalTransitionException::class)) {
        class IllegalTransitionException extends \RuntimeException
        {
        }
    }
}

namespace Shopware\Core\Framework\Migration {
    if (!class_exists(MigrationStep::class)) {
        abstract class MigrationStep
        {
            abstract public function getCreationTimestamp(): int;

            abstract public function update(\Doctrine\DBAL\Connection $connection): void;

            abstract public function updateDestructive(\Doctrine\DBAL\Connection $connection): void;
        }
    }
}

namespace {
    function ebizcharge_register_autoload(string $root): void
    {
        static $registered = [];

        if (isset($registered[$root])) {
            return;
        }

        spl_autoload_register(static function (string $class) use ($root): void {
            $prefixes = [
                'EbizChargeShopware\\' => $root . '/src/',
                'EbizChargeShopware\\Tests\\' => $root . '/tests/',
            ];

            foreach ($prefixes as $prefix => $baseDir) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $relative = substr($class, strlen($prefix));
                $path = $baseDir . str_replace('\\', '/', $relative) . '.php';

                if (is_file($path)) {
                    require_once $path;
                }
            }
        });

        $registered[$root] = true;
    }
}
