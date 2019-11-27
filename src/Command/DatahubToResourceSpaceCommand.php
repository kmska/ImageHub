<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use App\Utils\StringUtil;
use DOMDocument;
use DOMXPath;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\Exception\OaipmhException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DatahubToResourceSpaceCommand extends Command implements ContainerAwareInterface, LoggerAwareInterface
{
    private $datahubRecordIdPrefix;

    private $datahubUrl;
    private $datahubLanguage;
    private $namespace;
    private $metadataPrefix;
    private $dataDefinition;
    private $relatedWorksXpath;
    private $publicUse;

    private $datahubEndpoint;
    private $verbose;

    private $resourceSpace;

    private $resourceSpaceData;
    private $datahubData;
    private $resourceIds;
    private $relations;
    private $publicImages;

    protected function configure()
    {
        $this
            ->setName('app:datahub-to-resourcespace')
            ->addArgument('rs_id', InputArgument::OPTIONAL, 'The ID (ref) of the resource in ResourceSpace that needs updating')
            ->addArgument('url', InputArgument::OPTIONAL, 'The URL of the Datahub')
            ->setDescription('')
            ->setHelp('');
    }

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->verbose = $input->getOption('verbose');

        $resourceSpaceId = $input->getArgument('rs_id');
        if($resourceSpaceId != null && !preg_match('/^[0-9]+$/', $resourceSpaceId)) {
            $resourceSpaceId = null;
        }

        $this->datahubUrl = $input->getArgument('url');
        if (!$this->datahubUrl) {
            $this->datahubUrl = $this->container->getParameter('datahub_url');
        }

        $this->datahubRecordIdPrefix = $this->container->getParameter('datahub_record_id_prefix');

        $this->datahubLanguage = $this->container->getParameter('datahub_language');
        $this->namespace = $this->container->getParameter('datahub_namespace');
        $this->metadataPrefix = $this->container->getParameter('datahub_metadataprefix');
        $this->relatedWorksXpath = $this->container->getParameter('datahub_related_works_xpath');
        $this->dataDefinition = $this->container->getParameter('datahub_data_definition');
        $this->publicUse = $this->container->getParameter('public_use');

        $this->resourceSpace = new ResourceSpace($this->container);

        $this->resourceSpaceData = $this->resourceSpace->getCurrentResourceSpaceData();

        if ($this->resourceSpaceData === null) {
            $this->logger->error( 'Error: no resourcespace data.');
            return;
        }

        $this->resourceIds = array();
        $this->datahubData = array();
        $this->publicImages = array();
        $idsToDo = array();
        $idsDone = array();

        foreach ($this->resourceSpaceData as $resourceId => $oldData) {
            if (!empty($oldData['sourceinvnr'])) {
                $recordId = $this->datahubRecordIdPrefix . StringUtil::cleanObjectNumber($oldData['sourceinvnr']);
                if ($this->resourceSpace->isPublicUse($oldData, $this->publicUse)) {
                    $this->publicImages[] = $resourceId;
                }
                if(!array_key_exists($recordId, $this->resourceIds)) {
                    $this->resourceIds[$recordId] = array($resourceId);
                } else {
                    $this->resourceIds[$recordId][] = $resourceId;
                }
                if($resourceSpaceId == null || $resourceSpaceId != null && $resourceSpaceId == $resourceId) {
                    $this->getDatahubData($recordId);

                    $idsDone[] = $recordId;
                    foreach ($this->relations[$recordId] as $relatedId => $relation) {
                        if (!in_array($relatedId, $idsToDo)) {
                            $idsToDo[] = $relatedId;
                        }
                    }
                }
            }
        }

        $checkedAll = false;
        while(!$checkedAll) {
            $checkedAll = true;
            foreach($idsToDo as $index => $id) {
                if(!in_array($id, $idsDone)) {
                    $checkedAll = false;

                    $this->getDatahubData($id);

                    $idsDone[] = $id;
                    foreach ($this->relations[$id] as $relatedId => $relation) {
                        if (!in_array($relatedId, $idsToDo)) {
                            $idsToDo[] = $relatedId;
                        }
                    }
                }
            }
        }


        if (count($this->datahubData) > 0) {

            $this->addAllRelations();
            $this->fixSortOrders();

            foreach ($this->datahubData as $recordId => $newData) {
                if(array_key_exists($recordId, $this->resourceIds)) {
                    foreach($this->resourceIds[$recordId] as $resourceId) {

                        $isThisPublic = in_array($resourceId, $this->publicImages);

                        $relations = '';
                        foreach($this->relations[$recordId] as $k => $v) {
                            if(array_key_exists($k, $this->resourceIds)) {
                                foreach($this->resourceIds[$k] as $otherResourceId) {
                                    if(array_key_exists($otherResourceId, $this->resourceSpaceData)) {
                                        $isOtherPublic = in_array($otherResourceId, $this->publicImages);
                                        // Add relations only when one of the following coditions is met:
                                        // - The 'related' resource is actually itself
                                        // - Both resources are for public use (relations between works)
                                        // - This resource is not meant for publication, but the other is (public images added to research images)
                                        if($resourceId == $otherResourceId
                                            || $isThisPublic && $isOtherPublic
                                            || !$isThisPublic && $this->resourceSpaceData[$resourceId]['sourceinvnr'] == $this->resourceSpaceData[$otherResourceId]['sourceinvnr']) {
                                            $relations .= (empty($relations) ? '' : PHP_EOL) . $otherResourceId;
                                        }
                                    }
                                }
                            }
                        }

                        $newData['relatedrecords'] = $relations;

                        $this->updateResourceSpaceFields($resourceId, $newData);
                    }
                }
            }
        } else {
            $this->logger->warning('Warning: no data found.');
        }
    }

    function getDatahubData($recordId)
    {
        // Add a reference to itself
        $this->relations[$recordId] = array(
            $recordId => array(
                'related_work_type' => 'relation',
                'record_id'         => $recordId,
                'sort_order'        => 1
            )
        );

        $newData = array();
        try {
            if (!$this->datahubEndpoint)
                $this->datahubEndpoint = Endpoint::build($this->datahubUrl . '/oai');

            $record = $this->datahubEndpoint->getRecord($recordId, $this->metadataPrefix);
            $data = $record->GetRecord->record->metadata->children($this->namespace, true);
            $domDoc = new DOMDocument;
            $domDoc->loadXML($data->asXML());
            $xpath = new DOMXPath($domDoc);

            foreach ($this->dataDefinition as $key => $dataDef) {
                if(!array_key_exists('field', $dataDef)) {
                    continue;
                }
                $xpaths = array();
                if(array_key_exists('xpaths', $dataDef)) {
                    $xpaths = $dataDef['xpaths'];
                } else if(array_key_exists('xpath', $dataDef)) {
                    $xpaths[] = $dataDef['xpath'];
                }
                $value = null;
                foreach($xpaths as $xpath_) {
                    $query = $this->buildXpath($xpath_, $this->datahubLanguage);
                    $extracted = $xpath->query($query);
                    if ($extracted) {
                        if (count($extracted) > 0) {
                            foreach ($extracted as $extr) {
                                if ($extr->nodeValue !== 'n/a') {
                                    if($value == null) {
                                        $value = $extr->nodeValue;
                                    }
                                    else if($key != 'keywords' || !in_array($extr->nodeValue, explode(",", $value))) {
                                        $value .= ', ' . $extr->nodeValue;
                                    }
                                }
                            }
                        }
                    }
                }
                if ($value != null) {
                    $newData[$dataDef['field']] = trim($value);
                }
            }

            // Find all related works (hasPart, isPartOf, relatedTo)
            $query = $this->buildXpath($this->relatedWorksXpath, $this->datahubLanguage);
            $domNodes = $xpath->query($query);
            $value = null;
            if ($domNodes) {
                if (count($domNodes) > 0) {
                    foreach ($domNodes as $domNode) {
                        $relatedRecordId = null;
                        $relation = null;
                        $sortOrder = 1;
                        if($domNode->attributes) {
                            for($i = 0; $i < $domNode->attributes->length; $i++) {
                                if($domNode->attributes->item($i)->nodeName == $this->namespace . ':sortorder') {
                                    $sortOrder = $domNode->attributes->item($i)->nodeValue;
                                }
                            }
                        }
                        $childNodes = $domNode->childNodes;
                        foreach ($childNodes as $childNode) {
                            if ($childNode->nodeName == $this->namespace . ':relatedWork') {
                                $objects = $childNode->childNodes;
                                foreach($objects as $object) {
                                    if($object->childNodes) {
                                        foreach($object->childNodes as $objectId) {
                                            if($objectId->attributes) {
                                                for($i = 0; $i < $objectId->attributes->length; $i++) {
                                                    if($objectId->attributes->item($i)->nodeName == $this->namespace . ':type' && $objectId->attributes->item($i)->nodeValue == 'oai') {
                                                        $relatedRecordId = $objectId->nodeValue;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            } else if($childNode->nodeName == $this->namespace . ':relatedWorkRelType') {
                                $objects = $childNode->childNodes;
                                foreach($objects as $object) {
                                    if($object->nodeName == $this->namespace . ':conceptID') {
                                        $relation = substr($object->nodeValue, strrpos($object->nodeValue, '/') + 1);
                                    }
                                }
                            }
                        }

                        if($relatedRecordId != null) {
                            if(!array_key_exists($relatedRecordId, $this->relations[$recordId])) {
                                if ($relation == null) {
                                    $relation = 'relation';
                                }
                                $arr = array(
                                    'related_work_type' => $relation,
                                    'record_id'         => $relatedRecordId,
                                    'sort_order'        => $sortOrder
                                );
                                $this->relations[$recordId][$relatedRecordId] = $arr;
                            }
                        }
                    }
                }
            }
        }
        catch(OaipmhException $e) {
//            echo 'Record id ' . $recordId . ' error: ' . $e . PHP_EOL;
            $this->logger->error('Record id ' . $recordId . ' error: ' . $e);
        }
        catch(HttpException $e) {
//            echo 'Record id ' . $recordId . ' error: ' . $e . PHP_EOL;
            $this->logger->error('Record id ' . $recordId . ' error: ' . $e);
        }

        // Combine earliest and latest date into one
        if(array_key_exists('earliestdate', $newData)) {
            if(array_key_exists('latestdate', $newData)) {
                $newData['datecreatedofartwork'] = StringUtil::getDateRange($newData['earliestdate'], $newData['latestdate']);
                unset($newData['latestdate']);
            } else {
                $newData['datecreatedofartwork'] = StringUtil::getDateRange($newData['earliestdate'], $newData['earliestdate']);
            }
            unset($newData['earliestdate']);
        } else if(array_key_exists('latestdate', $newData)) {
            $newData['datecreatedofartwork'] = StringUtil::getDateRange($newData['latestdate'], $newData['latestdate']);
            unset($newData['latestdate']);
        }

        $this->datahubData[$recordId] = $newData;
    }

    private function addAllRelations()
    {
        $relations = array();

        // Initialize the array containing all directly related works
        foreach($this->relations as $recordId => $value) {
            $relations[$recordId] = $value;
        }

        // Loop through all records and keep adding relations until all (directly or indirectly) related works contain references to each other
        $relationsChanged = true;
        while($relationsChanged) {
            $relationsChanged = false;
            foreach($relations as $recordId => $related) {
                foreach($relations as $otherid => $otherRelation) {
                    if(array_key_exists($recordId, $otherRelation)) {
                        foreach ($related as $relatedData) {
                            if (!array_key_exists($relatedData['record_id'], $otherRelation)) {
                                $relations[$otherid][$relatedData['record_id']] = array(
                                    'related_work_type' => 'relation',
                                    'record_id'         => $relatedData['record_id'],
                                    'sort_order'        => $relatedData['sort_order']
                                );
                                $relationsChanged = true;
                            }
                        }
                    }
                }
            }
        }

        // Add the newly found relations to the appropriate related_works arrays
        foreach($relations as $recordId => $related) {
            foreach($related as $relatedData) {
                if(array_key_exists($relatedData['record_id'], $this->relations)) {
                    if (array_key_exists($recordId, $this->relations)) {
                        if (!array_key_exists($relatedData['record_id'], $this->relations[$recordId])) {
                            $this->relations[$recordId][$relatedData['record_id']] = array(
                                'related_work_type' => 'relation',
                                'record_id'         => $relatedData['record_id'],
                                'sort_order'        => $relatedData['sort_order']
                            );
                        }
                    }
                }
            }
        }
    }

    private function isHigherOrder($type, $highestType)
    {
        if($highestType == null) {
            return true;
        } else if($highestType == 'isPartOf') {
            return false;
        } else if($highestType == 'relation') {
            return $type == 'isPartOf';
        } else if($highestType == 'hasPart') {
            return $type == 'isPartOf' || $type == 'relation';
        } else {
            return true;
        }
    }

    private function fixSortOrders()
    {
        foreach($this->relations as $recordId => $value) {
            if(count($value) > 1) {

                // Sort based on record ids to ensure all related_works for related record ids contain exactly the same information in the same order
                ksort($this->relations[$recordId]);

                // Check for colliding sort orders
                $mismatch = true;
                while($mismatch) {
                    $mismatch = false;
                    foreach ($this->relations[$recordId] as $relatedId => $relatedWork) {
                        $order = $this->relations[$recordId][$relatedId]['sort_order'];

                        foreach ($this->relations[$recordId] as $otherId => $otherWork) {

                            // Find colliding sort orders
                            if ($relatedId != $otherId && $this->relations[$recordId][$otherId]['sort_order'] == $order) {

                                // Upon collision, find out which relation has the highest priority
                                $highest = null;
                                $highestType = 'none';
                                foreach ($this->relations[$recordId] as $relatedRef => $data) {
                                    if ($this->relations[$recordId][$relatedRef]['sort_order'] == $order
                                        && $this->isHigherOrder($this->relations[$recordId][$relatedRef]['related_work_type'], $highestType)) {
                                        $highest = $relatedRef;
                                        $highestType = $this->relations[$recordId][$relatedRef]['related_work_type'];
                                    }
                                }

                                // Increment the sort order of all related works with the same or higher sort order with one,
                                // except the one with the highest priority
                                foreach ($this->relations[$recordId] as $relatedRef => $data) {
                                    if ($relatedRef != $highest && $this->relations[$recordId][$relatedRef]['sort_order'] >= $order) {
                                        $this->relations[$recordId][$relatedRef]['sort_order'] = $this->relations[$recordId][$relatedRef]['sort_order'] + 1;
                                    }
                                }


                                $mismatch = true;
                                break;
                            }
                        }
                    }
                }

                // Sort related works based on sort_order
                uasort($this->relations[$recordId], array('App\Command\DatahubToResourceSpaceCommand', 'sortRelatedWorks'));

            }
        }
    }

    private function sortRelatedWorks($a, $b)
    {
        return $a['sort_order'] - $b['sort_order'];
    }

    function updateResourceSpaceFields($resourceId, $newData)
    {
        if(!array_key_exists($resourceId, $this->resourceSpaceData)) {
            return;
        }

        $oldData = $this->resourceSpaceData[$resourceId];

        $updatedFields = 0;
        foreach($newData as $key => $value) {
            $update = false;
            if(!array_key_exists($key, $oldData)) {
                if($this->verbose) {
//                    echo 'Field ' . $key . ' does not exist, should be ' . $value . PHP_EOL;
                    $this->logger->error('Field ' . $key . ' does not exist, should be ' . $value);
                }
                $update = true;
            } else if($key == 'keywords') {
                $explodeVal = explode(',', $value);
                $explodeRS = explode(',', $oldData[$key]);
                $hasAll = true;
                foreach($explodeVal as $val) {
                    $has = false;
                    foreach($explodeRS as $rs) {
                        if($rs == $val) {
                            $has = true;
                            break;
                        }
                    }
                    if(!$has) {
                        $hasAll = false;
                        break;
                    }
                }
                if(!$hasAll) {
                    if($this->verbose) {
//                        echo 'Mismatching field ' . $key . ', should be ' . $value . ', is ' . $oldData[$key] . PHP_EOL;
                        $this->logger->error('Mismatching field ' . $key . ', should be ' . $value . ', is ' . $oldData[$key]);
                    }
                    $update = true;
                }
            } else {
                if($oldData[$key] != $value) {
                    if($this->verbose) {
//                        echo 'Mismatching field ' . $key . ', should be ' . $value . ', is ' . $oldData[$key] . PHP_EOL;
                        $this->logger->error('Mismatching field ' . $key . ', should be ' . $value . ', is ' . $oldData[$key]);
                    }
                    $update = true;
                }
            }
            if($update) {
                $result = $this->resourceSpace->updateField($resourceId, $key, $value);
                if($result !== 'true') {
//                    echo 'Error updating field ' . $key . ' for resource id ' . $resourceId . ':' . PHP_EOL . $result . PHP_EOL;
                    $this->logger->error('Error updating field ' . $key . ' for resource id ' . $resourceId . ':' . PHP_EOL . $result);
                } else {
                    $updatedFields++;
                }
            }
        }
        if($this->verbose) {
//            echo 'Updated ' . $updatedFields . ' fields for resource id ' . $resourceId . PHP_EOL;
            $this->logger->info('Updated ' . $updatedFields . ' fields for resource id ' . $resourceId);
        }
    }

    // Build the xpath based on the provided namespace
    private function buildXpath($xpath, $language)
    {
        $xpath = str_replace('{language}', $language, $xpath);
        $xpath = str_replace('[@', '[@' . $this->namespace . ':', $xpath);
        $xpath = str_replace('[@' . $this->namespace . ':xml:', '[@xml:', $xpath);
        $xpath = preg_replace('/\[([^@])/', '[' . $this->namespace . ':${1}', $xpath);
        $xpath = preg_replace('/\/([^\/])/', '/' . $this->namespace . ':${1}', $xpath);
        if(strpos($xpath, '/') !== 0) {
            $xpath = $this->namespace . ':' . $xpath;
        }
        $xpath = 'descendant::' . $xpath;
        return $xpath;
    }
}
