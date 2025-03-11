<?php
namespace MagoArab\CdnIntegration\Block;

use Magento\Framework\View\Element\Template;
use MagoArab\CdnIntegration\Helper\Data as Helper;

class CdnConfig extends Template
{
    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @param Template\Context $context
     * @param Helper $helper
     * @param array $data
     */
    public function __construct(
        Template\Context $context,
        Helper $helper,
        array $data = []
    ) {
        $this->helper = $helper;
        parent::__construct($context, $data);
    }

    /**
     * Get CDN configuration for JS
     *
     * @return array
     */
    public function getConfig()
    {
        return [
            'enabled' => $this->helper->isEnabled(),
            'cdnBaseUrl' => $this->helper->getCdnBaseUrl(),
            'customUrls' => $this->helper->getCustomUrls()
        ];
    }
}