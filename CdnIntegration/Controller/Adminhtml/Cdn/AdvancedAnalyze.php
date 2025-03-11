<?php
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Cdn;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\AdvancedUrlAnalyzer;

class AdvancedAnalyze extends Action
{
    /**
     * Authorization level
     */
    const ADMIN_RESOURCE = 'MagoArab_CdnIntegration::config';

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var Helper
     */
    protected $helper;
    
    /**
     * @var AdvancedUrlAnalyzer
     */
    protected $urlAnalyzer;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Helper $helper
     * @param AdvancedUrlAnalyzer $urlAnalyzer
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Helper $helper,
        AdvancedUrlAnalyzer $urlAnalyzer
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
        $this->urlAnalyzer = $urlAnalyzer;
    }

    /**
     * Advanced analyze URLs
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        
        try {
            if (!$this->helper->isEnabled()) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('CDN Integration is disabled.')
                ]);
            }
            
            $storeUrl = $this->getRequest()->getParam('store_url');
            $maxPages = (int)$this->getRequest()->getParam('max_pages', 5);
            $includeProductImages = (bool)$this->getRequest()->getParam('include_products', true);
            
            if (empty($storeUrl)) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('Store URL is required.')
                ]);
            }
            
            $this->helper->log("Starting enhanced URL analysis for: {$storeUrl}", 'info');
            $this->helper->log("Max pages: {$maxPages}, Include product images: " . ($includeProductImages ? 'Yes' : 'No'), 'info');
            
            // Execute the advanced analysis with product image support
            $urls = $this->urlAnalyzer->analyze($storeUrl, $maxPages, $includeProductImages);
            
            if (empty($urls)) {
                $this->helper->log("No URLs found in advanced analysis", 'warning');
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('No suitable URLs found to analyze.')
                ]);
            }
            
            $this->helper->log("Enhanced analysis complete, found " . count($urls) . " URLs", 'info');
            
            $successMessage = __('Enhanced analysis found %1 unique static and media URLs.', count($urls));
            if ($includeProductImages) {
                $successMessage = __('Enhanced analysis found %1 unique static and media URLs, including product images.', count($urls));
            }
            
            $this->messageManager->addSuccessMessage($successMessage);
            
            return $resultJson->setData([
                'success' => true,
                'urls' => $urls,
                'message' => __('URL analysis completed with %1 URLs found.', count($urls))
            ]);
        } catch (\Exception $e) {
            $this->helper->log('Error in AdvancedAnalyze::execute: ' . $e->getMessage(), 'error');
            $this->messageManager->addExceptionMessage($e, __('An error occurred during enhanced URL analysis.'));
            
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}