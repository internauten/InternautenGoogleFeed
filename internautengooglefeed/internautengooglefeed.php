<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/InternautenGoogleFeedBuilder.php';

class InternautenGoogleFeed extends Module
{
    public const CONF_TOKEN = 'IGF_FEED_TOKEN';
    public const CONF_EXCLUDED_CATEGORIES = 'IGF_EXCLUDED_CATEGORIES';
    public const CONF_INCLUDE_OUT_OF_STOCK = 'IGF_INCLUDE_OUT_OF_STOCK';
    public const CONF_USE_COMBINATIONS = 'IGF_USE_COMBINATIONS';
    public const CONF_IDENTIFIER_PREFIX = 'IGF_IDENTIFIER_PREFIX';
    public const CONF_IMAGE_TYPE = 'IGF_IMAGE_TYPE';
    public const CONF_CHECK_LIMIT = 'IGF_CHECK_LIMIT';
    public const CONF_NORMALIZE_TITLE_CASE = 'IGF_NORMALIZE_TITLE_CASE';
    public const CONF_TITLE_CASE_MIN_LENGTH = 'IGF_TITLE_CASE_MIN_LENGTH';
    public const CONF_KEEP_UPPERCASE_WORDS = 'IGF_KEEP_UPPERCASE_WORDS';
    public const CONF_PROTECT_BRAND_WORDS = 'IGF_PROTECT_BRAND_WORDS';

    private const DEFAULT_CHECK_LIMIT = 0;
    private const DEFAULT_TITLE_CASE_MIN_LENGTH = 4;
    private const MIN_TITLE_CASE_MIN_LENGTH = 2;
    private const MAX_TITLE_CASE_MIN_LENGTH = 30;
    private const DEFAULT_KEEP_UPPERCASE_WORDS = 'USB, HDMI, LED, LCD, OLED, GPS, WLAN, WIFI, USV, ABS, PVC, INOX, XXL, XXXL, MwSt';
    private const REPORT_MAX_ROWS = 500;

    public function __construct()
    {
        $this->name = 'internautengooglefeed';
        $this->tab = 'seo';
        $this->version = '1.0.0';
        $this->author = 'die.internauten.ch GmbH';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Internauten Google Feed');
        $this->description = $this->l('Stellt alle gueltigen Shop-Produkte als tokengeschuetzten Feed fuer Google Merchant bereit.');
        $this->ps_versions_compliancy = [
            'min' => '1.7.8.0',
            'max' => _PS_VERSION_,
        ];
    }

    public function install()
    {
        return parent::install()
            && Configuration::updateValue(self::CONF_TOKEN, $this->generateToken())
            && Configuration::updateValue(self::CONF_EXCLUDED_CATEGORIES, '')
            && Configuration::updateValue(self::CONF_INCLUDE_OUT_OF_STOCK, 0)
            && Configuration::updateValue(self::CONF_USE_COMBINATIONS, 1)
            && Configuration::updateValue(self::CONF_IDENTIFIER_PREFIX, '')
            && Configuration::updateValue(self::CONF_IMAGE_TYPE, '')
            && Configuration::updateValue(self::CONF_CHECK_LIMIT, self::DEFAULT_CHECK_LIMIT)
            && Configuration::updateValue(self::CONF_NORMALIZE_TITLE_CASE, 1)
            && Configuration::updateValue(self::CONF_TITLE_CASE_MIN_LENGTH, self::DEFAULT_TITLE_CASE_MIN_LENGTH)
            && Configuration::updateValue(self::CONF_KEEP_UPPERCASE_WORDS, self::DEFAULT_KEEP_UPPERCASE_WORDS)
            && Configuration::updateValue(self::CONF_PROTECT_BRAND_WORDS, 1);
    }

    public function uninstall()
    {
        foreach ([
            self::CONF_TOKEN,
            self::CONF_EXCLUDED_CATEGORIES,
            self::CONF_INCLUDE_OUT_OF_STOCK,
            self::CONF_USE_COMBINATIONS,
            self::CONF_IDENTIFIER_PREFIX,
            self::CONF_IMAGE_TYPE,
            self::CONF_CHECK_LIMIT,
            self::CONF_NORMALIZE_TITLE_CASE,
            self::CONF_TITLE_CASE_MIN_LENGTH,
            self::CONF_KEEP_UPPERCASE_WORDS,
            self::CONF_PROTECT_BRAND_WORDS,
        ] as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitInternautenGoogleFeedConfig')) {
            $output .= $this->postProcessConfiguration();
        }

        if (Tools::isSubmit('submitInternautenGoogleFeedToken')) {
            Configuration::updateValue(self::CONF_TOKEN, $this->generateToken());
            $output .= $this->displayConfirmation($this->l('Neues Token wurde erzeugt.'));
        }

        $report = '';
        if (Tools::isSubmit('submitInternautenGoogleFeedCheck')) {
            $report = $this->renderCheckReport();
        }

        return $output . $this->renderInfoPanel() . $this->renderForm() . $report;
    }

