<?php
namespace MagoArab\CdnIntegration\Controller\Adminhtml\Cdn;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MagoArab\CdnIntegration\Helper\Data as Helper;
use MagoArab\CdnIntegration\Model\Github\Api as GithubApi;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Helper\Image as ImageHelper;

class AnalyzeUrls extends Action
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
     * @var GithubApi
     */
    protected $githubApi;
    
    /**
     * @var Filesystem
     */
    protected $filesystem;
    
    /**
     * @var ProductCollectionFactory
     */
    protected $productCollectionFactory;
    
    /**
     * @var ImageHelper
     */
    protected $imageHelper;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param Helper $helper
     * @param GithubApi $githubApi
     * @param Filesystem $filesystem
     * @param ProductCollectionFactory $productCollectionFactory
     * @param ImageHelper $imageHelper
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        Helper $helper,
        GithubApi $githubApi = null,
        Filesystem $filesystem = null,
        ProductCollectionFactory $productCollectionFactory = null,
        ImageHelper $imageHelper = null
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
        $this->githubApi = $githubApi;
        $this->filesystem = $filesystem;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->imageHelper = $imageHelper;
    }

    /**
     * Analyze URLs from storefront page or upload to GitHub based on parameter
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
            
            // Check if this is an upload request
            $isUpload = $this->getRequest()->getParam('upload');
            
            if ($isUpload) {
                try {
                    return $this->processUpload();
                } catch (\Exception $e) {
                    $this->helper->log('Error in uploadToGithub: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 'error');
                    return $resultJson->setData([
                        'success' => false,
                        'message' => 'Error in upload process: ' . $e->getMessage()
                    ]);
                }
            }
            
            // Normal analyze flow
            $storeUrl = $this->getRequest()->getParam('store_url');
            if (empty($storeUrl)) {
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('Store URL is required.')
                ]);
            }
            
            $this->helper->log("Starting URL analysis for: {$storeUrl}", 'info');
            
            // Fetch homepage content to analyze
            $content = $this->fetchUrl($storeUrl);
            if (empty($content)) {
                $this->helper->log("Failed to fetch store homepage: {$storeUrl}", 'error');
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('Failed to fetch store homepage. Please check the URL.')
                ]);
            }
            
            $this->helper->log("Successfully fetched homepage content, length: " . strlen($content), 'info');
            
            // Extract URLs from homepage content
            $urls = $this->extractUrls($content);
            
            // If homepage analysis didn't find any URLs, try adding product images
            if (empty($urls) && $this->productCollectionFactory !== null && $this->imageHelper !== null) {
                $this->helper->log("No URLs found from homepage, attempting to analyze product images", 'info');
                $urls = $this->getProductImageUrls();
            }
            
            if (empty($urls)) {
                $this->helper->log("No suitable URLs found after analysis", 'warning');
                return $resultJson->setData([
                    'success' => false,
                    'message' => __('No suitable URLs found to analyze. Please try using Deep Analyze instead.')
                ]);
            }
            
            $this->helper->log("Analysis complete, found " . count($urls) . " URLs", 'info');
            
            $this->messageManager->addSuccessMessage(
                __('Found %1 URLs to analyze.', count($urls))
            );
            
            return $resultJson->setData([
                'success' => true,
                'urls' => $urls,
                'message' => __('URL analysis completed.')
            ]);
        } catch (\Exception $e) {
            $this->helper->log('Error in AnalyzeUrls::execute: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 'error');
            $this->messageManager->addExceptionMessage($e, __('An error occurred while analyzing URLs.'));
            
            return $resultJson->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get product image URLs as fallback
     *
     * @param int $limit
     * @return array
     */
    protected function getProductImageUrls($limit = 20)
    {
        $urls = [];
        
        try {
            // Create product collection
            $collection = $this->productCollectionFactory->create();
            $collection->addAttributeToSelect(['image', 'small_image', 'thumbnail']);
            $collection->setPageSize($limit);
            
            foreach ($collection as $product) {
                // Get main product image URL
                if ($product->getImage()) {
                    $imageUrl = $this->imageHelper->init($product, 'product_page_image_large')
                        ->setImageFile($product->getImage())
                        ->getUrl();
                        
                    $parsedUrl = parse_url($imageUrl);
                    if (isset($parsedUrl['path'])) {
                        $path = $parsedUrl['path'];
                        if (strpos($path, '/media/') === 0) {
                            $urls[] = $path;
                        }
                    }
                }
                
                // Get small image URL
                if ($product->getSmallImage()) {
                    $smallImageUrl = $this->imageHelper->init($product, 'product_page_image_small')
                        ->setImageFile($product->getSmallImage())
                        ->getUrl();
                        
                    $parsedUrl = parse_url($smallImageUrl);
                    if (isset($parsedUrl['path'])) {
                        $path = $parsedUrl['path'];
                        if (strpos($path, '/media/') === 0 && !in_array($path, $urls)) {
                            $urls[] = $path;
                        }
                    }
                }
                
                // Get thumbnail URL
                if ($product->getThumbnail()) {
                    $thumbnailUrl = $this->imageHelper->init($product, 'product_thumbnail_image')
                        ->setImageFile($product->getThumbnail())
                        ->getUrl();
                        
                    $parsedUrl = parse_url($thumbnailUrl);
                    if (isset($parsedUrl['path'])) {
                        $path = $parsedUrl['path'];
                        if (strpos($path, '/media/') === 0 && !in_array($path, $urls)) {
                            $urls[] = $path;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->helper->log("Error getting product image URLs: " . $e->getMessage(), 'error');
        }
        
        return $urls;
    }
    
    /**
     * Process the upload request
     * 
     * @return \Magento\Framework\Controller\Result\Json
     */
    private function processUpload()
    {
        $resultJson = $this->resultJsonFactory->create();
        
        // Check for required dependencies
        if ($this->githubApi === null) {
            $this->helper->log('GithubApi dependency is missing', 'error');
            return $resultJson->setData([
                'success' => false,
                'message' => __('GitHub API service is not available. Please check your module configuration.')
            ]);
        }
        
        if ($this->filesystem === null) {
            $this->helper->log('Filesystem dependency is missing', 'error');
            return $resultJson->setData([
                'success' => false,
                'message' => __('Filesystem service is not available. Please check your module configuration.')
            ]);
        }
        
        $urls = $this->getRequest()->getParam('urls');
        $this->helper->log('Received URLs for upload: ' . $urls, 'info');
        
        if (empty($urls)) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('No URLs provided for upload.')
            ]);
        }
        
        // Decode URLs
        $urls = json_decode($urls, true);
        if (!is_array($urls)) {
            return $resultJson->setData([
                'success' => false,
                'message' => __('Invalid URL format.')
            ]);
        }
        
        // Get file system directories
        try {
            $staticDir = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW)->getAbsolutePath();
            $mediaDir = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
            
            $this->helper->log("Static directory: {$staticDir}", 'debug');
            $this->helper->log("Media directory: {$mediaDir}", 'debug');
        } catch (\Exception $e) {
            $this->helper->log('Error getting directories: ' . $e->getMessage(), 'error');
            return $resultJson->setData([
                'success' => false,
                'message' => __('Error accessing file system: %1', $e->getMessage())
            ]);
        }
        
        // Initialize results
        $results = [
            'total' => count($urls),
            'success' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        // Process each URL
        foreach ($urls as $url) {
            $this->helper->log("Processing URL: {$url}", 'debug');
            
            try {
                // Determine local file path
                $localPath = '';
                $remotePath = '';
                
                if (strpos($url, '/static/') === 0) {
                    $path = substr($url, 8); // Remove '/static/'
                    $localPath = $staticDir . $path;
                    $remotePath = $path;
                } elseif (strpos($url, '/media/') === 0) {
                    $path = substr($url, 7); // Remove '/media/'
                    $localPath = $mediaDir . $path;
                    $remotePath = $path;
                } else {
                    // Skip unsupported URLs
                    $results['failed']++;
                    $results['details'][] = [
                        'url' => $url,
                        'success' => false,
                        'message' => __('Unsupported URL format.')
                    ];
                    continue;
                }
                
                $this->helper->log("Local path: {$localPath}", 'debug');
                $this->helper->log("Remote path: {$remotePath}", 'debug');
                
                // Check if file exists
                if (!file_exists($localPath)) {
                    $this->helper->log("File not found: {$localPath}", 'error');
                    $results['failed']++;
                    $results['details'][] = [
                        'url' => $url,
                        'success' => false,
                        'message' => __('File not found: %1', $localPath)
                    ];
                    continue;
                }
                
                // Upload file to GitHub
                $success = $this->githubApi->uploadFile($localPath, $remotePath);
                
                if ($success) {
                    $this->helper->log("Successfully uploaded {$url} to GitHub", 'info');
                    $results['success']++;
                    $results['details'][] = [
                        'url' => $url,
                        'success' => true,
                        'message' => __('Successfully uploaded to GitHub')
                    ];
                } else {
                    $this->helper->log("Failed to upload {$url} to GitHub", 'error');
                    $results['failed']++;
                    $results['details'][] = [
                        'url' => $url,
                        'success' => false,
                        'message' => __('Failed to upload to GitHub')
                    ];
                }
            } catch (\Exception $e) {
                $this->helper->log('Exception processing URL ' . $url . ': ' . $e->getMessage(), 'error');
                $results['failed']++;
                $results['details'][] = [
                    'url' => $url,
                    'success' => false,
                    'message' => $e->getMessage()
                ];
            }
        }
        
        // Create success or failure message
        if ($results['failed'] > 0) {
            $message = __('Upload completed with issues: %1 successful, %2 failed, %3 total.', 
                $results['success'], 
                $results['failed'], 
                $results['total']
            );
        } else {
            $message = __('All %1 files were successfully uploaded to GitHub.', $results['success']);
        }
        
        $this->messageManager->addSuccessMessage($message);
        
        return $resultJson->setData([
            'success' => true,
            'results' => $results,
            'message' => $message
        ]);
    }
    
    /**
     * Fetch URL content using cURL
     *
     * @param string $url
     * @return string
     */
    protected function fetchUrl($url)
    {
        try {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curl, CURLOPT_MAXREDIRS, 5);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
            curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($curl, CURLOPT_ENCODING, ''); // Accept all encodings
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification for development
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false); // Disable SSL verification for development
            
            $response = curl_exec($curl);
            $error = curl_error($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            if ($error) {
                $this->helper->log("cURL Error: {$error}", 'error');
                return '';
            }
            
            if ($httpCode >= 400) {
                $this->helper->log("HTTP Error: {$httpCode} when fetching {$url}", 'error');
                return '';
            }
            
            if (empty($response)) {
                $this->helper->log("Empty response from {$url}", 'warning');
            }
            
            return $response;
        } catch (\Exception $e) {
            $this->helper->log("Exception in fetchUrl: " . $e->getMessage(), 'error');
            return '';
        }
    }
    
    /**
     * Extract URLs from HTML content
     *
     * @param string $content
     * @return array
     */
    protected function extractUrls($content)
    {
        $urls = [];
        
        // Search for CSS and JS files with more comprehensive patterns
        $patterns = [
            // CSS links - more flexible pattern
            '/<link[^>]*href=[\'"]([^\'"]+\.css(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Script sources - more flexible pattern
            '/<script[^>]*src=[\'"]([^\'"]+\.js(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Script with data-requiremodule
            '/<script[^>]*data-requiremodule=[\'"]([^\'"]+)[\'"][^>]*>/i',
            
            // Images with all common extensions
            '/<img[^>]*src=[\'"]([^\'"]+\.(png|jpg|jpeg|gif|svg|webp)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Font files
            '/url\([\'"]?([^\'"]+\.(woff|woff2|ttf|eot|otf)(?:\?[^\'"]*)?)[\'"]?\)/i',
            
            // SVGs in various positions
            '/<[^>]*?(?:href|src)=[\'"]([^\'"]+\.svg(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Video files
            '/<(?:source|video)[^>]*src=[\'"]([^\'"]+\.(mp4|webm|ogg)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Audio files
            '/<(?:source|audio)[^>]*src=[\'"]([^\'"]+\.(mp3|wav|ogg)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Data attributes with URLs
            '/ data-[^=]*=[\'"]([^\'"]+\.(js|css|png|jpg|jpeg|gif|svg|webp)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // RequireJS text plugin
            '/text!([\'"])?([^\'"!]+)([\'"])?/i',
            
            // Look for JavaScript arrays containing URLs in JSON format
            '/"(\/static\/[^"]+\.(js|css|png|jpg|jpeg|gif)(?:\?[^"]*)?)"/',
            '/"(\/media\/[^"]+\.(js|css|png|jpg|jpeg|gif)(?:\?[^"]*)?)"/'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                if (isset($matches[1]) && !empty($matches[1])) {
                    foreach ($matches[1] as $url) {
                        // Skip data URLs
                        if (strpos($url, 'data:') === 0) {
                            continue;
                        }
                        
                        // Normalize URL (remove domain if present)
                        if (strpos($url, 'http') === 0) {
                            $parsedUrl = parse_url($url);
                            if (isset($parsedUrl['path'])) {
                                $url = $parsedUrl['path'];
                            }
                        }
                        
                        // Keep only static and media URLs
                        if (strpos($url, '/static/') !== false || strpos($url, '/media/') !== false) {
                            $urls[] = $url;
                        }
                    }
                }
            }
        }
        
        // Specifically search for merged and minified files with improved patterns
        $mergedPatterns = [
            // Standard merged files
            '/\/static\/_cache\/merged\/[^"\')+\s]+/i',
            
            // Minified files
            '/\/static\/_cache\/minified\/[^"\')+\s]+/i',
            
            // RequireJS text plugin URLs
            '/text!(\/static\/[^!]+)/i',
            
            // RequireJS files
            '/"(\/static\/[^"]+)"/i',
            
            // Magento admin URLs
            '/\/static\/adminhtml\/[^"\']+/i',
            
            // Advanced CSS/JS bundled files - universal pattern for any theme
            '/\/static\/(?:version\d+\/)?(?:frontend|adminhtml)\/[^"\']+\.(css|js)/i',
        ];
        
        foreach ($mergedPatterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                // Use the first capture group if it exists, otherwise use the full match
                $matchGroup = !empty($matches[1]) ? $matches[1] : $matches[0];
                foreach ($matchGroup as $url) {
                    if (strpos($url, '/static/') !== false || strpos($url, '/media/') !== false) {
                        $urls[] = $url;
                    }
                }
            }
        }
        
        // Search for URLs in CSS url() with improved pattern
        if (preg_match_all('/url\([\'"]?([^\'")\s]+)[\'"]?\)/i', $content, $cssMatches)) {
            if (isset($cssMatches[1]) && !empty($cssMatches[1])) {
                foreach ($cssMatches[1] as $url) {
                    // Skip data URIs
                    if (strpos($url, 'data:') === 0) {
                        continue;
                    }
                    
                    // Normalize URL
                    if (strpos($url, 'http') === 0) {
                        $parsedUrl = parse_url($url);
                        if (isset($parsedUrl['path'])) {
                            $url = $parsedUrl['path'];
                        }
                    }
                    
                    if (strpos($url, '/static/') !== false || strpos($url, '/media/') !== false) {
                        $urls[] = $url;
                    }
                }
            }
        }
        
        // Look for JSON data structures that might contain URLs
        if (preg_match_all('/\{[^}]+\}/', $content, $jsonBlocks)) {
            foreach ($jsonBlocks[0] as $jsonBlock) {
                // Try to extract URLs from potential JSON
                if (preg_match_all('/"(\/(?:static|media)\/[^"]+\.(js|css|png|jpg|jpeg|gif|svg|webp))"/i', $jsonBlock, $jsonMatches)) {
                    foreach ($jsonMatches[1] as $url) {
                        $urls[] = $url;
                    }
                }
            }
        }
        
        // Remove duplicates and sort
        $urls = array_map('trim', $urls);
        $urls = array_unique($urls);
        $urls = array_filter($urls, function($url) {
            return !empty($url) && (strpos($url, '/static/') === 0 || strpos($url, '/media/') === 0);
        });
        
        sort($urls);
        
        return $urls;
    }
}