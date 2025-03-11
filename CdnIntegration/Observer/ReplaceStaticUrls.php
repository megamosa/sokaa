<?php
namespace MagoArab\CdnIntegration\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use MagoArab\CdnIntegration\Helper\Data as Helper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\State;
use Magento\Framework\App\Area;

class ReplaceStaticUrls implements ObserverInterface
{
    /**
     * @var Helper
     */
    protected $helper;
    
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    
    /**
     * @var State
     */
    protected $appState;
    
    /**
     * @var array Cache for replaced URLs
     */
    protected $replacedUrlsCache = [];
    
    /**
     * @var array Cache for skipped URLs
     */
    protected $skippedUrlsCache = [];
    
    /**
     * @param Helper $helper
     * @param ScopeConfigInterface $scopeConfig
     * @param State $appState
     */
    public function __construct(
        Helper $helper,
        ScopeConfigInterface $scopeConfig,
        State $appState
    ) {
        $this->helper = $helper;
        $this->scopeConfig = $scopeConfig;
        $this->appState = $appState;
    }
	/**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if (!$this->helper->isEnabled()) {
            return;
        }
        
        // Reset caches at the beginning of execution
        $this->replacedUrlsCache = [];
        $this->skippedUrlsCache = [];
        
        // Check if custom URLs are defined
        $customUrls = $this->helper->getCustomUrls();
        
        // Skip admin area
        try {
            $areaCode = $this->appState->getAreaCode();
            if ($areaCode === Area::AREA_ADMINHTML) {
                $this->helper->log("Skipping admin area", 'debug');
                return;
            }
        } catch (\Exception $e) {
            // Check URL for admin path as a fallback
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            if (strpos($requestUri, '/admin/') !== false) {
                $this->helper->log("Skipping admin path: {$requestUri}", 'debug');
                return;
            }
        }
        
        $response = $observer->getEvent()->getResponse();
        if (!$response) {
            return;
        }
        
        $html = $response->getBody();
        if (empty($html)) {
            return;
        }
        
        // Check if current page is a product view page
        $isProductPage = false;
        if (strpos($html, 'catalog/product/view') !== false || 
            strpos($html, 'product-image-photo') !== false ||
            strpos($html, 'data-gallery-role="gallery-placeholder"') !== false) {
            $isProductPage = true;
            $this->helper->log("Detected product page, applying special image handling", 'debug');
        }
		// Get the CDN base URL
        $cdnBaseUrl = $this->helper->getCdnBaseUrl();
        if (empty($cdnBaseUrl)) {
            $this->helper->log("CDN base URL is empty", 'warning');
            return;
        }
        
        $this->helper->log("Starting URL replacement with CDN base URL: {$cdnBaseUrl}", 'debug');
        
        // Get the base URLs
        $baseUrl = rtrim($this->scopeConfig->getValue(
            \Magento\Store\Model\Store::XML_PATH_UNSECURE_BASE_URL,
            ScopeInterface::SCOPE_STORE
        ), '/');
        
        $secureBaseUrl = rtrim($this->scopeConfig->getValue(
            \Magento\Store\Model\Store::XML_PATH_SECURE_BASE_URL,
            ScopeInterface::SCOPE_STORE
        ), '/');
        
        // Safe file types to use with CDN
        $safeFileTypes = ['css', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'js', 'woff', 'woff2', 'ttf', 'eot'];
        
        // Files to always exclude from CDN - more specific matching
        $criticalFiles = [
            '/requirejs/require.js',        // Add leading slash for exact match
            '/mage/requirejs/mixins.js',    // Add leading slash for exact match
            '/mage/polyfill.js',           // Add leading slash for exact match
            '/mage/bootstrap.js'           // Add leading slash for exact match
        ];
        
        // Initialize replacement counter and arrays for debugging
        $replacementCount = 0;
        $replacedUrls = [];
        $failedUrls = [];

        // Special handling for product images if this is a product page
        if ($isProductPage) {
            $html = $this->handleProductGalleryImages($html, $cdnBaseUrl, $replacementCount, $replacedUrls);
            
            // Enhanced regex for product images
            $productImagePatterns = [
                // Main product image
                '/<img[^>]*class="[^"]*product-image-photo[^"]*"[^>]*src="([^"]+)"[^>]*>/i',
                
                // Fotorama gallery images
                '/<img[^>]*src="([^"]+)"[^>]*class="[^"]*fotorama__img[^"]*"[^>]*>/i',
                
                // All images from product media gallery in JSON
                '/{"full":"([^"]+)","img":"([^"]+)","thumb":"([^"]+)"/i',
                
                // Fallback to all images in product area
                '/<div[^>]*class="[^"]*product[^"]*"[^>]*>.*?<img[^>]*src="([^"]+)"[^>]*>.*?<\/div>/is',
            ];
            
            foreach ($productImagePatterns as $pattern) {
                $html = preg_replace_callback(
                    $pattern,
                    function($matches) use ($cdnBaseUrl, &$replacementCount, &$replacedUrls) {
                        // Get all captured groups that might contain URLs
                        $urls = array_slice($matches, 1);
                        
                        // Replace all URLs in the match
                        $fullMatch = $matches[0];
                        $modified = $fullMatch;
                        
                        foreach ($urls as $url) {
                            if (empty($url)) continue;
                            
                            // Check if this is a media URL
                            if (strpos($url, '/media/') !== false) {
                                $cdnPath = substr($url, strpos($url, '/media/') + 7); // Remove '/media/'
                                $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                                
                                // Replace URL in the HTML
                                $modified = str_replace($url, $cdnUrl, $modified);
                                $replacementCount++;
                                $replacedUrls[$url] = $cdnUrl;
                                
                                $this->helper->log("Replaced product image: {$url} -> {$cdnUrl}", 'debug');
                            }
                        }
                        
                        return $modified;
                    },
                    $html
                );
            }
        }
		// Process RequireJS config files specifically
        $requireJsConfigPattern = '/<script[^>]*src=[\'"]([^\'"]*requirejs-config\.js[^\'"]*)[\'"][^>]*>/i';
        if (preg_match_all($requireJsConfigPattern, $html, $configMatches)) {
            foreach ($configMatches[1] as $configUrl) {
                // Replace the URL with CDN version
                $originalHtml = $html;
                $html = $this->processUrl($html, $configUrl, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, $replacementCount, $replacedUrls);
                
                // If config URL was replaced, we need to load and process the config file content
                if ($html !== $originalHtml && strpos($configUrl, 'requirejs-config.js') !== false) {
                    try {
                        // Modify the config URL to point to our CDN
                        $cdnPath = '';
                        if (strpos($configUrl, '/static/') === 0) {
                            $cdnPath = substr($configUrl, 8); // Remove '/static/'
                        } elseif (strpos($configUrl, '/media/') === 0) {
                            $cdnPath = substr($configUrl, 7); // Remove '/media/'
                        }
                        
                        if (!empty($cdnPath)) {
                            $cdnConfigUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                            
                            // Add a JavaScript patch to update RequireJS config
                            $patchScript = "<script type=\"text/javascript\">
                                require.config({
                                    urlArgs: 'v=" . time() . "',
                                    baseUrl: '" . rtrim($cdnBaseUrl, '/') . "/'
                                });
                            </script>";
                            
                            // Add the patch after the config script
                            $pattern = '/<script[^>]*src=[\'"]' . preg_quote($configUrl, '/') . '[\'"][^>]*><\/script>/i';
                            $html = preg_replace(
                                $pattern,
                                '$0' . $patchScript,
                                $html
                            );
                        }
                    } catch (\Exception $e) {
                        $this->helper->log('Error processing RequireJS config: ' . $e->getMessage(), 'error');
                    }
                }
            }
        }
        
        // Apply special handling for RequireJS files
        $html = $this->handleRequireJsSpecialCases($html, $cdnBaseUrl, $replacementCount, $replacedUrls);
        
        // Perform aggressive replacement for all script and link tags
        $patternTypes = [
            // Script tags: match both src and data-requiremodule attributes
            ['tag' => 'script', 'attrs' => ['src', 'data-requiremodule']],
            // Link tags: match href attribute
            ['tag' => 'link', 'attrs' => ['href']]
        ];
        
        foreach ($patternTypes as $patternType) {
            $tag = $patternType['tag'];
            foreach ($patternType['attrs'] as $attr) {
                // Create pattern to match tag with attribute
                $pattern = '/<' . $tag . '[^>]*' . $attr . '=[\'"]([^\'"]+)[\'"][^>]*>/i';
                
                if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $fullTag = $match[0];
                        $url = $match[1];
                        
                        // Skip if not a static or media URL
                        if (strpos($url, '/static/') !== 0 && strpos($url, '/media/') !== 0) {
                            continue;
                        }
                        
                        // Skip URLs already in cache
                        $cacheKey = md5($url);
                        if (isset($this->replacedUrlsCache[$cacheKey]) || isset($this->skippedUrlsCache[$cacheKey])) {
                            continue;
                        }
                        
                        // Skip critical files - exact matching
                        $shouldSkip = false;
                        foreach ($criticalFiles as $criticalFile) {
                            // Use exact matching instead of partial
                            if ($url === $criticalFile || $url === '/static' . $criticalFile) {
                                $shouldSkip = true;
                                $this->helper->log("Skipping critical file: {$url}", 'debug');
                                break;
                            }
                        }
                        
                        // Special handling for jQuery UI and Magento UI modules
                        if (strpos($url, 'jquery/ui-modules/') !== false || 
                            strpos($url, 'Magento_Ui/js/') !== false ||
                            strpos($url, 'mage/') !== false) {
                            
                            // Additional check to ensure this is not a critical file
                            $isJqueryUiModule = strpos($url, 'jquery/ui-modules/') !== false;
                            $isMagentoUiModule = strpos($url, 'Magento_Ui/js/') !== false;
                            $isMageUtils = strpos($url, 'mage/utils/') !== false;
                            
                            if ($isJqueryUiModule || $isMagentoUiModule || $isMageUtils) {
                                // Log that we're processing these files specially
                                $this->helper->log("Processing special module: {$url}", 'debug');
                                
                                // Override the critical file check
                                $shouldSkip = false;
                            }
                        }
                        
                        if ($shouldSkip) {
                            $this->skippedUrlsCache[$cacheKey] = true;
                            continue;
                        }
                        
                        // Determine path to use in CDN URL
                        $cdnPath = '';
                        if (strpos($url, '/static/') === 0) {
                            $cdnPath = substr($url, 8); // Remove '/static/'
                        } elseif (strpos($url, '/media/') === 0) {
                            $cdnPath = substr($url, 7); // Remove '/media/'
                        }
                        
                        if (empty($cdnPath)) {
                            $this->skippedUrlsCache[$cacheKey] = true;
                            continue;
                        }
                        
                        // Create full CDN URL - make sure there's no double slash
                        $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                        
                        // Replace in the tag
                        $newTag = str_replace($attr . '="' . $url . '"', $attr . '="' . $cdnUrl . '"', $fullTag);
                        $newTag = str_replace($attr . "='" . $url . "'", $attr . "='" . $cdnUrl . "'", $newTag);
                        
                        // Replace just this exact instance
                        $html = str_replace($fullTag, $newTag, $html);
                        $replacementCount++;
                        $replacedUrls[$url] = $cdnUrl;
                        $this->replacedUrlsCache[$cacheKey] = true;
                        
                        $this->helper->log("Replaced in {$tag} tag: {$url} → {$cdnUrl}", 'debug');
                    }
                }
            }
        }
		// Replace all standalone image tags
        $imgPattern = '/<img[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>/i';
        if (preg_match_all($imgPattern, $html, $imgMatches, PREG_SET_ORDER)) {
            foreach ($imgMatches as $match) {
                $fullTag = $match[0];
                $url = $match[1];
                
                // Skip if not a media URL
                if (strpos($url, '/media/') !== 0) {
                    continue;
                }
                
                // Skip URLs already in cache
                $cacheKey = md5($url);
                if (isset($this->replacedUrlsCache[$cacheKey]) || isset($this->skippedUrlsCache[$cacheKey])) {
                    continue;
                }
                
                // Determine path to use in CDN URL
                $cdnPath = substr($url, 7); // Remove '/media/'
                
                // Create full CDN URL - make sure there's no double slash
                $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                
                // Replace in the tag
                $newTag = str_replace('src="' . $url . '"', 'src="' . $cdnUrl . '"', $fullTag);
                
                // Replace just this exact instance
                $html = str_replace($fullTag, $newTag, $html);
                $replacementCount++;
                $replacedUrls[$url] = $cdnUrl;
                $this->replacedUrlsCache[$cacheKey] = true;
                
                $this->helper->log("Replaced image src: {$url} → {$cdnUrl}", 'debug');
            }
        }
        
        // Handle RequireJS configuration
        if (strpos($html, 'requirejs-config') !== false) {
            $configPattern = '/"([^"]+\.(js|css|png|jpeg|jpg|gif|svg))"/';
            if (preg_match_all($configPattern, $html, $configMatches)) {
                foreach ($configMatches[1] as $url) {
                    if (strpos($url, '/static/') === 0 || strpos($url, '/media/') === 0) {
                        $html = $this->processUrl($html, $url, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, $replacementCount, $replacedUrls);
                    }
                }
            }
        }
        
        // Handle inline JSON data that may contain URLs
        $jsonPattern = '/(\{[^\}]+\"url\":[\s]*[\"\'])([^\"\']+)([\"\'])/i';
        if (preg_match_all($jsonPattern, $html, $jsonMatches)) {
            foreach ($jsonMatches[2] as $index => $url) {
                if (strpos($url, '/static/') === 0 || strpos($url, '/media/') === 0) {
                    $fullMatch = $jsonMatches[0][$index];
                    $prefix = $jsonMatches[1][$index];
                    $suffix = $jsonMatches[3][$index];
                    
                    // Process the URL
                    $cdnPath = '';
                    if (strpos($url, '/static/') === 0) {
                        $cdnPath = substr($url, 8);
                    } elseif (strpos($url, '/media/') === 0) {
                        $cdnPath = substr($url, 7);
                    }
                    
                    $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                    $newMatch = $prefix . $cdnUrl . $suffix;
                    
                    $html = str_replace($fullMatch, $newMatch, $html);
                    $replacementCount++;
                    $replacedUrls[$url] = $cdnUrl;
                }
            }
        }
		// Process each custom URL in the exact order they were defined
        if (!empty($customUrls)) {
            foreach ($customUrls as $url) {
                $cacheKey = md5($url);
                
                // Skip if already processed
                if (isset($this->replacedUrlsCache[$cacheKey])) {
                    continue;
                }
                
                // Normalize URL (remove domain if present)
                $normalizedUrl = $url;
                if (strpos($url, 'http') === 0) {
                    $parsedUrl = parse_url($url);
                    if (isset($parsedUrl['path'])) {
                        $normalizedUrl = $parsedUrl['path'];
                    }
                }
                
                // Process the normalized URL
                $html = $this->processUrl($html, $normalizedUrl, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, $replacementCount, $replacedUrls);
            }
        }
        
        // Search for any CSS background images
        $cssBackgroundPattern = '/background(-image)?:\s*url\([\'"]?([^\'")\s]+)[\'"]?\)/i';
        if (preg_match_all($cssBackgroundPattern, $html, $bgMatches)) {
            foreach ($bgMatches[2] as $url) {
                // Skip data URIs
                if (strpos($url, 'data:') === 0) {
                    continue;
                }
                
                if (strpos($url, '/static/') === 0 || strpos($url, '/media/') === 0) {
                    $html = $this->processUrl($html, $url, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, $replacementCount, $replacedUrls);
                }
            }
        }
        
        // Handle data-mage-init and other data attributes that may contain URLs
        $dataAttrPattern = '/data-[^=]+=[\'"](.*?)[\'"](?=[^>]*>)/i';
        if (preg_match_all($dataAttrPattern, $html, $dataMatches)) {
            foreach ($dataMatches[1] as $attrValue) {
                // Check if this looks like JSON
                if (strpos($attrValue, '{') === 0) {
                    try {
                        $jsonData = json_decode($attrValue, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                            // Process any URLs in the JSON data
                            $this->processJsonForUrls($jsonData, function($url) use (&$html, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, &$replacementCount, &$replacedUrls) {
                                if (strpos($url, '/static/') === 0 || strpos($url, '/media/') === 0) {
                                    $html = $this->processUrl($html, $url, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, $replacementCount, $replacedUrls);
                                }
                            });
                        }
                    } catch (\Exception $e) {
                        // Not valid JSON, ignore
                    }
                }
            }
        }
        
        // Add RequireJS patch for dynamically loaded modules
        if (strpos($html, 'require.js') !== false || strpos($html, 'requirejs') !== false) {
            // Update requirejs config 
            $configPatchScript = "<script type=\"text/javascript\">
            (function() {
                var oldDefine = window.define;
                if (oldDefine) {
                    window.define = function(name, deps, callback) {
                        // Modify paths to use CDN
                        if (Array.isArray(deps)) {
                            deps = deps.map(function(dep) {
                                if (typeof dep === 'string' && (dep.indexOf('/static/') === 0 || dep.indexOf('/media/') === 0)) {
                                    var cdnPath = '';
                                    if (dep.indexOf('/static/') === 0) {
                                        cdnPath = dep.substring(8);
                                    } else if (dep.indexOf('/media/') === 0) {
                                        cdnPath = dep.substring(7);
                                    }
                                    return '{$cdnBaseUrl}' + cdnPath;
                                }
                                return dep;
                            });
                        }
                        return oldDefine.call(window, name, deps, callback);
                    };
                    // Copy all properties
                    for (var prop in oldDefine) {
                        if (oldDefine.hasOwnProperty(prop)) {
                            window.define[prop] = oldDefine[prop];
                        }
                    }
                }
            })();
            </script>";
            
            // Add the code after RequireJS loads
            $html = preg_replace('/<script[^>]*src=[\'"][^\'"]*require\.js[^\'"]*[\'"][^>]*><\/script>/', '$0' . $configPatchScript, $html);
        }
        
        // Special handling for JSON encoded URLs in JavaScript
        $jsonUrlPattern = '/"(\\\\?\/static\\\\?\/[^"\\\\]*\\.(?:js|css|png|jpg)(?:\\?[^"\\\\]*)?)"(?=\s*,|\s*\])/i';
        if (preg_match_all($jsonUrlPattern, $html, $jsonUrlMatches)) {
            foreach ($jsonUrlMatches[1] as $encodedUrl) {
                // Normalize the URL (remove escaping)
                $url = str_replace('\/', '/', $encodedUrl);
                
                // Skip if not a static or media URL
                if (strpos($url, '/static/') !== 0 && strpos($url, '/media/') !== 0) {
                    continue;
                }
                
                // Determine path to use in CDN URL
                $cdnPath = '';
                if (strpos($url, '/static/') === 0) {
                    $cdnPath = substr($url, 8); // Remove '/static/'
                } elseif (strpos($url, '/media/') === 0) {
                    $cdnPath = substr($url, 7); // Remove '/media/'
                }
                
                if (empty($cdnPath)) {
                    continue;
                }
                
                // Create full CDN URL
                $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                
                // Replace in HTML, preserving escaping pattern
                $encodedCdnUrl = str_replace('/', '\/', $cdnUrl);
                $html = str_replace('"' . $encodedUrl . '"', '"' . $encodedCdnUrl . '"', $html);
                $replacementCount++;
                $replacedUrls[$url] = $cdnUrl;
                
                $this->helper->log("Replaced JSON URL: {$url} -> {$cdnUrl}", 'debug');
            }
        }
		// Look for JavaScript arrays containing URLs (like the ones in your paste.txt)
        $jsArrayPattern = '/[\'"](\\/static\\/[^\'"]+\\.(?:js|css|png|jpg)(?:\\?[^\'"]*)?)[\'"](?=\\s*,|\\s*\\])/i';
        if (preg_match_all($jsArrayPattern, $html, $jsArrayMatches)) {
            foreach ($jsArrayMatches[1] as $url) {
                $html = $this->processUrl($html, $url, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, $replacementCount, $replacedUrls);
            }
        }
        
        // Look for URLs in JSON format like \"\/static\/frontend\/...\"
        $jsonEscapedPattern = '/([\\\"]\\\\\/static\\\\\/[^"\\\\]+\\.(?:js|css|png|jpg)(?:\\?[^"\\\\]*)?[\\\"])/i';
        if (preg_match_all($jsonEscapedPattern, $html, $jsonMatches)) {
            foreach ($jsonMatches[1] as $escapedUrl) {
                // Convert JSON escaped URL to normal URL
                $url = str_replace('\/', '/', $escapedUrl);
                $url = trim($url, '"\\');
                $html = $this->processUrl($html, $url, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, $replacementCount, $replacedUrls);
            }
        }
        
        // Handle lazy loading data-src attributes
        $dataSrcPattern = '/data-src=[\'"](\/(static|media)\/[^\'"]+)[\'"]/i';
        if (preg_match_all($dataSrcPattern, $html, $dataSrcMatches, PREG_SET_ORDER)) {
            foreach ($dataSrcMatches as $match) {
                $fullAttr = $match[0];
                $url = $match[1];
                
                // Determine path to use in CDN URL
                $cdnPath = '';
                if (strpos($url, '/static/') === 0) {
                    $cdnPath = substr($url, 8); // Remove '/static/'
                } elseif (strpos($url, '/media/') === 0) {
                    $cdnPath = substr($url, 7); // Remove '/media/'
                }
                
                if (!empty($cdnPath)) {
                    $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                    $newAttr = 'data-src="' . $cdnUrl . '"';
                    
                    $html = str_replace($fullAttr, $newAttr, $html);
                    $replacementCount++;
                    $replacedUrls[$url] = $cdnUrl;
                    
                    $this->helper->log("Replaced data-src: {$url} -> {$cdnUrl}", 'debug');
                }
            }
        }
        
        // Add special patcher for product gallery images if this is a product page
        if ($isProductPage) {
            $productGalleryPatcher = "<script>
            // Product image CDN patcher
            (function() {
                var cdnBaseUrl = '{$cdnBaseUrl}';
                
                // Convert all product image URLs to CDN URLs
                function patchProductImages() {
                    // Select all product images in galleries, main images, etc.
                    var images = document.querySelectorAll('.product-image-photo, .fotorama__img, img[data-role=\"image-element\"]');
                    
                    images.forEach(function(img) {
                        var src = img.getAttribute('src');
                        if (src && src.indexOf('/media/') !== -1) {
                            var cdnPath = src.substring(src.indexOf('/media/') + 7);
                            var cdnUrl = cdnBaseUrl.replace(/\\/$/, '') + '/' + cdnPath.replace(/^\\//, '');
                            img.setAttribute('src', cdnUrl);
                        }
                        
                        // Also handle data-src and srcset if present
                        var dataSrc = img.getAttribute('data-src');
                        if (dataSrc && dataSrc.indexOf('/media/') !== -1) {
                            var cdnPath = dataSrc.substring(dataSrc.indexOf('/media/') + 7);
                            var cdnUrl = cdnBaseUrl.replace(/\\/$/, '') + '/' + cdnPath.replace(/^\\//, '');
                            img.setAttribute('data-src', cdnUrl);
                        }
                        
           // Handle srcset attribute
                        var srcset = img.getAttribute('srcset');
                        if (srcset && srcset.indexOf('/media/') !== -1) {
                            // Split srcset into individual entries
                            var entries = srcset.split(',');
                            var newSrcset = [];
                            
                            entries.forEach(function(entry) {
                                var parts = entry.trim().split(' ');
                                var url = parts[0];
                                var descriptor = parts.slice(1).join(' ');
                                
                                if (url && url.indexOf('/media/') !== -1) {
                                    var cdnPath = url.substring(url.indexOf('/media/') + 7);
                                    var cdnUrl = cdnBaseUrl.replace(/\\/$/, '') + '/' + cdnPath.replace(/^\\//, '');
                                    newSrcset.push(cdnUrl + ' ' + descriptor);
                                } else {
                                    newSrcset.push(entry);
                                }
                            });
                            
                            img.setAttribute('srcset', newSrcset.join(', '));
                        }
                    });
                    
                    // Also patch any JSON data with image URLs
                    var scripts = document.querySelectorAll('script[type=\"text/x-magento-init\"]');
                    scripts.forEach(function(script) {
                        try {
                            var content = script.textContent;
                            if (content && content.indexOf('\"img\":') !== -1) {
                                // Replace all media URLs in the JSON
                                var modified = content.replace(/\"(\/media\/[^\"]+)\"/g, function(match, url) {
                                    var cdnPath = url.substring(7); // Remove '/media/'
                                    var cdnUrl = cdnBaseUrl.replace(/\\/$/, '') + '/' + cdnPath.replace(/^\\//, '');
                                    return '\"' + cdnUrl + '\"';
                                });
                                
                                if (modified !== content) {
                                    script.textContent = modified;
                                }
                            }
                        } catch (e) {
                            console.error('Error patching gallery JSON:', e);
                        }
                    });
                }
                
                // Run immediately and after DOM content loaded
                patchProductImages();
                document.addEventListener('DOMContentLoaded', patchProductImages);
                
                // Also run when the gallery is initialized
                document.addEventListener('gallery:loaded', patchProductImages);
                
                // Run again after a delay to catch any lazy-loaded images
                setTimeout(patchProductImages, 1000);
                setTimeout(patchProductImages, 3000);
            })();
            </script>";
            
            // Add the script right before the closing body tag
            $html = str_replace('</body>', $productGalleryPatcher . '</body>', $html);
        }
        
        // Log stats
        if ($replacementCount > 0) {
            $this->helper->log("Replaced {$replacementCount} URLs with CDN URLs", 'info');
            
            // Detailed debug log if debug mode is enabled
            if ($this->helper->isDebugEnabled()) {
                $this->helper->log("Replaced URLs: " . json_encode(array_slice($replacedUrls, 0, 50)), 'debug');
                if (!empty($failedUrls)) {
                    $this->helper->log("Failed to replace URLs: " . json_encode(array_slice($failedUrls, 0, 20)), 'debug');
                }
            }
        }
        
        $response->setBody($html);
    }
	/**
     * Handle special cases for product gallery images
     *
     * @param string $html The HTML content
     * @param string $cdnBaseUrl The CDN base URL
     * @param int &$replacementCount Reference to the replacement counter
     * @param array &$replacedUrls Reference to the replaced URLs array
     * @return string The modified HTML
     */
    private function handleProductGalleryImages($html, $cdnBaseUrl, &$replacementCount, &$replacedUrls)
    {
        // Look for JSON configuration for gallery
        if (preg_match_all('/data-gallery-role="gallery-placeholder"[^>]*data-mage-init=\'([^\']+)\'/i', $html, $galleryMatches)) {
            foreach ($galleryMatches[1] as $index => $jsonConfig) {
                $originalJson = $jsonConfig;
                
                try {
                    $config = json_decode($jsonConfig, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($config['mage/gallery/gallery']['data'])) {
                        $modified = false;
                        
                        // Process each gallery image
                        foreach ($config['mage/gallery/gallery']['data'] as &$item) {
                            // Process 'img' URL
                            if (isset($item['img']) && strpos($item['img'], '/media/') !== false) {
                                $cdnPath = substr($item['img'], strpos($item['img'], '/media/') + 7);
                                $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                                $replacedUrls[$item['img']] = $cdnUrl;
                                $item['img'] = $cdnUrl;
                                $modified = true;
                                $replacementCount++;
                            }
                            
                            // Process 'thumb' URL
                            if (isset($item['thumb']) && strpos($item['thumb'], '/media/') !== false) {
                                $cdnPath = substr($item['thumb'], strpos($item['thumb'], '/media/') + 7);
                                $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                                $replacedUrls[$item['thumb']] = $cdnUrl;
                                $item['thumb'] = $cdnUrl;
                                $modified = true;
                                $replacementCount++;
                            }
                            
                            // Process 'full' URL
                            if (isset($item['full']) && strpos($item['full'], '/media/') !== false) {
                                $cdnPath = substr($item['full'], strpos($item['full'], '/media/') + 7);
                                $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                                $replacedUrls[$item['full']] = $cdnUrl;
                                $item['full'] = $cdnUrl;
                                $modified = true;
                                $replacementCount++;
                            }
                        }
                        
                        // Replace the original JSON with the modified one if needed
                        if ($modified) {
                            $newJson = json_encode($config);
                            // Escape single quotes for HTML attribute
                            $newJson = str_replace("'", "&#39;", $newJson);
                            $html = str_replace("data-mage-init='{$originalJson}'", "data-mage-init='{$newJson}'", $html);
                            
                            $this->helper->log("Replaced gallery image URLs in JSON configuration", 'debug');
                        }
                    }
                } catch (\Exception $e) {
                    $this->helper->log("Error processing gallery JSON: " . $e->getMessage(), 'error');
                }
            }
        }
        
        return $html;
    }
    