    private function postProcessConfiguration()
    {
        $token = trim((string) Tools::getValue(self::CONF_TOKEN));

        if ($token === '') {
            return $this->displayError($this->l('Das Token darf nicht leer sein.'));
        }

        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token)) {
            return $this->displayError($this->l('Das Token darf nur Buchstaben, Zahlen, "-" und "_" enthalten (8 bis 64 Zeichen).'));
        }

        $prefix = trim((string) Tools::getValue(self::CONF_IDENTIFIER_PREFIX));
        if ($prefix !== '' && !preg_match('/^[A-Za-z0-9_-]{1,20}$/', $prefix)) {
            return $this->displayError($this->l('Das Artikelnummer-Praefix darf nur Buchstaben, Zahlen, "-" und "_" enthalten (max. 20 Zeichen).'));
        }

        $checkLimit = (int) Tools::getValue(self::CONF_CHECK_LIMIT);
        if ($checkLimit < 0) {
            return $this->displayError($this->l('Das Pruef-Limit muss 0 oder groesser sein.'));
        }

        $titleCaseMinLength = (int) Tools::getValue(self::CONF_TITLE_CASE_MIN_LENGTH);
        if ($titleCaseMinLength < self::MIN_TITLE_CASE_MIN_LENGTH || $titleCaseMinLength > self::MAX_TITLE_CASE_MIN_LENGTH) {
            return $this->displayError(sprintf(
                $this->l('Die Mindestlaenge fuer die Korrektur muss zwischen %d und %d liegen.'),
                self::MIN_TITLE_CASE_MIN_LENGTH,
                self::MAX_TITLE_CASE_MIN_LENGTH
            ));
        }

        $imageType = (string) Tools::getValue(self::CONF_IMAGE_TYPE);
        if ($imageType !== '' && !in_array($imageType, $this->getAvailableImageTypeNames(), true)) {
            return $this->displayError($this->l('Der gewaehlte Bildtyp existiert nicht.'));
        }

        $excluded = [];
        foreach ((array) Tools::getValue(self::CONF_EXCLUDED_CATEGORIES, []) as $idCategory) {
            $idCategory = (int) $idCategory;
            if ($idCategory > 0) {
                $excluded[$idCategory] = $idCategory;
            }
        }

        Configuration::updateValue(self::CONF_TOKEN, $token);
        Configuration::updateValue(self::CONF_EXCLUDED_CATEGORIES, implode(',', $excluded));
        Configuration::updateValue(self::CONF_INCLUDE_OUT_OF_STOCK, (int) (bool) Tools::getValue(self::CONF_INCLUDE_OUT_OF_STOCK));
        Configuration::updateValue(self::CONF_USE_COMBINATIONS, (int) (bool) Tools::getValue(self::CONF_USE_COMBINATIONS));
        Configuration::updateValue(self::CONF_IDENTIFIER_PREFIX, $prefix);
        Configuration::updateValue(self::CONF_IMAGE_TYPE, $imageType);
        Configuration::updateValue(self::CONF_CHECK_LIMIT, $checkLimit);
        Configuration::updateValue(self::CONF_NORMALIZE_TITLE_CASE, (int) (bool) Tools::getValue(self::CONF_NORMALIZE_TITLE_CASE));
        Configuration::updateValue(self::CONF_TITLE_CASE_MIN_LENGTH, $titleCaseMinLength);
        Configuration::updateValue(
            self::CONF_KEEP_UPPERCASE_WORDS,
            implode(', ', $this->getKeepUppercaseWords((string) Tools::getValue(self::CONF_KEEP_UPPERCASE_WORDS)))
        );
        Configuration::updateValue(self::CONF_PROTECT_BRAND_WORDS, (int) (bool) Tools::getValue(self::CONF_PROTECT_BRAND_WORDS));

        return $this->displayConfirmation($this->l('Einstellungen wurden gespeichert.'));
    }

    /**
     * @return array<int, int>
     */
    public function getExcludedCategoryIds()
    {
        $raw = (string) Configuration::get(self::CONF_EXCLUDED_CATEGORIES);
        $ids = [];

        foreach (explode(',', $raw) as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Erzeugt den Feed-Builder mit den aktuellen BO-Einstellungen.
     *
     * @return InternautenGoogleFeedBuilder
     */
    public function createFeedBuilder(Context $context)
    {
        return new InternautenGoogleFeedBuilder([
            'id_shop' => (int) $context->shop->id,
            'id_lang' => (int) $context->language->id,
            'currency' => $context->currency,
            'link' => $context->link,
            'excluded_categories' => $this->getExcludedCategoryIds(),
            'include_out_of_stock' => (bool) Configuration::get(self::CONF_INCLUDE_OUT_OF_STOCK),
            'use_combinations' => (bool) Configuration::get(self::CONF_USE_COMBINATIONS),
            'identifier_prefix' => (string) Configuration::get(self::CONF_IDENTIFIER_PREFIX),
            'image_type' => (string) Configuration::get(self::CONF_IMAGE_TYPE),
            'normalize_title_case' => $this->isTitleCaseNormalizationEnabled(),
            'title_case_min_length' => $this->getTitleCaseMinLength(),
            'keep_uppercase_words' => $this->getKeepUppercaseWords(),
            'protect_brand_words' => $this->isBrandWordProtectionEnabled(),
        ]);
    }

    /**
     * Fehlt der Schluessel (Modul vor Einfuehrung der Option installiert), gilt der Standard "aktiv".
     */
    public function isBrandWordProtectionEnabled()
    {
        if (!Configuration::hasKey(self::CONF_PROTECT_BRAND_WORDS)) {
            return true;
        }

        return (bool) Configuration::get(self::CONF_PROTECT_BRAND_WORDS);
    }

    /**
     * Fehlt der Schluessel (Modul vor Einfuehrung der Option installiert), gilt der Standard "aktiv".
     */
    public function isTitleCaseNormalizationEnabled()
    {
        if (!Configuration::hasKey(self::CONF_NORMALIZE_TITLE_CASE)) {
            return true;
        }

        return (bool) Configuration::get(self::CONF_NORMALIZE_TITLE_CASE);
    }

    public function getTitleCaseMinLength()
    {
        $length = (int) Configuration::get(self::CONF_TITLE_CASE_MIN_LENGTH);

        if ($length < self::MIN_TITLE_CASE_MIN_LENGTH || $length > self::MAX_TITLE_CASE_MIN_LENGTH) {
            return self::DEFAULT_TITLE_CASE_MIN_LENGTH;
        }

        return $length;
    }

    /**
     * @return array<int, string>
     */
    public function getKeepUppercaseWords($raw = null)
    {
        if ($raw === null) {
            $raw = Configuration::hasKey(self::CONF_KEEP_UPPERCASE_WORDS)
                ? (string) Configuration::get(self::CONF_KEEP_UPPERCASE_WORDS)
                : self::DEFAULT_KEEP_UPPERCASE_WORDS;
        }

        $words = [];

        foreach (preg_split('/[\s,;]+/u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $word = trim($word);
            if ($word !== '') {
                $words[Tools::strtolower($word)] = $word;
            }
        }

        return array_values($words);
    }

    public function isValidFeedToken($token)
    {
        $expected = (string) Configuration::get(self::CONF_TOKEN);

        if ($expected === '' || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    private function renderInfoPanel()
    {
        $feedUrl = $this->getFeedUrl();

        $html = '<div class="panel">';
        $html .= '<div class="panel-heading"><i class="icon-rss"></i> ' . $this->l('Feed-URL') . '</div>';
        $html .= '<p>' . $this->l('Diese URL im Google Merchant Center als geplanten Abruf hinterlegen:') . '</p>';
        $html .= '<p><code>' . htmlspecialchars($feedUrl, ENT_QUOTES, 'UTF-8') . '</code></p>';
        $html .= '<p class="help-block">' . $this->l('Ohne oder mit falschem Token liefert die URL HTTP 403.') . '</p>';
        $html .= '</div>';

        return $html;
    }

    private function getFeedUrl()
    {
        return $this->context->link->getModuleLink(
            $this->name,
            'feed',
            ['token' => (string) Configuration::get(self::CONF_TOKEN)],
            true,
            (int) $this->context->language->id,
            (int) $this->context->shop->id
        );
    }

    private function renderForm()
    {
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Einstellungen'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Feed-Token'),
                        'name' => self::CONF_TOKEN,
                        'required' => true,
                        'desc' => $this->l('Nur Aufrufe mit diesem Token erhalten den Feed. Erlaubt: Buchstaben, Zahlen, "-" und "_" (8 bis 64 Zeichen).'),
                    ],
                    [
                        'type' => 'categories',
                        'label' => $this->l('Ausgeschlossene Kategorien'),
                        'name' => self::CONF_EXCLUDED_CATEGORIES,
                        'desc' => $this->l('Produkte, die einer dieser Kategorien zugeordnet sind, werden nicht uebertragen.'),
                        'tree' => [
                            'id' => 'igf-excluded-categories',
                            'title' => $this->l('Kategorien vom Feed ausschliessen'),
                            'selected_categories' => $this->getExcludedCategoryIds(),
                            'root_category' => (int) $this->context->shop->getCategory(),
                            'use_checkbox' => true,
                            'use_search' => true,
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Kombinationen einzeln ausgeben'),
                        'name' => self::CONF_USE_COMBINATIONS,
                        'is_bool' => true,
                        'desc' => $this->l('Jede Kombination wird als eigenes Item mit item_group_id ausgegeben.'),
                        'values' => [
                            ['id' => 'combinations_on', 'value' => 1, 'label' => $this->l('Ja')],
                            ['id' => 'combinations_off', 'value' => 0, 'label' => $this->l('Nein')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Nicht lieferbare Produkte mitliefern'),
                        'name' => self::CONF_INCLUDE_OUT_OF_STOCK,
                        'is_bool' => true,
                        'desc' => $this->l('Wenn aktiv, werden ausverkaufte Produkte mit availability "out_of_stock" uebertragen.'),
                        'values' => [
                            ['id' => 'oos_on', 'value' => 1, 'label' => $this->l('Ja')],
                            ['id' => 'oos_off', 'value' => 0, 'label' => $this->l('Nein')],
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Bildformat'),
                        'name' => self::CONF_IMAGE_TYPE,
                        'options' => [
                            'query' => $this->getImageTypeOptions(),
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Bildgroesse, die im Feed verlinkt wird.'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Grossschreibung in Titel und Beschreibung korrigieren'),
                        'name' => self::CONF_NORMALIZE_TITLE_CASE,
                        'is_bool' => true,
                        'desc' => $this->l('Google Merchant wertet durchgehend grossgeschriebene Woerter als Werbetext und kann Artikel ablehnen. Aus "SOMMER AKTION" wird "Sommer Aktion".'),
                        'values' => [
                            ['id' => 'title_case_on', 'value' => 1, 'label' => $this->l('Ja')],
                            ['id' => 'title_case_off', 'value' => 0, 'label' => $this->l('Nein')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Mindestlaenge fuer die Korrektur'),
                        'name' => self::CONF_TITLE_CASE_MIN_LENGTH,
                        'desc' => sprintf(
                            $this->l('Nur Woerter ab dieser Buchstabenzahl werden korrigiert, damit Kuerzel wie "XL" oder "LED" erhalten bleiben. Woerter des Herstellernamens bleiben immer unveraendert. Erlaubt: %d bis %d.'),
                            self::MIN_TITLE_CASE_MIN_LENGTH,
                            self::MAX_TITLE_CASE_MIN_LENGTH
                        ),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Abkuerzungen in Grossschreibung behalten'),
                        'name' => self::CONF_KEEP_UPPERCASE_WORDS,
                        'cols' => 60,
                        'rows' => 3,
                        'desc' => $this->l('Durch Komma oder Leerzeichen getrennt, z. B. "USB, HDMI, INOX". Diese Woerter bleiben in Titel und Beschreibung unveraendert.'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Herstellernamen von der Korrektur ausnehmen'),
                        'name' => self::CONF_PROTECT_BRAND_WORDS,
                        'is_bool' => true,
                        'desc' => $this->l('Wenn aktiv, bleiben Woerter des Herstellernamens grossgeschrieben, z. B. "HUGO BOSS". Abschalten, wenn Herstellernamen Gattungsbegriffe wie "Gutschein" enthalten und dadurch faelschlich geschuetzt werden.'),
                        'values' => [
                            ['id' => 'brand_protect_on', 'value' => 1, 'label' => $this->l('Ja')],
                            ['id' => 'brand_protect_off', 'value' => 0, 'label' => $this->l('Nein')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Praefix fuer Artikelnummern'),
                        'name' => self::CONF_IDENTIFIER_PREFIX,
                        'desc' => $this->l('Optional. Wird der Feed-ID vorangestellt, z. B. "shop-".'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Limit fuer die Pruefung'),
                        'name' => self::CONF_CHECK_LIMIT,
                        'desc' => $this->l('Maximale Anzahl geprueter Items. 0 bedeutet: alle Produkte pruefen.'),
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Einstellungen speichern'),
                    'name' => 'submitInternautenGoogleFeedConfig',
                ],
                'buttons' => [
                    [
                        'title' => $this->l('Feed pruefen'),
                        'name' => 'submitInternautenGoogleFeedCheck',
                        'type' => 'submit',
                        'class' => 'btn btn-default pull-right',
                        'icon' => 'process-icon-refresh',
                    ],
                    [
                        'title' => $this->l('Neues Token erzeugen'),
                        'name' => 'submitInternautenGoogleFeedToken',
                        'type' => 'submit',
                        'class' => 'btn btn-default pull-right',
                        'icon' => 'process-icon-refresh',
                    ],
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->title = $this->displayName;
        $helper->submit_action = 'submitInternautenGoogleFeedConfig';
        $helper->fields_value = [
            self::CONF_TOKEN => (string) Configuration::get(self::CONF_TOKEN),
            self::CONF_INCLUDE_OUT_OF_STOCK => (int) Configuration::get(self::CONF_INCLUDE_OUT_OF_STOCK),
            self::CONF_USE_COMBINATIONS => (int) Configuration::get(self::CONF_USE_COMBINATIONS),
            self::CONF_IDENTIFIER_PREFIX => (string) Configuration::get(self::CONF_IDENTIFIER_PREFIX),
            self::CONF_IMAGE_TYPE => (string) Configuration::get(self::CONF_IMAGE_TYPE),
            self::CONF_CHECK_LIMIT => (int) Configuration::get(self::CONF_CHECK_LIMIT),
            self::CONF_NORMALIZE_TITLE_CASE => (int) $this->isTitleCaseNormalizationEnabled(),
            self::CONF_TITLE_CASE_MIN_LENGTH => $this->getTitleCaseMinLength(),
            self::CONF_KEEP_UPPERCASE_WORDS => implode(', ', $this->getKeepUppercaseWords()),
            self::CONF_PROTECT_BRAND_WORDS => (int) $this->isBrandWordProtectionEnabled(),
        ];

        if (version_compare(_PS_VERSION_, '9.0.0', '>=')) {
            // PS9 validiert den Symfony-CSRF-Token; das Formular postet daher auf die aktuelle URL zurueck.
            $fieldsForm['form']['input'][] = ['type' => 'hidden', 'name' => '_token'];
            $fieldsForm['form']['input'][] = ['type' => 'hidden', 'name' => 'token'];
            $helper->token = false;
            $helper->currentIndex = '';
            $helper->back_url = '';
            $helper->fields_value['_token'] = (string) Tools::getValue('_token', '');
            $helper->fields_value['token'] = Tools::getAdminTokenLite('AdminModules');
        } else {
            $helper->token = Tools::getAdminTokenLite('AdminModules');
            $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
            $helper->back_url = '';
        }

        return $helper->generateForm([$fieldsForm]);
    }

    private function renderCheckReport()
    {
        $builder = $this->createFeedBuilder($this->context);
        $items = $builder->buildItems((int) Configuration::get(self::CONF_CHECK_LIMIT));
        $issues = $builder->getIssues();

        $errors = 0;
        $warnings = 0;
        foreach ($issues as $issue) {
            if ($issue['severity'] === InternautenGoogleFeedBuilder::SEVERITY_ERROR) {
                ++$errors;
            } else {
                ++$warnings;
            }
        }

        $this->context->smarty->assign([
            'igf_item_count' => count($items),
            'igf_skipped_count' => $builder->getSkippedCount(),
            'igf_error_count' => $errors,
            'igf_warning_count' => $warnings,
            'igf_issues' => array_slice($issues, 0, self::REPORT_MAX_ROWS),
            'igf_issues_truncated' => count($issues) > self::REPORT_MAX_ROWS,
            'igf_report_max_rows' => self::REPORT_MAX_ROWS,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/report.tpl');
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function getImageTypeOptions()
    {
        $options = [
            [
                'id_option' => '',
                'name' => $this->l('Originalbild (ohne Groessenangabe)'),
            ],
        ];

        foreach (ImageType::getImagesTypes('products', true) as $imageType) {
            $options[] = [
                'id_option' => (string) $imageType['name'],
                'name' => sprintf('%s (%dx%d)', $imageType['name'], (int) $imageType['width'], (int) $imageType['height']),
            ];
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function getAvailableImageTypeNames()
    {
        $names = [];

        foreach (ImageType::getImagesTypes('products', true) as $imageType) {
            $names[] = (string) $imageType['name'];
        }

        return $names;
    }

    private function generateToken()
    {
        return bin2hex(random_bytes(16));
    }
}
