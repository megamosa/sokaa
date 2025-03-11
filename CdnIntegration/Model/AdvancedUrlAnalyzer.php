<?php
namespace MagoArab\CdnIntegration\Model;

use MagoArab\CdnIntegration\Helper\Data as Helper;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Api\ProductRepositoryInterface;

class AdvancedUrlAnalyzer
{
    /**
     * @var Helper
     */
    protected $helper;
    
    /**
     * @var Curl
     */
    protected $curl;
    
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    
    /**
     * @var ProductCollectionFactory
     */
    protected $productCollectionFactory;
    
    /**
     * @var CategoryCollectionFactory
     */
    protected $categoryCollectionFactory;
    
    /**
     * @var ImageHelper
     */
    protected $imageHelper;
    
    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;
    
    /**
     * @var array
     */
    protected $visitedUrls = [];
    
    /**
     * @var array
     */
    protected $discoveredAssets = [];
    
    /**
     * @var int
     */
    protected $maxPagesToVisit = 5;
    
    /**
     * @var int
     */
    protected $visitedCount = 0;
    
    /**
     * @var string
     */
    protected $baseUrl;
    
    /**
     * @param Helper $helper
     * @param Curl $curl
     * @param StoreManagerInterface $storeManager
     * @param ProductCollectionFactory $productCollectionFactory
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param ImageHelper $imageHelper
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        Helper $helper,
        Curl $curl,
        StoreManagerInterface $storeManager,
        ProductCollectionFactory $productCollectionFactory,
        CategoryCollectionFactory $categoryCollectionFactory,
        ImageHelper $imageHelper,
        ProductRepositoryInterface $productRepository
    ) {
        $this->helper = $helper;
        $this->curl = $curl;
        $this->storeManager = $storeManager;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->imageHelper = $imageHelper;
        $this->productRepository = $productRepository;
    }
    
    /**
     * Analyze store for static and media URLs
     *
     * @param string|null $startUrl
     * @param int $maxPages
     * @param bool $includeProductImages
     * @return array
     */
    public function analyze($startUrl = null, $maxPages = 5, $includeProductImages = true)
    {
        // Reset state
        $this->visitedUrls = [];
        $this->discoveredAssets = [];
        $this->visitedCount = 0;
        $this->maxPagesToVisit = $maxPages;
        
        if (!$startUrl) {
            $this->baseUrl = $this->storeManager->getStore()->getBaseUrl();
        } else {
            $this->baseUrl = $startUrl;
            
            // Ensure baseUrl ends with a slash
            if (substr($this->baseUrl, -1) !== '/') {
                $this->baseUrl .= '/';
            }
        }
        
        $this->helper->log("Starting enhanced URL analysis from: {$this->baseUrl}", 'info');
        
        // Start with the homepage
        $this->crawlPage($this->baseUrl);
        
        // Add product image analysis if requested
        if ($includeProductImages) {
            $this->helper->log("Starting product image analysis", 'info');
            $this->analyzeProductImages();
            $this->helper->log("Product image analysis complete", 'info');
            
            // Also analyze category images
            $this->helper->log("Starting category image analysis", 'info');
            $this->analyzeCategoryImages();
            $this->helper->log("Category image analysis complete", 'info');
        }
        
        $this->helper->log("Analysis complete. Visited {$this->visitedCount} pages, found " . 
            count($this->discoveredAssets) . " unique static/media assets.", 'info');
        
        // Return unique, sorted list of discovered assets
        return $this->getDiscoveredAssets();
    }
    