    /**
     * Handle special cases for RequireJS loaded files
     *
     * @param string $html The HTML content
     * @param string $cdnBaseUrl The CDN base URL
     * @param int &$replacementCount Reference to the replacement counter
     * @param array &$replacedUrls Reference to the replaced URLs array
     * @return string The modified HTML
     */
    private function handleRequireJsSpecialCases($html, $cdnBaseUrl, &$replacementCount, &$replacedUrls)
    {
        // Extract all RequireJS data-requiremodule attributes
        $pattern = '/data-requiremodule=[\'"](.*?)[\'"]/i';
        if (preg_match_all($pattern, $html, $moduleMatches)) {
            foreach ($moduleMatches[1] as $moduleName) {
                // Try to convert the module name to a file path
                // This is needed because RequireJS modules don't always match file paths directly
                
                // Check if this is a full path already
                if (strpos($moduleName, '/') === 0) {
                    // Already a path, just process it
                    $originalHtml = $html;
                    $html = $this->processModulePath($html, $moduleName, $cdnBaseUrl, $replacementCount, $replacedUrls);
                    
                    // If something was replaced, no need to try other variations
                    if ($html !== $originalHtml) {
                        continue;
                    }
                }
                
                // Common module naming patterns in Magento
                $possiblePaths = [
                    // Direct path - moduleName.js
                    '/static/frontend/*/'. $moduleName . '.js',
                    '/static/frontend/*/'. $moduleName . '.min.js',
                    
                    // Path with module as directory
                    '/static/frontend/*/' . $moduleName . '/main.js',
                    '/static/frontend/*/' . $moduleName . '/main.min.js',
                    
                    // Mage util paths
                    '/static/frontend/*/mage/' . $moduleName . '.js',
                    '/static/frontend/*/mage/' . $moduleName . '.min.js',
                    '/static/frontend/*/mage/utils/' . $moduleName . '.js',
                    '/static/frontend/*/mage/utils/' . $moduleName . '.min.js',
                    
                    // jQuery modules
                    '/static/frontend/*/jquery/' . $moduleName . '.js',
                    '/static/frontend/*/jquery/' . $moduleName . '.min.js',
                    '/static/frontend/*/jquery/ui-modules/' . $moduleName . '.js',
                    '/static/frontend/*/jquery/ui-modules/' . $moduleName . '.min.js',
                    
                    // Magento UI
                    '/static/frontend/*/Magento_Ui/js/' . $moduleName . '.js',
                    '/static/frontend/*/Magento_Ui/js/' . $moduleName . '.min.js',
                    '/static/frontend/*/Magento_Ui/js/lib/' . $moduleName . '.js',
                    '/static/frontend/*/Magento_Ui/js/lib/' . $moduleName . '.min.js',
                ];
                
                // Replace hard-coded paths with wildcards to make them more flexible
                $possiblePaths = str_replace('frontend/Smartwave/porto_rtl/ar_SA', 'frontend/*', $possiblePaths);
                
                foreach ($possiblePaths as $path) {
                    // Try to find this pattern in the HTML and replace it
                    $pathPattern = str_replace('*', '[^"\']+', preg_quote($path, '/'));
                    $pattern = '/([\'"])(' . $pathPattern . ')([\'"])/i';
                    
                    $html = preg_replace_callback(
                        $pattern,
                        function($matches) use ($cdnBaseUrl, &$replacementCount, &$replacedUrls) {
                            $url = $matches[2];
                            
                            // Determine path to use in CDN URL
                            $cdnPath = '';
                            if (strpos($url, '/static/') === 0) {
                                $cdnPath = substr($url, 8); // Remove '/static/'
                            } elseif (strpos($url, '/media/') === 0) {
                                $cdnPath = substr($url, 7); // Remove '/media/'
                            }
                            
                            if (empty($cdnPath)) {
                                return $matches[0];
                            }
                            
                            $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                            $replacementCount++;
                            $replacedUrls[$url] = $cdnUrl;
                            
                            return $matches[1] . $cdnUrl . $matches[3];
                        },
                        $html
                    );
                }
            }
        }
		// Handle require.js text plugin usage
        $textPattern = '/text!([\'"]+)?([^\'"\!]+)([\'"]+)?/i';
        if (preg_match_all($textPattern, $html, $textMatches)) {
            foreach ($textMatches[2] as $index => $url) {
                // Process only static/media URLs
                if (strpos($url, '/static/') === 0 || strpos($url, '/media/') === 0) {
                    $originalUrl = $url;
                    
                    // Determine path to use in CDN URL
                    $cdnPath = '';
                    if (strpos($url, '/static/') === 0) {
                        $cdnPath = substr($url, 8); // Remove '/static/'
                    } elseif (strpos($url, '/media/') === 0) {
                        $cdnPath = substr($url, 7); // Remove '/media/'
                    }
                    
                    if (empty($cdnPath)) {
                        continue;
                    }
                    
                    // Create CDN URL
                    $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
                    
                    // Get the full text plugin reference
                    $fullMatch = $textMatches[0][$index];
                    $quote = $textMatches[1][$index] ?? '';
                    $endQuote = $textMatches[3][$index] ?? '';
                    
                    // Create replacement
                    $replacement = 'text!' . $quote . $cdnUrl . $endQuote;
                    
                    // Replace in HTML
                    $html = str_replace($fullMatch, $replacement, $html);
                    $replacementCount++;
                    $replacedUrls[$originalUrl] = $cdnUrl;
                }
            }
        }
        
        return $html;
    }

