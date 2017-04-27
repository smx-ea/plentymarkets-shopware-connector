<?php

namespace PlentymarketsAdapter\ResponseParser\Product;

use DateTimeImmutable;
use DateTimeZone;
use PlentyConnector\Connector\IdentityService\Exception\NotFoundException;
use PlentyConnector\Connector\IdentityService\IdentityServiceInterface;
use PlentyConnector\Connector\TransferObject\Category\Category;
use PlentyConnector\Connector\TransferObject\CustomerGroup\CustomerGroup;
use PlentyConnector\Connector\TransferObject\Language\Language;
use PlentyConnector\Connector\TransferObject\Manufacturer\Manufacturer;
use PlentyConnector\Connector\TransferObject\Product\Barcode\Barcode;
use PlentyConnector\Connector\TransferObject\Product\Image\Image;
use PlentyConnector\Connector\TransferObject\Product\LinkedProduct\LinkedProduct;
use PlentyConnector\Connector\TransferObject\Product\Price\Price;
use PlentyConnector\Connector\TransferObject\Product\Product;
use PlentyConnector\Connector\TransferObject\Product\Property\Property;
use PlentyConnector\Connector\TransferObject\Product\Property\Value\Value;
use PlentyConnector\Connector\TransferObject\Product\Variation\Variation;
use PlentyConnector\Connector\TransferObject\ShippingProfile\ShippingProfile;
use PlentyConnector\Connector\TransferObject\Shop\Shop;
use PlentyConnector\Connector\TransferObject\TransferObjectInterface;
use PlentyConnector\Connector\TransferObject\Unit\Unit;
use PlentyConnector\Connector\TransferObject\VatRate\VatRate;
use PlentyConnector\Connector\ValueObject\Attribute\Attribute;
use PlentyConnector\Connector\ValueObject\Translation\Translation;
use PlentymarketsAdapter\Client\ClientInterface;
use PlentymarketsAdapter\Helper\MediaCategoryHelper;
use PlentymarketsAdapter\PlentymarketsAdapter;
use PlentymarketsAdapter\ResponseParser\Media\MediaResponseParserInterface;
use Psr\Log\LoggerInterface;

/**
 * Class ProductResponseParser.
 */
class ProductResponseParser implements ProductResponseParserInterface
{
    /**
     * @var IdentityServiceInterface
     */
    private $identityService;

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var \PlentymarketsAdapter\ReadApi\Webstore
     */
    private $webstoresApi;
    private $itemsSalesPricesApi;
    private $itemsItemShippingProfilesApi;
    private $itemsAccountsContacsClasses;
    private $itemsImagesApi;
    private $itemsVariationsVariationPropertiesApi;
    private $itemsPropertiesSelectionsApi;
    private $availabilitiesApi;
    private $itemAttributesApi;
    private $itemAttributesValuesApi;
    private $itemsPropertiesNamesApi;

    /**
     * ProductResponseParser constructor.
     *
     * @param IdentityServiceInterface $identityService
     * @param ClientInterface          $client
     * @param LoggerInterface          $logger
     */
    public function __construct(
        IdentityServiceInterface $identityService,
        ClientInterface $client,
        LoggerInterface $logger
    ) {
        $this->identityService = $identityService;
        $this->client = $client;
        $this->logger = $logger;

        //TODO: inject when refactoring this class
        $this->webstoresApi = new \PlentymarketsAdapter\ReadApi\Webstore($client);
        $this->itemsSalesPricesApi = new \PlentymarketsAdapter\ReadApi\Item\SalesPrice($client);
        $this->itemsAccountsContacsClasses = new \PlentymarketsAdapter\ReadApi\Account\ContactClass($client);
        $this->itemsItemShippingProfilesApi = new \PlentymarketsAdapter\ReadApi\Item\ShippingProfile($client);
        $this->itemsImagesApi = new \PlentymarketsAdapter\ReadApi\Item\Image($client);
        $this->itemsVariationsVariationPropertiesApi = new \PlentymarketsAdapter\ReadApi\Item\Variation\Property($client);
        $this->itemsPropertiesSelectionsApi = new\PlentymarketsAdapter\ReadApi\Item\Property\Selection($client);
        $this->availabilitiesApi = new \PlentymarketsAdapter\ReadApi\Availability($client);
        $this->itemsPropertiesNamesApi = new \PlentymarketsAdapter\ReadApi\Item\Property\Name($client);
        $this->itemAttributesApi = new \PlentymarketsAdapter\ReadApi\Item\Attribute($client);
        $this->itemAttributesValuesApi = new \PlentymarketsAdapter\ReadApi\Item\Attribute\Value($client);
    }

