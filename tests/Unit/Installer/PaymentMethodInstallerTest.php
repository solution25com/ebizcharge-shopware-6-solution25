<?php declare(strict_types=1);

namespace EbizChargeShopware\Tests\Unit\Installer;

use EbizChargeShopware\Installer\PaymentMethodInstaller;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

final class PaymentMethodInstallerTest extends TestCase
{
    public function testCreatesAndUpdatesPaymentMethodWithAfterOrderEnabled(): void
    {
        $repository = new class extends EntityRepository {
            public array $existingIds = [];
            public array $created = [];
            public array $updated = [];

            public function create(array $data, object $context): void
            {
                $this->created[] = $data;
                foreach ($data as $row) {
                    $technicalName = (string) ($row['technicalName'] ?? '');
                    if ($technicalName !== '') {
                        $this->existingIds[$technicalName] = $technicalName . '-id';
                    }
                }
            }

            public function update(array $data, object $context): void
            {
                $this->updated[] = $data;
            }

            public function searchIds(object $criteria, object $context): object
            {
                $technicalName = $this->technicalNameFromCriteria($criteria);

                return new class($this->existingIds[$technicalName] ?? null) {
                    public function __construct(private ?string $existingId)
                    {
                    }

                    public function firstId(): ?string
                    {
                        return $this->existingId;
                    }
                };
            }

            private function technicalNameFromCriteria(object $criteria): string
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
        $context = new Context();

        $installer->ensurePaymentMethod('plugin-id', $context, false);
        self::assertTrue($repository->created[0][0]['afterOrderEnabled']);
        self::assertSame('ebizcharge_credit_card', $repository->created[0][0]['technicalName']);
        self::assertSame('ebizcharge_ach', $repository->created[1][0]['technicalName']);

        $installer->ensurePaymentMethod('plugin-id', $context, true);
        self::assertTrue($repository->updated[0][0]['afterOrderEnabled']);
        self::assertTrue($repository->updated[1][0]['afterOrderEnabled']);
    }
}
