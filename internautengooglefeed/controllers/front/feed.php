<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class InternautenGoogleFeedFeedModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /** @var bool Kein Theme-Rendering, der Feed wird direkt ausgegeben. */
    public $content_only = true;

    public function initContent()
    {
        /** @var InternautenGoogleFeed $module */
        $module = $this->module;

        if (!$module->isValidFeedToken((string) Tools::getValue('token'))) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow', true);
            echo 'Forbidden';
            exit;
        }

        $builder = $module->createFeedBuilder($this->context);
        $items = $builder->buildItems();

        $xml = $builder->renderXml(
            $items,
            (string) Configuration::get('PS_SHOP_NAME'),
            $this->context->link->getBaseLink((int) $this->context->shop->id),
            (string) Configuration::get('PS_SHOP_NAME')
        );

        header('HTTP/1.1 200 OK');
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Length: ' . strlen($xml));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Robots-Tag: noindex, nofollow', true);
        echo $xml;
        exit;
    }
}