    /**
     * @param array $product
     *
     * @return TransferObjectInterface[]
     */
    public function parse(array $product)
    {
        static $webstores;

        if (null === $webstores) {
            $webstores = $this->webstoresApi->findAll();
        }

        $result = [];

        $mainVariation = $this->getMainVariation($product['variations']);

        $identity = $this->identityService->findOneOrCreate(
            (string) $product['id'],
            PlentymarketsAdapter::NAME,
            Product::TYPE
        );

        $hasStockLimitation = array_filter($product['variations'], function (array $variation) {
            return (bool) $variation['stockLimitation'];
        });

        $shopIdentifiers = $this->getShopIdentifiers($mainVariation);

        if (empty($shopIdentifiers)) {
            return [];
        }

        $variations = $this->getVariations($product['texts'], $product['variations'], $result);

        /**
         * @var Product $object
         */
        $object = Product::fromArray([
            'identifier' => $identity->getObjectIdentifier(),
            'name' => (string) $product['texts'][0]['name1'],
            'number' => (string) $mainVariation['number'],
            'active' => $this->getActive($variations),
            'shopIdentifiers' => $shopIdentifiers,
            'manufacturerIdentifier' => $this->getManufacturerIdentifier($product),
            'categoryIdentifiers' => $this->getCategories($mainVariation),
            'defaultCategoryIdentifiers' => $this->getDafaultCategories($mainVariation),
            'shippingProfileIdentifiers' => $this->getShippingProfiles($product),
            'images' => $this->getImages($product, $product['texts'], $result),
            'variations' => $variations,
            'vatRateIdentifier' => $this->getVatRateIdentifier($mainVariation),
            'limitedStock' => (bool) $hasStockLimitation,
            'description' => (string) $product['texts'][0]['shortDescription'],
            'longDescription' => (string) $product['texts'][0]['description'],
            'technicalDescription' => (string) $product['texts'][0]['technicalData'],
            'metaTitle' => (string) $product['texts'][0]['name1'],
            'metaDescription' => (string) $product['texts'][0]['metaDescription'],
            'metaKeywords' => (string) $product['texts'][0]['keywords'],
            'metaRobots' => 'INDEX, FOLLOW',
            'linkedProducts' => $this->getLinkedProducts($product),
            'documents' => [],
            'properties' => $this->getProperties($mainVariation),
            'translations' => $this->getProductTranslations($product['texts']),
            'availableFrom' => $this->getAvailableFrom($mainVariation),
            'availableTo' => $this->getAvailableTo($mainVariation),
            'attributes' => $this->getAttributes($product),
        ]);

        $result[] = $object;

        return $result;
    }

    /**
     * @param array $variations
     *
     * @return array
     */
    private function getMainVariation(array $variations)
    {
        $mainVariation = array_filter($variations, function ($varation) {
            return $varation['isMain'] === true;
        });

        if (empty($mainVariation)) {
            throw new \InvalidArgumentException('product without main variaton');
        }

        return array_shift($mainVariation);
    }

    /**
     * Returns the matching price configurations.
     *
     * @return array
     */
    private function getPriceConfigurations()
    {
        static $priceConfigurations;

        if (null === $priceConfigurations) {
            $priceConfigurations = $this->itemsSalesPricesApi->findAll();

            $shopIdentities = $this->identityService->findBy([
                'adapterName' => PlentymarketsAdapter::NAME,
                'objectType' => Shop::TYPE,
            ]);

            if (empty($shopIdentities)) {
                return $priceConfigurations;
            }

            $priceConfigurations = array_filter($priceConfigurations,
                function ($priceConfiguration) use ($shopIdentities) {
                    foreach ($shopIdentities as $identity) {
                        foreach ($priceConfiguration['clients'] as $client) {
                            if ($client['plentyId'] === -1 || $identity->getAdapterIdentifier() === (string) $client['plentyId']) {
                                return true;
                            }
                        }
                    }

                    return false;
                });

            if (empty($priceConfigurations)) {
                $this->logger->notice('no valid price configuration found');
            }
        }

        return $priceConfigurations;
    }

