<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Sammelt die Produktdaten und erzeugt daraus den Google-Merchant-Feed (RSS 2.0).
 */
class InternautenGoogleFeedBuilder
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';

    /** Untergrenze fuer die Wortlaenge, damit Kuerzel wie "XL" nie angetastet werden. */
    private const ALL_CAPS_TITLE_MIN_LENGTH = 3;

    /** @var int */
    private $idShop;

    /** @var int */
    private $idLang;

    /** @var Currency */
    private $currency;

    /** @var Link */
    private $link;

    /** @var array<int, true> Ausgeschlossene Kategorie-IDs als Lookup */
    private $excludedCategories = [];

    /** @var bool */
    private $includeOutOfStock;

    /** @var bool */
    private $useCombinations;

    /** @var string */
    private $identifierPrefix;

    /** @var string */
    private $imageType;

    /** @var bool */
    private $normalizeTitleCase;

    /** @var int */
    private $titleCaseMinLength;

    /** @var array<string, true> Kleingeschriebene Woerter, die nie umgeschrieben werden */
    private $keepUppercaseWords = [];

    /** @var bool */
    private $protectBrandWords;

    /** @var array<int, array<string, string>> */
    private $issues = [];

    /** @var int */
    private $skippedCount = 0;

    /** @var int */
    private $validCount = 0;

    public function __construct(array $settings)
    {
        $this->idShop = (int) $settings['id_shop'];
        $this->idLang = (int) $settings['id_lang'];
        $this->currency = $settings['currency'];
        $this->link = $settings['link'];
        $this->includeOutOfStock = !empty($settings['include_out_of_stock']);
        $this->useCombinations = !empty($settings['use_combinations']);
        $this->identifierPrefix = (string) ($settings['identifier_prefix'] ?? '');
        $this->imageType = (string) ($settings['image_type'] ?? '');
        $this->normalizeTitleCase = !empty($settings['normalize_title_case']);
        $this->titleCaseMinLength = max(2, (int) ($settings['title_case_min_length'] ?? 4));
        $this->protectBrandWords = !empty($settings['protect_brand_words']);

        foreach ((array) ($settings['keep_uppercase_words'] ?? []) as $word) {
            $word = Tools::strtolower(trim((string) $word));
            if ($word !== '') {
                $this->keepUppercaseWords[$word] = true;
            }
        }

        foreach ((array) ($settings['excluded_categories'] ?? []) as $idCategory) {
            $idCategory = (int) $idCategory;
            if ($idCategory > 0) {
                $this->excludedCategories[$idCategory] = true;
            }
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getIssues()
    {
        return $this->issues;
    }

    public function getSkippedCount()
    {
        return $this->skippedCount;
    }

    public function getValidCount()
    {
        return $this->validCount;
    }

    /**
     * Sammelt alle feedfaehigen Items und protokolliert dabei die gefundenen Probleme.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildItems($limit = 0)
    {
        $items = [];
        $rows = $this->fetchProductRows();

        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];

            if ($this->isExcludedByCategory($idProduct)) {
                ++$this->skippedCount;
                continue;
            }

            $variants = $this->useCombinations
                ? $this->getCombinationIds($idProduct)
                : [0];

            foreach ($variants as $idProductAttribute) {
                $item = $this->buildItem($row, (int) $idProductAttribute);

                if ($item === null) {
                    ++$this->skippedCount;
                    continue;
                }

                ++$this->validCount;
                $items[] = $item;

                if ($limit > 0 && count($items) >= $limit) {
                    return $items;
                }
            }
        }

        return $items;
    }

    /**
     * @return string XML des kompletten Feeds
     */
    public function renderXml(array $items, $shopName, $shopUrl, $shopDescription)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= '  <channel>' . "\n";
        $xml .= '    <title>' . $this->cdata($shopName) . '</title>' . "\n";
        $xml .= '    <link>' . $this->escape($shopUrl) . '</link>' . "\n";
        $xml .= '    <description>' . $this->cdata($shopDescription) . '</description>' . "\n";

        foreach ($items as $item) {
            $xml .= $this->renderItem($item);
        }

        $xml .= '  </channel>' . "\n";
        $xml .= '</rss>' . "\n";

        return $xml;
    }

    private function renderItem(array $item)
    {
        $xml = '    <item>' . "\n";
        $xml .= '      <g:id>' . $this->cdata($item['id']) . '</g:id>' . "\n";
        $xml .= '      <g:title>' . $this->cdata($item['title']) . '</g:title>' . "\n";
        $xml .= '      <g:description>' . $this->cdata($item['description']) . '</g:description>' . "\n";
        $xml .= '      <g:link>' . $this->cdata($item['link']) . '</g:link>' . "\n";
        $xml .= '      <g:image_link>' . $this->cdata($item['image_link']) . '</g:image_link>' . "\n";

        foreach ($item['additional_image_links'] as $additionalImage) {
            $xml .= '      <g:additional_image_link>' . $this->cdata($additionalImage) . '</g:additional_image_link>' . "\n";
        }

        $xml .= '      <g:availability>' . $this->escape($item['availability']) . '</g:availability>' . "\n";
        $xml .= '      <g:price>' . $this->escape($item['price']) . '</g:price>' . "\n";

        if ($item['sale_price'] !== null) {
            $xml .= '      <g:sale_price>' . $this->escape($item['sale_price']) . '</g:sale_price>' . "\n";
        }

        $xml .= '      <g:condition>' . $this->escape($item['condition']) . '</g:condition>' . "\n";
        $xml .= '      <g:identifier_exists>' . ($item['identifier_exists'] ? 'yes' : 'no') . '</g:identifier_exists>' . "\n";

        if ($item['gtin'] !== '') {
            $xml .= '      <g:gtin>' . $this->escape($item['gtin']) . '</g:gtin>' . "\n";
        }

        if ($item['mpn'] !== '') {
            $xml .= '      <g:mpn>' . $this->cdata($item['mpn']) . '</g:mpn>' . "\n";
        }

        if ($item['brand'] !== '') {
            $xml .= '      <g:brand>' . $this->cdata($item['brand']) . '</g:brand>' . "\n";
        }

        if ($item['product_type'] !== '') {
            $xml .= '      <g:product_type>' . $this->cdata($item['product_type']) . '</g:product_type>' . "\n";
        }

        if ($item['item_group_id'] !== null) {
            $xml .= '      <g:item_group_id>' . $this->cdata($item['item_group_id']) . '</g:item_group_id>' . "\n";
        }

        if ($item['shipping_weight'] !== null) {
            $xml .= '      <g:shipping_weight>' . $this->escape($item['shipping_weight']) . '</g:shipping_weight>' . "\n";
        }

        $xml .= '    </item>' . "\n";

        return $xml;
    }

    /**
     * @return array<string, mixed>|null null bedeutet: fuer Google Merchant nicht verwendbar
     */
    private function buildItem(array $row, $idProductAttribute)
    {
        $idProduct = (int) $row['id_product'];
        $reference = $idProductAttribute > 0
            ? $idProduct . '-' . $idProductAttribute
            : (string) $idProduct;

        // Google beanstandet mehrfache Leerzeichen im Titel.
        $name = $this->collapseWhitespace((string) $row['name']);
        if ($name === '') {
            $this->addIssue($idProduct, $reference, self::SEVERITY_ERROR, 'Produktname fehlt.');

            return null;
        }

        if ($idProductAttribute > 0) {
            $combinationSuffix = $this->collapseWhitespace($this->getCombinationLabel($idProductAttribute));
            if ($combinationSuffix !== '') {
                $name .= ' - ' . $combinationSuffix;
            }
        }

        $brand = trim((string) $row['manufacturer_name']);

        $normalizedName = $this->normalizeCase($name, $brand);
        if ($normalizedName !== $name) {
            $this->addIssue(
                $idProduct,
                $reference,
                self::SEVERITY_WARNING,
                sprintf('Titel enthielt durchgehende Grossschreibung und wurde angepasst zu "%s".', $normalizedName),
                $name
            );
            $name = $normalizedName;
        }

        $description = $this->buildDescription($row);
        if ($description === '') {
            $this->addIssue($idProduct, $reference, self::SEVERITY_WARNING, 'Beschreibung fehlt, es wird der Produktname verwendet.', $name);
            $description = $name;
        } else {
            $normalizedDescription = $this->normalizeCase($description, $brand);
            if ($normalizedDescription !== $description) {
                $this->addIssue(
                    $idProduct,
                    $reference,
                    self::SEVERITY_WARNING,
                    'Beschreibung enthielt durchgehende Grossschreibung und wurde angepasst.',
                    $name
                );
                $description = $normalizedDescription;
            }
        }

        $priceWithTax = $this->getPrice($idProduct, $idProductAttribute, false);
        if ($priceWithTax === null) {
            $this->addIssue($idProduct, $reference, self::SEVERITY_ERROR, 'Preis konnte nicht berechnet werden.', $name);

            return null;
        }

        if ($priceWithTax <= 0) {
            $this->addIssue($idProduct, $reference, self::SEVERITY_ERROR, 'Preis ist 0 oder negativ.', $name);

            return null;
        }

        $salePrice = null;
        $regularPrice = $this->getPrice($idProduct, $idProductAttribute, true);
        if ($regularPrice !== null && $regularPrice > $priceWithTax) {
            $salePrice = $priceWithTax;
            $priceWithTax = $regularPrice;
        }

        $images = $this->getImageLinks($idProduct, $idProductAttribute, $row['link_rewrite']);
        if (empty($images)) {
            $this->addIssue($idProduct, $reference, self::SEVERITY_ERROR, 'Kein Produktbild vorhanden.', $name);

            return null;
        }

        $productLink = $this->link->getProductLink(
            $idProduct,
            $row['link_rewrite'],
            null,
            null,
            $this->idLang,
            $this->idShop,
            $idProductAttribute > 0 ? $idProductAttribute : null
        );

        $gtin = $this->resolveGtin($row, $idProductAttribute);
        $mpn = $this->resolveMpn($row, $idProductAttribute);

        if ($brand === '') {
            $this->addIssue($idProduct, $reference, self::SEVERITY_WARNING, 'Marke (Hersteller) fehlt.', $name);
        }

        if ($gtin === '' && $mpn === '') {
            $this->addIssue(
                $idProduct,
                $reference,
                self::SEVERITY_WARNING,
                'Weder GTIN/EAN noch MPN vorhanden, identifier_exists wird auf "no" gesetzt.',
                $name
            );
        }

        $availability = $this->resolveAvailability($idProduct, $idProductAttribute, $row);
        if ($availability === null) {
            return null;
        }

        return [
            'id' => $this->identifierPrefix . $reference,
            'title' => Tools::substr($name, 0, 150),
            'description' => Tools::substr($description, 0, 5000),
            'link' => $productLink,
            'image_link' => array_shift($images),
            'additional_image_links' => array_slice($images, 0, 10),
            'availability' => $availability,
            'price' => $this->formatPrice($priceWithTax),
            'sale_price' => $salePrice !== null ? $this->formatPrice($salePrice) : null,
            'condition' => $this->resolveCondition($row['condition']),
            'gtin' => $gtin,
            'mpn' => $mpn,
            'identifier_exists' => ($gtin !== '' || $mpn !== ''),
            'brand' => $brand,
            'product_type' => $this->getCategoryPath($idProduct),
            'item_group_id' => $idProductAttribute > 0 ? $this->identifierPrefix . $idProduct : null,
            'shipping_weight' => $this->resolveWeight($row, $idProductAttribute),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductRows()
    {
        $sql = new DbQuery();
        $sql->select('p.`id_product`, p.`ean13`, p.`isbn`, p.`upc`, p.`mpn`, p.`weight`, p.`id_manufacturer`');
        $sql->select('ps.`condition`, ps.`visibility`, ps.`available_for_order`, ps.`show_price`, ps.`price`');
        $sql->select('pl.`name`, pl.`description`, pl.`description_short`, pl.`link_rewrite`');
        $sql->select('m.`name` AS manufacturer_name');
        $sql->from('product', 'p');
        $sql->innerJoin(
            'product_shop',
            'ps',
            'ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $this->idShop
        );
        $sql->innerJoin(
            'product_lang',
            'pl',
            'pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $this->idLang . ' AND pl.`id_shop` = ' . $this->idShop
        );
        $sql->leftJoin('manufacturer', 'm', 'm.`id_manufacturer` = p.`id_manufacturer`');
        $sql->where('ps.`active` = 1');
        $sql->where('ps.`visibility` IN ("both", "catalog")');
        $sql->orderBy('p.`id_product` ASC');

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        return is_array($rows) ? $rows : [];
    }

    private function isExcludedByCategory($idProduct)
    {
        if (empty($this->excludedCategories)) {
            return false;
        }

        foreach ($this->getProductCategories($idProduct) as $idCategory) {
            if (isset($this->excludedCategories[(int) $idCategory])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, int>
     */
    private function getProductCategories($idProduct)
    {
        $sql = new DbQuery();
        $sql->select('cp.`id_category`');
        $sql->from('category_product', 'cp');
        $sql->where('cp.`id_product` = ' . (int) $idProduct);

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $categories = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $categories[] = (int) $row['id_category'];
        }

        return $categories;
    }

    /**
     * @return array<int, int>
     */
    private function getCombinationIds($idProduct)
    {
        $sql = new DbQuery();
        $sql->select('pa.`id_product_attribute`');
        $sql->from('product_attribute', 'pa');
        $sql->innerJoin(
            'product_attribute_shop',
            'pas',
            'pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . $this->idShop
        );
        $sql->where('pa.`id_product` = ' . (int) $idProduct);
        $sql->orderBy('pas.`default_on` DESC, pa.`id_product_attribute` ASC');

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $ids = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $ids[] = (int) $row['id_product_attribute'];
        }

        // Produkte ohne Kombinationen werden als einzelnes Item ausgeliefert.
        return empty($ids) ? [0] : $ids;
    }

    private function getCombinationLabel($idProductAttribute)
    {
        $sql = new DbQuery();
        $sql->select('al.`name`');
        $sql->from('product_attribute_combination', 'pac');
        $sql->innerJoin('attribute', 'a', 'a.`id_attribute` = pac.`id_attribute`');
        $sql->innerJoin(
            'attribute_lang',
            'al',
            'al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . $this->idLang
        );
        $sql->where('pac.`id_product_attribute` = ' . (int) $idProductAttribute);
        $sql->orderBy('a.`position` ASC');

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $parts = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $parts[] = (string) $row['name'];
        }

        return implode(', ', $parts);
    }

    private function buildDescription(array $row)
    {
        $description = strip_tags((string) $row['description_short']);

        if (trim($description) === '') {
            $description = strip_tags((string) $row['description']);
        }

        return $this->collapseWhitespace($description);
    }

    /**
     * Fasst Whitespace-Folgen zu einem Leerzeichen zusammen und entfernt Zeilenumbrueche.
     * Google beanstandet mehrfache Leerzeichen in Titel und Beschreibung.
     */
    private function collapseWhitespace($text)
    {
        $text = preg_replace('/[\p{Z}\s]+/u', ' ', (string) $text);

        return $text === null ? '' : trim($text);
    }

    /**
     * Wandelt durchgehend grossgeschriebene Woerter in Grossschreibung am Wortanfang um.
     * Google Merchant wertet Wort-Grossschreibung als Werbetext und kann Artikel deshalb ablehnen.
     * Es werden ausschliesslich reine Buchstabenfolgen ersetzt, damit Satzzeichen,
     * Ziffern und Artikelnummern wie "AB-12/X" unveraendert bleiben.
     */
    private function normalizeCase($text, $brand = '')
    {
        if (!$this->normalizeTitleCase) {
            return $text;
        }

        $protected = $this->getProtectedWords($brand);
        $minLength = max($this->titleCaseMinLength, self::ALL_CAPS_TITLE_MIN_LENGTH);

        $result = preg_replace_callback(
            '/\p{Lu}[\p{L}]*/u',
            function (array $matches) use ($protected, $minLength) {
                $word = $matches[0];

                if (Tools::strlen($word) < $minLength) {
                    return $word;
                }

                // Enthaelt das Wort einen Kleinbuchstaben, ist es bereits korrekt geschrieben.
                if (preg_match('/\p{Ll}/u', $word)) {
                    return $word;
                }

                if (isset($protected[Tools::strtolower($word)])) {
                    return $word;
                }

                return Tools::ucfirst(Tools::strtolower($word));
            },
            (string) $text
        );

        return $result === null ? $text : $result;
    }

    /**
     * @return array<string, true> Kleingeschriebene Woerter aus Ausnahmeliste und optional dem Herstellernamen
     */
    private function getProtectedWords($brand)
    {
        $protected = $this->keepUppercaseWords;
        $brand = trim((string) $brand);

        if (!$this->protectBrandWords || $brand === '') {
            return $protected;
        }

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $brand, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $protected[Tools::strtolower($word)] = true;
        }

        return $protected;
    }

    /**
     * @return float|null
     */
    private function getPrice($idProduct, $idProductAttribute, $withoutReduction)
    {
        try {
            $price = Product::getPriceStatic(
                (int) $idProduct,
                true,
                $idProductAttribute > 0 ? (int) $idProductAttribute : null,
                6,
                null,
                false,
                !$withoutReduction,
                1
            );
        } catch (Exception $exception) {
            return null;
        }

        return $price === null ? null : (float) $price;
    }

    private function formatPrice($price)
    {
        return number_format((float) $price, 2, '.', '') . ' ' . $this->currency->iso_code;
    }

    /**
     * @return string|null null bedeutet: Produkt wird ausgelassen
     */
    private function resolveAvailability($idProduct, $idProductAttribute, array $row)
    {
        $quantity = (int) StockAvailable::getQuantityAvailableByProduct(
            (int) $idProduct,
            $idProductAttribute > 0 ? (int) $idProductAttribute : null,
            $this->idShop
        );

        if ($quantity > 0) {
            return 'in_stock';
        }

        $outOfStockBehaviour = (int) StockAvailable::outOfStock((int) $idProduct, $this->idShop);
        $allowOrdering = $outOfStockBehaviour === 1
            || ($outOfStockBehaviour === 2 && (int) Configuration::get('PS_ORDER_OUT_OF_STOCK') === 1);

        if ($allowOrdering) {
            return 'backorder';
        }

        if (!$this->includeOutOfStock) {
            $this->addIssue(
                $idProduct,
                $idProductAttribute > 0 ? $idProduct . '-' . $idProductAttribute : (string) $idProduct,
                self::SEVERITY_WARNING,
                'Nicht auf Lager und nicht bestellbar, daher nicht im Feed.',
                $row['name']
            );

            return null;
        }

        return 'out_of_stock';
    }

    private function resolveCondition($condition)
    {
        $condition = (string) $condition;

        return in_array($condition, ['new', 'used', 'refurbished'], true) ? $condition : 'new';
    }

    private function resolveGtin(array $row, $idProductAttribute)
    {
        if ($idProductAttribute > 0) {
            $combination = $this->getCombinationIdentifiers($idProductAttribute);
            foreach (['ean13', 'isbn', 'upc'] as $field) {
                if (trim((string) ($combination[$field] ?? '')) !== '') {
                    return trim((string) $combination[$field]);
                }
            }
        }

        foreach (['ean13', 'isbn', 'upc'] as $field) {
            if (trim((string) $row[$field]) !== '') {
                return trim((string) $row[$field]);
            }
        }

        return '';
    }

    private function resolveMpn(array $row, $idProductAttribute)
    {
        if ($idProductAttribute > 0) {
            $combination = $this->getCombinationIdentifiers($idProductAttribute);
            if (trim((string) ($combination['mpn'] ?? '')) !== '') {
                return trim((string) $combination['mpn']);
            }
        }

        return trim((string) $row['mpn']);
    }

    /**
     * @return array<string, string>
     */
    private function getCombinationIdentifiers($idProductAttribute)
    {
        $sql = new DbQuery();
        $sql->select('pa.`ean13`, pa.`isbn`, pa.`upc`, pa.`mpn`, pa.`weight`');
        $sql->from('product_attribute', 'pa');
        $sql->where('pa.`id_product_attribute` = ' . (int) $idProductAttribute);

        $row = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);

        return is_array($row) ? $row : [];
    }

    /**
     * @return string|null
     */
    private function resolveWeight(array $row, $idProductAttribute)
    {
        $weight = (float) $row['weight'];

        if ($idProductAttribute > 0) {
            $combination = $this->getCombinationIdentifiers($idProductAttribute);
            $weight += (float) ($combination['weight'] ?? 0);
        }

        if ($weight <= 0) {
            return null;
        }

        return number_format($weight, 3, '.', '') . ' ' . Configuration::get('PS_WEIGHT_UNIT');
    }

    /**
     * @return array<int, string>
     */
    private function getImageLinks($idProduct, $idProductAttribute, $linkRewrite)
    {
        $sql = new DbQuery();
        $sql->select('i.`id_image`, ish.`cover`');
        $sql->from('image', 'i');
        $sql->innerJoin(
            'image_shop',
            'ish',
            'ish.`id_image` = i.`id_image` AND ish.`id_shop` = ' . $this->idShop
        );

        if ($idProductAttribute > 0) {
            $sql->leftJoin(
                'product_attribute_image',
                'pai',
                'pai.`id_image` = i.`id_image` AND pai.`id_product_attribute` = ' . (int) $idProductAttribute
            );
        }

        $sql->where('i.`id_product` = ' . (int) $idProduct);
        $sql->orderBy(
            ($idProductAttribute > 0 ? 'pai.`id_product_attribute` DESC, ' : '')
            . 'ish.`cover` DESC, i.`position` ASC'
        );

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $links = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $links[] = $this->link->getImageLink(
                (string) $linkRewrite,
                (int) $row['id_image'],
                $this->imageType !== '' ? $this->imageType : null
            );
        }

        return $links;
    }

    private function getCategoryPath($idProduct)
    {
        $sql = new DbQuery();
        $sql->select('cl.`name`');
        $sql->from('product_shop', 'ps');
        $sql->innerJoin('category', 'c', 'c.`id_category` = ps.`id_category_default`');
        $sql->innerJoin('category', 'parents', 'parents.`nleft` <= c.`nleft` AND parents.`nright` >= c.`nright`');
        $sql->innerJoin(
            'category_lang',
            'cl',
            'cl.`id_category` = parents.`id_category` AND cl.`id_lang` = ' . $this->idLang
            . ' AND cl.`id_shop` = ' . $this->idShop
        );
        $sql->where('ps.`id_product` = ' . (int) $idProduct);
        $sql->where('ps.`id_shop` = ' . $this->idShop);
        $sql->where('parents.`is_root_category` = 0');
        $sql->where('parents.`id_parent` != 0');
        $sql->orderBy('parents.`level_depth` ASC');

        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $parts = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $parts[] = (string) $row['name'];
        }

        return implode(' > ', $parts);
    }

    private function addIssue($idProduct, $reference, $severity, $message, $name = '')
    {
        $this->issues[] = [
            'id_product' => (int) $idProduct,
            'reference' => (string) $reference,
            'severity' => $severity,
            'message' => $message,
            'name' => (string) $name,
        ];
    }

    private function cdata($value)
    {
        return '<![CDATA[' . str_replace(']]>', ']]&gt;', (string) $value) . ']]>';
    }

    private function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
