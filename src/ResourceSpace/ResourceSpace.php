<?php

namespace App\ResourceSpace;

use App\Utils\StringUtil;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ResourceSpace
{
    private $apiUrl;
    private $apiUsername;
    private $apiKey;

    public function __construct(ContainerInterface $container)
    {
        // Make sure the API URL does not end with a '?' character
        $this->apiUrl = rtrim($container->getParameter('resourcespace_api_url'), '?');
        $this->apiUsername = $container->getParameter('resourcespace_api_username');
        $this->apiKey = $container->getParameter('resourcespace_api_key');
    }

    public function getCurrentResourceSpaceData()
    {
        $resources = $this->getAllResources();
        $data = array();
        foreach($resources as $resource) {
            $data[$resource['ref']] = $this->getResourceSpaceData($resource['ref']);
        }

        return $data;
    }

    private function getResourceSpaceData($ref)
    {
        $extracted = array();
        $currentData = $this->getResourceInfo($ref);
        if($currentData != null) {
            if(!empty($currentData)) {
                foreach($currentData as $field) {
                    $extracted[$field['name']] = $field['value'];
                }
            }
        }
        return $extracted;
    }

    public function getResourceSpaceIds()
    {
        $resources = $this->getAllResources();
        $resourceIds = array();
        foreach($resources as $resource) {
            $currentData = $this->getResourceInfo($resource['ref']);
            if($currentData == null) {
                continue;
            }
            if(empty($currentData)) {
                continue;
            }
            foreach($currentData as $field) {
                // Match based on originalfilename
                if($field['name'] == 'originalfilename') {
                    $filename = StringUtil::stripExtension($field['value']);
                    $resourceIds[$filename] = $resource['ref'];
                    break;
                }
            }
        }

        return $resourceIds;
    }

    public function getAllResources()
    {
        # We need to supply something to param1, otherwise ResourceSpace returns a 500 (it's become a mandatory argument)
        $allResources = $this->doApiCall('do_search&param1=\'\'');

        if ($allResources == 'Invalid signature') {
            echo 'Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into app/config/parameters.yml.';
//            $this->logger->error('Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into app/config/parameters.yml.');
            return NULL;
        }

        $resources = json_decode($allResources, true);
        return $resources;
    }

    private function getResourceInfo($id)
    {
        $data = $this->doApiCall('get_resource_field_data&param1=' . $id);
        return json_decode($data, true);
    }

    public function updateField($id, $key, $value)
    {
        echo 'ID ' . $id . ', ' . $key . ' = ' . $value . PHP_EOL;
        return $this->doApiCall('update_field&param1=' . $id . '&param2=' . $key . '&param3=' . urlencode($value));
    }

    private function doApiCall($query)
    {
        $query = 'user=' . $this->apiUsername . '&function=' . $query;
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        $data = file_get_contents($url);
        return $data;
    }

    private function getSign($query)
    {
        return hash('sha256', $this->apiKey . $query);
    }
}