    /**
     * TODO: implement new logic for shop based prices
     *
     * @param array $variation
     *
     * @return array
     */
    private function getPrices(array $variation)
    {
        static $customerGroups;

        if (null === $customerGroups) {
            $customerGroups = array_keys($this->itemsAccountsContacsClasses->findAll());
        }

        $priceConfigurations = $this->getPriceConfigurations();

        $temporaryPrices = [];
        foreach ($variation['variationSalesPrices'] as $price) {
            $priceConfiguration = array_filter($priceConfigurations, function ($configuration) use ($price) {
                return $configuration['id'] === $price['salesPriceId'];
            });

            if (empty($priceConfiguration)) {
                // no price configuration found, skip price

                continue;
            }

            $priceConfiguration = array_shift($priceConfiguration);

            $customerClasses = $priceConfiguration['customerClasses'];

            if (count($customerClasses) !== 1 && $customerClasses[0]['customerClassId'] !== -1) {
                foreach ($customerGroups as $group) {
                    $customerGroupIdentity = $this->identityService->findOneBy([
                        'adapterIdentifier' => $group,
                        'adapterName' => PlentymarketsAdapter::NAME,
                        'objectType' => CustomerGroup::TYPE,
                    ]);

                    if (null === $customerGroupIdentity) {
                        $this->logger->warning('missing mapping für customer group', ['group' => $group]);

                        continue;
                    }

                    if (!isset($temporaryPrices[$customerGroupIdentity->getObjectIdentifier()][$priceConfiguration['type']])) {
                        $temporaryPrices[$customerGroupIdentity->getObjectIdentifier()][$priceConfiguration['type']] = [
                            'from' => $priceConfiguration['minimumOrderQuantity'],
                            'price' => $price['price'],
                        ];
                    }
                }
            } else {
                if (!isset($temporaryPrices['default'][$priceConfiguration['type']])) {
                    $temporaryPrices['default'][$priceConfiguration['type']] = [
                        'from' => $priceConfiguration['minimumOrderQuantity'],
                        'price' => $price['price'],
                    ];
                }
            }
        }

        /**
         * @var Price[] $prices
         */
        $prices = [];
        foreach ($temporaryPrices as $customerGroup => $priceArray) {
            if ($customerGroup === 'default') {
                $customerGroup = null;
            }

            $price = 0.0;
            $pseudoPrice = 0.0;

            if (isset($priceArray['default'])) {
                $price = (float) $priceArray['default']['price'];
            }

            if (isset($priceArray['rrp'])) {
                $pseudoPrice = (float) $priceArray['rrp']['price'];
            }

            if (isset($priceArray['specialOffer'])) {
                if ($pseudoPrice === 0.0) {
                    $pseudoPrice = $price;
                }

                $price = (float) $priceArray['specialOffer']['price'];
            }

            $prices[] = Price::fromArray([
                'price' => $price,
                'pseudoPrice' => $pseudoPrice,
                'customerGroupIdentifier' => $customerGroup,
                'from' => (int) $priceArray['default']['from'],
                'to' => null,
            ]);
        }

        foreach ($prices as $price) {
            /**
             * @var Price[] $possibleScalePrices
             */
            $possibleScalePrices = array_filter($prices, function (Price $possiblePrice) use ($price) {
                return $possiblePrice->getCustomerGroupIdentifier() === $price->getCustomerGroupIdentifier() &&
                    spl_object_hash($price) !== spl_object_hash($possiblePrice);
            });

            if (empty($possibleScalePrices)) {
                continue;
            }

            usort($possibleScalePrices, function (Price $possibleScalePriceLeft, Price $possibleScalePriceright) {
                if ($possibleScalePriceLeft->getFromAmount() === $possibleScalePriceright->getFromAmount()) {
                    return 0;
                }

                if ($possibleScalePriceLeft->getFromAmount() > $possibleScalePriceright->getFromAmount()) {
                    return 1;
                }

                return -1;
            });

            foreach ($possibleScalePrices as $possibleScalePrice) {
                if ($possibleScalePrice->getFromAmount() > $price->getFromAmount()) {
                    $price->setToAmount($possibleScalePrice->getFromAmount() - 1);

                    break;
                }
            }
        }

        return $prices;
    }

    /**
     * @param array $texts
     * @param array $variation
     * @param array $result
     *
     * @return Image[]
     */
    private function getVariationImages(array $texts, array $variation, array &$result)
    {
        $images = array_map(function ($image) use ($texts, &$result) {
            /**
             * @var MediaResponseParserInterface $mediaResponseParser
             */
            $mediaResponseParser = Shopware()->Container()->get('plentmarkets_adapter.response_parser.media');

            if (!empty($image['names'][0]['name'])) {
                $name = $image['names'][0]['name'];
            } else {
                $name = $texts[0]['name1'];
            }

            $media = $mediaResponseParser->parse([
                'mediaCategory' => MediaCategoryHelper::PRODUCT,
                'link' => $image['url'],
                'name' => $name,
                'translations' => $this->getMediaTranslations($image, $texts),
            ]);

            $result[] = $media;

            $linkedShops = array_filter($image['availabilities'], function (array $availabilitiy) {
                return $availabilitiy['type'] === 'mandant';
            });

            $shopIdentifiers = array_map(function ($shop) {
                $shopIdentity = $this->identityService->findOneBy([
                    'adapterIdentifier' => (string) $shop['value'],
                    'adapterName' => PlentymarketsAdapter::NAME,
                    'objectType' => Shop::TYPE,
                ]);

                if (null === $shopIdentity) {
                    return null;
                }

                return $shopIdentity->getObjectIdentifier();
            }, $linkedShops);

            return Image::fromArray([
                'mediaIdentifier' => $media->getIdentifier(),
                'shopIdentifiers' => array_filter($shopIdentifiers),
                'position' => (int) $image['position'],
            ]);
        }, $variation['images']);

        return array_filter($images);
    }

