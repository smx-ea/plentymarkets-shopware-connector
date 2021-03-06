<?php

namespace PlentyConnector\Connector\TransferObject\Product\Badge;

use PlentyConnector\Connector\ValueObject\AbstractValueObject;
use ReflectionClass;

/**
 * Class Barcode
 */
class Badge extends AbstractValueObject
{
    const TYPE_HIGHLIGHT = 'highlight';

    /**
     * @var string
     */
    private $type = self::TYPE_HIGHLIGHT;

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return array
     */
    public function getTypes()
    {
        $reflection = new ReflectionClass(__CLASS__);

        return $reflection->getConstants();
    }

    /**
     * @param string $type
     */
    public function setType($type)
    {
        $this->type = $type;
    }
}