    /**
     * Process a module path for replacement
     * 
     * @param string $html The HTML content
     * @param string $modulePath The module path to process
     * @param string $cdnBaseUrl The CDN base URL
     * @param int &$replacementCount Reference to the replacement counter
     * @param array &$replacedUrls Reference to the replaced URLs array
     * @return string The modified HTML
     */
    private function processModulePath($html, $modulePath, $cdnBaseUrl, &$replacementCount, &$replacedUrls)
    {
        // Skip if not a static or media URL
        if (strpos($modulePath, '/static/') !== 0 && strpos($modulePath, '/media/') !== 0) {
            return $html;
        }
        
        // Determine path to use in CDN URL
        $cdnPath = '';
        if (strpos($modulePath, '/static/') === 0) {
            $cdnPath = substr($modulePath, 8); // Remove '/static/'
        } elseif (strpos($modulePath, '/media/') === 0) {
            $cdnPath = substr($modulePath, 7); // Remove '/media/'
        }
        
        if (empty($cdnPath)) {
            return $html;
        }
        
        // Create full CDN URL
        $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
        
        // Replace the module path in different contexts
        
        // 1. In data-requiremodule attribute
        $pattern = '/(data-requiremodule=[\'"])' . preg_quote($modulePath, '/') . '([\'"])/i';
        $html = preg_replace_callback(
            $pattern,
            function($matches) use ($cdnUrl, &$replacementCount, &$replacedUrls, $modulePath) {
                $replacementCount++;
                $replacedUrls[$modulePath] = $cdnUrl;
                return $matches[1] . $cdnUrl . $matches[2];
            },
            $html
        );
        
        // 2. In script src attribute
        $pattern = '/(src=[\'"])' . preg_quote($modulePath, '/') . '([\'"])/i';
        $html = preg_replace_callback(
            $pattern,
            function($matches) use ($cdnUrl, &$replacementCount, &$replacedUrls, $modulePath) {
                $replacementCount++;
                $replacedUrls[$modulePath] = $cdnUrl;
                return $matches[1] . $cdnUrl . $matches[2];
            },
            $html
        );
        
        // 3. In RequireJS paths or maps
        $pattern = '/([\'"])' . preg_quote($modulePath, '/') . '([\'"])/i';
        $html = preg_replace_callback(
            $pattern,
            function($matches) use ($cdnUrl, &$replacementCount, &$replacedUrls, $modulePath) {
                $replacementCount++;
                $replacedUrls[$modulePath] = $cdnUrl;
                return $matches[1] . $cdnUrl . $matches[2];
            },
            $html
        );
        
        return $html;
    }
	/**
     * Process JSON data recursively to find and replace URLs
     *
     * @param array $jsonData
     * @param callable $callback Function to call with each URL
     * @return void
     */
    private function processJsonForUrls($jsonData, $callback)
    {
        foreach ($jsonData as $key => $value) {
            if (is_array($value)) {
                $this->processJsonForUrls($value, $callback);
            } elseif (is_string($value)) {
                // Check if this value looks like a URL
                if ((strpos($value, '/static/') === 0 || strpos($value, '/media/') === 0) &&
                    (strpos($value, '.js') !== false || strpos($value, '.css') !== false || 
                     strpos($value, '.png') !== false || strpos($value, '.jpg') !== false ||
                     strpos($value, '.jpeg') !== false || strpos($value, '.gif') !== false ||
                     strpos($value, '.svg') !== false)) {
                    $callback($value);
                }
            }
        }
    }
    