    /**
     * @param array $variation
     *
     * @throws NotFoundException
     *
     * @return string
     */
    private function getUnitIdentifier(array $variation)
    {
        if (empty($variation['unit'])) {
            return null;
        }

        // Unit
        $unitIdentity = $this->identityService->findOneBy([
            'adapterIdentifier' => $variation['unit']['unitId'],
            'adapterName' => PlentymarketsAdapter::NAME,
            'objectType' => Unit::TYPE,
        ]);

        if (null === $unitIdentity) {
            throw new NotFoundException('missing mapping for unit');
        }

        return $unitIdentity->getObjectIdentifier();
    }

    /**
     * @param array $variation
     *
     * @throws NotFoundException
     *
     * @return string
     */
    private function getVatRateIdentifier(array $variation)
    {
        $vatRateIdentity = $this->identityService->findOneBy([
            'adapterIdentifier' => $variation['vatId'],
            'adapterName' => PlentymarketsAdapter::NAME,
            'objectType' => VatRate::TYPE,
        ]);

        if (null === $vatRateIdentity) {
            throw new NotFoundException('missing mapping for vat rate');
        }

        return $vatRateIdentity->getObjectIdentifier();
    }

    /**
     * @param array $variation
     *
     * @return null|DateTimeImmutable
     */
    private function getReleaseDate(array $variation)
    {
        if (null !== $variation['releasedAt']) {
            $timezone = new DateTimeZone('UTC');

            return new DateTimeImmutable($variation['releasedAt'], $timezone);
        }

        return null;
    }

    /**
     * @param array $product
     *
     * @throws NotFoundException
     *
     * @return string
     */
    private function getManufacturerIdentifier(array $product)
    {
        $manufacturerIdentity = $this->identityService->findOneOrCreate(
            (string) $product['manufacturerId'],
            PlentymarketsAdapter::NAME,
            Manufacturer::TYPE
        );

        if (null === $manufacturerIdentity) {
            throw new NotFoundException('missing mapping for manufacturer');
        }

        return $manufacturerIdentity->getObjectIdentifier();
    }

    /**
     * @param array $product
     *
     * @return array
     */
    private function getShippingProfiles(array $product)
    {
        $productShippingProfiles = $this->itemsItemShippingProfilesApi->findOne($product['id']);

        $shippingProfiles = [];
        foreach ($productShippingProfiles as $profile) {
            $profileIdentity = $this->identityService->findOneBy([
                'adapterIdentifier' => (string) $profile['profileId'],
                'objectType' => ShippingProfile::TYPE,
                'adapterName' => PlentymarketsAdapter::NAME,
            ]);

            if (null === $profileIdentity) {
                $this->logger->warning('missing mapping for shipping profile', ['profile' => $profile]);

                continue;
            }

            $shippingProfiles[] = $profileIdentity->getObjectIdentifier();
        }

        return $shippingProfiles;
    }

    /**
     * @param array $product
     * @param array $texts
     * @param array $result
     *
     * @return Image[]
     */
    private function getImages(array $product, array $texts, array &$result)
    {
        $images = $this->itemsImagesApi->findAll($product['id']);

        $imageIdentifiers = array_map(function ($image) use ($texts, &$result) {
            /**
             * @var MediaResponseParserInterface $mediaResponseParser
             */
            $mediaResponseParser = Shopware()->Container()->get('plentmarkets_adapter.response_parser.media');

            if (!empty($image['names'][0]['name'])) {
                $name = $image['names'][0]['name'];
            } else {
                $name = $texts[0]['name1'];
            }

            $media = $mediaResponseParser->parse([
                'mediaCategory' => MediaCategoryHelper::PRODUCT,
                'link' => $image['url'],
                'name' => $name,
                'translations' => $this->getMediaTranslations($image, $texts),
            ]);

            $result[] = $media;

            $linkedShops = array_filter($image['availabilities'], function (array $availabilitiy) {
                return $availabilitiy['type'] === 'mandant';
            });

            $shopIdentifiers = array_map(function ($shop) {
                $shopIdentity = $this->identityService->findOneBy([
                    'adapterIdentifier' => (string) $shop['value'],
                    'adapterName' => PlentymarketsAdapter::NAME,
                    'objectType' => Shop::TYPE,
                ]);

                if (null === $shopIdentity) {
                    return null;
                }

                return $shopIdentity->getObjectIdentifier();
            }, $linkedShops);

            return Image::fromArray([
                'mediaIdentifier' => $media->getIdentifier(),
                'shopIdentifiers' => array_filter($shopIdentifiers),
                'position' => (int) $image['position'],
            ]);
        }, $images);

        return array_filter($imageIdentifiers);
    }

