<?php

namespace App\Command;

use App\Entity\DatahubData;
use App\Entity\IIIfManifest;
use App\Entity\ResourceData;
use App\ResourceSpace\ResourceSpace;
use Doctrine\ORM\EntityManagerInterface;
use DOMDocument;
use DOMXPath;
use Exception;
use Phpoaipmh\Endpoint;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class GenerateIIIFManifestsCommand extends Command implements ContainerAwareInterface, LoggerAwareInterface
{
    private $verbose;
    private $cantaloupeUrl;
    private $cantaloupeCurlOpts;
    private $publicUse;
    private $recommendedForPublication;
    private $namespace;
    private $metadataPrefix;

    private $metadataFields;
    private $labelField;
    private $descriptionField;
    private $attributionField;

    private $resourceSpace;
    private $imageData;
    private $publicManifestsAdded;
    private $datahubUrl;
    private $datahubEndpoint;
    private $datahubUsername;
    private $datahubPassword;
    private $datahubPublicId;
    private $datahubSecret;

    private $datahubToken;

    private $serviceUrl;
    private $createTopLevelCollection;

    protected function configure()
    {
        $this
            ->setName('app:generate-iiif-manifests')
            ->addArgument('rs_id', InputArgument::OPTIONAL, 'The ID (ref) of the resource in ResourceSpace for which we want to generate a IIIF manifest')
            ->setDescription('')
            ->setHelp('');
    }

    /**
     * Sets the container.
     */
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

        // Make sure the service URL name ends with a trailing slash
        $this->serviceUrl = rtrim($this->container->getParameter('service_url'), '/') . '/';


        $this->datahubUrl = $this->container->getParameter('datahub_url');

        // Username, password, public ID and secret for datahub PUT requests
        $this->datahubUsername = $this->container->getParameter('datahub_username');
        $this->datahubPassword = $this->container->getParameter('datahub_password');
        $this->datahubPublicId = $this->container->getParameter('datahub_public_id');
        $this->datahubSecret = $this->container->getParameter('datahub_secret');

        $this->namespace = $this->container->getParameter('datahub_namespace');
        $this->metadataPrefix = $this->container->getParameter('datahub_metadataprefix');

        $this->metadataFields = $this->container->getParameter('iiif_metadata_fields');
        $this->labelField = $this->container->getParameter('iiif_label');
        $this->descriptionField = $this->container->getParameter('iiif_description');
        $this->attributionField = $this->container->getParameter('iiif_attribution');

        $this->cantaloupeUrl = $this->container->getParameter('cantaloupe_url');
        $curlOpts = $this->container->getParameter('cantaloupe_curl_opts');
        $this->cantaloupeCurlOpts = array();
        foreach($curlOpts as $key => $value) {
            $this->cantaloupeCurlOpts[constant($key)] = $value;
        }

        $this->resourceSpace = new ResourceSpace($this->container);

        $resourceSpaceId = $input->getArgument('rs_id');
        if(!preg_match('/^[0-9]+$/', $resourceSpaceId)) {
            $resourceSpaceId = null;
        }
        // Always create a top-level collection
        $this->createTopLevelCollection = true;

        $resources = $this->resourceSpace->getAllResources();
        if ($resources === null) {
            $this->logger->error( 'Error: no resourcespace data.');
            return;
        }
        $this->imageData = array();
        $this->publicManifestsAdded = array();

        $this->publicUse = $this->container->getParameter('public_use');
        $this->recommendedForPublication = $this->container->getParameter('recommended_for_publication');
        $em = $this->container->get('doctrine')->getManager();
        //Disable SQL logging to improve performance
        $em->getConnection()->getConfiguration()->setSQLLogger(null);

        foreach($resources as $resource) {
            $resourceId = $resource['ref'];
            $publicData = $em->createQueryBuilder()
                ->select('i')
                ->from(ResourceData::class, 'i')
                ->where('i.id = :id')
                ->andWhere('i.name = :name')
                ->setParameter('id', $resourceId)
                ->setParameter('name', 'is_public')
                ->getQuery()
                ->getResult();
            $isPublic = false;
            foreach($publicData as $data) {
                $isPublic = $data->getValue() === '1';
            }
            $this->getImageData($resourceId, $isPublic);
        }

        // For good measure, sort the image data based on ResourceSpace id
        ksort($this->imageData);

        $this->generateAndStoreManifests($em);
    }

    private function getImageData($resourceId, $isPublic)
    {
        if($isPublic) {
            $url = $this->publicUse['public_folder'];
        } else {
            $url = $this->publicUse['private_folder'];
        }
        $url .= $resourceId;

        $imageData = $this->getCantaloupeData($url);
        if($imageData) {
            $imageData['canvas_base'] = $this->serviceUrl . $resourceId;
            $imageData['service_id'] = $this->cantaloupeUrl . $url . '.tif';
            $imageData['image_url'] = $this->cantaloupeUrl . $url . '.tif/full/full/0/default.jpg';
            $imageData['public_use'] = $isPublic;
            $this->imageData[$resourceId] = $imageData;
        }
    }

    private function getCantaloupeData($resourceId)
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_URL, $this->cantaloupeUrl . $resourceId . '.tif/info.json');
            foreach($this->cantaloupeCurlOpts as $key => $value) {
                curl_setopt($ch, $key, $value);
            }
            $jsonData = curl_exec($ch);
            if (curl_errno($ch)) {
                $this->logger->error(curl_error($ch));
                curl_close($ch);
            } else {
                curl_close($ch);
                $data = json_decode($jsonData);
                if($this->verbose) {
//                echo 'Retrieved image ' . $resourceId . ' from Cantaloupe' . PHP_EOL;
                    $this->logger->info('Retrieved image ' . $resourceId . ' from Cantaloupe');
                }
                return array('height' => $data->height, 'width' => $data->width);
            }
        } catch(Exception $e) {
//            echo $e->getMessage() . PHP_EOL;
            $this->logger->error($e->getMessage());
        }
        return null;
    }

    private function generateAndStoreManifests(EntityManagerInterface $em)
    {
        $validate = $this->container->getParameter('validate_manifests');
        $validatorUrl = $this->container->getParameter('validator_url');

        // Top-level collection containing a link to all manifests
        $manifests = array();

        if($this->createTopLevelCollection) {
            $this->deleteAllManifests($em);
        }

        foreach($this->imageData as $resourceId => $data) {

            $rsData = $em->createQueryBuilder()
                ->select('i')
                ->from(ResourceData::class, 'i')
                ->where('i.id = :id')
                ->setParameter('id', $resourceId)
                ->getQuery()
                ->getResult();
            $data['metadata'] = array();
            $data['label'] = '';
            $data['attribution'] = '';
            $data['description'] = '';
            $data['recommended_for_publication'] = false;
            $data['dh_record_id'] = '';
            foreach($rsData as $d) {
                if($d->getName() == $this->labelField) {
                    $data['label'] = $d->getValue();
                }
                if($d->getName() == $this->descriptionField) {
                    $data['description'] = $d->getValue();
                }
                if($d->getName() == $this->attributionField) {
                    $data['attribution'] = $d->getValue();
                }
                if($d->getName() == 'is_recommended_for_pub') {
                    $data['recommended_for_publication'] = $d->getValue() === '1';
                }
                if($d->getName() == 'dh_record_id') {
                    $data['dh_record_id'] = $d->getValue();
                }
                if($d->getName() == 'related_resources') {
                    $data['related_resources'] = explode(',', $d->getValue());
                }
                foreach ($this->metadataFields as $field => $name) {
                    if($d->getName() == $field) {
                        $data['metadata'][$name] = $d->getValue();
                    }
                }
            }

            // Fill in (multilingual) manifest data
            $manifestMetadata = array();
            foreach($data['metadata'] as $key => $metadata) {
/*                $arr = array();
                foreach($metadata as $language => $value) {
                    // Change nl into nl-BE, en into en-GB, etc.
                    if(array_key_exists($language, $this->localisations)) {
                        $language = $this->localisations[$language];
                    }
                    $arr[] = array(
                        '@language' => $language,
                        '@value'    => $value
                    );
                }*/

                // Replace comma by ' - ' for date ranges
                if(preg_match('/^[0-9]{3,4}\-[0-9]{1,2}\-[0-9]{1,2}, *[0-9]{3,4}\-[0-9]{1,2}\-[0-9]{1,2}$/', $metadata)) {
                    $metadata = str_replace(' ', '', $metadata);
                    $metadata = str_replace(',', ' - ', $metadata);

                  // Remove date and month when the exact date is clearly unknown
                  if(preg_match('/^[0-9]{3,4}\-01\-01 \- [0-9]{3,4}\-12\-31$/', $metadata)) {
                      $metadata = str_replace('-01-01', '', $metadata);
                      $metadata = str_replace('-12-31', '', $metadata);
                  }

                  // Remove latest date if it is the same as the earliest date
                  $dashIndex = strpos($metadata, ' - ');
                  $earliestDate = substr($metadata, 0, $dashIndex);
                  $latestDate = substr($metadata, $dashIndex + 3);
                  if($earliestDate === $latestDate) {
                    $metadata = $earliestDate;
                  }
                }

                $manifestMetadata[] = array(
                    'label' => $key,
                    'value' => $metadata
                );
            }

            // Generate the canvases
            $canvases = array();
            $index = 0;
            $startCanvas = null;
            $thumbnail = null;
            $isStartCanvas = false;

            if(!array_key_exists('related_resources', $data)) {
                $data['related_resources'] = array();
            }
            // Just to make sure that the 'related resources' always contains a reference to itself
            if(!in_array($resourceId, $data['related_resources'])) {
                $data['related_resources'][] = $resourceId;
            }

            // Loop through all resources related to this resource (including itself)
            foreach($data['related_resources'] as $relatedRef) {

                if(!array_key_exists($relatedRef, $this->imageData)) {
                    continue;
                }

                // When the related resource ID is the ID of the resource we're currently processing,
                // we know that this canvas is in fact the main canvas.
                $isStartCanvas = $relatedRef == $resourceId;

                $index++;
                $canvasId = $this->imageData[$relatedRef]['canvas_base'] . '/canvas/' . $index . '.json';
//                $serviceId = $this->serviceUrl . $relatedRef;
                $serviceId = $this->imageData[$relatedRef]['service_id'];
                $imageUrl = $this->imageData[$relatedRef]['image_url'];
                $publicUse = $this->imageData[$relatedRef]['public_use'];
                if($isStartCanvas && $startCanvas == null) {
                    $startCanvas = $canvasId;
                    $thumbnail = $serviceId;
                }
                $canvases[] = $this->generateCanvas($serviceId, $relatedRef, $imageUrl, $canvasId, $publicUse);

/*                // Store the canvas in the database
                $canvasDocument = new Canvas();
                $canvasDocument->setCanvasId($canvasId);
                $canvasDocument->setData(json_encode($newCanvas));
                $dm->persist($canvasDocument);
*/
            }

            $manifestId = $this->serviceUrl . $resourceId . '/manifest.json';
            // Generate the whole manifest
            $manifest = array(
                '@context'         => 'http://iiif.io/api/presentation/2/context.json',
                '@type'            => 'sc:Manifest',
                '@id'              => $manifestId,
                'label'            => $data['label'],
                'attribution'      => $data['attribution'],
                'description'      => empty($data['description']) ? 'n/a' : $data['description'],
                'metadata'         => $manifestMetadata,
                'viewingDirection' => 'left-to-right',
                'viewingHint'      => 'individuals',
                'sequences'        => $this->createSequence($canvases, $startCanvas)
            );

            // This image is not for public use, therefore we also don't want this manifest to be public
            if($isStartCanvas && !$data['public_use']) {
                $manifest['service'] = $this->getAuthenticationService();
            }

            if(!$this->createTopLevelCollection) {
                $this->deleteManifest($em, $manifestId);
            }

            $manifestDocument = $this->storeManifest($em, $manifest, $manifestId);

            // Validate the manifest
            // We can only pass a URL to the validator, so the manifest needs to be stored and served already before validation
            // If it does not pass validation, remove from the database
            $valid = true;
            if($validate) {
                $valid = $this->validateManifest($validatorUrl, $manifestId);
                if (!$valid) {
//                    echo 'Manifest ' . $manifestId . ' is not valid.' . PHP_EOL;
                    $this->logger->error('Manifest ' . $manifestId . ' is not valid.');
                    $em->remove($manifestDocument);
                    $em->flush();
                    $em->clear();
                }
            }

            if($valid) {
                if($this->verbose) {
//                    echo 'Generated manifest ' . $manifestId . ' for resource ' . $resourceId . PHP_EOL;
                    $this->logger->info('Generated manifest ' . $manifestId . ' for resource ' . $resourceId);
                }

                // Add to manifests array to add to the top-level collection
                $manifests[] = array(
                    '@id' => $manifestId,
                    '@type' => 'sc:Manifest',
                    'label' => $data['label'],
                    'metadata' => $manifestMetadata
                );

                if($data['recommended_for_publication']) {
                    // Update the LIDO data to include the manifest and thumbnail
                    if (!empty($data['dh_record_id'])) {
                        $recordId = $data['dh_record_id'];
                        if ($data['public_use'] || !in_array($recordId, $this->publicManifestsAdded)) {
                            $this->addManifestAndThumbnailToLido($this->namespace, $recordId, $manifestId, $thumbnail);
                            if ($data['public_use'] && !in_array($recordId, $this->publicManifestsAdded)) {
                                $this->publicManifestsAdded[] = $recordId;
                            }
                        }
                    }
                }
            }
        }

        //TODO do we actually need a top-level manifest?
        // If so, we need to store the 'label' of each manifest separately and then do a SELECT to get all ID's and labels for the top-level manifest

        if($this->createTopLevelCollection && count($manifests) > 0) {
            // Generate the top-level collection and store it in mongoDB
            $collectionId = $this->serviceUrl . 'collection/top';
            $collection = array(
                '@context' => 'http://iiif.io/api/presentation/2/context.json',
                '@id' => $collectionId,
                '@type' => 'sc:Collection',
                'label' => 'Top Level Collection for Imagehub',
                'viewingHint' => 'top',
                'description' => 'This collection lists all the IIIF manifests available in this Imagehub instance',
                'manifests' => $manifests
            );

            $this->deleteManifest($em, $collectionId);

            $manifestDocument = $this->storeManifest($em, $collection, $collectionId);

            $valid = true;
            if ($validate) {
                $valid = $this->validateManifest($validatorUrl, $collectionId);
                if (!$valid) {
//                    echo 'Top-level collection ' . $collectionId . ' is not valid.' . PHP_EOL;
                    $this->logger->error('Top-level collection ' . $collectionId . ' is not valid.');
                    $em->remove($manifestDocument);
                    $em->flush();
                    $em->clear();
                }
            }

            if ($this->verbose) {
                if ($valid) {
//                    echo 'Created and stored top-level collection' . PHP_EOL;
                    $this->logger->info('Created and stored top-level collection');
                }
//                echo 'Done, created and stored ' . count($manifests) . ' manifests.' . PHP_EOL;
                $this->logger->info('Done, created and stored ' . count($manifests) . ' manifests.');
            }
        }
    }

    private function generateCanvas($serviceId, $relatedRef, $imageUrl, $canvasId, $publicUse)
    {
        $service = array(
            '@context' => 'http://iiif.io/api/image/2/context.json',
            '@id'      => $serviceId,
            'profile'  => 'http://iiif.io/api/image/2/level2.json'
        );
        $resource = array(
            '@id'     => $imageUrl,
            '@type'   => 'dctypes:Image',
            'format'  => 'image/jpeg',
            'service' => $service,
            'height'  => $this->imageData[$relatedRef]['height'],
            'width'   => $this->imageData[$relatedRef]['width']
        );
        $image = array(
            '@context'   => 'http://iiif.io/api/presentation/2/context.json',
            '@type'      => 'oa:Annotation',
            '@id'        => $canvasId . '/image',
            'motivation' => 'sc:painting',
            'resource'   => $resource,
            'on'         => $canvasId
        );
        if(!$publicUse) {
            $image['service'] = $this->getAuthenticationService();
        }
        $newCanvas = array(
            '@id'    => $canvasId,
            '@type'  => 'sc:Canvas',
            'label'  => $relatedRef,
            'height' => $this->imageData[$relatedRef]['height'],
            'width'  => $this->imageData[$relatedRef]['width'],
            'images' => array($image)
        );
        return $newCanvas;
    }

    private function getAuthenticationService()
    {
        $arr = array(
            '@context' => 'http://iiif.io/api/auth/1/context.json',
            '@id'      => $this->container->getParameter('authentication_url'),
        );
        foreach($this->container->getParameter('authentication_service_description') as $key => $value) {
            $arr[$key] = $value;
        }
        return $arr;
    }

    private function createSequence($canvases, $startCanvas)
    {
        // Fill in sequence data
        if($startCanvas == null) {
            $manifestSequence = array(
                '@type'    => 'sc:Sequence',
                '@context' => 'http://iiif.io/api/presentation/2/context.json',
                'canvases' => $canvases
            );
        } else {
            $manifestSequence = array(
                '@type'       => 'sc:Sequence',
                '@context'    => 'http://iiif.io/api/presentation/2/context.json',
                'startCanvas' => $startCanvas,
                'canvases'    => $canvases
            );
        }
        return array($manifestSequence);
    }

    private function deleteAllManifests(EntityManagerInterface $em)
    {
        $qb = $em->createQueryBuilder();
        $query = $qb->delete(IIIfManifest::class, 'manifest')
            ->getQuery();
        $query->execute();
        $em->flush();
    }

    private function deleteManifest(EntityManagerInterface $em, $manifestId)
    {
        $qb = $em->createQueryBuilder();
        $query = $qb->delete(IIIfManifest::class, 'manifest')
                    ->where('manifest.manifestId = :manif_id')
                    ->setParameter('manif_id', $manifestId)
                    ->getQuery();
        $query->execute();
        $em->flush();
    }

    private function storeManifest(EntityManagerInterface $em, $manifest, $manifestId)
    {
        // Store the manifest in mongodb
        $manifestDocument = new IIIFManifest();
        $manifestDocument->setManifestId($manifestId);
        $manifestDocument->setData(json_encode($manifest));
        $em->persist($manifestDocument);
        $em->flush();
        $em->clear();
        return $manifestDocument;
    }

    private function validateManifest($validatorUrl, $manifestId)
    {
        $valid = true;
        try {
            $validatorJsonResult = file_get_contents($validatorUrl . $manifestId);
            $validatorResult = json_decode($validatorJsonResult);
            $valid = $validatorResult->okay == 1;
            if (!empty($validatorResult->warnings)) {
                foreach ($validatorResult->warnings as $warning) {
//                    echo 'Manifest ' . $manifestId . ' warning: ' . $warning . PHP_EOL;
                    $this->logger->warning('Manifest ' . $manifestId . ' warning: ' . $warning);
                }
            }
            if (!empty($validatorResult->error)) {
                if ($validatorResult->error != 'None') {
                    $valid = false;
//                    echo 'Manifest ' . $manifestId . ' error: ' . $validatorResult->error . PHP_EOL;
                    $this->logger->error('Manifest ' . $manifestId . ' error: ' . $validatorResult->error);
                }
            }
        } catch (Exception $e) {
            if($this->verbose) {
//                echo 'Error validating manifest ' . $manifestId . ': ' . $e . PHP_EOL;
                $this->logger->error('Error validating manifest ' . $manifestId . ': ' . $e);
            } else {
//                echo 'Error validating manifest ' . $manifestId . ': ' . $e->getMessage() . PHP_EOL;
                $this->logger->error('Error validating manifest ' . $manifestId . ': ' . $e->getMessage());
            }
        }
        return $valid;
    }

    private function addManifestAndThumbnailToLido($namespace, $datahubRecordId, $manifestUrl, $thumbnail)
    {
        if (!$this->datahubEndpoint) {
            $this->datahubEndpoint = Endpoint::build($this->datahubUrl . '/oai');
        }

        $record = null;
        try {
            $record = $this->datahubEndpoint->getRecord($datahubRecordId, $this->metadataPrefix);
        } catch(Exception $e) {
//            echo 'Error fetching datahub record ' . $datahubRecordId . ': ' . $e->getMessage() . PHP_EOL;
            $this->logger->error('Error fetching datahub record ' . $datahubRecordId . ': ' . $e->getMessage());
        }
        if($record == null) {
            return;
        }
        $data = $record->GetRecord->record->metadata->children($this->namespace, true);
        $domDoc = new DOMDocument;
        $domDoc->preserveWhiteSpace = false;
        $domDoc->formatOutput = true;
        $domDoc->loadXML($data->asXML());
        $xpath = new DOMXPath($domDoc);

        $query = 'descendant::lido:administrativeMetadata';
        $administrativeMetadatas = $xpath->query($query);
        foreach($administrativeMetadatas as $administrativeMetadata) {

            $resourceWrap = null;
            foreach($administrativeMetadata->childNodes as $childNode) {
                if ($childNode->nodeName == $namespace . ':resourceWrap') {
                    $resourceWrap = $childNode;
                    if($this->verbose) {
                        $this->logger->info('Resource wrap found for record ' . $datahubRecordId);
                    }

                    $domElemsToRemove = array();
                    // Remove any resourceSets that already contain a manifest URL
                    foreach($childNode->childNodes as $resourceSet) {
                        if($resourceSet->nodeName == $namespace . ':resourceSet') {
                            $remove = false;
                            foreach($resourceSet->childNodes as $resource) {
                                if($resource->getAttribute($namespace . ':source') == 'Imagehub' || $resource->getAttribute($namespace . ':source') == 'ImagehubKMSKA') {
                                    $remove = true;
                                    break;
                                }
                            }
                            if($remove) {
                                $domElemsToRemove[] = $resourceSet;
                            }
                        }
                    }
                    foreach($domElemsToRemove as $resourceSet) {
                        $childNode->removeChild($resourceSet);
                    }
                    break;
                }
            }

            if ($resourceWrap == null) {
                $resourceWrap = $domDoc->createElement($namespace . ':resourceWrap');
                $administrativeMetadata->appendChild($resourceWrap);
            }

            // Add manifest URL to the administrative metadata
            $resourceSet = $domDoc->createElement($namespace . ':resourceSet');
            $resourceWrap->appendChild($resourceSet);

            $resourceId = $domDoc->createElement($namespace . ':resourceID');
            $resourceId->setAttribute($namespace . ':type', 'purl');
            $resourceId->setAttribute($namespace . ':source', 'ImagehubKMSKA');
            $resourceId->nodeValue = $manifestUrl;
            $resourceSet->appendChild($resourceId);

            $resourceType = $domDoc->createElement($namespace . ':resourceType');
            $resourceSet->appendChild($resourceType);
            $term = $domDoc->createElement($namespace . ':term');
            $term->setAttribute($namespace . ':pref', 'preferred');
            $term->nodeValue = 'IIIF Manifest';
            $resourceType->appendChild($term);

            $resourceSource = $domDoc->createElement($namespace . ':resourceSource');
            $resourceSet->appendChild($resourceSource);
            $legalBodyName = $domDoc->createElement($namespace . ':legalBodyName');
            $resourceSource->appendChild($legalBodyName);
            $appellationValue = $domDoc->createElement($namespace . ':appellationValue');
            // Hardcoded value
            $appellationValue->nodeValue = 'Vlaamse Kunstcollectie VZW';
            $legalBodyName->appendChild($appellationValue);

            // Add thumbnail to the administrative metadata
            $resourceSet = $domDoc->createElement($namespace . ':resourceSet');
            $resourceWrap->appendChild($resourceSet);

            $resourceId = $domDoc->createElement($namespace . ':resourceID');
            $resourceId->setAttribute($namespace . ':type', 'purl');
            $resourceId->setAttribute($namespace . ':source', 'ImagehubKMSKA');
            $resourceId->nodeValue = $thumbnail;
            $resourceSet->appendChild($resourceId);

            $resourceType = $domDoc->createElement($namespace . ':resourceType');
            $resourceSet->appendChild($resourceType);
            $term = $domDoc->createElement($namespace . ':term');
            $term->setAttribute($namespace . ':pref', 'preferred');
            $term->nodeValue = 'thumbnail';
            $resourceType->appendChild($term);
        }

        if($this->datahubToken == null) {
            $this->initializeDatahubToken();
        }
        $this->updateDatahubRecord($datahubRecordId, $domDoc->saveXML());
    }

    private function initializeDatahubToken()
    {
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL, $this->datahubUrl . '/oauth/v2/token');
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS,
            'grant_type=password&username=' . urlencode($this->datahubUsername)
            . '&password=' . urlencode($this->datahubPassword)
            . '&client_id=' . urlencode($this->datahubPublicId)
            . '&client_secret=' . urlencode($this->datahubSecret)
        );

        $resultJson = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($resultJson);
        $this->datahubToken = $result->access_token;
        if($this->verbose) {
            $this->logger->info('Datahub token: ' . $this->datahubToken);
        }
    }

    private function updateDatahubRecord($datahubRecordId, $xmlData)
    {
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL, $this->datahubUrl . '/api/v1/data/' . $datahubRecordId . '.lidoxml');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS, $xmlData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/lido+xml',
                'Content-Length: ' . strlen($xmlData),
                'Authorization: Bearer ' . $this->datahubToken
            )
        );

        $result = curl_exec($ch);

        $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if(!empty($result) || $responseCode != 204) {
            $this->logger->error('Error updating Datahub record ' . $datahubRecordId . ': ' . PHP_EOL . $result);
        } else if($this->verbose) {
            $this->logger->info('Updated Datahub record ' . $datahubRecordId);
        }

        curl_close($ch);
    }
}