    /**
     * Replace a specific URL in HTML content
     * This method ensures exact replacements to maintain file order
     *
     * @param string $html
     * @param string $url
     * @param string $cdnBaseUrl
     * @param string $baseUrl
     * @param string $secureBaseUrl
     * @param array $safeFileTypes
     * @param array $criticalFiles
     * @param int &$replacementCount
     * @param array &$replacedUrls
     * @return string
     */
    private function processUrl($html, $url, $cdnBaseUrl, $baseUrl, $secureBaseUrl, $safeFileTypes, $criticalFiles, &$replacementCount, &$replacedUrls)
    {
        try {
            // Skip if URL is empty
            if (empty($url)) {
                return $html;
            }
            
            // Normalize URL (remove domain if present)
            $normalizedUrl = $url;
            if (strpos($url, 'http') === 0) {
                $parsedUrl = parse_url($url);
                if (isset($parsedUrl['path'])) {
                    $normalizedUrl = $parsedUrl['path'];
                } else {
                    return $html;
                }
            }
            
            // Ensure URL starts with a slash
            if (strpos($normalizedUrl, '/') !== 0) {
                $normalizedUrl = '/' . $normalizedUrl;
            }
            
            // Skip if not a static or media URL
            if (strpos($normalizedUrl, '/static/') !== 0 && strpos($normalizedUrl, '/media/') !== 0) {
                return $html;
            }
            
            // Skip URLs already in cache
            $cacheKey = md5($normalizedUrl);
            if (isset($this->replacedUrlsCache[$cacheKey]) || isset($this->skippedUrlsCache[$cacheKey])) {
                return $html;
            }
            
            // Skip critical files - exact matching
            $shouldSkip = false;
            foreach ($criticalFiles as $criticalFile) {
                // Use exact matching instead of partial
                if ($normalizedUrl === $criticalFile || $normalizedUrl === '/static' . $criticalFile) {
                    $shouldSkip = true;
                    $this->helper->log("Skipping critical file: {$normalizedUrl}", 'debug');
                    break;
                }
            }
			// Special handling for jQuery UI and Magento UI modules
            if (strpos($normalizedUrl, 'jquery/ui-modules/') !== false || 
                strpos($normalizedUrl, 'Magento_Ui/js/') !== false ||
                strpos($normalizedUrl, 'mage/') !== false) {
                
                // Additional check to ensure this is not a critical file
                $isJqueryUiModule = strpos($normalizedUrl, 'jquery/ui-modules/') !== false;
                $isMagentoUiModule = strpos($normalizedUrl, 'Magento_Ui/js/') !== false;
                $isMageUtils = strpos($normalizedUrl, 'mage/utils/') !== false;
                
                if ($isJqueryUiModule || $isMagentoUiModule || $isMageUtils) {
                    // Log that we're processing these files specially
                    $this->helper->log("Processing special module: {$normalizedUrl}", 'debug');
                    
                    // Override the critical file check
                    $shouldSkip = false;
                }
            }
            
            if ($shouldSkip) {
                $this->skippedUrlsCache[$cacheKey] = true;
                return $html;
            }
            
            // Determine path to use in CDN URL
            $cdnPath = '';
            if (strpos($normalizedUrl, '/static/') === 0) {
                $cdnPath = substr($normalizedUrl, 8); // Remove '/static/'
            } elseif (strpos($normalizedUrl, '/media/') === 0) {
                $cdnPath = substr($normalizedUrl, 7); // Remove '/media/'
            }
            
            if (empty($cdnPath)) {
                $this->skippedUrlsCache[$cacheKey] = true;
                return $html;
            }
            
            // Create full CDN URL - make sure there's no double slash
            $cdnUrl = rtrim($cdnBaseUrl, '/') . '/' . ltrim($cdnPath, '/');
            
            // Process various forms of URL
            $urlVariations = [
                $normalizedUrl,                  // /static/path/to/file.ext
                ltrim($normalizedUrl, '/'),      // static/path/to/file.ext
            ];
            
            // Also add domain versions if we have the base URL
            if (!empty($baseUrl)) {
                $urlVariations[] = $baseUrl . ltrim($normalizedUrl, '/');
                $urlVariations[] = rtrim($baseUrl, '/') . $normalizedUrl;
            }
            
            if (!empty($secureBaseUrl) && $secureBaseUrl !== $baseUrl) {
                $urlVariations[] = $secureBaseUrl . ltrim($normalizedUrl, '/');
                $urlVariations[] = rtrim($secureBaseUrl, '/') . $normalizedUrl;
            }
            
            // Special handling for RequireJS modules
            if (strpos($normalizedUrl, '.js') !== false) {
                // Extract potential module name from URL
                $potentialModule = '';
                if (preg_match('/\/static\/frontend\/[^\/]+\/[^\/]+\/[^\/]+\/(.+?)\.js/', $normalizedUrl, $moduleMatches)) {
                    $potentialModule = $moduleMatches[1];
                    
                    // Replace slashes with underscores for module names like 'jquery/ui-modules/widget'
                    $moduleVariations = [
                        $potentialModule,
                        str_replace('/', '_', $potentialModule)
                    ];
                    
                    foreach ($moduleVariations as $module) {
                        // Look for this module in RequireJS configuration
                        $modulePattern = '/([\'"]{1})' . preg_quote($module, '/') . '([\'"]{1})\s*:\s*([\'"]{1})([^\'")]+)([\'"]{1})/i';
                        $html = preg_replace_callback(
                            $modulePattern,
                            function($matches) use ($cdnUrl, &$replacementCount, $normalizedUrl) {
                                // Only replace the value if it points to a static URL
                                if (strpos($matches[4], '/static/') === 0 || strpos($matches[4], '/media/') === 0) {
                                    $replacementCount++;
                                    return $matches[1] . $matches[2] . $matches[3] . $cdnUrl . $matches[5];
                                }
                                return $matches[0];
                            },
                            $html
                        );
                    }
                }
            }
            
            // Store original HTML for comparison
            $originalHtml = $html;
			// Perform replacements for all variations
            foreach (array_unique($urlVariations) as $urlVar) {
                // Skip empty variations
                if (empty($urlVar)) {
                    continue;
                }
                
                // Process absolute URLs with domain
                if (strpos($urlVar, 'http') === 0) {
                    if (strpos($html, $urlVar) !== false) {
                        $html = str_replace($urlVar, $cdnUrl, $html);
                    }
                    continue;
                }
                
                // Try different context replacements
                $contexts = [
                    // In HTML attributes with double quotes
                    ['pattern' => '/(\s(?:src|href|data-[^=]*)=")(' . preg_quote($urlVar, '/') . ')(")/i'],
                    // In HTML attributes with single quotes
                    ['pattern' => "/(\s(?:src|href|data-[^=]*)=')(" . preg_quote($urlVar, '/') . ")(')/" ],
                    // In CSS url() with double quotes
                    ['pattern' => '/url\("(' . preg_quote($urlVar, '/') . ')"\)/'],
                    // In CSS url() with single quotes
                    ['pattern' => "/url\('(" . preg_quote($urlVar, '/') . ")'\\)/"],
                    // In CSS url() without quotes
                    ['pattern' => '/url\((' . preg_quote($urlVar, '/') . ')\)/'],
                    // RequireJS text plugin
                    ['pattern' => '/text!"(' . preg_quote($urlVar, '/') . ')"/', 'replacementIndex' => 1],
                    ['pattern' => "/text!'(" . preg_quote($urlVar, '/') . ")'/" , 'replacementIndex' => 1],
                    // Quoted URLs in JavaScript
                    ['pattern' => '/(["\'])(' . preg_quote($urlVar, '/') . ')(["\'])/']
                ];
                
                foreach ($contexts as $context) {
                    $pattern = $context['pattern'];
                    $replacementIndex = isset($context['replacementIndex']) ? $context['replacementIndex'] : 2; // Default to 2nd group
                    
                    $html = preg_replace_callback(
                        $pattern,
                        function($matches) use ($cdnUrl, &$replacementCount, $replacementIndex) {
                            $replacementCount++;
                            $matches[$replacementIndex] = $cdnUrl;
                            return implode('', array_slice($matches, 1)); // Skip the full match at index 0
                        },
                        $html
                    );
                }
            }
            
            // Special handling for data-mage-init attributes
            $dataMageInitPattern = '/data-mage-init=[\'"](.+?)[\'"]/s';
            if (preg_match_all($dataMageInitPattern, $html, $mageInitMatches)) {
                foreach ($mageInitMatches[1] as $index => $jsonValue) {
                    try {
                        // Check if JSON is valid
                        $mageInitJson = json_decode($jsonValue, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $modified = false;
                            
                            // Recursive function to search and replace URLs in the JSON
                            $processJsonUrls = function(&$json) use (&$processJsonUrls, $normalizedUrl, $cdnUrl, &$modified) {
                                if (is_array($json)) {
                                    foreach ($json as $key => &$value) {
                                        if (is_string($value) && $value === $normalizedUrl) {
                                            $value = $cdnUrl;
                                            $modified = true;
                                        } elseif (is_array($value)) {
                                            $processJsonUrls($value);
                                        }
                                    }
                                }
                            };
                            
                            $processJsonUrls($mageInitJson);
                            
                            if ($modified) {
                                $newJsonValue = json_encode($mageInitJson);
                                $fullMatch = $mageInitMatches[0][$index];
                                $newAttr = str_replace($jsonValue, $newJsonValue, $fullMatch);
                                $html = str_replace($fullMatch, $newAttr, $html);
                                $replacementCount++;
                            }
                        }
                    } catch (\Exception $e) {
                        // Invalid JSON, skip
                        $this->helper->log("Error processing data-mage-init JSON: " . $e->getMessage(), 'debug');
                    }
                }
            }
			// Provide special handling for image srcset attributes
            $srcsetPattern = '/srcset=[\'"]([^\'"]+)[\'"]/i';
            if (preg_match_all($srcsetPattern, $html, $srcsetMatches)) {
                foreach ($srcsetMatches[1] as $index => $srcsetValue) {
                    // Split the srcset into individual entries
                    $srcsetEntries = explode(',', $srcsetValue);
                    $modified = false;
                    
                    foreach ($srcsetEntries as &$entry) {
                        $parts = preg_split('/\s+/', trim($entry), 2);
                        if (count($parts) > 0) {
                            $entryUrl = $parts[0];
                            $descriptor = isset($parts[1]) ? ' ' . $parts[1] : '';
                            
                            if (strpos($entryUrl, $normalizedUrl) !== false) {
                                $entry = $cdnUrl . $descriptor;
                                $modified = true;
                            }
                        }
                    }
                    
                    if ($modified) {
                        $newSrcset = implode(', ', $srcsetEntries);
                        $fullMatch = $srcsetMatches[0][$index];
                        $newAttr = str_replace($srcsetValue, $newSrcset, $fullMatch);
                        $html = str_replace($fullMatch, $newAttr, $html);
                        $replacementCount++;
                    }
                }
            }
            
            // If we changed anything, log it and update cache
            if ($html !== $originalHtml) {
                $this->helper->log("Replaced URL: {$normalizedUrl} with {$cdnUrl}", 'debug');
                $replacedUrls[$normalizedUrl] = $cdnUrl;
                $this->replacedUrlsCache[$cacheKey] = true;
            }
            
            return $html;
        } catch (\Exception $e) {
            $this->helper->log("Error processing URL {$url}: " . $e->getMessage(), 'error');
            return $html;
        }
    }
}