    /**
     * @param array $mainVariation
     *
     * @return array
     */
    private function getDafaultCategories(array $mainVariation)
    {
        $defaultCategories = [];

        foreach ($mainVariation['variationDefaultCategory'] as $category) {
            $categoryIdentity = $this->identityService->findOneBy([
                'adapterIdentifier' => (string) $category['branchId'],
                'adapterName' => PlentymarketsAdapter::NAME,
                'objectType' => Category::TYPE,
            ]);

            if (null === $categoryIdentity) {
                $this->logger->warning('missing mapping for category', ['category' => $category]);

                continue;
            }

            $defaultCategories[] = $categoryIdentity->getObjectIdentifier();
        }

        return $defaultCategories;
    }

    /**
     * @param array $texts
     *
     * @return Translation[]
     */
    private function getProductTranslations(array $texts)
    {
        $translations = [];

        foreach ($texts as $text) {
            $languageIdentifier = $this->identityService->findOneBy([
                'adapterIdentifier' => $text['lang'],
                'adapterName' => PlentymarketsAdapter::NAME,
                'objectType' => Language::TYPE,
            ]);

            if (null === $languageIdentifier) {
                continue;
            }

            $translations[] = Translation::fromArray([
                'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                'property' => 'name',
                'value' => $text['name1'],
            ]);

            $translations[] = Translation::fromArray([
                'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                'property' => 'description',
                'value' => $text['shortDescription'],
            ]);

            $translations[] = Translation::fromArray([
                'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                'property' => 'longDescription',
                'value' => $text['description'],
            ]);

            $translations[] = Translation::fromArray([
                'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                'property' => 'technicalDescription',
                'value' => $text['technicalData'],
            ]);

            $translations[] = Translation::fromArray([
                'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                'property' => 'metaTitle',
                'value' => $text['name1'],
            ]);

            $translations[] = Translation::fromArray([
                'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                'property' => 'metaDescription',
                'value' => $text['metaDescription'],
            ]);

            $translations[] = Translation::fromArray([
                'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                'property' => 'metaKeywords',
                'value' => $text['keywords'],
            ]);
        }

        return $translations;
    }

    /**
     * @param $variation
     *
     * @return float
     */
    private function getStock($variation)
    {
        $summedStocks = 0;

        foreach ($variation['stock'] as $stock) {
            if (array_key_exists('netStock', $stock)) {
                $summedStocks += $stock['netStock'];
            }
        }

        return (float) $summedStocks;
    }

    /**
     * @param array $mainVariation
     *
     * @return array
     */
    private function getCategories(array $mainVariation)
    {
        $categories = [];
        foreach ($mainVariation['variationCategories'] as $category) {
            $categoryIdentity = $this->identityService->findOneBy([
                'adapterIdentifier' => (string) $category['categoryId'],
                'adapterName' => PlentymarketsAdapter::NAME,
                'objectType' => Category::TYPE,
            ]);

            if (null === $categoryIdentity) {
                $this->logger->warning('missing mapping for category', ['category' => $category]);

                continue;
            }

            $categories[] = $categoryIdentity->getObjectIdentifier();
        }

        return $categories;
    }

    /**
     * @param array $product
     *
     * @return Attribute[]
     */
    private function getAttributes(array $product)
    {
        $attributes = [];

        for ($i = 0; $i < 20; ++$i) {
            $key = 'free' . ($i + 1);

            $attributes[] = Attribute::fromArray([
                'key' => $key,
                'value' => (string) $product[$key],
            ]);
        }

        return $attributes;
    }

