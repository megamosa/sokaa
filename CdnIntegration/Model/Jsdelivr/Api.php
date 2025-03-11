<?php
namespace MagoArab\CdnIntegration\Model\Jsdelivr;

use MagoArab\CdnIntegration\Helper\Data as Helper;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class Api
{
    /**
     * jsDelivr Purge API URL
     */
    const PURGE_API_URL = 'https://purge.jsdelivr.net';

    /**
     * @var Helper
     */
    protected $helper;

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Helper $helper
     * @param Curl $curl
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        Helper $helper,
        Curl $curl,
        Json $json,
        LoggerInterface $logger
    ) {
        $this->helper = $helper;
        $this->curl = $curl;
        $this->json = $json;
        $this->logger = $logger;
    }
/**
 * Purge specific file types
 *
 * @param array $fileTypes
 * @return bool
 */
public function purgeFileTypes(array $fileTypes = ['js', 'css'])
{
    $username = $this->helper->getGithubUsername();
    $repository = $this->helper->getGithubRepository();
    $branch = $this->helper->getGithubBranch();
    
    if (!$username || !$repository) {
        $this->helper->log('GitHub configuration is incomplete', 'error');
        return false;
    }
    
    $success = true;
    
    foreach ($fileTypes as $type) {
        // Purge by file type using the jsDelivr wildcard syntax
        $purgeUrl = "https://purge.jsdelivr.net/gh/{$username}/{$repository}@{$branch}/**/*.{$type}";
        
        $this->helper->log("Purging all .{$type} files from jsDelivr", 'info');
        
        try {
            $this->curl = new \Magento\Framework\HTTP\Client\Curl();
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->setOption(CURLOPT_TIMEOUT, 30);
            
            $this->curl->get($purgeUrl);
            
            $response = $this->curl->getBody();
            $statusCode = $this->curl->getStatus();
            
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->helper->log("Successfully purged all .{$type} files from jsDelivr", 'info');
            } else {
                $success = false;
                $this->helper->log("Failed to purge .{$type} files. Status: {$statusCode}, Response: {$response}", 'error');
            }
        } catch (\Exception $e) {
            $success = false;
            $this->helper->log("Exception when purging .{$type} files: " . $e->getMessage(), 'error');
        }
    }
    
    return $success;
}
 /**
 * Purge all files in the repository from jsDelivr cache
 *
 * @return bool
 */
public function purgeAll()
{
    try {
        $username = $this->helper->getGithubUsername();
        $repository = $this->helper->getGithubRepository();
        $branch = $this->helper->getGithubBranch();
        
        if (!$username || !$repository) {
            $this->helper->log('GitHub configuration is incomplete', 'error');
            throw new \Exception('GitHub configuration is incomplete');
        }
        
        // Create the URL to purge everything in the repository
        // Format: https://purge.jsdelivr.net/gh/username/repository@branch/
        $purgeUrl = "https://purge.jsdelivr.net/gh/{$username}/{$repository}@{$branch}/";
        
        $this->helper->log("Purging all files from jsDelivr using URL: {$purgeUrl}", 'info');
        
        // Set up the request with proper headers
        $this->curl = new \Magento\Framework\HTTP\Client\Curl();
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Accept', 'application/json');
        $this->curl->addHeader('User-Agent', 'MagoArab-Magento-CDN/1.0');
        
        // Set longer timeout for the purge request
        $this->curl->setOption(CURLOPT_TIMEOUT, 30);
        
        // Make the actual request - purge endpoint doesn't need a POST body
        $this->curl->get($purgeUrl);
        
        $response = $this->curl->getBody();
        $statusCode = $this->curl->getStatus();
        
        $this->helper->log("jsDelivr Purge response code: {$statusCode}", 'debug');
        $this->helper->log("jsDelivr Purge response: {$response}", 'debug');
        
        $success = ($statusCode >= 200 && $statusCode < 300);
        
        if ($success) {
            $this->helper->log("Successfully purged all files from jsDelivr", 'info');
            
            // Also purge by file type to ensure complete purge
            $this->purgeFileTypes(['js', 'css', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'woff', 'woff2', 'ttf']);
            
            // Purge specific paths related to RequireJS
            $this->purgeRequireJsPaths();
            
            return true;
        }
        
        $this->helper->log(
            "Failed to purge files. Status: {$statusCode}, Response: {$response}",
            'error'
        );
        
        return false;
    } catch (\Exception $e) {
        $this->helper->log(
            "Exception when purging files. Error: " . $e->getMessage() . "\n" .
            "Stack trace: " . $e->getTraceAsString(),
            'error'
        );
        return false;
    }
}

/**
 * Purge specific paths related to RequireJS
 *
 * @return bool
 */
protected function purgeRequireJsPaths()
{
    $username = $this->helper->getGithubUsername();
    $repository = $this->helper->getGithubRepository();
    $branch = $this->helper->getGithubBranch();
    
    if (!$username || !$repository) {
        return false;
    }
    
    $success = true;
    $commonJsPaths = [
        'mage/utils/',
        'mage/requirejs/',
        'jquery/ui-modules/',
        'Magento_Ui/js/lib/',
        'requirejs/'
    ];
    
    foreach ($commonJsPaths as $path) {
        $purgeUrl = "https://purge.jsdelivr.net/gh/{$username}/{$repository}@{$branch}/{$path}";
        
        $this->helper->log("Purging specific path: {$path}", 'info');
        
        try {
            $this->curl = new \Magento\Framework\HTTP\Client\Curl();
            $this->curl->addHeader('Content-Type', 'application/json');
            $this->curl->addHeader('Accept', 'application/json');
            $this->curl->setOption(CURLOPT_TIMEOUT, 30);
            
            $this->curl->get($purgeUrl);
            
            $response = $this->curl->getBody();
            $statusCode = $this->curl->getStatus();
            
            if ($statusCode < 200 || $statusCode >= 300) {
                $success = false;
                $this->helper->log("Failed to purge path {$path}: {$response}", 'error');
            }
        } catch (\Exception $e) {
            $success = false;
            $this->helper->log("Exception purging path {$path}: " . $e->getMessage(), 'error');
        }
    }
    
    return $success;
}
}