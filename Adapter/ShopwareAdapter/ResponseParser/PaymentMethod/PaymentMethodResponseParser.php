<?php

namespace ShopwareAdapter\ResponseParser\PaymentMethod;

use PlentyConnector\Connector\IdentityService\IdentityServiceInterface;
use PlentyConnector\Connector\TransferObject\PaymentMethod\PaymentMethod;
use ShopwareAdapter\ShopwareAdapter;

class PaymentMethodResponseParser implements PaymentMethodResponseParserInterface
{
    /**
     * @var IdentityServiceInterface
     */
    private $identityService;

    public function __construct(IdentityServiceInterface $identityService)
    {
        $this->identityService = $identityService;
    }

    /**
     * {@inheritdoc}
     */
    public function parse(array $entry)
    {
        $identity = $this->identityService->findOneOrCreate(
            (string) $entry['id'],
            ShopwareAdapter::NAME,
            PaymentMethod::TYPE
        );

        return PaymentMethod::fromArray([
            'identifier' => $identity->getObjectIdentifier(),
            'name' => $entry['name'],
        ]);
    }
}