    /**
     * @param array $image
     * @param array $productTexts
     *
     * @return array
     */
    private function getMediaTranslations(array $image, array $productTexts)
    {
        $translations = [];

        foreach ($image['names'] as $text) {
            $languageIdentifier = $this->identityService->findOneBy([
                'adapterIdentifier' => $text['lang'],
                'adapterName' => PlentymarketsAdapter::NAME,
                'objectType' => Language::TYPE,
            ]);

            if (null === $languageIdentifier) {
                continue;
            }

            if (!empty($text['name'])) {
                $name = $text['name'];
            } else {
                $name = '';

                foreach ($productTexts as $productText) {
                    if ($text['lang'] === $productText['lang']) {
                        $name = $productText['name1'];
                    }
                }
            }

            $translations[] = Translation::fromArray([
                'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                'property' => 'name',
                'value' => $name,
            ]);

            if (!empty($text['alternate'])) {
                $alternate = $text['alternate'];
            } else {
                $alternate = '';

                foreach ($productTexts as $productText) {
                    if ($text['lang'] === $productText['lang']) {
                        $alternate = $productText['name1'];
                    }
                }
            }

            $translations[] = Translation::fromArray([
                'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                'property' => 'alternateName',
                'value' => $alternate,
            ]);
        }

        return $translations;
    }

    /**
     * @param array $texts
     * @param array $variations
     * @param array $result
     *
     * @return Variation[]
     */
    private function getVariations(array $texts, $variations, array &$result)
    {
        $mappedVariations = [];

        if (count($variations) > 1) {
            $variations = array_filter($variations, function (array $variation) {
                return !empty($variation['variationAttributeValues']);
            });
        }

        usort($variations, function (array $a, array $b) {
            if ((int) $a['position'] === (int) $b['position']) {
                return 0;
            }

            return ((int) $a['position'] < (int) $b['position']) ? -1 : 1;
        });

        $first = true;
        foreach ($variations as $element) {
            $variation = new Variation();
            $variation->setActive((bool) $element['isActive']);
            $variation->setIsMain($first);
            $variation->setStock($this->getStock($element));
            $variation->setNumber((string) $element['number']);
            $variation->setBarcodes($this->getBarcodes($element));
            $variation->setPosition((int) $element['position']);
            $variation->setModel((string) $element['model']);
            $variation->setImages($this->getVariationImages($texts, $element, $result));
            $variation->setPrices($this->getPrices($element));
            $variation->setPurchasePrice((float) $element['purchasePrice']);
            $variation->setUnitIdentifier($this->getUnitIdentifier($element));
            $variation->setContent((float) $element['unit']['content']);
            $variation->setPackagingUnit((float) $element['packingUnits']);
            $variation->setMaximumOrderQuantity((float) $element['maximumOrderQuantity']);
            $variation->setMinimumOrderQuantity((float) $element['minimumOrderQuantity']);
            $variation->setIntervalOrderQuantity((float) $element['intervalOrderQuantity']);
            $variation->setReleaseDate($this->getReleaseDate($element));
            $variation->setShippingTime($this->getShippingTime($element));
            $variation->setWidth((int) $element['widthMM']);
            $variation->setHeight((int) $element['heightMM']);
            $variation->setLength((int) $element['lengthMM']);
            $variation->setWeight((int) $element['weightNetG']);
            $variation->setProperties($this->getVariationProperties($element));

            $mappedVariations[] = $variation;
            $first = false;
        }

        return $mappedVariations;
    }

    /**
     * @param $product
     *
     * @return LinkedProduct[]
     */
    private function getLinkedProducts(array $product)
    {
        $result = [];

        foreach ($product['itemCrossSelling'] as $linkedProduct) {
            if ($linkedProduct['relationship'] === 'Similar') {
                $type = LinkedProduct::TYPE_SIMILAR;
            } elseif ($linkedProduct['relationship'] === 'Accessory') {
                $type = LinkedProduct::TYPE_ACCESSORY;
            } elseif ($linkedProduct['relationship'] === 'ReplacementPart') {
                $type = LinkedProduct::TYPE_REPLACEMENT;
            } else {
                continue;
            }

            $productIdentity = $this->identityService->findOneBy([
                'adapterIdentifier' => $linkedProduct['crossItemId'],
                'adapterName' => PlentymarketsAdapter::NAME,
                'objectType' => Product::TYPE,
            ]);

            if (null === $productIdentity) {
                $this->logger->warning('linked product not found', ['linkedProduct' => $linkedProduct]);

                continue;
            }

            $result[] = LinkedProduct::fromArray([
                'type' => $type,
                'productIdentifier' => $productIdentity->getObjectIdentifier(),
            ]);
        }

        return $result;
    }

