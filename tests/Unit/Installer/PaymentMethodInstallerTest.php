<?php declare(strict_types=1);

namespace EbizChargeShopware\Tests\Unit\Installer;

use EbizChargeShopware\Installer\PaymentMethodInstaller;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Event\NestedEventCollection;

final class PaymentMethodInstallerTest extends TestCase
{
    public function testCreatesAndUpdatesPaymentMethodWithAfterOrderEnabled(): void
    {
        $repository = new class extends EntityRepository {
            public array $existingIds = [];
            public array $created = [];
            public array $updated = [];

            public function __construct()
            {
            }

            public function create(array $data, Context $context): EntityWrittenContainerEvent
            {
                $this->created[] = $data;
                foreach ($data as $row) {
                    $technicalName = (string) ($row['technicalName'] ?? '');
                    if ($technicalName !== '') {
                        $this->existingIds[$technicalName] = $technicalName . '-id';
                    }
                }

                return new EntityWrittenContainerEvent($context, new NestedEventCollection(), []);
            }

            public function update(array $data, Context $context): EntityWrittenContainerEvent
            {
                $this->updated[] = $data;

                return new EntityWrittenContainerEvent($context, new NestedEventCollection(), []);
            }

            public function searchIds(Criteria $criteria, Context $context): IdSearchResult
            {
                $technicalName = $this->technicalNameFromCriteria($criteria);
                $id = $this->existingIds[$technicalName] ?? null;

                return new IdSearchResult(
                    $id === null ? 0 : 1,
                    $id === null ? [] : [$id => ['primaryKey' => $id, 'data' => []]],
                    $criteria,
                    $context
                );
            }

            private function technicalNameFromCriteria(Criteria $criteria): string
            {
                foreach ($criteria->getFilters() as $filter) {
                    if (method_exists($filter, 'getField') && method_exists($filter, 'getValue') && $filter->getField() === 'technicalName') {
                        return (string) $filter->getValue();
                    }
                }

                return '';
            }
        };

        $installer = new PaymentMethodInstaller($repository);
        $context = Context::createDefaultContext();

        $installer->ensurePaymentMethod('plugin-id', $context, false);
        self::assertTrue($repository->created[0][0]['afterOrderEnabled']);
        self::assertSame('ebizcharge_credit_card', $repository->created[0][0]['technicalName']);
        self::assertSame('ebizcharge_ach', $repository->created[1][0]['technicalName']);

        $installer->ensurePaymentMethod('plugin-id', $context, true);
        self::assertTrue($repository->updated[0][0]['afterOrderEnabled']);
        self::assertTrue($repository->updated[1][0]['afterOrderEnabled']);
        self::assertArrayNotHasKey('active', $repository->updated[0][0]);
        self::assertArrayNotHasKey('active', $repository->updated[1][0]);

        $installer->ensurePayByLinkPaymentMethod('plugin-id', $context);
        $installer->ensurePayByLinkPaymentMethod('plugin-id', $context);
        self::assertArrayNotHasKey('active', $repository->updated[2][0]);
    }
}