    /**
     * Analyze all product images
     * 
     * @return void
     */
    protected function analyzeProductImages()
    {
        try {
            // Create product collection
            $collection = $this->productCollectionFactory->create();
            $collection->addAttributeToSelect('*');
            
            // Set page size and limit to avoid memory issues
            $collection->setPageSize(100);
            $collection->setCurPage(1);
            
            $totalPages = $collection->getLastPageNumber();
            $currentPage = 1;
            
            $this->helper->log("Processing products: {$collection->getSize()} found", 'info');
            
            // Process each page of the collection
            while ($currentPage <= $totalPages) {
                if ($currentPage > 1) {
                    $collection->setCurPage($currentPage);
                    $collection->clear();
                }
                
                foreach ($collection as $product) {
                    $this->processProductImages($product);
                }
                
                $currentPage++;
            }
        } catch (\Exception $e) {
            $this->helper->log("Error analyzing product images: " . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Process images for a single product
     * 
     * @param \Magento\Catalog\Model\Product $product
     * @return void
     */
    protected function processProductImages($product)
    {
        try {
            $productId = $product->getId();
            $this->helper->log("Processing images for product ID: {$productId}", 'debug');
            
            // Get base image
            $baseImageUrl = $this->getProductImageUrl($product, 'product_base_image');
            if ($baseImageUrl) {
                $this->addAssetIfValid($baseImageUrl);
            }
            
            // Get small image
            $smallImageUrl = $this->getProductImageUrl($product, 'product_small_image');
            if ($smallImageUrl) {
                $this->addAssetIfValid($smallImageUrl);
            }
            
            // Get thumbnail image
            $thumbnailUrl = $this->getProductImageUrl($product, 'product_thumbnail_image');
            if ($thumbnailUrl) {
                $this->addAssetIfValid($thumbnailUrl);
            }
            
            // Get swatch image if available
            $swatchImageUrl = $this->getProductImageUrl($product, 'product_swatch_image');
            if ($swatchImageUrl) {
                $this->addAssetIfValid($swatchImageUrl);
            }
            
            // Get gallery images
            $this->processProductGalleryImages($product);
            
        } catch (\Exception $e) {
            $this->helper->log("Error processing images for product ID {$product->getId()}: " . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Get product image URL
     * 
     * @param \Magento\Catalog\Model\Product $product
     * @param string $imageType
     * @return string|null
     */
    protected function getProductImageUrl($product, $imageType)
    {
        try {
            // Check if product has the image
            if (!$product->getImage() && $imageType === 'product_base_image') {
                return null;
            }
            if (!$product->getSmallImage() && $imageType === 'product_small_image') {
                return null;
            }
            if (!$product->getThumbnail() && $imageType === 'product_thumbnail_image') {
                return null;
            }
            
            // Get image URL via the image helper
            $imageUrl = $this->imageHelper->init($product, $imageType)
                ->setImageFile($product->getImage())
                ->getUrl();
                
            return $imageUrl;
        } catch (\Exception $e) {
            $this->helper->log("Error getting image URL for product ID {$product->getId()}: " . $e->getMessage(), 'error');
            return null;
        }
    }
    
    /**
     * Process gallery images for a product
     * 
     * @param \Magento\Catalog\Model\Product $product
     * @return void
     */
    protected function processProductGalleryImages($product)
    {
        try {
            // Try to get full product (with gallery images)
            if (!$product->getMediaGalleryImages()) {
                $product = $this->productRepository->getById($product->getId());
            }
            
            // Get all gallery images
            $galleryImages = $product->getMediaGalleryImages();
            
            if ($galleryImages && $galleryImages->getSize() > 0) {
                foreach ($galleryImages as $image) {
                    if (isset($image['url'])) {
                        $this->addAssetIfValid($image['url']);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->helper->log("Error processing gallery images for product ID {$product->getId()}: " . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Analyze category images
     * 
     * @return void
     */
    protected function analyzeCategoryImages()
    {
        try {
            // Create category collection
            $collection = $this->categoryCollectionFactory->create();
            $collection->addAttributeToSelect('*');
            
            // Set page size and limit to avoid memory issues
            $collection->setPageSize(50);
            
            $this->helper->log("Processing categories: {$collection->getSize()} found", 'info');
            
            foreach ($collection as $category) {
                $this->processCategoryImages($category);
            }
        } catch (\Exception $e) {
            $this->helper->log("Error analyzing category images: " . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Process images for a single category
     * 
     * @param \Magento\Catalog\Model\Category $category
     * @return void
     */
    protected function processCategoryImages($category)
    {
        try {
            $categoryId = $category->getId();
            $this->helper->log("Processing images for category ID: {$categoryId}", 'debug');
            
            // Get category image URL
            if ($category->getImageUrl()) {
                $this->addAssetIfValid($category->getImageUrl());
            }
            
            // Check for thumbnail
            if ($category->getThumbnail()) {
                $thumbnailUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) 
                    . 'catalog/category/' . $category->getThumbnail();
                $this->addAssetIfValid($thumbnailUrl);
            }
        } catch (\Exception $e) {
            $this->helper->log("Error processing images for category ID {$category->getId()}: " . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Add asset to discovered assets if it's a valid media or static URL
     * 
     * @param string $url
     * @return void
     */
    protected function addAssetIfValid($url)
    {
        if (empty($url)) {
            return;
        }
        
        // Convert absolute URLs to relative paths
        $parsedUrl = parse_url($url);
        $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
        
        // Ensure path starts with a slash
        if ($path && $path[0] !== '/') {
            $path = '/' . $path;
        }
        
        // Keep only static and media URLs
        if (strpos($path, '/static/') === 0 || strpos($path, '/media/') === 0) {
            if (!in_array($path, $this->discoveredAssets)) {
                $this->helper->log("Found asset: {$path}", 'debug');
                $this->discoveredAssets[] = $path;
            }
        }
    }
    
    /**
     * Crawl a page and extract assets and links
     *
     * @param string $url
     * @return void
     */
    protected function crawlPage($url)
    {
        // Skip if we've already visited this URL or reached the limit
        if (in_array($url, $this->visitedUrls) || $this->visitedCount >= $this->maxPagesToVisit) {
            return;
        }
        
        $this->helper->log("Crawling page: {$url}", 'debug');
        $this->visitedUrls[] = $url;
        $this->visitedCount++;
        
        // Fetch the page content
        $content = $this->fetchUrl($url);
        if (empty($content)) {
            $this->helper->log("Failed to fetch content from: {$url}", 'warning');
            return;
        }
        
        // Extract and store static/media assets
        $assets = $this->extractAssets($content);
        foreach ($assets as $asset) {
            if (!in_array($asset, $this->discoveredAssets)) {
                $this->discoveredAssets[] = $asset;
            }
        }
        
        // Extract links to other pages on the same domain
        $links = $this->extractLinks($content, $url);
        
        // Visit each link (depth-first)
        foreach ($links as $link) {
            if ($this->visitedCount < $this->maxPagesToVisit) {
                $this->crawlPage($link);
            } else {
                break;
            }
        }
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
            // Create new curl instance for each request to avoid conflicts
            $this->curl = new Curl();
            
            // Set browser-like user agent
            $this->curl->setOption(CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->setOption(CURLOPT_FOLLOWLOCATION, true);
            $this->curl->setOption(CURLOPT_MAXREDIRS, 5);
            $this->curl->setOption(CURLOPT_TIMEOUT, 30);
            $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, false);
            $this->curl->setOption(CURLOPT_SSL_VERIFYHOST, false);
            
            // Add accept headers to mimic browser
            $this->curl->addHeader('Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8');
            $this->curl->addHeader('Accept-Language', 'en-US,en;q=0.5');
            
            $this->curl->get($url);
            $statusCode = $this->curl->getStatus();
            
            if ($statusCode >= 200 && $statusCode < 300) {
                return $this->curl->getBody();
            }
            
            $this->helper->log("Error fetching URL {$url}: HTTP status {$statusCode}", 'warning');
            return '';
        } catch (\Exception $e) {
            $this->helper->log("Exception fetching URL {$url}: " . $e->getMessage(), 'error');
            return '';
        }
    }
    
    /**
     * Extract static and media assets from HTML content
     *
     * @param string $content
     * @return array
     */
    protected function extractAssets($content)
    {
        $assets = [];
        
        // Comprehensive patterns to find all types of static and media assets
        $patterns = [
            // CSS links
            '/<link[^>]*href=[\'"]([^\'"]+\.css(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // JavaScript files
            '/<script[^>]*src=[\'"]([^\'"]+\.js(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Images
            '/<img[^>]*src=[\'"]([^\'"]+\.(png|jpg|jpeg|gif|svg|webp)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Background images in inline styles
            '/style=[\'"][^"\']*background(?:-image)?:\s*url\([\'"]?([^\'")+\s]+)[\'"]?\)[^"\']*[\'"]/',
            
            // Fonts and other assets in CSS url()
            '/url\([\'"]?([^\'")\s]+)[\'"]?\)/i',
            
            // Video and audio sources
            '/<(?:video|audio)[^>]*>.*?<source[^>]*src=[\'"]([^\'"]+)[\'"].*?<\/(?:video|audio)>/is',
            
            // Media in object/embed tags
            '/<(?:object|embed)[^>]*(?:data|src)=[\'"]([^\'"]+)[\'"][^>]*>/i',
            
            // Data attributes with URLs
            '/ data-[^=]*=[\'"]([^\'"]+\.(js|css|png|jpg|jpeg|gif)(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // SVG images in various positions
            '/<[^>]*?(?:href|src)=[\'"]([^\'"]+\.svg(?:\?[^\'"]*)?)[\'"][^>]*>/i',
            
            // Preload links
            '/<link[^>]*rel=[\'"]preload[\'"][^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i',
            
            // Import statements in styles
            '/@import\s+[\'"]([^\'"]+)[\'"]/i',
            
            // srcset attribute for responsive images
            '/<img[^>]*srcset=[\'"]([^\'"]+)[\'"][^>]*>/i',
            
            // Picture source elements
            '/<source[^>]*srcset=[\'"]([^\'"]+)[\'"][^>]*>/i'
        ];
        
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            
            if (isset($matches[1]) && !empty($matches[1])) {
                foreach ($matches[1] as $asset) {
                    // Skip data URLs and absolute URLs to other domains
                    if (strpos($asset, 'data:') === 0 || 
                        (strpos($asset, 'http') === 0 && strpos($asset, $this->baseUrl) !== 0)) {
                        continue;
                    }
                    
                    // Handle srcset attribute (multiple URLs separated by commas)
                    if (strpos($asset, ',') !== false && (strpos($pattern, 'srcset') !== false)) {
                        $srcSetUrls = explode(',', $asset);
                        foreach ($srcSetUrls as $srcSetUrl) {
                            // Extract URL part before size descriptor
                            $parts = preg_split('/\s+/', trim($srcSetUrl));
                            if (!empty($parts[0])) {
                                $this->addAssetIfValid($parts[0]);
                            }
                        }
                        continue;
                    }
                    
                    // Convert absolute URLs to relative paths
                    if (strpos($asset, $this->baseUrl) === 0) {
                        $asset = substr($asset, strlen($this->baseUrl) - 1); // -1 to keep the leading slash
                    }
                    
                    // Keep only static and media URLs
                    if (strpos($asset, '/static/') === 0 || strpos($asset, '/media/') === 0) {
                        $assets[] = $asset;
                    }
                }
            }
        }
        
        // Special patterns for Magento-specific resources
        $magentoPatterns = [
            // Merged JS/CSS files
            '/\/static\/_cache\/merged\/[^"\')+\s]+/i',
            
            // Minified JS/CSS files
            '/\/static\/_cache\/minified\/[^"\')+\s]+/i',
            
            // RequireJS text plugin
            '/text!(\/static\/[^!]+)/i',
            
            // RequireJS define paths
            '/"(\/static\/[^"]+)"/i'
        ];
        
        foreach ($magentoPatterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            
            $matchesArray = !empty($matches[1]) ? $matches[1] : $matches[0];
            
            if (!empty($matchesArray)) {
                foreach ($matchesArray as $asset) {
                    if (strpos($asset, '/static/') === 0 || strpos($asset, '/media/') === 0) {
                        $assets[] = $asset;
                    }
                }
            }
        }
        
        // Look for JSON data that might contain URLs
        if (preg_match_all('/\{[^}]+\}/m', $content, $jsonMatches)) {
            foreach ($jsonMatches[0] as $jsonString) {
                if (preg_match_all('/"(\/(?:static|media)\/[^"]+)"/i', $jsonString, $jsonPaths)) {
                    foreach ($jsonPaths[1] as $asset) {
                        $assets[] = $asset;
                    }
                }
            }
        }
        
        // Look specifically for product gallery image data
        if (preg_match_all('/data-gallery-role="gallery-placeholder"[^>]*?data-mage-init=\'([^\']+)\'/i', $content, $galleryMatches)) {
            foreach ($galleryMatches[1] as $galleryData) {
                try {
                    $data = json_decode($galleryData, true);
                    if (isset($data['mage/gallery/gallery']['data'])) {
                        foreach ($data['mage/gallery/gallery']['data'] as $item) {
                            if (isset($item['img'])) {
                                $this->addAssetIfValid($item['img']);
                            }
                            if (isset($item['thumb'])) {
                                $this->addAssetIfValid($item['thumb']);
                            }
                            if (isset($item['full'])) {
                                $this->addAssetIfValid($item['full']);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $this->helper->log("Error parsing gallery data: " . $e->getMessage(), 'error');
                }
            }
        }
        
        // Remove duplicates and sort
        $assets = array_unique($assets);
        sort($assets);
        
        return $assets;
    }
    
    /**
     * Extract links to other pages on the same domain
     *
     * @param string $content
     * @param string $baseUrl
     * @return array
     */
    protected function extractLinks($content, $baseUrl)
    {
        $links = [];
        $baseUrlDomain = parse_url($this->baseUrl, PHP_URL_HOST);
        
        // Find all links
        preg_match_all('/<a[^>]*href=[\'"]([^\'"#]+)[\'"][^>]*>/i', $content, $matches);
        
        if (isset($matches[1]) && !empty($matches[1])) {
            foreach ($matches[1] as $link) {
                // Skip JavaScript links, mailto, tel, etc.
                if (strpos($link, 'javascript:') === 0 || 
                    strpos($link, 'mailto:') === 0 || 
                    strpos($link, 'tel:') === 0) {
                    continue;
                }
                
                // Handle relative URLs
                if (strpos($link, 'http') !== 0) {
                    // Handle different relative path formats
                    if (strpos($link, '/') === 0) {
                        // Absolute path relative to domain
                        $domain = parse_url($this->baseUrl, PHP_URL_SCHEME) . '://' . $baseUrlDomain;
                        $link = $domain . $link;
                    } else {
                        // Relative to current path
                        $basePath = dirname($baseUrl);
                        $link = $basePath . '/' . $link;
                    }
                }
                
                // Only include links to the same domain
                $linkDomain = parse_url($link, PHP_URL_HOST);
                if ($linkDomain === $baseUrlDomain) {
                    // Make sure we don't include static/media URLs
                    $path = parse_url($link, PHP_URL_PATH);
                    if (strpos($path, '/static/') !== 0 && strpos($path, '/media/') !== 0) {
                        $links[] = $link;
                    }
                }
            }
        }
        
        // Prioritize product and category pages
        $prioritizedLinks = [];
        $regularLinks = [];
        
        foreach ($links as $link) {
            if (strpos($link, '/catalog/product/view/') !== false || 
                strpos($link, '/catalog/category/view/') !== false) {
                $prioritizedLinks[] = $link;
            } else {
                $regularLinks[] = $link;
            }
        }
        
        // Return prioritized links first
        return array_merge($prioritizedLinks, $regularLinks);
    }
    
    /**
     * Get discovered assets
     *
     * @return array
     */
    public function getDiscoveredAssets()
    {
        return $this->discoveredAssets;
    }
}