    /**
     * @param $mainVariation
     *
     * @return Property[]
     */
    private function getProperties(array $mainVariation)
    {
        $result = [];

        $properties = $this->itemsVariationsVariationPropertiesApi->findOne(
            $mainVariation['itemId'],
            $mainVariation['id']
        );

        static $propertyNames;

        foreach ($properties as $property) {
            if (!isset($propertyNames[$property['property']['id']])) {
                $propertyName = $this->itemsPropertiesNamesApi->findOne($property['property']['id']);

                $propertyNames[$property['property']['id']] = $propertyName;
            } else {
                $propertyName = $propertyNames[$property['property']['id']];
            }

            $translations = [];
            foreach ($propertyName as $name) {
                $languageIdentifier = $this->identityService->findOneBy([
                    'adapterIdentifier' => $name['lang'],
                    'adapterName' => PlentymarketsAdapter::NAME,
                    'objectType' => Language::TYPE,
                ]);

                if (null === $languageIdentifier) {
                    continue;
                }

                $translations[] = Translation::fromArray([
                    'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                    'property' => 'name',
                    'value' => $name['name'],
                ]);
            }

            $values = [];

            if ($property['property']['valueType'] === 'text') {
                if (empty($property['valueTexts'][0]['value'])) {
                    continue;
                }

                $valueTranslations = [];
                foreach ($property['valueTexts'] as $name) {
                    $languageIdentifier = $this->identityService->findOneBy([
                        'adapterIdentifier' => $name['lang'],
                        'adapterName' => PlentymarketsAdapter::NAME,
                        'objectType' => Language::TYPE,
                    ]);

                    if (null === $languageIdentifier) {
                        continue;
                    }

                    $valueTranslations[] = Translation::fromArray([
                        'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                        'property' => 'value',
                        'value' => $name['value'],
                    ]);
                }

                $values[] = Value::fromArray([
                    'value' => (string) $property['valueTexts'][0]['value'],
                    'translations' => $valueTranslations,
                ]);
            } elseif ($property['property']['valueType'] === 'int') {
                $values[] = Value::fromArray([
                    'value' => (string) $property['valueInt'],
                ]);
            } elseif ($property['property']['valueType'] === 'float') {
                $values[] = Value::fromArray([
                    'value' => (string) $property['valueFloat'],
                ]);
            } elseif ($property['property']['valueType'] === 'file') {
                $this->logger->notice('file properties are not supported', ['variation', $mainVariation['id']]);

                continue;
            } elseif ($property['property']['valueType'] === 'selection') {
                static $selections;

                if (null === $property['propertySelectionId']) {
                    continue;
                }

                if (!isset($selections[$property['propertyId']])) {
                    $selection = $this->itemsPropertiesSelectionsApi->findOne($property['propertyId']);

                    foreach ($selection as $element) {
                        $selections[$property['propertyId']][$element['id']] = $element;
                        $selections[$property['propertyId']][$element['id']]['translations'] = [];

                        $languageIdentifier = $this->identityService->findOneBy([
                            'adapterIdentifier' => $element['lang'],
                            'adapterName' => PlentymarketsAdapter::NAME,
                            'objectType' => Language::TYPE,
                        ]);

                        if (null !== $languageIdentifier) {
                            $translation = Translation::fromArray([
                                'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                                'property' => 'value',
                                'value' => $element['name'],
                            ]);

                            $selections[$property['propertyId']][$element['id']]['translations'] = [$translation];
                        }
                    }
                }

                $values[] = Value::fromArray([
                    'value' => (string) $selections[$property['propertyId']][$property['propertySelectionId']]['name'],
                    'translations' => $selections[$property['propertyId']][$property['propertySelectionId']]['translations'],
                ]);
            }

            $result[] = Property::fromArray([
                'name' => $propertyName[0]['name'],
                'values' => $values,
                'translations' => $translations,
            ]);
        }

        return $result;
    }

    /**
     * @param array $variation
     *
     * @return int
     */
    private function getShippingTime(array $variation)
    {
        static $shippingConfigurations;

        if (null === $shippingConfigurations) {
            try {
                $shippingConfigurations = $this->availabilitiesApi->findAll();
            } catch (\Exception $exception) {
                // not implemented on all systems yet

                $shippingConfigurations = [];
            }
        }

        $shippingConfiguration = array_filter($shippingConfigurations,
            function (array $configuration) use ($variation) {
                return $configuration['id'] === $variation['availability'];
            });

        if (!empty($shippingConfiguration)) {
            $shippingConfiguration = array_shift($shippingConfiguration);

            return $shippingConfiguration['averageDays'];
        }

        return 0;
    }

