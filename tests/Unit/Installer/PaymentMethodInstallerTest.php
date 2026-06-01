<?php declare(strict_types=1);

namespace EbizChargeShopware\Tests\Unit\Installer;

use EbizChargeShopware\Installer\PaymentMethodInstaller;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

final class PaymentMethodInstallerTest extends TestCase
{
    public function testCreatesAndUpdatesPaymentMethodWithAfterOrderDisabled(): void
    {
        $repository = new class extends EntityRepository {
            public ?string $existingId = null;
            public array $created = [];
            public array $updated = [];

            public function create(array $data, object $context): void
            {
                $this->created[] = $data;
                $this->existingId = 'payment-method-id';
            }

            public function update(array $data, object $context): void
            {
                $this->updated[] = $data;
            }

            public function searchIds(object $criteria, object $context): object
            {
                return new class($this->existingId) {
                    public function __construct(private ?string $existingId)
                    {
                    }

                    public function firstId(): ?string
                    {
                        return $this->existingId;
                    }
                };
            }
        };

        $installer = new PaymentMethodInstaller($repository);
        $context = new Context();

        $installer->ensurePaymentMethod('plugin-id', $context, false);
        self::assertFalse($repository->created[0][0]['afterOrderEnabled']);

        $installer->ensurePaymentMethod('plugin-id', $context, true);
        self::assertFalse($repository->updated[0][0]['afterOrderEnabled']);
    }
}
