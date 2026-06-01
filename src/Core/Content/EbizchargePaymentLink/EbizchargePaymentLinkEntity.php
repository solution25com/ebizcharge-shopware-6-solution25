<?php

declare(strict_types=1);

namespace EbizChargeShopware\Core\Content\EbizchargePaymentLink;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;

class EbizchargePaymentLinkEntity extends Entity
{
    protected string $orderTransactionId;

    protected string $orderId;

    protected string $link;

    public function getOrderTransactionId(): string
    {
        return $this->orderTransactionId;
    }

    public function setOrderTransactionId(string $orderTransactionId): void
    {
        $this->orderTransactionId = $orderTransactionId;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getLink(): string
    {
        return $this->link;
    }

    public function setLink(string $link): void
    {
        $this->link = $link;
    }
}