    /**
     * @param $variation
     *
     * @return Property[]
     */
    private function getVariationProperties(array $variation)
    {
        static $attributes;

        $result = [];
        foreach ($variation['variationAttributeValues'] as $attributeValue) {
            if (!isset($attributes[$attributeValue['attributeId']])) {
                $attributes[$attributeValue['attributeId']] = $this->itemAttributesApi->findOne($attributeValue['attributeId']);

                $attributes[$attributeValue['attributeId']]['values'] = [];

                $values = $this->itemAttributesValuesApi->findOne($attributeValue['attributeId']);

                foreach ($values as $value) {
                    $attributes[$attributeValue['attributeId']]['values'][$value['id']] = $value;
                }
            }

            if (!isset($attributes[$attributeValue['attributeId']]['values'][$attributeValue['valueId']]['valueNames'])) {
                continue;
            }

            $propertyNames = $attributes[$attributeValue['attributeId']]['attributeNames'];
            $valueNames = $attributes[$attributeValue['attributeId']]['values'][$attributeValue['valueId']]['valueNames'];

            $value = Value::fromArray([
                'value' => $valueNames[0]['name'],
                'translations' => $this->getPropertyValueTranslations($valueNames),
            ]);

            $result[] = Property::fromArray([
                'name' => $propertyNames[0]['name'],
                'values' => [$value],
                'translations' => $this->getPropertyTranslations($propertyNames),
            ]);
        }

        return $result;
    }

    /**
     * @param array $names
     *
     * @return Translation[]
     */
    private function getPropertyValueTranslations(array $names)
    {
        $translations = [];

        foreach ($names as $name) {
            $languageIdentifier = $this->identityService->findOneBy([
                'adapterIdentifier' => $name['lang'],
                'adapterName' => PlentymarketsAdapter::NAME,
                'objectType' => Language::TYPE,
            ]);

            if (null === $languageIdentifier) {
                continue;
            }

            $translations[] = Translation::fromArray([
                'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                'property' => 'value',
                'value' => $name['name'],
            ]);
        }

        return $translations;
    }

    /**
     * @param array $names
     *
     * @return Translation[]
     */
    private function getPropertyTranslations(array $names)
    {
        $translations = [];

        foreach ($names as $name) {
            $languageIdentifier = $this->identityService->findOneBy([
                'adapterIdentifier' => $name['lang'],
                'adapterName' => PlentymarketsAdapter::NAME,
                'objectType' => Language::TYPE,
            ]);

            if (null === $languageIdentifier) {
                continue;
            }

            $translations[] = Translation::fromArray([
                'languageIdentifier' => $languageIdentifier->getObjectIdentifier(),
                'property' => 'name',
                'value' => $name['name'],
            ]);
        }

        return $translations;
    }

    /**
     * @param array $mainVariation
     *
     * @return array
     */
    private function getShopIdentifiers(array $mainVariation)
    {
        $identifiers = [];

        foreach ($mainVariation['variationClients'] as $client) {
            $identity = $this->identityService->findOneBy([
                'adapterIdentifier' => $client['plentyId'],
                'adapterName' => PlentymarketsAdapter::NAME,
                'objectType' => Shop::TYPE,
            ]);

            if (null === $identity) {
                $this->logger->notice('shop not found', $client);

                continue;
            }

            $identifiers[] = $identity->getObjectIdentifier();
        }

        return $identifiers;
    }

    /**
     * @param array $mainVariation
     *
     * @return DateTimeImmutable
     */
    private function getAvailableFrom(array $mainVariation)
    {
        if (!empty($mainVariation['availableUntil'])) {
            $timezone = new DateTimeZone('UTC');

            return new DateTimeImmutable('now', $timezone);
        }

        return null;
    }

    /**
     * @param array $mainVariation
     *
     * @return DateTimeImmutable
     */
    private function getAvailableTo(array $mainVariation)
    {
        if (!empty($mainVariation['availableUntil'])) {
            $timezone = new DateTimeZone('UTC');

            return new DateTimeImmutable($mainVariation['availableUntil'], $timezone);
        }

        return null;
    }

    /**
     * @param array $variation
     *
     * @return Barcode[]
     */
    private function getBarcodes(array $variation)
    {
        $barcodeMapping = [
            1 => Barcode::TYPE_GTIN13,
            2 => Barcode::TYPE_GTIN128,
            3 => Barcode::TYPE_UPC,
            4 => Barcode::TYPE_ISBN,
        ];

        $barcodes = array_filter($variation['variationBarcodes'], function (array $barcode) use ($barcodeMapping) {
            return array_key_exists($barcode['barcodeId'], $barcodeMapping);
        });

        $barcodes = array_map(function (array $barcode) use ($barcodeMapping) {
            return Barcode::fromArray([
                'type' => $barcodeMapping[$barcode['barcodeId']],
                'code' => $barcode['code'],
            ]);
        }, $barcodes);

        return $barcodes;
    }

    /**
     * @param Variation[] $variations
     *
     * @return bool
     */
    private function getActive(array $variations)
    {
        foreach ($variations as $variation) {
            if ($variation->getActive()) {
                return true;
            }
        }

        return false;
    }
}
